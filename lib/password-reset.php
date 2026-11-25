<?php

function passwordResetTarget(string $portal): array {
    return match ($portal) {
        'tenant' => ['table' => 'staff', 'email' => 'username', 'role' => null, 'label' => 'staff portal'],
        'owner' => ['table' => 'platform_users', 'email' => 'email', 'role' => 'business_owner', 'label' => 'business-owner account'],
        'admin' => ['table' => 'platform_users', 'email' => 'email', 'role' => 'platform_owner', 'label' => 'platform administration'],
        default => throw new InvalidArgumentException('Invalid password reset portal.'),
    };
}

function passwordResetIsRateLimited(PDO $db, string $ip): bool {
    $db->beginTransaction();
    try {
        $key = hash('sha256', $ip);
        $stmt = $db->prepare(
            'INSERT INTO password_reset_rate_limits (ip_hash, request_count, window_started_at)
             VALUES (:ip, 1, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                request_count = IF(window_started_at <= CURRENT_TIMESTAMP - INTERVAL 1 HOUR, 1, request_count + 1),
                window_started_at = IF(window_started_at <= CURRENT_TIMESTAMP - INTERVAL 1 HOUR, CURRENT_TIMESTAMP, window_started_at)'
        );
        $stmt->execute([':ip' => $key]);
        $count = $db->prepare('SELECT request_count FROM password_reset_rate_limits WHERE ip_hash = :ip');
        $count->execute([':ip' => $key]);
        $limited = (int)$count->fetchColumn() > 5;
        $db->commit();
        return $limited;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function requestPasswordReset(PDO $db, string $portal, string $email, string $resetUrl, callable $send): void {
    $target = passwordResetTarget($portal);
    $db->beginTransaction();
    try {
        $roleSql = $target['role'] === null ? '' : ' AND role = :role';
        $params = [':email' => $email];
        if ($target['role'] !== null) {
            $params[':role'] = $target['role'];
        }
        $lookup = $db->prepare(
            "SELECT id, password_version FROM {$target['table']}
             WHERE {$target['email']} = :email AND is_active = 1 $roleSql FOR UPDATE"
        );
        $lookup->execute($params);
        $account = $lookup->fetch();
        if ($account === false) {
            $db->commit();
            return;
        }
        $token = bin2hex(random_bytes(32));
        $save = $db->prepare(
            'INSERT INTO password_reset_tokens (account_id, token_hash, password_version, expires_at)
             VALUES (:id, :hash, :version, CURRENT_TIMESTAMP + INTERVAL 30 MINUTE)
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                password_version = VALUES(password_version), expires_at = VALUES(expires_at)'
        );
        $save->execute([
            ':id' => $account['id'], ':hash' => hash('sha256', $token),
            ':version' => $account['password_version'],
        ]);
        $result = $send(
            $email,
            'Reset your ClientQ password',
            "A password reset was requested for your ClientQ {$target['label']}.\n\n"
                . $resetUrl . '?token=' . $token . "\n\n"
                . "This link expires in 30 minutes and can only be used once.\n"
                . 'If you did not request this, ignore this email. Your password has not changed.'
        );
        if (($result['success'] ?? false) !== true) {
            throw new RuntimeException('Password reset email delivery failed: ' . ($result['error'] ?? 'Unknown mail error'));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function passwordResetAccount(PDO $db, string $portal, string $token, bool $lock = false): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $target = passwordResetTarget($portal);
    $roleSql = $target['role'] === null ? '' : ' AND a.role = :role';
    $params = [':hash' => hash('sha256', $token)];
    if ($target['role'] !== null) {
        $params[':role'] = $target['role'];
    }
    $stmt = $db->prepare(
        "SELECT r.account_id FROM password_reset_tokens r
         JOIN {$target['table']} a ON a.id = r.account_id
         WHERE r.token_hash = :hash AND r.expires_at > CURRENT_TIMESTAMP
            AND a.is_active = 1 AND r.password_version = a.password_version $roleSql" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function completePasswordReset(PDO $db, string $portal, string $token, string $password): bool {
    if (strlen($password) < 8 || strlen($password) > 255) {
        throw new InvalidArgumentException('Password must be between 8 and 255 characters.');
    }
    if (str_contains($password, "\0")) {
        throw new InvalidArgumentException('Password cannot contain null characters.');
    }
    $target = passwordResetTarget($portal);
    $db->beginTransaction();
    try {
        $id = passwordResetAccount($db, $portal, $token, true);
        if ($id === null) {
            $db->rollBack();
            return false;
        }
        $clearTemporary = $portal === 'tenant' ? ', must_change_password = 0' : '';
        $update = $db->prepare(
            "UPDATE {$target['table']} SET password_hash = :hash, password_version = password_version + 1 $clearTemporary WHERE id = :id"
        );
        $update->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
        $delete = $db->prepare('DELETE FROM password_reset_tokens WHERE account_id = :id');
        $delete->execute([':id' => $id]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
