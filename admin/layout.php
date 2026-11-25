<?php
/**
 * Shared admin layout header
 */
function adminHeader($title, $staff, $activePage = '') {
    $businessName = BUSINESS_NAME;
    $logoUrl = businessLogoUrl();
    $theme = businessTheme();
    $isOwner = isOwner();
    $goesBy = htmlspecialchars(staffDisplayName($staff));
    $role = staffAccessLabel($staff);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($businessName) ?> Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
    <script>
        let checkingTenantStatus = false;
        async function checkTenantStatus() {
            if (document.hidden || checkingTenantStatus) return;
            checkingTenantStatus = true;
            try {
                const response = await fetch('/api/get-clients?tenant_status=1&_=' + Date.now(), { cache: 'no-store' });
                const data = await response.json();
                if ((response.status === 503 && data.tenant_status === 'inactive')
                    || (response.status === 410 && data.tenant_status === 'deleted')) {
                    window.location.reload();
                    return;
                }
                if (!response.ok || data.tenant_status !== 'active') {
                    throw new Error('Unable to check tenant status.');
                }
            } catch (error) {
                console.error('Failed to check tenant status:', error);
            } finally {
                checkingTenantStatus = false;
            }
        }
        checkTenantStatus();
        setInterval(checkTenantStatus, 5000);
        document.addEventListener('visibilitychange', checkTenantStatus);
    </script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="w-full min-h-screen">

        <!-- Admin Header -->
        <header class="bg-primary text-white px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <?php if ($logoUrl): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($businessName) ?> logo"
                        class="w-11 h-11 shrink-0 rounded-lg bg-white object-contain p-1">
                    <?php endif; ?>
                    <div>
                        <p class="text-xs text-white/75"><?= htmlspecialchars($businessName) ?></p>
                        <h1 class="text-lg font-bold"><?= htmlspecialchars($title) ?></h1>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <div class="max-w-28 break-words text-right text-sm sm:max-w-none">
                        <p class="font-semibold"><?= $goesBy ?></p>
                        <p class="text-white/75 text-xs"><?= $role ?></p>
                    </div>
                    <button id="portalMenuToggle" type="button" aria-controls="portalNavigation"
                        aria-expanded="false" aria-label="Open navigation menu"
                        class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-white/30 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:hidden">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path id="portalMenuIcon" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        <!-- Nav -->
        <nav id="portalNavigation" aria-label="Portal navigation"
            class="hidden flex-col border-b bg-white text-sm whitespace-nowrap shadow-sm lg:flex lg:flex-row lg:overflow-x-auto">
            <a href="dashboard"
               class="flex-1 min-w-28 text-center px-4 py-3 font-semibold <?= $activePage === 'dashboard' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                Waitlist
            </a>
            <a href="profile"
               class="flex-1 min-w-28 text-center px-4 py-3 font-semibold <?= $activePage === 'profile' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                My Profile
            </a>
            <a href="reservations"
               class="flex-1 min-w-28 text-center px-4 py-3 font-semibold <?= $activePage === 'reservations' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                Reservations
            </a>
            <?php if (staffCanManageUsers($staff)): ?>
            <a href="staff"
               class="flex-1 min-w-28 text-center px-4 py-3 font-semibold <?= $activePage === 'staff' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                Users
            </a>
            <?php endif; ?>
            <?php if ($isOwner): ?>
            <a href="business"
               class="flex-1 min-w-28 text-center px-4 py-3 font-semibold <?= $activePage === 'business' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                Business
            </a>
            <a href="billing"
               class="flex-1 min-w-28 text-center px-4 py-3 font-semibold <?= $activePage === 'billing' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                Invoices &amp; Payments
            </a>
            <?php endif; ?>
            <a href="logout"
               class="min-w-24 px-4 py-3 text-center text-red-600 hover:text-red-700 font-semibold">
                Logout
            </a>
        </nav>
        <script id="portalNavigationScript">
            (() => {
                const toggle = document.getElementById('portalMenuToggle');
                const navigation = document.getElementById('portalNavigation');
                const icon = document.getElementById('portalMenuIcon');

                function setMenuOpen(open) {
                    navigation.classList.toggle('hidden', !open);
                    navigation.classList.toggle('flex', open);
                    toggle.setAttribute('aria-expanded', String(open));
                    toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
                    icon.setAttribute('d', open ? 'M6 6l12 12M6 18L18 6' : 'M4 6h16M4 12h16M4 18h16');
                }

                toggle.addEventListener('click', () => {
                    setMenuOpen(toggle.getAttribute('aria-expanded') !== 'true');
                });
                navigation.addEventListener('click', event => {
                    if (event.target.closest('a')) setMenuOpen(false);
                });
                document.addEventListener('keydown', event => {
                    if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                        setMenuOpen(false);
                        toggle.focus();
                    }
                });
                document.addEventListener('click', event => {
                    if (!toggle.contains(event.target) && !navigation.contains(event.target)) setMenuOpen(false);
                });
                window.matchMedia('(min-width: 1024px)').addEventListener('change', () => setMenuOpen(false));
            })();
        </script>
        <main class="w-full px-0 py-0 sm:px-5 sm:py-5 lg:px-8 lg:py-6">
            <div class="w-full min-h-[calc(100vh-9rem)] bg-white sm:rounded-2xl sm:shadow-sm overflow-hidden">
<?php
}

function adminFooter() {
?>
            </div>
        </main>
    </div>
</body>
</html>
<?php
}
