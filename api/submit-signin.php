<?php
/**
 * API: Submit client sign-in
 * POST JSON: { name, email, staff_id, no_in_party }
 * - Validates inputs
 * - Generates 6-char alphanumeric confirmation code
 * - Writes to database
 * - Sends confirmation email
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mail.php';

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
$email = trim($input['email'] ?? '');
$staffId = (int)($input['staff_id'] ?? 0);
$noInParty = (int)($input['no_in_party'] ?? 0);

// Server-side validation
$errors = [];

if (empty($name) || !preg_match('/^[a-zA-Z\s]+$/', $name)) {
    $errors[] = 'Name must contain only letters.';
}

if ($noInParty < 1 || $noInParty > 10) {
    $errors[] = 'Party size must be between 1 and 10.';
}

if ($staffId <= 0) {
    $errors[] = 'Please select a staff member.';
}

if (empty($email) || substr_count($email, '@') !== 1 || strpos($email, '.') === false) {
    $errors[] = 'Please enter a valid email address.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

$db = getDB();

// Verify staff exists
$staffStmt = $db->prepare("SELECT id, goes_by FROM staff WHERE id = :id AND is_active = 1");
$staffStmt->execute([':id' => $staffId]);
$staff = $staffStmt->fetch();

if (!$staff) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid staff member selected.']);
    exit;
}

// Generate 6-character alphanumeric confirmation code
function generateCode($length = 6) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

$confirmationCode = generateCode();

// Ensure code is unique
$codeCheck = $db->prepare("SELECT COUNT(*) FROM clients WHERE confirmation_code = :code AND is_active = 1");
$codeCheck->execute([':code' => $confirmationCode]);
while ($codeCheck->fetchColumn() > 0) {
    $confirmationCode = generateCode();
    $codeCheck->execute([':code' => $confirmationCode]);
}

// Insert client
$insertStmt = $db->prepare("
    INSERT INTO clients (name, email, no_in_party, staff_id, confirmation_code)
    VALUES (:name, :email, :no_in_party, :staff_id, :code)
");

try {
    $insertStmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':no_in_party' => $noInParty,
        ':staff_id' => $staffId,
        ':code' => $confirmationCode,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save. Please try again.']);
    exit;
}

// Build removal link
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = dirname(dirname($_SERVER['SCRIPT_NAME']));
$removeLink = "$protocol://$host$basePath/remove";

// Send confirmation email
$subject = BUSINESS_NAME . " - Waitlist Confirmation";
$message = "Hello $name,\n\n";
$message .= "You have been added to the " . BUSINESS_NAME . " waitlist.\n\n";
$message .= "Details:\n";
$message .= "- Name: $name\n";
$message .= "- No. in Party: $noInParty\n";
$message .= "- Staff: {$staff['goes_by']}\n";
$message .= "- Confirmation Code: $confirmationCode\n\n";
$message .= "If you need to remove yourself from the waitlist, visit:\n";
$message .= "$removeLink\n\n";
$message .= "Use your confirmation code ($confirmationCode) and email to remove yourself.\n\n";
$message .= "Thank you!\n" . BUSINESS_NAME;

// Send confirmation email via SMTP
$emailResult = sendEmail($email, $subject, $message);

if (!$emailResult['success']) {
    // Client is saved but email failed — still succeed but warn
    echo json_encode([
        'success' => true,
        'message' => 'You have been added to the waitlist. (Email could not be sent: ' . $emailResult['error'] . ')',
        'confirmation_code' => $confirmationCode,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'You have been added to the waitlist. A confirmation email has been sent.',
    'confirmation_code' => $confirmationCode,
]);
