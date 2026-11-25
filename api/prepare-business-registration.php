<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/business-registration.php';

if (!isPlatformHost()) {
    http_response_code(404);
    echo json_encode(['error' => 'Business registration is only available on ' . BASE_DOMAIN . '.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = str_starts_with($contentType, 'multipart/form-data')
    ? $_POST
    : json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$csrfToken = $input['csrf_token'] ?? null;
if (!verifyPlatformCsrfToken($csrfToken)) {
    clientqLogInvalidSignupToken('review', is_string($csrfToken) && $csrfToken !== '');
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}

try {
    $existingSignup = signupPaymentRecord();
    if ($existingSignup !== null && $existingSignup['pending_registration'] !== null) {
        http_response_code(409);
        echo json_encode(['error' => 'A payment signup is already in progress. Return to signup confirmation to finish it without paying again.']);
        exit;
    }
    $registration = validateBusinessRegistrationInput($input);
    $db = getDB();

    $existingStmt = $db->prepare(
        'SELECT (SELECT COUNT(*) FROM businesses WHERE slug = :slug)
            + (SELECT COUNT(*) FROM tenant_status_pages WHERE slug = :status_slug)'
    );
    $existingStmt->execute([':slug' => $registration['slug'], ':status_slug' => $registration['slug']]);
    if ((int)$existingStmt->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'That ClientQ address is already in use.']);
        exit;
    }

    $platformUserStmt = $db->prepare(
        'SELECT id, password_hash, role, is_active FROM platform_users WHERE email = :email'
    );
    $platformUserStmt->execute([':email' => $registration['email']]);
    $platformUser = $platformUserStmt->fetch();

    if ($platformUser
        && ((int)$platformUser['is_active'] !== 1
            || $platformUser['role'] !== 'business_owner'
            || !password_verify($registration['password'], $platformUser['password_hash']))) {
        http_response_code(409);
        echo json_encode([
            'error' => 'An account already exists for that email. Use the existing account password or another email.',
        ]);
        exit;
    }

    unset($registration['password']);
    $registration['owner_password_hash'] = $platformUser
        ? $platformUser['password_hash']
        : password_hash($input['password'], PASSWORD_DEFAULT);
    $registration['platform_user_id'] = $platformUser ? (int)$platformUser['id'] : null;
    $registration['theme_primary'] = normalizeThemeColor(
        $input['theme_primary'] ?? null,
        TENANT_THEME_DEFAULT_PRIMARY
    );
    $registration['theme_accent'] = normalizeThemeColor(
        $input['theme_accent'] ?? null,
        TENANT_THEME_DEFAULT_ACCENT
    );
    $registration['logo'] = null;
    $registration['created_at'] = time();

    $logo = $_FILES['logo'] ?? null;
    if (is_array($logo) && ($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $registration['logo'] = stagePendingBusinessLogo($logo);
    }

    clearPendingBusinessRegistration();
    unset($_SESSION['paypal_signup_id'], $_SESSION['stripe_signup_id']);
    $_SESSION['pending_business_registration'] = $registration;

    echo json_encode([
        'success' => true,
        'confirmation_url' => platformUrl('/signup-confirmation'),
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    clientqLog('ClientQ registration preparation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to review your ClientQ signup right now.']);
}
