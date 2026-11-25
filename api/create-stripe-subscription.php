<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/business-registration.php';
requirePlatformHost(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
$request = json_decode(file_get_contents('php://input'), true);
if (!is_array($request) || !is_string($request['csrf_token'] ?? null)
    || !verifyPlatformCsrfToken($request['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}

try {
    if (paypalSignupRecord() !== null) {
        http_response_code(409);
        echo json_encode(['error' => 'PayPal checkout is already in progress. Resume that checkout or verify payment; do not pay again by card.']);
        exit;
    }
    $pending = pendingBusinessRegistration();
    if ($pending === null) {
        http_response_code(409);
        echo json_encode(['error' => 'Your signup review expired. Return to signup and try again.']);
        exit;
    }
    $environment = stripeBillingEnvironment();
    $db = getDB();
    $signup = stripeSignupRecord();
    if ($signup === null) {
        $stmt = $db->prepare(
            'SELECT (SELECT COUNT(*) FROM businesses WHERE slug = ?)
                + (SELECT COUNT(*) FROM tenant_status_pages WHERE slug = ?)'
        );
        $stmt->execute([$pending['slug'], $pending['slug']]);
        if ((int)$stmt->fetchColumn() !== 0) {
            http_response_code(409);
            echo json_encode(['error' => 'That ClientQ address is already in use. Return to signup before paying.']);
            exit;
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM signup_attempts WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL 1 HOUR'
        );
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        $dailyCount = (int)$db->query('SELECT COUNT(*) FROM businesses WHERE created_at >= CURRENT_DATE')->fetchColumn();
        if ((int)$stmt->fetchColumn() >= max(1, (int)(getenv('CLIENTQ_SIGNUPS_PER_HOUR') ?: 3))
            || $dailyCount >= max(1, (int)(getenv('CLIENTQ_DAILY_SIGNUP_LIMIT') ?: 100))) {
            http_response_code(429);
            echo json_encode(['error' => 'Signup capacity has been reached. Please try again later before paying.']);
            exit;
        }
        $id = bin2hex(random_bytes(16));
        $appointments = appointmentsEnabled($pending);
        $stmt = $db->prepare(
            'INSERT INTO stripe_signup_subscriptions
                (id, plan, appointments_enabled, amount_cents, environment, pending_registration)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $pending['plan'], (int)$appointments,
            planMonthlyAmount($pending['plan'], $appointments), $environment, json_encode($pending, JSON_THROW_ON_ERROR)]);
        $_SESSION['stripe_signup_id'] = $id;
        $signup = stripeSignupRecord();
        if ($signup === null) {
            throw new RuntimeException('The Stripe signup record could not be loaded.');
        }
    }
    if ($signup['environment'] !== $environment) {
        throw new RuntimeException('The signup belongs to a different Stripe environment.');
    }
    if ($signup['checkout_session_id'] === null) {
        $name = 'ClientQ ' . planDisplayName($signup['plan'])
            . ($signup['plan'] === 'starter' && $signup['appointments_enabled'] ? ' with Appointments' : '');
        $session = stripeBillingRequest('POST', '/checkout/sessions', [
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'payment_method_collection' => 'always',
            'client_reference_id' => $signup['id'],
            'metadata' => ['signup_id' => $signup['id']],
            'customer_email' => $pending['email'],
            'subscription_data' => ['metadata' => ['signup_id' => $signup['id']]],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd', 'unit_amount' => (int)$signup['amount_cents'],
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                    'product_data' => ['name' => $name],
                ],
            ]],
            'success_url' => platformUrl('/signup-confirmation?payment=approved'),
            'cancel_url' => platformUrl('/signup-confirmation?payment=cancelled'),
        ], 'clientq-signup-' . $signup['id']);
        if (!is_string($session['id'] ?? null) || !preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/', $session['id'])) {
            throw new RuntimeException('Stripe did not return a checkout session ID.');
        }
        $stmt = $db->prepare('UPDATE stripe_signup_subscriptions SET checkout_session_id = ? WHERE id = ?');
        $stmt->execute([$session['id'], $signup['id']]);
        $signup['checkout_session_id'] = $session['id'];
    } else {
        $session = stripeBillingRequest('GET', '/checkout/sessions/' . rawurlencode($signup['checkout_session_id']));
    }
    validateStripeSignupSession($session, $signup);
    if (($session['status'] ?? null) === 'complete') {
        echo json_encode(['success' => true, 'verify_payment' => true]);
        exit;
    }
    if (($session['status'] ?? null) !== 'open') {
        http_response_code(409);
        echo json_encode(['error' => 'This card checkout expired. Contact ClientQ support to reset it safely; do not start another payment.']);
        exit;
    }
    echo json_encode(['success' => true, 'checkout_url' => stripeHostedUrl($session['url'] ?? null, 'checkout.stripe.com')]);
} catch (Throwable $e) {
    clientqLog('ClientQ Stripe subscription creation failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Card checkout is unavailable. Your account has not been created. Retry here without starting another payment.']);
}
