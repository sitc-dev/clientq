<?php

session_start();
require_once __DIR__ . '/../src/php/config.php';
requirePlatformHost();

$user = getPlatformSessionUser();
header('Location: ' . ($user && $user['role'] === 'platform_owner' ? 'dashboard' : 'login'));
exit;
