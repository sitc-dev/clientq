<?php
/**
 * Admin API: Delete a client from the waitlist
 * Staff can only delete their own clients.
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
$clientId = (int)($input['client_id'] ?? 0);

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid client ID']);
    exit;
}

$db = getDB();

// Verify the client belongs to this staff member
$stmt = $db->prepare("SELECT id, staff_id FROM clients WHERE id = :id AND is_active = 1");
$stmt->execute([':id' => $clientId]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    echo json_encode(['error' => 'Client not found']);
    exit;
}

if ((int)$client['staff_id'] !== (int)$_SESSION['staff_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only remove your own clients']);
    exit;
}

// Soft-delete
$del = $db->prepare("UPDATE clients SET is_active = 0 WHERE id = :id");
$del->execute([':id' => $clientId]);

echo json_encode(['success' => true, 'message' => 'Client removed']);
