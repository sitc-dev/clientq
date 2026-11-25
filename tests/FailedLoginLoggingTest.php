<?php

declare(strict_types=1);

use ClientQ\Tests\Console\ConsoleTest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Console/ConsoleTest.php';
require_once dirname(__DIR__) . '/lib/logging.php';

#[ConsoleTest(
    'Log a failed login with its submitted username and source file.',
    'The log contains a structured username, role, reason, IP address, tenant slug, and repository-relative filename without allowing line injection.'
)]
final class FailedLoginLoggingTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir()
            . '/clientq-login-phpunit-'
            . bin2hex(random_bytes(8));
        putenv('CLIENTQ_LOG_DIR=' . $this->logDirectory);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logDirectory)) {
            foreach (new DirectoryIterator($this->logDirectory) as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->logDirectory);
        }
        unset($_SERVER['REMOTE_ADDR']);
        putenv('CLIENTQ_LOG_DIR');
    }

    public function testFailedLoginIncludesUsernameAndSourceFile(): void
    {
        clientqLogFailedLogin(
            "person@example.com\nforged-entry",
            'tenant_staff',
            'invalid_credentials',
            'demo'
        );

        $logFile = $this->logDirectory . '/clientq-' . date('Y-m-d') . '.log';
        $contents = file_get_contents($logFile);

        self::assertIsString($contents);
        self::assertStringContainsString('[WARNING] [tests/FailedLoginLoggingTest.php]', $contents);
        self::assertStringContainsString('[tests/FailedLoginLoggingTest.php]', $contents);
        self::assertStringContainsString(
            'username="person@example.com\\nforged-entry"',
            $contents
        );
        self::assertStringContainsString('role="tenant_staff"', $contents);
        self::assertStringContainsString('reason="invalid_credentials"', $contents);
        self::assertStringContainsString('ip="192.0.2.10"', $contents);
        self::assertStringContainsString('tenant="demo"', $contents);
        self::assertSame(1, substr_count(trim($contents), "\n") + 1);
    }

    public function testRejectedSignupTokensAreWarnings(): void
    {
        clientqLogInvalidSignupToken('prepare', false);

        $logFile = $this->logDirectory . '/clientq-' . date('Y-m-d') . '.log';
        $contents = file_get_contents($logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('[WARNING] [tests/FailedLoginLoggingTest.php]', $contents);
        self::assertStringContainsString('ClientQ signup invalid request token: stage=prepare', $contents);
    }
}
