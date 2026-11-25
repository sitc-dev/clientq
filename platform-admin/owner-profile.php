<?php

session_start();
require_once __DIR__ . '/../src/php/config.php';
$admin = requirePlatformRole('platform_owner');
$db = getDB();
$csrfToken = platformCsrfToken();
$ownerId = (int)($_GET['owner_id'] ?? $_POST['owner_id'] ?? 0);
$error = '';

if ($ownerId <= 0) {
    http_response_code(404);
    exit('Owner profile not found.');
}

$ownerStmt = $db->prepare(
    "SELECT id, name, first_name, last_name, phone, email, is_active, created_at
     FROM platform_users
     WHERE id = :id AND role = 'business_owner'"
);
$ownerStmt->execute([':id' => $ownerId]);
$owner = $ownerStmt->fetch();
if (!$owner) {
    http_response_code(404);
    exit('Owner profile not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $noteText = trim((string)($_POST['note_text'] ?? ''));

    if (!verifyPlatformCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Expired website session. Consider refreshing the page.';
    } elseif ($noteText === '') {
        $error = 'Enter a note.';
    } elseif (strlen($noteText) > 5000) {
        $error = 'Notes cannot exceed 5,000 characters.';
    } else {
        $noteStmt = $db->prepare(
            'INSERT INTO business_owner_notes (owner_user_id, created_by, note_text)
             VALUES (:owner_user_id, :created_by, :note_text)'
        );
        $noteStmt->execute([
            ':owner_user_id' => $ownerId,
            ':created_by' => $admin['id'],
            ':note_text' => $noteText,
        ]);
        header('Location: owner-profile?owner_id=' . $ownerId . '&saved=1');
        exit;
    }
}

$businessStmt = $db->prepare(
    "SELECT b.id, b.name, b.slug, b.plan, b.is_active, b.created_at,
        COUNT(i.id) AS invoice_count,
        COALESCE(SUM(CASE WHEN i.status = 'open' THEN i.amount_cents ELSE 0 END), 0)
            AS open_balance,
        COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.amount_cents ELSE 0 END), 0)
            AS paid_total
     FROM business_owners bo
     JOIN businesses b ON b.id = bo.business_id
     LEFT JOIN invoices i ON i.business_id = b.id
     WHERE bo.user_id = :owner_id
     GROUP BY b.id, b.name, b.slug, b.plan, b.is_active, b.created_at
     ORDER BY b.name"
);
$businessStmt->execute([':owner_id' => $ownerId]);
$businesses = $businessStmt->fetchAll();

$notesStmt = $db->prepare(
    'SELECT n.id, n.note_text, n.created_at, author.name AS author_name
     FROM business_owner_notes n
     LEFT JOIN platform_users author ON author.id = n.created_by
     WHERE n.owner_user_id = :owner_id
     ORDER BY n.created_at DESC, n.id DESC'
);
$notesStmt->execute([':owner_id' => $ownerId]);
$notes = $notesStmt->fetchAll();

$phone = null;
if (!empty($owner['phone'])) {
    $phone = sprintf(
        '(%s) %s-%s',
        substr($owner['phone'], 0, 3),
        substr($owner['phone'], 3, 3),
        substr($owner['phone'], 6)
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($owner['name']) ?> - ClientQ Owner Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="bg-slate-900 text-white">
        <div class="max-w-6xl mx-auto px-6 py-5 flex justify-between items-center">
            <div>
                <a href="dashboard" class="text-sm text-slate-300 hover:text-white">← Owner administration</a>
                <h1 class="mt-1 text-2xl font-bold">Owner profile</h1>
            </div>
            <div class="text-right">
                <p><?= htmlspecialchars($admin['name']) ?></p>
                <a href="logout" class="text-sm text-slate-300">Log out</a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto p-6 space-y-8">
        <?php if (isset($_GET['saved'])): ?>
            <div class="rounded-lg bg-green-100 px-4 py-3 text-green-700" role="status">Note added.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="rounded-lg bg-red-100 px-4 py-3 text-red-700" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <section class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl bg-white p-6 shadow-sm lg:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold"><?= htmlspecialchars($owner['name']) ?></h2>
                        <p class="text-slate-500"><?= htmlspecialchars($owner['email']) ?></p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-sm font-semibold <?= $owner['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                        Owner account: <?= $owner['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">First name</dt>
                        <dd class="mt-1"><?= htmlspecialchars($owner['first_name'] ?: '—') ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">Last name</dt>
                        <dd class="mt-1"><?= htmlspecialchars($owner['last_name'] ?: '—') ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">Phone</dt>
                        <dd class="mt-1"><?= htmlspecialchars($phone ?: '—') ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">ClientQ member since</dt>
                        <dd class="mt-1"><?= htmlspecialchars(date('M j, Y', strtotime($owner['created_at']))) ?></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold">Add note</h2>
                <form method="POST" class="mt-4 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="owner_id" value="<?= $ownerId ?>">
                    <label for="note_text" class="block text-sm font-semibold">Internal note</label>
                    <textarea id="note_text" name="note_text" rows="7" maxlength="5000" required
                        class="w-full rounded-lg border px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Add account, support, or follow-up details..."><?= htmlspecialchars($_POST['note_text'] ?? '') ?></textarea>
                    <p class="text-xs text-slate-500">Visible only to platform administrators.</p>
                    <button class="w-full rounded-lg bg-blue-600 py-3 font-semibold text-white hover:bg-blue-700">Add note</button>
                </form>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl bg-white shadow-sm">
            <div class="p-6">
                <h2 class="text-xl font-bold">Tenant accounts</h2>
                <p class="text-slate-500">Businesses connected to this owner profile.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="p-4 text-left">Business</th>
                            <th class="p-4 text-left">Plan</th>
                            <th class="p-4 text-right">Invoices</th>
                            <th class="p-4 text-right">Open balance</th>
                            <th class="p-4 text-right">Paid</th>
                            <th class="p-4 text-center">Tenant status</th>
                            <th class="p-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    <?php if (!$businesses): ?>
                        <tr><td colspan="7" class="p-8 text-center text-slate-400">No tenant accounts are linked.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($businesses as $business): ?>
                        <tr>
                            <td class="p-4">
                                <a class="font-semibold text-blue-600" href="<?= htmlspecialchars(tenantUrl($business['slug'], '/')) ?>"
                                    target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars($business['name']) ?>
                                </a>
                                <p class="text-slate-400"><?= htmlspecialchars($business['slug']) ?></p>
                                <div class="mt-2 flex flex-wrap gap-3">
                                    <a class="text-blue-600 hover:text-blue-800"
                                        href="<?= htmlspecialchars(tenantUrl($business['slug'], '/')) ?>"
                                        target="_blank" rel="noopener noreferrer">Waitlist</a>
                                    <a class="text-blue-600 hover:text-blue-800"
                                        href="<?= htmlspecialchars(tenantUrl($business['slug'], '/admin/login')) ?>"
                                        target="_blank" rel="noopener noreferrer">Staff portal</a>
                                </div>
                            </td>
                            <td class="p-4"><?= htmlspecialchars(planDisplayName($business['plan'])) ?></td>
                            <td class="p-4 text-right"><?= (int)$business['invoice_count'] ?></td>
                            <td class="p-4 text-right"><?= htmlspecialchars(formatMoney((int)$business['open_balance'])) ?></td>
                            <td class="p-4 text-right"><?= htmlspecialchars(formatMoney((int)$business['paid_total'])) ?></td>
                            <td class="p-4 text-center"><?= $business['is_active'] ? 'Active' : 'Inactive' ?></td>
                            <td class="p-4 text-right">
                                <?php
                                $statusBusiness = $business;
                                require __DIR__ . '/../src/php/partials/tenant-status-control.php';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Notes</h2>
            <div class="mt-5 space-y-4">
                <?php if (!$notes): ?>
                    <p class="rounded-lg bg-slate-50 p-5 text-slate-500">No notes have been added.</p>
                <?php endif; ?>
                <?php foreach ($notes as $note): ?>
                    <article class="rounded-xl border border-slate-200 p-5">
                        <div class="flex flex-wrap justify-between gap-2 text-sm text-slate-500">
                            <span><?= htmlspecialchars($note['author_name'] ?: 'Former platform administrator') ?></span>
                            <time datetime="<?= htmlspecialchars($note['created_at']) ?>">
                                <?= htmlspecialchars(date('M j, Y g:i A', strtotime($note['created_at']))) ?>
                            </time>
                        </div>
                        <p class="mt-3 whitespace-pre-wrap break-words text-slate-800"><?= htmlspecialchars($note['note_text']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    </main>
    <?php require __DIR__ . '/../src/php/partials/delete-confirmation.php'; ?>
</body>
</html>
