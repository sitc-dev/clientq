<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/reservations.php';
$tenant = requireTenant(true);
$staff = getSessionStaff();
if ($staff === null) {
    reservationJsonError(401, 'Not authenticated.');
}
$db = getTenantDB();
$staffId = (int)$staff['id'];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $date = $_GET['date'] ?? reservationNow()->format('Y-m-d');
        if (!is_string($date)) {
            throw new InvalidArgumentException('Choose a valid reservation date.');
        }
        $day = reservationDate($date);
        $stmt = $db->prepare('SELECT id, name, phone, party_size, starts_at, ends_at, confirmation_code FROM reservations WHERE staff_id = :staff AND starts_at >= :day AND starts_at < :next_day ORDER BY starts_at');
        $stmt->execute([':staff' => $staffId, ':day' => $day->format('Y-m-d'), ':next_day' => $day->modify('+1 day')->format('Y-m-d')]);
        echo json_encode(['success' => true, 'appointments_enabled' => appointmentsEnabled($tenant), 'schedule' => reservationSchedule($db, $staffId), 'date' => $date, 'reservations' => $stmt->fetchAll()]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        reservationJsonError(405, 'Method not allowed.');
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        reservationJsonError(400, 'Invalid request body.');
    }
    $token = $input['csrf_token'] ?? null;
    if (!is_string($token) || !verifyPlatformCsrfToken($token)) {
        reservationJsonError(403, 'Expired website session. Consider refreshing the page.');
    }
    $action = $input['action'] ?? null;
    if ($action !== 'schedule' && $action !== 'cancel') {
        reservationJsonError(422, 'Choose a valid reservation action.');
    }
    if ($action === 'schedule' && !appointmentsEnabled($tenant)) {
        reservationJsonError(403, 'Appointment scheduling is not included in this Essentials subscription. Existing reservations can still be viewed and cancelled.');
    }
    $schedule = $action === 'schedule' ? validateReservationSchedule($input) : null;
    $id = $action === 'cancel' ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    if ($action === 'cancel' && !$id) {
        reservationJsonError(422, 'Choose a valid reservation.');
    }
    $db->beginTransaction();
    $lock = $db->prepare('SELECT id FROM staff WHERE id = :id AND is_active = 1 FOR UPDATE');
    $lock->execute([':id' => $staffId]);
    if (!$lock->fetch()) {
        $db->rollBack();
        reservationJsonError(401, 'Not authenticated.');
    }
    if ($action === 'schedule') {
        $stmt = $db->prepare('INSERT INTO staff_reservation_schedules (staff_id, working_days, opening_time, closing_time, days_ahead) VALUES (:staff, :days, :opening, :closing, :ahead) ON DUPLICATE KEY UPDATE working_days = VALUES(working_days), opening_time = VALUES(opening_time), closing_time = VALUES(closing_time), days_ahead = VALUES(days_ahead)');
        $stmt->execute([':staff' => $staffId, ':days' => implode(',', $schedule['days']), ':opening' => $schedule['opening_time'], ':closing' => $schedule['closing_time'], ':ahead' => $schedule['days_ahead']]);
    } else {
        $stmt = $db->prepare('DELETE FROM reservations WHERE id = :id AND staff_id = :staff');
        $stmt->execute([':id' => $id, ':staff' => $staffId]);
        if ($stmt->rowCount() !== 1) {
            $db->rollBack();
            reservationJsonError(404, 'Reservation not found.');
        }
    }
    $db->commit();
    clientqLog('ClientQ reservation ' . $action . ' saved: staff ' . $staffId, level: 'INFO');
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $e) {
    reservationJsonError(422, $e->getMessage());
} catch (ClientQDatabaseException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ staff reservation update failed: ' . $e->getMessage());
    reservationJsonError(500, 'Reservation details could not be loaded or saved.');
}
