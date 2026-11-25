<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/reservations.php';

$tenant = requireTenant(true);
$staffAccount = getSessionStaff();

if ($staffAccount === null) {
    session_unset();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

if (!staffCanManageUsers($staffAccount)) {
    http_response_code(403);
    echo json_encode(['error' => 'Owner or Manager access is required.']);
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
$employeeId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($employeeId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid employee.']);
    exit;
}

$db = getTenantDB();

try {
    $db->beginTransaction();
    lockStaffAdministration($db);
    $actor = currentStaffAdministrator($db, $staffAccount);
    $stmt = $db->prepare(
        "SELECT s.id, s.role,
            (SELECT COUNT(*) FROM clients c WHERE c.staff_id = s.id AND c.is_active = 1) AS active_clients
         FROM staff s
         WHERE s.id = :id AND s.is_active = 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $employeeId]);
    $employee = $stmt->fetch();

    if (!$employee) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Employee not found.']);
        exit;
    }
    if (!staffCanManageUser($actor, $employee)) {
        $db->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Managers can manage Staff accounts only.']);
        exit;
    }
    retainActiveStaffOwner($db, $employee, $employee['role'], false);
    if ((int)$employeeId === (int)$actor['id']) {
        throw new DomainException('You cannot deactivate your own account.');
    }

    if ((int)$employee['active_clients'] > 0) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Remove or serve this employee’s waiting clients before deactivating the profile.']);
        exit;
    }

    $reservations = $db->prepare('SELECT COUNT(*) FROM reservations WHERE staff_id = :staff AND ends_at > :now');
    $reservations->execute([':staff' => $employeeId, ':now' => reservationNow()->format('Y-m-d H:i:s')]);
    if ((int)$reservations->fetchColumn() > 0) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Have this employee cancel or complete their reservations before deactivating the profile.']);
        exit;
    }

    $update = $db->prepare("UPDATE staff SET is_active = 0, password_version = password_version + 1 WHERE id = :id");
    $update->execute([':id' => $employeeId]);
    $db->commit();

    echo json_encode(['success' => true]);
} catch (DomainException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
} catch (ClientQDatabaseException | RuntimeException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ employee deactivation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The employee could not be deactivated.']);
}
