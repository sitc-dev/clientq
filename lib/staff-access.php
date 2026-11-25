<?php

const STAFF_ACCESS_LEVELS = ['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'];

function staffAccessLabel(array $staff): string {
    if (!isset(STAFF_ACCESS_LEVELS[$staff['role']])) {
        throw new LogicException('Unknown staff access level.');
    }
    return STAFF_ACCESS_LEVELS[$staff['role']];
}

function staffCanManageUsers(array $staff): bool {
    return in_array($staff['role'], ['owner', 'manager'], true);
}

function staffAssignableRoles(array $staff): array {
    return $staff['role'] === 'owner' ? array_keys(STAFF_ACCESS_LEVELS)
        : ($staff['role'] === 'manager' ? ['staff'] : []);
}

function staffCanManageUser(array $actor, array $target): bool {
    return $actor['role'] === 'owner' || ($actor['role'] === 'manager' && $target['role'] === 'staff');
}

function staffCanCompleteClient(array $staff, ?int $assignedStaffId): bool {
    return staffCanManageUsers($staff) || $assignedStaffId === null || $assignedStaffId === (int)$staff['id'];
}

function staffCanDeleteClient(array $staff, ?int $assignedStaffId): bool {
    return staffCanManageUsers($staff) || $assignedStaffId === (int)$staff['id'];
}

function lockStaffAdministration(PDO $db): void {
    if (!$db->inTransaction()) {
        throw new LogicException('Staff administration requires an active transaction.');
    }
    if ($db->query('SELECT id FROM business_settings WHERE id = 1 FOR UPDATE')->fetchColumn() === false) {
        throw new RuntimeException('Business settings are missing.');
    }
}

function currentStaffAdministrator(PDO $db, array $actor): array {
    $stmt = $db->prepare('SELECT id, role, password_version FROM staff WHERE id = ? AND password_version = ? AND is_active = 1 FOR UPDATE');
    $stmt->execute([$actor['id'], $actor['password_version']]);
    $current = $stmt->fetch();
    if ($current === false || !staffCanManageUsers($current)) {
        throw new DomainException('Your access changed. Sign in again before managing users.');
    }
    return $current;
}

function retainActiveStaffOwner(PDO $db, array $target, string $nextRole, bool $active = true): void {
    if ($target['role'] === 'owner' && ($nextRole !== 'owner' || !$active)
        && (int)$db->query("SELECT COUNT(*) FROM staff WHERE role = 'owner' AND is_active = 1")->fetchColumn() <= 1) {
        throw new DomainException('The last active Owner cannot be demoted or deactivated.');
    }
}
