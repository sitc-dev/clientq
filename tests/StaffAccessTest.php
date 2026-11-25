<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/staff-access.php';

final class StaffAccessTest extends TestCase {
    public static function clientPermissions(): array {
        return [
            ['owner', null, true, true], ['owner', 20, true, true],
            ['manager', null, true, true], ['manager', 20, true, true],
            ['staff', 10, true, true], ['staff', null, true, false], ['staff', 20, false, false],
        ];
    }

    #[DataProvider('clientPermissions')]
    public function testClientActionsDependOnRoleAndAssignment(string $role, ?int $assignment, bool $complete, bool $delete): void {
        $staff = ['id' => 10, 'role' => $role];
        self::assertSame($complete, staffCanCompleteClient($staff, $assignment));
        self::assertSame($delete, staffCanDeleteClient($staff, $assignment));
    }
}
