<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/waitlist-hours.php';

requireTenant(true);
$staff = getSessionStaff();
if ($staff === null) {
    dailyHoursError(401, 'Not authenticated.');
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    dailyHoursError(405, 'Method not allowed.');
}
$input = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    dailyHoursError(400, 'Invalid request body.');
}
$scope = $input['scope'] ?? null;
if (!in_array($scope, ['waitlist', 'staff'], true)) {
    dailyHoursError(422, 'Choose waitlist hours or your staff availability.');
}
if ($scope === 'waitlist' && $staff['role'] !== 'owner') {
    dailyHoursError(403, 'Owner access is required.');
}
$staffId = $scope === 'staff' ? (int)$staff['id'] : null;
$db = getTenantDB();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $date = $input['date'] ?? waitlistNow()->format('Y-m-d');
        if (!is_string($date)) {
            throw new InvalidArgumentException('Choose a valid workday.');
        }
        echo json_encode(['success' => true, 'schedule' => waitlistHours($db, $date, $staffId)]);
        exit;
    }
    if (!is_string($input['csrf_token'] ?? null) || !verifyPlatformCsrfToken($input['csrf_token'])) {
        dailyHoursError(403, 'Expired website session. Consider refreshing the page.');
    }
    $hours = validateWaitlistHours($input);
    $db->beginTransaction();
    lockWaitlistHours($db);
    $active = $db->prepare('SELECT id FROM staff WHERE id = ? AND is_active = 1 FOR UPDATE');
    $active->execute([$staff['id']]);
    if ($active->fetchColumn() === false) {
        $db->rollBack();
        dailyHoursError(401, 'Not authenticated.');
    }
    if ($staffId === null) {
        $stmt = $db->prepare(
            'INSERT INTO waitlist_daily_hours (work_date, is_closed, opening_time, closing_time) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_closed = VALUES(is_closed), opening_time = VALUES(opening_time), closing_time = VALUES(closing_time)'
        );
        $stmt->execute([$hours['date'], (int)$hours['is_closed'], $hours['opening_time'], $hours['closing_time']]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO staff_daily_availability (staff_id, work_date, is_closed, opening_time, closing_time) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_closed = VALUES(is_closed), opening_time = VALUES(opening_time), closing_time = VALUES(closing_time)'
        );
        $stmt->execute([$staffId, $hours['date'], (int)$hours['is_closed'], $hours['opening_time'], $hours['closing_time']]);
    }
    $db->commit();
    clientqLog('ClientQ daily ' . $scope . ' hours saved: staff ' . $staff['id'] . ', date ' . $hours['date'] . '.', level: 'INFO');
    echo json_encode(['success' => true, 'schedule' => $hours]);
} catch (InvalidArgumentException $e) {
    dailyHoursError(422, $e->getMessage());
} catch (ClientQDatabaseException | RuntimeException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ daily hours update failed: ' . $e->getMessage());
    dailyHoursError(500, 'Daily hours could not be loaded or saved. Please try again.');
}

function dailyHoursError(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}
