<?php
/**
 * API: Submit client sign-in
 * POST JSON: { name, phone, staff_id, no_in_party, sms_consent }
 * - Validates inputs
 * - Generates 6-char alphanumeric confirmation code
 * - Writes to database
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/sms.php';
require_once __DIR__ . '/../lib/waitlist-hours.php';

$tenant = requireTenant(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$name = trim($input['name'] ?? '');
$phone = preg_replace('/\D/', '', (string)($input['phone'] ?? ''));
$anyStaff = ($input['staff_id'] ?? null) === 'any';
$staffId = $anyStaff ? null : filter_var($input['staff_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$noInParty = (int)($input['no_in_party'] ?? 0);
$smsConsent = filter_var($input['sms_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Server-side validation
$errors = [];

if (empty($name) || !preg_match('/^[a-zA-Z\s]+$/', $name)) {
    $errors[] = 'Name must contain only letters.';
}

if ($noInParty < 1 || $noInParty > 10) {
    $errors[] = 'Party size must be between 1 and 10.';
}

if (!$anyStaff && !$staffId) {
    $errors[] = 'Please select a staff member.';
}

if (strlen($phone) !== 10) {
    $errors[] = 'Please enter a valid 10-digit phone number.';
}

if (!$smsConsent) {
    $errors[] = 'Transactional and informational text message consent is required.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

$db = getTenantDB();

// Generate 6-character alphanumeric confirmation code
function generateCode($length = 6) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

try {
    $db->beginTransaction();
    lockWaitlistHours($db);
    $dailyState = waitlistDailyState($db, waitlistNow());
    if (!$dailyState['waitlist']['can_join']) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => $dailyState['waitlist']['message']] + $dailyState);
        exit;
    }

    if (!$anyStaff) {
        $staffStmt = $db->prepare("SELECT id FROM staff WHERE id = :id AND is_active = 1 FOR UPDATE");
        $staffStmt->execute([':id' => $staffId]);
        $staff = $staffStmt->fetch();

        if (!$staff) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid staff member selected.']);
            exit;
        }
        if (!in_array((int)$staffId, array_map('intval', array_column($dailyState['available_staff'], 'id')), true)) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'This staff member is not available for the rest of this workday. Choose another staff member.'] + $dailyState);
            exit;
        }
    }

    $confirmationCode = generateCode();
    $codeCheck = $db->prepare("SELECT COUNT(*) FROM clients WHERE confirmation_code = :code AND is_active = 1");
    $codeCheck->execute([':code' => $confirmationCode]);
    while ($codeCheck->fetchColumn() > 0) {
        $confirmationCode = generateCode();
        $codeCheck->execute([':code' => $confirmationCode]);
    }

    $insertStmt = $db->prepare("
        INSERT INTO clients (
            name,
            phone,
            no_in_party,
            staff_id,
            confirmation_code,
            sms_consent,
            sms_consent_at
        )
        VALUES (:name, :phone, :no_in_party, :staff_id, :code, 1, CURRENT_TIMESTAMP)
    ");
    $insertStmt->execute([
        ':name' => $name,
        ':phone' => $phone,
        ':no_in_party' => $noInParty,
        ':staff_id' => $staffId,
        ':code' => $confirmationCode,
    ]);
    $signupId = (int)$db->lastInsertId();
    $db->commit();
} catch (ClientQDatabaseException | RuntimeException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ submit sign-in failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save. Please try again.']);
    exit;
}

$smsSent = false;
try {
    $removalUrl = tenantUrl($tenant['slug'], '/remove?signup=' . $signupId);
    sendTenantSms(
        $tenant,
        $phone,
        "$name, you are on the {$tenant['name']} waitlist. Confirmation code: $confirmationCode. "
        . "Delete this sign-up: $removalUrl\n"
        . 'Reply STOP to opt out or HELP for help. Msg & data rates may apply.'
    );
    $smsSent = true;
} catch (ClientQSmsException | ClientQDatabaseException $e) {
    clientqLog('ClientQ sign-in confirmation SMS failed: ' . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'message' => 'You have been added to the waitlist.',
    'confirmation_code' => $confirmationCode,
    'sms_sent' => $smsSent,
    'sms_warning' => $smsSent ? null : 'Your sign-up was saved, but the confirmation text could not be sent.',
]);
