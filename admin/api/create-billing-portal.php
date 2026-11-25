<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../src/php/stripe-billing.php';
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
    $stmt = getDB()->prepare('SELECT customer_id, environment FROM stripe_signup_subscriptions WHERE business_id = ?');
    $stmt->execute([tenantId()]);
    $subscription = $stmt->fetch();
    if ($subscription === false || $subscription['customer_id'] === null) {
        http_response_code(404);
        echo json_encode(['error' => 'No card subscription was found for this business.']);
        exit;
    }
    if ($subscription['environment'] !== stripeBillingEnvironment()) {
        throw new RuntimeException('Subscription and Stripe environments do not match.');
    }
    $session = stripeBillingRequest('POST', '/billing_portal/sessions', [
        'customer' => $subscription['customer_id'],
        'return_url' => tenantUrl($tenant['slug'], '/admin/billing'),
    ]);
    echo json_encode(['success' => true, 'portal_url' => stripeHostedUrl($session['url'] ?? null, 'billing.stripe.com')]);
} catch (Throwable $e) {
    clientqLog('ClientQ Stripe billing portal creation failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Subscription management is unavailable. Contact ClientQ support if you need to cancel.']);
}
