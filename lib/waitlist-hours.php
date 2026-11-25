<?php

function waitlistNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
}

function waitlistDate(string $date): DateTimeImmutable {
    if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date)) {
        throw new InvalidArgumentException('Choose a valid workday.');
    }
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/New_York'));
    if ($day === false || $day->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Choose a valid workday.');
    }
    return $day;
}

function validateWaitlistHours(array $input): array {
    if (!is_string($input['date'] ?? null)) {
        throw new InvalidArgumentException('Choose a valid workday.');
    }
    $day = waitlistDate($input['date']);
    if ($day < waitlistNow()->setTime(0, 0)) {
        throw new InvalidArgumentException('Choose today or a future workday.');
    }
    if (!is_bool($input['is_closed'] ?? null)) {
        throw new InvalidArgumentException('Choose whether this workday is open or closed.');
    }
    foreach (['opening_time', 'closing_time'] as $field) {
        if (!is_string($input[$field] ?? null)
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $input[$field])) {
            throw new InvalidArgumentException('Choose valid start and end times.');
        }
    }
    if ($input['closing_time'] <= $input['opening_time']) {
        throw new InvalidArgumentException('The end time must be after the start time on the same workday.');
    }
    return array_intersect_key($input, array_flip(['date', 'is_closed', 'opening_time', 'closing_time']));
}

function waitlistHours(PDO $db, string $date, ?int $staffId = null): array {
    waitlistDate($date);
    if ($staffId === null) {
        $stmt = $db->prepare('SELECT is_closed, opening_time, closing_time FROM waitlist_daily_hours WHERE work_date = ?');
        $stmt->execute([$date]);
    } else {
        $stmt = $db->prepare('SELECT is_closed, opening_time, closing_time FROM staff_daily_availability WHERE work_date = ? AND staff_id = ?');
        $stmt->execute([$date, $staffId]);
    }
    $row = $stmt->fetch();
    return [
        'date' => $date,
        'is_closed' => $row !== false && (bool)$row['is_closed'],
        'opening_time' => $row === false ? '08:00' : substr($row['opening_time'], 0, 5),
        'closing_time' => $row === false ? '16:30' : substr($row['closing_time'], 0, 5),
    ];
}

function waitlistHoursState(array $hours, DateTimeImmutable $now): array {
    $now = $now->setTimezone(new DateTimeZone('America/New_York'));
    $day = waitlistDate($hours['date']);
    $opening = $day->modify($hours['opening_time']);
    $closing = $day->modify($hours['closing_time']);
    return $hours + [
        'is_open' => !$hours['is_closed'] && $now >= $opening && $now < $closing,
        'is_upcoming' => !$hours['is_closed'] && $now < $opening,
        'is_finished' => $hours['is_closed'] || $now >= $closing,
        'opening_label' => $opening->format('g:i A'),
        'closing_label' => $closing->format('g:i A'),
    ];
}

function waitlistDailyState(PDO $db, DateTimeImmutable $now): array {
    $now = $now->setTimezone(new DateTimeZone('America/New_York'));
    $date = $now->format('Y-m-d');
    $hours = waitlistHoursState(waitlistHours($db, $date), $now);
    if ($hours['is_closed']) {
        $message = 'The waitlist is closed for today.';
    } elseif ($hours['is_upcoming']) {
        $message = 'Today\'s waitlist opens at ' . $hours['opening_label'] . ' and closes at ' . $hours['closing_label'] . ' (Eastern time).';
    } elseif (!$hours['is_open']) {
        $message = 'Today\'s waitlist closed at ' . $hours['closing_label'] . ' (Eastern time).';
    } else {
        $message = 'Today\'s waitlist is open until ' . $hours['closing_label'] . ' (Eastern time).';
    }
    $hours['message'] = $message;
    $stmt = $db->prepare(
        'SELECT s.id, s.name, s.goes_by, COALESCE(a.is_closed, 0) AS is_closed,
            COALESCE(a.opening_time, \'08:00:00\') AS opening_time,
            COALESCE(a.closing_time, \'16:30:00\') AS closing_time
         FROM staff s LEFT JOIN staff_daily_availability a ON a.staff_id = s.id AND a.work_date = ?
         WHERE s.is_active = 1 ORDER BY s.id'
    );
    $stmt->execute([$date]);
    $staff = [];
    $available = [];
    foreach ($stmt->fetchAll() as $row) {
        $member = ['id' => $row['id'], 'goes_by' => staffDisplayName($row)];
        $staff[] = $member;
        $schedule = waitlistHoursState([
            'date' => $date, 'is_closed' => (bool)$row['is_closed'],
            'opening_time' => substr($row['opening_time'], 0, 5),
            'closing_time' => substr($row['closing_time'], 0, 5),
        ], $now);
        if ($schedule['is_finished']) {
            continue;
        }
        $available[] = $member + [
            'opening_time' => $schedule['opening_time'],
            'closing_time' => $schedule['closing_time'],
            'selection_label' => $member['goes_by'] . ($schedule['is_upcoming'] ? ' (starts at ' . $schedule['opening_label'] . ')' : ''),
            'availability_notice' => $schedule['is_upcoming']
                ? $member['goes_by'] . ' starts at ' . $schedule['opening_label'] . ' today (Eastern time). You may join their waitlist now, but service will not begin before then.'
                : '',
        ];
    }
    if ($hours['is_open'] && $available === []) {
        $hours['message'] = 'No staff are available for this workday. New waitlist signups are unavailable.';
    }
    $hours['can_join'] = $hours['is_open'] && $available !== [];
    return ['waitlist' => $hours, 'staff' => $staff, 'available_staff' => $available];
}

function lockWaitlistHours(PDO $db): void {
    if (!$db->inTransaction()) {
        throw new LogicException('Waitlist changes require an active transaction.');
    }
    if ($db->query('SELECT id FROM business_settings WHERE id = 1 FOR UPDATE')->fetchColumn() === false) {
        throw new RuntimeException('Business settings are missing.');
    }
}
