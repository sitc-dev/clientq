<?php
require_once __DIR__ . '/config.php';

$businessName = BUSINESS_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($businessName) ?> Client Waitlist</title>
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
    <style>
        .confirmation-slide {
            animation: slideIn 0.5s ease-out, slideOut 0.5s ease-in 4.5s forwards;
        }
        @keyframes slideIn {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-100%); opacity: 0; }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex justify-center">
    <div class="w-full max-w-md bg-white min-h-screen shadow-lg relative">

        <!-- Header -->
        <div class="bg-primary text-white p-4 text-center">
            <h1 class="text-lg font-bold"><?= htmlspecialchars($businessName) ?> Client Waitlist</h1>
        </div>

        <!-- Confirmation Message (hidden by default) -->
        <?php if (isset($_GET['confirmed'])): ?>
            <div id="confirmationMsg" class="confirmation-slide bg-green-500 text-white text-center py-3 px-4 font-semibold">
                <?= htmlspecialchars($_GET['confirmed']) ?> confirmed on wait list.
            </div>
            <script>
                setTimeout(() => {
                    const msg = document.getElementById('confirmationMsg');
                    if (msg) msg.remove();
                }, 5000);
            </script>
        <?php endif; ?>

        <!-- Staff Filter -->
        <div class="p-3 border-b">
            <select id="staffFilter" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="all">All Staff</option>
            </select>
        </div>

        <!-- Client List Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-200 text-gray-700">
                    <tr>
                        <th class="py-2 px-2 text-left w-8">#</th>
                        <th class="py-2 px-2 text-left">Client</th>
                        <th class="py-2 px-2 text-left">Staff</th>
                        <th class="py-2 px-2 text-right">Wait Time</th>
                        <th class="py-2 px-2 text-center">No. in Party</th>
                    </tr>
                </thead>
                <tbody id="clientList">
                    <tr>
                        <td colspan="5" class="py-8 text-center text-gray-400">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="pagination" class="flex justify-center gap-2 py-3 border-t hidden"></div>

        <!-- Action Buttons -->
        <div class="p-4 space-y-3 border-t">
            <a href="sign-in" class="block w-full bg-accent text-white text-center py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                Sign In
            </a>
            <a href="staff-profiles" class="block w-full bg-gray-600 text-white text-center py-3 rounded-lg font-semibold hover:bg-gray-700 transition">
                See Staff Profiles
            </a>
        </div>

    </div>

    <script>
        let currentPage = 1;
        let currentStaffFilter = 'all';

        // Load staff for filter dropdown
        async function loadStaffFilter() {
            try {
                const res = await fetch('api/get-clients?staff_only=1');
                const data = await res.json();
                const select = document.getElementById('staffFilter');
                if (data.staff) {
                    data.staff.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s.id;
                        opt.textContent = s.goes_by;
                        select.appendChild(opt);
                    });
                }
            } catch (e) {
                console.error('Failed to load staff:', e);
            }
        }

        // Load client list
        async function loadClients() {
            try {
                const params = new URLSearchParams({
                    page: currentPage,
                    staff: currentStaffFilter
                });
                const res = await fetch('api/get-clients?' + params);
                const data = await res.json();
                const tbody = document.getElementById('clientList');

                if (!data.clients || data.clients.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="py-8 text-center text-gray-400">No current clients.</td></tr>';
                    document.getElementById('pagination').classList.add('hidden');
                    return;
                }

                tbody.innerHTML = data.clients.map((c, i) => {
                    const num = (currentPage - 1) * 25 + i + 1;
                    return `<tr class="border-b hover:bg-gray-50">
                        <td class="py-2 px-2 text-gray-600">${num}</td>
                        <td class="py-2 px-2 font-medium">${escapeHtml(c.name)}</td>
                        <td class="py-2 px-2 text-gray-600">${escapeHtml(c.staff_name)}</td>
                        <td class="py-2 px-2 text-right font-mono text-sm">${c.wait_time}</td>
                        <td class="py-2 px-2 text-center">${c.no_in_party}</td>
                    </tr>`;
                }).join('');

                // Pagination
                const paginationDiv = document.getElementById('pagination');
                if (data.total_pages > 1) {
                    paginationDiv.classList.remove('hidden');
                    paginationDiv.innerHTML = '';
                    for (let p = 1; p <= data.total_pages; p++) {
                        const btn = document.createElement('button');
                        btn.textContent = p;
                        btn.className = p === currentPage
                            ? 'px-3 py-1 bg-accent text-white rounded'
                            : 'px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300';
                        btn.onclick = () => { currentPage = p; loadClients(); };
                        paginationDiv.appendChild(btn);
                    }
                } else {
                    paginationDiv.classList.add('hidden');
                }
            } catch (e) {
                console.error('Failed to load clients:', e);
            }
        }

        function escapeHtml(text) {
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        // Staff filter change
        document.getElementById('staffFilter').addEventListener('change', function() {
            currentStaffFilter = this.value;
            currentPage = 1;
            loadClients();
        });

        // Initial load
        loadStaffFilter();
        loadClients();

        // Auto-refresh every 30 seconds
        setInterval(loadClients, 30000);
    </script>
</body>
</html>
