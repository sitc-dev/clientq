<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireOwner();
header('Cache-Control: no-store');

$staff = getLoggedInStaff();
$logoUrl = businessLogoUrl();
$theme = businessTheme();
$csrfToken = platformCsrfToken();

adminHeader('Business Settings', $staff, 'business');
?>

        <?php $dailyHoursScope = 'waitlist'; require __DIR__ . '/../src/php/partials/daily-hours-form.php'; ?>

        <div class="p-4 md:p-6">
            <div id="messagePanel" role="status" aria-live="polite" tabindex="-1"
                class="hidden px-4 py-3 mb-4 rounded-lg text-sm"></div>

            <div class="max-w-xl">
                <h2 class="text-lg font-bold text-gray-800">Business logo</h2>
                <p class="text-sm text-gray-500 mt-1 mb-5">
                    Upload a PNG, JPEG, or WebP image up to 2 MB. It will appear on your waitlist and business portal.
                </p>

                <div class="border rounded-xl p-5 mb-5 bg-gray-50 flex items-center justify-center min-h-40">
                    <?php if ($logoUrl): ?>
                        <img id="logoPreview" src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars(BUSINESS_NAME) ?> logo"
                            class="max-h-32 max-w-full object-contain">
                    <?php else: ?>
                        <p id="logoPlaceholder" class="text-gray-400">No logo uploaded</p>
                        <img id="logoPreview" class="hidden max-h-32 max-w-full object-contain" alt="Logo preview">
                    <?php endif; ?>
                </div>

                <form id="themeForm" class="mb-5 space-y-4">
                    <h2 class="text-lg font-bold text-gray-800">Business colors</h2>
                    <p class="text-sm text-gray-500">Choose up to two base colors: primary and accent. They apply to your waitlist and portal without uploading a new logo. Light colors are adjusted for readable text.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex items-center gap-2 border rounded-lg px-3 py-2">
                            <label for="themePrimary" class="text-sm font-semibold">Primary</label>
                            <input id="themePrimary" type="color" value="<?= htmlspecialchars($theme['primary']) ?>" class="h-9 w-12 cursor-pointer" required>
                            <span id="primaryLabel" class="text-sm font-mono"><?= htmlspecialchars($theme['primary']) ?></span>
                        </div>
                        <div class="flex items-center gap-2 border rounded-lg px-3 py-2">
                            <label for="themeAccent" class="text-sm font-semibold">Accent</label>
                            <input id="themeAccent" type="color" value="<?= htmlspecialchars($theme['accent']) ?>" class="h-9 w-12 cursor-pointer" required>
                            <span id="accentLabel" class="text-sm font-mono"><?= htmlspecialchars($theme['accent']) ?></span>
                        </div>
                    </div>
                    <button id="saveThemeButton" type="submit" class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-accentDark transition">Save colors</button>
                </form>

                <form id="logoForm" class="space-y-4">
                    <input type="file" id="logo" name="logo" required accept="image/png,image/jpeg,image/webp"
                        class="block w-full text-sm border rounded-lg file:border-0 file:bg-gray-100 file:px-4 file:py-3 file:mr-4">
                    <button id="uploadButton" type="submit"
                        class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-accentDark transition">
                        Upload Logo
                    </button>
                </form>
            </div>
        </div>

        <script src="/assets/logo-palette.js"></script>
        <script>
            const csrfToken = <?= json_encode($csrfToken) ?>;
            const primaryInput = document.getElementById('themePrimary');
            const accentInput = document.getElementById('themeAccent');
            function updateColorLabels() {
                document.getElementById('primaryLabel').textContent = primaryInput.value.toUpperCase();
                document.getElementById('accentLabel').textContent = accentInput.value.toUpperCase();
            }
            primaryInput.addEventListener('input', updateColorLabels);
            accentInput.addEventListener('input', updateColorLabels);
            document.getElementById('themeForm').addEventListener('submit', async function(event) {
                event.preventDefault();
                const button = document.getElementById('saveThemeButton');
                button.disabled = true;
                document.getElementById('uploadButton').disabled = true;
                button.textContent = 'Saving...';
                try {
                    const response = await fetch('api/save-theme', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: csrfToken, theme_primary: primaryInput.value, theme_accent: accentInput.value })
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.error || 'Colors could not be saved.');
                    setPalette(data.theme_primary, data.theme_accent);
                    showMessage('Business colors updated.', true);
                    setTimeout(() => window.location.reload(), 700);
                } catch (error) {
                    showMessage(error.message || 'Colors could not be saved. Please try again.', false);
                } finally {
                    button.disabled = false;
                    document.getElementById('uploadButton').disabled = false;
                    button.textContent = 'Save colors';
                }
            });
            document.getElementById('logo').addEventListener('change', async function() {
                const file = this.files[0];
                if (!file) return;
                const preview = document.getElementById('logoPreview');
                preview.src = URL.createObjectURL(file);
                preview.classList.remove('hidden');
                document.getElementById('logoPlaceholder')?.classList.add('hidden');

                try {
                    const palette = await ClientQLogoPalette.extract(file);
                    setPalette(palette.primary, palette.accent);
                } catch (error) {
                    showMessage('The logo is ready, but its colors could not be detected. The current theme will be kept.', false);
                }
            });

            document.getElementById('logoForm').addEventListener('submit', async function(event) {
                event.preventDefault();
                const button = document.getElementById('uploadButton');
                const formData = new FormData();
                formData.append('logo', document.getElementById('logo').files[0]);
                formData.append('theme_primary', document.getElementById('themePrimary').value);
                formData.append('theme_accent', document.getElementById('themeAccent').value);
                button.disabled = true;
                document.getElementById('saveThemeButton').disabled = true;
                button.textContent = 'Uploading...';

                try {
                    const response = await fetch('api/upload-logo', { method: 'POST', body: formData });
                    const data = await response.json();
                    showMessage(data.success ? 'Logo updated.' : (data.error || 'Upload failed.'), data.success);
                    if (data.success) {
                        document.getElementById('logoPreview').src = data.logo_url;
                        setPalette(data.theme_primary, data.theme_accent);
                        setTimeout(() => window.location.reload(), 700);
                    }
                } catch (error) {
                    showMessage('Upload failed. Please try again.', false);
                } finally {
                    button.disabled = false;
                    document.getElementById('saveThemeButton').disabled = false;
                    button.textContent = 'Upload Logo';
                }
            });

            function showMessage(message, success) {
                const panel = document.getElementById('messagePanel');
                panel.textContent = message;
                panel.className = success
                    ? 'bg-green-100 border border-green-400 text-green-700 px-4 py-3 mb-4 rounded-lg text-sm'
                    : 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 mb-4 rounded-lg text-sm';
                panel.focus();
            }

            function setPalette(primary, accent) {
                document.getElementById('themePrimary').value = primary;
                document.getElementById('themeAccent').value = accent;
                updateColorLabels();
            }

        </script>

<?php adminFooter(); ?>
