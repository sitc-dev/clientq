<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../src/php/config.php';
require_once __DIR__ . '/../../lib/logo-upload.php';

$tenant = requireTenant(true);
$staffAccount = getSessionStaff();

if ($staffAccount === null) {
    session_unset();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

if ($staffAccount['role'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['error' => 'Owner access is required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'Select a logo to upload.']);
    exit;
}

try {
    $validatedLogo = validateLogoUpload($_FILES['logo']);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$themePrimary = normalizeThemeColor($_POST['theme_primary'] ?? null, '#5B2A86');
$themeAccent = normalizeThemeColor($_POST['theme_accent'] ?? null, '#E94B35');

$relativeDirectory = 'uploads/' . $tenant['slug'];
$absoluteDirectory = dirname(__DIR__, 2) . '/' . $relativeDirectory;
if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true)) {
    clientqLog('ClientQ could not create logo directory: ' . $absoluteDirectory);
    http_response_code(500);
    echo json_encode(['error' => 'The logo could not be stored.']);
    exit;
}

$filename = 'logo-' . bin2hex(random_bytes(12)) . '.' . $validatedLogo['extension'];
$relativePath = $relativeDirectory . '/' . $filename;
$absolutePath = $absoluteDirectory . '/' . $filename;

if (!move_uploaded_file($validatedLogo['temporary_path'], $absolutePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'The logo could not be stored.']);
    exit;
}

$db = getTenantDB();

try {
    $db->beginTransaction();
    $settingsStmt = $db->query('SELECT logo_path FROM business_settings WHERE id = 1 FOR UPDATE');
    $oldPath = $settingsStmt->fetchColumn();
    $stmt = $db->prepare(
        'UPDATE business_settings
         SET logo_path = :logo_path, theme_primary = :theme_primary, theme_accent = :theme_accent
         WHERE id = 1'
    );
    $stmt->execute([
        ':logo_path' => $relativePath,
        ':theme_primary' => $themePrimary,
        ':theme_accent' => $themeAccent,
    ]);
    $db->commit();
} catch (ClientQDatabaseException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    unlink($absolutePath);
    clientqLog('ClientQ logo update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The logo could not be saved.']);
    exit;
}

if (is_string($oldPath) && str_starts_with($oldPath, $relativeDirectory . '/')) {
    $oldAbsolutePath = dirname(__DIR__, 2) . '/' . $oldPath;
    if (is_file($oldAbsolutePath)) {
        unlink($oldAbsolutePath);
    }
}

echo json_encode([
    'success' => true,
    'logo_url' => '/' . $relativePath,
    'theme_primary' => $themePrimary,
    'theme_accent' => $themeAccent,
]);
