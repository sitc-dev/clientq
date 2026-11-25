<?php

require_once __DIR__ . '/auth.php';
requireAuth();
$staff = getLoggedInStaff();
if (empty($staff['must_change_password'])) {
    header('Location: dashboard');
    exit;
}
header('Cache-Control: no-store');
$csrfToken = platformCsrfToken();
$theme = businessTheme();
$logoUrl = businessLogoUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set your password - <?= htmlspecialchars(BUSINESS_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/assets/password-visibility.js" defer></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">
    <main class="w-full max-w-md bg-white rounded-xl shadow-lg p-6">
        <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars(BUSINESS_NAME) ?> logo" class="h-16 max-w-full object-contain mb-4">
        <?php endif; ?>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(BUSINESS_NAME) ?></p>
        <h1 class="text-2xl font-bold mt-2">Set your new password</h1>
        <p class="text-sm text-gray-600 mt-3 mb-5">Replace your temporary password before using the staff portal. Choose a different password of at least 8 characters.</p>
        <div id="message" role="alert" class="hidden bg-red-100 text-red-800 rounded-lg p-3 mb-4"></div>
        <form id="setPasswordForm" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div>
                <label for="new_password" class="block text-sm font-semibold">New password</label>
                <input id="new_password" type="password" minlength="8" maxlength="255" required autocomplete="new-password" class="w-full border rounded-lg p-3 mt-1">
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-semibold">Confirm new password</label>
                <input id="confirm_password" type="password" minlength="8" maxlength="255" required autocomplete="new-password" class="w-full border rounded-lg p-3 mt-1">
            </div>
            <button type="submit" class="w-full rounded-lg text-white font-semibold p-3" style="background: <?= $theme['accent'] ?>">Save new password</button>
        </form>
        <p class="text-center text-sm mt-5"><a href="logout" class="underline">Log out</a></p>
    </main>
    <script>
        document.getElementById('setPasswordForm').addEventListener('submit', async function(event) {
            event.preventDefault();
            const button = this.querySelector('button[type="submit"]');
            button.disabled = true;
            try {
                const password = document.getElementById('new_password').value;
                const confirmation = document.getElementById('confirm_password').value;
                if (password !== confirmation) throw new Error('New passwords do not match.');
                const response = await fetch('api/change-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        new_password: password,
                        confirm_password: confirmation,
                        csrf_token: this.elements.csrf_token.value
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Password could not be changed.');
                window.location.href = 'dashboard';
            } catch (error) {
                const message = document.getElementById('message');
                message.textContent = error.message;
                message.classList.remove('hidden');
                button.disabled = false;
            }
        });
    </script>
</body>
</html>
