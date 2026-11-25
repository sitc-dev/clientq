<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

[$script, $name, $email, $password] = array_pad($argv, 4, null);
$name = trim((string)$name);
$email = strtolower(trim((string)$email));
$password = (string)$password;

if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Usage: php $script \"Owner Name\" owner@example.com \"password-at-least-12-chars\"\n");
    exit(1);
}

try {
    $stmt = getDB()->prepare(
        "INSERT INTO platform_users (name, email, password_hash, role)
         VALUES (:name, :email, :password_hash, 'platform_owner')"
    );
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    echo "Platform owner created for $email.\n";
} catch (ClientQDatabaseException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        fwrite(STDERR, "A platform account already exists for $email.\n");
        exit(1);
    }
    throw $e;
}
