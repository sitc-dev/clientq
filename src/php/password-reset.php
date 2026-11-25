<?php

if (!isset($resetPortal, $resetMode)) {
    http_response_code(404);
    exit('Page not found.');
}

require_once dirname(__DIR__, 2) . '/lib/password-reset.php';
require_once __DIR__ . '/mail.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$target = passwordResetTarget($resetPortal);
$prefix = match ($resetPortal) {
    'tenant' => '/admin',
    'owner' => '/account',
    'admin' => '/platform-admin',
};
$db = $resetPortal === 'tenant' ? getTenantDB() : getDB();
$title = $resetMode === 'request' ? 'Forgot password?' : 'Reset password';
$error = '';
$notice = '';
$canReset = false;
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';

try {
    if ($resetMode === 'reset') {
        $canReset = passwordResetAccount($db, $resetPortal, $token) !== null;
        if (!$canReset) {
            http_response_code(400);
            $error = 'This reset link is invalid or expired. Request a new one.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? null;
        if (!is_string($csrf) || !verifyPlatformCsrfToken($csrf)) {
            http_response_code(403);
            $error = 'Your request could not be verified. Reload the page and try again.';
        } elseif ($resetMode === 'request') {
            $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                http_response_code(422);
                $error = 'Enter the email address used for this login.';
            } elseif (passwordResetIsRateLimited(getDB(), $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
                http_response_code(429);
                $error = 'Too many reset requests. Try again in one hour.';
            } elseif (SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASS === '') {
                clientqLog('ClientQ password reset failed: SMTP is not configured.');
                http_response_code(503);
                $error = 'Password reset email is temporarily unavailable. Contact your administrator.';
            } else {
                $resetUrl = $resetPortal === 'tenant'
                    ? tenantUrl(requireTenant()['slug'], $prefix . '/reset-password')
                    : platformUrl($prefix . '/reset-password');
                requestPasswordReset($db, $resetPortal, $email, $resetUrl, 'sendEmail');
                $notice = 'If an active account matches that email for this portal, a reset link has been sent. Check your inbox and spam folder.';
            }
        } elseif ($canReset) {
            $password = $_POST['password'] ?? null;
            $confirmation = $_POST['password_confirmation'] ?? null;
            if (!is_string($password) || strlen($password) < 8 || strlen($password) > 255) {
                http_response_code(422);
                $error = 'Password must be between 8 and 255 characters.';
            } elseif (str_contains($password, "\0")) {
                http_response_code(422);
                $error = 'Password cannot contain null characters.';
            } elseif ($password !== $confirmation) {
                http_response_code(422);
                $error = 'The passwords do not match.';
            } elseif (!completePasswordReset($db, $resetPortal, $token, $password)) {
                http_response_code(400);
                $canReset = false;
                $error = 'This reset link is invalid or expired. Request a new one.';
            } else {
                header('Location: ' . $prefix . '/login?password_reset=1', true, 303);
                exit;
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        $error = 'Method not allowed.';
    }
} catch (ClientQDatabaseException | RuntimeException $e) {
    clientqLog('ClientQ password reset failed: ' . $e->getMessage());
    http_response_code(503);
    $error = 'Password reset is temporarily unavailable. Please try again later.';
}
$csrfToken = platformCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title . ' - ' . BUSINESS_NAME) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: #f3f4f6; color: #172554; font-family: system-ui, sans-serif; }
        main { width: min(100%, 28rem); padding: 2rem; background: white; border-radius: 1rem; box-shadow: 0 10px 25px #0001; }
        h1 { font-size: 1.5rem; }
        p { line-height: 1.5; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input, button { width: 100%; padding: .8rem; margin-top: .5rem; border: 1px solid #cbd5e1; border-radius: .5rem; font: inherit; }
        button { margin-top: 1.5rem; background: #1d4ed8; color: white; cursor: pointer; }
        a { color: #1d4ed8; }
        .error { padding: .8rem; background: #fee2e2; color: #991b1b; border-radius: .5rem; }
        .notice { padding: .8rem; background: #dcfce7; color: #166534; border-radius: .5rem; }
    </style>
</head>
<body>
<main>
    <p><?= htmlspecialchars(BUSINESS_NAME . ' ' . $target['label']) ?></p>
    <h1><?= htmlspecialchars($title) ?></h1>
    <?php if ($error): ?><p role="alert" class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p role="status" class="notice"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($resetMode === 'request'): ?>
        <p>Enter your login email to receive a single-use link that expires in 30 minutes.</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label for="email">Login email</label>
            <input id="email" name="email" type="email" required maxlength="255" autocomplete="email">
            <button type="submit">Send reset link</button>
        </form>
    <?php elseif ($canReset): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" required minlength="8" maxlength="255" autocomplete="new-password">
            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" maxlength="255" autocomplete="new-password">
            <button type="submit">Reset password</button>
        </form>
    <?php endif; ?>
    <?php if ($resetMode === 'reset'): ?><p><a href="<?= htmlspecialchars($prefix . '/forgot-password') ?>">Request a new reset link</a></p><?php endif; ?>
    <p><a href="<?= htmlspecialchars($prefix . '/login') ?>">Back to login</a></p>
</main>
</body>
</html>
