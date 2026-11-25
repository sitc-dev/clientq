<?php
/**
 * Admin API: Change password
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';

$tenant = requireTenant(true);
$staffAccount = getSessionStaff();

if ($staffAccount === null) {
    session_unset();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !is_string($input['new_password'] ?? null)
    || !is_string($input['current_password'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid password request.']);
    exit;
}
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$firstLogin = !empty($staffAccount['must_change_password']);
if ($firstLogin) {
    if (!is_string($input['csrf_token'] ?? null) || !verifyPlatformCsrfToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
        exit;
    }
    if ($newPassword !== ($input['confirm_password'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => 'New passwords do not match.']);
        exit;
    }
}

if ((!$firstLogin && $currentPassword === '') || $newPassword === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Both current and new passwords are required.']);
    exit;
}

if (strlen($newPassword) < ($firstLogin ? 8 : 6) || strlen($newPassword) > 255 || str_contains($newPassword, "\0")) {
    http_response_code(400);
    echo json_encode(['error' => $firstLogin ? 'Choose a password between 8 and 255 characters without null characters.' : 'Choose a password between 6 and 255 characters without null characters.']);
    exit;
}

$db = getTenantDB();
try {
    $stmt = $db->prepare("SELECT password_hash FROM staff WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['staff_id']]);
    $staff = $stmt->fetch();

    if (!$staff || (!$firstLogin && !password_verify($currentPassword, $staff['password_hash']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Current password is incorrect.']);
        exit;
    }
    if ($firstLogin && password_verify($newPassword, $staff['password_hash'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Choose a password different from your temporary password.']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $db->prepare(
        "UPDATE staff SET password_hash = :hash, password_version = password_version + 1, must_change_password = 0
         WHERE id = :id AND password_version = :version"
    );
    $update->execute([
        ':hash' => $newHash,
        ':id' => $_SESSION['staff_id'],
        ':version' => $staffAccount['password_version'],
    ]);
    if ($update->rowCount() === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Your password changed elsewhere. Log in again before changing it.']);
        exit;
    }
    if ($firstLogin) {
        session_regenerate_id(true);
    }
    $_SESSION['staff_password_version'] = (int)$staffAccount['password_version'] + 1;

    echo json_encode(['success' => true, 'message' => 'Password changed']);
} catch (ClientQDatabaseException $e) {
    clientqLog('ClientQ password change failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to change the password right now.']);
}
