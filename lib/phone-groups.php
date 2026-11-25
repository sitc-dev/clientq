<?php

function assignBusinessPhoneGroup(PDO $db, int $businessId): string {
    if (!$db->inTransaction()) {
        throw new LogicException('Phone-pool assignment requires an active registration transaction.');
    }

    $state = $db->query('SELECT last_group_code FROM phone_pool_state WHERE id = 1 FOR UPDATE')->fetch();
    if ($state === false) {
        throw new RuntimeException('Phone-pool assignment state is not initialized.');
    }

    $existing = $db->prepare('SELECT group_code FROM business_phone_groups WHERE business_id = ? FOR UPDATE');
    $existing->execute([$businessId]);
    $group = $existing->fetchColumn();
    if ($group !== false) {
        return $group;
    }

    $groups = $db->query(
        'SELECT group_code FROM phone_groups WHERE phone_number IS NOT NULL ORDER BY group_code FOR UPDATE'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($groups === []) {
        throw new DomainException('No SMS phone number is configured in the phone pool.');
    }

    $next = $groups[0];
    foreach ($groups as $group) {
        if ($state['last_group_code'] === null || strcmp($group, $state['last_group_code']) > 0) {
            $next = $group;
            break;
        }
    }

    $assign = $db->prepare('INSERT INTO business_phone_groups (business_id, group_code) VALUES (?, ?)');
    $assign->execute([$businessId, $next]);
    $advance = $db->prepare('UPDATE phone_pool_state SET last_group_code = ? WHERE id = 1');
    $advance->execute([$next]);
    return $next;
}
