<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ClientQ\Tests\Console\ConsoleTest;

require_once dirname(__DIR__, 2) . '/src/php/config.php';

final class OwnerProfileNotesIntegrationTest extends TestCase {
    private string $baseUrl;
    private string $cookieJar;
    private string $ownerEmail;
    private string $adminEmail;
    private string $adminPassword = 'phpunit-admin-password';
    private int $ownerId;
    private int $adminId;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped(
                'Run with composer test:integration against the Docker stack.'
            );
        }

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $this->baseUrl = getenv('CLIENTQ_INTEGRATION_BASE_URL') ?: 'http://127.0.0.1';
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'clientq-profile-cookie-');
        $this->ownerEmail = 'profile-' . $suffix . '-owner@example.com';
        $this->adminEmail = 'profile-' . $suffix . '-admin@example.com';

        $db = getDB();
        $ownerStmt = $db->prepare(
            "INSERT INTO platform_users
                (name, first_name, last_name, phone, email, password_hash, role)
             VALUES
                (:name, :first_name, :last_name, :phone, :email, :password_hash, 'business_owner')"
        );
        $ownerStmt->execute([
            ':name' => 'Profile Test Owner',
            ':first_name' => 'Profile',
            ':last_name' => 'Owner',
            ':phone' => '5551234567',
            ':email' => $this->ownerEmail,
            ':password_hash' => password_hash('owner-password', PASSWORD_DEFAULT),
        ]);
        $this->ownerId = (int)$db->lastInsertId();

        $adminStmt = $db->prepare(
            "INSERT INTO platform_users (name, email, password_hash, role)
             VALUES (:name, :email, :password_hash, 'platform_owner')"
        );
        $adminStmt->execute([
            ':name' => 'Profile Test Admin',
            ':email' => $this->adminEmail,
            ':password_hash' => password_hash($this->adminPassword, PASSWORD_DEFAULT),
        ]);
        $this->adminId = (int)$db->lastInsertId();
    }

    protected function tearDown(): void {
        if (!isset($this->ownerEmail)) {
            return;
        }

        $stmt = getDB()->prepare(
            'DELETE FROM platform_users WHERE email IN (:owner_email, :admin_email)'
        );
        $stmt->execute([
            ':owner_email' => $this->ownerEmail,
            ':admin_email' => $this->adminEmail,
        ]);

        if (is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    #[ConsoleTest(
        'Open a tenant owner profile and add an internal note as a platform administrator.',
        'The profile displays owner information and stores an escaped note with the correct administrator author.'
    )]
    public function testPlatformAdminCanReadOwnerProfileAndAddNote(): void {
        $login = $this->request(
            'POST',
            '/platform-admin/login',
            ['email' => $this->adminEmail, 'password' => $this->adminPassword]
        );
        self::assertSame(302, $login['status'], $login['body']);

        $profile = $this->request(
            'GET',
            '/platform-admin/owner-profile?owner_id=' . $this->ownerId
        );
        self::assertSame(200, $profile['status'], $profile['body']);
        self::assertStringContainsString('Profile Test Owner', $profile['body']);
        self::assertStringContainsString($this->ownerEmail, $profile['body']);
        self::assertStringContainsString('(555) 123-4567', $profile['body']);
        self::assertSame(
            1,
            preg_match(
                '/name="csrf_token" value="([a-f0-9]+)"/',
                $profile['body'],
                $csrfMatch
            )
        );

        $noteText = 'Follow up about onboarding <script>alert("x")</script>.';
        $save = $this->request(
            'POST',
            '/platform-admin/owner-profile?owner_id=' . $this->ownerId,
            [
                'csrf_token' => $csrfMatch[1],
                'owner_id' => (string)$this->ownerId,
                'note_text' => $noteText,
            ]
        );
        self::assertSame(302, $save['status'], $save['body']);

        $savedProfile = $this->request(
            'GET',
            '/platform-admin/owner-profile?owner_id=' . $this->ownerId
        );
        self::assertSame(200, $savedProfile['status'], $savedProfile['body']);
        self::assertStringContainsString(
            'Follow up about onboarding &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;.',
            $savedProfile['body']
        );
        self::assertStringNotContainsString(
            '<script>alert("x")</script>',
            $savedProfile['body']
        );
        self::assertStringContainsString('Profile Test Admin', $savedProfile['body']);

        $noteStmt = getDB()->prepare(
            'SELECT note_text, created_by FROM business_owner_notes
             WHERE owner_user_id = :owner_user_id'
        );
        $noteStmt->execute([':owner_user_id' => $this->ownerId]);
        $note = $noteStmt->fetch();
        self::assertSame($noteText, $note['note_text']);
        self::assertSame($this->adminId, (int)$note['created_by']);
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null
    ): array {
        $curl = curl_init($this->baseUrl . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Host: ' . BASE_DOMAIN],
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $message = curl_error($curl);
            curl_close($curl);
            self::fail('HTTP request failed: ' . $message);
        }

        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => $responseBody];
    }
}
