<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once dirname(__DIR__, 2) . '/lib/waitlist-hours.php';
require_once dirname(__DIR__, 2) . '/lib/employee-invitation.php';

final class WaitlistAssignmentIntegrationTest extends TestCase {
    private array $tenants = [];
    private PDO $db;
    private string $cookie;
    private string $token = '';

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1' || DB_HOST !== 'db'
            || (DB_NAME !== 'clientq' && !preg_match('/^clientq_tenant_(daily_hours|access)_test_[a-f0-9]{12}$/D', DB_NAME))) {
            self::markTestSkipped('Run against local Docker databases only.');
        }
        if (waitlistNow()->format('H:i') >= '23:58') {
            self::markTestSkipped('Avoid crossing the workday boundary during live HTTP fixtures.');
        }
        self::assertSame('', getenv('CLIENTQ_TWILIO_ACCOUNT_SID') ?: '', 'Disable SMS credentials for these fixtures.');
        $this->cookie = tempnam(sys_get_temp_dir(), 'clientq-waitlist-cookie-');
        $hash = password_hash('waitlist-fixture-password', PASSWORD_DEFAULT);
        foreach (['waitlist-test-', 'waitlist-other-'] as $prefix) {
            $slug = $prefix . bin2hex(random_bytes(5));
            $database = 'clientq_tenant_' . substr(hash('sha256', $slug), 0, 32);
            $this->tenants[] = ['slug' => $slug, 'database' => $database];
            getDB()->exec("CREATE DATABASE `$database`");
            $db = createDatabaseConnection($database);
            $db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/tenant-schema.sql'));
            $stmt = $db->prepare('INSERT INTO staff (name, goes_by, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ([1 => 'owner', 2 => 'staff', 3 => 'staff'] as $id => $role) {
                $stmt->execute(['Fixture Staff', 'Staff ' . $id, 'staff' . $id . '@example.com', 'staff' . $id . '@example.com', $hash, $role]);
            }
            $date = waitlistNow()->format('Y-m-d');
            $db->prepare("INSERT INTO waitlist_daily_hours (work_date, opening_time, closing_time) VALUES (?, '00:00', '23:59')")
                ->execute([$date]);
            $db->prepare("INSERT INTO staff_daily_availability (staff_id, work_date, opening_time, closing_time) SELECT id, ?, '00:00', '23:59' FROM staff")
                ->execute([$date]);
            $stmt = getDB()->prepare('INSERT INTO businesses (name, slug, owner_email, database_name) VALUES (?, ?, ?, ?)');
            $stmt->execute(['Waitlist Fixture', $slug, $slug . '@example.com', $database]);
            if (!isset($this->db)) {
                $this->db = $db;
            }
        }
    }

    protected function tearDown(): void {
        foreach ($this->tenants as $tenant) {
            $stmt = getDB()->prepare('DELETE FROM businesses WHERE slug = ? AND database_name = ?');
            $stmt->execute([$tenant['slug'], $tenant['database']]);
            getDB()->exec("DROP DATABASE IF EXISTS `{$tenant['database']}`");
        }
        if (isset($this->cookie) && is_file($this->cookie)) {
            unlink($this->cookie);
        }
    }

    private function request(string $method, string $path, mixed $input = null, bool $form = false, ?string $host = null, ?string $sessionId = null): array {
        $curl = curl_init((getenv('CLIENTQ_INTEGRATION_BASE_URL') ?: 'http://127.0.0.1') . $path);
        $headers = ['Host: ' . ($host ?? $this->tenants[0]['slug'] . '.' . BASE_DOMAIN)];
        if ($sessionId !== null) {
            $headers[] = 'Cookie: PHPSESSID=' . $sessionId;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_COOKIEFILE => $this->cookie,
        ]);
        if ($input !== null) {
            if (is_array($input)) {
                $input = array_replace(['csrf_token' => $this->token], $input);
            }
            $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $form ? http_build_query($input) : json_encode($input, JSON_THROW_ON_ERROR));
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($curl);
        self::assertNotFalse($body, curl_error($curl));
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => $body, 'data' => json_decode($body, true)];
    }

    private function login(int $staffId): void {
        $login = $this->request('POST', '/admin/login', ['email' => 'staff' . $staffId . '@example.com', 'password' => 'waitlist-fixture-password'], true);
        self::assertSame(302, $login['status'], $login['body']);
        $page = $this->request('GET', '/admin/dashboard');
        self::assertSame(200, $page['status'], $page['body']);
        self::assertSame(1, preg_match('/const completionToken = "([a-f0-9]+)";/', $page['body'], $match));
        $this->token = $match[1];
    }

    private function client(?int $staffId, string $name = 'Fixture Client'): int {
        $stmt = $this->db->prepare('INSERT INTO clients (name, phone, staff_id, confirmation_code) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, '2025550123', $staffId, strtoupper(bin2hex(random_bytes(3)))]);
        return (int)$this->db->lastInsertId();
    }

    public function testAnyStaffSignupPublicFilteringAndVerifiedSelfDeletion(): void {
        $page = $this->request('GET', '/');
        self::assertStringContainsString('<option value="any">First available staff</option>', $page['body']);
        $input = ['name' => 'Any Client', 'phone' => '2025550123', 'staff_id' => 'any', 'no_in_party' => 2, 'sms_consent' => true];
        $signup = $this->request('POST', '/api/submit-signin', $input);
        self::assertSame(200, $signup['status'], $signup['body']);
        self::assertTrue($signup['data']['success']);
        $client = $this->db->query('SELECT * FROM clients')->fetch();
        self::assertNull($client['staff_id']);
        self::assertSame(2, (int)$client['no_in_party']);
        $this->client(2);
        $public = $this->request('GET', '/api/get-clients');
        self::assertSame(2, (int)$public['data']['total_clients']);
        self::assertCount(2, $public['data']['clients']);
        $any = $this->request('GET', '/api/get-clients?staff=any');
        self::assertSame(1, (int)$any['data']['total_clients']);
        self::assertSame('First available staff', $any['data']['clients'][0]['staff_name']);
        self::assertSame(1, (int)$this->request('GET', '/api/get-clients?staff=2')['data']['total_clients']);
        foreach (['', 0, -1, null, [], '2invalid', 'anyone', 999999] as $invalid) {
            self::assertSame(400, $this->request('POST', '/api/submit-signin', array_replace($input, ['staff_id' => $invalid]))['status']);
        }
        $challenge = $this->db->prepare('INSERT INTO waitlist_deletion_challenges (client_id, code_hash, expires_at) VALUES (?, ?, CURRENT_TIMESTAMP + INTERVAL 10 MINUTE)');
        $challenge->execute([$client['id'], password_hash('123456', PASSWORD_DEFAULT)]);
        $verified = $this->request('POST', '/api/remove-client', [
            'action' => 'verify_code', 'signup_id' => $client['id'], 'phone' => $client['phone'], 'verification_code' => '123456',
        ]);
        self::assertSame(200, $verified['status'], $verified['body']);
        self::assertSame('First available staff', $verified['data']['client']['staff_name']);
        $deleted = $this->request('POST', '/api/remove-client', [
            'action' => 'delete', 'signup_id' => $client['id'], 'phone' => $client['phone'], 'verification_token' => $verified['data']['verification_token'],
        ]);
        self::assertSame(200, $deleted['status'], $deleted['body']);
    }

    public function testClientActionsMatchOwnerManagerAndStaffPermissions(): void {
        $this->db->exec("UPDATE staff SET role = 'manager' WHERE id = 2");
        foreach ([1, 2, 3] as $staffId) {
            $canManage = $staffId !== 3;
            $this->db->exec('DELETE FROM clients');
            $own = $this->client($staffId);
            $other = $this->client($staffId === 1 ? 2 : 1);
            $any = $this->client(null);
            $this->login($staffId);
            $list = $this->request('GET', '/admin/api/get-clients');
            self::assertSame(200, $list['status'], $list['body']);
            self::assertSame(3, $list['data']['count']);
            $clients = array_column($list['data']['clients'], null, 'id');
            self::assertTrue($clients[$own]['can_complete']);
            self::assertTrue($clients[$own]['can_remove']);
            self::assertSame($canManage, $clients[$other]['can_complete']);
            self::assertSame($canManage, $clients[$other]['can_remove']);
            self::assertTrue($clients[$any]['can_complete']);
            self::assertSame($canManage, $clients[$any]['can_remove']);
            self::assertSame('First available staff', $clients[$any]['staff_name']);
            self::assertSame($canManage ? 200 : 403, $this->request('POST', '/admin/api/complete-client', ['client_id' => $other, 'staff_id' => $staffId])['status']);
            self::assertSame(200, $this->request('POST', '/admin/api/complete-client', ['client_id' => $own])['status']);
            self::assertSame(404, $this->request('POST', '/admin/api/complete-client', ['client_id' => $own])['status']);
            self::assertSame(200, $this->request('POST', '/admin/api/complete-client', ['client_id' => $any])['status']);
            self::assertSame(3, (int)$this->db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
            self::assertSame($canManage ? 3 : 2, (int)$this->db->query('SELECT COUNT(*) FROM clients WHERE is_active = 0')->fetchColumn());
            self::assertSame($canManage ? [] : [$other], array_map('intval', array_column($this->request('GET', '/admin/api/get-clients')['data']['clients'], 'id')));
            self::assertSame($canManage ? 0 : 1, (int)$this->request('GET', '/api/get-clients')['data']['total_clients']);
            $this->request('GET', '/admin/logout');
        }
    }

    public function testCompletionRequiresAuthenticationCsrfAndTheCorrectTenantSession(): void {
        $id = $this->client(null);
        self::assertSame(401, $this->request('POST', '/admin/api/complete-client', ['client_id' => $id])['status']);
        self::assertSame(401, $this->request('GET', '/admin/api/get-clients')['status']);
        $this->login(2);
        self::assertSame(405, $this->request('GET', '/admin/api/complete-client')['status']);
        self::assertSame(400, $this->request('POST', '/admin/api/complete-client', 'invalid')['status']);
        $expired = $this->request('POST', '/admin/api/complete-client', ['client_id' => $id, 'csrf_token' => 'wrong']);
        self::assertSame(403, $expired['status']);
        self::assertSame('Expired website session. Consider refreshing the page.', $expired['data']['error']);
        foreach ([0, -1, [], 'invalid'] as $invalid) {
            self::assertSame(422, $this->request('POST', '/admin/api/complete-client', ['client_id' => $invalid])['status']);
        }
        $cookies = file($this->cookie, FILE_IGNORE_NEW_LINES);
        $sessionId = null;
        foreach ($cookies as $line) {
            $fields = explode("\t", $line);
            if (count($fields) === 7 && $fields[5] === 'PHPSESSID') {
                $sessionId = $fields[6];
            }
        }
        self::assertNotNull($sessionId);
        $host = $this->tenants[1]['slug'] . '.' . BASE_DOMAIN;
        self::assertSame(401, $this->request('POST', '/admin/api/complete-client', ['client_id' => $id], false, $host, $sessionId)['status']);
        self::assertSame(401, $this->request('GET', '/admin/api/daily-hours?scope=staff', null, false, $host, $sessionId)['status']);
        self::assertSame(1, (int)$this->db->query('SELECT is_active FROM clients WHERE id = ' . $id)->fetchColumn());
    }

    public function testConcurrentStaffCompletionOnlyCompletesAnUnassignedClientOnce(): void {
        $id = $this->client(null);
        $this->login(1);
        $firstCookie = $this->cookie;
        $firstToken = $this->token;
        $this->cookie = tempnam(sys_get_temp_dir(), 'clientq-waitlist-cookie-');
        $multi = curl_multi_init();
        $handles = [];
        try {
            $this->login(2);
            foreach ([[$firstCookie, $firstToken], [$this->cookie, $this->token]] as [$cookie, $token]) {
                $curl = curl_init((getenv('CLIENTQ_INTEGRATION_BASE_URL') ?: 'http://127.0.0.1') . '/admin/api/complete-client');
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEFILE => $cookie,
                    CURLOPT_HTTPHEADER => ['Host: ' . $this->tenants[0]['slug'] . '.' . BASE_DOMAIN, 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode(['client_id' => $id, 'csrf_token' => $token], JSON_THROW_ON_ERROR),
                ]);
                $handles[] = $curl;
                curl_multi_add_handle($multi, $curl);
            }
            do {
                curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 1);
            } while ($running);
            $statuses = array_map(fn($curl): int => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $handles);
            sort($statuses);
            self::assertSame([200, 404], $statuses);
            self::assertSame(0, (int)$this->db->query('SELECT is_active FROM clients WHERE id = ' . $id)->fetchColumn());
        } finally {
            foreach ($handles as $curl) {
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
            }
            curl_multi_close($multi);
            unlink($firstCookie);
        }
    }

    public function testMigrationPreservesExistingAssignmentsAndAllowsUnassignedClients(): void {
        $id = $this->client(2);
        $this->db->exec('ALTER TABLE clients MODIFY COLUMN staff_id INT UNSIGNED NOT NULL');
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-any-staff-migration.sql');
        $this->db->exec($migration);
        $this->db->exec($migration);
        self::assertSame(2, (int)$this->db->query('SELECT staff_id FROM clients WHERE id = ' . $id)->fetchColumn());
        $any = $this->client(null);
        self::assertNull($this->db->query('SELECT staff_id FROM clients WHERE id = ' . $any)->fetchColumn());
    }

    public function testMigrationPreservesLegacySignedStaffForeignKeys(): void {
        $slug = 'waitlist-legacy-' . bin2hex(random_bytes(5));
        $database = 'clientq_tenant_' . substr(hash('sha256', $slug), 0, 32);
        $this->tenants[] = ['slug' => $slug, 'database' => $database];
        getDB()->exec("CREATE DATABASE `$database`");
        $db = createDatabaseConnection($database);
        $db->exec('CREATE TABLE staff (id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->exec('CREATE TABLE clients (id INT PRIMARY KEY, staff_id INT NOT NULL, FOREIGN KEY (staff_id) REFERENCES staff (id)) ENGINE=InnoDB');
        $db->exec('INSERT INTO staff (id) VALUES (1)');
        $db->exec('INSERT INTO clients (id, staff_id) VALUES (1, 1)');
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-any-staff-migration.sql');
        $db->exec($migration);
        $db->exec($migration);
        self::assertSame(1, (int)$db->query('SELECT staff_id FROM clients WHERE id = 1')->fetchColumn());
        $db->exec('INSERT INTO clients (id, staff_id) VALUES (2, NULL)');
        self::assertNull($db->query('SELECT staff_id FROM clients WHERE id = 2')->fetchColumn());
        $dailyMigration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-daily-waitlist-hours-migration.sql');
        $db->exec($dailyMigration);
        $db->exec($dailyMigration);
        $db->exec("INSERT INTO staff_daily_availability (staff_id, work_date) VALUES (1, '2026-11-19')");
        self::assertSame('08:00:00', $db->query('SELECT opening_time FROM staff_daily_availability')->fetchColumn());
        $this->expectException(ClientQDatabaseQueryException::class);
        $this->expectExceptionMessage('1452');
        $db->exec('INSERT INTO clients (id, staff_id) VALUES (3, 999)');
    }

    public function testDailyHoursAreOwnerControlledAndStaffCanOnlySetTheirOwnAvailability(): void {
        $date = waitlistNow()->modify('+7 days')->format('Y-m-d');
        $hours = ['scope' => 'staff', 'date' => $date, 'is_closed' => false, 'opening_time' => '10:00', 'closing_time' => '15:30'];
        self::assertSame(401, $this->request('GET', '/admin/api/daily-hours?scope=staff')['status']);
        $this->login(2);
        $defaults = $this->request('GET', '/admin/api/daily-hours?scope=staff&date=' . $date);
        self::assertSame(200, $defaults['status'], $defaults['body']);
        self::assertSame('08:00', $defaults['data']['schedule']['opening_time']);
        self::assertSame('16:30', $defaults['data']['schedule']['closing_time']);
        self::assertFalse($defaults['data']['schedule']['is_closed']);
        self::assertSame(403, $this->request('POST', '/admin/api/daily-hours', $hours + ['csrf_token' => 'invalid'])['status']);
        self::assertSame(403, $this->request('GET', '/admin/api/daily-hours?scope=waitlist')['status']);
        self::assertSame(403, $this->request('POST', '/admin/api/daily-hours', array_replace($hours, ['scope' => 'waitlist']))['status']);
        self::assertSame(405, $this->request('PUT', '/admin/api/daily-hours', $hours)['status']);
        $saved = $this->request('POST', '/admin/api/daily-hours', $hours + ['staff_id' => 1]);
        self::assertSame(200, $saved['status'], $saved['body']);
        $row = $this->db->query("SELECT staff_id, opening_time FROM staff_daily_availability WHERE work_date = '$date'")->fetch();
        self::assertSame(2, (int)$row['staff_id']);
        self::assertSame('10:00:00', $row['opening_time']);
        self::assertStringContainsString('data-daily-hours="staff"', $this->request('GET', '/admin/profile')['body']);
        foreach ([
            ['date' => '2026-02-30'], ['date' => []], ['opening_time' => '25:00'],
            ['closing_time' => '09:00'], ['is_closed' => 'false'],
            ['date' => waitlistNow()->modify('-1 day')->format('Y-m-d')],
        ] as $invalid) {
            self::assertSame(422, $this->request('POST', '/admin/api/daily-hours', array_replace($hours, $invalid))['status']);
        }
        $this->request('GET', '/admin/logout');
        $this->login(1);
        self::assertStringContainsString('data-daily-hours="waitlist"', $this->request('GET', '/admin/business')['body']);
        $businessHours = array_replace($hours, ['scope' => 'waitlist', 'opening_time' => '09:45', 'closing_time' => '17:15']);
        self::assertSame(200, $this->request('POST', '/admin/api/daily-hours', $businessHours)['status']);
        self::assertSame(
            '09:45',
            $this->request('GET', '/admin/api/daily-hours?scope=waitlist&date=' . $date)['data']['schedule']['opening_time']
        );
        $otherDate = waitlistNow()->modify('+8 days')->format('Y-m-d');
        self::assertSame('08:00', $this->request('GET', '/admin/api/daily-hours?scope=waitlist&date=' . $otherDate)['data']['schedule']['opening_time']);
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-daily-waitlist-hours-migration.sql');
        $this->db->exec($migration);
        $this->db->exec($migration);
        self::assertSame('09:45', waitlistHours($this->db, $date)['opening_time']);
        self::assertSame('10:00', waitlistHours($this->db, $date, 2)['opening_time']);
    }

    public function testClosedWaitlistAndOffDutyStaffCannotBeBypassedThroughDirectSignup(): void {
        $this->client(2);
        $this->login(1);
        $hours = ['scope' => 'waitlist', 'date' => waitlistNow()->format('Y-m-d'), 'is_closed' => true, 'opening_time' => '00:00', 'closing_time' => '23:59'];
        self::assertSame(200, $this->request('POST', '/admin/api/daily-hours', $hours)['status']);
        $public = $this->request('GET', '/api/get-clients');
        self::assertFalse($public['data']['waitlist']['can_join']);
        self::assertCount(1, $public['data']['clients']);
        $signup = ['name' => 'Scheduled Client', 'phone' => '2025550123', 'staff_id' => 2, 'no_in_party' => 1, 'sms_consent' => true];
        foreach ([2, 'any'] as $staffId) {
            self::assertSame(409, $this->request('POST', '/api/submit-signin', array_replace($signup, ['staff_id' => $staffId]))['status']);
        }
        self::assertSame(200, $this->request('POST', '/admin/api/daily-hours', array_replace($hours, ['is_closed' => false]))['status']);
        $this->request('GET', '/admin/logout');
        $this->login(2);
        self::assertSame(200, $this->request('POST', '/admin/api/daily-hours', array_replace($hours, ['scope' => 'staff']))['status']);
        $public = $this->request('GET', '/api/get-clients');
        self::assertNotContains(2, array_map('intval', array_column($public['data']['available_staff'], 'id')));
        self::assertContains(2, array_map('intval', array_column($public['data']['staff'], 'id')));
        self::assertSame(1, $this->request('GET', '/api/get-clients?staff=2')['data']['total_clients']);
        self::assertSame(409, $this->request('POST', '/api/submit-signin', $signup)['status']);
        self::assertSame(200, $this->request('POST', '/api/submit-signin', array_replace($signup, ['staff_id' => 'any']))['status']);
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        $this->db->exec('UPDATE staff_daily_availability SET is_closed = 1');
        self::assertSame(409, $this->request('POST', '/api/submit-signin', array_replace($signup, ['staff_id' => 'any']))['status']);
        $this->db->exec('DELETE FROM business_settings');
        $failedSignup = $this->request('POST', '/api/submit-signin', $signup);
        self::assertSame(500, $failedSignup['status'], $failedSignup['body']);
        self::assertSame('Failed to save. Please try again.', $failedSignup['data']['error']);
        $failedSave = $this->request('POST', '/admin/api/daily-hours', array_replace($hours, ['scope' => 'staff']));
        self::assertSame(500, $failedSave['status'], $failedSave['body']);
        self::assertSame('Daily hours could not be loaded or saved. Please try again.', $failedSave['data']['error']);
    }

    public function testDailyStateIncludesUpcomingStaffButHidesOffDutyAndFinishedStaff(): void {
        $now = waitlistNow()->setTime(9, 0);
        $this->db->exec("UPDATE staff_daily_availability SET opening_time = '08:00', closing_time = '08:30' WHERE staff_id = 1");
        $this->db->exec("UPDATE staff_daily_availability SET opening_time = '11:00', closing_time = '16:30' WHERE staff_id = 2");
        $this->db->exec('UPDATE staff_daily_availability SET is_closed = 1 WHERE staff_id = 3');
        $state = waitlistDailyState($this->db, $now);
        self::assertTrue($state['waitlist']['can_join']);
        self::assertSame([2], array_map('intval', array_column($state['available_staff'], 'id')));
        self::assertSame('Staff 2 (starts at 11:00 AM)', $state['available_staff'][0]['selection_label']);
        self::assertStringContainsString('service will not begin before then', $state['available_staff'][0]['availability_notice']);
        $started = waitlistDailyState($this->db, $now->setTime(11, 0));
        self::assertSame('', $started['available_staff'][0]['availability_notice']);
        $finished = waitlistDailyState($this->db, $now->setTime(16, 30));
        self::assertSame([], $finished['available_staff']);
        self::assertFalse($finished['waitlist']['can_join']);
        $tomorrow = waitlistDailyState($this->db, $now->modify('+1 day'));
        self::assertSame([1, 2, 3], array_map('intval', array_column($tomorrow['available_staff'], 'id')));
        self::assertSame('08:00', $tomorrow['waitlist']['opening_time']);
        self::assertSame('16:30', $tomorrow['waitlist']['closing_time']);
    }

    private function userInput(int $id, ?string $role = null): array {
        $row = $this->db->query('SELECT * FROM staff WHERE id = ' . $id)->fetch();
        $input = [
            'id' => $id, 'name' => $row['name'], 'goes_by' => $row['goes_by'], 'email' => $row['email'],
            'position' => $row['position'], 'additional_info' => $row['additional_info'] ?? '',
        ];
        if ($role !== null) {
            $input['role'] = $role;
        }
        return $input;
    }

    public function testOwnersAndManagersCanDeleteAnyWaitingClientButStaffCannot(): void {
        $this->db->exec("UPDATE staff SET role = 'manager' WHERE id = 2");
        foreach ([1, 2, 3] as $staffId) {
            $this->login($staffId);
            foreach ([$staffId, $staffId === 1 ? 2 : 1, null] as $assigned) {
                $id = $this->client($assigned);
                $allowed = $staffId !== 3 || $assigned === $staffId;
                self::assertSame(
                    $allowed ? 200 : 403,
                    $this->request('POST', '/admin/api/delete-client', ['client_id' => $id])['status']
                );
                self::assertSame($allowed ? 0 : 1, (int)$this->db->query('SELECT COUNT(*) FROM clients WHERE id = ' . $id)->fetchColumn());
                $this->db->exec('DELETE FROM clients WHERE id = ' . $id);
            }
            $id = $this->client($staffId);
            self::assertSame(403, $this->request('POST', '/admin/api/delete-client', ['client_id' => $id, 'csrf_token' => 'wrong'])['status']);
            self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM clients WHERE id = ' . $id)->fetchColumn());
            $this->request('GET', '/admin/logout');
        }
    }

    public function testManagersCanManageStaffButCannotEscalateOrUseOwnerSettings(): void {
        $this->db->exec("UPDATE staff SET role = 'manager' WHERE id = 2");
        $this->login(2);
        $profile = $this->request('GET', '/admin/profile');
        self::assertStringContainsString('id="profileAccessLevel" class="mt-1 text-sm text-gray-700">Manager', $profile['body']);
        self::assertStringNotContainsString('href="billing"', $profile['body']);
        self::assertStringNotContainsString('href="business"', $profile['body']);
        self::assertStringContainsString('href="staff"', $profile['body']);
        foreach (['/admin/business', '/admin/billing', '/admin/api/payment-methods'] as $path) {
            self::assertSame(403, $this->request('GET', $path)['status']);
        }
        self::assertSame(403, $this->request('POST', '/admin/api/save-theme', [])['status']);
        self::assertSame(403, $this->request('GET', '/admin/api/daily-hours?scope=waitlist')['status']);
        $users = $this->request('GET', '/admin/staff');
        self::assertSame(200, $users['status'], $users['body']);
        self::assertStringContainsString('id="accessLevel"', $users['body']);
        self::assertStringNotContainsString('<option value="owner"', $users['body']);
        self::assertStringNotContainsString('<option value="manager"', $users['body']);
        self::assertSame(200, $this->request('POST', '/admin/api/save-employee', $this->userInput(3, 'staff'))['status']);
        self::assertSame(403, $this->request('POST', '/admin/api/save-employee', $this->userInput(1))['status']);
        self::assertSame(403, $this->request('POST', '/admin/api/deactivate-employee', ['id' => 1])['status']);
        foreach (['owner', 'manager'] as $role) {
            self::assertSame(403, $this->request('POST', '/admin/api/save-employee', $this->userInput(3, $role))['status']);
            $new = array_replace($this->userInput(3, $role), ['id' => 0, 'email' => $role . '-invite@example.com']);
            self::assertSame(403, $this->request('POST', '/admin/api/save-employee', $new)['status']);
        }
        self::assertSame(403, $this->request('POST', '/admin/api/save-employee', $this->userInput(3) + ['csrf_token' => 'wrong'])['status']);
        self::assertSame(200, $this->request('POST', '/admin/api/deactivate-employee', ['id' => 3])['status']);
        self::assertSame(0, (int)$this->db->query('SELECT is_active FROM staff WHERE id = 3')->fetchColumn());
    }

    public function testOwnerRoleEditsRevokeOldSessionsAndCannotRemoveTheLastOwner(): void {
        $this->login(3);
        $staffCookie = $this->cookie;
        $this->cookie = tempnam(sys_get_temp_dir(), 'clientq-owner-access-cookie-');
        try {
            $this->login(1);
            self::assertSame(409, $this->request('POST', '/admin/api/save-employee', $this->userInput(1, 'manager'))['status']);
            self::assertSame(409, $this->request('POST', '/admin/api/deactivate-employee', ['id' => 1])['status']);
            self::assertSame(422, $this->request('POST', '/admin/api/save-employee', $this->userInput(3, 'superadmin'))['status']);
            self::assertSame(200, $this->request('POST', '/admin/api/save-employee', $this->userInput(3, 'manager'))['status']);
            self::assertSame(1, (int)$this->db->query('SELECT password_version FROM staff WHERE id = 3')->fetchColumn());
            $ownerCookie = $this->cookie;
            $this->cookie = $staffCookie;
            self::assertSame(401, $this->request('GET', '/admin/api/get-clients')['status']);
            $this->cookie = $ownerCookie;
            self::assertSame(200, $this->request('POST', '/admin/api/save-employee', $this->userInput(2, 'owner'))['status']);
            self::assertSame(200, $this->request('POST', '/admin/api/save-employee', $this->userInput(2, 'staff'))['status']);
            self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM staff WHERE role = 'owner' AND is_active = 1")->fetchColumn());
        } finally {
            unlink($staffCookie);
        }
    }

    public function testInvitationsRespectAccessLevelsQuotasAndMailRollback(): void {
        foreach (['manager', 'owner', 'staff'] as $role) {
            $data = [
                'name' => 'Role Fixture', 'first_name' => 'Role', 'last_name' => 'Fixture', 'goes_by' => '',
                'email' => $role . '-fixture@example.com', 'position' => '', 'additional_info' => '', 'role' => $role,
            ];
            $temporary = '';
            createInvitedEmployee($this->db, $data, 5, 'Fixture', 'https://example.com/admin/login',
                static function ($recipient, $subject, $body) use ($role, &$temporary): array {
                    self::assertStringContainsString('Access level: ' . STAFF_ACCESS_LEVELS[$role], $body);
                    self::assertSame(1, preg_match('/Temporary password: ([^\r\n]+)/', $body, $matches));
                    $temporary = $matches[1];
                    return ['success' => true];
                }, $this->db->query('SELECT * FROM staff WHERE id = 1')->fetch());
            $row = $this->db->query("SELECT * FROM staff WHERE email = '$role-fixture@example.com'")->fetch();
            self::assertSame($role, $row['role']);
            self::assertSame(1, (int)$row['must_change_password']);
            self::assertTrue(password_verify($temporary, $row['password_hash']));
        }
        $data['email'] = 'over-quota@example.com';
        $data['role'] = 'owner';
        try {
            createInvitedEmployee($this->db, $data, 5, 'Fixture', 'https://example.com/admin/login',
                static function (): array { self::fail('Quota must include additional owners and managers.'); });
            self::fail('Invitation should exceed the plan limit.');
        } catch (DomainException $e) {
            self::assertStringContainsString('5 active employees', $e->getMessage());
        }
        $data['email'] = 'failed-role-invite@example.com';
        $data['role'] = 'manager';
        try {
            createInvitedEmployee($this->db, $data, 50, 'Fixture', 'https://example.com/admin/login',
                static fn(): array => ['success' => false, 'error' => 'Mock delivery failure']);
            self::fail('Mail failure must roll back the role-bearing account.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Mock delivery failure', $e->getMessage());
        }
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM staff WHERE email IN ('over-quota@example.com', 'failed-role-invite@example.com')")->fetchColumn());
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-staff-access-levels-migration.sql');
        $this->db->exec($migration);
        $this->db->exec($migration);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM staff WHERE role = 'manager'")->fetchColumn());
    }
}
