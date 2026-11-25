<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ClientQ\Tests\Console\ConsoleTest;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once __DIR__ . '/PayPalSignupTestServer.php';
require_once dirname(__DIR__, 2) . '/lib/waitlist-hours.php';

final class TenantSignupSheetIntegrationTest extends TestCase {
    private string $baseUrl;
    private string $cookieJar;
    private string $slug;
    private string $email;
    private string $databaseName;
    private PayPalSignupTestServer $paypalServer;
    private ?string $paypalSignupId = null;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped(
                'Run with composer test:integration against the Docker stack.'
            );
        }

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $this->paypalServer = new PayPalSignupTestServer();
        $this->baseUrl = $this->paypalServer->baseUrl;
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'clientq-phpunit-cookie-');
        $this->slug = 'phpunit-' . $suffix;
        $this->email = $this->slug . '@example.com';
        $this->databaseName = 'clientq_tenant_'
            . substr(hash('sha256', $this->slug), 0, 32);
    }

    protected function tearDown(): void {
        if (!isset($this->slug)) {
            return;
        }

        $db = getDB();
        try {
            $db->beginTransaction();
            $deletePayment = $db->prepare('DELETE FROM paypal_signup_subscriptions WHERE id = ?');
            $deletePayment->execute([$this->paypalSignupId]);

            $businessStmt = $db->prepare(
                'SELECT id FROM businesses WHERE slug = :slug FOR UPDATE'
            );
            $businessStmt->execute([':slug' => $this->slug]);
            $businessId = $businessStmt->fetchColumn();
            if ($businessId !== false) {
                $deleteBusiness = $db->prepare('DELETE FROM businesses WHERE id = :id');
                $deleteBusiness->execute([':id' => $businessId]);
            }

            $deleteUser = $db->prepare(
                "DELETE FROM platform_users
                 WHERE email = :email AND role = 'business_owner'"
            );
            $deleteUser->execute([':email' => $this->email]);

            $deleteAttempts = $db->prepare(
                'DELETE FROM signup_attempts WHERE ip_address = :ip'
            );
            $deleteAttempts->execute([':ip' => $this->paypalServer->signupIp]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        } finally {
            if (preg_match('/^clientq_tenant_[a-f0-9]{32}$/', $this->databaseName)) {
                $db->exec("DROP DATABASE IF EXISTS `{$this->databaseName}`");
            }
            if (is_file($this->cookieJar)) {
                unlink($this->cookieJar);
            }
            $this->paypalServer->stop();
        }
    }

    #[ConsoleTest(
        'Provision a tenant through signup and exercise its public signup sheet.',
        'The tenant uses the gray, red, and black default theme, accepts a client, lists the client, and is fully cleaned up.'
    )]
    public function testCreatedTenantSignupSheetAcceptsAndListsAClient(): void {
        if (waitlistNow()->format('H:i') >= '23:58') {
            self::markTestSkipped('Avoid crossing the workday boundary during live signup fixtures.');
        }
        $platformGalley = $this->request('GET', '/galley');
        self::assertSame(404, $platformGalley['status']);

        $unknownGalley = $this->request('GET', '/galley', null, false, $this->tenantHost());
        self::assertSame(404, $unknownGalley['status']);

        $exampleWaitlist = $this->request('GET', '/example-waitlist');
        self::assertSame(200, $exampleWaitlist['status']);
        self::assertStringContainsString(
            '<title>Northside Barber Client Waitlist</title>',
            $exampleWaitlist['body']
        );
        self::assertStringContainsString(
            'aria-label="Northside Barber waitlist pages"',
            $exampleWaitlist['body']
        );
        self::assertStringContainsString('id="exampleWaitlistPanel"', $exampleWaitlist['body']);
        self::assertStringContainsString('id="exampleSignUpPanel"', $exampleWaitlist['body']);
        self::assertStringContainsString('id="examplePartyButtons"', $exampleWaitlist['body']);
        self::assertStringContainsString('id="exampleStaff"', $exampleWaitlist['body']);
        self::assertStringContainsString(
            'Reply STOP to opt out or HELP for help.',
            $exampleWaitlist['body']
        );
        self::assertStringContainsString('Privacy Statement', $exampleWaitlist['body']);
        self::assertStringContainsString('Terms &amp; Conditions', $exampleWaitlist['body']);
        self::assertLessThan(
            strpos($exampleWaitlist['body'], 'id="examplePhone"'),
            strpos(
                $exampleWaitlist['body'],
                'By providing your phone number, you agree to receive'
            )
        );
        self::assertStringNotContainsString('Preview only.', $exampleWaitlist['body']);
        self::assertStringNotContainsString('Demonstration form', $exampleWaitlist['body']);
        self::assertStringNotContainsString('Back to the product tour', $exampleWaitlist['body']);
        self::assertStringNotContainsString('/api/submit-signin', $exampleWaitlist['body']);

        $signup = $this->request('GET', '/signup');
        self::assertSame(200, $signup['status']);
        self::assertMatchesRegularExpression('/id="signupForm"/', $signup['body']);

        self::assertSame(
            1,
            preg_match(
                '~formData\.append\(\'csrf_token\', "([a-f0-9]+)"\)~',
                $signup['body'],
                $csrfMatch
            )
        );
        $csrfToken = $csrfMatch[1];

        $prepare = $this->request(
            'POST',
            '/api/prepare-business-registration',
            [
                'csrf_token' => $csrfToken,
                'plan' => 'starter',
                'business_name' => 'PHPUnit Service Shop',
                'slug' => $this->slug,
                'first_name' => 'PHPUnit',
                'last_name' => 'Owner',
                'email' => $this->email,
                'phone' => '4105550199',
                'password' => 'phpunit-owner-password',
            ]
        );
        self::assertSame(200, $prepare['status'], $prepare['body']);
        self::assertTrue($this->decodeJson($prepare)['success']);

        $businessCountStmt = getDB()->prepare(
            'SELECT COUNT(*) FROM businesses WHERE slug = :slug'
        );
        $businessCountStmt->execute([':slug' => $this->slug]);
        self::assertSame(
            0,
            (int)$businessCountStmt->fetchColumn(),
            'Reviewing signup must not provision the tenant.'
        );

        $confirmation = $this->request('GET', '/signup-confirmation');
        self::assertSame(200, $confirmation['status']);
        self::assertStringContainsString(
            'PHPUnit Service Shop',
            $confirmation['body']
        );
        self::assertStringContainsString(
            'http://' . $this->tenantHost() . '/',
            $confirmation['body']
        );
        self::assertStringContainsString(
            'first monthly payment',
            $confirmation['body']
        );

        $checkout = $this->request('POST', '/api/create-paypal-subscription', ['csrf_token' => $csrfToken], true);
        self::assertSame(200, $checkout['status'], $checkout['body']);
        $state = $this->paypalServer->state();
        $subscription = reset($state['subscriptions']);
        $this->paypalSignupId = $subscription['custom_id'];
        $this->paypalServer->approve($subscription['id']);

        $create = $this->request(
            'POST',
            '/api/register-business',
            ['csrf_token' => $csrfToken],
            true
        );
        self::assertSame(201, $create['status'], $create['body']);
        $createData = $this->decodeJson($create);
        self::assertTrue($createData['success']);
        self::assertSame(
            'http://' . $this->tenantHost() . '/admin/login',
            $createData['login_url']
        );
        self::assertArrayNotHasKey('owner_portal_url', $createData);

        $tenantDb = createDatabaseConnection($this->databaseName);
        $date = waitlistNow()->format('Y-m-d');
        $tenantDb->prepare("INSERT INTO waitlist_daily_hours (work_date, opening_time, closing_time) VALUES (?, '00:00', '23:59')")
            ->execute([$date]);
        $tenantDb->prepare("INSERT INTO staff_daily_availability (staff_id, work_date, opening_time, closing_time) SELECT id, ?, '00:00', '23:59' FROM staff")
            ->execute([$date]);
        $owner = $tenantDb->query(
            "SELECT id, goes_by, first_name, last_name, phone, email FROM staff
             WHERE role = 'owner' AND is_active = 1"
        )->fetch();
        self::assertIsArray($owner);
        self::assertSame('PHPUnit', $owner['goes_by']);
        self::assertSame('PHPUnit', $owner['first_name']);
        self::assertSame('Owner', $owner['last_name']);
        self::assertSame('4105550199', $owner['phone']);
        self::assertSame($this->email, $owner['email']);

        $settings = $tenantDb->query(
            'SELECT theme_primary, theme_accent FROM business_settings WHERE id = 1'
        )->fetch();
        self::assertSame(TENANT_THEME_DEFAULT_PRIMARY, $settings['theme_primary']);
        self::assertSame(TENANT_THEME_DEFAULT_ACCENT, $settings['theme_accent']);

        $tenantDb->exec('ALTER TABLE clients DROP COLUMN sms_consent_at, DROP COLUMN sms_consent');
        $consentMigration = file_get_contents(
            dirname(__DIR__, 2) . '/database/tenant-sms-consent-migration.sql'
        );
        self::assertIsString($consentMigration);
        for ($run = 0; $run < 2; $run++) {
            foreach (explode(';', $consentMigration) as $statement) {
                if (trim($statement) !== '') {
                    $tenantDb->query($statement)->closeCursor();
                }
            }
        }

        $legacySignIn = $this->request('GET', '/sign-in', null, false, $this->tenantHost());
        self::assertSame(302, $legacySignIn['status']);
        self::assertSame('/#sign-in', $legacySignIn['location']);

        $signupSheet = $this->request('GET', '/', null, false, $this->tenantHost());
        self::assertSame(200, $signupSheet['status']);
        self::assertStringContainsString('PHPUnit Service Shop', $signupSheet['body']);
        self::assertStringContainsString('id="signInForm"', $signupSheet['body']);
        self::assertStringContainsString('id="clientList"', $signupSheet['body']);
        self::assertStringContainsString('id="waitlistTab" aria-selected="true" aria-controls="waitlist"', $signupSheet['body']);
        self::assertStringContainsString('id="signupTab" aria-selected="false" aria-controls="sign-in"', $signupSheet['body']);
        self::assertStringContainsString('id="qrTab" aria-selected="false" aria-controls="qr-code"', $signupSheet['body']);
        self::assertSame(3, substr_count($signupSheet['body'], 'role="tab"'));
        self::assertStringNotContainsString('id="showSignIn"', $signupSheet['body']);
        self::assertStringNotContainsString('id="showWaitlist"', $signupSheet['body']);
        self::assertStringNotContainsString('id="showWaitlistBottom"', $signupSheet['body']);
        self::assertStringContainsString('id="sign-in" class="signup-panel hidden"', $signupSheet['body']);
        self::assertLessThan(
            strpos($signupSheet['body'], 'id="clientList"'),
            strpos($signupSheet['body'], 'id="waitlistTab"')
        );
        self::assertStringContainsString('id="signupQrCode"', $signupSheet['body']);
        self::assertStringContainsString('data-business-name="PHPUnit Service Shop"', $signupSheet['body']);
        self::assertStringContainsString('src="/assets/tenant-qr.js"', $signupSheet['body']);
        self::assertStringContainsString('id="signupQrMessage" role="status"', $signupSheet['body']);
        self::assertStringContainsString('`${window.location.origin}/#sign-in`', $signupSheet['body']);
        self::assertStringContainsString($owner['goes_by'], $signupSheet['body']);
        self::assertStringContainsString(TENANT_THEME_DEFAULT_PRIMARY, $signupSheet['body']);
        self::assertStringContainsString(TENANT_THEME_DEFAULT_ACCENT, $signupSheet['body']);
        self::assertStringContainsString(TENANT_THEME_DEFAULT_SOFT, $signupSheet['body']);
        self::assertStringContainsString(
            'By providing your phone number, you agree to receive',
            $signupSheet['body']
        );
        self::assertStringContainsString(
            'Reply STOP to opt out or HELP for help.',
            $signupSheet['body']
        );
        self::assertStringNotContainsString('type="checkbox"', $signupSheet['body']);

        $clientSignupWithoutConsent = $this->request(
            'POST',
            '/api/submit-signin',
            [
                'name' => 'Integration Client',
                'phone' => '4105550101',
                'staff_id' => (int)$owner['id'],
                'no_in_party' => 2,
            ],
            true,
            $this->tenantHost()
        );
        self::assertSame(400, $clientSignupWithoutConsent['status']);
        self::assertStringContainsString(
            'Transactional and informational text message consent is required.',
            $this->decodeJson($clientSignupWithoutConsent)['error']
        );

        $clientSignup = $this->request(
            'POST',
            '/api/submit-signin',
            [
                'name' => 'Integration Client',
                'phone' => '4105550101',
                'staff_id' => (int)$owner['id'],
                'no_in_party' => 2,
                'sms_consent' => true,
            ],
            true,
            $this->tenantHost()
        );
        self::assertSame(200, $clientSignup['status'], $clientSignup['body']);
        $clientSignupData = $this->decodeJson($clientSignup);
        self::assertTrue($clientSignupData['success']);
        self::assertMatchesRegularExpression(
            '/^[A-Z0-9]{6}$/',
            $clientSignupData['confirmation_code']
        );

        $waitlist = $this->request(
            'GET',
            '/api/get-clients?page=1&staff=all',
            null,
            false,
            $this->tenantHost()
        );
        self::assertSame(200, $waitlist['status'], $waitlist['body']);
        $waitlistData = $this->decodeJson($waitlist);
        self::assertSame(1, $waitlistData['total_clients']);
        self::assertSame('Integration Client', $waitlistData['clients'][0]['name']);
        self::assertSame(2, (int)$waitlistData['clients'][0]['no_in_party']);
        self::assertSame('PHPUnit', $waitlistData['clients'][0]['staff_name']);

        $galley = $this->request('GET', '/galley', null, false, $this->tenantHost());
        self::assertSame(200, $galley['status'], $galley['body']);
        self::assertStringContainsString(
            '<title>PHPUnit Service Shop Galley View</title>',
            $galley['body']
        );
        self::assertStringContainsString('const isGalleyView = true;', $galley['body']);
        self::assertStringContainsString('id="clientList"', $galley['body']);
        self::assertStringContainsString('Time waiting', $galley['body']);
        self::assertStringContainsString('No. in Party', $galley['body']);
        self::assertStringNotContainsString('id="staffFilter"', $galley['body']);
        self::assertStringNotContainsString('<a ', $galley['body']);
        self::assertStringNotContainsString('role="tab"', $galley['body']);
        self::assertStringNotContainsString('id="signInForm"', $galley['body']);
        self::assertStringNotContainsString('qrcode.min.js', $galley['body']);
        self::assertStringNotContainsString('id="showSignIn"', $galley['body']);
        self::assertStringNotContainsString('id="showWaitlist"', $galley['body']);

        $normalWaitlist = $this->request('GET', '/', null, false, $this->tenantHost());
        self::assertSame(200, $normalWaitlist['status']);
        self::assertStringContainsString('const isGalleyView = false;', $normalWaitlist['body']);
        self::assertStringContainsString('id="staffFilter"', $normalWaitlist['body']);
        self::assertStringContainsString('id="signInForm"', $normalWaitlist['body']);
        self::assertStringNotContainsString('href="sign-in"', $normalWaitlist['body']);
        self::assertStringContainsString('href="staff-profiles"', $normalWaitlist['body']);

        $storedConsent = $tenantDb->query(
            "SELECT sms_consent, sms_consent_at
             FROM clients
             WHERE name = 'Integration Client'"
        )->fetch();
        self::assertSame(1, (int)$storedConsent['sms_consent']);
        self::assertNotEmpty($storedConsent['sms_consent_at']);

        $insertClient = $tenantDb->prepare(
            'INSERT INTO clients (name, phone, staff_id, confirmation_code)
             VALUES (?, ?, ?, ?)'
        );
        for ($i = 1; $i <= 10; $i++) {
            $insertClient->execute([
                'Display Client ' . $i,
                '4105550101',
                (int)$owner['id'],
                sprintf('T%05d', $i),
            ]);
        }

        $displayClientIds = [];
        foreach ([1 => 10, 2 => 1] as $page => $expectedCount) {
            $displayWaitlist = $this->request(
                'GET',
                '/api/get-clients?galley=1&page=' . $page,
                null,
                false,
                $this->tenantHost()
            );
            self::assertSame(200, $displayWaitlist['status'], $displayWaitlist['body']);
            $displayData = $this->decodeJson($displayWaitlist);
            self::assertCount($expectedCount, $displayData['clients']);
            self::assertSame(2, $displayData['total_pages']);
            self::assertSame(11, $displayData['total_clients']);
            $displayClientIds = array_merge(
                $displayClientIds,
                array_column($displayData['clients'], 'id')
            );
        }
        self::assertCount(11, array_unique($displayClientIds));

        $standardWaitlist = $this->request(
            'GET',
            '/api/get-clients?page=1',
            null,
            false,
            $this->tenantHost()
        );
        self::assertSame(200, $standardWaitlist['status'], $standardWaitlist['body']);
        $standardData = $this->decodeJson($standardWaitlist);
        self::assertCount(11, $standardData['clients']);
        self::assertSame(1, $standardData['total_pages']);
    }

    private function tenantHost(): string {
        return $this->slug . '.' . BASE_DOMAIN;
    }

    private function decodeJson(array $response): array {
        $decoded = json_decode($response['body'], true);
        self::assertIsArray($decoded, $response['body']);
        return $decoded;
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $json = false,
        string $host = BASE_DOMAIN
    ): array {
        $curl = curl_init($this->baseUrl . $path);
        $headers = ['Host: ' . $host];
        $location = null;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, strlen('Location:')));
                }
                return strlen($header);
            },
        ]);

        if ($body !== null) {
            if ($json) {
                $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
                $headers[] = 'Content-Type: application/json';
                curl_setopt($curl, CURLOPT_POSTFIELDS, $encodedBody);
            } else {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $message = curl_error($curl);
            curl_close($curl);
            self::fail('HTTP request failed: ' . $message);
        }

        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => $responseBody, 'location' => $location];
    }
}
