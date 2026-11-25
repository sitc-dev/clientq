<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/database-errors.php';

abstract class DatabaseConnectionTestCase extends TestCase {
    private string $logDirectory;

    protected function setUp(): void {
        $this->logDirectory = sys_get_temp_dir()
            . '/clientq-phpunit-'
            . bin2hex(random_bytes(8));
        putenv('CLIENTQ_LOG_DIR=' . $this->logDirectory);
    }

    protected function tearDown(): void {
        if (is_dir($this->logDirectory)) {
            foreach (new DirectoryIterator($this->logDirectory) as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->logDirectory);
        }
        putenv('CLIENTQ_LOG_DIR');
    }

    protected function triggerConnectionFailure(
        string $target,
        int $mysqlErrorNumber,
        string $sqlState,
        string $driverMessage
    ): ClientQDatabaseConnectionException {
        $connector = static function () use (
            $mysqlErrorNumber,
            $sqlState,
            $driverMessage
        ): PDO {
            $exception = new PDOException(
                "SQLSTATE[$sqlState] [$mysqlErrorNumber] $driverMessage"
            );
            $exception->errorInfo = [$sqlState, $mysqlErrorNumber, $driverMessage];
            throw $exception;
        };

        try {
            connectToMysql(
                'mysql:host=test.invalid;charset=utf8mb4',
                'clientq',
                'not-logged',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                $target,
                $connector
            );
            self::fail('Expected a database connection exception.');
        } catch (ClientQDatabaseConnectionException $exception) {
            return $exception;
        }
    }

    protected function assertConnectionExceptionDetails(
        ClientQDatabaseConnectionException $exception,
        string $target,
        int $mysqlErrorNumber,
        string $sqlState,
        string $driverMessage
    ): void {
        self::assertSame($mysqlErrorNumber, $exception->mysqlErrorNumber);
        self::assertSame($sqlState, $exception->sqlState);
        self::assertSame($mysqlErrorNumber, $exception->getCode());
        self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        self::assertStringContainsString($target, $exception->getMessage());
        self::assertStringContainsString(
            "MySQL error $mysqlErrorNumber",
            $exception->getMessage()
        );
        self::assertStringContainsString(
            "SQLSTATE $sqlState",
            $exception->getMessage()
        );
        self::assertStringContainsString($driverMessage, $exception->getMessage());
    }

    protected function assertDailyLogContainsConnectionFailure(
        ClientQDatabaseConnectionException $exception,
        string $target,
        int $mysqlErrorNumber,
        string $sqlState,
        string $driverMessage
    ): void {
        $logFile = $this->logDirectory . '/clientq-' . date('Y-m-d') . '.log';
        self::assertFileExists($logFile);
        $logContents = file_get_contents($logFile);
        self::assertIsString($logContents);
        self::assertStringContainsString($exception->getMessage(), $logContents);
        self::assertStringContainsString($target, $logContents);
        self::assertStringContainsString("MySQL error $mysqlErrorNumber", $logContents);
        self::assertStringContainsString("SQLSTATE $sqlState", $logContents);
        self::assertStringContainsString($driverMessage, $logContents);
        self::assertStringContainsString('[tests/DatabaseConnectionTestCase.php]', $logContents);
        self::assertStringNotContainsString('not-logged', $logContents);
    }
}
