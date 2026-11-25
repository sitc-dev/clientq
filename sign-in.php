<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$stmt = $db->query("SELECT id, goes_by FROM staff WHERE is_active = 1 ORDER BY id");
$staffMembers = $stmt->fetchAll();

$businessName = BUSINESS_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= htmlspecialchars($businessName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1e3a5f',
                        accent: '#2563eb',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen flex justify-center">
    <div class="w-full max-w-md bg-white min-h-screen shadow-lg">

        <!-- Header -->
        <div class="bg-primary text-white p-4 text-center">
            <h1 class="text-lg font-bold">Client Sign-In</h1>
        </div>

        <!-- Error Panel (hidden by default) -->
        <div id="errorPanel" class="hidden bg-red-100 border border-red-400 text-red-700 px-4 py-3 m-4 rounded-lg text-sm">
            <ul id="errorList" class="list-disc list-inside space-y-1"></ul>
        </div>

        <!-- Sign-In Form -->
        <form id="signInForm" class="p-4 space-y-5" novalidate>

            <!-- Name -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Enter name (letters only):</label>
                <input
                    type="text"
                    id="clientName"
                    name="name"
                    placeholder="Client name"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>

            <!-- No. in Party -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">No. in party (including self):</label>
                <div class="grid grid-cols-5 gap-2" id="partyButtons">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                    <button
                        type="button"
                        class="party-btn w-full py-2 rounded-lg border border-gray-300 bg-white text-gray-700 font-semibold shadow hover:bg-gray-100 transition"
                        data-value="<?= $i ?>"
                    >
                        <?= $i ?>
                    </button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" id="noInParty" name="no_in_party" value="">
            </div>

            <!-- Staff Member -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Select staff member to service you:</label>
                <select
                    id="staffSelect"
                    name="staff_id"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">Staff Members</option>
                    <?php foreach ($staffMembers as $staff): ?>
                    <option value="<?= $staff['id'] ?>"><?= htmlspecialchars($staff['goes_by']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Email -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Enter email address:</label>
                <input
                    type="email"
                    id="clientEmail"
                    name="email"
                    placeholder="Client email address"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>

            <!-- Action Buttons -->
            <div class="space-y-3 pt-2">
                <button
                    type="submit"
                    id="submitBtn"
                    class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition"
                >
                    Submit
                </button>
                <button
                    type="button"
                    id="resetBtn"
                    class="w-full bg-gray-400 text-white py-3 rounded-lg font-semibold hover:bg-gray-500 transition"
                >
                    Reset Inputs
                </button>
            </div>
        </form>

        <!-- Back Button -->
        <div class="p-4 border-t">
            <a href="./" class="block w-full bg-gray-600 text-white text-center py-3 rounded-lg font-semibold hover:bg-gray-700 transition">
                Back to Client List View
            </a>
        </div>

    </div>

    <script>
        // Name input: allow letters and spaces only
        document.getElementById('clientName').addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z\s]/g, '');
        });

        // Party buttons: toggle selection
        let selectedParty = null;
        document.querySelectorAll('.party-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Deselect previously selected
                document.querySelectorAll('.party-btn').forEach(b => {
                    b.classList.remove('bg-accent', 'text-white', 'shadow-none');
                    b.classList.add('bg-white', 'text-gray-700', 'shadow');
                });

                // Select this one
                this.classList.remove('bg-white', 'text-gray-700', 'shadow');
                this.classList.add('bg-accent', 'text-white', 'shadow-none');
                selectedParty = this.dataset.value;
                document.getElementById('noInParty').value = selectedParty;
            });
        });

        // Reset button
        document.getElementById('resetBtn').addEventListener('click', function() {
            document.getElementById('clientName').value = '';
            document.getElementById('clientEmail').value = '';
            document.getElementById('staffSelect').selectedIndex = 0;
            document.getElementById('noInParty').value = '';
            selectedParty = null;

            document.querySelectorAll('.party-btn').forEach(b => {
                b.classList.remove('bg-accent', 'text-white', 'shadow-none');
                b.classList.add('bg-white', 'text-gray-700', 'shadow');
            });

            document.getElementById('errorPanel').classList.add('hidden');
        });

        // Form submission with validation
        document.getElementById('signInForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const errors = [];
            const name = document.getElementById('clientName').value.trim();
            const email = document.getElementById('clientEmail').value.trim();
            const staffId = document.getElementById('staffSelect').value;
            const partySize = document.getElementById('noInParty').value;

            // Validate name
            if (!name || name.length === 0) {
                errors.push('Please enter your name.');
            }

            // Validate party size
            if (!partySize) {
                errors.push('Please select the number in your party.');
            }

            // Validate staff selection
            if (!staffId) {
                errors.push('Please select a staff member.');
            }

            // Validate email
            const atCount = (email.match(/@/g) || []).length;
            const hasDot = email.includes('.');
            if (atCount !== 1 || !hasDot) {
                errors.push('Please enter a valid email address (must contain one "@" and at least one ".").');
            }

            if (errors.length > 0) {
                const errorPanel = document.getElementById('errorPanel');
                const errorList = document.getElementById('errorList');
                errorList.innerHTML = errors.map(e => `<li>${e}</li>`).join('');
                errorPanel.classList.remove('hidden');
                return;
            }

            // Hide errors
            document.getElementById('errorPanel').classList.add('hidden');

            // Submit via AJAX
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            try {
                const res = await fetch('api/submit-signin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, email, staff_id: staffId, no_in_party: partySize })
                });
                const data = await res.json();

                if (data.success) {
                    window.location.href = './?confirmed=' + encodeURIComponent(name);
                } else {
                    const errorPanel = document.getElementById('errorPanel');
                    const errorList = document.getElementById('errorList');
                    errorList.innerHTML = `<li>${data.error || 'An error occurred. Please try again.'}</li>`;
                    errorPanel.classList.remove('hidden');
                }
            } catch (err) {
                console.error('Submit error:', err);
                const errorPanel = document.getElementById('errorPanel');
                const errorList = document.getElementById('errorList');
                errorList.innerHTML = '<li>An error occurred. Please try again.</li>';
                errorPanel.classList.remove('hidden');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit';
            }
        });
    </script>
</body>
</html>
