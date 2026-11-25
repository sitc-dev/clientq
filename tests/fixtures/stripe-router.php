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
parse_str(file_get_contents('php://input'), $parameters);
$state['requests'][] = [
    'method' => $method, 'path' => $path, 'parameters' => $parameters,
    'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
];

if ($method === 'POST' && $path === '/v1/checkout/sessions') {
    $id = 'cs_test_' . bin2hex(random_bytes(6));
    $session = [
        'id' => $id, 'url' => 'https://checkout.stripe.test/' . $id,
        'status' => 'open', 'payment_status' => 'unpaid',
        'metadata' => $parameters['metadata'],
        'amount_total' => (int)$parameters['line_items'][0]['price_data']['unit_amount'],
        'currency' => $parameters['line_items'][0]['price_data']['currency'],
        'success_url' => $parameters['success_url'], 'cancel_url' => $parameters['cancel_url'],
        'expires_at' => time() + 1800,
    ];
    $state['sessions'][$id] = $session;
} elseif (preg_match('#^/v1/checkout/sessions/([^/]+)(/expire)?$#', $path, $match)
    && isset($state['sessions'][$match[1]])) {
    $session = $state['sessions'][$match[1]];
    if ($method === 'POST' && isset($match[2])) {
        $session['status'] = 'expired';
        $state['sessions'][$match[1]] = $session;
    } elseif ($method !== 'GET') {
        http_response_code(405);
        $session = ['error' => ['message' => 'Unsupported test Stripe method.']];
    }
} else {
    http_response_code(404);
    $session = ['error' => ['message' => 'Test Stripe session not found.']];
}

if (file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Unable to persist the test Stripe state.');
}
echo json_encode($session, JSON_THROW_ON_ERROR);
