<?php

require_once __DIR__ . '/tenant-deletion.php';
require_once __DIR__ . '/../src/php/exceptions/ClientQTenantLifecycleException.php';

function tenantStatusRecord(PDO $db, string $slug): ?array {
    $stmt = $db->prepare('SELECT * FROM tenant_status_pages WHERE slug = :slug');
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetch() ?: null;
}

function tenantStatusBranding(PDO $db, array $business): array {
    if (!preg_match('/^clientq_tenant_[a-z0-9_]+$/', $business['database_name'])
        || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $business['slug'])
    ) {
        throw new RuntimeException('Invalid tenant database or upload configuration.');
    }
    $existing = tenantStatusRecord($db, $business['slug']);
    if ($existing !== null && $existing['status'] === 'deleted') {
        return $existing;
    }

    $settings = createDatabaseConnection($business['database_name'])->query(
        'SELECT logo_path, theme_primary FROM business_settings WHERE id = 1'
    )->fetch();
    if (!$settings) {
        throw new RuntimeException('Tenant business settings are missing.');
    }
    $logoPath = $settings['logo_path'];
    if (is_string($logoPath) && $logoPath !== '') {
        $prefix = 'uploads/' . $business['slug'] . '/';
        if (!str_starts_with($logoPath, $prefix)
            || !preg_match('/^[a-zA-Z0-9._-]+\.(jpg|jpeg|png|webp)$/', substr($logoPath, strlen($prefix)), $match)
        ) {
            throw new RuntimeException('Invalid tenant logo path.');
        }
        $root = dirname(__DIR__);
        $source = realpath($root . '/' . $logoPath);
        $tenantDirectory = realpath($root . '/uploads/' . $business['slug']);
        if ($source === false || $tenantDirectory === false
            || is_link($root . '/uploads/' . $business['slug']) || is_link($root . '/' . $logoPath)
            || dirname($tenantDirectory) !== realpath($root . '/uploads')
            || dirname($source) !== $tenantDirectory || !is_file($source)
        ) {
            throw new RuntimeException('Tenant logo is unavailable.');
        }
        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw new RuntimeException('Tenant logo could not be read.');
        }
        $directory = $root . '/uploads/_tenant-status';
        if (is_link($directory)
            || (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
        ) {
            throw new RuntimeException('Status logo directory is unavailable.');
        }
        $logoPath = 'uploads/_tenant-status/' . $business['slug'] . '-' . $hash . '.' . $match[1];
        $destination = $root . '/' . $logoPath;
        if (is_link($destination)) {
            throw new RuntimeException('Invalid status logo destination.');
        }
        if (!is_file($destination)) {
            $temporary = tempnam($directory, 'status-');
            if ($temporary === false) {
                throw new RuntimeException('Status logo staging failed.');
            }
            try {
                if (!copy($source, $temporary) || !chmod($temporary, 0644)
                    || !hash_equals($hash, (string)hash_file('sha256', $temporary))
                    || !rename($temporary, $destination)
                ) {
                    throw new RuntimeException('Tenant status logo could not be preserved.');
                }
            } finally {
                if (is_file($temporary) && !unlink($temporary)) {
                    clientqLog('ClientQ tenant status logo cleanup failed: ' . basename($temporary), level: 'WARNING');
                }
            }
        }
        if (!hash_equals($hash, (string)hash_file('sha256', $destination))) {
            throw new RuntimeException('Tenant status logo could not be preserved.');
        }
    } else {
        $logoPath = null;
    }
    return [
        'logo_path' => $logoPath,
        'theme_primary' => normalizeThemeColor($settings['theme_primary'], TENANT_THEME_DEFAULT_PRIMARY),
    ];
}

function tenantLifecycleNote(string $action, array $business, array $admin): string {
    return sprintf(
        '%s UTC: Tenant %s (ID %d, %s) %s by %s (admin ID %d).',
        gmdate('Y-m-d H:i:s'),
        $business['name'],
        (int)$business['id'],
        $business['slug'],
        $action,
        $admin['name'],
        (int)$admin['id']
    );
}

function saveTenantStatusRecord(PDO $db, array $business, array $branding, string $status, array $admin): void {
    $stmt = $db->prepare(
        'INSERT INTO tenant_status_pages
            (slug, business_id, business_name, logo_path, theme_primary, status,
             changed_at, changed_by_id, changed_by_name, note)
         VALUES (:slug, :business_id, :name, :logo, :primary_color, :status,
             UTC_TIMESTAMP(), :admin_id, :admin_name, :note)
         ON DUPLICATE KEY UPDATE business_id = VALUES(business_id), business_name = VALUES(business_name),
             logo_path = VALUES(logo_path), theme_primary = VALUES(theme_primary), status = VALUES(status),
             changed_at = VALUES(changed_at), changed_by_id = VALUES(changed_by_id),
             changed_by_name = VALUES(changed_by_name), note = VALUES(note)'
    );
    $stmt->execute([
        ':slug' => $business['slug'], ':business_id' => $business['id'], ':name' => $business['name'],
        ':logo' => $branding['logo_path'], ':primary_color' => $branding['theme_primary'],
        ':status' => $status, ':admin_id' => $admin['id'], ':admin_name' => $admin['name'],
        ':note' => tenantLifecycleNote($status === 'deleted' ? 'deletion requested' : 'marked inactive', $business, $admin),
    ]);
}

function setTenantActiveState(PDO $db, array $business, bool $active, array $admin): void {
    $previous = tenantStatusRecord($db, $business['slug']);
    if ($previous !== null && $previous['status'] === 'deleted') {
        throw new ClientQTenantLifecycleException('This tenant is being deleted and cannot be reactivated. Retry deletion to finish cleanup.', 409);
    }
    $branding = tenantStatusBranding($db, $business);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE businesses SET is_active = :active WHERE id = :id');
        $stmt->execute([':active' => (int)$active, ':id' => $business['id']]);
        if ($active) {
            $stmt = $db->prepare('DELETE FROM tenant_status_pages WHERE slug = :slug');
            $stmt->execute([':slug' => $business['slug']]);
        } else {
            saveTenantStatusRecord($db, $business, $branding, 'inactive', $admin);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    clientqLog('ClientQ tenant status changed: ' . clientqLogField(
        tenantLifecycleNote($active ? 'reactivated' : 'marked inactive', $business, $admin)
    ), level: 'INFO');
}

// Callers hold the registration lock and this tenant's deletion lock.
function permanentlyDeleteTenant(PDO $db, array $business, array $admin, bool $removeLastOwner = true): void {
    $owners = $db->prepare('SELECT user_id FROM business_owners WHERE business_id = :id');
    $owners->execute([':id' => $business['id']]);
    $ownerIds = $owners->fetchAll(PDO::FETCH_COLUMN);
    if (count($ownerIds) > 1) {
        throw new ClientQTenantLifecycleException('Deletion blocked: the tenant is linked to another owner. Resolve the unintended ownership link first.', 409);
    }

    $previous = tenantStatusRecord($db, $business['slug']);
    $branding = tenantStatusBranding($db, $business);
    $db->beginTransaction();
    try {
        saveTenantStatusRecord($db, $business, $branding, 'deleted', $admin);
        $stmt = $db->prepare('UPDATE businesses SET is_active = 0 WHERE id = :id');
        $stmt->execute([':id' => $business['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $dataRemoved = false;
    try {
        $databaseName = $business['database_name'];
        $db->exec("DROP DATABASE IF EXISTS `$databaseName`");
        $dataRemoved = true;
        if (!removeTenantUploadDirectory($business['slug'])) {
            throw new RuntimeException('Tenant uploads could not be removed.');
        }
        $db->beginTransaction();
        $stmt = $db->prepare('DELETE FROM businesses WHERE id = :id');
        $stmt->execute([':id' => $business['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Tenant registry record was not removed.');
        }
        if ($removeLastOwner) {
            $deleteOwner = $db->prepare(
                "DELETE FROM platform_users WHERE id = :id AND role = 'business_owner'
                 AND NOT EXISTS (SELECT 1 FROM business_owners WHERE user_id = :owner_id)"
            );
            foreach ($ownerIds as $ownerId) {
                $deleteOwner->execute([':id' => $ownerId, ':owner_id' => $ownerId]);
            }
        }
        $note = tenantLifecycleNote('deleted', $business, $admin);
        $audit = $db->prepare(
            'UPDATE tenant_status_pages SET note = :note, changed_at = UTC_TIMESTAMP() WHERE slug = :slug'
        );
        $audit->execute([':note' => $note, ':slug' => $business['slug']]);
        $db->commit();
        clientqLog('ClientQ tenant deleted: ' . clientqLogField($note), level: 'INFO');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        clientqLog('ClientQ tenant deletion failed: business_id=' . (int)$business['id'] . ' error=' . $e->getMessage());
        if (!$dataRemoved) {
            $db->beginTransaction();
            try {
                $restore = $db->prepare('UPDATE businesses SET is_active = :active WHERE id = :id');
                $restore->execute([':active' => $business['is_active'], ':id' => $business['id']]);
                $restore = $db->prepare('DELETE FROM tenant_status_pages WHERE slug = :slug');
                $restore->execute([':slug' => $business['slug']]);
                if ($previous !== null) {
                    $restore = $db->prepare(
                        'INSERT INTO tenant_status_pages
                         (slug, business_id, business_name, logo_path, theme_primary, status,
                          changed_at, changed_by_id, changed_by_name, note)
                         VALUES (:slug, :business_id, :business_name, :logo_path, :theme_primary, :status,
                          :changed_at, :changed_by_id, :changed_by_name, :note)'
                    );
                    $restore->execute($previous);
                }
                $db->commit();
            } catch (Throwable $restoreError) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                clientqLog('ClientQ tenant access restoration failed: ' . $restoreError->getMessage());
                throw new ClientQTenantLifecycleException('Deletion failed before removing tenant data, but access restoration failed. Retry deletion or contact support.', 500, $restoreError);
            }
        }
        throw new ClientQTenantLifecycleException(
            $dataRemoved
                ? 'Some tenant data was removed, but cleanup did not finish. The waitlist remains unavailable. Retry deletion to finish cleanup.'
                : 'The tenant could not be deleted. No tenant data was removed.',
            500,
            $e
        );
    }
}
