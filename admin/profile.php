<?php
/**
 * Admin - My Profile
 * Staff can view and edit their own profile.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../lib/staff-profile.php';
requireAuth();
header('Cache-Control: no-store');

$staff = getLoggedInStaff();
if (!$staff) {
    header('Location: logout');
    exit;
}

$nameParts = staffNameParts($staff);
$photoUrl = staffProfilePhotoUrl($staff, $tenant['slug']);
$hasStripeSubscription = false;
if ($staff['role'] === 'owner') {
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM stripe_signup_subscriptions WHERE business_id = ? AND provisioned_at IS NOT NULL'
    );
    $stmt->execute([$tenant['id']]);
    $hasStripeSubscription = (int)$stmt->fetchColumn() > 0;
}
adminHeader('My Profile', $staff, 'profile');
?>

        <div id="messagePanel" class="hidden px-4 py-3 m-4 rounded-lg text-sm"></div>

        <section class="p-4 md:p-6 border-b">
            <h2 class="font-semibold">Access level</h2>
            <p id="profileAccessLevel" class="mt-1 text-sm text-gray-700"><?= htmlspecialchars(staffAccessLabel($staff)) ?></p>
            <p class="mt-1 text-xs text-gray-500">Your access level is assigned by an Owner. Managers can manage Staff accounts only.</p>
        </section>

        <?php $dailyHoursScope = 'staff'; require __DIR__ . '/../src/php/partials/daily-hours-form.php'; ?>

        <form id="photoForm" class="p-4 md:p-6 border-b space-y-3" enctype="multipart/form-data">
            <h2 class="font-semibold">My Profile Photo</h2>
            <img id="profilePhoto" <?php if ($photoUrl): ?>src="<?= htmlspecialchars($photoUrl) ?>"<?php endif; ?> alt="Your profile photo" class="<?= $photoUrl ? '' : 'hidden ' ?>h-24 w-24 rounded-full object-cover">
            <label for="profile_photo" class="block text-sm">Choose a PNG, JPEG, or WebP image up to 2 MB. This photo appears on your public staff profile.</label>
            <input id="profile_photo" type="file" name="photo" accept="image/png,image/jpeg,image/webp" required class="block w-full text-sm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(platformCsrfToken()) ?>">
            <button type="submit" class="bg-accent text-white px-4 py-2 rounded-lg font-semibold">Upload My Photo</button>
            <p id="photoMessage" role="status" class="text-sm"></p>
        </form>

        <form id="profileForm" class="p-4 md:p-6 space-y-4 md:grid md:grid-cols-2 md:gap-6 md:space-y-0">

            <div>
                <label for="first_name" class="block text-sm font-semibold text-gray-700 mb-1">First Name</label>
                <input type="text" id="first_name" required maxlength="100" autocomplete="given-name" value="<?= htmlspecialchars($nameParts['first_name']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label for="last_name" class="block text-sm font-semibold text-gray-700 mb-1">Last Name</label>
                <input type="text" id="last_name" maxlength="100" autocomplete="family-name" value="<?= htmlspecialchars($nameParts['last_name']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label for="goes_by" class="block text-sm font-semibold text-gray-700 mb-1">Nickname (optional)</label>
                <input type="text" id="goes_by" maxlength="50" value="<?= htmlspecialchars($staff['goes_by']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                <p class="text-xs text-gray-500 mt-1">Leave blank to display the first name.</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Position</label>
                <input type="text" id="position" maxlength="100" value="<?= htmlspecialchars($staff['position']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-semibold text-gray-700 mb-1">Experience</label>
                <textarea id="experience" rows="3" maxlength="65535"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"><?= htmlspecialchars($staff['experience']) ?></textarea>
            </div>

            <div class="md:col-span-2">
                <h2 class="font-semibold mb-3">My Contact Details</h2>
                <p class="text-sm text-gray-500 mb-3">Your address and phone are private and do not appear on the public staff profile.</p>
                <label for="address" class="block text-sm font-semibold text-gray-700 mb-1">Address</label>
                <input type="text" id="address" maxlength="255" autocomplete="address-line1" value="<?= htmlspecialchars($staff['address'] ?? '') ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div class="md:col-span-2">
                <label for="city" class="block text-sm font-semibold text-gray-700 mb-1">City</label>
                <input type="text" id="city" maxlength="100" autocomplete="address-level2" value="<?= htmlspecialchars($staff['city'] ?? '') ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label for="state" class="block text-sm font-semibold text-gray-700 mb-1">State</label>
                <select id="state" autocomplete="address-level1"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="">Select state (optional)</option>
                    <?php foreach (STAFF_STATE_CODES as $state): ?>
                    <option value="<?= $state ?>" <?= ($staff['state'] ?? '') === $state ? 'selected' : '' ?>><?= $state ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="zip" class="block text-sm font-semibold text-gray-700 mb-1">ZIP Code</label>
                <input id="zip" type="text" inputmode="numeric" maxlength="10" pattern="[0-9]{5}(-[0-9]{4})?" autocomplete="postal-code"
                    value="<?= htmlspecialchars($staff['zip'] ?? '') ?>" placeholder="12345 or 12345-6789"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label for="phone" class="block text-sm font-semibold text-gray-700 mb-1">Phone Number</label>
                <input type="tel" id="phone" inputmode="numeric" maxlength="13" autocomplete="tel-national"
                    pattern="[0-9]{10}|\([0-9]{3}\)[0-9]{3}-[0-9]{4}" placeholder="(555)555-0123"
                    value="<?= htmlspecialchars($staff['phone'] ?? '') ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                <p class="text-xs text-gray-500 mt-1">Enter exactly 10 digits, or leave blank.</p>
            </div>

            <div>
                <label for="email" class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                <input type="email" id="email" required maxlength="255" autocomplete="email" value="<?= htmlspecialchars($staff['email'] ?: $staff['username']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                <p class="text-xs text-gray-500 mt-1">This is your staff portal login email. Changing it signs out your other sessions.</p>
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-semibold text-gray-700 mb-1">Additional Info</label>
                <textarea id="additional_info" rows="3"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                ><?= htmlspecialchars($staff['additional_info'] ?? '') ?></textarea>
            </div>

            <div class="md:col-span-2">
                <button type="submit"
                    class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-accentDark transition">
                    Save Profile
                </button>
            </div>
        </form>

        <?php if ($hasStripeSubscription): ?>
        <section id="paymentMethods" data-csrf="<?= htmlspecialchars(platformCsrfToken()) ?>" class="border-t p-4 md:p-6 space-y-4">
            <h2 class="font-semibold">Subscription payment cards</h2>
            <p class="text-sm text-gray-500">Save more than one card and choose the default for your monthly subscription. To change a card number, add its replacement through Stripe, then make it the default. Full card numbers and security codes stay with Stripe; only the last four digits appear here.</p>
            <div id="savedCards" class="space-y-3" aria-live="polite"></div>
            <p id="cardMessage" role="status" tabindex="-1" class="text-sm"></p>
            <button id="addPaymentCard" type="button" disabled class="bg-accent text-white px-4 py-2 rounded-lg font-semibold hover:bg-accentDark disabled:opacity-50">Add or replace card</button>
            <a href="billing" class="ml-3 text-sm font-semibold text-accent underline">Manage subscription</a>
        </section>
        <script src="/assets/owner-cards.js" defer></script>
        <?php endif; ?>

        <!-- Change Password Section -->
        <div class="border-t mt-4">
            <form id="passwordForm" class="p-4 md:p-6 space-y-4 md:grid md:grid-cols-2 md:gap-6 md:space-y-0">
                <h2 class="text-md font-bold text-gray-700 md:col-span-2">Change Password</h2>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Current Password</label>
                    <input type="password" id="current_password"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">New Password</label>
                    <input type="password" id="new_password"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Confirm New Password</label>
                    <input type="password" id="confirm_password"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                </div>

                <div class="md:col-span-2">
                    <button type="submit"
                        class="w-full bg-gray-600 text-white py-3 rounded-lg font-semibold hover:bg-gray-700 transition">
                        Change Password
                    </button>
                </div>
            </form>
        </div>

        <script>
            document.getElementById('photoForm').addEventListener('submit', async function(event) {
                event.preventDefault();
                const button = this.querySelector('button[type="submit"]');
                const message = document.getElementById('photoMessage');
                button.disabled = true;
                try {
                    const response = await fetch('api/upload-profile-photo', {
                        method: 'POST',
                        body: new FormData(this)
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.error || 'The profile photo could not be uploaded.');
                    const preview = document.getElementById('profilePhoto');
                    preview.src = data.photo_url;
                    preview.classList.remove('hidden');
                    message.textContent = 'Profile photo updated.';
                    this.reset();
                } catch (error) {
                    message.textContent = error.message;
                } finally {
                    button.disabled = false;
                }
            });
        </script>
        <script src="/assets/phone-input.js"></script>
        <script>
            const phoneInput = document.getElementById('phone');
            phoneInput.value = formatPhone(phoneInput.value);
            phoneInput.addEventListener('input', function() {
                this.value = phoneDigits(this.value);
            });
            phoneInput.addEventListener('blur', function() {
                this.value = formatPhone(this.value);
            });

            // Save profile
            document.getElementById('profileForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                try {
                    const res = await fetch('api/update-profile', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            first_name: document.getElementById('first_name').value,
                            last_name: document.getElementById('last_name').value,
                            address: document.getElementById('address').value,
                            city: document.getElementById('city').value,
                            state: document.getElementById('state').value,
                            zip: document.getElementById('zip').value,
                            phone: phoneDigits(phoneInput.value),
                            email: document.getElementById('email').value,
                            goes_by: document.getElementById('goes_by').value,
                            position: document.getElementById('position').value,
                            experience: document.getElementById('experience').value,
                            additional_info: document.getElementById('additional_info').value,
                        })
                    });
                    const data = await res.json();
                    showMessage(data.success ? 'Profile updated.' : (data.error || 'Failed to update.'), data.success ? 'green' : 'red');
                } catch (e) {
                    showMessage('An error occurred.', 'red');
                }
            });

            // Change password
            document.getElementById('passwordForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const newPass = document.getElementById('new_password').value;
                const confirmPass = document.getElementById('confirm_password').value;

                if (newPass !== confirmPass) {
                    showMessage('New passwords do not match.', 'red');
                    return;
                }
                if (newPass.length < 6) {
                    showMessage('Password must be at least 6 characters.', 'red');
                    return;
                }

                try {
                    const res = await fetch('api/change-password', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            current_password: document.getElementById('current_password').value,
                            new_password: newPass,
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        document.getElementById('current_password').value = '';
                        document.getElementById('new_password').value = '';
                        document.getElementById('confirm_password').value = '';
                    }
                    showMessage(data.success ? 'Password changed.' : (data.error || 'Failed.'), data.success ? 'green' : 'red');
                } catch (e) {
                    showMessage('An error occurred.', 'red');
                }
            });

            function showMessage(msg, color) {
                const panel = document.getElementById('messagePanel');
                panel.textContent = msg;
                panel.className = color === 'green'
                    ? 'bg-green-100 border border-green-400 text-green-700 px-4 py-3 m-4 rounded-lg text-sm'
                    : 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 m-4 rounded-lg text-sm';
                setTimeout(() => { panel.className = 'hidden px-4 py-3 m-4 rounded-lg text-sm'; }, 4000);
            }
        </script>

<?php adminFooter(); ?>
