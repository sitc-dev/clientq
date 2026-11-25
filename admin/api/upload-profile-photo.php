<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/logo-upload.php';
require_once __DIR__ . '/../../lib/staff-profile.php';

$tenant = requireTenant(true);
$staff = getSessionStaff();
if ($staff === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
if (isset($_POST['id']) || isset($_POST['staff_id']) || isset($_POST['business_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'You can upload only your own profile photo.']);
    exit;
}
if (!is_string($_POST['csrf_token'] ?? null) || !verifyPlatformCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}
try {
    $image = validateImageUpload($_FILES['photo'] ?? [], 'Profile photo');
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
    exit;
}

$directory = 'uploads/' . $tenant['slug'];
$absoluteDirectory = dirname(__DIR__, 2) . '/' . $directory;
if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true)) {
    clientqLog('ClientQ profile photo upload failed: could not create directory ' . $absoluteDirectory);
    http_response_code(500);
    echo json_encode(['error' => 'The profile photo could not be stored.']);
    exit;
}
$path = $directory . '/profile-' . (int)$staff['id'] . '-' . bin2hex(random_bytes(12)) . '.' . $image['extension'];
$absolutePath = dirname(__DIR__, 2) . '/' . $path;
if (!move_uploaded_file($image['temporary_path'], $absolutePath)) {
    clientqLog('ClientQ profile photo upload failed: could not store uploaded image.');
    http_response_code(500);
    echo json_encode(['error' => 'The profile photo could not be stored.']);
    exit;
}

$db = getTenantDB();
try {
    $db->beginTransaction();
    $select = $db->prepare('SELECT id, profile_pic FROM staff WHERE id = :id AND is_active = 1 AND password_version = :version FOR UPDATE');
    $select->execute([':id' => $staff['id'], ':version' => $staff['password_version']]);
    $oldStaff = $select->fetch();
    if (!$oldStaff) {
        $db->rollBack();
        unlink($absolutePath);
        http_response_code(409);
        echo json_encode(['error' => 'Your account changed. Log in again before uploading.']);
        exit;
    }
    $update = $db->prepare('UPDATE staff SET profile_pic = :path WHERE id = :id');
    $update->execute([':path' => $path, ':id' => $staff['id']]);
    $db->commit();
} catch (ClientQDatabaseException $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    unlink($absolutePath);
    clientqLog('ClientQ profile photo upload failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The profile photo could not be saved.']);
    exit;
}

$oldUrl = staffProfilePhotoUrl($oldStaff, $tenant['slug']);
if ($oldUrl !== null && is_file(dirname(__DIR__, 2) . $oldUrl)) {
    if (!unlink(dirname(__DIR__, 2) . $oldUrl)) {
        clientqLog('ClientQ profile photo cleanup failed: ' . $oldUrl, level: 'WARNING');
    }
}
echo json_encode(['success' => true, 'photo_url' => '/' . $path]);
