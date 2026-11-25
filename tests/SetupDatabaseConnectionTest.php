<?php

declare(strict_types=1);

use ClientQ\Tests\Console\ConsoleTest;

require_once __DIR__ . '/DatabaseConnectionTestCase.php';

#[ConsoleTest(
    'Simulate an unavailable MySQL server during ClientQ setup.',
    'The exception and daily log contain MySQL error 2002, SQLSTATE HY000, and no password.'
)]
final class SetupDatabaseConnectionTest extends DatabaseConnectionTestCase {
    public function testUnavailableSetupServerIncludesNativeMysqlDetailsAndIsLogged(): void {
        $target = 'setup MySQL server';
        $errorNumber = 2002;
        $sqlState = 'HY000';
        $message = 'Connection refused';

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
