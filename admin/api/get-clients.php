<?php
/**
 * Admin API: Get the tenant waitlist and the logged-in staff member's allowed actions
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../../src/php/config.php';

$tenant = requireTenant(true);
if (($_GET['clear_cache'] ?? '') === '1') {
    header('Clear-Site-Data: "cache"');
}
$staffAccount = getSessionStaff();

if ($staffAccount === null) {
    session_unset();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$db = getTenantDB();

try {
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.phone, c.no_in_party, c.staff_id, c.confirmation_code, c.sign_in_time,
               s.name AS staff_name, s.goes_by AS staff_nickname,
               TIMESTAMPDIFF(SECOND, c.sign_in_time, NOW()) AS wait_seconds
        FROM clients c
        LEFT JOIN staff s ON c.staff_id = s.id
        WHERE c.is_active = 1
        ORDER BY c.sign_in_time ASC, c.id ASC
    ");
    $stmt->execute();
    $clients = $stmt->fetchAll();

    foreach ($clients as &$client) {
        $assignedStaffId = $client['staff_id'] === null ? null : (int)$client['staff_id'];
        $client['can_complete'] = staffCanCompleteClient($staffAccount, $assignedStaffId);
        $client['can_remove'] = staffCanDeleteClient($staffAccount, $assignedStaffId);
        $client['staff_name'] = $client['staff_id'] === null ? 'First available staff' : staffDisplayName([
            'name' => $client['staff_name'], 'goes_by' => $client['staff_nickname'],
        ]);
        $seconds = (int)$client['wait_seconds'];
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        $client['wait_time'] = $hours > 0
            ? sprintf('%02d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%02d:%02d', $minutes, $secs);
        unset($client['wait_seconds'], $client['staff_nickname']);
    }
    unset($client);

    echo json_encode(['success' => true, 'clients' => $clients, 'count' => count($clients)]);
} catch (ClientQDatabaseException $e) {
    clientqLog('ClientQ staff client list failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load clients right now.']);
}
