<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/reservations.php';
$tenant = requireTenant(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reservationJsonError(405, 'Method not allowed.');
}
if (!appointmentsEnabled($tenant)) {
    reservationJsonError(403, 'This business is not accepting online appointments. Please use its waitlist.');
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    reservationJsonError(400, 'Invalid request body.');
}
$token = $input['csrf_token'] ?? null;
if (!is_string($token) || !verifyPlatformCsrfToken($token)) {
    reservationJsonError(403, 'Expired website session. Consider refreshing the page.');
}
foreach (['name', 'phone', 'date', 'time'] as $key) {
    if (!isset($input[$key]) || !is_string($input[$key])) {
        reservationJsonError(422, 'Enter valid reservation details.');
    }
}
$staffId = filter_var($input['staff_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$party = filter_var($input['party_size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
if (!$staffId || !$party) {
    reservationJsonError(422, 'Choose a staff member and party size from 1 to 10.');
}
try {
    $booking = createReservation(getTenantDB(), $staffId, $input['date'], $input['time'], $party, trim($input['name']), preg_replace('/\D/', '', $input['phone']));
    clientqLog('ClientQ reservation created: staff ' . $staffId . ', reservation ' . $booking['id'], level: 'INFO');
    http_response_code(201);
    echo json_encode(['success' => true, 'reservation' => $booking]);
} catch (InvalidArgumentException $e) {
    reservationJsonError(422, $e->getMessage());
} catch (DomainException $e) {
    reservationJsonError(409, $e->getMessage());
} catch (ClientQDatabaseException $e) {
    if ($e->mysqlErrorNumber === 1062) {
        reservationJsonError(409, 'That slot was just reserved. Choose another time.');
    }
    clientqLog('ClientQ reservation booking failed: ' . $e->getMessage());
    reservationJsonError(500, 'The reservation could not be saved.');
}
