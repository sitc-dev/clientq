<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../src/php/config.php';
require_once __DIR__ . '/../lib/business-registration.php';
require_once __DIR__ . '/../lib/phone-groups.php';

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

$request = json_decode(file_get_contents('php://input'), true);
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$csrfToken = $request['csrf_token'] ?? null;
if (!verifyPlatformCsrfToken($csrfToken)) {
    clientqLogInvalidSignupToken('confirmation', is_string($csrfToken) && $csrfToken !== '');
    http_response_code(403);
    echo json_encode(['error' => 'Expired website session. Consider refreshing the page.']);
    exit;
}

$paymentSignup = signupPaymentRecord();
if ($paymentSignup !== null && $paymentSignup['provisioned_at'] !== null) {
    $stmt = getDB()->prepare('SELECT slug FROM businesses WHERE id = ?');
    $stmt->execute([$paymentSignup['business_id']]);
    $createdSlug = $stmt->fetchColumn();
    if ($createdSlug !== false) {
        echo json_encode(['success' => true, 'login_url' => tenantUrl($createdSlug, '/admin/login')]);
    } else {
        http_response_code(409);
        echo json_encode(['error' => 'This signup was already completed and its business was removed. Contact ClientQ support.']);
    }
    exit;
}
$input = pendingBusinessRegistration();
if ($input === null) {
    http_response_code(409);
    echo json_encode(['error' => 'Your signup review expired. Return to signup and try again.']);
    exit;
}

if ($paymentSignup === null
    || ($paymentSignup['provider'] === 'paypal' && $paymentSignup['subscription_id'] === null)
    || ($paymentSignup['provider'] === 'stripe' && $paymentSignup['checkout_session_id'] === null)) {
    http_response_code(402);
    echo json_encode(['error' => 'A successful first-month subscription payment is required before creating your account.']);
    exit;
}
try {
    if ($paymentSignup['provider'] === 'paypal') {
        if ($paymentSignup['environment'] !== paypalEnvironment()) {
            throw new RuntimeException('The signup belongs to a different PayPal environment.');
        }
        $paymentId = verifyPayPalSignupPayment($paymentSignup);
    } else {
        $paymentId = verifyStripeSignupPayment($paymentSignup);
    }
    if ($paymentId === null) {
        http_response_code(409);
        echo json_encode(['error' => 'A successful first-month payment has not been confirmed yet. Wait a moment and check payment again; do not pay twice.']);
        exit;
    }
    $paymentTable = signupPaymentTable($paymentSignup);
    $stmt = getDB()->prepare("UPDATE $paymentTable SET first_payment_id = ? WHERE id = ?");
    $stmt->execute([$paymentId, $paymentSignup['id']]);
} catch (Throwable $e) {
    clientqLog('ClientQ signup payment verification failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Unable to verify payment right now. Your account has not been created. Retry verification, not payment.']);
    exit;
}

$businessName = $input['business_name'];
$slug = $input['slug'];
$firstName = $input['first_name'];
$lastName = $input['last_name'];
$phone = $input['phone'];
$ownerName = $input['owner_name'];
$email = $input['email'];
$username = $email;
$plan = $input['plan'];
$appointments = appointmentsEnabled($input);
$expectedPlatformUserId = $input['platform_user_id'];
$db = getDB();
$databaseName = 'clientq_tenant_' . substr(hash('sha256', $slug), 0, 32);
$tenantSchema = file_get_contents(__DIR__ . '/../database/tenant-schema.sql');
$databaseCreated = false;
$registrationCommitted = false;
$ownerPasswordHash = $input['owner_password_hash'];
$logo = $input['logo'] ?? null;
$themePrimary = $input['theme_primary'] ?? TENANT_THEME_DEFAULT_PRIMARY;
$themeAccent = $input['theme_accent'] ?? TENANT_THEME_DEFAULT_ACCENT;
$storedLogoAbsolutePath = null;

if ($tenantSchema === false) {
    clientqLog('ClientQ tenant schema could not be read.');
    http_response_code(500);
    echo json_encode(['error' => 'Unable to create your ClientQ right now.']);
    exit;
}

$lockName = 'clientq-register';
$lockStmt = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
$lockStmt->execute([':lock_name' => $lockName]);
if ((int)$lockStmt->fetchColumn() !== 1) {
    http_response_code(503);
    echo json_encode(['error' => 'Registration is busy. Please try again.']);
    exit;
}

try {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $hourlyLimit = max(1, (int)(getenv('CLIENTQ_SIGNUPS_PER_HOUR') ?: 3));
    $dailyLimit = max(1, (int)(getenv('CLIENTQ_DAILY_SIGNUP_LIMIT') ?: 100));

    $db->exec("DELETE FROM signup_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");

    $ipCountStmt = $db->prepare(
        'SELECT COUNT(*) FROM signup_attempts
         WHERE ip_address = :ip_address AND attempted_at >= NOW() - INTERVAL 1 HOUR'
    );
    $ipCountStmt->execute([':ip_address' => $clientIp]);
    $dailyCount = (int)$db->query(
        'SELECT COUNT(*) FROM businesses WHERE created_at >= CURRENT_DATE'
    )->fetchColumn();

    if ((int)$ipCountStmt->fetchColumn() >= $hourlyLimit || $dailyCount >= $dailyLimit) {
        http_response_code(429);
        echo json_encode(['error' => 'Signup capacity has been reached. Your payment signup is saved. Retry here later without paying again.']);
        exit;
    }

    $attemptStmt = $db->prepare('INSERT INTO signup_attempts (ip_address) VALUES (:ip_address)');
    $attemptStmt->execute([':ip_address' => $clientIp]);

    $existingStmt = $db->prepare(
        'SELECT (SELECT COUNT(*) FROM businesses WHERE slug = :slug)
            + (SELECT COUNT(*) FROM tenant_status_pages WHERE slug = :status_slug)'
    );
    $existingStmt->execute([':slug' => $slug, ':status_slug' => $slug]);
    if ((int)$existingStmt->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'That ClientQ address is now in use. Your payment is saved; contact ClientQ support before starting another signup.']);
        exit;
    }

    $platformUserStmt = $db->prepare(
        'SELECT id, password_hash, role, is_active FROM platform_users WHERE email = :email'
    );
    $platformUserStmt->execute([':email' => $email]);
    $platformUser = $platformUserStmt->fetch();

    $platformUserChanged = $expectedPlatformUserId === null
        ? $platformUser !== false
        : !$platformUser
            || (int)$platformUser['id'] !== $expectedPlatformUserId
            || !hash_equals($ownerPasswordHash, $platformUser['password_hash']);
    if ($platformUserChanged
        || ($platformUser
            && ((int)$platformUser['is_active'] !== 1
                || $platformUser['role'] !== 'business_owner'))) {
        http_response_code(409);
        echo json_encode([
            'error' => 'The owner account changed while you were reviewing. Your payment is saved; contact ClientQ support before starting another signup.',
        ]);
        exit;
    }

    $db->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $databaseCreated = true;

    $tenantDb = createDatabaseConnection($databaseName);
    $tenantDb->exec($tenantSchema);

    $staffStmt = $tenantDb->prepare(
        'INSERT INTO staff
            (name, first_name, last_name, phone, email, goes_by, position, experience, username, password_hash, role)
         VALUES
            (:name, :first_name, :last_name, :phone, :email, :goes_by, :position, :experience, :username, :password_hash, :role)'
    );
    $staffStmt->execute([
        ':name' => $ownerName,
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':phone' => $phone,
        ':email' => $email,
        ':goes_by' => $firstName,
        ':position' => 'Owner',
        ':experience' => '',
        ':username' => $username,
        ':password_hash' => $ownerPasswordHash,
        ':role' => 'owner',
    ]);

    if (is_array($logo)) {
        $pendingLogoPath = pendingBusinessLogoPath($logo);
        if ($pendingLogoPath === null || !is_file($pendingLogoPath)) {
            throw new RuntimeException('The staged business logo is unavailable.');
        }

        $relativeDirectory = 'uploads/' . $slug;
        $absoluteDirectory = dirname(__DIR__) . '/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('The business logo directory could not be created.');
        }

        $filename = 'logo-' . bin2hex(random_bytes(12)) . '.' . $logo['extension'];
        $relativeLogoPath = $relativeDirectory . '/' . $filename;
        $storedLogoAbsolutePath = $absoluteDirectory . '/' . $filename;
        if (!copy($pendingLogoPath, $storedLogoAbsolutePath)) {
            throw new RuntimeException('The business logo could not be stored.');
        }

        $settingsStmt = $tenantDb->prepare(
            'UPDATE business_settings
             SET logo_path = :logo_path, theme_primary = :theme_primary, theme_accent = :theme_accent
             WHERE id = 1'
        );
        $settingsStmt->execute([
            ':logo_path' => $relativeLogoPath,
            ':theme_primary' => $themePrimary,
            ':theme_accent' => $themeAccent,
        ]);
    }

    $db->beginTransaction();
    $businessStmt = $db->prepare(
        'INSERT INTO businesses (name, slug, owner_email, database_name, plan, appointments_enabled)
         VALUES (:name, :slug, :email, :database_name, :plan, :appointments_enabled)'
    );
    $businessStmt->execute([
        ':name' => $businessName,
        ':slug' => $slug,
        ':email' => $email,
        ':database_name' => $databaseName,
        ':plan' => $plan,
        ':appointments_enabled' => (int)$appointments,
    ]);
    $businessId = (int)$db->lastInsertId();
    $phoneGroup = assignBusinessPhoneGroup($db, $businessId);

    if ($platformUser) {
        $platformUserId = (int)$platformUser['id'];
        $accountStmt = $db->prepare(
            'UPDATE platform_users
             SET name = :name, first_name = :first_name, last_name = :last_name, phone = :phone
             WHERE id = :id'
        );
        $accountStmt->execute([
            ':name' => $ownerName,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':phone' => $phone,
            ':id' => $platformUserId,
        ]);
    } else {
        $accountStmt = $db->prepare(
            "INSERT INTO platform_users
                (name, first_name, last_name, phone, email, password_hash, role)
             VALUES
                (:name, :first_name, :last_name, :phone, :email, :password_hash, 'business_owner')"
        );
        $accountStmt->execute([
            ':name' => $ownerName,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':phone' => $phone,
            ':email' => $email,
            ':password_hash' => $ownerPasswordHash,
        ]);
        $platformUserId = (int)$db->lastInsertId();
    }

    $ownerStmt = $db->prepare(
        'INSERT INTO business_owners (business_id, user_id) VALUES (:business_id, :user_id)'
    );
    $ownerStmt->execute([
        ':business_id' => $businessId,
        ':user_id' => $platformUserId,
    ]);
    $paymentStmt = $db->prepare(
        "UPDATE $paymentTable SET business_id = ?, pending_registration = NULL,
            provisioned_at = CURRENT_TIMESTAMP WHERE id = ? AND provisioned_at IS NULL"
    );
    $paymentStmt->execute([$businessId, $paymentSignup['id']]);
    $db->commit();
    $registrationCommitted = true;
    clearPendingBusinessRegistration();
    clientqLog('ClientQ ' . $paymentSignup['provider'] . ' signup provisioned: business ' . $businessId
        . ', signup ' . $paymentSignup['id'] . ', phone group ' . $phoneGroup . '.', level: 'INFO');

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'login_url' => tenantUrl($slug, '/admin/login'),
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (!$registrationCommitted && $storedLogoAbsolutePath !== null && is_file($storedLogoAbsolutePath)) {
        unlink($storedLogoAbsolutePath);
    }
    if (!$registrationCommitted && $databaseCreated) {
        try {
            $db->exec("DROP DATABASE IF EXISTS `$databaseName`");
        } catch (ClientQDatabaseException $cleanupError) {
            clientqLog('ClientQ tenant cleanup failed: ' . $cleanupError->getMessage());
        }
    }

    if ($e instanceof ClientQDatabaseException && $e->mysqlErrorNumber === 1062) {
        http_response_code(409);
        echo json_encode(['error' => 'That ClientQ address is already in use. Your payment is saved; contact ClientQ support.']);
        exit;
    }

    clientqLog('ClientQ registration failed: ' . $e->getMessage());
    if ($e instanceof DomainException) {
        http_response_code(503);
        echo json_encode(['error' => 'No SMS phone number is available right now. Your payment signup is saved. Retry here without paying again.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['error' => 'Unable to create your ClientQ right now. Your payment signup is saved. Retry here without paying again.']);
} finally {
    $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $releaseStmt->execute([':lock_name' => $lockName]);
}
