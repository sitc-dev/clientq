<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/reservations.php';
$tenant = requireTenant(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    reservationJsonError(405, 'Method not allowed.');
}
if (!appointmentsEnabled($tenant)) {
    reservationJsonError(403, 'This business is not accepting online appointments. Please use its waitlist.');
}
$staffId = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$party = filter_var($_GET['party_size'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
$date = $_GET['date'] ?? reservationNow()->format('Y-m-d');
if ($staffId === false || $staffId === null || $party === false || !is_string($date)) {
    reservationJsonError(422, 'Choose a staff member, date, and party size from 1 to 10.');
}
try {
    $db = getTenantDB();
    $stmt = $db->prepare('SELECT id FROM staff WHERE id = :id AND is_active = 1');
    $stmt->execute([':id' => $staffId]);
    if (!$stmt->fetch()) {
        reservationJsonError(404, 'Staff member not found.');
    }
    $schedule = reservationSchedule($db, $staffId);
    $times = reservationAvailability($db, $staffId, $schedule, $date, $party);
    $day = reservationDate($date);
    $slots = array_map(function(string $time) use ($day, $party): array {
        $start = $day->modify($time);
        return ['value' => $time, 'label' => $start->format('g:i A') . ' - ' . $start->modify('+' . ($party * 45) . ' minutes')->format('g:i A')];
    }, $times);
    echo json_encode([
        'success' => true, 'date' => $date, 'slots' => $slots, 'timezone' => 'America/New_York',
        'min_date' => reservationNow()->format('Y-m-d'),
        'max_date' => reservationNow()->modify('+' . $schedule['days_ahead'] . ' days')->format('Y-m-d'),
    ]);
} catch (InvalidArgumentException $e) {
    reservationJsonError(422, $e->getMessage());
} catch (ClientQDatabaseException $e) {
    clientqLog('ClientQ reservation availability failed: ' . $e->getMessage());
    reservationJsonError(500, 'Reservation availability could not be loaded.');
}
