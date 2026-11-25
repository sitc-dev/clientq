<?php

function reservationNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
}

function reservationDate(string $date): DateTimeImmutable {
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) {
        throw new InvalidArgumentException('Choose a valid reservation date.');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/New_York'));
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Choose a valid reservation date.');
    }
    return $parsed;
}

function reservationSchedule(PDO $db, int $staffId): array {
    $stmt = $db->prepare('SELECT working_days, opening_time, closing_time, days_ahead FROM staff_reservation_schedules WHERE staff_id = :id');
    $stmt->execute([':id' => $staffId]);
    $row = $stmt->fetch();
    return [
        'days' => $row ? array_map('intval', array_filter(explode(',', $row['working_days']), 'strlen')) : [],
        'opening_time' => $row ? substr($row['opening_time'], 0, 5) : '08:30',
        'closing_time' => $row ? substr($row['closing_time'], 0, 5) : '17:00',
        'days_ahead' => $row ? (int)$row['days_ahead'] : 14,
    ];
}

function validateReservationSchedule(array $input): array {
    $days = $input['days'] ?? null;
    $opening = $input['opening_time'] ?? null;
    $closing = $input['closing_time'] ?? null;
    $ahead = filter_var($input['days_ahead'] ?? null, FILTER_VALIDATE_INT);
    if (!is_array($days) || count($days) > 7) {
        throw new InvalidArgumentException('Choose valid working days.');
    }
    foreach ($days as $day) {
        if (!is_int($day) || $day < 1 || $day > 7) {
            throw new InvalidArgumentException('Choose valid working days.');
        }
    }
    foreach ([$opening, $closing] as $time) {
        if (!is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            throw new InvalidArgumentException('Choose valid opening and closing times.');
        }
    }
    if ($closing <= $opening || $closing > '23:15') {
        throw new InvalidArgumentException('Closing time must follow opening time and be no later than 11:15 PM.');
    }
    if ($ahead === false || $ahead < 1 || $ahead > 90) {
        throw new InvalidArgumentException('Allow bookings between 1 and 90 days ahead.');
    }
    $days = array_values(array_unique($days));
    sort($days);
    return ['days' => $days, 'opening_time' => $opening, 'closing_time' => $closing, 'days_ahead' => $ahead];
}

function reservationSlotStarts(array $schedule, string $date, int $partySize, array $occupied, DateTimeImmutable $now): array {
    $day = reservationDate($date);
    $today = $now->setTimezone(new DateTimeZone('America/New_York'))->setTime(0, 0);
    if ($day < $today || $day > $today->modify('+' . $schedule['days_ahead'] . ' days')) {
        throw new InvalidArgumentException('Choose a date within this staff member\'s booking window.');
    }
    if ($partySize < 1 || $partySize > 10) {
        throw new InvalidArgumentException('Party size must be between 1 and 10.');
    }
    if (!in_array((int)$day->format('N'), $schedule['days'], true)) {
        return [];
    }
    $opening = $day->modify($schedule['opening_time']);
    $closing = $day->modify($schedule['closing_time']);
    $starts = [];
    for ($time = $opening; $time < $closing; $time = $time->modify('+45 minutes')) {
        $starts[] = $time;
    }
    $available = [];
    for ($i = 0; $i + $partySize <= count($starts); $i++) {
        if ($starts[$i] <= $now) {
            continue;
        }
        $start = $starts[$i]->format('Y-m-d H:i:s');
        $end = $starts[$i]->modify('+' . ($partySize * 45) . ' minutes')->format('Y-m-d H:i:s');
        foreach ($occupied as $booking) {
            if ($start < $booking['ends_at'] && $end > $booking['starts_at']) {
                continue 2;
            }
        }
        $available[] = $starts[$i]->format('H:i');
    }
    return $available;
}

function reservationAvailability(PDO $db, int $staffId, array $schedule, string $date, int $partySize): array {
    $day = reservationDate($date);
    $stmt = $db->prepare('SELECT starts_at, ends_at FROM reservations WHERE staff_id = :staff AND ends_at > :day AND starts_at < :next_day');
    $stmt->execute([':staff' => $staffId, ':day' => $day->format('Y-m-d'), ':next_day' => $day->modify('+1 day')->format('Y-m-d')]);
    return reservationSlotStarts($schedule, $date, $partySize, $stmt->fetchAll(), reservationNow());
}

function createReservation(PDO $db, int $staffId, string $date, string $time, int $partySize, string $name, string $phone): array {
    if ($name === '' || strlen($name) > 100 || !preg_match('/^[a-zA-Z\s]+$/', $name)) {
        throw new InvalidArgumentException('Enter a name up to 100 characters using letters and spaces.');
    }
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        throw new InvalidArgumentException('Enter a valid 10-digit phone number.');
    }
    $db->beginTransaction();
    try {
        $staff = $db->prepare('SELECT id FROM staff WHERE id = :id AND is_active = 1 FOR UPDATE');
        $staff->execute([':id' => $staffId]);
        if (!$staff->fetch()) {
            throw new InvalidArgumentException('Choose an active staff member.');
        }
        $schedule = reservationSchedule($db, $staffId);
        $available = reservationAvailability($db, $staffId, $schedule, $date, $partySize);
        if (!in_array($time, $available, true)) {
            throw new DomainException('That time is no longer available for your party. Choose another slot.');
        }
        $start = reservationDate($date)->modify($time);
        $end = $start->modify('+' . ($partySize * 45) . ' minutes');
        $code = strtoupper(bin2hex(random_bytes(3)));
        $stmt = $db->prepare('INSERT INTO reservations (staff_id, name, phone, party_size, starts_at, ends_at, confirmation_code) VALUES (:staff, :name, :phone, :party, :start, :end, :code)');
        $stmt->execute([':staff' => $staffId, ':name' => $name, ':phone' => $phone, ':party' => $partySize, ':start' => $start->format('Y-m-d H:i:s'), ':end' => $end->format('Y-m-d H:i:s'), ':code' => $code]);
        $id = (int)$db->lastInsertId();
        $slot = $db->prepare('INSERT INTO reservation_slots (staff_id, starts_at, reservation_id) VALUES (:staff, :start, :reservation)');
        for ($member = 0; $member < $partySize; $member++) {
            $slot->execute([':staff' => $staffId, ':start' => $start->modify('+' . ($member * 45) . ' minutes')->format('Y-m-d H:i:s'), ':reservation' => $id]);
        }
        $db->commit();
        return ['id' => $id, 'confirmation_code' => $code, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s')];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function reservationJsonError(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}
