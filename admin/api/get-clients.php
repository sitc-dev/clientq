<?php
/**
 * Admin API: Get clients for the logged-in staff member
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

if (!isset($_SESSION['staff_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("
    SELECT c.id, c.name, c.email, c.no_in_party, c.confirmation_code, c.sign_in_time,
           TIMESTAMPDIFF(SECOND, c.sign_in_time, NOW()) AS wait_seconds
    FROM clients c
    WHERE c.staff_id = :staff_id AND c.is_active = 1
    ORDER BY c.sign_in_time ASC
");
$stmt->execute([':staff_id' => $_SESSION['staff_id']]);
$clients = $stmt->fetchAll();

foreach ($clients as &$client) {
    $seconds = (int)$client['wait_seconds'];
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $client['wait_time'] = $hours > 0
        ? sprintf('%02d:%02d:%02d', $hours, $minutes, $secs)
        : sprintf('%02d:%02d', $minutes, $secs);
    unset($client['wait_seconds']);
}
unset($client);

echo json_encode(['success' => true, 'clients' => $clients, 'count' => count($clients)]);
