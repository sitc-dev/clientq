<?php
/**
 * API: Look up and remove a client from the waitlist
 * POST JSON:
 *   { action: "lookup", code, email } - Find client by code + email
 *   { action: "remove", code, email } - Remove client from waitlist
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

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

$action = $input['action'] ?? '';
$code = strtoupper(trim($input['code'] ?? ''));
$email = trim($input['email'] ?? '');

// Validate inputs
if (strlen($code) !== 6 || !ctype_alnum($code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid confirmation code. Must be 6 alphanumeric characters.']);
    exit;
}

if (empty($email) || substr_count($email, '@') !== 1 || strpos($email, '.') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid email address.']);
    exit;
}

$db = getDB();

// Find client
$stmt = $db->prepare("
    SELECT c.id, c.name, c.email, c.no_in_party, c.sign_in_time,
           s.goes_by AS staff_name
    FROM clients c
    JOIN staff s ON c.staff_id = s.id
    WHERE c.confirmation_code = :code
      AND c.email = :email
      AND c.is_active = 1
");
$stmt->execute([':code' => $code, ':email' => $email]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    echo json_encode(['error' => 'No matching client found. Please check your confirmation code and email address.']);
    exit;
}

if ($action === 'lookup') {
    // Format sign-in date
    $signInDate = date('m/d/y, g:ia', strtotime($client['sign_in_time']));

    echo json_encode([
        'success' => true,
        'client' => [
            'name' => $client['name'],
            'email' => $client['email'],
            'staff_name' => $client['staff_name'],
            'sign_in_date' => $signInDate,
        ]
    ]);
    exit;
}

if ($action === 'remove') {
    // Soft-delete client
    $deleteStmt = $db->prepare("UPDATE clients SET is_active = 0 WHERE id = :id");
    $deleteStmt->execute([':id' => $client['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'You have been removed from the waitlist.'
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
