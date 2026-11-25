<?php
/**
 * Admin API: Update staff profile
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

$name = trim($input['name'] ?? '');
$goesBy = trim($input['goes_by'] ?? '');
$position = trim($input['position'] ?? '');
$experience = trim($input['experience'] ?? '');
$additionalInfo = trim($input['additional_info'] ?? '');

if (empty($name) || empty($goesBy)) {
    http_response_code(400);
    echo json_encode(['error' => 'Name and "Goes by" are required.']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("
    UPDATE staff SET
        name = :name,
        goes_by = :goes_by,
        position = :position,
        experience = :experience,
        additional_info = :additional_info
    WHERE id = :id
");

$stmt->execute([
    ':name' => $name,
    ':goes_by' => $goesBy,
    ':position' => $position,
    ':experience' => $experience,
    ':additional_info' => $additionalInfo,
    ':id' => $_SESSION['staff_id'],
]);

// Update session display name
$_SESSION['goes_by'] = $goesBy;

echo json_encode(['success' => true, 'message' => 'Profile updated']);
