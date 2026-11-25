<?php
/**
 * Admin - Staff Overview (Owner only)
 * Owner can see all staff profiles and their client counts.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireOwner();

$staff = getLoggedInStaff();
$db = getDB();

// Get all staff with client counts
$allStaff = $db->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM clients c WHERE c.staff_id = s.id AND c.is_active = 1) AS client_count
    FROM staff s
    WHERE s.is_active = 1
    ORDER BY s.id
")->fetchAll();

adminHeader('All Staff', $staff, 'staff');
?>

        <div class="divide-y">
            <?php foreach ($allStaff as $member): ?>
            <div class="staff-item">
                <button class="staff-toggle w-full flex items-center justify-between p-4 hover:bg-gray-50 transition">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-primary to-accent flex items-center justify-center text-white font-bold text-lg">
                            <?= strtoupper(substr($member['goes_by'], 0, 1)) ?>
                        </div>
                        <div class="text-left">
                            <span class="font-semibold text-gray-800 block"><?= htmlspecialchars($member['goes_by']) ?></span>
                            <span class="text-xs text-gray-400">
                                <?= $member['role'] === 'owner' ? '👑 Owner' : 'Staff' ?>
                                · <?= $member['client_count'] ?> client(s)
                            </span>
                        </div>
                    </div>
                    <svg class="arrow-icon w-5 h-5 text-gray-500 transform transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
                <div class="staff-details hidden bg-gray-50 px-4 pb-4 pl-16">
                    <div class="space-y-2 text-sm">
                        <p><span class="font-semibold text-gray-600">Name:</span> <?= htmlspecialchars($member['name']) ?></p>
                        <p><span class="font-semibold text-gray-600">Goes by:</span> <?= htmlspecialchars($member['goes_by']) ?></p>
                        <p><span class="font-semibold text-gray-600">Username:</span> <?= htmlspecialchars($member['username']) ?></p>
                        <p><span class="font-semibold text-gray-600">Position:</span> <?= htmlspecialchars($member['position']) ?></p>
                        <p><span class="font-semibold text-gray-600">Experience:</span> <?= htmlspecialchars($member['experience']) ?></p>
                        <?php if (!empty($member['additional_info'])): ?>
                        <div>
                            <span class="font-semibold text-gray-600">Additional info:</span>
                            <p class="text-gray-700 mt-1"><?= htmlspecialchars($member['additional_info']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
            document.querySelectorAll('.staff-toggle').forEach(btn => {
                btn.addEventListener('click', function() {
                    const item = this.closest('.staff-item');
                    const details = item.querySelector('.staff-details');
                    const arrow = item.querySelector('.arrow-icon');
                    const isOpen = !details.classList.contains('hidden');

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

<?php adminFooter(); ?>
