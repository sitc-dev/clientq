<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../src/php/config.php';

requireTenant(true);
$staff = getSessionStaff();
if ($staff === null) {
    session_unset();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
if ($staff['role'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['error' => 'Owner access is required.']);
    exit;
}
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
if (!is_string($input['csrf_token'] ?? null) || !verifyPlatformCsrfToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}
foreach (['theme_primary', 'theme_accent'] as $field) {
    if (!is_string($input[$field] ?? null) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $input[$field])) {
        http_response_code(422);
        echo json_encode(['error' => 'Choose valid primary and accent colors.']);
        exit;
    }
}
$primary = normalizeThemeColor($input['theme_primary'], TENANT_THEME_DEFAULT_PRIMARY);
$accent = normalizeThemeColor($input['theme_accent'], TENANT_THEME_DEFAULT_ACCENT);
$db = getTenantDB();
try {
    $db->beginTransaction();
    if ($db->query('SELECT id FROM business_settings WHERE id = 1 FOR UPDATE')->fetchColumn() === false) {
        throw new RuntimeException('Business settings are missing.');
    }
    $stmt = $db->prepare('UPDATE business_settings SET theme_primary = ?, theme_accent = ? WHERE id = 1');
    $stmt->execute([$primary, $accent]);
    $db->commit();
    echo json_encode(['success' => true, 'theme_primary' => $primary, 'theme_accent' => $accent]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    clientqLog('ClientQ theme update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Business colors could not be saved. Please try again.']);
}
