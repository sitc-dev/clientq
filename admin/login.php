<?php
/**
 * Admin Login Page
 */
session_start();
require_once __DIR__ . '/../src/php/config.php';
$tenant = requireTenant();
$businessId = (int)$tenant['id'];

// If already logged in, redirect to dashboard
$existingStaff = getSessionStaff();
if ($existingStaff !== null) {
    header('Location: ' . (!empty($existingStaff['must_change_password']) ? 'set-password' : 'dashboard'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginEmail = $_POST['email'] ?? $_POST['username'] ?? '';
    $username = is_string($loginEmail) ? strtolower(trim($loginEmail)) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!filter_var($username, FILTER_VALIDATE_EMAIL) || empty($password)) {
        clientqLogFailedLogin(
            $username,
            'tenant_staff',
            'missing_credentials',
            (string)$tenant['slug']
        );
        $error = 'Please enter your email address and password.';
    } else {
        $db = getTenantDB();
        $stmt = $db->prepare("SELECT id, goes_by, username, password_hash, password_version, must_change_password, role FROM staff WHERE username = :username AND is_active = 1");
        $stmt->execute([':username' => $username]);
        $staff = $stmt->fetch();

        if ($staff && password_verify($password, $staff['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['staff_id'] = $staff['id'];
            $_SESSION['business_id'] = $businessId;
            $_SESSION['username'] = $staff['username'];
            $_SESSION['goes_by'] = $staff['goes_by'];
            $_SESSION['role'] = $staff['role'];
            $_SESSION['staff_password_version'] = (int)$staff['password_version'];
            header('Location: ' . (!empty($staff['must_change_password']) ? 'set-password' : 'dashboard'));
            exit;
        } else {
            clientqLogFailedLogin(
                $username,
                'tenant_staff',
                'invalid_credentials',
                (string)$tenant['slug']
            );
            $error = 'Invalid email or password.';
        }
    }
}

$businessName = BUSINESS_NAME;
$logoUrl = businessLogoUrl();
$theme = businessTheme();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - <?= htmlspecialchars($businessName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/assets/password-visibility.js" defer></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                primary: '<?= $theme['primary'] ?>',
                accent: '<?= $theme['accent'] ?>',
                accentDark: '<?= $theme['accent_dark'] ?>',
                soft: '<?= $theme['soft'] ?>',
                primarySoft: '<?= $theme['primary_soft'] ?>'
            } } }
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-xl shadow-lg overflow-hidden">

        <div class="bg-primary text-white p-6 text-center">
            <?php if ($logoUrl): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($businessName) ?> logo"
                    class="mx-auto mb-3 h-20 w-20 rounded-lg bg-white object-contain p-2">
            <?php endif; ?>
            <h1 class="text-xl font-bold"><?= htmlspecialchars($businessName) ?></h1>
            <p class="text-white/75 text-sm mt-1">Staff Login</p>
        </div>

        <?php if (isset($_GET['password_reset'])): ?>
            <div role="status" class="bg-green-100 text-green-800 px-4 py-3 text-sm">Password reset. Log in with your new password.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 border-b border-red-400 text-red-700 px-4 py-3 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="p-6 space-y-4">
            <div>
                <label for="email" class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                <input id="email" type="email" name="email" required maxlength="255" autocomplete="username"
                    value="<?= htmlspecialchars($username ?? '') ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>
            <div>
                <label for="password" class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>
            <button type="submit"
                class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-accentDark transition">
                Log In
            </button>
        </form>

        <p class="px-6 pb-4 text-center text-sm"><a href="forgot-password" class="text-accent hover:underline">Forgot password?</a></p>
        <div class="p-4 border-t text-center">
            <a href="../" class="text-sm text-gray-500 hover:text-gray-700">← Back to Waitlist</a>
        </div>
    </div>
</body>
</html>
