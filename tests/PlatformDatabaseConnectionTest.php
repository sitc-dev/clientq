<?php

declare(strict_types=1);

use ClientQ\Tests\Console\ConsoleTest;

require_once __DIR__ . '/DatabaseConnectionTestCase.php';

#[ConsoleTest(
    'Simulate an access-denied failure when connecting to the platform database.',
    'The exception and daily log contain MySQL error 1045, SQLSTATE 28000, and no password.'
)]
final class PlatformDatabaseConnectionTest extends DatabaseConnectionTestCase {
    public function testAccessDeniedIncludesNativeMysqlDetailsAndIsLogged(): void {
        $target = "database 'clientq'";
        $errorNumber = 1045;
        $sqlState = '28000';
        $message = "Access denied for user 'clientq'";

        $exception = $this->triggerConnectionFailure(
            $target,
            $errorNumber,
            $sqlState,
            $message
        );

        $this->assertConnectionExceptionDetails(
            $exception,
            $target,
            $errorNumber,
            $sqlState,
            $message
        );
        $this->assertDailyLogContainsConnectionFailure(
            $exception,
            $target,
            $errorNumber,
            $sqlState,
            $message
        );
    }
}
