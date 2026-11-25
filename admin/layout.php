<?php
/**
 * Shared admin layout header
 */
function adminHeader($title, $staff, $activePage = '') {
    $businessName = BUSINESS_NAME;
    $isOwner = isOwner();
    $goesBy = htmlspecialchars($staff['goes_by']);
    $role = $isOwner ? 'Owner' : 'Staff';
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
                        primary: '#1e3a5f',
                        accent: '#2563eb',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="w-full max-w-4xl mx-auto bg-white min-h-screen shadow-lg">

        <!-- Admin Header -->
        <div class="bg-primary text-white p-4">
            <div class="flex justify-between items-center">
                <h1 class="text-lg font-bold"><?= htmlspecialchars($title) ?></h1>
                <div class="text-right text-sm">
                    <p class="font-semibold"><?= $goesBy ?></p>
                    <p class="text-blue-200 text-xs"><?= $role ?></p>
                </div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="flex border-b bg-gray-50 text-sm">
            <a href="dashboard"
               class="flex-1 text-center py-3 font-semibold <?= $activePage === 'dashboard' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                My Clients
            </a>
            <a href="profile"
               class="flex-1 text-center py-3 font-semibold <?= $activePage === 'profile' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                My Profile
            </a>
            <?php if ($isOwner): ?>
            <a href="staff"
               class="flex-1 text-center py-3 font-semibold <?= $activePage === 'staff' ? 'text-accent border-b-2 border-accent bg-white' : 'text-gray-500 hover:text-gray-700' ?>">
                All Staff
            </a>
            <?php endif; ?>
            <a href="logout"
               class="px-4 py-3 text-red-300 hover:text-red-100 font-semibold">
                Logout
            </a>
        </nav>
<?php
}

function adminFooter() {
?>
    </div>
</body>
</html>
<?php
}
