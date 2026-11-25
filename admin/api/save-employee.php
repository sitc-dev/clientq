<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/staff-profile.php';
require_once __DIR__ . '/../../lib/employee-invitation.php';
require_once __DIR__ . '/../../src/php/mail.php';

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

$id = $input['id'] ?? null;
$employeeId = in_array($id, [null, '', 0, '0'], true) ? 0
    : filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($employeeId === false) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid user.']);
    exit;
}
$requestedRole = $input['role'] ?? null;
if (array_key_exists('role', $input) && (!is_string($requestedRole) || !isset(STAFF_ACCESS_LEVELS[$requestedRole]))) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid access level.']);
    exit;
}
if ($requestedRole !== null && !in_array($requestedRole, staffAssignableRoles($staffAccount), true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Managers can assign Staff access only.']);
    exit;
}
foreach (['name', 'first_name', 'last_name', 'goes_by', 'email', 'position', 'additional_info', 'password'] as $field) {
    if (array_key_exists($field, $input) && !is_string($input[$field])) {
        http_response_code(422);
        echo json_encode(['error' => 'Employee fields must contain text.']);
        exit;
    }
}
$structuredName = array_key_exists('first_name', $input) || array_key_exists('last_name', $input);
$nameParts = $structuredName
    ? ['first_name' => trim($input['first_name'] ?? ''), 'last_name' => trim($input['last_name'] ?? '')]
    : staffNameParts(['name' => trim($input['name'] ?? '')]);
$name = trim($nameParts['first_name'] . ' ' . $nameParts['last_name']);
$goesBy = trim($input['goes_by'] ?? '');
$position = trim($input['position'] ?? '');
$additionalInfo = trim($input['additional_info'] ?? '');
$password = $input['password'] ?? '';
$email = strtolower(trim($input['email'] ?? ''));

if ($nameParts['first_name'] === '' || strlen($name) > 100) {
    $error = 'Enter a full name up to 100 characters.';
} elseif (strlen($goesBy) > 50) {
    $error = 'Nickname must be up to 50 characters.';
} elseif (strlen($position) > 100 || strlen($additionalInfo) > 65535) {
    $error = 'Position or profile details are too long.';
} elseif (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Enter a valid employee email address.';
} elseif ($employeeId > 0 && $password !== '' && (strlen($password) < 8 || strlen($password) > 255 || str_contains($password, "\0"))) {
    $error = 'Password must be between 8 and 255 characters.';
}

if (isset($error)) {
    http_response_code(422);
    echo json_encode(['error' => $error]);
    exit;
}

$db = getTenantDB();

try {
    if ($employeeId > 0) {
        $db->beginTransaction();
        lockStaffAdministration($db);
        $actor = currentStaffAdministrator($db, $staffAccount);
        $employeeStmt = $db->prepare("SELECT id, name, first_name, last_name, username, role FROM staff WHERE id = :id AND is_active = 1 FOR UPDATE");
        $employeeStmt->execute([':id' => $employeeId]);
        $employee = $employeeStmt->fetch();
        if (!$employee) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Employee not found.']);
            exit;
        }
        $role = $requestedRole ?? $employee['role'];
        if (!staffCanManageUser($actor, $employee) || !in_array($role, staffAssignableRoles($actor), true)) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'Managers can manage Staff accounts only.']);
            exit;
        }
        retainActiveStaffOwner($db, $employee, $role);
        if (!$structuredName) {
            $nameParts = staffNameParts(array_replace($employee, ['name' => $name]));
        }

        $passwordSql = '';
        $params = [
            ':id' => $employeeId,
            ':name' => $name,
            ':first_name' => $nameParts['first_name'],
            ':last_name' => $nameParts['last_name'],
            ':goes_by' => $goesBy,
            ':username' => $email,
            ':email' => $email,
            ':position' => $position,
            ':additional_info' => $additionalInfo,
            ':role' => $role,
        ];

        if ($password !== '') {
            $passwordSql = ', password_hash = :password_hash';
            $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($password !== '' || $email !== $employee['username'] || $role !== $employee['role']) {
            $passwordSql .= ', password_version = password_version + 1';
        }

        $stmt = $db->prepare(
            "UPDATE staff SET
                name = :name,
                first_name = :first_name,
                last_name = :last_name,
                goes_by = :goes_by,
                username = :username,
                email = :email,
                position = :position,
                additional_info = :additional_info,
                role = :role
                $passwordSql
             WHERE id = :id"
        );
        $stmt->execute($params);
        $db->commit();
    } else {
        $employeeLimits = ['starter' => 10, 'growth' => 25, 'pro' => 50];
        $limit = $employeeLimits[$tenant['plan']] ?? $employeeLimits['growth'];
        if (SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASS === '') {
            throw new RuntimeException('SMTP is not configured.');
        }

        createInvitedEmployee($db, [
            'name' => $name, 'first_name' => $nameParts['first_name'], 'last_name' => $nameParts['last_name'],
            'goes_by' => $goesBy, 'email' => $email,
            'position' => $position, 'additional_info' => $additionalInfo,
            'role' => $requestedRole ?? 'staff',
        ], $limit, $tenant['name'], tenantUrl($tenant['slug'], '/admin/login'), 'sendEmail', $staffAccount);
    }

    echo json_encode(['success' => true]);
} catch (DomainException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ employee invitation failed: ' . $e->getMessage());
    http_response_code($employeeId > 0 ? 500 : 503);
    echo json_encode(['error' => $employeeId > 0 ? 'The user could not be saved. Please try again.'
        : 'The employee was not created because invitation email could not be sent. Check SMTP settings and try again.']);
} catch (ClientQDatabaseException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if ($e->mysqlErrorNumber === 1062) {
        http_response_code(409);
        echo json_encode(['error' => 'That email address is already in use.']);
        exit;
    }

    clientqLog('ClientQ employee save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The employee could not be saved.']);
}
