<?php
/**
 * Admin API: Change password
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

if (!isset($_SESSION['staff_id'])) {
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
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';

if (empty($currentPassword) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode(['error' => 'Both current and new passwords are required.']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'New password must be at least 6 characters.']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT password_hash FROM staff WHERE id = :id");
$stmt->execute([':id' => $_SESSION['staff_id']]);
$staff = $stmt->fetch();

if (!$staff || !password_verify($currentPassword, $staff['password_hash'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Current password is incorrect.']);
    exit;
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$update = $db->prepare("UPDATE staff SET password_hash = :hash WHERE id = :id");
$update->execute([':hash' => $newHash, ':id' => $_SESSION['staff_id']]);

echo json_encode(['success' => true, 'message' => 'Password changed']);
