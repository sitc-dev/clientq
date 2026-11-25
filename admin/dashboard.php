<?php
/**
 * Admin Dashboard - Tenant Waitlist
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$staff = getLoggedInStaff();
if (!$staff) {
    header('Location: logout');
    exit;
}

adminHeader('Waitlist', $staff, 'dashboard');
?>

        <!-- Success/Error Messages -->
        <div id="messagePanel" class="hidden px-4 py-3 m-4 rounded-lg text-sm"></div>

        <!-- Client count -->
        <div id="clientCount" class="p-4 hidden">
            <p class="text-sm text-gray-500 mb-3"><span id="countNum"></span> client(s) waiting</p>
        </div>

        <!-- Client list container (loaded via AJAX) -->
        <div id="clientListContainer" class="w-full">
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
            let clientRequest = 0;
            let clientsById = new Map();
            const completingClients = new Set();
            const completionToken = <?= json_encode(platformCsrfToken()) ?>;

            function escapeHtml(text) {
                const d = document.createElement('div');
                d.textContent = text;
                return d.innerHTML;
            }

            function formatPhone(phone) {
                const digits = String(phone || '').replace(/\D/g, '');
                return digits.length === 10
                    ? `(${digits.substring(0, 3)})${digits.substring(3, 6)}-${digits.substring(6)}`
                    : '';
            }

            function clientActions(client) {
                return `${client.can_complete ? `
                    <button type="button" data-client-action="complete" data-client-id="${client.id}"
                        ${completingClients.has(Number(client.id)) ? 'disabled' : ''}
                        class="rounded-lg bg-accent px-3 py-2 text-sm font-semibold text-white hover:bg-accentDark disabled:opacity-50">Mark as completed</button>` : ''}
                    ${client.can_remove ? `
                    <button type="button" data-client-action="remove" data-client-id="${client.id}"
                        class="rounded-lg px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Remove</button>` : ''}`;
            }

            async function loadClients() {
                const request = ++clientRequest;
                try {
                    const params = new URLSearchParams({
                        _: `${Date.now()}-${request}`,
                        clear_cache: request === 1 ? '1' : '0'
                    });
                    const res = await fetch('api/get-clients?' + params, { cache: 'no-store' });
                    if (!res.ok) {
                        throw new Error(res.status === 401
                            ? 'Your session expired. Please log in again.'
                            : 'Unable to load clients. Retrying automatically.');
                    }
                    const data = await res.json();
                    if (request !== clientRequest) return;
                    if (!data || data.success !== true || !Array.isArray(data.clients)) {
                        throw new Error(data?.error || 'Invalid client list response. Retrying automatically.');
                    }
                    const container = document.getElementById('clientListContainer');
                    const countDiv = document.getElementById('clientCount');
                    clientsById = new Map(data.clients.map(client => [Number(client.id), client]));

                    if (data.clients.length === 0) {
                        container.innerHTML = '<div class="p-8 text-center text-gray-400"><p class="text-lg">No clients waiting.</p></div>';
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
                                        <th class="py-2 px-3 text-left">Staff</th>
                                        <th class="py-2 px-3 text-left">Phone</th>
                                        <th class="py-2 px-3 text-center">Party</th>
                                        <th class="py-2 px-3 text-right">Time waiting</th>
                                        <th class="py-2 px-3 text-center">Code</th>
                                        <th class="py-2 px-3 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.clients.map((c, i) => `
                                    <tr class="border-b hover:bg-gray-50">
                                        <td class="py-3 px-3 text-gray-600">${i + 1}</td>
                                        <td class="py-3 px-3 font-medium">${escapeHtml(c.name)}</td>
                                        <td class="py-3 px-3">${escapeHtml(c.staff_name)}</td>
                                        <td class="py-3 px-3 text-gray-500">${formatPhone(c.phone)}</td>
                                        <td class="py-3 px-3 text-center">${c.no_in_party}</td>
                                        <td class="py-3 px-3 text-right font-mono">${c.wait_time}</td>
                                        <td class="py-3 px-3 text-center text-xs text-gray-400 font-mono">${c.confirmation_code}</td>
                                        <td class="py-3 px-3 text-center">
                                            <div class="flex flex-wrap justify-center gap-2">${clientActions(c)}</div>
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
                                            <p>Staff: ${escapeHtml(c.staff_name)}</p>
                                            <p>${formatPhone(c.phone)}</p>
                                            <p>Party: ${c.no_in_party} · Time waiting: <span class="font-mono">${c.wait_time}</span></p>
                                            <p class="text-xs text-gray-400">Code: ${c.confirmation_code}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">${clientActions(c)}</div>
                            </div>`).join('')}
                        </div>`;
                } catch (e) {
                    if (request !== clientRequest) return;
                    console.error('Failed to load clients:', e);
                    const container = document.getElementById('clientListContainer');
                    container.innerHTML = '<div role="alert" class="p-8 text-center text-red-600"></div>';
                    container.firstElementChild.textContent = e.message || 'Unable to load clients. Retrying automatically.';
                    document.getElementById('clientCount').classList.add('hidden');
                    document.getElementById('countNum').textContent = '';
                }
            }

            document.getElementById('clientListContainer').addEventListener('click', async event => {
                const button = event.target.closest('button[data-client-action]');
                if (!button) return;
                const id = Number(button.dataset.clientId);
                const client = clientsById.get(id);
                if (!client) return;
                if (button.dataset.clientAction === 'remove') {
                    deleteClient(id, client.name);
                    return;
                }
                if (completingClients.has(id)) return;
                completingClients.add(id);
                button.disabled = true;
                try {
                    const response = await fetch('api/complete-client', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ client_id: id, csrf_token: completionToken })
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.error || 'The client could not be marked as completed.');
                    showMessage('Client marked as completed.', 'green');
                    await loadClients();
                } catch (error) {
                    console.error('Client completion failed:', error);
                    showMessage(error.message || 'The client could not be marked as completed.', 'red');
                } finally {
                    completingClients.delete(id);
                    document.querySelectorAll(`button[data-client-action="complete"][data-client-id="${id}"]`)
                        .forEach(action => { action.disabled = false; });
                }
            });

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
                        body: JSON.stringify({ client_id: deleteId, csrf_token: completionToken })
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
