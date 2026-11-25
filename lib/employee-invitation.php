<?php
require_once __DIR__ . '/staff-access.php';

function createInvitedEmployee(
    PDO $db,
    array $employee,
    int $limit,
    string $businessName,
    string $loginUrl,
    callable $send,
    ?array $actor = null
): void {
    $employee['username'] = $employee['email'];
    $employee['experience'] = '';
    $employee['role'] = $employee['role'] ?? 'staff';
    if (!is_string($employee['role']) || !isset(STAFF_ACCESS_LEVELS[$employee['role']])) {
        throw new InvalidArgumentException('Choose a valid access level.');
    }
    $db->beginTransaction();
    try {
        lockStaffAdministration($db);
        if ($actor !== null && !in_array($employee['role'], staffAssignableRoles(currentStaffAdministrator($db, $actor)), true)) {
            throw new DomainException('Your access level cannot assign this role.');
        }
        $count = (int)$db->query(
            "SELECT COUNT(*) - LEAST(COALESCE(SUM(role = 'owner'), 0), 1) FROM staff WHERE is_active = 1"
        )->fetchColumn();
        if ($count >= $limit) {
            throw new DomainException('Your plan supports up to ' . $limit . ' active employees.');
        }

        $temporaryPassword = bin2hex(random_bytes(12));
        $stmt = $db->prepare(
            "INSERT INTO staff
                (name, first_name, last_name, goes_by, username, email, position, experience,
                 additional_info, password_hash, role, must_change_password)
             VALUES
                (:name, :first_name, :last_name, :goes_by, :username, :email, :position, :experience,
                 :additional_info, :password_hash, :role, 1)"
        );
        $stmt->execute(array_merge($employee, [
            'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
        ]));
        $result = $send(
            $employee['email'],
            'Your ' . $businessName . ' staff portal login',
            "Your staff account for {$businessName} has been created.\n\n"
                . "Staff portal: {$loginUrl}\n"
                . "Email: {$employee['email']}\n"
                . 'Access level: ' . STAFF_ACCESS_LEVELS[$employee['role']] . "\n"
                . "Temporary password: {$temporaryPassword}\n\n"
                . 'At your first login, you must choose a new password before accessing the staff portal.'
        );
        if (($result['success'] ?? false) !== true) {
            throw new RuntimeException('Employee invitation email delivery failed: ' . ($result['error'] ?? 'Unknown mail error'));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
