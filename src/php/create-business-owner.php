<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

[$script, $slug, $name, $email, $password] = array_pad($argv, 5, null);
$slug = strtolower(trim((string)$slug));
$name = trim((string)$name);
$email = strtolower(trim((string)$email));
$password = (string)$password;

if ($slug === '' || $name === '' || strlen($name) > 100
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    fwrite(STDERR, "Usage: php $script business-slug \"Owner Name\" owner@example.com \"password-at-least-8-chars\"\n");
    exit(1);
}

$db = getDB();
$businessStmt = $db->prepare('SELECT id FROM businesses WHERE slug = :slug');
$businessStmt->execute([':slug' => $slug]);
$businessId = $businessStmt->fetchColumn();
if (!$businessId) {
    fwrite(STDERR, "Business '$slug' was not found.\n");
    exit(1);
}

try {
    $db->beginTransaction();
    $userStmt = $db->prepare(
        'SELECT id, role, is_active FROM platform_users WHERE email = :email FOR UPDATE'
    );
    $userStmt->execute([':email' => $email]);
    $user = $userStmt->fetch();

    if ($user && $user['role'] !== 'business_owner') {
        throw new RuntimeException('That email belongs to a platform administrator.');
    }
    if ($user && !(bool)$user['is_active']) {
        throw new RuntimeException('That business owner account is inactive.');
    }

    if ($user) {
        $userId = (int)$user['id'];
        $update = $db->prepare('UPDATE platform_users SET name = :name, password_hash = :password_hash WHERE id = :id');
        $update->execute([
            ':name' => $name,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $userId,
        ]);
    } else {
        $insert = $db->prepare(
            "INSERT INTO platform_users (name, email, password_hash, role)
             VALUES (:name, :email, :password_hash, 'business_owner')"
        );
        $insert->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int)$db->lastInsertId();
    }

    $link = $db->prepare(
        'INSERT IGNORE INTO business_owners (business_id, user_id) VALUES (:business_id, :user_id)'
    );
    $link->execute([':business_id' => $businessId, ':user_id' => $userId]);
    $db->commit();
    echo "Business owner account linked to '$slug'.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
