<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../src/php/config.php';

requireTenant(true);
$staff = getSessionStaff();
if ($staff === null) {
    session_unset();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}
if (!is_string($input['csrf_token'] ?? null) || !verifyPlatformCsrfToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}
$clientId = filter_var($input['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$clientId) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid client.']);
    exit;
}
$db = getTenantDB();
try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT staff_id FROM clients WHERE id = :id AND is_active = 1 FOR UPDATE');
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch();
    if ($client === false) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'This client is no longer waiting. Refresh the waitlist.']);
        exit;
    }
    if (!staffCanCompleteClient($staff, $client['staff_id'] === null ? null : (int)$client['staff_id'])) {
        $db->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'You can only complete clients assigned to you or to First available staff.']);
        exit;
    }
    $update = $db->prepare('UPDATE clients SET is_active = 0 WHERE id = :id');
    $update->execute([':id' => $clientId]);
    $db->commit();
    clientqLog('ClientQ client completed: client ' . $clientId . ', staff ' . $staff['id'], level: 'INFO');
    echo json_encode(['success' => true]);
} catch (ClientQDatabaseException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ client completion failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The client could not be marked as completed.']);
}
