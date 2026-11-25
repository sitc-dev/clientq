<?php

session_start();
require_once __DIR__ . '/../src/php/config.php';
$admin = requirePlatformRole('platform_owner');
$db = getDB();
$csrfToken = platformCsrfToken();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyPlatformCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Expired website session. Consider refreshing the page.';
    } else {
        $businessId = (int)($_POST['business_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $amount = trim($_POST['amount'] ?? '');
        $dueDate = trim($_POST['due_date'] ?? '');
        $amountCents = null;
        if (preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $amount)) {
            [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '');
            $amountCents = (int)$whole * 100 + (int)str_pad($decimal, 2, '0');
        }

        $businessStmt = $db->prepare('SELECT id FROM businesses WHERE id = :id AND is_active = 1');
        $businessStmt->execute([':id' => $businessId]);
        $parsedDueDate = $dueDate === '' ? null : DateTime::createFromFormat('Y-m-d', $dueDate);
        $validDate = $dueDate === ''
            || ($parsedDueDate !== false && $parsedDueDate->format('Y-m-d') === $dueDate);

        if (!$businessStmt->fetch()) {
            $error = 'Select an active business.';
        } elseif ($description === '' || strlen($description) > 255) {
            $error = 'Enter an invoice description up to 255 characters.';
        } elseif ($amountCents === null || $amountCents < 50) {
            $error = 'Enter an invoice amount of at least $0.50.';
        } elseif (!$validDate) {
            $error = 'Enter a valid due date.';
        } else {
            $invoiceNumber = 'CQ-' . date('Ym') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $db->prepare(
                "INSERT INTO invoices
                    (business_id, created_by, invoice_number, description, amount_cents, due_date)
                 VALUES
                    (:business_id, :created_by, :invoice_number, :description, :amount_cents, :due_date)"
            );
            $stmt->execute([
                ':business_id' => $businessId,
                ':created_by' => $admin['id'],
                ':invoice_number' => $invoiceNumber,
                ':description' => $description,
                ':amount_cents' => $amountCents,
                ':due_date' => $dueDate !== '' ? $dueDate : null,
            ]);
            $message = 'Invoice ' . $invoiceNumber . ' created.';
        }
    }
}

$owners = $db->query(
    "SELECT u.id AS owner_id, u.name AS owner_name, u.phone, u.email, u.created_at AS owner_created_at,
        b.id AS business_id, b.name AS business_name, b.slug, b.plan, b.is_active,
        COUNT(i.id) AS invoice_count,
        COALESCE(SUM(CASE WHEN i.status = 'open' THEN i.amount_cents ELSE 0 END), 0) AS open_balance,
        COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.amount_cents ELSE 0 END), 0) AS paid_total
     FROM platform_users u
     LEFT JOIN business_owners bo ON bo.user_id = u.id
     LEFT JOIN businesses b ON b.id = bo.business_id
     LEFT JOIN invoices i ON i.business_id = b.id
     WHERE u.role = 'business_owner'
     GROUP BY u.id, u.name, u.phone, u.email, u.created_at, b.id, b.name, b.slug, b.plan, b.is_active
     ORDER BY u.name, b.name"
)->fetchAll();

$invoices = $db->query(
    "SELECT i.invoice_number, i.description, i.amount_cents, i.currency, i.status, i.due_date,
        i.paid_at, b.name AS business_name
     FROM invoices i
     JOIN businesses b ON b.id = i.business_id
     ORDER BY i.created_at DESC
     LIMIT 50"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Dashboard - ClientQ</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen">
    <header class="bg-slate-900 text-white">
        <div class="max-w-7xl mx-auto px-6 py-5 flex justify-between items-center">
            <div><p class="text-slate-400 text-sm">ClientQ platform</p><h1 class="text-2xl font-bold">Owner administration</h1></div>
            <div class="text-right"><p><?= htmlspecialchars($admin['name']) ?></p><a href="logout" class="text-sm text-slate-300">Log out</a></div>
        </div>
    </header>
    <main class="max-w-7xl mx-auto p-6 space-y-8">
        <?php if ($message): ?><div class="bg-green-100 text-green-700 rounded-lg px-4 py-3"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="bg-red-100 text-red-700 rounded-lg px-4 py-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <section class="bg-white rounded-2xl shadow-sm p-6">
            <h2 class="text-xl font-bold mb-4">Issue an invoice</h2>
            <form method="POST" class="grid md:grid-cols-4 gap-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <select name="business_id" required class="border rounded-lg px-3 py-2">
                    <option value="">Select business</option>
                    <?php foreach ($owners as $owner): ?>
                    <?php if (!$owner['business_id']) continue; ?>
                    <option value="<?= (int)$owner['business_id'] ?>"><?= htmlspecialchars($owner['business_name'] . ' — ' . $owner['owner_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="description" required maxlength="255" placeholder="Invoice description" class="border rounded-lg px-3 py-2">
                <input name="amount" required inputmode="decimal" placeholder="Amount, e.g. 59.00" class="border rounded-lg px-3 py-2">
                <input type="date" name="due_date" class="border rounded-lg px-3 py-2">
                <button class="md:col-span-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-3 font-semibold">Create invoice</button>
            </form>
        </section>

        <section class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="p-6"><h2 class="text-xl font-bold">ClientQ owner profiles</h2><p class="text-slate-500">All business owners and their ClientQ accounts.</p></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50"><tr><th class="text-left p-4">Owner</th><th class="text-left p-4">Business</th><th class="text-left p-4">Plan</th><th class="text-right p-4">Open balance</th><th class="text-right p-4">Paid</th><th class="text-center p-4">Status</th><th class="text-right p-4">Actions</th></tr></thead>
                    <tbody class="divide-y">
                    <?php foreach ($owners as $owner): ?>
                    <tr>
                        <td class="p-4">
                            <p class="font-semibold"><?= htmlspecialchars($owner['owner_name']) ?></p>
                            <p class="text-slate-500"><?= htmlspecialchars($owner['email']) ?></p>
                            <?php if (!empty($owner['phone'])): ?>
                                <p class="text-slate-500"><?= htmlspecialchars(sprintf(
                                    '(%s)%s-%s',
                                    substr($owner['phone'], 0, 3),
                                    substr($owner['phone'], 3, 3),
                                    substr($owner['phone'], 6)
                                )) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="p-4">
                            <?php if ($owner['business_id']): ?>
                                <a class="text-blue-600 font-semibold" href="<?= htmlspecialchars(tenantUrl($owner['slug'], '/')) ?>"><?= htmlspecialchars($owner['business_name']) ?></a>
                                <p class="text-slate-400"><?= htmlspecialchars($owner['slug']) ?></p>
                            <?php else: ?>
                                <span class="text-slate-500">No tenant accounts</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4"><?= $owner['plan'] ? htmlspecialchars(planDisplayName($owner['plan'])) : '—' ?></td>
                        <td class="p-4 text-right"><?= htmlspecialchars(formatMoney((int)$owner['open_balance'])) ?></td>
                        <td class="p-4 text-right"><?= htmlspecialchars(formatMoney((int)$owner['paid_total'])) ?></td>
                        <td class="p-4 text-center"><?= $owner['business_id'] ? ($owner['is_active'] ? '<span class="text-green-700">Active</span>' : '<span class="text-red-700">Inactive</span>') : '—' ?></td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-2">
                            <a href="owner-profile?owner_id=<?= (int)$owner['owner_id'] ?>"
                                class="rounded-lg bg-blue-50 px-3 py-2 font-semibold text-blue-700 hover:bg-blue-100">
                                Profile
                            </a>
                            <?php if ($owner['business_id']): ?>
                                <?php
                                $statusBusiness = [
                                    'id' => $owner['business_id'],
                                    'name' => $owner['business_name'],
                                    'is_active' => $owner['is_active'],
                                ];
                                require __DIR__ . '/../src/php/partials/tenant-status-control.php';
                                ?>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="p-6"><h2 class="text-xl font-bold">Recent invoices</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50"><tr><th class="text-left p-4">Invoice</th><th class="text-left p-4">Business</th><th class="text-left p-4">Description</th><th class="text-right p-4">Amount</th><th class="text-center p-4">Status</th></tr></thead>
                    <tbody class="divide-y">
                    <?php foreach ($invoices as $invoice): ?>
                    <tr><td class="p-4 font-medium"><?= htmlspecialchars($invoice['invoice_number']) ?></td><td class="p-4"><?= htmlspecialchars($invoice['business_name']) ?></td><td class="p-4"><?= htmlspecialchars($invoice['description']) ?></td><td class="p-4 text-right"><?= htmlspecialchars(formatMoney((int)$invoice['amount_cents'], $invoice['currency'])) ?></td><td class="p-4 text-center"><?= htmlspecialchars(ucfirst($invoice['status'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../src/php/partials/delete-confirmation.php'; ?>
</body>
</html>
