<?php
/**
 * Admin - My Profile
 * Staff can view and edit their own profile.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$staff = getLoggedInStaff();
if (!$staff) {
    header('Location: logout');
    exit;
}

adminHeader('My Profile', $staff, 'profile');
?>

        <div id="messagePanel" class="hidden px-4 py-3 m-4 rounded-lg text-sm"></div>

        <form id="profileForm" class="p-4 md:p-6 space-y-4 md:grid md:grid-cols-2 md:gap-6 md:space-y-0">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Full Name</label>
                <input type="text" id="name" value="<?= htmlspecialchars($staff['name']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Goes By</label>
                <input type="text" id="goes_by" value="<?= htmlspecialchars($staff['goes_by']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Position</label>
                <input type="text" id="position" value="<?= htmlspecialchars($staff['position']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Experience</label>
                <input type="text" id="experience" value="<?= htmlspecialchars($staff['experience']) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-semibold text-gray-700 mb-1">Additional Info</label>
                <textarea id="additional_info" rows="3"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                ><?= htmlspecialchars($staff['additional_info'] ?? '') ?></textarea>
            </div>

            <div class="md:col-span-2">
                <button type="submit"
                    class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                    Save Profile
                </button>
            </div>
        </form>

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
            // Save profile
            document.getElementById('profileForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                try {
                    const res = await fetch('api/update-profile', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            name: document.getElementById('name').value,
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
