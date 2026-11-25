<?php

declare(strict_types=1);

use ClientQ\Tests\Console\ConsoleTest;

require_once __DIR__ . '/DatabaseConnectionTestCase.php';

#[ConsoleTest(
    'Simulate a permission failure while connecting to a new tenant database.',
    'The exception and daily log contain MySQL error 1044, SQLSTATE 42000, and no password.'
)]
final class ProvisioningDatabaseConnectionTest extends DatabaseConnectionTestCase {
    public function testProvisioningPermissionFailureIncludesNativeMysqlDetailsAndIsLogged(): void {
        $target = "database 'clientq_tenant_provisioning'";
        $errorNumber = 1044;
        $sqlState = '42000';
        $message = "Access denied for user 'clientq' to database 'clientq_tenant_provisioning'";

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
