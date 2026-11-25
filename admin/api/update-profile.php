<?php
/**
 * Admin API: Update staff profile
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/staff-profile.php';

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
foreach (['name', 'first_name', 'last_name', 'goes_by', 'position', 'experience', 'additional_info', 'address', 'city', 'state', 'zip', 'phone', 'email'] as $field) {
    if (array_key_exists($field, $input) && !is_string($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => 'Profile fields must contain text.']);
        exit;
    }
}

$nameParts = staffNameParts($staffAccount);
if (array_key_exists('first_name', $input) || array_key_exists('last_name', $input)) {
    $nameParts = [
        'first_name' => trim($input['first_name'] ?? $nameParts['first_name']),
        'last_name' => trim($input['last_name'] ?? $nameParts['last_name']),
    ];
} elseif (array_key_exists('name', $input)) {
    $nameParts = staffNameParts(array_replace($staffAccount, ['name' => trim($input['name'])]));
} else {
    http_response_code(400);
    echo json_encode(['error' => 'First name is required.']);
    exit;
}
$name = trim($nameParts['first_name'] . ' ' . $nameParts['last_name']);
$goesBy = trim($input['goes_by'] ?? '');
$position = trim($input['position'] ?? '');
$experience = trim($input['experience'] ?? '');
$additionalInfo = trim($input['additional_info'] ?? '');
$address = trim($input['address'] ?? $staffAccount['address'] ?? '');
$city = trim($input['city'] ?? $staffAccount['city'] ?? '');
$state = strtoupper(trim($input['state'] ?? $staffAccount['state'] ?? ''));
$zip = trim($input['zip'] ?? $staffAccount['zip'] ?? '');
$phone = trim($input['phone'] ?? $staffAccount['phone'] ?? '');
$email = strtolower(trim($input['email'] ?? ($staffAccount['email'] ?: $staffAccount['username'])));

if ($nameParts['first_name'] === '') {
    http_response_code(400);
    echo json_encode(['error' => 'First name is required.']);
    exit;
}
if (strlen($address) > 255 || strlen($city) > 100 || strlen($position) > 100 || strlen($experience) > 65535 || strlen($additionalInfo) > 65535) {
    $error = 'One or more profile fields are too long.';
} elseif ($state !== '' && !in_array($state, STAFF_STATE_CODES, true)) {
    $error = 'Select a valid two-letter state.';
} elseif ($phone !== '' && !preg_match('/^[0-9]{10}$/', $phone)) {
    $error = 'Phone number must contain exactly 10 digits.';
} elseif ($zip !== '' && !preg_match('/^[0-9]{5}(-[0-9]{4})?$/', $zip)) {
    $error = 'Enter a five-digit ZIP code or ZIP+4.';
} elseif (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Enter a valid email address.';
}
if (isset($error)) {
    http_response_code(400);
    echo json_encode(['error' => $error]);
    exit;
}
if (strlen($name) > 100 || strlen($goesBy) > 50) {
    http_response_code(400);
    echo json_encode(['error' => 'Name must be up to 100 characters and nickname up to 50 characters.']);
    exit;
}

$db = getTenantDB();
try {
    $emailChanged = $email !== $staffAccount['username'];
    $versionSql = $emailChanged ? ', password_version = password_version + 1' : '';
    $stmt = $db->prepare("
        UPDATE staff SET
            name = :name,
            first_name = :first_name,
            last_name = :last_name,
            address = :address,
            city = :city,
            state = :state,
            zip = :zip,
            phone = :phone,
            email = :email,
            username = :username,
            goes_by = :goes_by,
            position = :position,
            experience = :experience,
            additional_info = :additional_info
            $versionSql
        WHERE id = :id AND password_version = :version
    ");

    $stmt->execute([
        ':name' => $name,
        ':first_name' => $nameParts['first_name'],
        ':last_name' => $nameParts['last_name'],
        ':address' => $address,
        ':city' => $city,
        ':state' => $state,
        ':zip' => $zip,
        ':phone' => $phone,
        ':email' => $email,
        ':username' => $email,
        ':goes_by' => $goesBy,
        ':position' => $position,
        ':experience' => $experience,
        ':additional_info' => $additionalInfo,
        ':id' => $_SESSION['staff_id'],
        ':version' => $staffAccount['password_version'],
    ]);

    if ($stmt->rowCount() === 0) {
        $check = $db->prepare('SELECT password_version FROM staff WHERE id = :id AND is_active = 1');
        $check->execute([':id' => $staffAccount['id']]);
        $currentVersion = $check->fetchColumn();
        if ($currentVersion === false || (int)$currentVersion !== (int)$staffAccount['password_version']) {
            http_response_code(409);
            echo json_encode(['error' => 'Your login changed elsewhere. Log in again before updating your profile.']);
            exit;
        }
    }
    if ($emailChanged) {
        session_regenerate_id(true);
        $_SESSION['staff_password_version'] = (int)$staffAccount['password_version'] + 1;
    }
    $_SESSION['username'] = $email;
    $_SESSION['goes_by'] = $goesBy;
    echo json_encode(['success' => true, 'message' => 'Profile updated']);
} catch (ClientQDatabaseException $e) {
    if ($e->mysqlErrorNumber === 1062) {
        http_response_code(409);
        echo json_encode(['error' => 'That email address is already in use.']);
        exit;
    }
    clientqLog('ClientQ profile update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to update the profile right now.']);
}
