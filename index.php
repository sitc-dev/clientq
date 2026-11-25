<?php
require_once __DIR__ . '/src/php/config.php';

if (isPlatformHost()) {
    require __DIR__ . '/src/php/platform-home.php';
    exit;
}

session_start();
$tenant = requireTenant();
$isGalleyView = $isGalleyView ?? false;
$businessName = $tenant['name'];
$logoUrl = businessLogoUrl();
$theme = businessTheme();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($businessName) ?> <?= $isGalleyView ? 'Galley View' : 'Waitlist' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if (!$isGalleyView): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        #signupQrCode canvas,
        #signupQrCode img {
            width: 100%;
            height: auto !important;
        }
        .waitlist-mode-signup {
            background-color: #FFFFFF;
            background-image:
                radial-gradient(ellipse at center, transparent 15%, rgba(255, 255, 255, 0.82) 48%, #FFFFFF 70%),
                linear-gradient(var(--signup-grid) 1px, transparent 1px),
                linear-gradient(90deg, var(--signup-grid) 1px, transparent 1px),
                radial-gradient(ellipse at center, var(--signup-surface), transparent 70%);
            background-size: 100% 100%, 28px 28px, 28px 28px, 100% 100%;
            background-position: center;
            background-repeat: no-repeat, repeat, repeat, no-repeat;
        }
        .waitlist-mode-appointment {
            background-color: #FFFFFF;
            background-image:
                radial-gradient(circle at center, var(--appointment-highlight) 0 70px, transparent 71px 130px, var(--appointment-surface) 131px 133px, transparent 134px),
                radial-gradient(ellipse at center, var(--appointment-surface), transparent 70%);
            background-size: 100% 100%;
            background-position: center;
            background-repeat: no-repeat;
        }
        .waitlist-mode-signup > .waitlist-mode-heading {
            border-color: var(--signup-grid);
        }
        .waitlist-mode-appointment > .waitlist-mode-heading {
            border-color: var(--appointment-surface);
        }
    </style>
    <?php endif; ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '<?= $theme['primary'] ?>',
                        accent: '<?= $theme['accent'] ?>',
                        accentDark: '<?= $theme['accent_dark'] ?>',
                        soft: '<?= $theme['soft'] ?>',
                        primarySoft: '<?= $theme['primary_soft'] ?>',
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
    <?php if ($isGalleyView): ?>
    <style>
        .galley-view table {
            font-size: clamp(1.5rem, 4.5vh, 8rem);
            line-height: 1.2;
        }
        .galley-view th {
            font-size: clamp(1.125rem, 3.2vh, 5rem);
        }
        .galley-view th, .galley-view td {
            padding-block: 0.65vh;
        }
        .galley-view td:nth-child(1),
        .galley-view td:nth-child(2) {
            font-weight: 600;
        }
        .galley-view h1 {
            font-size: clamp(2rem, 6vh, 10rem);
            line-height: 1.2;
        }
        .galley-view #pagination {
            font-size: clamp(1rem, 2.4vh, 4rem);
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?= $isGalleyView ? 'galley-view bg-gray-100 min-h-screen p-2' : 'bg-gray-100 min-h-screen flex justify-center sm:p-6' ?>">
    <div class="<?= $isGalleyView ? 'w-full overflow-hidden rounded-2xl bg-white shadow-lg' : 'w-full max-w-md bg-white min-h-screen shadow-lg relative sm:min-h-0 sm:rounded-2xl sm:overflow-hidden' ?>">

        <!-- Header -->
        <div class="bg-primary text-white text-center <?= $isGalleyView ? 'flex items-center justify-center gap-6 p-3' : 'p-4' ?>">
            <?php if ($logoUrl): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($businessName) ?> logo"
                    class="<?= $isGalleyView ? 'h-[6vh] max-h-20 max-w-48' : 'h-16 max-w-48 mx-auto mb-2' ?> rounded-lg bg-white object-contain p-1">
            <?php endif; ?>
            <h1 class="text-lg font-bold"><?= htmlspecialchars($businessName) ?> Waitlist</h1>
        </div>

        <!-- Confirmation Message (hidden by default) -->
        <?php if (!$isGalleyView && isset($_GET['confirmed'])): ?>
            <div id="confirmationMsg" class="confirmation-slide bg-green-500 text-white text-center py-3 px-4 font-semibold">
                <?= htmlspecialchars($_GET['confirmed']) ?> confirmed on wait list.
                <?php if (!empty($_GET['code'])): ?>
                    Confirmation code: <?= htmlspecialchars($_GET['code']) ?>
                <?php endif; ?>
            </div>
            <script>
                const confirmationUrl = new URL(window.location.href);
                ['confirmed', 'code', 'sms'].forEach(param => confirmationUrl.searchParams.delete(param));
                window.history.replaceState(window.history.state, '', confirmationUrl);
                setTimeout(() => {
                    const msg = document.getElementById('confirmationMsg');
                    if (msg) msg.remove();
                }, 5000);
            </script>
        <?php endif; ?>

        <?php if (!$isGalleyView && isset($_GET['confirmed']) && ($_GET['sms'] ?? '') === 'unavailable'): ?>
        <div role="status" class="bg-amber-100 text-amber-900 p-4 text-sm">
            Your sign-up was saved, but the confirmation text could not be sent.
            Keep your confirmation code: <?= htmlspecialchars($_GET['code'] ?? '') ?>.
            Contact staff if you need to delete this sign-up.
        </div>
        <?php endif; ?>

        <?php if (!$isGalleyView): ?>
        <div role="tablist" aria-label="Waitlist options" class="grid grid-cols-3 border-b bg-gray-50 text-sm">
            <button type="button" role="tab" id="waitlistTab" aria-selected="true" aria-controls="waitlist" data-panel="waitlist"
                class="signup-tab border-b-2 border-accent bg-white px-2 py-3 font-semibold text-accent">
                Waitlist
            </button>
            <button type="button" role="tab" id="signupTab" aria-selected="false" aria-controls="sign-in" data-panel="sign-in" tabindex="-1"
                class="signup-tab border-b-2 border-transparent px-2 py-3 font-semibold text-gray-500 hover:text-gray-700">
                <?= appointmentsEnabled($tenant) ? 'Sign Up/Book Appointment' : 'Sign Up' ?>
            </button>
            <button type="button" role="tab" id="qrTab" aria-selected="false" aria-controls="qr-code" data-panel="qr-code" tabindex="-1"
                class="signup-tab border-b-2 border-transparent px-2 py-3 font-semibold text-gray-500 hover:text-gray-700">
                QR Code
            </button>
        </div>
        <section id="waitlist" class="signup-panel" role="tabpanel" aria-labelledby="waitlistTab">

        <!-- Staff Filter -->
        <div class="p-3 border-b">
            <select id="staffFilter" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="all">All Staff</option>
                <option value="any">First available staff</option>
            </select>
        </div>
        <?php endif; ?>

        <!-- Client List Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-200 text-gray-700">
                    <tr>
                        <th class="py-2 px-2 text-left w-8">#</th>
                        <th class="py-2 px-2 text-left">Client</th>
                        <th class="py-2 px-2 text-left">Staff</th>
                        <th class="py-2 px-2 text-right">Time waiting</th>
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
        <div id="pagination" aria-live="polite" class="flex justify-center gap-2 <?= $isGalleyView ? 'py-2 text-sm text-gray-600' : 'py-3' ?> border-t hidden"></div>

        <!-- Action Buttons -->
        <?php if (!$isGalleyView): ?>
        <div class="p-4 space-y-3 border-t">
            <a href="staff-profiles" class="block w-full bg-gray-600 text-white text-center py-3 rounded-lg font-semibold hover:bg-gray-700 transition">
                See Staff Profiles
            </a>
        </div>
        </section>
        <?php require __DIR__ . '/src/php/partials/waitlist-sign-in.php'; ?>
        <?php endif; ?>

    </div>

    <script>
        const isGalleyView = <?= json_encode($isGalleyView) ?>;
        const clientsPerPage = <?= json_encode($isGalleyView ? GALLEY_CLIENTS_PER_PAGE : CLIENTS_PER_PAGE) ?>;
        let currentPage = 1;
        let currentStaffFilter = 'all';
        let totalPages = 1;
        let clientRequest = 0;

        function updateStaffDropdowns(staff, availableStaff) {
            for (const [id, label, defaultValue] of [
                ['staffFilter', 'All Staff', 'all'],
                ['staffSelect', 'Staff Members', ''],
                ['reservationStaff', 'Staff Members', '']
            ]) {
                const select = document.getElementById(id);
                if (!select) continue;
                const options = [
                    { value: defaultValue, text: label },
                    ...(id === 'reservationStaff' ? [] : [{ value: 'any', text: 'First available staff' }]),
                    ...(id === 'staffSelect' ? availableStaff : staff).map(member => ({
                        value: String(member.id),
                        text: id === 'staffSelect' ? member.selection_label : member.goes_by,
                        notice: id === 'staffSelect' ? member.availability_notice : ''
                    }))
                ];
                if (options.length === select.options.length && options.every((option, index) =>
                    option.value === select.options[index].value && option.text === select.options[index].textContent
                    && (option.notice || '') === (select.options[index].dataset.availabilityNotice || '')
                )) {
                    continue;
                }
                const selectedValue = select.value;
                select.replaceChildren(...options.map(option => {
                    const element = document.createElement('option');
                    element.value = option.value;
                    element.textContent = option.text;
                    element.dataset.availabilityNotice = option.notice || '';
                    return element;
                }));
                select.value = options.some(option => option.value === selectedValue) ? selectedValue : defaultValue;
                if (id === 'reservationStaff' && select.value !== selectedValue) {
                    select.dispatchEvent(new Event('change'));
                }
            }
            updateStaffAvailabilityNotice();
            const filter = document.getElementById('staffFilter');
            if (filter && currentStaffFilter !== filter.value) {
                currentStaffFilter = filter.value;
                currentPage = 1;
                return true;
            }
            return false;
        }

        function updateStaffAvailabilityNotice() {
            const select = document.getElementById('staffSelect');
            const notice = document.getElementById('staffAvailabilityNotice');
            notice.textContent = select.selectedOptions[0]?.dataset.availabilityNotice || '';
            notice.classList.toggle('hidden', notice.textContent === '');
        }

        function updateWaitlistHours(hours) {
            document.getElementById('waitlistSignupFields').disabled = !hours.can_join;
            document.getElementById('waitlistHoursMessage').textContent = hours.message;
        }

        // Load client list
        async function loadClients() {
            const request = ++clientRequest;
            try {
                const params = new URLSearchParams({
                    page: currentPage,
                    staff: currentStaffFilter,
                    _: `${Date.now()}-${request}`,
                    clear_cache: request === 1 ? '1' : '0'
                });
                if (isGalleyView) params.set('galley', '1');
                const res = await fetch('/api/get-clients?' + params, { cache: 'no-store' });
                if (!res.ok) {
                    throw new Error('Unable to load the waitlist.');
                }
                const data = await res.json();
                if (request !== clientRequest) return;
                if (!Array.isArray(data.clients) || !Array.isArray(data.staff)
                    || !Array.isArray(data.available_staff) || typeof data.waitlist?.can_join !== 'boolean') {
                    throw new Error(data.error || 'Invalid waitlist response.');
                }
                if (!isGalleyView) {
                    updateWaitlistHours(data.waitlist);
                    if (updateStaffDropdowns(data.staff, data.available_staff)) {
                        await loadClients();
                        return;
                    }
                }
                totalPages = Math.max(1, Number(data.total_pages));
                if (currentPage > totalPages) {
                    currentPage = totalPages;
                    await loadClients();
                    return;
                }
                const tbody = document.getElementById('clientList');

                if (data.clients.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="py-8 text-center text-gray-400">No current clients.</td></tr>';
                    document.getElementById('pagination').classList.add('hidden');
                    return;
                }

                tbody.innerHTML = data.clients.map((c, i) => {
                    const num = (currentPage - 1) * clientsPerPage + i + 1;
                    return `<tr class="border-b hover:bg-gray-50">
                        <td class="py-2 px-2 text-gray-600">${num}</td>
                        <td class="py-2 px-2 font-medium">${escapeHtml(c.name)}</td>
                        <td class="py-2 px-2 text-gray-600">${escapeHtml(c.staff_name)}</td>
                        <td class="py-2 px-2 text-right font-mono">${c.wait_time}</td>
                        <td class="py-2 px-2 text-center">${c.no_in_party}</td>
                    </tr>`;
                }).join('');

                // Pagination
                const paginationDiv = document.getElementById('pagination');
                if (isGalleyView) {
                    paginationDiv.classList.remove('hidden');
                    paginationDiv.textContent = `Page ${currentPage} of ${totalPages} | Updates every 30 seconds`;
                } else if (totalPages > 1) {
                    paginationDiv.classList.remove('hidden');
                    paginationDiv.innerHTML = '';
                    for (let p = 1; p <= totalPages; p++) {
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
                if (request !== clientRequest) return;
                console.error('Failed to load clients:', e);
                if (!isGalleyView) {
                    updateWaitlistHours({ can_join: false, message: 'Unable to load daily availability. Retrying automatically.' });
                }
                document.getElementById('clientList').innerHTML = '<tr><td colspan="5" role="alert" class="py-8 text-center text-red-600">Unable to load the waitlist. Retrying automatically.</td></tr>';
                document.getElementById('pagination').classList.add('hidden');
            }
        }

        function escapeHtml(text) {
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        // Staff filter change
        if (!isGalleyView) {
            document.getElementById('staffFilter').addEventListener('change', function() {
                currentStaffFilter = this.value;
                currentPage = 1;
                loadClients();
            });
        }

        // Initial load
        loadClients();

        if ('EventSource' in window) {
            const events = new EventSource('/api/waitlist-events');
            let waitlistRevision = null;
            events.addEventListener('waitlist-change', event => {
                if (waitlistRevision !== event.data) {
                    waitlistRevision = event.data;
                    loadClients();
                }
            });
            events.addEventListener('waitlist-error', event => {
                console.error(event.data);
            });
            events.addEventListener('error', () => {
                if (events.readyState === EventSource.CLOSED) {
                    console.error('Live waitlist updates unavailable; using periodic refresh.');
                }
            });
            window.addEventListener('pagehide', () => events.close());
            window.addEventListener('pageshow', event => {
                if (event.persisted) window.location.reload();
            });
        }

        // Auto-refresh every 30 seconds
        setInterval(() => {
            if (isGalleyView) {
                currentPage = currentPage < totalPages ? currentPage + 1 : 1;
            }
            loadClients();
        }, 30000);
    </script>
</body>
</html>
