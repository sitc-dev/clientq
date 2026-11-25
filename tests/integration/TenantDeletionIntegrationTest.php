<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ClientQ\Tests\Console\ConsoleTest;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once dirname(__DIR__, 2) . '/lib/tenant-deletion.php';
require_once dirname(__DIR__, 2) . '/lib/password-reset.php';
require_once dirname(__DIR__, 2) . '/lib/employee-invitation.php';

final class TenantDeletionIntegrationTest extends TestCase {
    private string $baseUrl;
    private string $cookieJar;
    private string $slug;
    private string $businessName;
    private string $databaseName;
    private string $ownerEmail;
    private string $adminEmail;
    private string $adminPassword = 'phpunit-admin-password';
    private int $businessId;
    private int $ownerId;
    private int $adminId;
    private string $uploadDirectory;
    private array $additionalBusinesses = [];
    private array $additionalOwnerIds = [];
    private ?string $statusLogoPath = null;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped(
                'Run with composer test:integration against the Docker stack.'
            );
        }
        self::assertSame('db', DB_HOST, 'Deletion fixtures must not run against the remote database.');

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $this->baseUrl = getenv('CLIENTQ_INTEGRATION_BASE_URL') ?: 'http://127.0.0.1';
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'clientq-delete-cookie-');
        $this->slug = 'delete-' . $suffix;
        $this->businessName = 'Delete Tenant ' . strtoupper($suffix);
        $this->databaseName = 'clientq_tenant_'
            . substr(hash('sha256', $this->slug), 0, 32);
        $this->ownerEmail = $this->slug . '-owner@example.com';
        $this->adminEmail = $this->slug . '-admin@example.com';
        $this->uploadDirectory = dirname(__DIR__, 2) . '/uploads/' . $this->slug;

        $db = getDB();
        $db->exec(
            "CREATE DATABASE `{$this->databaseName}`
             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $tenantDb = createDatabaseConnection($this->databaseName);
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-schema.sql');
        self::assertIsString($schema);
        $tenantDb->exec($schema);

        $db->beginTransaction();
        try {
            $businessStmt = $db->prepare(
                'INSERT INTO businesses (name, slug, owner_email, database_name, plan)
                 VALUES (:name, :slug, :owner_email, :database_name, :plan)'
            );
            $businessStmt->execute([
                ':name' => $this->businessName,
                ':slug' => $this->slug,
                ':owner_email' => $this->ownerEmail,
                ':database_name' => $this->databaseName,
                ':plan' => 'starter',
            ]);
            $this->businessId = (int)$db->lastInsertId();

            $ownerStmt = $db->prepare(
                "INSERT INTO platform_users (name, email, password_hash, role)
                 VALUES (:name, :email, :password_hash, 'business_owner')"
            );
            $ownerStmt->execute([
                ':name' => 'Deletion Test Owner',
                ':email' => $this->ownerEmail,
                ':password_hash' => password_hash('owner-password', PASSWORD_DEFAULT),
            ]);
            $this->ownerId = (int)$db->lastInsertId();

            $adminStmt = $db->prepare(
                "INSERT INTO platform_users (name, email, password_hash, role)
                 VALUES (:name, :email, :password_hash, 'platform_owner')"
            );
            $adminStmt->execute([
                ':name' => 'Deletion Test Admin',
                ':email' => $this->adminEmail,
                ':password_hash' => password_hash($this->adminPassword, PASSWORD_DEFAULT),
            ]);
            $this->adminId = (int)$db->lastInsertId();

            $linkStmt = $db->prepare(
                'INSERT INTO business_owners (business_id, user_id)
                 VALUES (:business_id, :user_id)'
            );
            $linkStmt->execute([
                ':business_id' => $this->businessId,
                ':user_id' => $this->ownerId,
            ]);

            $invoiceStmt = $db->prepare(
                'INSERT INTO invoices
                    (business_id, created_by, invoice_number, description, amount_cents)
                 VALUES
                    (:business_id, :created_by, :invoice_number, :description, :amount_cents)'
            );
            $invoiceStmt->execute([
                ':business_id' => $this->businessId,
                ':created_by' => $this->adminId,
                ':invoice_number' => 'TEST-' . strtoupper($suffix),
                ':description' => 'Tenant deletion integration test',
                ':amount_cents' => 5900,
            ]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        if (!is_dir($this->uploadDirectory)) {
            mkdir($this->uploadDirectory, 0755, true);
        }
        file_put_contents($this->uploadDirectory . '/logo-test.png', 'test upload');
        chmod($this->uploadDirectory, 0777);
    }

    protected function tearDown(): void {
        if (!isset($this->slug)) {
            return;
        }

        $db = getDB();
        foreach ($this->additionalBusinesses as $business) {
            $stmt = $db->prepare('DELETE FROM businesses WHERE id = :id');
            $stmt->execute([':id' => $business['id']]);
            $db->exec("DROP DATABASE IF EXISTS `{$business['database_name']}`");
            removeTenantUploadDirectory($business['slug']);
            $stmt = $db->prepare('DELETE FROM tenant_status_pages WHERE slug = :slug');
            $stmt->execute([':slug' => $business['slug']]);
        }
        foreach ($this->additionalOwnerIds as $ownerId) {
            $stmt = $db->prepare('DELETE FROM platform_users WHERE id = :id');
            $stmt->execute([':id' => $ownerId]);
        }
        $businessStmt = $db->prepare('DELETE FROM businesses WHERE slug = :slug');
        $businessStmt->execute([':slug' => $this->slug]);

        $userStmt = $db->prepare(
            'DELETE FROM platform_users WHERE email IN (:owner_email, :admin_email)'
        );
        $userStmt->execute([
            ':owner_email' => $this->ownerEmail,
            ':admin_email' => $this->adminEmail,
        ]);

        if (preg_match('/^clientq_tenant_[a-f0-9]{32}$/', $this->databaseName)) {
            $db->exec("DROP DATABASE IF EXISTS `{$this->databaseName}`");
        }
        removeTenantUploadDirectory($this->slug);
        $stmt = $db->prepare('DELETE FROM tenant_status_pages WHERE slug = :slug');
        $stmt->execute([':slug' => $this->slug]);
        if ($this->statusLogoPath !== null && is_file(dirname(__DIR__, 2) . '/' . $this->statusLogoPath)) {
            unlink(dirname(__DIR__, 2) . '/' . $this->statusLogoPath);
        }

        if (is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    public function testEmployeeInvitationsGeneratePasswordsAndRollbackUndeliveredAccounts(): void {
        $fixture = $this->createInvitationFixture();
        $db = createDatabaseConnection($this->databaseName);
        self::assertTrue(password_verify($fixture['password'], $fixture['row']['password_hash']));
        self::assertNotSame($fixture['password'], $fixture['row']['password_hash']);
        self::assertSame(1, (int)$fixture['row']['must_change_password']);
        self::assertSame('staff', $fixture['row']['role']);
        self::assertSame('', $fixture['row']['experience']);
        $data = array_replace($fixture['data'], ['email' => 'undelivered-employee@example.com']);
        try {
            createInvitedEmployee($db, $data, 15, $this->businessName, 'https://example.com/admin/login',
                static fn(): array => ['success' => false, 'error' => 'Simulated delivery failure']);
            self::fail('Mail failure must prevent employee creation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Simulated delivery failure', $exception->getMessage());
        }
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM staff WHERE username = 'undelivered-employee@example.com'")->fetchColumn());
        self::assertFalse($db->inTransaction());
        try {
            createInvitedEmployee($db, $data, 1, $this->businessName, 'https://example.com/admin/login',
                static function (): array { self::fail('Over-quota invitations must not be sent.'); });
            self::fail('Employee limit must be enforced.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('1 active employees', $exception->getMessage());
        }
        self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM staff WHERE role = 'staff'")->fetchColumn());
    }

    public function testInvitedEmployeeMustSetDifferentPasswordBeforeAccessingStaffTools(): void {
        $fixture = $this->createInvitationFixture();
        $host = $this->slug . '.' . BASE_DOMAIN;
        $login = $this->request('POST', '/admin/login', [
            'username' => $fixture['data']['username'], 'password' => $fixture['password'],
        ], false, $host);
        self::assertSame(302, $login['status']);
        self::assertSame('set-password', $login['headers']['location']);
        foreach (['/admin/dashboard', '/admin/dashboard.php', '/admin/profile', '/admin/staff', '/admin/billing'] as $path) {
            $blocked = $this->request('GET', $path, null, false, $host);
            if (str_ends_with($path, '.php')) {
                self::assertSame(301, $blocked['status']);
                self::assertSame(substr($path, 0, -4), parse_url($blocked['headers']['location'], PHP_URL_PATH));
                continue;
            }
            self::assertSame(302, $blocked['status']);
            self::assertSame('/admin/set-password', $blocked['headers']['location']);
        }
        foreach (['get-clients', 'get-clients.php', 'update-profile', 'save-employee', 'create-checkout-session', 'delete-client'] as $endpoint) {
            $blocked = $this->request('POST', '/admin/api/' . $endpoint, [], true, $host);
            if (str_ends_with($endpoint, '.php')) {
                self::assertSame(301, $blocked['status']);
                self::assertSame('/admin/api/get-clients', parse_url($blocked['headers']['location'], PHP_URL_PATH));
                continue;
            }
            self::assertSame(403, $blocked['status'], $blocked['body']);
            self::assertTrue($this->decodeJson($blocked)['password_change_required']);
        }
        $form = $this->request('GET', '/admin/set-password', null, false, $host);
        self::assertSame(200, $form['status']);
        self::assertSame(1, preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form['body'], $match));
        $payload = ['new_password' => 'my-permanent-password', 'confirm_password' => 'my-permanent-password'];
        self::assertSame(403, $this->request('POST', '/admin/api/change-password', $payload, true, $host)['status']);
        $payload['csrf_token'] = $match[1];
        foreach ([
            ['new_password' => 'short', 'confirm_password' => 'short'],
            ['confirm_password' => 'different'],
            ['new_password' => $fixture['password'], 'confirm_password' => $fixture['password']],
        ] as $invalid) {
            $response = $this->request('POST', '/admin/api/change-password', array_replace($payload, $invalid), true, $host);
            self::assertSame(400, $response['status'], $response['body']);
        }
        $originalJar = $this->cookieJar;
        $staleJar = tempnam(sys_get_temp_dir(), 'clientq-invitation-cookie-');
        $otherJar = tempnam(sys_get_temp_dir(), 'clientq-invitation-other-cookie-');
        copy($originalJar, $staleJar);
        try {
            $this->cookieJar = $otherJar;
            self::assertSame(302, $this->request('POST', '/admin/login', [
                'username' => $fixture['data']['username'], 'password' => $fixture['password'],
            ], false, $host)['status']);
            $this->cookieJar = $originalJar;
            $changed = $this->request('POST', '/admin/api/change-password', $payload, true, $host);
            self::assertSame(200, $changed['status'], $changed['body']);
            self::assertSame(200, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);
            self::assertSame(302, $this->request('GET', '/admin/set-password', null, false, $host)['status']);
            $row = createDatabaseConnection($this->databaseName)->query(
                "SELECT * FROM staff WHERE id = {$fixture['row']['id']}"
            )->fetch();
            self::assertSame(0, (int)$row['must_change_password']);
            self::assertSame(1, (int)$row['password_version']);
            self::assertTrue(password_verify('my-permanent-password', $row['password_hash']));
            self::assertFalse(password_verify($fixture['password'], $row['password_hash']));
            foreach ([$staleJar, $otherJar] as $jar) {
                $this->cookieJar = $jar;
                self::assertSame(401, $this->request('GET', '/admin/api/get-clients', null, false, $host)['status']);
            }
        } finally {
            $this->cookieJar = $originalJar;
            unlink($staleJar);
            unlink($otherJar);
        }
        $this->request('GET', '/admin/logout', null, false, $host);
        $old = $this->request('POST', '/admin/login', [
            'username' => $fixture['data']['username'], 'password' => $fixture['password'],
        ], false, $host);
        self::assertSame(200, $old['status']);
        self::assertStringContainsString('Invalid email or password.', $old['body']);
        $new = $this->request('POST', '/admin/login', [
            'username' => $fixture['data']['username'], 'password' => 'my-permanent-password',
        ], false, $host);
        self::assertSame(302, $new['status']);
        self::assertSame('dashboard', $new['headers']['location']);
    }

    public function testOwnerProfileEditsDoNotResendInvitationsAndCreationNeedsEmail(): void {
        $fixture = $this->createInvitationFixture();
        $this->loginTenantOwnerAndGetBillingCsrf();
        $host = $this->slug . '.' . BASE_DOMAIN;
        $form = $this->request('GET', '/admin/staff', null, false, $host);
        self::assertStringContainsString('id="firstName"', $form['body']);
        self::assertStringNotContainsString('id="experience"', $form['body']);
        self::assertStringNotContainsString('id="username"', $form['body']);
        self::assertStringNotContainsString('type="file"', $form['body']);
        self::assertStringContainsString('id="passwordFields" class="hidden', $form['body']);
        $db = createDatabaseConnection($this->databaseName);
        $db->exec("UPDATE staff SET experience = 'Employee-written experience' WHERE id = {$fixture['row']['id']}");
        $edit = array_replace($fixture['data'], ['id' => $fixture['row']['id'], 'first_name' => 'New First Name']);
        unset($edit['name']);
        $saved = $this->request('POST', '/admin/api/save-employee', $edit, true, $host);
        self::assertSame(200, $saved['status'], $saved['body']);
        $db = createDatabaseConnection($this->databaseName);
        $row = $db->query("SELECT * FROM staff WHERE id = {$fixture['row']['id']}")->fetch();
        self::assertSame('New First Name', $row['first_name']);
        self::assertSame($fixture['row']['password_hash'], $row['password_hash']);
        self::assertSame(1, (int)$row['must_change_password']);
        self::assertSame('Employee-written experience', $row['experience']);
        $new = array_replace($fixture['data'], ['username' => 'new-employee@example.com', 'email' => '']);
        self::assertSame(422, $this->request('POST', '/admin/api/save-employee', $new, true, $host)['status']);
        if (!(getenv('CLIENTQ_SMTP_HOST') ?: '')) {
            $new['email'] = 'new-employee@example.com';
            $failed = $this->request('POST', '/admin/api/save-employee', $new, true, $host);
            self::assertSame(503, $failed['status'], $failed['body']);
            self::assertStringContainsString('invitation email', $this->decodeJson($failed)['error']);
            self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM staff WHERE username = 'new-employee@example.com'")->fetchColumn());
        }
    }

    public function testInvitationMigrationKeepsExistingPasswordsAndSupportsLongExperience(): void {
        $db = createDatabaseConnection($this->databaseName);
        $db->exec('ALTER TABLE staff DROP COLUMN must_change_password, MODIFY experience VARCHAR(50) NULL DEFAULT NULL');
        $insert = $db->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash) VALUES ('Existing Employee', '', 'existing', :hash)"
        );
        $hash = password_hash('existing-password', PASSWORD_DEFAULT);
        $insert->execute([':hash' => $hash]);
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-employee-invitation-migration.sql');
        self::assertIsString($migration);
        $db->exec($migration);
        $experience = str_repeat("Multiline experience.\n", 10);
        $update = $db->prepare("UPDATE staff SET experience = :experience WHERE username = 'existing'");
        $update->execute([':experience' => $experience]);
        $db->exec($migration);
        $row = $db->query("SELECT * FROM staff WHERE username = 'existing'")->fetch();
        self::assertSame($hash, $row['password_hash']);
        self::assertSame(0, (int)$row['must_change_password']);
        self::assertSame($experience, $row['experience']);
    }

    private function createInvitationFixture(): array {
        $data = [
            'name' => 'Invited Employee', 'first_name' => 'Invited', 'last_name' => 'Employee',
            'goes_by' => '', 'username' => 'invited-employee@example.com', 'email' => 'invited-employee@example.com',
            'position' => 'Stylist', 'experience' => str_repeat("Experience line.\n", 10), 'additional_info' => '',
        ];
        $password = '';
        $url = 'http://' . $this->slug . '.' . BASE_DOMAIN . '/admin/login';
        $db = createDatabaseConnection($this->databaseName);
        createInvitedEmployee($db, $data, 15, $this->businessName, $url,
            static function (string $recipient, string $subject, string $body) use (&$password, $data, $url): array {
                self::assertSame($data['email'], $recipient);
                self::assertStringContainsString($url, $body);
                self::assertStringContainsString('Email: ' . $data['email'], $body);
                self::assertStringNotContainsString('Username:', $body);
                self::assertStringContainsString('first login', $body);
                self::assertSame(1, preg_match('/Temporary password: ([^\r\n]+)/', $body, $match));
                $password = $match[1];
                return ['success' => true];
            });
        $row = $db->query("SELECT * FROM staff WHERE username = 'invited-employee@example.com'")->fetch();
        return ['data' => $data, 'password' => $password, 'row' => $row];
    }

    public function testEmailMigrationPreservesPasswordsAndRevokesRenamedLoginSessions(): void {
        $db = createDatabaseConnection($this->databaseName);
        $insert = $db->prepare('INSERT INTO staff (name, goes_by, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
        $hash = password_hash('email-login-password', PASSWORD_DEFAULT);
        $insert->execute(['Email Owner', '', 'owner@example.com', '', $hash, 'owner']);
        $ownerId = (int)$db->lastInsertId();
        $insert->execute(['Email Staff', '', 'old-staff@example.com', 'staff@example.com', $hash, 'staff']);
        $staffId = (int)$db->lastInsertId();
        $insert->execute(['Missing Email', '', 'legacy-name', '', $hash, 'staff']);
        $host = $this->slug . '.' . BASE_DOMAIN;
        self::assertSame(302, $this->request('POST', '/admin/login', [
            'email' => 'old-staff@example.com', 'password' => 'email-login-password',
        ], false, $host)['status']);
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-email-profile-migration.sql');
        self::assertIsString($migration);
        $db->exec($migration);
        $db->exec($migration);
        $owner = $db->query("SELECT * FROM staff WHERE id = $ownerId")->fetch();
        $staff = $db->query("SELECT * FROM staff WHERE id = $staffId")->fetch();
        self::assertSame('owner@example.com', $owner['email']);
        self::assertSame($hash, $owner['password_hash']);
        self::assertSame('staff@example.com', $staff['username']);
        self::assertSame($hash, $staff['password_hash']);
        self::assertSame(1, (int)$staff['password_version']);
        self::assertSame(401, $this->request('GET', '/admin/api/get-clients', null, false, $host)['status']);
        self::assertSame(200, $this->request('POST', '/admin/login', [
            'email' => 'legacy-name', 'password' => 'email-login-password',
        ], false, $host)['status']);
        $login = $this->request('POST', '/admin/login', [
            'email' => 'STAFF@EXAMPLE.COM', 'password' => 'email-login-password',
        ], false, $host);
        self::assertSame(302, $login['status']);
        $form = $this->request('GET', '/admin/profile', null, false, $host);
        self::assertStringContainsString('id="zip"', $form['body']);
        $duplicate = $this->request('POST', '/admin/api/update-profile', [
            'first_name' => 'Email', 'last_name' => 'Staff', 'email' => 'owner@example.com',
        ], true, $host);
        self::assertSame(409, $duplicate['status'], $duplicate['body']);
        self::assertSame('staff@example.com', $db->query("SELECT username FROM staff WHERE id = $staffId")->fetchColumn());
        $changed = $this->request('POST', '/admin/api/update-profile', [
            'first_name' => 'Email', 'last_name' => 'Staff', 'email' => 'changed@example.com',
        ], true, $host);
        self::assertSame(200, $changed['status'], $changed['body']);
        self::assertSame(200, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);
        $this->request('GET', '/admin/logout', null, false, $host);
        self::assertSame(200, $this->request('POST', '/admin/login', [
            'email' => 'staff@example.com', 'password' => 'email-login-password',
        ], false, $host)['status']);
        self::assertSame(302, $this->request('POST', '/admin/login', [
            'email' => 'changed@example.com', 'password' => 'email-login-password',
        ], false, $host)['status']);
    }

    public function testProfilePhotosAreSelfOnlyForBothOwnersAndEmployees(): void {
        $db = createDatabaseConnection($this->databaseName);
        $insert = $db->prepare('INSERT INTO staff (name, goes_by, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
        $ids = [];
        foreach (['owner', 'staff'] as $role) {
            $email = 'photo-' . $role . '@example.com';
            $insert->execute(['Photo ' . $role, '', $email, $email, password_hash('photo-password', PASSWORD_DEFAULT), $role]);
            $ids[$role] = (int)$db->lastInsertId();
        }
        $imagePath = $this->uploadDirectory . '/photo-source.png';
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0c8AAAAASUVORK5CYII='));
        $badPath = $this->uploadDirectory . '/not-an-image.png';
        file_put_contents($badPath, '<?php echo "not an image";');
        $host = $this->slug . '.' . BASE_DOMAIN;
        self::assertSame(401, $this->request('POST', '/admin/api/upload-profile-photo', [], false, $host)['status']);
        foreach (['owner', 'staff'] as $role) {
            $this->request('GET', '/admin/logout', null, false, $host);
            self::assertSame(302, $this->request('POST', '/admin/login', [
                'email' => 'photo-' . $role . '@example.com', 'password' => 'photo-password',
            ], false, $host)['status']);
            $form = $this->request('GET', '/admin/profile', null, false, $host);
            self::assertSame(1, preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form['body'], $match));
            $payload = ['csrf_token' => $match[1], 'photo' => new CURLFile($imagePath, 'image/png', 'untrusted.php')];
            self::assertSame(403, $this->request('POST', '/admin/api/upload-profile-photo',
                array_replace($payload, ['csrf_token' => 'wrong']), false, $host)['status']);
            $otherId = $ids[$role === 'owner' ? 'staff' : 'owner'];
            $otherPhoto = $db->query("SELECT profile_pic FROM staff WHERE id = $otherId")->fetchColumn();
            self::assertSame(403, $this->request('POST', '/admin/api/upload-profile-photo',
                array_replace($payload, ['staff_id' => $otherId]), false, $host)['status']);
            self::assertSame(422, $this->request('POST', '/admin/api/upload-profile-photo',
                array_replace($payload, ['photo' => new CURLFile($badPath, 'image/png', 'fake.png')]), false, $host)['status']);
            $uploaded = $this->request('POST', '/admin/api/upload-profile-photo', $payload, false, $host);
            self::assertSame(200, $uploaded['status'], $uploaded['body']);
            $url = $this->decodeJson($uploaded)['photo_url'];
            self::assertMatchesRegularExpression('~^/uploads/' . $this->slug . '/profile-' . $ids[$role] . '-[a-f0-9]{24}\.png$~', $url);
            self::assertFileExists(dirname(__DIR__, 2) . $url);
            self::assertSame(ltrim($url, '/'), $db->query("SELECT profile_pic FROM staff WHERE id = {$ids[$role]}")->fetchColumn());
            self::assertSame($otherPhoto, $db->query("SELECT profile_pic FROM staff WHERE id = $otherId")->fetchColumn());
            $public = $this->request('GET', '/staff-profiles', null, false, $host);
            self::assertStringContainsString('src="' . $url . '"', $public['body']);
            $replacement = $this->request('POST', '/admin/api/upload-profile-photo', $payload, false, $host);
            self::assertSame(200, $replacement['status'], $replacement['body']);
            self::assertNotSame($url, $this->decodeJson($replacement)['photo_url']);
            self::assertFileDoesNotExist(dirname(__DIR__, 2) . $url);
        }
        $other = $this->createAdditionalTenant($this->ownerId);
        self::assertSame(401, $this->request('POST', '/admin/api/upload-profile-photo', $payload, false,
            $other['slug'] . '.' . BASE_DOMAIN)['status']);
    }

    public function testStructuredStaffProfilesValidateAndKeepContactDetailsPrivate(): void {
        $db = createDatabaseConnection($this->databaseName);
        $insert = $db->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash, role)
             VALUES ('Legacy Person', '', :username, :hash, :role)"
        );
        $ids = [];
        foreach (['owner', 'staff'] as $role) {
            $insert->execute([
                ':username' => 'profile-' . $role . '@example.com',
                ':hash' => password_hash('profile-password', PASSWORD_DEFAULT), ':role' => $role,
            ]);
            $ids[$role] = (int)$db->lastInsertId();
        }
        $host = $this->slug . '.' . BASE_DOMAIN;
        foreach (['owner', 'staff'] as $role) {
            $this->request('GET', '/admin/logout', null, false, $host);
            self::assertSame(302, $this->request('POST', '/admin/login', [
                'email' => 'profile-' . $role . '@example.com', 'password' => 'profile-password',
            ], false, $host)['status']);
            $page = $this->request('GET', '/admin/profile', null, false, $host);
            self::assertSame(200, $page['status']);
            self::assertSame('no-store', $page['headers']['cache-control']);
            self::assertStringContainsString('id="first_name" required maxlength="100" autocomplete="given-name" value="Legacy"', $page['body']);
            self::assertStringContainsString('id="last_name" maxlength="100" autocomplete="family-name" value="Person"', $page['body']);

            $otherId = $ids[$role === 'owner' ? 'staff' : 'owner'];
            $otherBefore = $db->query("SELECT * FROM staff WHERE id = $otherId")->fetch();
            $profile = [
                'first_name' => 'Mary Jane', 'last_name' => "O'Neil", 'goes_by' => '',
                'address' => '123 Example Lane "Suite 2"', 'city' => 'Sample Harbor',
                'state' => 'ny', 'zip' => '02139-1234', 'phone' => '2025550199',
                'email' => strtoupper($role . '-contact@example.com'),
                'id' => $otherId, 'role' => 'owner',
            ];
            $saved = $this->request('POST', '/admin/api/update-profile', $profile, true, $host);
            self::assertSame(200, $saved['status'], $saved['body']);
            self::assertTrue($this->decodeJson($saved)['success']);
            $row = $db->query("SELECT * FROM staff WHERE id = {$ids[$role]}")->fetch();
            self::assertSame('Mary Jane', $row['first_name']);
            self::assertSame("O'Neil", $row['last_name']);
            self::assertSame("Mary Jane O'Neil", $row['name']);
            self::assertSame($profile['address'], $row['address']);
            self::assertSame('NY', $row['state']);
            self::assertSame('2025550199', $row['phone']);
            self::assertSame(strtolower($profile['email']), $row['email']);
            self::assertSame(strtolower($profile['email']), $row['username']);
            self::assertSame('02139-1234', $row['zip']);
            self::assertSame($role, $row['role']);
            self::assertSame($otherBefore, $db->query("SELECT * FROM staff WHERE id = $otherId")->fetch());
            $page = $this->request('GET', '/admin/profile', null, false, $host);
            self::assertStringContainsString('value="Mary Jane"', $page['body']);
            self::assertStringContainsString('value="O&#039;Neil"', $page['body']);
            self::assertStringContainsString('value="NY" selected', $page['body']);
            self::assertStringContainsString('value="' . htmlspecialchars($profile['address']) . '"', $page['body']);
            foreach (['/api/get-clients?staff_only=1', '/staff-profiles'] as $path) {
                $public = $this->request('GET', $path, null, false, $host);
                self::assertSame(200, $public['status']);
                self::assertStringNotContainsString('Sample Harbor', $public['body']);
                self::assertStringNotContainsString('02139-1234', $public['body']);
                self::assertStringNotContainsString(strtolower($profile['email']), $public['body']);
            }
            foreach ([
                ['first_name' => ''], ['first_name' => str_repeat('x', 101)],
                ['phone' => '123456789'], ['phone' => '12345678901'], ['phone' => '12345x7890'],
                ['state' => 'ZZ'], ['email' => 'not-an-email'],
                ['email' => ''], ['zip' => '1234'], ['zip' => '123456'], ['zip' => '12345-abcd'],
                ['address' => str_repeat('x', 256)], ['city' => str_repeat('x', 101)],
                ['phone' => ['invalid']],
            ] as $invalid) {
                $response = $this->request('POST', '/admin/api/update-profile', array_replace($profile, $invalid), true, $host);
                self::assertSame(400, $response['status'], $response['body']);
            }
            self::assertSame($row, $db->query("SELECT * FROM staff WHERE id = {$ids[$role]}")->fetch());
            $legacy = $this->request('POST', '/admin/api/update-profile', [
                'name' => $row['name'], 'goes_by' => 'MJ',
            ], true, $host);
            self::assertSame(200, $legacy['status'], $legacy['body']);
            $row = $db->query("SELECT * FROM staff WHERE id = {$ids[$role]}")->fetch();
            self::assertSame('Mary Jane', $row['first_name']);
            self::assertSame('Sample Harbor', $row['city']);
            $cleared = $this->request('POST', '/admin/api/update-profile', array_replace($profile, [
                'address' => '', 'city' => '', 'state' => '', 'zip' => '', 'phone' => '',
            ]), true, $host);
            self::assertSame(200, $cleared['status'], $cleared['body']);
            $row = $db->query("SELECT * FROM staff WHERE id = {$ids[$role]}")->fetch();
            foreach (['address', 'city', 'state', 'zip', 'phone'] as $field) {
                self::assertSame('', $row[$field]);
            }
        }
        $this->request('GET', '/admin/logout', null, false, $host);
        self::assertSame(302, $this->request('POST', '/admin/login', [
            'email' => 'owner-contact@example.com', 'password' => 'profile-password',
        ], false, $host)['status']);
        $managed = ['id' => $ids['staff'], 'name' => "Mary Jane O'Neil", 'email' => 'staff-contact@example.com'];
        self::assertSame(200, $this->request('POST', '/admin/api/save-employee', $managed, true, $host)['status']);
        self::assertSame('Mary Jane', $db->query("SELECT first_name FROM staff WHERE id = {$ids['staff']}")->fetchColumn());
        $managed['name'] = 'Jordan Updated';
        self::assertSame(200, $this->request('POST', '/admin/api/save-employee', $managed, true, $host)['status']);
        $row = $db->query("SELECT * FROM staff WHERE id = {$ids['staff']}")->fetch();
        self::assertSame('Jordan', $row['first_name']);
        self::assertSame('Updated', $row['last_name']);
    }

    public function testStaffProfileMigrationPreservesLegacyNamesAndExistingContactData(): void {
        $name = 'clientq_tenant_' . bin2hex(random_bytes(16));
        getDB()->exec("CREATE DATABASE `$name`");
        try {
            $db = createDatabaseConnection($name);
            $db->exec("CREATE TABLE staff (id INT NOT NULL PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(255) NOT NULL DEFAULT '')");
            $db->exec("INSERT INTO staff (id, name, email) VALUES (1, 'Mary Jane van Smith', 'existing@example.com'), (2, 'Prince', '')");
            $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-staff-profile-migration.sql');
            self::assertIsString($migration);
            $db->exec($migration);
            $row = $db->query('SELECT * FROM staff WHERE id = 1')->fetch();
            self::assertSame('Mary', $row['first_name']);
            self::assertSame('Jane van Smith', $row['last_name']);
            self::assertSame('Mary Jane van Smith', $row['name']);
            self::assertSame('existing@example.com', $row['email']);
            self::assertSame('', $db->query('SELECT last_name FROM staff WHERE id = 2')->fetchColumn());
            $db->exec("UPDATE staff SET first_name = 'Mary Jane', last_name = 'van Smith', address = 'Existing address' WHERE id = 1");
            $before = $db->query('SELECT * FROM staff WHERE id = 1')->fetch();
            $db->exec($migration);
            self::assertSame($before, $db->query('SELECT * FROM staff WHERE id = 1')->fetch());
        } finally {
            getDB()->exec("DROP DATABASE `$name`");
        }
    }

    public function testBillingIsOwnerOnlyAndRestrictedToTheCurrentTenant(): void {
        $host = $this->slug . '.' . BASE_DOMAIN;
        self::assertSame(302, $this->request('GET', '/admin/billing', null, false, $host)['status']);
        self::assertSame(401, $this->request('POST', '/admin/api/create-checkout-session', [], true, $host)['status']);
        $csrf = $this->loginTenantOwnerAndGetBillingCsrf();
        $other = $this->createAdditionalTenant($this->ownerId);
        $otherInvoice = getDB()->prepare(
            "INSERT INTO invoices (business_id, created_by, invoice_number, description, amount_cents)
             VALUES (:business, :creator, :number, 'Other tenant invoice', 1000)"
        );
        $otherInvoice->execute([
            ':business' => $other['id'], ':creator' => $this->adminId, ':number' => 'OTHER-' . $this->slug,
        ]);
        $otherInvoiceId = (int)getDB()->lastInsertId();
        $page = $this->request('GET', '/admin/billing', null, false, $host);
        self::assertSame(200, $page['status']);
        self::assertSame('no-store', $page['headers']['cache-control']);
        self::assertStringContainsString('Pay invoice', $page['body']);
        self::assertStringContainsString('href="billing"', $page['body']);
        self::assertStringNotContainsString('OTHER-' . $this->slug, $page['body']);
        self::assertSame(404, $this->request('POST', '/admin/api/create-checkout-session', [
            'csrf_token' => $csrf, 'invoice_id' => $otherInvoiceId,
        ], true, $host)['status']);
        self::assertSame(401, $this->request('POST', '/admin/api/create-checkout-session', [
            'csrf_token' => $csrf, 'invoice_id' => $otherInvoiceId,
        ], true, $other['slug'] . '.' . BASE_DOMAIN)['status']);
        self::assertSame(405, $this->request('GET', '/admin/api/create-checkout-session', null, false, $host)['status']);
        self::assertSame(403, $this->request('POST', '/admin/api/create-checkout-session', [
            'csrf_token' => ['invalid'],
        ], true, $host)['status']);
        self::assertSame(422, $this->request('POST', '/admin/api/create-checkout-session', [
            'csrf_token' => $csrf, 'invoice_id' => 0,
        ], true, $host)['status']);
        $platformLogin = $this->request('POST', '/platform-admin/login', [
            'email' => $this->ownerEmail, 'password' => 'owner-password',
        ]);
        self::assertSame(200, $platformLogin['status']);
        self::assertStringContainsString('Invalid email or password.', $platformLogin['body']);
        self::assertSame(302, $this->request('GET', '/platform-admin/dashboard')['status']);

        $insert = createDatabaseConnection($this->databaseName)->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash, role)
             VALUES ('Billing Employee', '', 'billing-employee@example.com', :hash, 'staff')"
        );
        $insert->execute([':hash' => password_hash('employee-password', PASSWORD_DEFAULT)]);
        self::assertSame(302, $this->request('GET', '/admin/logout', null, false, $host)['status']);
        self::assertSame(302, $this->request('POST', '/admin/login', [
            'username' => 'billing-employee@example.com', 'password' => 'employee-password',
        ], false, $host)['status']);
        $dashboard = $this->request('GET', '/admin/dashboard', null, false, $host);
        self::assertSame(200, $dashboard['status']);
        self::assertStringNotContainsString('href="billing"', $dashboard['body']);
        self::assertStringNotContainsString('Pay invoice', $dashboard['body']);
        self::assertSame(403, $this->request('GET', '/admin/billing', null, false, $host)['status']);
        self::assertSame(403, $this->request('POST', '/admin/api/create-checkout-session', [
            'csrf_token' => $csrf, 'invoice_id' => $otherInvoiceId,
        ], true, $host)['status']);
    }

    public function testOwnerCheckoutReturnsToStaffPortalAndVerifiesStripePayment(): void {
        $stateFile = getenv('CLIENTQ_TEST_STRIPE_STATE');
        if (!$stateFile) {
            self::markTestSkipped('Run with the local Stripe fixture server and CLIENTQ_TEST_STRIPE_STATE.');
        }
        file_put_contents($stateFile, json_encode(['sessions' => [], 'requests' => []], JSON_THROW_ON_ERROR));
        $csrf = $this->loginTenantOwnerAndGetBillingCsrf();
        $invoiceId = (int)getDB()->query("SELECT id FROM invoices WHERE business_id = {$this->businessId}")->fetchColumn();
        $host = $this->slug . '.' . BASE_DOMAIN . ':8080';
        $pay = fn(): array => $this->request('POST', '/admin/api/create-checkout-session', [
            'csrf_token' => $csrf, 'invoice_id' => $invoiceId,
        ], true, $host);
        $first = $pay();
        self::assertSame(200, $first['status'], $first['body']);
        $state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
        $parameters = $state['requests'][0]['parameters'];
        self::assertSame('http://' . $host . '/admin/billing?payment=success', $parameters['success_url']);
        self::assertSame('http://' . $host . '/admin/billing?payment=cancelled', $parameters['cancel_url']);
        self::assertSame($this->ownerEmail, $parameters['customer_email']);
        self::assertSame((string)$invoiceId, $parameters['metadata']['invoice_id']);
        self::assertSame('5900', $parameters['line_items'][0]['price_data']['unit_amount']);
        $sessionId = array_key_first($state['sessions']);
        $second = $pay();
        self::assertSame(200, $second['status'], $second['body']);
        self::assertSame($this->decodeJson($first)['checkout_url'], $this->decodeJson($second)['checkout_url']);
        $this->request('GET', '/admin/billing?payment=success', null, false, $host);
        self::assertSame('open', getDB()->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn());

        $state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
        $state['sessions'][$sessionId]['success_url'] = 'http://' . BASE_DOMAIN . '/account/dashboard?payment=success';
        file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
        $replacement = $pay();
        self::assertSame(200, $replacement['status'], $replacement['body']);
        self::assertNotSame($this->decodeJson($first)['checkout_url'], $this->decodeJson($replacement)['checkout_url']);
        $state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('expired', $state['sessions'][$sessionId]['status']);
        self::assertSame('clientq-invoice-' . $invoiceId . '-attempt-2', end($state['requests'])['idempotency_key']);
        $sessionId = array_key_last($state['sessions']);
        $state['sessions'][$sessionId]['amount_total'] = 1;
        file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
        self::assertSame(503, $pay()['status']);
        self::assertSame('open', getDB()->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn());
        $state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
        $state['sessions'][$sessionId]['amount_total'] = 5900;
        $state['sessions'][$sessionId]['status'] = 'complete';
        $state['sessions'][$sessionId]['payment_status'] = 'paid';
        $state['sessions'][$sessionId]['payment_intent'] = 'pi_test_paid';
        file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
        $paid = $pay();
        self::assertSame(200, $paid['status'], $paid['body']);
        self::assertTrue($this->decodeJson($paid)['paid']);
        self::assertSame('paid', getDB()->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn());
    }

    public function testSeparateOwnerPortalAndRecoveryAreRetired(): void {
        foreach (['/account/', '/account/login', '/account/dashboard', '/account/forgot-password', '/account/reset-password?token=invalid', '/account/logout'] as $path) {
            $page = $this->request('GET', $path);
            self::assertSame(410, $page['status'], $page['body']);
            self::assertStringContainsString('staff portal', $page['body']);
            self::assertStringNotContainsString('<form', $page['body']);
        }
        self::assertSame(410, $this->request('POST', '/account/login', [
            'email' => $this->ownerEmail, 'password' => 'owner-password',
        ])['status']);
        $legacy = $this->request('POST', '/account/api/create-checkout-session', [], true);
        self::assertSame(410, $legacy['status']);
        self::assertStringContainsString('staff portal', $this->decodeJson($legacy)['error']);
        self::assertStringNotContainsString('href="/account/login"', $this->request('GET', '/')['body']);
    }

    private function loginTenantOwnerAndGetBillingCsrf(): string {
        $insert = createDatabaseConnection($this->databaseName)->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash, role)
             VALUES ('Billing Owner', '', :email, :hash, 'owner')"
        );
        $insert->execute([':email' => $this->ownerEmail, ':hash' => password_hash('owner-password', PASSWORD_DEFAULT)]);
        $host = $this->slug . '.' . BASE_DOMAIN;
        $login = $this->request('POST', '/admin/login', [
            'username' => $this->ownerEmail, 'password' => 'owner-password',
        ], false, $host);
        self::assertSame(302, $login['status'], $login['body']);
        $page = $this->request('GET', '/admin/billing', null, false, $host);
        self::assertSame(200, $page['status'], $page['body']);
        self::assertSame(1, preg_match('/const csrfToken = "([a-f0-9]+)"/', $page['body'], $match));
        return $match[1];
    }

    public function testPasswordResetTokensEnforceExpirySingleUseAndPortalRoles(): void {
        $db = getDB();
        $unexpectedSend = static function (): array {
            self::fail('Unknown, inactive, or wrong-role accounts must not receive reset emails.');
        };
        requestPasswordReset($db, 'admin', $this->ownerEmail, 'https://example.com/reset', $unexpectedSend);
        requestPasswordReset($db, 'owner', $this->adminEmail, 'https://example.com/reset', $unexpectedSend);
        requestPasswordReset($db, 'owner', 'missing@example.com', 'https://example.com/reset', $unexpectedSend);
        $db->exec("UPDATE platform_users SET is_active = 0 WHERE id = {$this->ownerId}");
        requestPasswordReset($db, 'owner', $this->ownerEmail, 'https://example.com/reset', $unexpectedSend);
        $db->exec("UPDATE platform_users SET is_active = 1 WHERE id = {$this->ownerId}");

        $token = $this->captureResetToken($db, 'owner', $this->ownerEmail);
        $stored = $db->query("SELECT token_hash FROM password_reset_tokens WHERE account_id = {$this->ownerId}")->fetchColumn();
        self::assertSame(hash('sha256', $token), $stored);
        self::assertNotSame($token, $stored);
        self::assertNull(passwordResetAccount($db, 'admin', $token));
        self::assertFalse(completePasswordReset($db, 'admin', $token, 'new-owner-password'));

        $replacement = $this->captureResetToken($db, 'owner', $this->ownerEmail);
        self::assertNull(passwordResetAccount($db, 'owner', $token));
        self::assertSame($this->ownerId, passwordResetAccount($db, 'owner', $replacement));
        try {
            requestPasswordReset($db, 'owner', $this->ownerEmail, 'https://example.com/reset',
                static fn(): array => ['success' => false, 'error' => 'Simulated mail failure']);
            self::fail('Delivery failure must be surfaced.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Simulated mail failure', $exception->getMessage());
        }
        self::assertSame($this->ownerId, passwordResetAccount($db, 'owner', $replacement));

        $db->exec("UPDATE password_reset_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE account_id = {$this->ownerId}");
        self::assertFalse(completePasswordReset($db, 'owner', $replacement, 'new-owner-password'));
        $token = $this->captureResetToken($db, 'owner', $this->ownerEmail);
        $db->exec("UPDATE platform_users SET password_version = password_version + 1 WHERE id = {$this->ownerId}");
        self::assertNull(passwordResetAccount($db, 'owner', $token));
        $token = $this->captureResetToken($db, 'owner', $this->ownerEmail);
        self::assertTrue(completePasswordReset($db, 'owner', $token, 'new-owner-password'));
        self::assertFalse(completePasswordReset($db, 'owner', $token, 'another-password'));
        $account = $db->query("SELECT * FROM platform_users WHERE id = {$this->ownerId}")->fetch();
        self::assertTrue(password_verify('new-owner-password', $account['password_hash']));
        self::assertSame(2, (int)$account['password_version']);
        self::assertSame('business_owner', $account['role']);
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM password_reset_tokens WHERE account_id = {$this->ownerId}")->fetchColumn());
    }

    public function testForgotPasswordFormsRejectUnverifiedRequestsAndReportMissingMail(): void {
        $host = $this->slug . '.' . BASE_DOMAIN;
        foreach ([
            ['/admin', $host], ['/platform-admin', BASE_DOMAIN],
        ] as [$prefix, $requestHost]) {
            $login = $this->request('GET', $prefix . '/login', null, false, $requestHost);
            self::assertSame(200, $login['status']);
            self::assertStringContainsString('href="forgot-password"', $login['body']);
            $form = $this->request('GET', $prefix . '/forgot-password', null, false, $requestHost);
            self::assertSame(200, $form['status'], $form['body']);
            self::assertSame('no-store', $form['headers']['cache-control']);
            self::assertSame('no-referrer', $form['headers']['referrer-policy']);
            $rejected = $this->request('POST', $prefix . '/forgot-password', ['email' => $this->ownerEmail], false, $requestHost);
            self::assertSame(403, $rejected['status']);
            $invalidToken = $this->request('GET', $prefix . '/reset-password?token=bad', null, false, $requestHost);
            self::assertSame(400, $invalidToken['status']);
            self::assertStringContainsString('invalid or expired', $invalidToken['body']);
        }
        $form = $this->request('GET', '/admin/forgot-password', null, false, $host);
        self::assertSame(1, preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form['body'], $match));
        $invalid = $this->request('POST', '/admin/forgot-password', [
            'csrf_token' => $match[1], 'email' => 'username-without-email',
        ], false, $host);
        self::assertSame(422, $invalid['status']);
        if (!(getenv('CLIENTQ_SMTP_HOST') ?: '')) {
            foreach ([$this->ownerEmail, 'missing@example.com'] as $email) {
                $response = $this->request('POST', '/admin/forgot-password', [
                    'csrf_token' => $match[1], 'email' => $email,
                ], false, $host);
                self::assertSame(503, $response['status'], $response['body']);
                self::assertStringContainsString('email is temporarily unavailable', $response['body']);
            }
        }
        self::assertSame(405, $this->request('PUT', '/admin/forgot-password', null, false, $host)['status']);
        self::assertSame(404, $this->request('GET', '/password-reset')['status']);
        self::assertSame(404, $this->request('GET', '/admin/forgot-password')['status']);
        self::assertSame(404, $this->request('GET', '/platform-admin/forgot-password', null, false, $host)['status']);
    }

    public function testPasswordRecoveryRevokesSessionsAndPreservesTenantAndAdminIsolation(): void {
        $db = createDatabaseConnection($this->databaseName);
        $staff = $db->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash, role)
             VALUES ('Recovery Owner', '', :email, :hash, 'owner')"
        );
        $staff->execute([':email' => $this->ownerEmail, ':hash' => password_hash('old-staff-password', PASSWORD_DEFAULT)]);
        $host = $this->slug . '.' . BASE_DOMAIN;
        $login = $this->request('POST', '/admin/login', [
            'username' => $this->ownerEmail, 'password' => 'old-staff-password',
        ], false, $host);
        self::assertSame(302, $login['status']);
        self::assertSame(200, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);

        $other = $this->createAdditionalTenant($this->ownerId);
        $otherDb = createDatabaseConnection($other['database_name']);
        $otherStaff = $otherDb->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash, role)
             VALUES ('Other Recovery Owner', '', :email, :hash, 'owner')"
        );
        $otherStaff->execute([':email' => $this->ownerEmail, ':hash' => password_hash('other-password', PASSWORD_DEFAULT)]);
        $db->exec('UPDATE staff SET must_change_password = 1');
        $token = $this->captureResetToken($db, 'tenant', $this->ownerEmail);
        $path = '/admin/reset-password?token=' . $token;
        self::assertSame(400, $this->request('GET', $path, null, false, $other['slug'] . '.' . BASE_DOMAIN)['status']);
        $form = $this->request('GET', $path, null, false, $host);
        self::assertSame(200, $form['status'], $form['body']);
        self::assertSame(1, preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form['body'], $match));
        self::assertSame('no-referrer', $form['headers']['referrer-policy']);
        self::assertStringNotContainsString('<script', $form['body']);
        $body = ['csrf_token' => $match[1], 'password' => 'new-staff-password', 'password_confirmation' => 'mismatch'];
        self::assertSame(403, $this->request('POST', $path, [], false, $host)['status']);
        self::assertSame(422, $this->request('POST', $path, $body, false, $host)['status']);
        foreach (['short', str_repeat('x', 256), "invalid\0password"] as $invalidPassword) {
            self::assertSame(422, $this->request('POST', $path, [
                'csrf_token' => $match[1], 'password' => $invalidPassword, 'password_confirmation' => $invalidPassword,
            ], false, $host)['status']);
        }
        $body['password_confirmation'] = $body['password'];
        $reset = $this->request('POST', $path, $body, false, $host);
        self::assertSame(303, $reset['status'], $reset['body']);
        self::assertSame('/admin/login?password_reset=1', $reset['headers']['location']);
        self::assertSame(0, (int)$db->query('SELECT must_change_password FROM staff LIMIT 1')->fetchColumn());
        self::assertSame(400, $this->request('GET', $path, null, false, $host)['status']);
        self::assertSame(302, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);
        self::assertSame(200, $this->request('GET', '/admin/login', null, false, $host)['status']);
        self::assertSame(302, $this->request('POST', '/admin/login', [
            'username' => $this->ownerEmail, 'password' => 'new-staff-password',
        ], false, $host)['status']);
        self::assertSame(200, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);
        self::assertSame(0, (int)$otherDb->query('SELECT password_version FROM staff LIMIT 1')->fetchColumn());

        $staleToken = $this->captureResetToken($db, 'tenant', $this->ownerEmail);
        $change = $this->request('POST', '/admin/api/change-password', [
            'current_password' => 'new-staff-password', 'new_password' => 'changed-staff-password',
        ], true, $host);
        self::assertSame(200, $change['status'], $change['body']);
        self::assertSame(200, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);
        self::assertNull(passwordResetAccount($db, 'tenant', $staleToken));

        $employee = $db->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash, role)
             VALUES ('Recovery Employee', '', 'recovery-employee@example.com', :hash, 'staff')"
        );
        $employee->execute([':hash' => password_hash('old-employee-password', PASSWORD_DEFAULT)]);
        $employeeId = (int)$db->lastInsertId();
        $ownerCookies = $this->cookieJar;
        $employeeCookies = tempnam(sys_get_temp_dir(), 'clientq-recovery-cookie-');
        try {
            $this->cookieJar = $employeeCookies;
            self::assertSame(302, $this->request('POST', '/admin/login', [
                'username' => 'recovery-employee@example.com', 'password' => 'old-employee-password',
            ], false, $host)['status']);
            self::assertSame(200, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);
            $this->cookieJar = $ownerCookies;
            $recovered = $this->request('POST', '/admin/api/save-employee', [
                'id' => $employeeId, 'name' => 'Recovery Employee', 'email' => 'recovery-employee@example.com',
                'password' => 'recovered-employee-password',
            ], true, $host);
            self::assertSame(200, $recovered['status'], $recovered['body']);
            $this->cookieJar = $employeeCookies;
            self::assertSame(302, $this->request('GET', '/admin/dashboard', null, false, $host)['status']);
            self::assertSame(302, $this->request('POST', '/admin/login', [
                'username' => 'recovery-employee@example.com', 'password' => 'recovered-employee-password',
            ], false, $host)['status']);
        } finally {
            $this->cookieJar = $ownerCookies;
            unlink($employeeCookies);
        }

        self::assertSame(302, $this->request('POST', '/platform-admin/login', [
            'email' => $this->adminEmail, 'password' => $this->adminPassword,
        ])['status']);
        $token = $this->captureResetToken(getDB(), 'admin', $this->adminEmail);
        self::assertSame(400, $this->request('GET', '/admin/reset-password?token=' . $token, null, false, $host)['status']);
        $path = '/platform-admin/reset-password?token=' . $token;
        $form = $this->request('GET', $path);
        self::assertSame(200, $form['status'], $form['body']);
        self::assertSame(1, preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form['body'], $match));
        $reset = $this->request('POST', $path, [
            'csrf_token' => $match[1], 'password' => 'new-central-password', 'password_confirmation' => 'new-central-password',
        ]);
        self::assertSame(303, $reset['status'], $reset['body']);
        self::assertSame(302, $this->request('GET', '/platform-admin/dashboard')['status']);
        self::assertSame(302, $this->request('POST', '/platform-admin/login', [
            'email' => $this->adminEmail, 'password' => 'new-central-password',
        ])['status']);
        self::assertSame(200, $this->request('GET', '/platform-admin/dashboard')['status']);
    }

    public function testPasswordResetMigrationSupportsLegacySignedStaffIds(): void {
        $name = 'clientq_tenant_' . bin2hex(random_bytes(16));
        getDB()->exec("CREATE DATABASE `$name`");
        try {
            $db = createDatabaseConnection($name);
            $db->exec(
                "CREATE TABLE staff (id INT NOT NULL PRIMARY KEY, password_hash VARCHAR(255) NOT NULL) ENGINE=InnoDB"
            );
            $db->exec("INSERT INTO staff (id, password_hash) VALUES (1, 'existing-hash')");
            $migration = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-password-reset-migration.sql');
            self::assertIsString($migration);
            for ($run = 0; $run < 2; $run++) {
                $db->exec($migration);
            }
            $account = $db->query('SELECT * FROM staff WHERE id = 1')->fetch();
            self::assertSame('existing-hash', $account['password_hash']);
            self::assertSame(0, (int)$account['password_version']);
            self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        } finally {
            getDB()->exec("DROP DATABASE `$name`");
        }
    }

    public function testPasswordResetRequestLimitResetsAfterOneHour(): void {
        $ip = 'reset-limit-' . $this->slug;
        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                self::assertFalse(passwordResetIsRateLimited(getDB(), $ip));
            }
            self::assertTrue(passwordResetIsRateLimited(getDB(), $ip));
            $expire = getDB()->prepare(
                'UPDATE password_reset_rate_limits SET window_started_at = NOW() - INTERVAL 1 HOUR WHERE ip_hash = :hash'
            );
            $expire->execute([':hash' => hash('sha256', $ip)]);
            self::assertFalse(passwordResetIsRateLimited(getDB(), $ip));
        } finally {
            $delete = getDB()->prepare('DELETE FROM password_reset_rate_limits WHERE ip_hash = :hash');
            $delete->execute([':hash' => hash('sha256', $ip)]);
        }
    }

    private function captureResetToken(PDO $db, string $portal, string $email): string {
        $token = '';
        requestPasswordReset($db, $portal, $email, 'https://example.com/reset',
            static function (string $recipient, string $subject, string $body) use ($email, &$token): array {
                self::assertSame($email, $recipient);
                self::assertStringContainsString('Reset', $subject);
                self::assertSame(1, preg_match('/\?token=([a-f0-9]{64})/', $body, $match));
                $token = $match[1];
                return ['success' => true];
            });
        self::assertNotSame('', $token);
        return $token;
    }

    public function testOptionalNicknamesFallBackToFirstNamesForOwnersAndEmployees(): void {
        $db = createDatabaseConnection($this->databaseName);
        $insert = $db->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash, role)
             VALUES ('Alex Owner', '', 'nickname-owner@example.com', :hash, 'owner')"
        );
        $insert->execute([':hash' => password_hash('nickname-owner-password', PASSWORD_DEFAULT)]);
        $ownerId = (int)$db->lastInsertId();
        $host = $this->slug . '.' . BASE_DOMAIN;
        $login = $this->request('POST', '/admin/login', [
            'username' => 'nickname-owner@example.com', 'password' => 'nickname-owner-password',
        ], false, $host);
        self::assertSame(302, $login['status'], $login['body']);
        $form = $this->request('GET', '/admin/staff', null, false, $host);
        self::assertSame(200, $form['status'], $form['body']);
        self::assertStringContainsString('Nickname (optional)', $form['body']);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*id="goesBy"[^>]*\brequired\b/', $form['body']);

        $employee = [
            'name' => 'Morgan Employee', 'username' => 'nickname-employee@example.com', 'email' => 'nickname-employee@example.com',
            'password' => 'nickname-employee-password',
        ];
        $create = $db->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash) VALUES ('Morgan Employee', '', 'nickname-employee@example.com', :hash)"
        );
        $create->execute([':hash' => password_hash($employee['password'], PASSWORD_DEFAULT)]);
        $staffId = (int)$db->query("SELECT id FROM staff WHERE username = 'nickname-employee@example.com'")->fetchColumn();
        $employee['id'] = $staffId;
        $employee['password'] = '';
        $client = $db->prepare(
            "INSERT INTO clients (name, phone, staff_id, confirmation_code)
             VALUES ('Nickname Test Client', '2025550101', :staff, 'NICK01')"
        );
        $client->execute([':staff' => $staffId]);

        foreach (['' => 'Morgan', '  Mo & Co  ' => 'Mo & Co', '   ' => 'Morgan'] as $nickname => $expected) {
            $employee['goes_by'] = $nickname;
            $save = $this->request('POST', '/admin/api/save-employee', $employee, true, $host);
            self::assertSame(200, $save['status'], $save['body']);
            $page = $this->request('GET', '/', null, false, $host);
            self::assertSame(200, $page['status'], $page['body']);
            self::assertStringContainsString(
                '<option value="' . $staffId . '">' . htmlspecialchars($expected) . '</option>',
                $page['body']
            );
            self::assertStringContainsString('<option value="' . $ownerId . '">Alex</option>', $page['body']);
            foreach (['/api/get-clients', '/api/get-clients?staff_only=1'] as $endpoint) {
                $list = $this->request('GET', $endpoint, null, false, $host);
                self::assertSame(200, $list['status'], $list['body']);
                $data = $this->decodeJson($list);
                self::assertSame($expected, array_column($data['staff'], 'goes_by', 'id')[$staffId]);
                if (isset($data['clients'])) {
                    self::assertSame($expected, $data['clients'][0]['staff_name']);
                }
            }
        }
        $employee['goes_by'] = str_repeat('x', 51);
        self::assertSame(422, $this->request('POST', '/admin/api/save-employee', $employee, true, $host)['status']);

        foreach ([
            ['username' => 'nickname-owner@example.com', 'password' => 'nickname-owner-password', 'name' => 'Alex Owner'],
            ['username' => 'nickname-employee@example.com', 'password' => 'nickname-employee-password', 'name' => 'Morgan Employee'],
        ] as $person) {
            $this->request('GET', '/admin/logout', null, false, $host);
            $login = $this->request('POST', '/admin/login', $person, false, $host);
            self::assertSame(302, $login['status'], $login['body']);
            foreach (['  Nickname  ', '   '] as $nickname) {
                $profile = $this->request('POST', '/admin/api/update-profile', [
                    'name' => $person['name'], 'goes_by' => $nickname,
                ], true, $host);
                self::assertSame(200, $profile['status'], $profile['body']);
                $stored = $db->prepare('SELECT name, goes_by FROM staff WHERE username = :username');
                $stored->execute([':username' => $person['username']]);
                $row = $stored->fetch();
                self::assertSame(trim($nickname), $row['goes_by']);
                $page = $this->request('GET', '/admin/profile', null, false, $host);
                self::assertSame(200, $page['status'], $page['body']);
                self::assertStringContainsString(
                    '<p class="font-semibold">' . htmlspecialchars(staffDisplayName($row)) . '</p>',
                    $page['body']
                );
            }
            $invalid = $this->request('POST', '/admin/api/update-profile', [
                'name' => $person['name'], 'goes_by' => str_repeat('x', 51),
            ], true, $host);
            self::assertSame(400, $invalid['status']);
        }
    }

    public function testStaffConfirmationPermanentlyDeletesOnlyTheirOwnClient(): void {
        $db = createDatabaseConnection($this->databaseName);
        $staff = $db->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash)
             VALUES ('Deletion Staff', 'Deletion Staff', :username, :hash)"
        );
        $staff->execute([
            ':username' => 'delete-staff@example.com',
            ':hash' => password_hash('deletion-staff-password', PASSWORD_DEFAULT),
        ]);
        $staffId = (int)$db->lastInsertId();
        $staff->execute([
            ':username' => 'other-staff@example.com',
            ':hash' => password_hash('other-staff-password', PASSWORD_DEFAULT),
        ]);
        $otherStaffId = (int)$db->lastInsertId();
        $client = $db->prepare(
            "INSERT INTO clients (name, phone, staff_id, confirmation_code)
             VALUES ('Deletion Client', '2025550101', :staff, :code)"
        );
        $client->execute([':staff' => $staffId, ':code' => 'DEL001']);
        $clientId = (int)$db->lastInsertId();
        $client->execute([':staff' => $otherStaffId, ':code' => 'KEEP01']);
        $otherClientId = (int)$db->lastInsertId();
        $challenge = $db->prepare(
            'INSERT INTO waitlist_deletion_challenges (client_id, code_hash, expires_at)
             VALUES (:id, :hash, CURRENT_TIMESTAMP + INTERVAL 10 MINUTE)'
        );
        $challenge->execute([':id' => $clientId, ':hash' => password_hash('123456', PASSWORD_DEFAULT)]);
        $host = $this->slug . '.' . BASE_DOMAIN;

        $unauthenticated = $this->request('POST', '/admin/api/delete-client', [
            'client_id' => $clientId,
        ], true, $host);
        self::assertSame(401, $unauthenticated['status']);
        $unauthenticatedList = $this->request(
            'GET', '/admin/api/get-clients?clear_cache=1', null, false, $host
        );
        self::assertSame(401, $unauthenticatedList['status']);
        $this->assertClientListNotCached($unauthenticatedList, true);
        $login = $this->request('POST', '/admin/login', [
            'username' => 'delete-staff@example.com', 'password' => 'deletion-staff-password',
        ], false, $host);
        self::assertSame(302, $login['status'], $login['body']);
        $dashboard = $this->request('GET', '/admin/dashboard', null, false, $host);
        self::assertSame(200, $dashboard['status'], $dashboard['body']);
        self::assertStringContainsString('This cannot be undone.', $dashboard['body']);
        self::assertSame(2, (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        foreach (['/api/get-clients', '/admin/api/get-clients'] as $endpoint) {
            $list = $this->request('GET', $endpoint . '?clear_cache=1', null, false, $host);
            self::assertSame(200, $list['status'], $list['body']);
            $this->assertClientListNotCached($list, true);
            self::assertNotEmpty($this->decodeJson($list)['clients']);
            $refresh = $this->request('GET', $endpoint . '?clear_cache=0', null, false, $host);
            self::assertSame(200, $refresh['status'], $refresh['body']);
            $this->assertClientListNotCached($refresh, false);
        }

        $forbidden = $this->request('POST', '/admin/api/delete-client', [
            'client_id' => $otherClientId,
        ], true, $host);
        self::assertSame(403, $forbidden['status']);
        $deleted = $this->request('POST', '/admin/api/delete-client', [
            'client_id' => $clientId,
        ], true, $host);
        self::assertSame(200, $deleted['status'], $deleted['body']);
        self::assertTrue($this->decodeJson($deleted)['success']);
        self::assertSame(
            [$otherClientId],
            array_map('intval', $db->query('SELECT id FROM clients')->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM waitlist_deletion_challenges')->fetchColumn());
        $repeated = $this->request('POST', '/admin/api/delete-client', [
            'client_id' => $clientId,
        ], true, $host);
        self::assertSame(404, $repeated['status']);
    }

    #[ConsoleTest(
        'Delete a tenant through the authenticated platform-admin endpoint.',
        'The tenant data and last owner are removed, while branded unavailable-page metadata and deletion attribution remain.'
    )]
    public function testPlatformAdminCanPermanentlyDeleteTenant(): void {
        $this->setFixtureLogo();
        $note = getDB()->prepare(
            'INSERT INTO business_owner_notes (owner_user_id, created_by, note_text) VALUES (:owner, :admin, :note)'
        );
        $note->execute([':owner' => $this->ownerId, ':admin' => $this->adminId, ':note' => 'Delete this profile note.']);
        $login = $this->request(
            'POST',
            '/platform-admin/login',
            ['email' => $this->adminEmail, 'password' => $this->adminPassword]
        );
        self::assertSame(302, $login['status'], $login['body']);

        $dashboard = $this->request('GET', '/platform-admin/dashboard');
        self::assertSame(200, $dashboard['status']);
        self::assertStringContainsString($this->businessName, $dashboard['body']);
        self::assertSame(
            1,
            preg_match(
                '/name="csrf_token" value="([a-f0-9]+)"/',
                $dashboard['body'],
                $csrfMatch
            )
        );

        $delete = $this->request(
            'POST',
            '/platform-admin/api/delete-business',
            [
                'csrf_token' => $csrfMatch[1],
                'business_id' => $this->businessId,
                'confirmation' => $this->businessName,
            ],
            true
        );
        self::assertSame(200, $delete['status'], $delete['body']);
        $deleteData = $this->decodeJson($delete);
        self::assertTrue($deleteData['success']);

        $db = getDB();
        self::assertSame(
            0,
            $this->countRows($db, 'businesses', 'id', $this->businessId)
        );
        self::assertSame(
            0,
            $this->countRows($db, 'business_owners', 'business_id', $this->businessId)
        );
        self::assertSame(
            0,
            $this->countRows($db, 'invoices', 'business_id', $this->businessId)
        );
        self::assertSame(
            0,
            $this->countRows($db, 'platform_users', 'id', $this->ownerId),
            'Deleting the last tenant must remove its owner account.'
        );
        self::assertSame(0, $this->countRows($db, 'business_owner_notes', 'owner_user_id', $this->ownerId));

        $databaseStmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME = :database_name'
        );
        $databaseStmt->execute([':database_name' => $this->databaseName]);
        self::assertSame(0, (int)$databaseStmt->fetchColumn());
        self::assertDirectoryDoesNotExist($this->uploadDirectory);

        $tenantPage = $this->request(
            'GET',
            '/sign-in',
            null,
            false,
            $this->slug . '.' . BASE_DOMAIN
        );
        self::assertSame(410, $tenantPage['status']);
        self::assertStringContainsString('Waitlist is no longer available.', $tenantPage['body']);
        self::assertStringContainsString($this->businessName, $tenantPage['body']);
        self::assertStringContainsString($this->statusLogoPath, $tenantPage['body']);
        self::assertStringNotContainsString('qrcode', $tenantPage['body']);
        self::assertStringNotContainsString('role="tab"', $tenantPage['body']);
        $logo = $this->request('GET', '/' . $this->statusLogoPath, null, false, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(200, $logo['status']);
        $audit = $db->prepare('SELECT * FROM tenant_status_pages WHERE slug = :slug');
        $audit->execute([':slug' => $this->slug]);
        $record = $audit->fetch();
        self::assertSame('deleted', $record['status']);
        self::assertSame($this->adminId, (int)$record['changed_by_id']);
        self::assertSame('Deletion Test Admin', $record['changed_by_name']);
        self::assertStringContainsString('deleted by Deletion Test Admin', $record['note']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC:/', $record['note']);
        $logDirectory = getenv('CLIENTQ_LOG_DIR') ?: dirname(__DIR__, 2) . '/logs';
        self::assertStringContainsString(
            'ClientQ tenant deleted: ' . json_encode($record['note'], JSON_UNESCAPED_SLASHES),
            file_get_contents($logDirectory . '/clientq-' . date('Y-m-d') . '.log')
        );
        $profile = $this->request('GET', '/platform-admin/owner-profile?owner_id=' . $this->ownerId);
        self::assertSame(404, $profile['status']);
        $reuse = $this->request('POST', '/api/prepare-business-registration', [
            'csrf_token' => $csrfMatch[1], 'business_name' => 'Cannot Reuse Deleted Address',
            'slug' => $this->slug, 'first_name' => 'Test', 'last_name' => 'Owner',
            'email' => $this->ownerEmail, 'password' => 'fixture-password',
            'phone' => '2025550198', 'plan' => 'starter',
        ], true);
        self::assertSame(409, $reuse['status'], $reuse['body']);
    }

    public function testDeleteOwnerRemovesAllTheirTenantsAndNotesButPreservesOthers(): void {
        $secondTenant = $this->createAdditionalTenant($this->ownerId);
        $otherOwnerId = $this->createAdditionalOwner();
        $otherTenant = $this->createAdditionalTenant($otherOwnerId);
        $note = getDB()->prepare(
            'INSERT INTO business_owner_notes (owner_user_id, created_by, note_text)
             VALUES (:owner_id, :admin_id, :note)'
        );
        $note->execute([
            ':owner_id' => $this->ownerId,
            ':admin_id' => $this->adminId,
            ':note' => 'Delete this owner note.',
        ]);

        $csrf = $this->loginAdminAndGetCsrf();
        $profile = $this->request('GET', '/platform-admin/owner-profile?owner_id=' . $this->ownerId);
        self::assertStringContainsString('Tenant accounts', $profile['body']);
        self::assertStringContainsString($secondTenant['slug'], $profile['body']);
        $delete = $this->deleteOwner($csrf);
        self::assertSame(200, $delete['status'], $delete['body']);
        self::assertTrue($this->decodeJson($delete)['success']);

        $db = getDB();
        self::assertSame(0, $this->countRows($db, 'platform_users', 'id', $this->ownerId));
        self::assertSame(0, $this->countRows($db, 'business_owner_notes', 'owner_user_id', $this->ownerId));
        self::assertSame(0, $this->countRows($db, 'invoices', 'business_id', $this->businessId));
        foreach ([
            ['id' => $this->businessId, 'slug' => $this->slug, 'database_name' => $this->databaseName],
            $secondTenant,
        ] as $tenant) {
            self::assertSame(0, $this->countRows($db, 'businesses', 'id', $tenant['id']));
            self::assertSame(0, $this->countRows($db, 'business_owners', 'business_id', $tenant['id']));
            $this->assertTenantDatabaseMissing($tenant['database_name']);
            self::assertDirectoryDoesNotExist(dirname(__DIR__, 2) . '/uploads/' . $tenant['slug']);
            foreach (['/', '/galley', '/admin/login'] as $path) {
                $page = $this->request('GET', $path, null, false, $tenant['slug'] . '.' . BASE_DOMAIN);
                self::assertSame(410, $page['status'], $page['body']);
            }
        }
        self::assertSame(1, $this->countRows($db, 'platform_users', 'id', $this->adminId));
        self::assertSame(1, $this->countRows($db, 'platform_users', 'id', $otherOwnerId));
        self::assertSame(1, $this->countRows($db, 'businesses', 'id', $otherTenant['id']));
        $otherPage = $this->request('GET', '/', null, false, $otherTenant['slug'] . '.' . BASE_DOMAIN);
        self::assertSame(200, $otherPage['status'], $otherPage['body']);
        $removedProfile = $this->request('GET', '/platform-admin/owner-profile?owner_id=' . $this->ownerId);
        self::assertSame(404, $removedProfile['status']);
    }

    public function testDeleteOwnerBlocksUnexpectedSharedTenantWithoutChangingData(): void {
        $otherOwnerId = $this->createAdditionalOwner();
        $link = getDB()->prepare(
            'INSERT INTO business_owners (business_id, user_id) VALUES (:business_id, :owner_id)'
        );
        $link->execute([':business_id' => $this->businessId, ':owner_id' => $otherOwnerId]);
        $delete = $this->deleteOwner($this->loginAdminAndGetCsrf());
        self::assertSame(409, $delete['status'], $delete['body']);
        self::assertStringContainsString('another owner', $this->decodeJson($delete)['error']);
        $active = getDB()->prepare('SELECT is_active FROM businesses WHERE id = :id');
        $active->execute([':id' => $this->businessId]);
        self::assertSame(1, (int)$active->fetchColumn());
        self::assertSame(1, $this->countRows(getDB(), 'platform_users', 'id', $this->ownerId));
        self::assertFileExists($this->uploadDirectory . '/logo-test.png');
        $page = $this->request('GET', '/', null, false, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(200, $page['status'], $page['body']);
    }

    public function testDeletingOneTenantPreservesOwnerOtherTenants(): void {
        $otherTenant = $this->createAdditionalTenant($this->ownerId);
        $csrf = $this->loginAdminAndGetCsrf();
        $deleteTenant = $this->request('POST', '/platform-admin/api/delete-business', [
            'csrf_token' => $csrf,
            'business_id' => $this->businessId,
            'confirmation' => $this->businessName,
        ], true);
        self::assertSame(200, $deleteTenant['status'], $deleteTenant['body']);
        $dashboard = $this->request('GET', '/platform-admin/dashboard');
        self::assertStringContainsString($this->ownerEmail, $dashboard['body']);
        self::assertStringContainsString($otherTenant['slug'], $dashboard['body']);
        self::assertStringNotContainsString($this->businessName, $dashboard['body']);
        self::assertSame(1, $this->countRows(getDB(), 'platform_users', 'id', $this->ownerId));
        self::assertStringContainsString('<dialog id="deleteConfirmation"', $dashboard['body']);
        $delete = $this->request('POST', '/platform-admin/api/update-tenant-status', [
            'csrf_token' => $csrf, 'business_id' => $otherTenant['id'],
            'action' => 'delete', 'confirmation' => 'Additional Delete Tenant',
        ], true);
        self::assertSame(200, $delete['status'], $delete['body']);
        self::assertSame(0, $this->countRows(getDB(), 'platform_users', 'id', $this->ownerId));
    }

    public function testOwnerDeletionRequiresPlatformAdminCsrfAndExactEmail(): void {
        $unauthenticated = $this->deleteOwner('invalid');
        self::assertSame(401, $unauthenticated['status']);
        $this->loginTenantOwnerAndGetBillingCsrf();
        self::assertSame(401, $this->deleteOwner('invalid')['status']);
        $csrf = $this->loginAdminAndGetCsrf();
        self::assertSame(405, $this->request('GET', '/platform-admin/api/delete-owner')['status']);
        self::assertSame(403, $this->deleteOwner('invalid')['status']);
        $wrongEmail = $this->request('POST', '/platform-admin/api/delete-owner', [
            'csrf_token' => $csrf, 'owner_id' => $this->ownerId, 'confirmation' => 'wrong@example.com',
        ], true);
        self::assertSame(422, $wrongEmail['status'], $wrongEmail['body']);
        $adminTarget = $this->request('POST', '/platform-admin/api/delete-owner', [
            'csrf_token' => $csrf, 'owner_id' => $this->adminId, 'confirmation' => $this->adminEmail,
        ], true);
        self::assertSame(404, $adminTarget['status'], $adminTarget['body']);
        $tenantHost = $this->request('POST', '/platform-admin/api/delete-owner', [
            'csrf_token' => $csrf, 'owner_id' => $this->ownerId, 'confirmation' => $this->ownerEmail,
        ], true, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(404, $tenantHost['status']);
        self::assertSame(1, $this->countRows(getDB(), 'businesses', 'id', $this->businessId));
        self::assertSame(1, $this->countRows(getDB(), 'platform_users', 'id', $this->ownerId));
    }

    public function testTenantStatusDropdownsUseConfirmationModal(): void {
        $csrf = $this->loginAdminAndGetCsrf();
        foreach ([
            '/platform-admin/dashboard',
            '/platform-admin/owner-profile?owner_id=' . $this->ownerId,
        ] as $path) {
            $page = $this->request('GET', $path);
            self::assertSame(200, $page['status'], $page['body']);
            $document = new DOMDocument();
            $document->loadHTML($page['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($document);
            if (str_starts_with($path, '/platform-admin/owner-profile')) {
                foreach (['Waitlist' => '/', 'Staff portal' => '/admin/login'] as $label => $tenantPath) {
                    $links = $xpath->query('//a[normalize-space(.)="' . $label . '"]');
                    self::assertNotFalse($links);
                    self::assertCount(1, $links);
                    self::assertSame(
                        tenantUrl($this->slug, $tenantPath),
                        $links->item(0)->attributes->getNamedItem('href')->nodeValue
                    );
                    self::assertSame('_blank', $links->item(0)->attributes->getNamedItem('target')->nodeValue);
                    self::assertSame('noopener noreferrer', $links->item(0)->attributes->getNamedItem('rel')->nodeValue);
                }
            }
            $controls = $xpath->query('//select[@data-business-id]');
            self::assertNotFalse($controls);
            self::assertCount(1, $controls);
            $control = $controls->item(0);
            self::assertInstanceOf(DOMElement::class, $control);
            self::assertSame((string)$this->businessId, $control->getAttribute('data-business-id'));
            self::assertSame('active', $control->getAttribute('data-current-status'));
            self::assertCount(3, $control->getElementsByTagName('option'));
            self::assertNotSame('', $control->getAttribute('aria-label'));
            self::assertStringNotContainsString('delete-record', $page['body']);
            $dialogs = $xpath->query('//dialog[@id="deleteConfirmation"]');
            self::assertNotFalse($dialogs);
            self::assertCount(1, $dialogs);
            self::assertSame($csrf, $dialogs->item(0)->attributes->getNamedItem('data-csrf-token')->nodeValue);
            self::assertStringNotContainsString('window.prompt(', $page['body']);
            self::assertStringNotContainsString('window.confirm(', $page['body']);
        }
    }

    public function testPartialDeletionDisablesAccessAndCanBeRetried(): void {
        $csrf = $this->loginAdminAndGetCsrf();
        chmod($this->uploadDirectory, 0555);
        try {
            $delete = $this->deleteOwner($csrf);
            self::assertSame(500, $delete['status'], $delete['body']);
            self::assertStringContainsString('Retry Delete Owner', $this->decodeJson($delete)['error']);
            $this->assertTenantDatabaseMissing($this->databaseName);
            $active = getDB()->prepare('SELECT is_active FROM platform_users WHERE id = :id');
            $active->execute([':id' => $this->ownerId]);
            self::assertSame(0, (int)$active->fetchColumn());
            $page = $this->request('GET', '/', null, false, $this->slug . '.' . BASE_DOMAIN);
            self::assertSame(410, $page['status']);
            $reactivate = $this->changeTenantStatus($csrf, 'active');
            self::assertSame(409, $reactivate['status'], $reactivate['body']);
        } finally {
            chmod($this->uploadDirectory, 0777);
        }
        $retry = $this->deleteOwner($csrf);
        self::assertSame(200, $retry['status'], $retry['body']);
        self::assertSame(0, $this->countRows(getDB(), 'platform_users', 'id', $this->ownerId));
        self::assertSame(0, $this->countRows(getDB(), 'businesses', 'id', $this->businessId));
        self::assertDirectoryDoesNotExist($this->uploadDirectory);
    }

    public function testInactiveTenantKeepsDataAndCanBeReactivated(): void {
        $this->setFixtureLogo();
        $tenantDb = createDatabaseConnection($this->databaseName);
        $staff = $tenantDb->prepare(
            "INSERT INTO staff (name, goes_by, username, password_hash)
             VALUES ('Kept Staff', 'Kept', 'kept@example.com', :hash)"
        );
        $staff->execute([':hash' => password_hash('kept-staff-password', PASSWORD_DEFAULT)]);
        $staffId = (int)$tenantDb->lastInsertId();
        $client = $tenantDb->prepare(
            "INSERT INTO clients (name, phone, staff_id, confirmation_code) VALUES ('Kept Client', '2025550198', :staff, 'KEEP01')"
        );
        $client->execute([':staff' => $staffId]);
        $host = $this->slug . '.' . BASE_DOMAIN;
        $login = $this->request('POST', '/admin/login', [
            'username' => 'kept@example.com', 'password' => 'kept-staff-password',
        ], false, $host);
        self::assertSame(302, $login['status'], $login['body']);
        $portal = $this->request('GET', '/admin/dashboard', null, false, $host);
        self::assertSame(200, $portal['status'], $portal['body']);
        $status = $this->request('GET', '/api/get-clients?tenant_status=1', null, false, $host);
        self::assertSame(200, $status['status']);
        self::assertSame(['tenant_status' => 'active'], $this->decodeJson($status));
        $csrf = $this->loginAdminAndGetCsrf();
        $inactive = $this->changeTenantStatus($csrf, 'inactive');
        self::assertSame(200, $inactive['status'], $inactive['body']);
        self::assertSame(1, (int)$tenantDb->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(1, $this->countRows(getDB(), 'platform_users', 'id', $this->ownerId));
        foreach (['/', '/galley', '/sign-in', '/admin/login', '/admin/dashboard', '/admin/profile', '/admin/staff', '/admin/business'] as $path) {
            $page = $this->request('GET', $path, null, false, $this->slug . '.' . BASE_DOMAIN);
            self::assertSame(503, $page['status'], $page['body']);
            $pageName = str_starts_with($path, '/admin/') ? 'Staff portal' : 'Waitlist';
            self::assertStringContainsString($pageName . ' is currently inactive.', $page['body']);
            self::assertStringContainsString(
                '<title>' . htmlspecialchars($this->businessName . ' ' . $pageName) . '</title>',
                $page['body']
            );
            self::assertStringContainsString($this->businessName, $page['body']);
            self::assertStringContainsString($this->statusLogoPath, $page['body']);
            self::assertStringNotContainsString('qrcode', $page['body']);
            self::assertStringNotContainsString('role="tab"', $page['body']);
            self::assertStringNotContainsString('<form', $page['body']);
        }
        $api = $this->request('GET', '/api/get-clients', null, false, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(503, $api['status']);
        self::assertArrayHasKey('error', $this->decodeJson($api));
        foreach (['/api/get-clients?tenant_status=1', '/admin/api/get-clients'] as $endpoint) {
            $status = $this->request('GET', $endpoint, null, false, $host);
            self::assertSame(503, $status['status']);
            self::assertSame('inactive', $this->decodeJson($status)['tenant_status']);
        }
        $signup = $this->request('POST', '/api/submit-signin', [], true, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(503, $signup['status'], $signup['body']);
        $dashboard = $this->request('GET', '/platform-admin/dashboard');
        self::assertStringContainsString('data-current-status="inactive"', $dashboard['body']);
        $profile = $this->request('GET', '/platform-admin/owner-profile?owner_id=' . $this->ownerId);
        self::assertSame(200, $profile['status'], $profile['body']);
        self::assertStringContainsString('Owner account: Active', $profile['body']);
        self::assertStringContainsString('Tenant status', $profile['body']);
        self::assertStringContainsString('class="p-4 text-center">Inactive</td>', $profile['body']);
        $active = $this->changeTenantStatus($csrf, 'active');
        self::assertSame(200, $active['status'], $active['body']);
        $page = $this->request('GET', '/', null, false, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(200, $page['status'], $page['body']);
        self::assertStringContainsString('role="tab"', $page['body']);
        self::assertStringContainsString('signupQrCode', $page['body']);
        $portal = $this->request('GET', '/admin/dashboard', null, false, $host);
        self::assertSame(200, $portal['status'], $portal['body']);
        self::assertSame(1, (int)$tenantDb->query('SELECT COUNT(*) FROM clients')->fetchColumn());
    }

    public function testStatusChangesRequireAdminCsrfAndExactBusinessName(): void {
        self::assertSame(401, $this->changeTenantStatus('invalid', 'inactive')['status']);
        $this->loginTenantOwnerAndGetBillingCsrf();
        self::assertSame(401, $this->changeTenantStatus('invalid', 'inactive')['status']);
        $csrf = $this->loginAdminAndGetCsrf();
        self::assertSame(403, $this->changeTenantStatus('invalid', 'inactive')['status']);
        self::assertSame(405, $this->request('GET', '/platform-admin/api/update-tenant-status')['status']);
        foreach (['inactive', 'active', 'delete'] as $action) {
            $wrong = $this->request('POST', '/platform-admin/api/update-tenant-status', [
                'csrf_token' => $csrf, 'business_id' => $this->businessId,
                'action' => $action, 'confirmation' => 'Wrong business',
            ], true);
            self::assertSame(422, $wrong['status'], $wrong['body']);
        }
        self::assertSame(422, $this->changeTenantStatus($csrf, 'unknown')['status']);
        self::assertSame(1, $this->countRows(getDB(), 'businesses', 'id', $this->businessId));
    }

    public function testInactiveTenantCanBeDeletedWithBrandingRetained(): void {
        $this->setFixtureLogo();
        $csrf = $this->loginAdminAndGetCsrf();
        $inactive = $this->changeTenantStatus($csrf, 'inactive');
        self::assertSame(200, $inactive['status'], $inactive['body']);
        $delete = $this->changeTenantStatus($csrf, 'delete');
        self::assertSame(200, $delete['status'], $delete['body']);
        self::assertSame(0, $this->countRows(getDB(), 'platform_users', 'id', $this->ownerId));
        $page = $this->request('GET', '/', null, false, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(410, $page['status']);
        self::assertStringContainsString('Waitlist is no longer available.', $page['body']);
        self::assertStringContainsString($this->statusLogoPath, $page['body']);
        self::assertFileExists(dirname(__DIR__, 2) . '/' . $this->statusLogoPath);
    }

    public function testReactivationFailsWithoutChangingStatusWhenTenantDataIsMissing(): void {
        $csrf = $this->loginAdminAndGetCsrf();
        $inactive = $this->changeTenantStatus($csrf, 'inactive');
        self::assertSame(200, $inactive['status'], $inactive['body']);
        getDB()->exec("DROP DATABASE `{$this->databaseName}`");
        $active = $this->changeTenantStatus($csrf, 'active');
        self::assertSame(500, $active['status'], $active['body']);
        $state = getDB()->prepare('SELECT is_active FROM businesses WHERE id = :id');
        $state->execute([':id' => $this->businessId]);
        self::assertSame(0, (int)$state->fetchColumn());
        $page = $this->request('GET', '/', null, false, $this->slug . '.' . BASE_DOMAIN);
        self::assertSame(503, $page['status']);
        self::assertStringContainsString('Waitlist is currently inactive.', $page['body']);
    }

    private function changeTenantStatus(string $csrf, string $action): array {
        return $this->request('POST', '/platform-admin/api/update-tenant-status', [
            'csrf_token' => $csrf, 'business_id' => $this->businessId,
            'action' => $action, 'confirmation' => $this->businessName,
        ], true);
    }

    private function setFixtureLogo(): void {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0c8AAAAASUVORK5CYII=', true);
        self::assertIsString($image);
        file_put_contents($this->uploadDirectory . '/logo-test.png', $image);
        $stmt = createDatabaseConnection($this->databaseName)->prepare(
            'UPDATE business_settings SET logo_path = :path WHERE id = 1'
        );
        $stmt->execute([':path' => 'uploads/' . $this->slug . '/logo-test.png']);
        $this->statusLogoPath = 'uploads/_tenant-status/' . $this->slug . '-' . hash('sha256', $image) . '.png';
    }

    private function loginAdminAndGetCsrf(): string {
        $login = $this->request('POST', '/platform-admin/login', [
            'email' => $this->adminEmail, 'password' => $this->adminPassword,
        ]);
        self::assertSame(302, $login['status'], $login['body']);
        $dashboard = $this->request('GET', '/platform-admin/dashboard');
        self::assertSame(200, $dashboard['status'], $dashboard['body']);
        self::assertSame(1, preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $dashboard['body'], $match));
        return $match[1];
    }

    private function deleteOwner(string $csrf): array {
        return $this->request('POST', '/platform-admin/api/delete-owner', [
            'csrf_token' => $csrf, 'owner_id' => $this->ownerId, 'confirmation' => $this->ownerEmail,
        ], true);
    }

    private function createAdditionalOwner(): int {
        $stmt = getDB()->prepare(
            "INSERT INTO platform_users (name, email, password_hash, role)
             VALUES (:name, :email, :hash, 'business_owner')"
        );
        $stmt->execute([
            ':name' => 'Other Deletion Test Owner',
            ':email' => 'other-' . bin2hex(random_bytes(5)) . '@example.com',
            ':hash' => password_hash('other-owner-password', PASSWORD_DEFAULT),
        ]);
        $id = (int)getDB()->lastInsertId();
        $this->additionalOwnerIds[] = $id;
        return $id;
    }

    private function createAdditionalTenant(int $ownerId): array {
        $slug = 'delete-' . bin2hex(random_bytes(5));
        $databaseName = 'clientq_tenant_' . substr(hash('sha256', $slug), 0, 32);
        $db = getDB();
        $db->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/tenant-schema.sql');
        self::assertIsString($schema);
        createDatabaseConnection($databaseName)->exec($schema);
        $stmt = $db->prepare(
            'INSERT INTO businesses (name, slug, owner_email, database_name, plan)
             VALUES (:name, :slug, :email, :database, :plan)'
        );
        $stmt->execute([
            ':name' => 'Additional Delete Tenant', ':slug' => $slug, ':email' => $this->ownerEmail,
            ':database' => $databaseName, ':plan' => 'starter',
        ]);
        $id = (int)$db->lastInsertId();
        $business = ['id' => $id, 'slug' => $slug, 'database_name' => $databaseName];
        $this->additionalBusinesses[] = $business;
        $link = $db->prepare('INSERT INTO business_owners (business_id, user_id) VALUES (:business, :owner)');
        $link->execute([':business' => $id, ':owner' => $ownerId]);
        $directory = dirname(__DIR__, 2) . '/uploads/' . $slug;
        mkdir($directory, 0777, true);
        chmod($directory, 0777);
        file_put_contents($directory . '/logo-test.png', 'test upload');
        return $business;
    }

    private function assertTenantDatabaseMissing(string $databaseName): void {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :database'
        );
        $stmt->execute([':database' => $databaseName]);
        self::assertSame(0, (int)$stmt->fetchColumn());
    }

    private function countRows(
        PDO $db,
        string $table,
        string $column,
        int $value
    ): int {
        $allowed = [
            'businesses' => ['id'],
            'business_owners' => ['business_id'],
            'invoices' => ['business_id'],
            'platform_users' => ['id'],
            'business_owner_notes' => ['owner_user_id'],
        ];
        self::assertContains($column, $allowed[$table] ?? []);

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE `$column` = :value"
        );
        $stmt->execute([':value' => $value]);
        return (int)$stmt->fetchColumn();
    }

    private function decodeJson(array $response): array {
        $decoded = json_decode($response['body'], true);
        self::assertIsArray($decoded, $response['body']);
        return $decoded;
    }

    private function assertClientListNotCached(array $response, bool $clearCache): void {
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response['headers']['cache-control']
        );
        self::assertSame('no-cache', $response['headers']['pragma']);
        self::assertSame('0', $response['headers']['expires']);
        if ($clearCache) {
            self::assertSame('"cache"', $response['headers']['clear-site-data']);
        } else {
            self::assertArrayNotHasKey('clear-site-data', $response['headers']);
        }
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $json = false,
        string $host = BASE_DOMAIN
    ): array {
        if ($method === 'POST' && is_array($body) && !array_key_exists('csrf_token', $body)
            && in_array($path, ['/admin/api/save-employee', '/admin/api/delete-client', '/admin/api/deactivate-employee'], true)) {
            $page = $this->request('GET', '/admin/dashboard', null, false, $host);
            if (preg_match('/const completionToken = "([a-f0-9]+)";/', $page['body'], $match)) {
                $body['csrf_token'] = $match[1];
            }
        }
        $curl = curl_init($this->baseUrl . $path);
        $headers = ['Host: ' . $host];
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            },
        ]);

        if ($body !== null) {
            if ($json) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt(
                    $curl,
                    CURLOPT_POSTFIELDS,
                    json_encode($body, JSON_THROW_ON_ERROR)
                );
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
        return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
    }
}
