<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/php/config.php';
require_once dirname(__DIR__, 2) . '/lib/phone-groups.php';

final class PhoneGroupsIntegrationTest extends TestCase {
    private PDO $db;

    protected function setUp(): void {
        if (getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped('Run against the isolated Docker integration database.');
        }

        $this->db = getDB();
        foreach (['platform-phone-groups-migration.sql', 'platform-phone-pool-assignment-migration.sql'] as $file) {
            $migration = file_get_contents(dirname(__DIR__, 2) . '/database/' . $file);
            self::assertIsString($migration);
            for ($run = 0; $run < 2; $run++) {
                foreach (explode(';', $migration) as $statement) {
                    if (trim($statement) !== '') {
                        $this->db->query($statement)->closeCursor();
                    }
                }
            }
        }
        $this->db->beginTransaction();
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testGroupCanServeMultipleTenantsButTenantCanHaveOnlyOneGroup(): void {
        $businessIds = [];
        $insert = $this->db->prepare(
            'INSERT INTO businesses (name, slug, owner_email, database_name)
             VALUES (:name, :slug, :email, :database_name)'
        );
        for ($index = 0; $index < 2; $index++) {
            $suffix = bin2hex(random_bytes(6));
            $insert->execute([
                ':name' => 'Phone Group Test',
                ':slug' => 'phone-test-' . $suffix,
                ':email' => 'phone-test-' . $suffix . '@example.com',
                ':database_name' => 'clientq_tenant_phone_test_' . $suffix,
            ]);
            $businessIds[] = (int)$this->db->lastInsertId();
        }

        $assign = $this->db->prepare(
            "INSERT INTO business_phone_groups (business_id, group_code) VALUES (:id, 'A')"
        );
        foreach ($businessIds as $id) {
            $assign->execute([':id' => $id]);
        }
        $members = $this->db->prepare(
            "SELECT business_id FROM business_phone_groups
             WHERE business_id IN (:first, :second) AND group_code = 'A'
             ORDER BY business_id"
        );
        $members->execute([':first' => $businessIds[0], ':second' => $businessIds[1]]);
        self::assertSame($businessIds, array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN)));

        $this->assertQueryRejected(
            "INSERT INTO business_phone_groups (business_id, group_code)
             VALUES ({$businessIds[0]}, 'B')"
        );
        $this->db->exec("DELETE FROM businesses WHERE id = {$businessIds[0]}");
        self::assertSame(
            0,
            (int)$this->db->query(
                "SELECT COUNT(*) FROM business_phone_groups WHERE business_id = {$businessIds[0]}"
            )->fetchColumn()
        );
    }

    public function testGroupsAreLimitedToFiveWithDistinctValidPhoneNumbers(): void {
        self::assertSame(
            ['A', 'B', 'C', 'D', 'E'],
            $this->db->query('SELECT group_code FROM phone_groups ORDER BY group_code')
                ->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertQueryRejected("INSERT INTO phone_groups (group_code) VALUES ('F')");
        $this->assertQueryRejected(
            "UPDATE phone_groups SET phone_number = 'not-a-phone' WHERE group_code = 'A'"
        );
        $this->db->exec(
            "UPDATE phone_groups SET phone_number = '+12025550199' WHERE group_code = 'A'"
        );
        $this->assertQueryRejected(
            "UPDATE phone_groups SET phone_number = '+12025550199' WHERE group_code = 'B'"
        );
    }

    private function business(): int {
        $suffix = bin2hex(random_bytes(6));
        $insert = $this->db->prepare('INSERT INTO businesses (name, slug, owner_email, database_name) VALUES (?, ?, ?, ?)');
        $insert->execute(['Phone Pool Test', 'phone-test-' . $suffix, $suffix . '@example.com', 'clientq_tenant_phone_test_' . $suffix]);
        return (int)$this->db->lastInsertId();
    }

    private function configurePool(): void {
        $this->db->exec('UPDATE phone_groups SET phone_number = NULL');
        $set = $this->db->prepare('UPDATE phone_groups SET phone_number = ? WHERE group_code = ?');
        foreach (['A' => '+12025550191', 'C' => '+12025550193', 'E' => '+12025550195'] as $group => $number) {
            $set->execute([$number, $group]);
        }
        $this->db->exec('UPDATE phone_pool_state SET last_group_code = NULL WHERE id = 1');
    }

    public function testRoundRobinSkipsUnconfiguredGroupsAndDoesNotRewindAfterDeletionOrRetry(): void {
        $this->configurePool();
        $first = $this->business();
        self::assertSame('A', assignBusinessPhoneGroup($this->db, $first));
        self::assertSame('C', assignBusinessPhoneGroup($this->db, $this->business()));
        $last = $this->business();
        self::assertSame('E', assignBusinessPhoneGroup($this->db, $last));
        $this->db->exec('DELETE FROM businesses WHERE id = ' . $last);
        self::assertSame('A', assignBusinessPhoneGroup($this->db, $this->business()));
        self::assertSame('A', assignBusinessPhoneGroup($this->db, $first));
        self::assertSame('C', assignBusinessPhoneGroup($this->db, $this->business()));
    }

    public function testRolledBackAssignmentDoesNotConsumeTheNextTurn(): void {
        $this->configurePool();
        $first = $this->business();
        $second = $this->business();
        $this->db->exec('SAVEPOINT phone_assignment');
        self::assertSame('A', assignBusinessPhoneGroup($this->db, $first));
        $this->db->exec('ROLLBACK TO SAVEPOINT phone_assignment');
        self::assertSame('A', assignBusinessPhoneGroup($this->db, $second));
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM business_phone_groups WHERE business_id = ?');
        $stmt->execute([$first]);
        self::assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testUnconfiguredPoolFailsWithoutAssigningOrAdvancingTheCursor(): void {
        $this->configurePool();
        $this->db->exec('UPDATE phone_groups SET phone_number = NULL');
        $this->db->exec("UPDATE phone_pool_state SET last_group_code = 'C' WHERE id = 1");
        $businessId = $this->business();
        try {
            assignBusinessPhoneGroup($this->db, $businessId);
            self::fail('An unconfigured phone pool must not assign a tenant.');
        } catch (DomainException $e) {
            self::assertSame('No SMS phone number is configured in the phone pool.', $e->getMessage());
        }
        self::assertSame('C', $this->db->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1')->fetchColumn());
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM business_phone_groups WHERE business_id = ?');
        $stmt->execute([$businessId]);
        self::assertSame(0, (int)$stmt->fetchColumn());
    }

    private function assertQueryRejected(string $sql): void {
        try {
            $this->db->exec($sql);
        } catch (ClientQDatabaseQueryException $exception) {
            $this->addToAssertionCount(1);
            return;
        }
        self::fail('Expected the database to reject the invalid phone-group change.');
    }
}
