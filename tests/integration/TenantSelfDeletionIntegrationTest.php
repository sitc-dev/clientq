<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/php/config.php';

final class TenantSelfDeletionIntegrationTest extends TestCase {
    private array $slugs = [];
    private array $databases = [];
    private array $businessIds = [];
    private PDO $db;
    private PDO $otherDb;
    private int $staffId;
    private ?string $previousGroupPhone = null;
    private bool $groupChanged = false;
    private string $messageLog;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1'
            || getenv('CLIENTQ_SMS_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped('Use the isolated database and local Twilio mock for SMS integration tests.');
        }
        self::assertSame('db', DB_HOST, 'Never run these fixtures against a remote database.');
        self::assertSame('127.0.0.1', parse_url(getenv('CLIENTQ_TWILIO_API_BASE') ?: '', PHP_URL_HOST));
        $this->messageLog = getenv('CLIENTQ_TWILIO_TEST_LOG') ?: '/tmp/clientq-twilio-test-messages.jsonl';
        self::assertNotFalse(file_put_contents($this->messageLog, ''));
        $platformDb = getDB();
        $phone = $platformDb->query("SELECT phone_number FROM phone_groups WHERE group_code = 'E'")->fetchColumn();
        $this->previousGroupPhone = is_string($phone) ? $phone : null;
        $platformDb->exec("UPDATE phone_groups SET phone_number = '+12025550199' WHERE group_code = 'E'");
        $this->groupChanged = true;

        for ($index = 0; $index < 2; $index++) {
            $slug = 'deletion-' . bin2hex(random_bytes(5));
            $database = 'clientq_tenant_' . substr(hash('sha256', $slug), 0, 32);
            $this->slugs[] = $slug;
            $this->databases[] = $database;
            $platformDb->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db = createDatabaseConnection($database);
            $schema = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-schema.sql');
            self::assertIsString($schema);
            $db->exec($schema);
            $staff = $db->prepare(
                "INSERT INTO staff (name, goes_by, username, password_hash, role)
                 VALUES ('Test Staff', 'Test Staff', 'test@example.com', :hash, 'owner')"
            );
            $staff->execute([':hash' => password_hash('isolated-test-password', PASSWORD_DEFAULT)]);
            $staffId = (int)$db->lastInsertId();
            $client = $db->prepare(
                "INSERT INTO clients (name, phone, staff_id, confirmation_code, sms_consent)
                 VALUES ('Deletion Test Client', '2025550101', :staff, :code, 1)"
            );
            $client->execute([':staff' => $staffId, ':code' => 'ABC123']);
            if ($index === 0) {
                $client->execute([':staff' => $staffId, ':code' => 'ABC124']);
                $this->db = $db;
                $this->staffId = $staffId;
            } else {
                $this->otherDb = $db;
            }
            $business = $platformDb->prepare(
                "INSERT INTO businesses (name, slug, owner_email, database_name)
                 VALUES ('Deletion Test Shop', :slug, :email, :database)"
            );
            $business->execute([':slug' => $slug, ':email' => $slug . '@example.com', ':database' => $database]);
            $businessId = (int)$platformDb->lastInsertId();
            $this->businessIds[] = $businessId;
            $membership = $platformDb->prepare(
                "INSERT INTO business_phone_groups (business_id, group_code) VALUES (:id, 'E')"
            );
            $membership->execute([':id' => $businessId]);
        }
    }

    protected function tearDown(): void {
        if (!$this->slugs && !$this->groupChanged) {
            return;
        }
        $platformDb = getDB();
        foreach ($this->slugs as $slug) {
            $delete = $platformDb->prepare('DELETE FROM businesses WHERE slug = :slug');
            $delete->execute([':slug' => $slug]);
        }
        foreach ($this->databases as $database) {
            if (preg_match('/^clientq_tenant_[a-f0-9]{32}$/', $database)) {
                $platformDb->exec("DROP DATABASE IF EXISTS `$database`");
            }
        }
        if ($this->groupChanged) {
            $restore = $platformDb->prepare("UPDATE phone_groups SET phone_number = :phone WHERE group_code = 'E'");
            $restore->execute([':phone' => $this->previousGroupPhone]);
        }
    }

    public function testSignupTextContainsUnlinkedTenantDeletionPage(): void {
        $signup = $this->request('POST', '/api/submit-signin', [
            'name' => 'SMS Test Client',
            'phone' => '2025550101',
            'staff_id' => $this->staffId,
            'no_in_party' => 1,
            'sms_consent' => true,
        ]);
        self::assertSame(200, $signup['status'], $signup['body']);
        $signupData = $this->json($signup);
        self::assertTrue($signupData['sms_sent']);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $signupData['confirmation_code']);
        $id = (int)$this->db->query("SELECT id FROM clients WHERE name = 'SMS Test Client'")->fetchColumn();
        $message = $this->lastMessage();
        self::assertSame('+12025550199', $message['From']);
        self::assertSame('+12025550101', $message['To']);
        self::assertStringContainsString('Confirmation code: ' . $signupData['confirmation_code'], $message['Body']);
        self::assertCount(1, file($this->messageLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        self::assertStringContainsString(
            'http://' . $this->slugs[0] . '.' . BASE_DOMAIN . '/remove?signup=' . $id,
            $message['Body']
        );
        foreach (['/', '/galley', '/staff-profiles'] as $path) {
            $page = $this->request('GET', $path);
            self::assertSame(200, $page['status']);
            self::assertStringNotContainsString('/remove', $page['body']);
        }
        $page = $this->request('GET', '/remove?signup=' . $id);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('This deletion cannot be undone.', $page['body']);
        self::assertStringContainsString('id="deletionConfirm" class="hidden', $page['body']);
        self::assertStringContainsString('id="verifyCodeForm" class="hidden', $page['body']);
        self::assertStringContainsString('id="deleteReady" class="hidden', $page['body']);
        self::assertStringNotContainsString('id="signupId"', $page['body']);
        self::assertStringNotContainsString('Sign-up reference', $page['body']);
        self::assertCount(1, file($this->messageLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function testPhoneProofAndExplicitConfirmationAreRequiredForPermanentDeletion(): void {
        $this->db->exec('DROP TABLE waitlist_deletion_challenges');
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-self-deletion-migration.sql');
        self::assertIsString($migration);
        $this->db->exec($migration);
        $this->db->exec($migration);
        self::assertSame(400, $this->requestRemoval('remove')['status']);
        self::assertSame(403, $this->requestRemoval('delete')['status']);
        self::assertSame(200, $this->requestRemoval('request_code')['status']);
        self::assertSame(429, $this->requestRemoval('request_code')['status']);
        $code = $this->lastCode();
        $hash = $this->db->query('SELECT code_hash FROM waitlist_deletion_challenges WHERE client_id = 1')->fetchColumn();
        self::assertNotSame($code, $hash);
        self::assertTrue(password_verify($code, $hash));
        self::assertSame(400, $this->requestRemoval('verify_code', ['verification_code' => $this->wrongCode($code)])['status']);
        $verified = $this->requestRemoval('verify_code', ['verification_code' => $code]);
        self::assertSame(200, $verified['status'], $verified['body']);
        $data = $this->json($verified);
        self::assertSame('Deletion Test Client', $data['client']['name']);
        self::assertSame('ABC123', $data['client']['confirmation_code']);
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(400, $this->requestRemoval('verify_code', ['verification_code' => $code])['status']);
        $token = $data['verification_token'];
        self::assertSame(400, $this->requestRemoval('delete', ['verification_token' => $token], 1)['status']);
        self::assertSame(403, $this->requestRemoval('delete', ['verification_token' => str_repeat('0', 64)])['status']);
        $deleted = $this->requestRemoval('delete', ['verification_token' => $token]);
        self::assertSame(200, $deleted['status'], $deleted['body']);
        self::assertSame([2], array_map('intval', $this->db->query('SELECT id FROM clients')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM clients WHERE confirmation_code = 'ABC123'")->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM waitlist_deletion_challenges')->fetchColumn());
        self::assertSame(1, (int)$this->otherDb->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(404, $this->requestRemoval('delete', ['verification_token' => $token])['status']);
    }

    public function testExpiredCodesAttemptLimitsAndResendLimitsAreEnforced(): void {
        self::assertSame(200, $this->requestRemoval('request_code')['status']);
        $code = $this->lastCode();
        $this->db->exec('UPDATE waitlist_deletion_challenges SET expires_at = CURRENT_TIMESTAMP - INTERVAL 1 SECOND');
        self::assertSame(400, $this->requestRemoval('verify_code', ['verification_code' => $code])['status']);
        $this->db->exec('UPDATE waitlist_deletion_challenges SET requested_at = CURRENT_TIMESTAMP - INTERVAL 2 MINUTE');
        self::assertSame(200, $this->requestRemoval('request_code')['status']);
        $code = $this->lastCode();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            self::assertSame(400, $this->requestRemoval('verify_code', ['verification_code' => $this->wrongCode($code)])['status']);
        }
        self::assertSame(429, $this->requestRemoval('verify_code', ['verification_code' => $code])['status']);
        $this->db->exec('UPDATE waitlist_deletion_challenges SET requested_at = CURRENT_TIMESTAMP - INTERVAL 2 MINUTE, send_count = 3');
        self::assertSame(429, $this->requestRemoval('request_code')['status']);
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
    }

    public function testProviderFailureIsExplicitAndDoesNotAuthorizeDeletion(): void {
        $this->db->exec("UPDATE clients SET phone = '2025550100' WHERE id = 1");
        $failure = $this->request('POST', '/api/remove-client', [
            'action' => 'request_code', 'signup_id' => 1, 'phone' => '2025550100',
        ]);
        self::assertSame(503, $failure['status']);
        self::assertArrayHasKey('error', $this->json($failure));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM waitlist_deletion_challenges')->fetchColumn());
        $signup = $this->request('POST', '/api/submit-signin', [
            'name' => 'SMS Failure Client', 'phone' => '2025550100', 'staff_id' => $this->staffId,
            'no_in_party' => 1, 'sms_consent' => true,
        ]);
        self::assertSame(200, $signup['status']);
        $data = $this->json($signup);
        self::assertTrue($data['success']);
        self::assertFalse($data['sms_sent']);
        self::assertNotEmpty($data['sms_warning']);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM clients WHERE name = 'SMS Failure Client'")->fetchColumn());
    }

    private function requestRemoval(string $action, array $extra = [], int $tenantIndex = 0): array {
        return $this->request('POST', '/api/remove-client', array_merge([
            'action' => $action, 'signup_id' => 1, 'phone' => '2025550101',
        ], $extra), $tenantIndex);
    }

    private function request(string $method, string $path, ?array $payload = null, int $tenantIndex = 0): array {
        $curl = curl_init((getenv('CLIENTQ_INTEGRATION_BASE_URL') ?: 'http://127.0.0.1') . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $this->slugs[$tenantIndex] . '.' . BASE_DOMAIN,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($curl);
        self::assertIsString($body, curl_error($curl));
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => $body];
    }

    private function json(array $response): array {
        $data = json_decode($response['body'], true);
        self::assertIsArray($data, $response['body']);
        return $data;
    }

    private function lastMessage(): array {
        $lines = file($this->messageLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $message = json_decode($lines[count($lines) - 1], true);
        self::assertIsArray($message);
        return $message;
    }

    private function lastCode(): string {
        self::assertSame(1, preg_match('/deletion code is ([0-9]{6})/', $this->lastMessage()['Body'], $match));
        return $match[1];
    }

    private function wrongCode(string $code): string {
        return ($code[0] === '0' ? '1' : '0') . substr($code, 1);
    }
}
