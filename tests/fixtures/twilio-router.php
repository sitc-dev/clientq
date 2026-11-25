<?php

if (PHP_SAPI !== 'cli-server' || getenv('CLIENTQ_SMS_MOCK') !== '1') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/Messages.json')) {
    http_response_code(404);
    echo json_encode(['code' => 20404]);
    exit;
}
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== getenv('CLIENTQ_TWILIO_ACCOUNT_SID')
    || ($_SERVER['PHP_AUTH_PW'] ?? '') !== getenv('CLIENTQ_TWILIO_AUTH_TOKEN')) {
    http_response_code(401);
    echo json_encode(['code' => 20003]);
    exit;
}
$path = getenv('CLIENTQ_TWILIO_TEST_LOG') ?: '/tmp/clientq-twilio-test-messages.jsonl';
if (file_put_contents($path, json_encode($_POST, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['code' => 20500]);
    exit;
}
if (($_POST['To'] ?? '') === '+12025550100') {
    http_response_code(503);
    echo json_encode(['code' => 20503]);
    exit;
}
http_response_code(201);
echo json_encode(['sid' => 'SM' . bin2hex(random_bytes(16)), 'status' => 'queued']);
