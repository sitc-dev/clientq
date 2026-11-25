<?php

header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
requirePlatformHost(true);
http_response_code(410);
header('Cache-Control: no-store');
echo json_encode(['error' => 'Owner payments have moved to your business staff portal.']);
