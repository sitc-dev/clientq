<?php
require_once __DIR__ . '/config.php';
$businessName = BUSINESS_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waitlist Self-Removal - <?= htmlspecialchars($businessName) ?></title>
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
    <div class="w-full max-w-md bg-white min-h-screen shadow-lg relative">

        <!-- Header -->
        <div class="bg-primary text-white p-4 text-center">
            <h1 class="text-lg font-bold">Waitlist Self-Removal</h1>
        </div>

        <!-- Error Panel -->
        <div id="errorPanel" class="hidden bg-red-100 border border-red-400 text-red-700 px-4 py-3 m-4 rounded-lg text-sm">
            <p id="errorMsg"></p>
        </div>

        <!-- Success Panel -->
        <div id="successPanel" class="hidden bg-green-100 border border-green-400 text-green-700 px-4 py-3 m-4 rounded-lg text-sm">
            <p id="successMsg"></p>
        </div>

        <!-- Removal Form -->
        <div class="p-4 space-y-5">

            <!-- Confirmation Code -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Enter removal confirmation code:</label>
                <input
                    type="text"
                    id="confirmCode"
                    placeholder="Confirmation code"
                    maxlength="6"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent uppercase tracking-widest text-center font-mono text-lg"
                >
            </div>

            <!-- Email -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Enter email address:</label>
                <input
                    type="email"
                    id="removeEmail"
                    placeholder="Client email address"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>

            <!-- Action Buttons -->
            <div class="space-y-3 pt-2">
                <button
                    type="button"
                    id="removeBtn"
                    class="w-full bg-red-600 text-white py-3 rounded-lg font-semibold hover:bg-red-700 transition"
                >
                    Remove Me
                </button>
                <button
                    type="button"
                    id="resetBtn"
                    class="w-full bg-gray-400 text-white py-3 rounded-lg font-semibold hover:bg-gray-500 transition"
                >
                    Reset Inputs
                </button>
            </div>
        </div>

        <!-- Back Button -->
        <div class="p-4 border-t">
            <a href="./" class="block w-full bg-gray-600 text-white text-center py-3 rounded-lg font-semibold hover:bg-gray-700 transition">
                Back to Client List View
            </a>
        </div>

        <!-- Confirmation Modal Overlay -->
        <div id="confirmModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6 space-y-4">
                <h2 class="text-lg font-bold text-gray-800">Confirm removal of</h2>
                <div class="text-sm space-y-1 text-gray-600">
                    <p><span class="font-semibold">Client:</span> <span id="modalClient"></span></p>
                    <p><span class="font-semibold">Email:</span> <span id="modalEmail"></span></p>
                    <p><span class="font-semibold">Staff:</span> <span id="modalStaff"></span></p>
                    <p><span class="font-semibold">Sign-In Date, Time:</span> <span id="modalDate"></span></p>
                </div>
                <p class="text-red-600 text-sm font-semibold">- Cannot be undone</p>
                <div class="flex gap-3 pt-2">
                    <button
                        id="confirmRemoveBtn"
                        class="flex-1 bg-red-600 text-white py-2 rounded-lg font-semibold hover:bg-red-700 transition"
                    >
                        Confirm Removal
                    </button>
                    <button
                        id="cancelRemoveBtn"
                        class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold hover:bg-gray-400 transition"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>

    </div>

    <script>
        let clientData = null;

        // Confirmation code: allow alphanumeric only, max 6 chars
        document.getElementById('confirmCode').addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z0-9]/g, '').substring(0, 6).toUpperCase();
        });

        // Reset button
        document.getElementById('resetBtn').addEventListener('click', function() {
            document.getElementById('confirmCode').value = '';
            document.getElementById('removeEmail').value = '';
            document.getElementById('errorPanel').classList.add('hidden');
            document.getElementById('successPanel').classList.add('hidden');
        });

        // Remove Me button - validate then show lookup
        document.getElementById('removeBtn').addEventListener('click', async function() {
            const code = document.getElementById('confirmCode').value.trim();
            const email = document.getElementById('removeEmail').value.trim();

            // Validate email
            const atCount = (email.match(/@/g) || []).length;
            const hasDot = email.includes('.');
            if (atCount !== 1 || !hasDot) {
                showError('Please enter a valid email address.');
                return;
            }

            if (code.length !== 6) {
                showError('Please enter your 6-character confirmation code.');
                return;
            }

            // Look up client
            try {
                const res = await fetch('api/remove-client', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'lookup', code, email })
                });
                const data = await res.json();

                if (data.success && data.client) {
                    clientData = data.client;
                    document.getElementById('modalClient').textContent = data.client.name;
                    document.getElementById('modalEmail').textContent = data.client.email;
                    document.getElementById('modalStaff').textContent = data.client.staff_name;
                    document.getElementById('modalDate').textContent = data.client.sign_in_date;
                    document.getElementById('confirmModal').classList.remove('hidden');
                } else {
                    showError(data.error || 'No matching client found. Check your code and email.');
                }
            } catch (err) {
                showError('An error occurred. Please try again.');
            }
        });

        // Cancel removal
        document.getElementById('cancelRemoveBtn').addEventListener('click', function() {
            document.getElementById('confirmModal').classList.add('hidden');
            clientData = null;
        });

        // Confirm removal
        document.getElementById('confirmRemoveBtn').addEventListener('click', async function() {
            if (!clientData) return;

            try {
                const code = document.getElementById('confirmCode').value.trim();
                const email = document.getElementById('removeEmail').value.trim();

                const res = await fetch('api/remove-client', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove', code, email })
                });
                const data = await res.json();

                document.getElementById('confirmModal').classList.add('hidden');

                if (data.success) {
                    document.getElementById('errorPanel').classList.add('hidden');
                    document.getElementById('successMsg').textContent = 'You have been removed from the waitlist.';
                    document.getElementById('successPanel').classList.remove('hidden');
                    document.getElementById('confirmCode').value = '';
                    document.getElementById('removeEmail').value = '';
                } else {
                    showError(data.error || 'Failed to remove. Please try again.');
                }
            } catch (err) {
                document.getElementById('confirmModal').classList.add('hidden');
                showError('An error occurred. Please try again.');
            }
        });

        function showError(msg) {
            document.getElementById('successPanel').classList.add('hidden');
            document.getElementById('errorMsg').textContent = msg;
            document.getElementById('errorPanel').classList.remove('hidden');
        }
    </script>
</body>
</html>
