<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/tenant-lifecycle.php';

$admin = requirePlatformRole('platform_owner', true);
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
if (!verifyPlatformCsrfToken($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}
$businessId = filter_var($input['business_id'] ?? null, FILTER_VALIDATE_INT);
$action = $tenantStatusAction ?? ($input['action'] ?? null);
$confirmation = $input['confirmation'] ?? null;
if ($businessId === false || $businessId <= 0
    || !in_array($action, ['active', 'inactive', 'delete'], true)
    || !is_string($confirmation) || $confirmation === ''
) {
    http_response_code(422);
    echo json_encode(['error' => 'Tenant, valid action, and business-name confirmation are required.']);
    exit;
}

$db = getDB();
$locks = [];
try {
    foreach (['clientq-register', 'clientq-delete-' . $businessId] as $lockName) {
        $stmt = $db->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute([':name' => $lockName]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new ClientQTenantLifecycleException('Tenant status changes are busy. Please try again.', 503);
        }
        $locks[] = $lockName;
    }
    $stmt = $db->prepare('SELECT id, name, slug, database_name, is_active FROM businesses WHERE id = :id');
    $stmt->execute([':id' => $businessId]);
    $business = $stmt->fetch();
    if (!$business) {
        throw new ClientQTenantLifecycleException('Tenant not found.', 404);
    }
    if (!hash_equals($business['name'], $confirmation)) {
        throw new ClientQTenantLifecycleException('Enter the exact business name to confirm this action.', 422);
    }
    if ($action === 'delete') {
        permanentlyDeleteTenant($db, $business, $admin);
    } else {
        setTenantActiveState($db, $business, $action === 'active', $admin);
    }
    echo json_encode(['success' => true]);
} catch (ClientQTenantLifecycleException $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ tenant status update failed: business_id=' . $businessId . ' error=' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The tenant status could not be updated.']);
} finally {
    foreach (array_reverse($locks) as $lockName) {
        $stmt = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute([':name' => $lockName]);
    }
}
