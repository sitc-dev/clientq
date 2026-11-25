<?php

session_start();
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/business-registration.php';

requirePlatformHost();
$pending = pendingBusinessRegistration();
$logo = is_array($pending) ? ($pending['logo'] ?? null) : null;
$path = is_array($logo) ? pendingBusinessLogoPath($logo) : null;

if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $logo['mime']);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
