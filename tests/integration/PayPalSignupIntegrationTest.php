<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once __DIR__ . '/PayPalSignupTestServer.php';

final class PayPalSignupIntegrationTest extends TestCase {
    private PayPalSignupTestServer $server;
    private string $cookie;
    private string $slug;
    private string $token;
    private ?string $signupId = null;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped('Run inside the Docker stack with CLIENTQ_RUN_INTEGRATION_TESTS=1.');
        }
        $this->server = new PayPalSignupTestServer();
        $this->cookie = tempnam(sys_get_temp_dir(), 'clientq-paypal-cookie-');
        $this->slug = 'paypal-test-' . bin2hex(random_bytes(6));
        $page = $this->request('GET', '/signup');
        self::assertSame(200, $page['status']);
        self::assertSame(1, preg_match('~formData\.append\(\'csrf_token\', "([a-f0-9]+)"\)~', $page['body'], $match));
        $this->token = $match[1];
        $this->prepareRegistration();
    }

    private function prepareRegistration(string $plan = 'starter', bool $appointments = false): void {
        $response = $this->request('POST', '/api/prepare-business-registration', [
            'plan' => $plan, 'appointments_enabled' => $appointments, 'business_name' => $this->slug, 'slug' => $this->slug,
            'first_name' => 'PayPal', 'last_name' => 'Tester', 'email' => $this->slug . '@example.com',
            'phone' => '2025550123', 'password' => 'fixture-only-password',
        ]);
        self::assertSame(200, $response['status'], $response['body']);
    }

    protected function tearDown(): void {
        if (!isset($this->slug)) {
            return;
        }
        $stmt = getDB()->prepare('DELETE FROM paypal_signup_subscriptions WHERE id = ?');
        $stmt->execute([$this->signupId]);
        $stmt = getDB()->prepare('DELETE FROM businesses WHERE slug = ? AND owner_email = ?');
        $stmt->execute([$this->slug, $this->slug . '@example.com']);
        $stmt = getDB()->prepare('DELETE FROM platform_users WHERE email = ?');
        $stmt->execute([$this->slug . '@example.com']);
        $database = 'clientq_tenant_' . substr(hash('sha256', $this->slug), 0, 32);
        getDB()->exec("DROP DATABASE IF EXISTS `$database`");
        $stmt = getDB()->prepare('DELETE FROM signup_attempts WHERE ip_address = ?');
        $stmt->execute([$this->server->signupIp]);
        $this->server->stop();
        unlink($this->cookie);
    }

    private function request(string $method, string $path, ?array $input = null, ?string $cookie = null, ?string $host = null): array {
        $handle = curl_init($this->server->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['Host: ' . ($host ?? BASE_DOMAIN), 'Content-Type: application/json'],
            CURLOPT_COOKIEFILE => $cookie ?? $this->cookie, CURLOPT_COOKIEJAR => $cookie ?? $this->cookie,
        ]);
        if ($input !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode(
                array_replace(['csrf_token' => $this->token], $input), JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($handle);
        self::assertNotFalse($body, curl_error($handle));
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => $body, 'data' => json_decode($body, true)];
    }

    private function startCheckout(): string {
        $response = $this->request('POST', '/api/create-paypal-subscription', []);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringStartsWith('https://www.sandbox.paypal.com/', $response['data']['approval_url']);
        $state = $this->server->state();
        self::assertCount(1, $state['subscriptions']);
        $subscription = reset($state['subscriptions']);
        self::assertSame($subscription['id'], $response['data']['subscription_id']);
        $this->signupId = $subscription['custom_id'];
        return $subscription['id'];
    }

    private function assertNoTenant(): void {
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM businesses WHERE slug = ?');
        $stmt->execute([$this->slug]);
        self::assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testRequiresCompletedPaymentAndReusesSubscriptionOnRetry(): void {
        self::assertSame(402, $this->request('POST', '/api/register-business', [])['status']);
        $id = $this->startCheckout();
        $this->startCheckout();
        $this->assertNoTenant();
        self::assertSame(409, $this->request('POST', '/api/register-business', [])['status']);
        $this->server->approve($id, 'PENDING');
        self::assertSame(409, $this->request('POST', '/api/register-business', [])['status']);
        $this->server->approve($id, 'COMPLETED', '1.00');
        self::assertSame(409, $this->request('POST', '/api/register-business', [])['status']);
        $this->assertNoTenant();
        $this->server->approve($id);
        $created = $this->request('POST', '/api/register-business', []);
        self::assertSame(201, $created['status'], $created['body']);
        $cursor = getDB()->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1')->fetchColumn();
        self::assertSame(200, $this->request('POST', '/api/register-business', [])['status']);
        self::assertSame($cursor, getDB()->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1')->fetchColumn());
        $stmt = getDB()->prepare('SELECT business_id, first_payment_id, pending_registration FROM paypal_signup_subscriptions WHERE id = ?');
        $stmt->execute([$this->signupId]);
        $record = $stmt->fetch();
        self::assertGreaterThan(0, (int)$record['business_id']);
        self::assertNotEmpty($record['first_payment_id']);
        self::assertNull($record['pending_registration']);
        $assignment = getDB()->prepare(
            'SELECT g.phone_number FROM business_phone_groups m JOIN phone_groups g ON g.group_code = m.group_code WHERE m.business_id = ?'
        );
        $assignment->execute([$record['business_id']]);
        $phoneNumber = $assignment->fetchColumn();
        self::assertIsString($phoneNumber);
        self::assertMatchesRegularExpression('/^\+[1-9][0-9]{1,14}$/', $phoneNumber);
        self::assertSame(1, (int)$assignment->rowCount());
        $requests = array_filter($this->server->state()['requests'], fn(array $r): bool => $r['method'] === 'POST' && $r['path'] === '/v1/billing/subscriptions');
        self::assertCount(1, $requests, 'Retries must not create another subscription.');
        self::assertSame($this->signupId, reset($requests)['request_id']);
    }

    public function testRejectsForgedReturnsWrongSignupAndInvalidTokens(): void {
        self::assertSame(403, $this->request('POST', '/api/create-paypal-subscription', ['csrf_token' => 'wrong'])['status']);
        $id = $this->startCheckout();
        $return = $this->request('GET', '/signup-confirmation?payment=approved&subscription_id=I-FAKE');
        self::assertSame(200, $return['status']);
        self::assertSame(409, $this->request('POST', '/api/register-business', ['subscription_id' => 'I-FAKE'])['status']);
        $this->server->approve($id);
        $state = $this->server->state();
        $state['subscriptions'][$id]['custom_id'] = 'another-signup';
        $this->server->writeState($state);
        self::assertSame(503, $this->request('POST', '/api/register-business', [])['status']);
        $this->assertNoTenant();
    }

    public function testRejectsPlanMismatchBeforeCreatingSubscription(): void {
        $state = $this->server->state();
        $state['wrong_plan_price'] = '1.00';
        $this->server->writeState($state);
        self::assertSame(503, $this->request('POST', '/api/create-paypal-subscription', [])['status']);
        self::assertCount(0, $this->server->state()['subscriptions']);
        $this->assertNoTenant();
    }

    public function testPaidSignupCanRetryAfterProvisioningConflictAndReviewExpiry(): void {
        $id = $this->startCheckout();
        $this->server->approve($id);
        $stmt = getDB()->prepare(
            "UPDATE paypal_signup_subscriptions SET pending_registration =
                JSON_SET(pending_registration, '$.created_at', 1) WHERE id = ?"
        );
        $stmt->execute([$this->signupId]);
        self::assertSame(200, $this->request('GET', '/signup-confirmation')['status']);
        $stmt = getDB()->prepare(
            "INSERT INTO platform_users (name, email, password_hash, role)
             VALUES (?, ?, ?, 'business_owner')"
        );
        $stmt->execute(['PayPal conflict fixture', $this->slug . '@example.com', password_hash('different-test-password', PASSWORD_DEFAULT)]);
        $conflict = $this->request('POST', '/api/register-business', []);
        self::assertSame(409, $conflict['status'], $conflict['body']);
        $this->assertNoTenant();
        $stmt = getDB()->prepare('SELECT first_payment_id, pending_registration FROM paypal_signup_subscriptions WHERE id = ?');
        $stmt->execute([$this->signupId]);
        $record = $stmt->fetch();
        self::assertNotEmpty($record['first_payment_id']);
        self::assertNotNull($record['pending_registration']);
        $stmt = getDB()->prepare('DELETE FROM platform_users WHERE email = ?');
        $stmt->execute([$this->slug . '@example.com']);
        $created = $this->request('POST', '/api/register-business', []);
        self::assertSame(201, $created['status'], $created['body']);
        self::assertCount(1, $this->server->state()['subscriptions']);
    }

    public function testPaidSignupWaitsForAConfiguredPhoneAndRetriesWithoutAnotherPayment(): void {
        $id = $this->startCheckout();
        $this->server->approve($id);
        $db = getDB();
        $numbers = $db->query('SELECT group_code, phone_number FROM phone_groups ORDER BY group_code')->fetchAll();
        $cursor = $db->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1')->fetchColumn();
        try {
            $db->exec('UPDATE phone_groups SET phone_number = NULL');
            $failed = $this->request('POST', '/api/register-business', []);
            self::assertSame(503, $failed['status'], $failed['body']);
            self::assertStringContainsString('No SMS phone number is available', $failed['data']['error']);
            $this->assertNoTenant();
            $database = 'clientq_tenant_' . substr(hash('sha256', $this->slug), 0, 32);
            $schema = $db->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $schema->execute([$database]);
            self::assertSame(0, (int)$schema->fetchColumn());
            self::assertSame($cursor, $db->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1')->fetchColumn());
            $payment = $db->prepare('SELECT first_payment_id, pending_registration, provisioned_at FROM paypal_signup_subscriptions WHERE id = ?');
            $payment->execute([$this->signupId]);
            $record = $payment->fetch();
            self::assertNotEmpty($record['first_payment_id']);
            self::assertNotNull($record['pending_registration']);
            self::assertNull($record['provisioned_at']);
        } finally {
            $restore = $db->prepare('UPDATE phone_groups SET phone_number = ? WHERE group_code = ?');
            foreach ($numbers as $number) {
                $restore->execute([$number['phone_number'], $number['group_code']]);
            }
        }
        $created = $this->request('POST', '/api/register-business', []);
        self::assertSame(201, $created['status'], $created['body']);
        self::assertSame(200, $this->request('POST', '/api/register-business', [])['status']);
        self::assertCount(1, $this->server->state()['subscriptions']);
        $assignment = $db->prepare('SELECT COUNT(*) FROM business_phone_groups m JOIN businesses b ON b.id = m.business_id WHERE b.slug = ?');
        $assignment->execute([$this->slug]);
        self::assertSame(1, (int)$assignment->fetchColumn());
    }

    public static function appointmentSelections(): array {
        return [
            'Essentials waitlist only' => ['starter', false, false, '39.00', 'A'],
            'Essentials with appointments' => ['starter', true, true, '44.00', 'D'],
            'Select includes appointments' => ['growth', false, true, '64.00', 'B'],
            'Premier includes appointments' => ['pro', false, true, '99.00', 'C'],
        ];
    }

    #[DataProvider('appointmentSelections')]
    public function testAppointmentSelectionControlsPriceProvisioningAndPublicAccess(
        string $plan, bool $requested, bool $enabled, string $amount, string $planCharacter
    ): void {
        $this->prepareRegistration($plan, $requested);
        $review = $this->request('GET', '/signup-confirmation');
        self::assertSame(200, $review['status']);
        self::assertStringContainsString('$' . (int)$amount . '/month', $review['body']);
        $id = $this->startCheckout();
        $state = $this->server->state();
        self::assertSame('P-' . str_repeat($planCharacter, 24), $state['subscriptions'][$id]['plan_id']);
        if ($requested) {
            $this->server->approve($id, 'COMPLETED', '39.00');
            self::assertSame(409, $this->request('POST', '/api/register-business', ['appointments_enabled' => true])['status']);
            $this->assertNoTenant();
        }
        $this->server->approve($id, 'COMPLETED', $amount);
        $created = $this->request('POST', '/api/register-business', ['appointments_enabled' => !$requested]);
        self::assertSame(201, $created['status'], $created['body']);
        $stmt = getDB()->prepare('SELECT plan, appointments_enabled FROM businesses WHERE slug = ?');
        $stmt->execute([$this->slug]);
        self::assertSame(['plan' => $plan, 'appointments_enabled' => (int)$enabled], $stmt->fetch());
        $host = $this->slug . '.' . BASE_DOMAIN;
        $public = $this->request('GET', '/', null, null, $host);
        self::assertSame(200, $public['status'], $public['body']);
        self::assertSame($enabled, str_contains($public['body'], 'id="reservationForm"'));
        self::assertStringContainsString('id="signInForm"', $public['body']);
        $date = (new DateTimeImmutable('tomorrow', new DateTimeZone('America/New_York')))->format('Y-m-d');
        $slots = $this->request('GET', '/api/reservation-slots?staff_id=1&date=' . $date, null, null, $host);
        self::assertSame($enabled ? 200 : 403, $slots['status'], $slots['body']);
    }
}
