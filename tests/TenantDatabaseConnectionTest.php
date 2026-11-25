<?php

declare(strict_types=1);

use ClientQ\Tests\Console\ConsoleTest;

require_once __DIR__ . '/DatabaseConnectionTestCase.php';

#[ConsoleTest(
    'Simulate a connection attempt to a missing tenant database.',
    'The exception and daily log contain MySQL error 1049, SQLSTATE 42000, and no password.'
)]
final class TenantDatabaseConnectionTest extends DatabaseConnectionTestCase {
    public function testMissingTenantDatabaseIncludesNativeMysqlDetailsAndIsLogged(): void {
        $target = "database 'clientq_tenant_missing'";
        $errorNumber = 1049;
        $sqlState = '42000';
        $message = "Unknown database 'clientq_tenant_missing'";

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
