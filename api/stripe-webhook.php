<?php

header('Content-Type: application/json');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../src/php/stripe.php';
require_once __DIR__ . '/../src/php/stripe-billing.php';
requirePlatformHost(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if (!is_string($payload) || !verifyStripeSignature($payload, $signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Stripe signature.']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event.']);
    exit;
}

$session = $event['data']['object'] ?? [];
if (($session['mode'] ?? '') === 'subscription') {
    try {
        if (($event['type'] ?? '') === 'checkout.session.completed') {
            $stmt = getDB()->prepare('SELECT * FROM stripe_signup_subscriptions WHERE checkout_session_id = ?');
            $stmt->execute([$session['id'] ?? '']);
            $signup = $stmt->fetch();
            if ($signup !== false && verifyStripeSignupPayment($signup) === null) {
                throw new RuntimeException('Stripe signup payment is not complete.');
            }
        }
    } catch (Throwable $e) {
        clientqLog('ClientQ Stripe signup webhook verification failed: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Unable to verify signup payment.']);
        exit;
    }
    echo json_encode(['received' => true]);
    exit;
}
if (($event['type'] ?? '') === 'checkout.session.completed'
    && ($session['payment_status'] ?? '') === 'paid') {
    $invoiceId = (int)($session['metadata']['invoice_id'] ?? 0);
    $stmt = getDB()->prepare(
        "UPDATE invoices SET
            status = 'paid',
            stripe_checkout_session_id = :session_id,
            stripe_checkout_url = NULL,
            stripe_checkout_expires_at = NULL,
            stripe_payment_intent_id = :payment_intent,
            paid_at = CURRENT_TIMESTAMP
         WHERE id = :invoice_id
           AND amount_cents = :amount_cents
           AND currency = :currency
           AND status = 'open'"
    );
    $stmt->execute([
        ':invoice_id' => $invoiceId,
        ':amount_cents' => (int)($session['amount_total'] ?? 0),
        ':currency' => strtolower((string)($session['currency'] ?? '')),
        ':payment_intent' => $session['payment_intent'] ?? null,
        ':session_id' => $session['id'] ?? '',
    ]);
} elseif (($event['type'] ?? '') === 'checkout.session.expired') {
    $stmt = getDB()->prepare(
        "UPDATE invoices SET
            stripe_checkout_session_id = NULL,
            stripe_checkout_url = NULL,
            stripe_checkout_expires_at = NULL
         WHERE stripe_checkout_session_id = :session_id AND status = 'open'"
    );
    $stmt->execute([':session_id' => $session['id'] ?? '']);
}

echo json_encode(['received' => true]);
