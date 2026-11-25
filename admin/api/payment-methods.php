<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/stripe-payment-methods.php';
$tenant = requireTenant(true);
$staff = getSessionStaff();
if ($staff === null) {
    session_unset();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
if ($staff['role'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['error' => 'Owner access is required.']);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body.']);
        exit;
    }
    if (!is_string($input['csrf_token'] ?? null) || !verifyPlatformCsrfToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
        exit;
    }
}
try {
    $stmt = getDB()->prepare(
        'SELECT * FROM stripe_signup_subscriptions WHERE business_id = ? AND provisioned_at IS NOT NULL'
    );
    $stmt->execute([$tenant['id']]);
    $signup = $stmt->fetch();
    if ($signup === false || $signup['customer_id'] === null || $signup['subscription_id'] === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Saved card management is available to Stripe subscribers only.']);
        exit;
    }
    if ($signup['environment'] !== stripeBillingEnvironment()) {
        throw new RuntimeException('Subscription and Stripe environments do not match.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true, 'cards' => stripeSavedCards($signup)]);
        exit;
    }
    $action = $input['action'] ?? null;
    if ($action === 'add') {
        $pending = $_SESSION['stripe_card_setup'] ?? null;
        if (!is_array($pending) || ($pending['business_id'] ?? null) !== (int)$tenant['id']
            || ($pending['customer_id'] ?? null) !== $signup['customer_id']
            || ($pending['environment'] ?? null) !== $signup['environment']) {
            $pending = null;
        }
        if (is_string($pending['session_id'] ?? null)) {
            $session = stripeBillingRequest('GET', '/checkout/sessions/' . rawurlencode($pending['session_id']));
            if (($session['status'] ?? null) === 'open') {
                echo json_encode(['success' => true, 'checkout_url' => stripeHostedUrl($session['url'] ?? null, 'checkout.stripe.com')]);
                exit;
            }
            $pending = null;
        }
        if ($pending === null) {
            $pending = ['business_id' => (int)$tenant['id'], 'customer_id' => $signup['customer_id'],
                'environment' => $signup['environment'], 'nonce' => bin2hex(random_bytes(16))];
        }
        $_SESSION['stripe_card_setup'] = $pending;
        $session = stripeBillingRequest('POST', '/checkout/sessions', [
            'mode' => 'setup', 'customer' => $signup['customer_id'], 'payment_method_types' => ['card'],
            'client_reference_id' => 'clientq-business-' . $tenant['id'],
            'metadata' => ['business_id' => (string)$tenant['id']],
            'success_url' => tenantUrl($tenant['slug'], '/admin/profile?card_session={CHECKOUT_SESSION_ID}'),
            'cancel_url' => tenantUrl($tenant['slug'], '/admin/profile?card=cancelled'),
        ], 'clientq-card-' . $tenant['id'] . '-' . $pending['nonce']);
        if (!is_string($session['id'] ?? null) || !preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/', $session['id'])
            || ($session['mode'] ?? null) !== 'setup' || ($session['customer'] ?? null) !== $signup['customer_id']) {
            throw new RuntimeException('Stripe did not return a card setup session for this business.');
        }
        $_SESSION['stripe_card_setup']['session_id'] = $session['id'];
        echo json_encode(['success' => true, 'checkout_url' => stripeHostedUrl($session['url'] ?? null, 'checkout.stripe.com')]);
        exit;
    }
    if ($action === 'verify') {
        $pending = $_SESSION['stripe_card_setup'] ?? null;
        $id = $input['session_id'] ?? null;
        if (!is_string($id) || !is_array($pending) || ($pending['session_id'] ?? null) !== $id
            || ($pending['business_id'] ?? null) !== (int)$tenant['id']
            || ($pending['customer_id'] ?? null) !== $signup['customer_id']) {
            http_response_code(409);
            echo json_encode(['error' => 'This card setup does not match your session. Refresh your profile to check saved cards.']);
            exit;
        }
        $session = stripeBillingRequest('GET', '/checkout/sessions/' . rawurlencode($id), [
            'expand' => ['setup_intent.payment_method'],
        ]);
        validateStripeCardSetup($session, $signup, (int)$tenant['id']);
        unset($_SESSION['stripe_card_setup']);
        echo json_encode(['success' => true]);
        exit;
    }
    if (!in_array($action, ['default', 'remove'], true)
        || !is_string($input['payment_method_id'] ?? null)
        || !preg_match('/^pm_[A-Za-z0-9]+$/', $input['payment_method_id'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Choose a valid saved card action.']);
        exit;
    }
    $id = $input['payment_method_id'];
    stripeOwnedCard($signup, $id);
    $context = stripeCardBillingContext($signup);
    if ($action === 'default') {
        stripeBillingRequest('POST', '/customers/' . rawurlencode($signup['customer_id']), [
            'invoice_settings' => ['default_payment_method' => $id],
        ]);
        if (!in_array($context['subscription']['status'] ?? null, ['canceled', 'incomplete_expired'], true)) {
            stripeBillingRequest('POST', '/subscriptions/' . rawurlencode($signup['subscription_id']), [
                'default_payment_method' => $id,
            ]);
        }
    } else {
        if ($id === $context['default_id']
            || $id === ($context['customer']['invoice_settings']['default_payment_method'] ?? null)) {
            http_response_code(409);
            echo json_encode(['error' => 'Choose another default card before removing this card.']);
            exit;
        }
        stripeBillingRequest('POST', '/payment_methods/' . rawurlencode($id) . '/detach');
    }
    echo json_encode(['success' => true]);
} catch (DomainException $e) {
    http_response_code(404);
    echo json_encode(['error' => 'This card does not belong to your business.']);
} catch (Throwable $e) {
    clientqLog('ClientQ saved card management failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Card management could not finish. Refresh your profile to check saved cards before retrying.']);
}
