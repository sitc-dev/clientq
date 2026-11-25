<?php

declare(strict_types=1);

use ClientQ\Tests\Console\ConsoleTest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Console/ConsoleTest.php';
require_once dirname(__DIR__) . '/lib/database-errors.php';

#[ConsoleTest(
    'Wrap a failed database operation and write an attributed log entry.',
    'The custom exception extends Exception, preserves native MySQL details, and the log identifies the repository-relative source file.'
)]
final class DatabaseOperationExceptionTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir()
            . '/clientq-operation-phpunit-'
            . bin2hex(random_bytes(8));
        putenv('CLIENTQ_LOG_DIR=' . $this->logDirectory);
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
        putenv('CLIENTQ_LOG_DIR');
    }

    public function testQueryExceptionPreservesDriverDetails(): void
    {
        $previous = new PDOException('Duplicate entry');
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];

        $exception = databaseQueryException(
            "database 'clientq'",
            'execute statement',
            $previous
        );

        self::assertInstanceOf(Exception::class, $exception);
        self::assertInstanceOf(ClientQDatabaseException::class, $exception);
        self::assertSame(1062, $exception->mysqlErrorNumber);
        self::assertSame('23000', $exception->sqlState);
        self::assertSame($previous->errorInfo, $exception->errorInfo);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testDriverDetailsCanBeParsedFromExceptionMessage(): void
    {
        $previous = new PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'
        );

        self::assertSame(1062, mysqlErrorNumber($previous));
        self::assertSame('23000', mysqlSqlState($previous));
    }

    public function testLoggerIncludesRepositoryRelativeSourcePath(): void
    {
        clientqLog(
            'ClientQ attributed log test.',
            dirname(__DIR__) . '/api/register-business.php'
        );

        $logFile = $this->logDirectory . '/clientq-' . date('Y-m-d') . '.log';
        $contents = file_get_contents($logFile);

        self::assertIsString($contents);
        self::assertStringContainsString(
            '[ERROR] [api/register-business.php] ClientQ attributed log test.',
            $contents
        );
    }

    public function testLoggerIncludesTheRequestedSeverityAndInferredSource(): void
    {
        foreach (['ERROR', 'INFO', 'WARNING'] as $level) {
            clientqLog('Severity test.', level: $level);
        }

        $logFile = $this->logDirectory . '/clientq-' . date('Y-m-d') . '.log';
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(3, $lines);
        foreach (['ERROR', 'INFO', 'WARNING'] as $index => $level) {
            self::assertMatchesRegularExpression(
                '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[' . $level
                    . '\] \[tests\/DatabaseOperationExceptionTest\.php\] Severity test\.$/',
                $lines[$index]
            );
        }
    }

    public function testMultilineMessagesCannotCreateUnlabeledLogEntries(): void
    {
        clientqLog("First line\r\nSecond line\nThird line", level: 'INFO');

        $logFile = $this->logDirectory . '/clientq-' . date('Y-m-d') . '.log';
        $contents = file_get_contents($logFile);
        self::assertIsString($contents);
        self::assertStringContainsString(
            '[INFO] [tests/DatabaseOperationExceptionTest.php] First line\\r\\nSecond line\\nThird line',
            $contents
        );
        self::assertSame(1, substr_count($contents, "\n"));
    }

    public function testUnsupportedLogLevelsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        clientqLog('Invalid severity.', level: 'DEBUG');
    }
}
