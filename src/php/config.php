<?php

require_once dirname(__DIR__, 2) . '/lib/database-errors.php';
require_once dirname(__DIR__, 2) . '/lib/staff-access.php';

define('DB_HOST', getenv('CLIENTQ_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('CLIENTQ_DB_PORT') ?: '3306');
define('DB_NAME', getenv('CLIENTQ_DB_NAME') ?: 'clientq');
define('DB_USER', getenv('CLIENTQ_DB_USER') ?: 'root');
define('DB_PASS', getenv('CLIENTQ_DB_PASS') ?: '');
define('BASE_DOMAIN', strtolower(getenv('CLIENTQ_BASE_DOMAIN') ?: 'clientq.local'));
define('CLIENTS_PER_PAGE', 25);
define('GALLEY_CLIENTS_PER_PAGE', 10);
define('TENANT_THEME_DEFAULT_PRIMARY', '#111827');
define('TENANT_THEME_DEFAULT_ACCENT', '#DC2626');
define('TENANT_THEME_DEFAULT_SOFT', '#F3F4F6');

function createDatabaseConnection(string $databaseName = ''): PDO {
    $databasePart = $databaseName === '' ? '' : ';dbname=' . $databaseName;

    return connectToMysql(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . $databasePart . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
        $databaseName === '' ? 'MySQL server' : "database '$databaseName'"
    );
}

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $pdo = createDatabaseConnection(DB_NAME);
        } catch (ClientQDatabaseConnectionException $e) {
            http_response_code(500);
            exit('ClientQ is temporarily unavailable.');
        }
    }

    return $pdo;
}

function getTenantDB(): PDO {
    static $connections = [];
    $tenant = requireTenant();
    $databaseName = $tenant['database_name'];

    if (!preg_match('/^clientq_tenant_[a-z0-9_]+$/', $databaseName)) {
        throw new RuntimeException('Invalid tenant database configuration.');
    }

    if (!isset($connections[$databaseName])) {
        try {
            $connections[$databaseName] = createDatabaseConnection($databaseName);
        } catch (ClientQDatabaseConnectionException $e) {
            http_response_code(500);
            exit('ClientQ is temporarily unavailable.');
        }
    }

    return $connections[$databaseName];
}

function requestHost(): string {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? BASE_DOMAIN);
    return preg_replace('/:\d+$/', '', $host);
}

function isPlatformHost(): bool {
    $host = requestHost();
    return $host === BASE_DOMAIN || $host === 'www.' . BASE_DOMAIN;
}

function requirePlatformHost(bool $json = false): void {
    if (isPlatformHost()) {
        return;
    }

    http_response_code(404);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Page not found.']);
    } else {
        echo 'Page not found.';
    }
    exit;
}

function platformUrl(string $path = '/'): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $port = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_PORT);
    $portSuffix = $port ? ':' . $port : '';

    return $scheme . '://' . BASE_DOMAIN . $portSuffix . $path;
}

function getPlatformSessionUser(): ?array {
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }

    $loaded = true;
    if (!isset($_SESSION['platform_user_id'])) {
        return null;
    }

    $stmt = getDB()->prepare(
        'SELECT id, name, email, role, password_version FROM platform_users WHERE id = :id AND is_active = 1'
    );
    $stmt->execute([':id' => $_SESSION['platform_user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user !== null && (int)($_SESSION['platform_password_version'] ?? 0) !== (int)$user['password_version']) {
        $user = null;
    }

    return $user;
}

function requirePlatformRole(string $role, bool $json = false): array {
    requirePlatformHost($json);
    $user = getPlatformSessionUser();

    if ($user !== null && $user['role'] === $role) {
        return $user;
    }

    if ($user === null) {
        session_unset();
    }

    http_response_code($user === null ? 401 : 403);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $user === null ? 'Not authenticated.' : 'Access denied.']);
    } else {
        $loginPath = $role === 'platform_owner' ? '/platform-admin/login' : '/account/login';
        if ($user === null) {
            header('Location: ' . $loginPath);
        } else {
            echo 'Access denied.';
        }
    }
    exit;
}

function platformCsrfToken(): string {
    if (empty($_SESSION['platform_csrf_token'])) {
        $_SESSION['platform_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['platform_csrf_token'];
}

function verifyPlatformCsrfToken(?string $token): bool {
    return is_string($token)
        && isset($_SESSION['platform_csrf_token'])
        && hash_equals($_SESSION['platform_csrf_token'], $token);
}

function platformLoginIsRateLimited(string $email, string $role): bool {
    $db = getDB();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $emailHash = hash('sha256', strtolower(trim($email)));
    $db->exec("DELETE FROM platform_login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");

    $stmt = $db->prepare(
        "SELECT
            SUM(ip_address = :ip_count) AS ip_attempts,
            SUM(email_hash = :email_count AND role = :role_count) AS account_attempts
         FROM platform_login_attempts
         WHERE attempted_at >= NOW() - INTERVAL 15 MINUTE
           AND (ip_address = :ip_filter
                OR (email_hash = :email_filter AND role = :role_filter))"
    );
    $stmt->execute([
        ':ip_count' => $ipAddress,
        ':email_count' => $emailHash,
        ':role_count' => $role,
        ':ip_filter' => $ipAddress,
        ':email_filter' => $emailHash,
        ':role_filter' => $role,
    ]);
    $attempts = $stmt->fetch();

    return (int)($attempts['ip_attempts'] ?? 0) >= 10
        || (int)($attempts['account_attempts'] ?? 0) >= 5;
}

function recordPlatformLoginFailure(string $email, string $role): void {
    $stmt = getDB()->prepare(
        'INSERT INTO platform_login_attempts (ip_address, email_hash, role)
         VALUES (:ip_address, :email_hash, :role)'
    );
    $stmt->execute([
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ':email_hash' => hash('sha256', strtolower(trim($email))),
        ':role' => $role,
    ]);
}

function clearPlatformLoginFailures(string $email, string $role): void {
    $stmt = getDB()->prepare(
        'DELETE FROM platform_login_attempts WHERE email_hash = :email_hash AND role = :role'
    );
    $stmt->execute([
        ':email_hash' => hash('sha256', strtolower(trim($email))),
        ':role' => $role,
    ]);
}

function formatMoney(int $amountCents, string $currency = 'usd'): string {
    return strtoupper($currency) . ' ' . number_format($amountCents / 100, 2);
}

function planDisplayName(string $plan): string {
    return match ($plan) {
        'starter' => 'Essentials',
        'growth' => 'Select',
        'pro' => 'Premier',
        default => throw new InvalidArgumentException('Unknown ClientQ plan.'),
    };
}

function planMonthlyAmount(string $plan, bool $appointments = false): int {
    $amount = match ($plan) {
        'starter' => 3900,
        'growth' => 6400,
        'pro' => 9900,
        default => throw new InvalidArgumentException('Unknown ClientQ plan.'),
    };
    return $amount + ($plan === 'starter' && $appointments ? 500 : 0);
}

function appointmentsEnabled(array $tenant): bool {
    return match ($tenant['plan']) {
        'starter' => (int)($tenant['appointments_enabled'] ?? 0) === 1,
        'growth', 'pro' => true,
        default => throw new InvalidArgumentException('Unknown ClientQ plan.'),
    };
}

function requestedTenantSlug(): ?string {
    $host = requestHost();
    $suffix = '.' . BASE_DOMAIN;

    if (isPlatformHost() || !str_ends_with($host, $suffix)) {
        return null;
    }

    $slug = substr($host, 0, -strlen($suffix));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) ? $slug : null;
}

function getTenant(): ?array {
    static $resolved = false;
    static $tenant = null;

    if ($resolved) {
        return $tenant;
    }

    $resolved = true;
    $slug = requestedTenantSlug();
    if ($slug === null) {
        return null;
    }

    $stmt = getDB()->prepare(
        'SELECT id, name, slug, owner_email, database_name, plan, appointments_enabled FROM businesses WHERE slug = :slug AND is_active = 1'
    );
    $stmt->execute([':slug' => $slug]);
    $tenant = $stmt->fetch() ?: null;

    return $tenant;
}

function requireTenant(bool $json = false): array {
    $tenant = getTenant();
    if ($tenant !== null) {
        return $tenant;
    }

    $slug = requestedTenantSlug();
    if ($slug !== null) {
        $stmt = getDB()->prepare(
            'SELECT business_name, logo_path, theme_primary, status FROM tenant_status_pages WHERE slug = :slug'
        );
        $stmt->execute([':slug' => $slug]);
        $tenantStatusPage = $stmt->fetch();
        if ($tenantStatusPage) {
            http_response_code($tenantStatusPage['status'] === 'deleted' ? 410 : 503);
            header('Cache-Control: no-store');
            if ($json) {
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => $tenantStatusPage['status'] === 'deleted'
                        ? 'This waitlist is no longer available.' : 'This waitlist is currently inactive.',
                    'tenant_status' => $tenantStatusPage['status'],
                ]);
            } else {
                require __DIR__ . '/tenant-unavailable.php';
            }
            exit;
        }
    }

    http_response_code(404);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Business not found.']);
    } else {
        echo '<!doctype html><title>Business not found</title><h1>Business not found</h1><p>Check the ClientQ subdomain and try again.</p>';
    }
    exit;
}

function tenantId(): int {
    return (int)requireTenant()['id'];
}

function tenantUrl(string $slug, string $path = '/'): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $port = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_PORT);
    $portSuffix = $port ? ':' . $port : '';

    return $scheme . '://' . $slug . '.' . BASE_DOMAIN . $portSuffix . $path;
}

function getBusinessSettings(): array {
    static $settings = null;

    if ($settings === null) {
        $stmt = getTenantDB()->query(
            'SELECT logo_path, theme_primary, theme_accent FROM business_settings WHERE id = 1'
        );
        $settings = $stmt->fetch() ?: [
            'logo_path' => null,
            'theme_primary' => TENANT_THEME_DEFAULT_PRIMARY,
            'theme_accent' => TENANT_THEME_DEFAULT_ACCENT,
        ];
    }

    return $settings;
}

function businessLogoUrl(): ?string {
    $path = getBusinessSettings()['logo_path'] ?? null;
    return is_string($path) && $path !== '' ? '/' . ltrim($path, '/') : null;
}

function normalizeThemeColor(?string $color, string $fallback): string {
    $normalized = is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)
        ? strtoupper($color)
        : $fallback;
    $rgb = [
        hexdec(substr($normalized, 1, 2)),
        hexdec(substr($normalized, 3, 2)),
        hexdec(substr($normalized, 5, 2)),
    ];

    while (themeContrastWithWhite($rgb) < 4.5) {
        $rgb = array_map(static fn($channel) => (int)round($channel * 0.88), $rgb);
    }

    return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
}

function themeContrastWithWhite(array $rgb): float {
    $channels = array_map(static function ($channel) {
        $value = $channel / 255;
        return $value <= 0.03928
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }, $rgb);
    $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

    return 1.05 / ($luminance + 0.05);
}

function mixThemeColor(string $color, string $mixWith, float $amount): string {
    $color = ltrim($color, '#');
    $mixWith = ltrim($mixWith, '#');
    $parts = [];

    for ($i = 0; $i < 3; $i++) {
        $base = hexdec(substr($color, $i * 2, 2));
        $mix = hexdec(substr($mixWith, $i * 2, 2));
        $parts[] = str_pad(dechex((int)round($base + ($mix - $base) * $amount)), 2, '0', STR_PAD_LEFT);
    }

    return '#' . strtoupper(implode('', $parts));
}

function businessTheme(): array {
    $settings = getBusinessSettings();
    $primary = normalizeThemeColor(
        $settings['theme_primary'] ?? null,
        TENANT_THEME_DEFAULT_PRIMARY
    );
    $accent = normalizeThemeColor(
        $settings['theme_accent'] ?? null,
        TENANT_THEME_DEFAULT_ACCENT
    );
    $usesDefaultPalette = $primary === TENANT_THEME_DEFAULT_PRIMARY
        && $accent === TENANT_THEME_DEFAULT_ACCENT;

    return [
        'primary' => $primary,
        'accent' => $accent,
        'accent_dark' => mixThemeColor($accent, '#000000', 0.18),
        'soft' => $usesDefaultPalette
            ? TENANT_THEME_DEFAULT_SOFT
            : mixThemeColor($accent, '#FFFFFF', 0.86),
        'primary_soft' => mixThemeColor($primary, '#FFFFFF', 0.88),
    ];
}

function staffDisplayName(array $staff): string {
    $nickname = trim($staff['goes_by'] ?? '');
    if ($nickname !== '') {
        return $nickname;
    }

    $firstName = strtok(trim($staff['name']), " \t\n\r\0\x0B");
    return $firstName === false ? '' : $firstName;
}

function getSessionStaff(): ?array {
    static $loaded = false;
    static $staff = null;

    if ($loaded) {
        return $staff;
    }

    $loaded = true;
    if (!isset($_SESSION['staff_id'], $_SESSION['business_id'])) {
        return null;
    }

    if ((int)$_SESSION['business_id'] !== tenantId()) {
        return null;
    }

    $stmt = getTenantDB()->prepare('SELECT * FROM staff WHERE id = :id AND is_active = 1');
    $stmt->execute([':id' => $_SESSION['staff_id']]);
    $staff = $stmt->fetch() ?: null;
    if ($staff !== null && (int)($_SESSION['staff_password_version'] ?? 0) !== (int)$staff['password_version']) {
        $staff = null;
    }
    if ($staff !== null && !empty($staff['must_change_password'])) {
        $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        $path = preg_replace('/\.php$/', '', $path);
        if (!in_array($path, ['/admin/login', '/admin/logout', '/admin/set-password', '/admin/api/change-password'], true)) {
            header('Cache-Control: no-store');
            if (str_starts_with($path, '/admin/api/')) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Set a new password before using the staff portal.',
                    'password_change_required' => true,
                ]);
            } else {
                header('Location: /admin/set-password');
            }
            exit;
        }
    }

    return $staff;
}

$currentTenant = getTenant();
define('BUSINESS_NAME', $currentTenant['name'] ?? 'ClientQ');
