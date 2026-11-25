<?php

session_start();
require_once __DIR__ . '/../src/php/config.php';
requirePlatformHost();

$existingUser = getPlatformSessionUser();
if ($existingUser && $existingUser['role'] === 'platform_owner') {
    header('Location: dashboard');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    if (platformLoginIsRateLimited($email, 'platform_owner')) {
        clientqLogFailedLogin($email, 'platform_owner', 'rate_limited');
        $error = 'Too many login attempts. Try again in 15 minutes.';
    } else {
        $stmt = getDB()->prepare(
            "SELECT id, password_hash, password_version FROM platform_users
             WHERE email = :email AND role = 'platform_owner' AND is_active = 1"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            clearPlatformLoginFailures($email, 'platform_owner');
            session_regenerate_id(true);
            $_SESSION['platform_user_id'] = (int)$user['id'];
            $_SESSION['platform_password_version'] = (int)$user['password_version'];
            $_SESSION['platform_csrf_token'] = bin2hex(random_bytes(32));
            header('Location: dashboard');
            exit;
        }

        recordPlatformLoginFailure($email, 'platform_owner');
        clientqLogFailedLogin($email, 'platform_owner', 'invalid_credentials');
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Admin - ClientQ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/assets/password-visibility.js" defer></script>
</head>
<body class="min-h-screen bg-slate-200 text-slate-900 flex items-center justify-center p-4">
    <main class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden">
        <div class="bg-slate-900 text-white p-7">
            <p class="text-slate-300 text-sm">ClientQ</p>
            <h1 class="text-2xl font-bold mt-2">Platform administration</h1>
        </div>
        <?php if (isset($_GET['password_reset'])): ?>
        <div role="status" class="bg-green-100 text-green-800 px-5 py-3">Password reset. Log in with your new password.</div>
        <?php endif; ?>
        <?php if ($error): ?><div class="bg-red-50 text-red-700 px-5 py-3" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-semibold mb-1">Email</label>
                <input type="email" name="email" required autocomplete="email"
                    class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label for="password" class="block text-sm font-semibold mb-1">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                    class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button class="w-full bg-slate-900 hover:bg-slate-800 text-white rounded-lg py-3 font-semibold">Log in</button>
        </form>
        <p class="px-6 pb-6 text-center text-sm"><a href="forgot-password" class="text-slate-700 hover:underline">Forgot password?</a></p>
    </main>
</body>
</html>
