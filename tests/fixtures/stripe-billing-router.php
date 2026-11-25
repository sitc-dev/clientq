<?php

$stateFile = getenv('CLIENTQ_TEST_STRIPE_STATE');
if (PHP_SAPI !== 'cli-server' || !$stateFile) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
$state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parameters = $_GET;
if ($method !== 'GET') {
    parse_str(file_get_contents('php://input'), $parameters);
}
$state['stripe_requests'][] = ['method' => $method, 'path' => $path, 'parameters' => $parameters,
    'request_id' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, 'api_version' => $_SERVER['HTTP_STRIPE_VERSION'] ?? null];
if (($state['stripe_fail_path'] ?? null) === $path) {
    http_response_code(503);
    $result = ['error' => ['message' => 'Fixture Stripe request failed.']];
} elseif ($path === '/v1/checkout/sessions' && $method === 'POST') {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'];
    $id = $state['stripe_idempotency'][$key] ?? 'cs_test_' . bin2hex(random_bytes(8));
    $result = $state['stripe_sessions'][$id] ?? ($parameters['mode'] === 'setup' ? [
        'id' => $id, 'mode' => 'setup', 'livemode' => false, 'customer' => $parameters['customer'],
        'client_reference_id' => $parameters['client_reference_id'], 'metadata' => $parameters['metadata'],
        'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/' . $id,
    ] : [
        'id' => $id, 'mode' => $parameters['mode'], 'livemode' => false,
        'client_reference_id' => $parameters['client_reference_id'], 'metadata' => $parameters['metadata'],
        'status' => 'open', 'payment_status' => 'unpaid', 'currency' => 'usd',
        'amount_total' => (int)$parameters['line_items'][0]['price_data']['unit_amount'],
        'amount_subtotal' => (int)$parameters['line_items'][0]['price_data']['unit_amount'],
        'url' => 'https://checkout.stripe.com/c/pay/' . $id,
    ]);
    $state['stripe_idempotency'][$key] = $id;
    $state['stripe_sessions'][$id] = $result;
} elseif (preg_match('#^/v1/checkout/sessions/(cs_test_[a-z0-9]+)$#', $path, $match)
    && $method === 'GET' && isset($state['stripe_sessions'][$match[1]])) {
    $result = $state['stripe_sessions'][$match[1]];
    if (($result['status'] ?? '') === 'complete' && ($result['mode'] ?? '') === 'setup') {
        if (!in_array('setup_intent.payment_method', $parameters['expand'] ?? [], true)) {
            $result['setup_intent'] = $result['setup_intent']['id'];
        }
    } elseif (($result['status'] ?? '') === 'complete') {
        if (!in_array('subscription', $parameters['expand'] ?? [], true)) {
            $result['subscription'] = $result['subscription']['id'];
        }
        if (!in_array('invoice.payment_intent.latest_charge', $parameters['expand'] ?? [], true)) {
            $result['invoice'] = $result['invoice']['id'];
        }
    }
} elseif (preg_match('#^/v1/customers/(cus_[A-Za-z0-9]+)$#', $path, $match)
    && isset($state['stripe_customers'][$match[1]])) {
    if ($method === 'POST') {
        $state['stripe_customers'][$match[1]]['invoice_settings'] = $parameters['invoice_settings'];
    }
    $result = $state['stripe_customers'][$match[1]];
} elseif (preg_match('#^/v1/subscriptions/(sub_[A-Za-z0-9]+)$#', $path, $match)
    && isset($state['stripe_card_subscriptions'][$match[1]])) {
    if ($method === 'POST') {
        $state['stripe_card_subscriptions'][$match[1]]['default_payment_method'] = $parameters['default_payment_method'];
    }
    $result = $state['stripe_card_subscriptions'][$match[1]];
} elseif ($path === '/v1/payment_methods' && $method === 'GET') {
    $cards = array_values(array_filter($state['stripe_payment_methods'] ?? [], fn(array $card): bool => $card['customer'] === $parameters['customer']));
    $result = ['data' => $cards, 'has_more' => false];
} elseif (preg_match('#^/v1/payment_methods/(pm_[A-Za-z0-9]+)(/detach)?$#', $path, $match)
    && isset($state['stripe_payment_methods'][$match[1]])) {
    $result = $state['stripe_payment_methods'][$match[1]];
    if ($method === 'POST' && isset($match[2])) {
        $state['stripe_payment_methods'][$match[1]]['customer'] = null;
        $result['customer'] = null;
    }
} elseif ($path === '/v1/billing_portal/sessions' && $method === 'POST') {
    $result = ['url' => 'https://billing.stripe.com/p/session/fixture'];
} else {
    http_response_code(404);
    $result = ['error' => ['message' => 'Unknown Stripe fixture request.']];
}
if (file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Could not write Stripe fixture state.');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
