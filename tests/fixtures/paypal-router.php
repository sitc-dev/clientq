<?php

$stateFile = getenv('CLIENTQ_TEST_PAYPAL_STATE');
if (PHP_SAPI !== 'cli-server' || !$stateFile) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
$state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parameters = json_decode(file_get_contents('php://input'), true);
$state['requests'][] = ['method' => $method, 'path' => $path, 'parameters' => $parameters,
    'request_id' => $_SERVER['HTTP_PAYPAL_REQUEST_ID'] ?? null];
$result = [];

if ($path === '/v1/oauth2/token' && $method === 'POST') {
    $result = ['access_token' => 'fixture-token'];
} elseif (preg_match('#^/v1/billing/plans/(P-[A-Z0-9]+)$#', $path, $match) && $method === 'GET') {
    $prices = ['A' => '39.00', 'B' => '64.00', 'C' => '99.00', 'D' => '44.00'];
    $result = [
        'id' => $match[1], 'status' => 'ACTIVE',
        'billing_cycles' => [[
            'sequence' => 1, 'tenure_type' => 'REGULAR', 'total_cycles' => 0,
            'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
            'pricing_scheme' => ['fixed_price' => ['currency_code' => 'USD', 'value' => $state['wrong_plan_price'] ?? $prices[substr($match[1], -1)]]],
        ]],
    ];
} elseif ($path === '/v1/billing/subscriptions' && $method === 'POST') {
    $requestId = $_SERVER['HTTP_PAYPAL_REQUEST_ID'];
    $id = $state['idempotency'][$requestId] ?? ('I-' . strtoupper(bin2hex(random_bytes(6))));
    $result = $state['subscriptions'][$id] ?? [
        'id' => $id, 'plan_id' => $parameters['plan_id'], 'custom_id' => $parameters['custom_id'],
        'status' => 'APPROVAL_PENDING', 'create_time' => gmdate('Y-m-d\TH:i:s\Z', time() - 10),
        'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=' . $id]],
    ];
    $state['idempotency'][$requestId] = $id;
    $state['subscriptions'][$id] = $result;
} elseif (preg_match('#^/v1/billing/subscriptions/(I-[A-Z0-9]+)(/transactions)?$#', $path, $match)
    && $method === 'GET' && isset($state['subscriptions'][$match[1]])) {
    $result = isset($match[2])
        ? ['transactions' => $state['transactions'][$match[1]] ?? []]
        : $state['subscriptions'][$match[1]];
} else {
    http_response_code(404);
    $result = ['error' => 'Unknown fixture request.'];
}
file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR);
