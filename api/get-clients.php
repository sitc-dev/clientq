<?php
/**
 * API: Get clients for the waitlist and/or staff list
 * GET params:
 *   - staff_only=1 : return only staff list
 *   - staff=all|{id} : filter clients by staff
 *   - page=N : pagination
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$db = getDB();

// Return staff list only
if (isset($_GET['staff_only'])) {
    $stmt = $db->query("SELECT id, goes_by FROM staff WHERE is_active = 1 ORDER BY id");
    echo json_encode(['staff' => $stmt->fetchAll()]);
    exit;
}

$staffFilter = $_GET['staff'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = CLIENTS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// Build query
$where = "c.is_active = 1";
$params = [];

if ($staffFilter !== 'all' && is_numeric($staffFilter)) {
    $where .= " AND c.staff_id = :staff_id";
    $params[':staff_id'] = (int)$staffFilter;
}

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM clients c WHERE $where");
$countStmt->execute($params);
$totalClients = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalClients / $perPage));

// Get clients
$stmt = $db->prepare("
    SELECT
        c.id,
        c.name,
        c.no_in_party,
        c.sign_in_time,
        s.goes_by AS staff_name,
        TIMESTAMPDIFF(SECOND, c.sign_in_time, NOW()) AS wait_seconds
    FROM clients c
    JOIN staff s ON c.staff_id = s.id
    WHERE $where
    ORDER BY c.sign_in_time ASC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_INT);
}
$stmt->execute();
$clients = $stmt->fetchAll();

// Format wait times
foreach ($clients as &$client) {
    $seconds = (int)$client['wait_seconds'];
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        $client['wait_time'] = sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        $client['wait_time'] = sprintf('%02d:%02d', $minutes, $secs);
    }
    unset($client['wait_seconds'], $client['sign_in_time']);
}
unset($client);

// Get staff list for filter
$staffStmt = $db->query("SELECT id, goes_by FROM staff WHERE is_active = 1 ORDER BY id");
$staffList = $staffStmt->fetchAll();

echo json_encode([
    'clients' => $clients,
    'total_pages' => $totalPages,
    'current_page' => $page,
    'total_clients' => $totalClients,
    'staff' => $staffList,
]);
