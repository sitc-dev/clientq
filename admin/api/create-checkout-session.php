<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../src/php/stripe.php';

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
    echo json_encode(['error' => 'Owner access required.']);
    exit;
}
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !is_string($input['csrf_token'] ?? null)
    || !verifyPlatformCsrfToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}

$invoiceId = filter_var($input['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($invoiceId === false) {
    http_response_code(422);
    echo json_encode(['error' => 'A valid invoice is required.']);
    exit;
}
$db = getDB();
$successUrl = tenantUrl($tenant['slug'], '/admin/billing?payment=success');
$cancelUrl = tenantUrl($tenant['slug'], '/admin/billing?payment=cancelled');

try {
    $db->beginTransaction();
    $stmt = $db->prepare(
        "SELECT i.id, i.invoice_number, i.description, i.amount_cents, i.currency,
            i.stripe_checkout_session_id, i.stripe_checkout_url,
            i.stripe_checkout_expires_at, i.stripe_checkout_attempt,
            b.name AS business_name, b.owner_email AS billing_email
         FROM invoices i
         JOIN businesses b ON b.id = i.business_id AND b.is_active = 1
         WHERE i.id = :invoice_id AND i.business_id = :business_id AND i.status = 'open'
         FOR UPDATE"
    );
    $stmt->execute([':invoice_id' => $invoiceId, ':business_id' => $tenant['id']]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Open invoice not found.']);
        exit;
    }

    if ($invoice['stripe_checkout_session_id']) {
        $existingSession = stripeRequest(
            'GET',
            '/checkout/sessions/' . rawurlencode($invoice['stripe_checkout_session_id'])
        );
        $sessionInvoiceId = (int)($existingSession['metadata']['invoice_id'] ?? 0);
        $sessionAmount = (int)($existingSession['amount_total'] ?? 0);
        $sessionCurrency = strtolower((string)($existingSession['currency'] ?? ''));
        if ($sessionInvoiceId !== (int)$invoice['id']
            || $sessionAmount !== (int)$invoice['amount_cents']
            || $sessionCurrency !== strtolower($invoice['currency'])) {
            throw new RuntimeException('Stored Stripe session does not match the invoice.');
        }

        $sessionStatus = $existingSession['status'] ?? '';
        if ($sessionStatus === 'open'
            && (($existingSession['success_url'] ?? '') !== $successUrl
                || ($existingSession['cancel_url'] ?? '') !== $cancelUrl)) {
            $expired = stripeRequest(
                'POST',
                '/checkout/sessions/' . rawurlencode($invoice['stripe_checkout_session_id']) . '/expire'
            );
            if (($expired['status'] ?? '') !== 'expired') {
                throw new RuntimeException('Stripe did not expire the outdated Checkout session.');
            }
            $sessionStatus = 'expired';
        }

        if ($sessionStatus === 'open') {
            $checkoutUrl = $existingSession['url'] ?? $invoice['stripe_checkout_url'];
            if (!$checkoutUrl) {
                throw new RuntimeException('Stripe did not return a Checkout URL.');
            }
            $expiresAt = isset($existingSession['expires_at'])
                ? date('Y-m-d H:i:s', (int)$existingSession['expires_at'])
                : $invoice['stripe_checkout_expires_at'];
            $update = $db->prepare(
                "UPDATE invoices SET stripe_checkout_url = :checkout_url,
                    stripe_checkout_expires_at = :expires_at
                 WHERE id = :id AND status = 'open'"
            );
            $update->execute([
                ':checkout_url' => $checkoutUrl,
                ':expires_at' => $expiresAt,
                ':id' => $invoice['id'],
            ]);
            $db->commit();
            echo json_encode(['success' => true, 'checkout_url' => $checkoutUrl]);
            exit;
        }

        if ($sessionStatus === 'complete'
            && ($existingSession['payment_status'] ?? '') === 'paid') {
            $update = $db->prepare(
                "UPDATE invoices SET status = 'paid',
                    stripe_checkout_url = NULL,
                    stripe_checkout_expires_at = NULL,
                    stripe_payment_intent_id = :payment_intent,
                    paid_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = 'open'"
            );
            $update->execute([
                ':payment_intent' => $existingSession['payment_intent'] ?? null,
                ':id' => $invoice['id'],
            ]);
            $db->commit();
            echo json_encode(['success' => true, 'paid' => true]);
            exit;
        }

        if ($sessionStatus !== 'expired') {
            $db->commit();
            http_response_code(409);
            echo json_encode(['error' => 'This payment is still being confirmed by Stripe.']);
            exit;
        }
    }

    $attempt = (int)$invoice['stripe_checkout_attempt'] + 1;
    $session = stripeRequest('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'payment_method_types' => ['card'],
        'customer_email' => $invoice['billing_email'],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => (string)$invoice['id'],
        'metadata' => [
            'invoice_id' => (string)$invoice['id'],
        ],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => strtolower($invoice['currency']),
                'unit_amount' => (int)$invoice['amount_cents'],
                'product_data' => [
                    'name' => $invoice['business_name'] . ' - ' . $invoice['invoice_number'],
                    'description' => $invoice['description'],
                ],
            ],
        ]],
    ], 'clientq-invoice-' . $invoice['id'] . '-attempt-' . $attempt);
    $expiresAt = isset($session['expires_at'])
        ? date('Y-m-d H:i:s', (int)$session['expires_at'])
        : date('Y-m-d H:i:s', time() + 1800);

    $update = $db->prepare(
        "UPDATE invoices SET
            stripe_checkout_session_id = :session_id,
            stripe_checkout_url = :checkout_url,
            stripe_checkout_expires_at = :expires_at,
            stripe_checkout_attempt = :attempt
         WHERE id = :id AND status = 'open'"
    );
    $update->execute([
        ':session_id' => $session['id'],
        ':checkout_url' => $session['url'],
        ':expires_at' => $expiresAt,
        ':attempt' => $attempt,
        ':id' => $invoice['id'],
    ]);
    $db->commit();

    echo json_encode(['success' => true, 'checkout_url' => $session['url']]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ Stripe checkout failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Payment service is temporarily unavailable.']);
}
