<?php

if (PHP_SAPI !== 'cli-server' || getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$_SERVER['REMOTE_ADDR'] = getenv('CLIENTQ_TEST_SIGNUP_IP') ?: '192.0.2.1';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    require $root . '/index.php';
} elseif (preg_match('#^/([a-z0-9-]+)$#', $path, $match) && is_file($root . '/src/php/' . $match[1] . '.php')) {
    require $root . '/src/php/' . $match[1] . '.php';
} elseif (preg_match('#^/(api|admin|admin/api)/([a-z0-9-]+)$#', $path, $match)
    && is_file($root . '/' . $match[1] . '/' . $match[2] . '.php')) {
    require $root . '/' . $match[1] . '/' . $match[2] . '.php';
} else {
    http_response_code(404);
}
