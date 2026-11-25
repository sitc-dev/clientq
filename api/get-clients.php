<?php
/**
 * API: Get clients for the waitlist and/or staff list
 * GET params:
 *   - staff_only=1 : return only staff list
 *   - staff=all|any|{id} : filter clients by staff
 *   - page=N : pagination
 *   - galley=1 : use shared-display page size
 *   - tenant_status=1 : return only the tenant availability
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/waitlist-hours.php';

$tenant = requireTenant(true);
if (($_GET['clear_cache'] ?? '') === '1') {
    header('Clear-Site-Data: "cache"');
}
if (($_GET['tenant_status'] ?? '') === '1') {
    echo json_encode(['tenant_status' => 'active']);
    exit;
}
$db = getTenantDB();

try {
    $dailyState = waitlistDailyState($db, waitlistNow());

    if (isset($_GET['staff_only'])) {
        echo json_encode($dailyState);
        exit;
    }

    $staffFilter = $_GET['staff'] ?? 'all';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = ($_GET['galley'] ?? '') === '1' ? GALLEY_CLIENTS_PER_PAGE : CLIENTS_PER_PAGE;
    $offset = ($page - 1) * $perPage;
    $where = "c.is_active = 1";
    $params = [];

    if ($staffFilter === 'any') {
        $where .= " AND c.staff_id IS NULL";
    } elseif ($staffFilter !== 'all' && is_numeric($staffFilter)) {
        $where .= " AND c.staff_id = :staff_id";
        $params[':staff_id'] = (int)$staffFilter;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM clients c WHERE $where");
    $countStmt->execute($params);
    $totalClients = $countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalClients / $perPage));

    $stmt = $db->prepare("
        SELECT
            c.id,
            c.name,
            c.no_in_party,
            c.staff_id,
            c.sign_in_time,
            s.name AS staff_name,
            s.goes_by AS staff_nickname,
            TIMESTAMPDIFF(SECOND, c.sign_in_time, NOW()) AS wait_seconds
        FROM clients c
        LEFT JOIN staff s ON c.staff_id = s.id
        WHERE $where
        ORDER BY c.sign_in_time ASC, c.id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmt->execute();
    $clients = $stmt->fetchAll();

    foreach ($clients as &$client) {
        $client['staff_name'] = $client['staff_id'] === null ? 'First available staff' : staffDisplayName([
            'name' => $client['staff_name'],
            'goes_by' => $client['staff_nickname'],
        ]);
        $seconds = (int)$client['wait_seconds'];
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        $client['wait_time'] = $hours > 0
            ? sprintf('%02d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%02d:%02d', $minutes, $secs);
        unset($client['wait_seconds'], $client['sign_in_time'], $client['staff_nickname']);
    }
    unset($client);

    echo json_encode([
        'clients' => $clients,
        'total_pages' => $totalPages,
        'current_page' => $page,
        'total_clients' => $totalClients,
    ] + $dailyState);
} catch (ClientQDatabaseException $e) {
    clientqLog('ClientQ public waitlist load failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load the waitlist right now.']);
}
