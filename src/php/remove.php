<?php
require_once __DIR__ . '/config.php';
$tenant = requireTenant();
$businessName = $tenant['name'];
$logoUrl = businessLogoUrl();
$theme = businessTheme();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Delete Your Sign-Up - <?= htmlspecialchars($businessName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: {
            primary: '<?= $theme['primary'] ?>',
            accent: '<?= $theme['accent'] ?>',
            accentDark: '<?= $theme['accent_dark'] ?>'
        } } } };
    </script>
</head>
<body class="bg-gray-100 min-h-screen flex justify-center sm:p-6">
    <main class="w-full max-w-md bg-white shadow-lg sm:rounded-2xl sm:overflow-hidden">
        <header class="bg-primary text-white p-4 text-center">
            <?php if ($logoUrl): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($businessName) ?> logo"
                class="h-16 max-w-48 mx-auto mb-2 rounded-lg bg-white object-contain p-1">
            <?php endif; ?>
            <p class="font-bold"><?= htmlspecialchars($businessName) ?></p>
            <h1 class="text-lg font-bold">Delete Your Sign-Up</h1>
        </header>

        <div id="errorPanel" role="alert" class="hidden m-4 rounded-lg bg-red-100 p-4 text-sm text-red-800"></div>
        <div id="statusPanel" role="status" class="hidden m-4 rounded-lg bg-green-100 p-4 text-sm text-green-800"></div>

        <form id="requestCodeForm" class="p-4 space-y-4">
            <p class="text-sm text-gray-600">Use the deletion link from your confirmation text to verify your phone number before deleting your sign-up. Opening this page does not delete anything.</p>
            <div>
                <label for="removePhone" class="block text-sm font-semibold mb-1">Phone number used to sign up</label>
                <input id="removePhone" type="tel" inputmode="numeric" autocomplete="tel" required
                    placeholder="(xxx)xxx-xxxx" class="w-full rounded-lg border px-3 py-2 focus:ring-2 focus:ring-accent">
            </div>
            <button id="requestCodeBtn" type="submit"
                class="w-full rounded-lg bg-accent py-3 font-semibold text-white hover:bg-accentDark">
                Send Verification Code
            </button>
        </form>

        <form id="verifyCodeForm" class="hidden p-4 space-y-4 border-t">
            <label for="verificationCode" class="block text-sm font-semibold">Six-digit code sent to your phone</label>
            <input id="verificationCode" type="text" inputmode="numeric" autocomplete="one-time-code"
                pattern="[0-9]{6}" maxlength="6" required
                class="w-full rounded-lg border px-3 py-2 text-center font-mono text-lg tracking-widest">
            <p class="text-xs text-gray-500">The code expires in ten minutes. You can request a new code after one minute, up to three times per hour.</p>
            <button id="verifyCodeBtn" type="submit"
                class="w-full rounded-lg bg-accent py-3 font-semibold text-white hover:bg-accentDark">Verify Code</button>
            <button id="resendCodeBtn" type="button" class="w-full rounded-lg border py-3 font-semibold">Resend Code</button>
        </form>

        <section id="deleteReady" class="hidden p-4 space-y-4 border-t" aria-label="Verified sign-up">
            <p class="text-sm text-gray-700">Your phone number is verified. Click Delete to review the deletion before confirming.</p>
            <button id="deleteBtn" type="button"
                class="w-full rounded-lg bg-red-600 py-3 font-semibold text-white hover:bg-red-700">
                Delete My Sign-Up
            </button>
        </section>

        <section id="deletionConfirm" class="hidden m-4 overflow-hidden rounded-xl border border-red-300 bg-white"
            aria-labelledby="confirmHeading" tabindex="-1">
            <h2 id="confirmHeading" class="bg-red-600 text-white text-center py-3 px-4 font-semibold">
                Confirm deletion of this sign-up and its confirmation code
            </h2>
            <div class="p-4 space-y-4">
            <p class="font-semibold text-red-800">This deletion cannot be undone.</p>
            <dl class="text-sm text-gray-700">
                <dt class="font-semibold">Name</dt><dd id="confirmName"></dd>
                <dt class="font-semibold mt-2">Confirmation code</dt><dd id="confirmOriginalCode" class="font-mono"></dd>
                <dt class="font-semibold mt-2">Staff</dt><dd id="confirmStaff"></dd>
                <dt class="font-semibold mt-2">Sign-up time</dt><dd id="confirmDate"></dd>
            </dl>
            <p class="text-sm text-gray-700">Only this sign-up record will be permanently deleted. Other sign-ups using your phone number will not be changed.</p>
            <button id="confirmDeleteBtn" type="button"
                class="w-full rounded-lg bg-red-600 py-3 font-semibold text-white hover:bg-red-700">
                Confirm Deletion
            </button>
            <button id="cancelDeleteBtn" type="button" class="w-full rounded-lg border bg-white py-3 font-semibold">Cancel</button>
            </div>
        </section>

        <div class="p-4 border-t">
            <a href="./" class="block text-center text-sm text-accent underline">Back to Waitlist</a>
        </div>
    </main>
    <script>
        const requestForm = document.getElementById('requestCodeForm');
        const verifyForm = document.getElementById('verifyCodeForm');
        const deleteReady = document.getElementById('deleteReady');
        const deleteButton = document.getElementById('deleteBtn');
        const confirmPanel = document.getElementById('deletionConfirm');
        const phoneInput = document.getElementById('removePhone');
        const codeInput = document.getElementById('verificationCode');
        let verificationToken = null;
        const signupReference = new URLSearchParams(window.location.search).get('signup');

        phoneInput.addEventListener('input', () => {
            phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, 10);
        });
        codeInput.addEventListener('input', () => {
            codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
        });

        function showStatus(message) {
            document.getElementById('statusPanel').textContent = message;
            document.getElementById('statusPanel').classList.remove('hidden');
        }

        async function removalRequest(action, extra = {}) {
            if (!signupReference || !/^[1-9][0-9]*$/.test(signupReference)) {
                throw new Error('Open the deletion link from your confirmation text to delete your sign-up.');
            }
            const response = await fetch('/api/remove-client', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action,
                    signup_id: signupReference,
                    phone: phoneInput.value.replace(/\D/g, ''),
                    ...extra
                })
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'The request could not be completed.');
            return data;
        }

        async function runAction(button, operation) {
            button.disabled = true;
            document.getElementById('errorPanel').classList.add('hidden');
            try {
                await operation();
            } catch (error) {
                console.error('Self-deletion request failed:', error);
                document.getElementById('errorPanel').textContent = error.message || 'The request could not be completed.';
                document.getElementById('errorPanel').classList.remove('hidden');
            } finally {
                button.disabled = false;
            }
        }

        async function requestCode() {
            const data = await removalRequest('request_code');
            verificationToken = null;
            codeInput.value = '';
            phoneInput.readOnly = true;
            document.getElementById('requestCodeBtn').classList.add('hidden');
            confirmPanel.classList.add('hidden');
            deleteReady.classList.add('hidden');
            verifyForm.classList.remove('hidden');
            showStatus(data.message);
            codeInput.focus();
        }

        requestForm.addEventListener('submit', event => {
            event.preventDefault();
            runAction(document.getElementById('requestCodeBtn'), requestCode);
        });
        document.getElementById('resendCodeBtn').addEventListener('click', event => {
            runAction(event.currentTarget, requestCode);
        });
        verifyForm.addEventListener('submit', event => {
            event.preventDefault();
            runAction(document.getElementById('verifyCodeBtn'), async () => {
                const data = await removalRequest('verify_code', {verification_code: codeInput.value});
                verificationToken = data.verification_token;
                document.getElementById('confirmName').textContent = data.client.name;
                document.getElementById('confirmOriginalCode').textContent = data.client.confirmation_code;
                document.getElementById('confirmStaff').textContent = data.client.staff_name;
                document.getElementById('confirmDate').textContent = data.client.sign_in_date;
                document.getElementById('statusPanel').classList.add('hidden');
                verifyForm.classList.add('hidden');
                deleteReady.classList.remove('hidden');
                deleteButton.focus();
            });
        });
        deleteButton.addEventListener('click', () => {
            if (!verificationToken) return;
            document.getElementById('errorPanel').classList.add('hidden');
            deleteReady.classList.add('hidden');
            confirmPanel.classList.remove('hidden');
            confirmPanel.focus();
        });
        document.getElementById('confirmDeleteBtn').addEventListener('click', event => {
            runAction(event.currentTarget, async () => {
                const data = await removalRequest('delete', {verification_token: verificationToken});
                verificationToken = null;
                requestForm.classList.add('hidden');
                deleteReady.classList.add('hidden');
                confirmPanel.classList.add('hidden');
                showStatus(data.message);
            });
        });
        document.getElementById('cancelDeleteBtn').addEventListener('click', () => {
            verificationToken = null;
            codeInput.value = '';
            phoneInput.readOnly = false;
            confirmPanel.classList.add('hidden');
            deleteReady.classList.add('hidden');
            verifyForm.classList.add('hidden');
            document.getElementById('requestCodeBtn').classList.remove('hidden');
            phoneInput.focus();
        });
    </script>
</body>
</html>
