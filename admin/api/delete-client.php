<?php
/**
 * Admin API: Delete a client from the waitlist
 * Owners and managers can delete any waiting client; staff can delete their own.
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

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid client ID']);
    exit;
}

$db = getTenantDB();

try {
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT id, staff_id FROM clients WHERE id = :id AND is_active = 1 FOR UPDATE");
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Client not found']);
        exit;
    }

    if (!staffCanDeleteClient($staffAccount, $client['staff_id'] === null ? null : (int)$client['staff_id'])) {
        $db->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'You can only remove your own clients']);
        exit;
    }

    $del = $db->prepare("DELETE FROM clients WHERE id = :id AND is_active = 1");
    $del->execute([':id' => $clientId]);

    if ($del->rowCount() === 0) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Client not found']);
        exit;
    }

    $db->commit();
    clientqLog('ClientQ client removed: client ' . $clientId . ', staff ' . $staffAccount['id'], level: 'INFO');
    echo json_encode(['success' => true, 'message' => 'Client removed']);
} catch (ClientQDatabaseException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ staff client removal failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to remove the client right now.']);
}
