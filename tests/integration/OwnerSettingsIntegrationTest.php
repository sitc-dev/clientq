<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once __DIR__ . '/PayPalSignupTestServer.php';

final class OwnerSettingsIntegrationTest extends TestCase {
    private PayPalSignupTestServer $server;
    private string $slug;
    private string $database;
    private string $signupId;
    private string $cookie;
    private string $token;
    private PDO $tenantDb;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1' || DB_HOST !== 'db' || DB_NAME !== 'clientq') {
            self::markTestSkipped('Run against local Docker databases only.');
        }
        $this->slug = 'owner-settings-test-' . bin2hex(random_bytes(5));
        $this->database = 'clientq_tenant_' . substr(hash('sha256', $this->slug), 0, 32);
        $this->signupId = bin2hex(random_bytes(16));
        $this->cookie = tempnam(sys_get_temp_dir(), 'clientq-owner-settings-cookie-');
        getDB()->exec("CREATE DATABASE `{$this->database}`");
        $this->tenantDb = createDatabaseConnection($this->database);
        $this->tenantDb->exec(file_get_contents(dirname(__DIR__, 2) . '/database/tenant-schema.sql'));
        $this->tenantDb->exec("UPDATE business_settings SET logo_path = 'uploads/owner-settings-fixture/logo.png' WHERE id = 1");
        $stmt = getDB()->prepare('INSERT INTO businesses (name, slug, owner_email, database_name) VALUES (?, ?, ?, ?)');
        $stmt->execute(['Owner Settings Fixture', $this->slug, $this->slug . '@example.com', $this->database]);
        $businessId = (int)getDB()->lastInsertId();
        $stmt = $this->tenantDb->prepare('INSERT INTO staff (name, first_name, goes_by, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (['owner', 'staff'] as $role) {
            $email = $role . '-' . $this->slug . '@example.com';
            $stmt->execute(['Settings Tester', 'Settings', 'Tester', $email, $email, password_hash('owner-settings-fixture-password', PASSWORD_DEFAULT), $role]);
        }
        $stmt = getDB()->prepare(
            "INSERT INTO stripe_signup_subscriptions
                (id, customer_id, subscription_id, plan, appointments_enabled, amount_cents, environment, business_id, provisioned_at)
             VALUES (?, 'cus_fixture', 'sub_fixture', 'growth', 1, 6400, 'test', ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$this->signupId, $businessId]);
        $this->server = new PayPalSignupTestServer(true);
        $state = $this->server->state();
        $state['stripe_customers'] = ['cus_fixture' => [
            'id' => 'cus_fixture', 'invoice_settings' => ['default_payment_method' => 'pm_first'],
        ]];
        $state['stripe_card_subscriptions'] = ['sub_fixture' => [
            'id' => 'sub_fixture', 'customer' => 'cus_fixture', 'status' => 'active', 'default_payment_method' => 'pm_first',
        ]];
        $state['stripe_payment_methods'] = [
            'pm_first' => self::card('pm_first', '4242'),
            'pm_second' => self::card('pm_second', '4444'),
            'pm_other' => self::card('pm_other', '5555', 'cus_other'),
        ];
        $this->server->writeState($state);
    }

    private static function card(string $id, string $last4, string $customer = 'cus_fixture'): array {
        return ['id' => $id, 'type' => 'card', 'customer' => $customer,
            'card' => ['brand' => 'visa', 'last4' => $last4, 'exp_month' => 12, 'exp_year' => 2035,
                'number' => '4242424242424242', 'fingerprint' => 'private-card-fingerprint']];
    }

    protected function tearDown(): void {
        if (!isset($this->slug)) {
            return;
        }
        $stmt = getDB()->prepare('DELETE FROM stripe_signup_subscriptions WHERE id = ?');
        $stmt->execute([$this->signupId]);
        $stmt = getDB()->prepare('DELETE FROM businesses WHERE slug = ? AND database_name = ?');
        $stmt->execute([$this->slug, $this->database]);
        getDB()->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        if (isset($this->server)) {
            $this->server->stop();
        }
        if (is_file($this->cookie)) {
            unlink($this->cookie);
        }
    }

    private function request(string $method, string $path, ?array $input = null, bool $form = false): array {
        $handle = curl_init($this->server->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_COOKIEFILE => $this->cookie,
            CURLOPT_HTTPHEADER => ['Host: ' . $this->slug . '.' . BASE_DOMAIN,
                'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json')],
        ]);
        if ($input !== null) {
            $input = array_replace(['csrf_token' => $this->token ?? ''], $input);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $form ? http_build_query($input) : json_encode($input, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($handle);
        self::assertNotFalse($body, curl_error($handle));
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => $body, 'data' => json_decode($body, true)];
    }

    private function login(string $role = 'owner'): void {
        $login = $this->request('POST', '/admin/login', [
            'email' => $role . '-' . $this->slug . '@example.com', 'password' => 'owner-settings-fixture-password',
        ], true);
        self::assertSame(302, $login['status'], $login['body']);
        if ($role === 'owner') {
            $page = $this->request('GET', '/admin/business');
            self::assertSame(200, $page['status'], $page['body']);
            self::assertSame(1, preg_match('/const csrfToken = "([a-f0-9]+)";/', $page['body'], $match));
            $this->token = $match[1];
        }
    }

    public function testTwoColorsCanBeSavedWithoutUploadingOrReplacingLogo(): void {
        $this->login();
        $page = $this->request('GET', '/admin/business');
        self::assertSame(2, substr_count($page['body'], 'type="color"'));
        $colors = ['theme_primary' => '#123456', 'theme_accent' => '#FFFFFF'];
        $saved = $this->request('POST', '/admin/api/save-theme', $colors);
        self::assertSame(200, $saved['status'], $saved['body']);
        $settings = $this->tenantDb->query('SELECT * FROM business_settings WHERE id = 1')->fetch();
        self::assertSame('uploads/owner-settings-fixture/logo.png', $settings['logo_path']);
        self::assertSame('#123456', $settings['theme_primary']);
        self::assertNotSame('#FFFFFF', $settings['theme_accent']);
        self::assertSame($settings['theme_accent'], $saved['data']['theme_accent']);
        $rgb = array_map('hexdec', str_split(substr($settings['theme_accent'], 1), 2));
        self::assertGreaterThanOrEqual(4.5, themeContrastWithWhite($rgb));
        self::assertSame(200, $this->request('POST', '/admin/api/save-theme', $colors)['status']);
        $public = $this->request('GET', '/');
        self::assertSame(200, $public['status'], $public['body']);
        self::assertStringContainsString($settings['theme_primary'], $public['body']);
        self::assertStringContainsString($settings['theme_accent'], $public['body']);
    }

    public function testSettingsRequireOwnerSessionValidCsrfAndValidColors(): void {
        self::assertSame(401, $this->request('POST', '/admin/api/save-theme', [])['status']);
        self::assertSame(401, $this->request('GET', '/admin/api/payment-methods')['status']);
        $this->login('staff');
        self::assertSame(403, $this->request('POST', '/admin/api/save-theme', [])['status']);
        self::assertSame(403, $this->request('GET', '/admin/api/payment-methods')['status']);
        self::assertStringNotContainsString('id="paymentMethods"', $this->request('GET', '/admin/profile')['body']);
        $this->request('GET', '/admin/logout');
        $this->login();
        $invalidToken = $this->request('POST', '/admin/api/save-theme', ['csrf_token' => 'wrong']);
        self::assertSame(403, $invalidToken['status']);
        self::assertSame('Expired website session. Consider refreshing the page.', $invalidToken['data']['error']);
        foreach ([['theme_primary' => '#123456'], ['theme_primary' => [], 'theme_accent' => '#123456'],
            ['theme_primary' => 'red', 'theme_accent' => '#123456']] as $input) {
            self::assertSame(422, $this->request('POST', '/admin/api/save-theme', $input)['status']);
        }
        self::assertSame(405, $this->request('GET', '/admin/api/save-theme')['status']);
        self::assertSame(403, $this->request('POST', '/admin/api/payment-methods', ['csrf_token' => 'wrong', 'action' => 'add'])['status']);
        self::assertStringContainsString('id="paymentMethods"', $this->request('GET', '/admin/profile')['body']);
        $stmt = getDB()->prepare('DELETE FROM stripe_signup_subscriptions WHERE id = ?');
        $stmt->execute([$this->signupId]);
        self::assertSame(404, $this->request('GET', '/admin/api/payment-methods')['status']);
        self::assertStringNotContainsString('id="paymentMethods"', $this->request('GET', '/admin/profile')['body']);
    }

    public function testMultipleMaskedCardsCanChangeDefaultAndRemoveOldCardWithoutCrossCustomerAccess(): void {
        $this->login();
        $cards = $this->request('GET', '/admin/api/payment-methods');
        self::assertSame(200, $cards['status'], $cards['body']);
        self::assertCount(2, $cards['data']['cards']);
        self::assertTrue($cards['data']['cards'][0]['is_default']);
        self::assertStringNotContainsString('4242424242424242', $cards['body']);
        self::assertStringNotContainsString('private-card-fingerprint', $cards['body']);
        self::assertSame(404, $this->request('POST', '/admin/api/payment-methods', ['action' => 'default', 'payment_method_id' => 'pm_other'])['status']);
        self::assertSame(409, $this->request('POST', '/admin/api/payment-methods', ['action' => 'remove', 'payment_method_id' => 'pm_first'])['status']);
        self::assertSame(200, $this->request('POST', '/admin/api/payment-methods', ['action' => 'default', 'payment_method_id' => 'pm_second'])['status']);
        $state = $this->server->state();
        self::assertSame('pm_second', $state['stripe_customers']['cus_fixture']['invoice_settings']['default_payment_method']);
        self::assertSame('pm_second', $state['stripe_card_subscriptions']['sub_fixture']['default_payment_method']);
        self::assertSame(200, $this->request('POST', '/admin/api/payment-methods', ['action' => 'remove', 'payment_method_id' => 'pm_first'])['status']);
        self::assertCount(1, $this->request('GET', '/admin/api/payment-methods')['data']['cards']);
        self::assertSame(409, $this->request('POST', '/admin/api/payment-methods', ['action' => 'remove', 'payment_method_id' => 'pm_second'])['status']);
    }

    public function testHostedCardSetupReusesOpenCheckoutAndVerifiesSavedCardBeforeSuccess(): void {
        $this->login();
        $created = $this->request('POST', '/admin/api/payment-methods', ['action' => 'add']);
        self::assertSame(200, $created['status'], $created['body']);
        self::assertStringStartsWith('https://checkout.stripe.com/', $created['data']['checkout_url']);
        self::assertSame($created['data']['checkout_url'], $this->request('POST', '/admin/api/payment-methods', ['action' => 'add'])['data']['checkout_url']);
        $state = $this->server->state();
        self::assertCount(1, $state['stripe_sessions']);
        $session = reset($state['stripe_sessions']);
        self::assertSame('setup', $session['mode']);
        self::assertSame('cus_fixture', $session['customer']);
        self::assertSame(409, $this->request('POST', '/admin/api/payment-methods', ['action' => 'verify', 'session_id' => 'cs_test_forged'])['status']);
        self::assertSame(503, $this->request('POST', '/admin/api/payment-methods', ['action' => 'verify', 'session_id' => $session['id']])['status']);
        $state = $this->server->state();
        $card = self::card('pm_new', '1234');
        $state['stripe_payment_methods']['pm_new'] = $card;
        $state['stripe_sessions'][$session['id']]['status'] = 'complete';
        $state['stripe_sessions'][$session['id']]['setup_intent'] = [
            'id' => 'seti_fixture', 'status' => 'succeeded', 'customer' => 'cus_fixture', 'payment_method' => $card,
        ];
        $this->server->writeState($state);
        self::assertSame(200, $this->request('POST', '/admin/api/payment-methods', ['action' => 'verify', 'session_id' => $session['id']])['status']);
        self::assertCount(3, $this->request('GET', '/admin/api/payment-methods')['data']['cards']);
        self::assertSame('pm_first', $this->server->state()['stripe_card_subscriptions']['sub_fixture']['default_payment_method']);
        self::assertSame(200, $this->request('POST', '/admin/api/payment-methods', ['action' => 'add'])['status']);
        self::assertCount(2, $this->server->state()['stripe_sessions']);
    }

    public function testPartialDefaultUpdateFailureIsExplicitAndCanBeRetried(): void {
        $this->login();
        $state = $this->server->state();
        $state['stripe_fail_path'] = '/v1/subscriptions/sub_fixture';
        $this->server->writeState($state);
        $failed = $this->request('POST', '/admin/api/payment-methods', ['action' => 'default', 'payment_method_id' => 'pm_second']);
        self::assertSame(503, $failed['status'], $failed['body']);
        self::assertArrayNotHasKey('success', $failed['data']);
        $state = $this->server->state();
        self::assertSame('pm_first', $state['stripe_card_subscriptions']['sub_fixture']['default_payment_method']);
        unset($state['stripe_fail_path']);
        $this->server->writeState($state);
        self::assertSame(200, $this->request('POST', '/admin/api/payment-methods', ['action' => 'default', 'payment_method_id' => 'pm_second'])['status']);
        self::assertSame('pm_second', $this->server->state()['stripe_card_subscriptions']['sub_fixture']['default_payment_method']);
    }
}
