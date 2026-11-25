<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/tenant-lifecycle.php';

$admin = requirePlatformRole('platform_owner', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

if (!verifyPlatformCsrfToken($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}

$ownerId = filter_var($input['owner_id'] ?? null, FILTER_VALIDATE_INT);
$confirmation = $input['confirmation'] ?? null;
if ($ownerId === false || $ownerId <= 0 || !is_string($confirmation) || $confirmation === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Owner ID and email confirmation are required.']);
    exit;
}

$db = getDB();
$locks = [];
$owner = null;
$businesses = [];
$deactivated = false;
$dataRemoved = false;

try {
    // Registration and tenant deletion also use these locks.
    foreach (['clientq-register', 'clientq-delete-owner-' . $ownerId] as $lockName) {
        $lockStmt = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lockStmt->execute([':lock_name' => $lockName]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            http_response_code(503);
            echo json_encode(['error' => 'Owner deletion is busy. Please try again.']);
            return;
        }
        $locks[] = $lockName;
    }

    $idsStmt = $db->prepare(
        'SELECT business_id FROM business_owners WHERE user_id = :owner_id ORDER BY business_id'
    );
    $idsStmt->execute([':owner_id' => $ownerId]);
    foreach ($idsStmt->fetchAll(PDO::FETCH_COLUMN) as $businessId) {
        $lockName = 'clientq-delete-' . $businessId;
        $lockStmt = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lockStmt->execute([':lock_name' => $lockName]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            http_response_code(503);
            echo json_encode(['error' => 'Tenant deletion is busy. Please try again.']);
            return;
        }
        $locks[] = $lockName;
    }

    $db->beginTransaction();
    $ownerStmt = $db->prepare(
        "SELECT id, email, is_active FROM platform_users
         WHERE id = :id AND role = 'business_owner' FOR UPDATE"
    );
    $ownerStmt->execute([':id' => $ownerId]);
    $owner = $ownerStmt->fetch();
    if (!$owner) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Tenant owner not found.']);
        return;
    }
    if (!hash_equals($owner['email'], $confirmation)) {
        $db->rollBack();
        http_response_code(422);
        echo json_encode(['error' => 'Enter the exact owner email to confirm deletion.']);
        return;
    }

    $businessStmt = $db->prepare(
        'SELECT b.id, b.name, b.slug, b.database_name, b.is_active
         FROM businesses b JOIN business_owners bo ON bo.business_id = b.id
         WHERE bo.user_id = :owner_id ORDER BY b.id FOR UPDATE'
    );
    $businessStmt->execute([':owner_id' => $ownerId]);
    $businesses = $businessStmt->fetchAll();

    foreach ($businesses as $business) {
        $otherOwnersStmt = $db->prepare(
            'SELECT user_id FROM business_owners
             WHERE business_id = :business_id AND user_id <> :owner_id FOR UPDATE'
        );
        $otherOwnersStmt->execute([
            ':business_id' => $business['id'],
            ':owner_id' => $ownerId,
        ]);
        if ($otherOwnersStmt->fetch()) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode([
                'error' => 'Deletion blocked: a tenant is linked to another owner. Remove the unintended ownership link before retrying.',
            ]);
            return;
        }
        if (!preg_match('/^clientq_tenant_[a-z0-9_]+$/', $business['database_name'])
            || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $business['slug'])
        ) {
            throw new RuntimeException('Invalid tenant database or upload configuration.');
        }
    }

    $deactivateOwner = $db->prepare('UPDATE platform_users SET is_active = 0 WHERE id = :id');
    $deactivateOwner->execute([':id' => $ownerId]);
    $deactivateBusiness = $db->prepare('UPDATE businesses SET is_active = 0 WHERE id = :id');
    foreach ($businesses as $business) {
        $deactivateBusiness->execute([':id' => $business['id']]);
    }
    $db->commit();
    $deactivated = true;

    foreach ($businesses as $business) {
        permanentlyDeleteTenant($db, $business, $admin, false);
        $dataRemoved = true;
    }

    $deleteOwner = $db->prepare(
        "DELETE FROM platform_users WHERE id = :id AND role = 'business_owner'"
    );
    $deleteOwner->execute([':id' => $ownerId]);
    if ($deleteOwner->rowCount() !== 1) {
        throw new RuntimeException('Central owner record was not deleted.');
    }

    clientqLog(sprintf(
        'ClientQ owner deleted: owner_id=%d tenant_count=%d admin_id=%d',
        $ownerId,
        count($businesses),
        (int)$admin['id']
    ), level: 'INFO');
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $dataRemoved = $dataRemoved || (bool)array_filter($businesses, static fn($business) =>
        (tenantStatusRecord($db, $business['slug'])['status'] ?? null) === 'deleted'
    );
    clientqLog(sprintf(
        'ClientQ owner deletion failed: owner_id=%d data_removed=%s error=%s',
        $ownerId,
        $dataRemoved ? 'yes' : 'no',
        $e->getMessage()
    ));

    $restoreFailed = false;
    if ($deactivated && !$dataRemoved) {
        try {
            $db->beginTransaction();
            $restoreOwner = $db->prepare('UPDATE platform_users SET is_active = :active WHERE id = :id');
            $restoreOwner->execute([':active' => $owner['is_active'], ':id' => $ownerId]);
            $restoreBusiness = $db->prepare('UPDATE businesses SET is_active = :active WHERE id = :id');
            foreach ($businesses as $business) {
                $restoreBusiness->execute([':active' => $business['is_active'], ':id' => $business['id']]);
            }
            $db->commit();
        } catch (Throwable $restoreError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $restoreFailed = true;
            clientqLog(sprintf(
                'ClientQ owner access restoration failed: owner_id=%d error=%s',
                $ownerId,
                $restoreError->getMessage()
            ));
        }
    }

    http_response_code(500);
    echo json_encode([
        'error' => $dataRemoved
            ? 'Some tenant data was removed, but deletion did not finish. The owner login and remaining tenant URLs stay disabled. Retry Delete Owner to finish cleanup.'
            : ($restoreFailed
                ? 'No tenant data was removed, but restoring access failed. The owner login and tenant URLs may remain disabled. Contact support or retry Delete Owner.'
                : 'The owner could not be deleted. No tenant data was removed.'),
    ]);
} finally {
    foreach (array_reverse($locks) as $lockName) {
        $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseStmt->execute([':lock_name' => $lockName]);
    }
}
