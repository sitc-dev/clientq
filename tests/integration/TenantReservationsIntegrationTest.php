<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once dirname(__DIR__, 2) . '/lib/reservations.php';

final class TenantReservationsIntegrationTest extends TestCase {
    private string $slug;
    private string $database;
    private string $cookie;
    private PDO $tenantDb;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1' || DB_HOST !== 'db' || DB_NAME !== 'clientq') {
            self::markTestSkipped('Run against local Docker databases only.');
        }
        $this->slug = 'reservation-test-' . bin2hex(random_bytes(5));
        $this->database = 'clientq_tenant_' . substr(hash('sha256', $this->slug), 0, 32);
        $this->cookie = tempnam(sys_get_temp_dir(), 'clientq-reservation-cookie-');
        $db = getDB();
        $db->exec("CREATE DATABASE `{$this->database}`");
        $this->tenantDb = createDatabaseConnection($this->database);
        $this->tenantDb->exec(file_get_contents(dirname(__DIR__, 2) . '/database/tenant-schema.sql'));
        $stmt = $db->prepare('INSERT INTO businesses (name, slug, owner_email, database_name) VALUES (?, ?, ?, ?)');
        $stmt->execute(['Reservation Test', $this->slug, $this->slug . '@example.com', $this->database]);
        $stmt = $this->tenantDb->prepare("INSERT INTO staff (name, first_name, goes_by, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ([1 => 'owner', 2 => 'staff'] as $id => $role) {
            $email = $id . '-' . $this->slug . '@example.com';
            $stmt->execute(['Reservation Tester', 'Reservation', 'Tester ' . $id, $email, $email, password_hash('reservation-test-password', PASSWORD_DEFAULT), $role]);
        }
    }

    protected function tearDown(): void {
        if (!isset($this->slug)) {
            return;
        }
        $stmt = getDB()->prepare('DELETE FROM businesses WHERE slug = ? AND database_name = ?');
        $stmt->execute([$this->slug, $this->database]);
        getDB()->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        if (is_file($this->cookie)) {
            unlink($this->cookie);
        }
    }

    private function request(string $method, string $path, ?array $input = null, bool $json = true, ?string $host = null): array {
        $curl = curl_init((getenv('CLIENTQ_INTEGRATION_BASE_URL') ?: 'http://127.0.0.1') . $path);
        $headers = ['Host: ' . ($host ?? $this->slug . '.' . BASE_DOMAIN)];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_COOKIEFILE => $this->cookie, CURLOPT_TIMEOUT => 20,
        ]);
        if ($input !== null) {
            if ($json) {
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json ? json_encode($input, JSON_THROW_ON_ERROR) : http_build_query($input));
            if (!$json) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($curl);
        self::assertNotFalse($body, curl_error($curl));
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => $body, 'data' => json_decode($body, true)];
    }

    private function token(): string {
        $page = $this->request('GET', '/');
        self::assertSame(200, $page['status']);
        self::assertSame(1, preg_match('/id="reservationForm" data-csrf="([a-f0-9]+)"/', $page['body'], $match));
        return $match[1];
    }

    private function seedPublishedSchedules(): void {
        $this->tenantDb->exec("INSERT INTO staff_reservation_schedules (staff_id, working_days, opening_time, closing_time, days_ahead)
            SELECT id, '2,3,4,5,6,7', '08:30:00', '17:00:00', 14 FROM staff");
    }

    public function testPlanPermissionsControlPrimaryAndInnerTabsAndAppointmentApis(): void {
        $this->seedPublishedSchedules();
        $stmt = getDB()->prepare('UPDATE businesses SET plan = ?, appointments_enabled = ? WHERE slug = ?');
        foreach ([['starter', 0, false], ['starter', 1, true], ['growth', 0, true], ['pro', 0, true]] as [$plan, $addon, $enabled]) {
            $stmt->execute([$plan, $addon, $this->slug]);
            $page = $this->request('GET', '/');
            self::assertSame(200, $page['status']);
            self::assertSame(1, preg_match('/id="signupTab"[^>]*>\\s*([^<]+)\\s*<\\/button>/', $page['body'], $match));
            self::assertSame($enabled ? 'Sign Up/Book Appointment' : 'Sign Up', trim($match[1]));
            self::assertStringContainsString('id="signInForm"', $page['body']);
            foreach (['walkinTab', 'reservationTab', 'reservationForm'] as $id) {
                self::assertSame($enabled, str_contains($page['body'], 'id="' . $id . '"'), $plan . ': ' . $id);
            }
            $date = reservationNow()->modify('+1 day')->format('Y-m-d');
            self::assertSame($enabled ? 200 : 403, $this->request('GET', '/api/reservation-slots?staff_id=1&date=' . $date)['status']);
            if (!$enabled) {
                self::assertSame(403, $this->request('POST', '/api/book-reservation', [])['status']);
            }
        }
    }

    public function testOwnerAndStaffMustEachPublishTheirOwnAvailabilityBeforeBooking(): void {
        $stmt = getDB()->prepare("UPDATE businesses SET plan = 'starter', appointments_enabled = 1 WHERE slug = ?");
        $stmt->execute([$this->slug]);
        $date = reservationNow()->modify('+1 day')->format('Y-m-d');
        $token = $this->token();
        $page = $this->request('GET', '/');
        self::assertStringContainsString('No reservations available.', $page['body']);
        foreach ([1, 2] as $staffId) {
            $slots = $this->request('GET', '/api/reservation-slots?staff_id=' . $staffId . '&date=' . $date);
            self::assertSame(200, $slots['status'], $slots['body']);
            self::assertSame([], $slots['data']['slots']);
            $booking = ['csrf_token' => $token, 'staff_id' => $staffId, 'date' => $date,
                'time' => '10:00', 'party_size' => 1, 'name' => 'Reservation Client', 'phone' => '2025550123'];
            self::assertSame(409, $this->request('POST', '/api/book-reservation', $booking)['status']);
        }
        self::assertSame(0, (int)$this->tenantDb->query('SELECT COUNT(*) FROM staff_reservation_schedules')->fetchColumn());
        foreach ([1, 2] as $staffId) {
            $login = $this->request('POST', '/admin/login', ['email' => $staffId . '-' . $this->slug . '@example.com', 'password' => 'reservation-test-password'], false);
            self::assertSame(302, $login['status']);
            $token = $this->token();
            $initial = $this->request('GET', '/admin/api/reservations?date=' . $date);
            self::assertSame(200, $initial['status'], $initial['body']);
            self::assertSame([], $initial['data']['schedule']['days']);
            $schedule = ['csrf_token' => $token, 'action' => 'schedule', 'staff_id' => 3 - $staffId,
                'days' => [1, 2, 3, 4, 5, 6, 7], 'opening_time' => '10:00', 'closing_time' => '11:00', 'days_ahead' => 14];
            $saved = $this->request('POST', '/admin/api/reservations', $schedule);
            self::assertSame(200, $saved['status'], $saved['body']);
            if ($staffId === 1) {
                self::assertSame([], $this->request('GET', '/api/reservation-slots?staff_id=2&date=' . $date)['data']['slots']);
            }
            $slots = $this->request('GET', '/api/reservation-slots?staff_id=' . $staffId . '&date=' . $date);
            self::assertSame(['10:00', '10:45'], array_column($slots['data']['slots'], 'value'));
            $booking = ['csrf_token' => $token, 'staff_id' => $staffId, 'date' => $date,
                'time' => '10:00', 'party_size' => 1, 'name' => 'Reservation Client', 'phone' => '2025550123'];
            $created = $this->request('POST', '/api/book-reservation', $booking);
            self::assertSame(201, $created['status'], $created['body']);
            self::assertSame(200, $this->request('POST', '/admin/api/reservations', array_replace($schedule, ['days' => []]))['status']);
            self::assertSame([], $this->request('GET', '/api/reservation-slots?staff_id=' . $staffId . '&date=' . $date)['data']['slots']);
            self::assertSame(409, $this->request('POST', '/api/book-reservation', array_replace($booking, ['time' => '10:45']))['status']);
            self::assertSame(200, $this->request('POST', '/admin/api/reservations', ['csrf_token' => $token, 'action' => 'cancel', 'id' => $created['data']['reservation']['id']])['status']);
            $this->request('GET', '/admin/logout');
        }
    }

    public function testPartyBookingConflictCancellationAndStaffIsolation(): void {
        $this->seedPublishedSchedules();
        $token = $this->token();
        $date = reservationNow()->modify('+1 day');
        while ($date->format('N') === '1') {
            $date = $date->modify('+1 day');
        }
        $day = $date->format('Y-m-d');
        $available = $this->request('GET', '/api/reservation-slots?' . http_build_query(['staff_id' => 1, 'date' => $day, 'party_size' => 2]));
        self::assertSame(200, $available['status'], $available['body']);
        self::assertCount(11, $available['data']['slots']);
        $booking = ['csrf_token' => $token, 'staff_id' => 1, 'date' => $day, 'time' => '08:30', 'party_size' => 2, 'name' => 'Reservation Client', 'phone' => '2025550123'];
        $created = $this->request('POST', '/api/book-reservation', $booking);
        self::assertSame(201, $created['status'], $created['body']);
        self::assertSame($day . ' 10:00:00', $created['data']['reservation']['ends_at']);
        self::assertSame(0, (int)$this->tenantDb->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(2, (int)$this->tenantDb->query('SELECT COUNT(*) FROM reservation_slots')->fetchColumn());
        $conflict = $this->request('POST', '/api/book-reservation', array_replace($booking, ['time' => '09:15', 'party_size' => 1]));
        self::assertSame(409, $conflict['status']);
        $otherStaff = $this->request('POST', '/api/book-reservation', array_replace($booking, ['staff_id' => 2]));
        self::assertSame(201, $otherStaff['status'], $otherStaff['body']);
        $login = $this->request('POST', '/admin/login', ['email' => '1-' . $this->slug . '@example.com', 'password' => 'reservation-test-password'], false);
        self::assertSame(302, $login['status']);
        $mine = $this->request('GET', '/admin/api/reservations?date=' . $day);
        self::assertSame(200, $mine['status'], $mine['body']);
        self::assertCount(1, $mine['data']['reservations']);
        $notMine = $this->request('POST', '/admin/api/reservations', ['action' => 'cancel', 'csrf_token' => $token, 'id' => $otherStaff['data']['reservation']['id']]);
        self::assertSame(404, $notMine['status']);
        $deactivate = $this->request('POST', '/admin/api/deactivate-employee', ['id' => 2, 'csrf_token' => $token]);
        self::assertSame(409, $deactivate['status']);
        $cancel = $this->request('POST', '/admin/api/reservations', ['action' => 'cancel', 'csrf_token' => $token, 'id' => $created['data']['reservation']['id']]);
        self::assertSame(200, $cancel['status'], $cancel['body']);
        self::assertSame(2, (int)$this->tenantDb->query('SELECT COUNT(*) FROM reservation_slots')->fetchColumn());
        $rebook = $this->request('POST', '/api/book-reservation', $booking);
        self::assertSame(201, $rebook['status'], $rebook['body']);
    }

    public function testScheduleChangesDateBoundsAndRequestValidation(): void {
        $token = $this->token();
        self::assertSame(401, $this->request('GET', '/admin/api/reservations')['status']);
        self::assertSame(404, $this->request('GET', '/api/reservation-slots?staff_id=1', null, true, BASE_DOMAIN)['status']);
        $invalid = ['csrf_token' => 'wrong', 'staff_id' => 1];
        self::assertSame(403, $this->request('POST', '/api/book-reservation', $invalid)['status']);
        $this->request('POST', '/admin/login', ['email' => '1-' . $this->slug . '@example.com', 'password' => 'reservation-test-password'], false);
        $schedule = ['csrf_token' => $token, 'action' => 'schedule', 'days' => [1, 2, 3, 4, 5, 6, 7], 'opening_time' => '10:00', 'closing_time' => '11:00', 'days_ahead' => 3, 'staff_id' => 2];
        $saved = $this->request('POST', '/admin/api/reservations', $schedule);
        self::assertSame(200, $saved['status'], $saved['body']);
        self::assertSame([], reservationSchedule($this->tenantDb, 2)['days']);
        $date = reservationNow()->modify('+1 day')->format('Y-m-d');
        $slots = $this->request('GET', '/api/reservation-slots?staff_id=1&date=' . $date);
        self::assertSame(['10:00', '10:45'], array_column($slots['data']['slots'], 'value'));
        $tooFar = $this->request('GET', '/api/reservation-slots?staff_id=1&date=' . reservationNow()->modify('+4 days')->format('Y-m-d'));
        self::assertSame(422, $tooFar['status']);
        $invalidDate = $this->request('GET', '/api/reservation-slots?staff_id=1&date=2026-02-30');
        self::assertSame(422, $invalidDate['status']);
        $closed = $this->request('POST', '/admin/api/reservations', array_replace($schedule, ['days' => []]));
        self::assertSame(200, $closed['status']);
        self::assertSame([], $this->request('GET', '/api/reservation-slots?staff_id=1&date=' . $date)['data']['slots']);
    }

    public function testWaitlistOnlyEssentialsBlocksNewBookingsButPreservesViewingAndCancellation(): void {
        $this->seedPublishedSchedules();
        $token = $this->token();
        $date = reservationNow()->modify('+1 day');
        while ($date->format('N') === '1') {
            $date = $date->modify('+1 day');
        }
        $booking = ['csrf_token' => $token, 'staff_id' => 1, 'date' => $date->format('Y-m-d'),
            'time' => '08:30', 'party_size' => 1, 'name' => 'Existing Client', 'phone' => '2025550123'];
        $created = $this->request('POST', '/api/book-reservation', $booking);
        self::assertSame(201, $created['status'], $created['body']);
        $stmt = getDB()->prepare("UPDATE businesses SET plan = 'starter', appointments_enabled = 0 WHERE slug = ?");
        $stmt->execute([$this->slug]);
        self::assertSame(403, $this->request('POST', '/api/book-reservation', array_replace($booking, ['time' => '09:15']))['status']);
        self::assertSame(403, $this->request('GET', '/api/reservation-slots?staff_id=1&date=' . $booking['date'])['status']);
        $public = $this->request('GET', '/');
        self::assertStringNotContainsString('id="reservationForm"', $public['body']);
        self::assertStringContainsString('id="signInForm"', $public['body']);
        self::assertSame(1, (int)$this->tenantDb->query('SELECT COUNT(*) FROM reservations')->fetchColumn());
        $this->request('POST', '/admin/login', ['email' => '1-' . $this->slug . '@example.com', 'password' => 'reservation-test-password'], false);
        $saved = $this->request('GET', '/admin/api/reservations?date=' . $booking['date']);
        self::assertSame(200, $saved['status'], $saved['body']);
        self::assertFalse($saved['data']['appointments_enabled']);
        self::assertCount(1, $saved['data']['reservations']);
        $schedule = ['csrf_token' => $token, 'action' => 'schedule', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'opening_time' => '10:00', 'closing_time' => '17:00', 'days_ahead' => 14];
        self::assertSame(403, $this->request('POST', '/admin/api/reservations', $schedule)['status']);
        $cancelled = $this->request('POST', '/admin/api/reservations', ['csrf_token' => $token, 'action' => 'cancel', 'id' => $created['data']['reservation']['id']]);
        self::assertSame(200, $cancelled['status'], $cancelled['body']);
        self::assertSame(0, (int)$this->tenantDb->query('SELECT COUNT(*) FROM reservations')->fetchColumn());
        $stmt = getDB()->prepare('UPDATE businesses SET appointments_enabled = 1 WHERE slug = ?');
        $stmt->execute([$this->slug]);
        self::assertSame(201, $this->request('POST', '/api/book-reservation', $booking)['status']);
    }

    public function testConcurrentBookingsCannotReserveTheSameSlot(): void {
        $this->seedPublishedSchedules();
        $firstCookie = $this->cookie;
        $firstToken = $this->token();
        $secondCookie = tempnam(sys_get_temp_dir(), 'clientq-reservation-cookie-');
        $multi = curl_multi_init();
        $handles = [];
        try {
            $this->cookie = $secondCookie;
            $secondToken = $this->token();
            $date = reservationNow()->modify('+1 day');
            while ($date->format('N') === '1') {
                $date = $date->modify('+1 day');
            }
            foreach ([[$firstCookie, $firstToken], [$secondCookie, $secondToken]] as [$cookie, $token]) {
                $curl = curl_init((getenv('CLIENTQ_INTEGRATION_BASE_URL') ?: 'http://127.0.0.1') . '/api/book-reservation');
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => ['Host: ' . $this->slug . '.' . BASE_DOMAIN, 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode([
                        'csrf_token' => $token, 'staff_id' => 1, 'date' => $date->format('Y-m-d'),
                        'time' => '08:30', 'party_size' => 2, 'name' => 'Concurrent Client', 'phone' => '2025550123',
                    ], JSON_THROW_ON_ERROR),
                ]);
                $handles[] = $curl;
                curl_multi_add_handle($multi, $curl);
            }
            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 1);
                }
            } while ($running);
            $statuses = array_map(fn($curl): int => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $handles);
            sort($statuses);
            self::assertSame([201, 409], $statuses, implode("\n", array_map('curl_multi_getcontent', $handles)));
            self::assertSame(1, (int)$this->tenantDb->query('SELECT COUNT(*) FROM reservations')->fetchColumn());
            self::assertSame(2, (int)$this->tenantDb->query('SELECT COUNT(*) FROM reservation_slots')->fetchColumn());
        } finally {
            foreach ($handles as $curl) {
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
            }
            curl_multi_close($multi);
            $this->cookie = $firstCookie;
            unlink($secondCookie);
        }
    }
}
