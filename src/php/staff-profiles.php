<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../lib/staff-profile.php';

$tenant = requireTenant();
$db = getTenantDB();
$stmt = $db->query("SELECT * FROM staff WHERE is_active = 1 ORDER BY id");
$staffMembers = $stmt->fetchAll();

$businessName = BUSINESS_NAME;
$theme = businessTheme();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Profiles - <?= htmlspecialchars($businessName) ?></title>
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
</head>
<body class="bg-gray-100 min-h-screen flex justify-center sm:p-6">
    <div class="w-full max-w-md bg-white min-h-screen shadow-lg sm:min-h-0 sm:rounded-2xl sm:overflow-hidden">

        <!-- Header -->
        <div class="bg-primary text-white p-4 text-center">
            <h1 class="text-lg font-bold">Staff Profiles</h1>
        </div>

        <!-- Staff Accordion -->
        <div class="divide-y divide-gray-200">
            <?php foreach ($staffMembers as $staff): ?>
            <div class="staff-item">
                <button
                    class="staff-toggle w-full flex items-center justify-between p-4 hover:bg-gray-50 transition"
                    data-staff-id="<?= $staff['id'] ?>"
                >
                    <div class="flex items-center gap-3">
                        <?php $photoUrl = staffProfilePhotoUrl($staff, $tenant['slug']); ?>
                        <?php if ($photoUrl): ?>
                        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars(staffDisplayName($staff)) ?> profile photo" class="w-12 h-12 rounded-full object-cover">
                        <?php else: ?>
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-primary to-accent flex items-center justify-center text-white font-bold text-lg">
                            <?= htmlspecialchars(strtoupper(substr(staffDisplayName($staff), 0, 1))) ?>
                        </div>
                        <?php endif; ?>
                        <span class="font-semibold text-gray-800"><?= htmlspecialchars(staffDisplayName($staff)) ?></span>
                    </div>
                    <svg class="arrow-icon w-5 h-5 text-gray-500 transform transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
                <div class="staff-details hidden bg-gray-50 px-4 pb-4 pl-16">
                    <div class="space-y-2 text-sm">
                        <p><span class="font-semibold text-gray-600">Name:</span> <?= htmlspecialchars($staff['name']) ?></p>
                        <?php if (trim($staff['goes_by']) !== ''): ?>
                        <p><span class="font-semibold text-gray-600">Nickname:</span> <?= htmlspecialchars($staff['goes_by']) ?></p>
                        <?php endif; ?>
                        <p><span class="font-semibold text-gray-600">Position:</span> <?= htmlspecialchars($staff['position']) ?></p>
                        <p><span class="font-semibold text-gray-600">Experience:</span> <?= htmlspecialchars($staff['experience']) ?></p>
                        <?php if (!empty($staff['additional_info'])): ?>
                        <div>
                            <span class="font-semibold text-gray-600">Additional info:</span>
                            <p class="text-gray-700 mt-1"><?= htmlspecialchars($staff['additional_info']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Back Button -->
        <div class="p-4 border-t">
            <a href="./" class="block w-full bg-gray-600 text-white text-center py-3 rounded-lg font-semibold hover:bg-gray-700 transition">
                Back to Client List View
            </a>
        </div>

    </div>

    <script>
        document.querySelectorAll('.staff-toggle').forEach(btn => {
            btn.addEventListener('click', function() {
                const item = this.closest('.staff-item');
                const details = item.querySelector('.staff-details');
                const arrow = item.querySelector('.arrow-icon');
                const isOpen = !details.classList.contains('hidden');

                // Close all other open profiles
                document.querySelectorAll('.staff-item').forEach(other => {
                    if (other !== item) {
                        other.querySelector('.staff-details').classList.add('hidden');
                        other.querySelector('.arrow-icon').classList.remove('rotate-90');
                    }
                });

                if (isOpen) {
                    details.classList.add('hidden');
                    arrow.classList.remove('rotate-90');
                } else {
                    details.classList.remove('hidden');
                    arrow.classList.add('rotate-90');
                }
            });
        });
    </script>
</body>
</html>
