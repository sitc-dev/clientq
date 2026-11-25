<?php
/**
 * Admin Dashboard - My Clients
 * Staff see their own clients. Owner sees their own clients.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$staff = getLoggedInStaff();
if (!$staff) {
    header('Location: logout');
    exit;
}

adminHeader('My Clients', $staff, 'dashboard');
?>

        <!-- Success/Error Messages -->
        <div id="messagePanel" class="hidden px-4 py-3 m-4 rounded-lg text-sm"></div>

        <!-- Client count -->
        <div id="clientCount" class="p-4 hidden">
            <p class="text-sm text-gray-500 mb-3"><span id="countNum"></span> client(s) waiting</p>
        </div>

        <!-- Client list container (loaded via AJAX) -->
        <div id="clientListContainer">
            <div class="p-8 text-center text-gray-400">Loading...</div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6 space-y-4">
                <h2 class="text-lg font-bold text-gray-800">Remove Client</h2>
                <p class="text-sm text-gray-600">Remove <strong id="deleteClientName"></strong> from the waitlist?</p>
                <p class="text-red-600 text-sm font-semibold">This cannot be undone.</p>
                <div class="flex gap-3 pt-2">
                    <button id="confirmDeleteBtn" class="flex-1 bg-red-600 text-white py-2 rounded-lg font-semibold hover:bg-red-700 transition">
                        Remove
                    </button>
                    <button onclick="closeDeleteModal()" class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold hover:bg-gray-400 transition">
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <script>
            let deleteId = null;

            function escapeHtml(text) {
                const d = document.createElement('div');
                d.textContent = text;
                return d.innerHTML;
            }

            async function loadClients() {
                try {
                    const res = await fetch('api/get-clients');
                    const data = await res.json();
                    const container = document.getElementById('clientListContainer');
                    const countDiv = document.getElementById('clientCount');

                    if (!data.clients || data.clients.length === 0) {
                        container.innerHTML = '<div class="p-8 text-center text-gray-400"><p class="text-lg">No clients in your queue.</p></div>';
                        countDiv.classList.add('hidden');
                        return;
                    }

                    document.getElementById('countNum').textContent = data.count;
                    countDiv.classList.remove('hidden');

                    container.innerHTML = `
                        <div class="hidden md:block overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-200 text-gray-700">
                                    <tr>
                                        <th class="py-2 px-3 text-left w-10">#</th>
                                        <th class="py-2 px-3 text-left">Client</th>
                                        <th class="py-2 px-3 text-left">Email</th>
                                        <th class="py-2 px-3 text-center">Party</th>
                                        <th class="py-2 px-3 text-right">Wait Time</th>
                                        <th class="py-2 px-3 text-center">Code</th>
                                        <th class="py-2 px-3 text-center w-16"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.clients.map((c, i) => `
                                    <tr class="border-b hover:bg-gray-50">
                                        <td class="py-3 px-3 text-gray-600">${i + 1}</td>
                                        <td class="py-3 px-3 font-medium">${escapeHtml(c.name)}</td>
                                        <td class="py-3 px-3 text-gray-500">${escapeHtml(c.email)}</td>
                                        <td class="py-3 px-3 text-center">${c.no_in_party}</td>
                                        <td class="py-3 px-3 text-right font-mono">${c.wait_time}</td>
                                        <td class="py-3 px-3 text-center text-xs text-gray-400 font-mono">${c.confirmation_code}</td>
                                        <td class="py-3 px-3 text-center">
                                            <button onclick="deleteClient(${c.id}, '${escapeHtml(c.name).replace(/'/g, "\\\\'")}')"
                                                class="text-red-500 hover:text-red-700 hover:bg-red-50 p-1 rounded transition" title="Remove client">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>`).join('')}
                                </tbody>
                            </table>
                        </div>
                        <div class="md:hidden divide-y">
                            ${data.clients.map((c, i) => `
                            <div class="p-4 hover:bg-gray-50" id="client-${c.id}">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs bg-gray-200 text-gray-600 rounded-full w-6 h-6 flex items-center justify-center font-semibold">${i + 1}</span>
                                            <span class="font-semibold text-gray-800">${escapeHtml(c.name)}</span>
                                        </div>
                                        <div class="mt-1 ml-8 text-sm text-gray-500 space-y-0.5">
                                            <p>${escapeHtml(c.email)}</p>
                                            <p>Party: ${c.no_in_party} · Wait: <span class="font-mono">${c.wait_time}</span></p>
                                            <p class="text-xs text-gray-400">Code: ${c.confirmation_code}</p>
                                        </div>
                                    </div>
                                    <button onclick="deleteClient(${c.id}, '${escapeHtml(c.name).replace(/'/g, "\\\\'")}')"
                                        class="text-red-500 hover:text-red-700 hover:bg-red-50 p-2 rounded-lg transition" title="Remove client">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>`).join('')}
                        </div>`;
                } catch (e) {
                    console.error('Failed to load clients:', e);
                }
            }

            function deleteClient(id, name) {
                deleteId = id;
                document.getElementById('deleteClientName').textContent = name;
                document.getElementById('deleteModal').classList.remove('hidden');
            }

            function closeDeleteModal() {
                document.getElementById('deleteModal').classList.add('hidden');
                deleteId = null;
            }

            document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
                if (!deleteId) return;
                try {
                    const res = await fetch('api/delete-client', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ client_id: deleteId })
                    });
                    const data = await res.json();
                    closeDeleteModal();

                    if (data.success) {
                        showMessage('Client removed successfully.', 'green');
                        loadClients();
                    } else {
                        showMessage(data.error || 'Failed to remove client.', 'red');
                    }
                } catch (e) {
                    closeDeleteModal();
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

            // Initial load and auto-refresh every 30 seconds
            loadClients();
            setInterval(loadClients, 30000);
        </script>

<?php adminFooter(); ?>
