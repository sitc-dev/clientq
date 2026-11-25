<?php
/**
 * Admin Login Page
 */
session_start();
require_once __DIR__ . '/../config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['staff_id'])) {
    header('Location: dashboard');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, goes_by, username, password_hash, role FROM staff WHERE username = :username AND is_active = 1");
        $stmt->execute([':username' => $username]);
        $staff = $stmt->fetch();

        if ($staff && password_verify($password, $staff['password_hash'])) {
            $_SESSION['staff_id'] = $staff['id'];
            $_SESSION['username'] = $staff['username'];
            $_SESSION['goes_by'] = $staff['goes_by'];
            $_SESSION['role'] = $staff['role'];
            header('Location: dashboard');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$businessName = BUSINESS_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - <?= htmlspecialchars($businessName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1e3a5f', accent: '#2563eb' } } }
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-xl shadow-lg overflow-hidden">

        <div class="bg-primary text-white p-6 text-center">
            <h1 class="text-xl font-bold"><?= htmlspecialchars($businessName) ?></h1>
            <p class="text-blue-200 text-sm mt-1">Staff Login</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 border-b border-red-400 text-red-700 px-4 py-3 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Username</label>
                <input type="text" name="username" required autocomplete="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required autocomplete="current-password"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>
            <button type="submit"
                class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                Log In
            </button>
        </form>

        <div class="p-4 border-t text-center">
            <a href="../" class="text-sm text-gray-500 hover:text-gray-700">← Back to Waitlist</a>
        </div>
    </div>
</body>
</html>
