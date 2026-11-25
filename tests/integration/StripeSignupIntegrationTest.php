<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once __DIR__ . '/PayPalSignupTestServer.php';

final class StripeSignupIntegrationTest extends TestCase {
    private PayPalSignupTestServer $server;
    private string $cookie;
    private string $slug;
    private string $token;
    private ?string $signupId = null;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1' || DB_HOST !== 'db') {
            self::markTestSkipped('Run against local Docker databases only.');
        }
        $this->server = new PayPalSignupTestServer(true);
        $this->cookie = tempnam(sys_get_temp_dir(), 'clientq-stripe-cookie-');
        $this->slug = 'stripe-test-' . bin2hex(random_bytes(6));
        $page = $this->request('GET', '/signup');
        self::assertSame(200, $page['status'], $page['body']);
        self::assertSame(1, preg_match('~formData\.append\(\'csrf_token\', "([a-f0-9]+)"\)~', $page['body'], $match));
        $this->token = $match[1];
        $this->prepare('starter', false);
    }

    protected function tearDown(): void {
        if (!isset($this->slug)) {
            return;
        }
        foreach (['stripe_signup_subscriptions', 'paypal_signup_subscriptions'] as $table) {
            $stmt = getDB()->prepare("DELETE FROM $table WHERE JSON_UNQUOTE(JSON_EXTRACT(pending_registration, '$.slug')) = ? OR id = ?");
            $stmt->execute([$this->slug, $this->signupId]);
        }
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

    private function request(string $method, string $path, ?array $input = null, ?string $host = null, bool $form = false, bool $signed = false): array {
        $headers = ['Host: ' . ($host ?? BASE_DOMAIN), 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json')];
        $payload = null;
        if ($input !== null) {
            $input = array_replace(['csrf_token' => $this->token], $input);
            $payload = $form ? http_build_query($input) : json_encode($input, JSON_THROW_ON_ERROR);
        }
        if ($signed) {
            $timestamp = time();
            $headers[] = 'Stripe-Signature: t=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_fixture');
        }
        $handle = curl_init($this->server->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_COOKIEFILE => $this->cookie, CURLOPT_COOKIEJAR => $this->cookie,
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }
        $body = curl_exec($handle);
        self::assertNotFalse($body, curl_error($handle));
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => $body, 'data' => json_decode($body, true)];
    }

    private function prepare(string $plan, bool $appointments): void {
        $response = $this->request('POST', '/api/prepare-business-registration', [
            'business_name' => $this->slug, 'slug' => $this->slug, 'plan' => $plan,
            'appointments_enabled' => $appointments, 'first_name' => 'Stripe', 'last_name' => 'Tester',
            'email' => $this->slug . '@example.com', 'phone' => '2025550123', 'password' => 'fixture-only-password',
        ]);
        self::assertSame(200, $response['status'], $response['body']);
    }

    private function start(): string {
        $response = $this->request('POST', '/api/create-stripe-subscription', []);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringStartsWith('https://checkout.stripe.com/', $response['data']['checkout_url']);
        $state = $this->server->state();
        self::assertCount(1, $state['stripe_sessions']);
        $session = reset($state['stripe_sessions']);
        $this->signupId = $session['client_reference_id'];
        return $session['id'];
    }

    private function assertNoTenant(): void {
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM businesses WHERE slug = ?');
        $stmt->execute([$this->slug]);
        self::assertSame(0, (int)$stmt->fetchColumn());
    }

    public static function selections(): array {
        return [
            'Essentials' => ['starter', false, 3900],
            'Essentials with appointments' => ['starter', true, 4400],
            'Select' => ['growth', false, 6400],
            'Premier' => ['pro', false, 9900],
        ];
    }

    #[DataProvider('selections')]
    public function testCardPaymentCreatesWaitlistAndOwnerPortalAtTheSelectedMonthlyPrice(string $plan, bool $appointments, int $amount): void {
        $this->prepare($plan, $appointments);
        self::assertSame(402, $this->request('POST', '/api/register-business', [])['status']);
        $id = $this->start();
        $this->start();
        self::assertSame(409, $this->request('POST', '/api/register-business', ['session_id' => 'cs_test_forged'])['status']);
        self::assertSame(409, $this->request('POST', '/api/create-paypal-subscription', [])['status']);
        $this->assertNoTenant();
        $posts = array_values(array_filter($this->server->state()['stripe_requests'], fn(array $r): bool => $r['method'] === 'POST'));
        self::assertCount(1, $posts);
        self::assertSame('subscription', $posts[0]['parameters']['mode']);
        self::assertSame(['card'], $posts[0]['parameters']['payment_method_types']);
        self::assertSame((string)$amount, $posts[0]['parameters']['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('month', $posts[0]['parameters']['line_items'][0]['price_data']['recurring']['interval']);
        self::assertSame('clientq-signup-' . $this->signupId, $posts[0]['request_id']);
        $this->server->approveCard($id);
        $created = $this->request('POST', '/api/register-business', ['plan' => 'pro', 'appointments_enabled' => true]);
        self::assertSame(201, $created['status'], $created['body']);
        $cursor = getDB()->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1')->fetchColumn();
        self::assertSame(200, $this->request('POST', '/api/register-business', [])['status']);
        self::assertSame($cursor, getDB()->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1')->fetchColumn());
        $stmt = getDB()->prepare('SELECT id, plan, appointments_enabled, database_name FROM businesses WHERE slug = ?');
        $stmt->execute([$this->slug]);
        $business = $stmt->fetch();
        self::assertSame($plan, $business['plan']);
        self::assertSame((int)($plan !== 'starter' || $appointments), (int)$business['appointments_enabled']);
        $assignment = getDB()->prepare(
            'SELECT g.phone_number FROM business_phone_groups m JOIN phone_groups g ON g.group_code = m.group_code WHERE m.business_id = ?'
        );
        $assignment->execute([$business['id']]);
        $phoneNumber = $assignment->fetchColumn();
        self::assertIsString($phoneNumber);
        self::assertMatchesRegularExpression('/^\+[1-9][0-9]{1,14}$/', $phoneNumber);
        self::assertSame(1, (int)$assignment->rowCount());
        $host = $this->slug . '.' . BASE_DOMAIN;
        $public = $this->request('GET', '/', null, $host);
        self::assertSame(200, $public['status'], $public['body']);
        self::assertStringContainsString('id="signInForm"', $public['body']);
        self::assertSame($plan !== 'starter' || $appointments, str_contains($public['body'], 'id="reservationForm"'));
        self::assertSame(200, $this->request('GET', '/admin/login', null, $host)['status']);
        self::assertSame(401, $this->request('POST', '/admin/api/create-billing-portal', [], $host)['status']);
        self::assertSame(302, $this->request('POST', '/admin/login', [
            'email' => $this->slug . '@example.com', 'password' => 'fixture-only-password',
        ], $host, true)['status']);
        $billing = $this->request('GET', '/admin/billing', null, $host);
        self::assertSame(200, $billing['status'], $billing['body']);
        self::assertStringContainsString('Card monthly subscription', $billing['body']);
        self::assertSame(1, preg_match('/const csrfToken = "([a-f0-9]+)";/', $billing['body'], $match));
        $this->token = $match[1];
        $portal = $this->request('POST', '/admin/api/create-billing-portal', [], $host);
        self::assertSame(200, $portal['status'], $portal['body']);
        self::assertStringStartsWith('https://billing.stripe.com/', $portal['data']['portal_url']);
        $stmt = getDB()->prepare('SELECT first_payment_id, subscription_id, customer_id, pending_registration FROM stripe_signup_subscriptions WHERE id = ?');
        $stmt->execute([$this->signupId]);
        $record = $stmt->fetch();
        self::assertStringStartsWith('pi_', $record['first_payment_id']);
        self::assertStringStartsWith('sub_', $record['subscription_id']);
        self::assertStringStartsWith('cus_', $record['customer_id']);
        self::assertNull($record['pending_registration']);
    }

    public function testPaidCardSignupRetainsDetailsAndCanRetryAfterProvisioningConflict(): void {
        $id = $this->start();
        $this->server->approveCard($id);
        $stmt = getDB()->prepare("UPDATE stripe_signup_subscriptions SET pending_registration = JSON_SET(pending_registration, '$.created_at', 1) WHERE id = ?");
        $stmt->execute([$this->signupId]);
        $stmt = getDB()->prepare("INSERT INTO platform_users (name, email, password_hash, role) VALUES (?, ?, ?, 'business_owner')");
        $stmt->execute(['Fixture conflict', $this->slug . '@example.com', password_hash('different-fixture-password', PASSWORD_DEFAULT)]);
        self::assertSame(200, $this->request('GET', '/signup-confirmation?payment=approved')['status']);
        self::assertSame(409, $this->request('POST', '/api/register-business', [])['status']);
        $this->assertNoTenant();
        $stmt = getDB()->prepare('SELECT first_payment_id, pending_registration FROM stripe_signup_subscriptions WHERE id = ?');
        $stmt->execute([$this->signupId]);
        $record = $stmt->fetch();
        self::assertNotEmpty($record['first_payment_id']);
        self::assertNotNull($record['pending_registration']);
        $stmt = getDB()->prepare('DELETE FROM platform_users WHERE email = ?');
        $stmt->execute([$this->slug . '@example.com']);
        self::assertSame(201, $this->request('POST', '/api/register-business', [])['status']);
        self::assertCount(1, $this->server->state()['stripe_sessions']);
    }

    public function testCheckoutReturnsAndWebhooksCannotSpoofPayment(): void {
        self::assertSame(403, $this->request('POST', '/api/create-stripe-subscription', ['csrf_token' => 'wrong'])['status']);
        $id = $this->start();
        self::assertSame(200, $this->request('GET', '/signup-confirmation?payment=approved&session_id=cs_test_fake')['status']);
        $event = ['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $id, 'mode' => 'subscription', 'payment_status' => 'paid',
        ]]];
        self::assertSame(400, $this->request('POST', '/api/stripe-webhook', $event)['status']);
        self::assertSame(503, $this->request('POST', '/api/stripe-webhook', $event, signed: true)['status']);
        $this->assertNoTenant();
        $this->server->approveCard($id);
        self::assertSame(200, $this->request('POST', '/api/stripe-webhook', $event, signed: true)['status']);
        self::assertSame(200, $this->request('POST', '/api/stripe-webhook', $event, signed: true)['status']);
        $this->assertNoTenant();
        $state = $this->server->state();
        $state['stripe_sessions'][$id]['subscription']['metadata']['signup_id'] = 'another-signup';
        $this->server->writeState($state);
        self::assertSame(503, $this->request('POST', '/api/register-business', [])['status']);
        $this->assertNoTenant();
    }

    public function testExistingPayPalCheckoutCannotBePaidAgainByCard(): void {
        $response = $this->request('POST', '/api/create-paypal-subscription', []);
        self::assertSame(200, $response['status'], $response['body']);
        $state = $this->server->state();
        $this->signupId = reset($state['subscriptions'])['custom_id'];
        self::assertSame(409, $this->request('POST', '/api/create-stripe-subscription', [])['status']);
        self::assertCount(0, $this->server->state()['stripe_sessions']);
        $this->assertNoTenant();
    }
}
