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
if (!is_array($request) || !verifyPlatformCsrfToken($request['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}

try {
    if (stripeSignupRecord() !== null) {
        http_response_code(409);
        echo json_encode(['error' => 'Card checkout is already in progress. Resume that checkout or verify payment; do not pay again with PayPal.']);
        exit;
    }
    $pending = pendingBusinessRegistration();
    if ($pending === null) {
        http_response_code(409);
        echo json_encode(['error' => 'Your signup review expired. Return to signup and try again.']);
        exit;
    }
    $db = getDB();
    $signup = paypalSignupRecord();
    if ($signup === null) {
        $appointments = appointmentsEnabled($pending);
        $plan = paypalPlan($pending['plan'], $appointments);
        $details = paypalRequest('GET', '/v1/billing/plans/' . rawurlencode($plan['id']));
        validatePayPalMonthlyPlan($details, $plan['id'], $plan['amount_cents']);
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
            'SELECT COUNT(*) FROM signup_attempts WHERE ip_address = ?
                AND attempted_at >= NOW() - INTERVAL 1 HOUR'
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
        $stmt = $db->prepare(
            'INSERT INTO paypal_signup_subscriptions
             (id, plan_id, plan, appointments_enabled, amount_cents, environment, pending_registration)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $plan['id'], $pending['plan'], (int)$appointments, $plan['amount_cents'],
            paypalEnvironment(), json_encode($pending, JSON_THROW_ON_ERROR)]);
        $_SESSION['paypal_signup_id'] = $id;
        $signup = paypalSignupRecord();
        if ($signup === null) {
            throw new RuntimeException('The PayPal signup record could not be loaded.');
        }
    }
    if ($signup['environment'] !== paypalEnvironment()) {
        throw new RuntimeException('The signup belongs to a different PayPal environment.');
    }
    if ($signup['subscription_id'] === null) {
        $subscription = paypalRequest('POST', '/v1/billing/subscriptions', [
            'plan_id' => $signup['plan_id'],
            'custom_id' => $signup['id'],
            'application_context' => [
                'brand_name' => 'ClientQ',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => platformUrl('/signup-confirmation?payment=approved'),
                'cancel_url' => platformUrl('/signup-confirmation?payment=cancelled'),
            ],
        ], $signup['id']);
        if (!is_string($subscription['id'] ?? null)
            || !preg_match('/^I-[A-Z0-9]+$/', $subscription['id'])) {
            throw new RuntimeException('PayPal did not return a subscription ID.');
        }
        $stmt = $db->prepare('UPDATE paypal_signup_subscriptions SET subscription_id = ? WHERE id = ?');
        $stmt->execute([$subscription['id'], $signup['id']]);
        $signup['subscription_id'] = $subscription['id'];
    } else {
        $subscription = paypalRequest('GET', '/v1/billing/subscriptions/' . rawurlencode($signup['subscription_id']));
    }
    validatePayPalSubscription($subscription, $signup);
    if (($subscription['status'] ?? '') === 'ACTIVE') {
        echo json_encode(['success' => true, 'subscription_id' => $signup['subscription_id'], 'verify_payment' => true]);
        exit;
    }
    $url = paypalApprovalUrl($subscription);
    if ($url === null) {
        http_response_code(409);
        echo json_encode(['error' => 'This subscription cannot be approved. Contact ClientQ support; do not start another payment.']);
        exit;
    }
    echo json_encode(['success' => true, 'subscription_id' => $signup['subscription_id'], 'approval_url' => $url]);
} catch (Throwable $e) {
    clientqLog('ClientQ PayPal subscription creation failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'PayPal checkout is unavailable. Your account has not been created. Please try again.']);
}
