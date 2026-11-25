<?php

require_once dirname(__DIR__, 2) . '/lib/database-errors.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = getenv('CLIENTQ_DB_HOST') ?: 'localhost';
$port = getenv('CLIENTQ_DB_PORT') ?: '3306';
$name = getenv('CLIENTQ_DB_NAME') ?: 'clientq';
$user = getenv('CLIENTQ_DB_USER') ?: 'root';
$pass = getenv('CLIENTQ_DB_PASS') ?: '';

if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
    fwrite(STDERR, "CLIENTQ_DB_NAME may only contain letters, numbers, and underscores.\n");
    exit(1);
}

try {
    $pdo = connectToMysql(
        "mysql:host=$host;port=$port;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
        'setup MySQL server'
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");

    $schema = file_get_contents(dirname(__DIR__, 2) . '/database/platform-schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Could not read database/platform-schema.sql.');
    }

    $pdo->exec($schema);
    echo "ClientQ database '$name' is ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Setup failed: {$e->getMessage()}\n");
    exit(1);
}
