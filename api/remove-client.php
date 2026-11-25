<?php

header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/sms.php';

$tenant = requireTenant(true);

function removalError(int $status, string $message, ?PDO $db = null): never {
    if ($db !== null && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    removalError(405, 'Method not allowed.');
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    removalError(400, 'Invalid request body.');
}
$action = $input['action'] ?? '';
$signupId = filter_var($input['signup_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$phone = is_string($input['phone'] ?? null) ? preg_replace('/\D/', '', $input['phone']) : '';
if (!in_array($action, ['request_code', 'verify_code', 'delete'], true)) {
    removalError(400, 'Invalid action.');
}
if ($signupId === false || $signupId === null || strlen($phone) !== 10) {
    removalError(422, 'Open the deletion link from your confirmation text and enter a valid 10-digit phone number.');
}
$verificationCode = $input['verification_code'] ?? '';
$verificationToken = $input['verification_token'] ?? '';
if ($action === 'verify_code'
    && (!is_string($verificationCode) || !preg_match('/^[0-9]{6}$/', $verificationCode))) {
    removalError(422, 'Enter the six-digit verification code.');
}
if ($action === 'delete'
    && (!is_string($verificationToken) || !preg_match('/^[a-f0-9]{64}$/', $verificationToken))) {
    removalError(403, 'Verify your phone number before deleting this sign-up.');
}

$db = getTenantDB();
try {
    $db->beginTransaction();
    $lookup = $db->prepare(
        'SELECT c.id, c.name, c.staff_id, c.confirmation_code, c.sign_in_time,
                s.name AS staff_name, s.goes_by AS staff_nickname
         FROM clients c LEFT JOIN staff s ON s.id = c.staff_id
         WHERE c.id = :id AND c.phone = :phone FOR UPDATE'
    );
    $lookup->execute([':id' => $signupId, ':phone' => $phone]);
    $client = $lookup->fetch();
    if (!$client) {
        removalError(404, 'No matching sign-up found. Open the deletion link from your confirmation text and check your phone number.', $db);
    }
    $client['staff_name'] = $client['staff_id'] === null ? 'First available staff' : staffDisplayName([
        'name' => $client['staff_name'],
        'goes_by' => $client['staff_nickname'],
    ]);
    $challengeQuery = $db->prepare(
        'SELECT *, expires_at > CURRENT_TIMESTAMP AS unexpired,
                requested_at > CURRENT_TIMESTAMP - INTERVAL 1 MINUTE AS cooldown,
                window_started_at > CURRENT_TIMESTAMP - INTERVAL 1 HOUR AS current_window
         FROM waitlist_deletion_challenges WHERE client_id = :id FOR UPDATE'
    );
    $challengeQuery->execute([':id' => $signupId]);
    $challenge = $challengeQuery->fetch();

    if ($action === 'request_code') {
        if ($challenge && ((int)$challenge['cooldown'] === 1
            || ((int)$challenge['current_window'] === 1 && (int)$challenge['send_count'] >= 3))) {
            removalError(429, 'Too many code requests. Wait before requesting another code.', $db);
        }
        $code = sprintf('%06d', random_int(0, 999999));
        $hash = password_hash($code, PASSWORD_DEFAULT);
        if ($challenge) {
            $save = $db->prepare(
                'UPDATE waitlist_deletion_challenges
                 SET code_hash = :hash, expires_at = CURRENT_TIMESTAMP + INTERVAL 10 MINUTE,
                     attempts = 0, verification_token_hash = NULL, requested_at = CURRENT_TIMESTAMP,
                     send_count = IF(window_started_at > CURRENT_TIMESTAMP - INTERVAL 1 HOUR, send_count + 1, 1),
                     window_started_at = IF(window_started_at > CURRENT_TIMESTAMP - INTERVAL 1 HOUR, window_started_at, CURRENT_TIMESTAMP)
                 WHERE client_id = :id'
            );
        } else {
            $save = $db->prepare(
                'INSERT INTO waitlist_deletion_challenges (client_id, code_hash, expires_at)
                 VALUES (:id, :hash, CURRENT_TIMESTAMP + INTERVAL 10 MINUTE)'
            );
        }
        $save->execute([':id' => $signupId, ':hash' => $hash]);
        sendTenantSms(
            $tenant,
            $phone,
            "{$tenant['name']} deletion code is $code. Expires in 10 minutes. Do not share this code. "
            . 'Reply STOP to opt out or HELP for help.'
        );
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'A six-digit verification code has been sent to your phone.']);
        exit;
    }

    if (!$challenge || (int)$challenge['unexpired'] !== 1) {
        removalError(400, 'Your verification code expired or was not requested. Request a new code.', $db);
    }
    if ($action === 'verify_code') {
        if ((int)$challenge['attempts'] >= 5) {
            removalError(429, 'Too many incorrect attempts. Request a new code.', $db);
        }
        if ($challenge['verification_token_hash'] !== null) {
            removalError(400, 'This code has already been used. Request a new code.', $db);
        }
        if (!password_verify($verificationCode, $challenge['code_hash'])) {
            $attempt = $db->prepare('UPDATE waitlist_deletion_challenges SET attempts = attempts + 1 WHERE client_id = :id');
            $attempt->execute([':id' => $signupId]);
            $db->commit();
            removalError(400, 'The verification code is incorrect.');
        }
        $token = bin2hex(random_bytes(32));
        $verify = $db->prepare('UPDATE waitlist_deletion_challenges SET verification_token_hash = :hash WHERE client_id = :id');
        $verify->execute([':hash' => hash('sha256', $token), ':id' => $signupId]);
        $db->commit();
        echo json_encode([
            'success' => true,
            'verification_token' => $token,
            'client' => [
                'name' => $client['name'],
                'confirmation_code' => $client['confirmation_code'],
                'staff_name' => $client['staff_name'],
                'sign_in_date' => $client['sign_in_time'],
            ],
        ]);
        exit;
    }
    if (!is_string($challenge['verification_token_hash'])
        || !hash_equals($challenge['verification_token_hash'], hash('sha256', $verificationToken))) {
        removalError(403, 'Verify your phone number before deleting this sign-up.', $db);
    }
    $delete = $db->prepare('DELETE FROM clients WHERE id = :id');
    $delete->execute([':id' => $signupId]);
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Your sign-up and confirmation code have been permanently deleted.']);
} catch (ClientQSmsException $e) {
    clientqLog('ClientQ deletion verification SMS failed: ' . $e->getMessage());
    removalError(503, 'The verification text could not be sent. Please try again later.', $db);
} catch (ClientQDatabaseException $e) {
    clientqLog('ClientQ waitlist self-deletion failed: ' . $e->getMessage());
    removalError(500, 'Unable to update the waitlist right now.', $db);
}
