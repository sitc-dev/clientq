<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../src/php/stripe-billing.php';
requireOwner();
header('Cache-Control: no-store');

$staff = getLoggedInStaff();
$tenant = requireTenant();
$csrfToken = platformCsrfToken();
$stmt = getDB()->prepare(
    'SELECT id, invoice_number, description, amount_cents, currency, status, due_date
     FROM invoices WHERE business_id = :business_id ORDER BY created_at DESC, id DESC'
);
$stmt->execute([':business_id' => tenantId()]);
$invoices = $stmt->fetchAll();
$stmt = getDB()->prepare(
    'SELECT subscription_id, amount_cents, environment FROM paypal_signup_subscriptions WHERE business_id = ?'
);
$stmt->execute([tenantId()]);
$subscription = $stmt->fetch();
$stmt = getDB()->prepare(
    'SELECT subscription_id, amount_cents, environment FROM stripe_signup_subscriptions WHERE business_id = ?'
);
$stmt->execute([tenantId()]);
$stripeSubscription = $stmt->fetch();

adminHeader('Invoices & Payments', $staff, 'billing');
?>
<section class="p-4 md:p-6">
    <p class="mb-5 text-sm text-gray-600">Appointment scheduling: <?= appointmentsEnabled($tenant) ? ($tenant['plan'] === 'starter' ? 'Essentials add-on ($5/month, included in your $44/month subscription).' : 'Included in your plan.') : 'Not included in your Essentials waitlist-only plan.' ?></p>
    <?php if ($subscription): ?>
    <div class="mb-6 rounded-xl border border-gray-200 p-4">
        <h2 class="font-bold">PayPal monthly subscription</h2>
        <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars(formatMoney((int)$subscription['amount_cents'], 'usd')) ?>/month. PayPal manages recurring charges and cancellation; separate invoices are not subscription payments.</p>
        <p class="mt-2 text-sm break-all">Subscription: <?= htmlspecialchars($subscription['subscription_id']) ?><?= $subscription['environment'] === 'sandbox' ? ' (sandbox test)' : '' ?></p>
        <a href="<?= $subscription['environment'] === 'sandbox' ? 'https://www.sandbox.paypal.com/myaccount/autopay/' : 'https://www.paypal.com/myaccount/autopay/' ?>" class="mt-2 inline-block font-semibold text-accent underline" target="_blank" rel="noopener noreferrer">Manage or cancel in PayPal</a>
    </div>
    <?php endif; ?>
    <?php if ($stripeSubscription): ?>
    <div class="mb-6 rounded-xl border border-gray-200 p-4">
        <h2 class="font-bold">Card monthly subscription</h2>
        <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars(formatMoney((int)$stripeSubscription['amount_cents'], 'usd')) ?>/month. Stripe Billing manages monthly card charges; separate invoices are not subscription payments.</p>
        <p class="mt-2 text-sm break-all">Subscription: <?= htmlspecialchars($stripeSubscription['subscription_id']) ?><?= $stripeSubscription['environment'] === 'test' ? ' (test mode)' : '' ?></p>
        <button id="manageSubscription" class="mt-2 font-semibold text-accent underline">Manage card or cancel subscription</button>
    </div>
    <?php endif; ?>
    <h2 class="text-xl font-bold">Business invoices</h2>
    <p class="mt-1 mb-5 text-sm text-gray-500">View invoices and pay securely through Stripe. Only business owners can access payments.</p>
    <div id="message" role="alert" aria-live="assertive" class="hidden rounded-lg px-4 py-3 mb-4"></div>
    <?php if (($_GET['payment'] ?? '') === 'success'): ?>
    <p role="status" class="rounded-lg bg-blue-50 text-blue-800 p-4 mb-4">Checkout returned. Invoice status updates when payment is confirmed by Stripe.</p>
    <?php elseif (($_GET['payment'] ?? '') === 'cancelled'): ?>
    <p role="status" class="rounded-lg bg-amber-50 text-amber-800 p-4 mb-4">Checkout was cancelled. You can try paying an open invoice again.</p>
    <?php endif; ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr><th class="text-left p-4">Invoice</th><th class="text-left p-4">Description</th><th class="text-left p-4">Due</th><th class="text-right p-4">Amount</th><th class="text-center p-4">Status</th><th class="p-4"></th></tr>
            </thead>
            <tbody class="divide-y">
            <?php if (!$invoices): ?>
                <tr><td colspan="6" class="p-8 text-center text-gray-500">No invoices.</td></tr>
            <?php endif; ?>
            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td class="p-4 font-medium"><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                    <td class="p-4"><?= htmlspecialchars($invoice['description']) ?></td>
                    <td class="p-4"><?= $invoice['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($invoice['due_date']))) : '—' ?></td>
                    <td class="p-4 text-right font-semibold"><?= htmlspecialchars(formatMoney((int)$invoice['amount_cents'], $invoice['currency'])) ?></td>
                    <td class="p-4 text-center"><?= htmlspecialchars(ucfirst($invoice['status'])) ?></td>
                    <td class="p-4 text-right">
                        <?php if ($invoice['status'] === 'open'): ?>
                        <button data-invoice-id="<?= (int)$invoice['id'] ?>" class="pay-invoice bg-accent hover:bg-accentDark text-white px-4 py-2 rounded-lg font-semibold">Pay invoice</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
    const csrfToken = <?= json_encode($csrfToken) ?>;
    document.getElementById('manageSubscription')?.addEventListener('click', async function() {
        this.disabled = true;
        try {
            const response = await fetch('api/create-billing-portal', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken })
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Unable to open subscription management.');
            window.location.href = data.portal_url;
        } catch (error) {
            const message = document.getElementById('message');
            message.textContent = error.message;
            message.className = 'bg-red-100 text-red-700 rounded-lg px-4 py-3 mb-4';
            this.disabled = false;
        }
    });
    document.querySelectorAll('.pay-invoice').forEach(button => {
        button.addEventListener('click', async function() {
            this.disabled = true;
            this.textContent = 'Opening Stripe...';
            try {
                const response = await fetch('api/create-checkout-session', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ invoice_id: this.dataset.invoiceId, csrf_token: csrfToken })
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Payment could not be started.');
                if (data.paid) {
                    window.location.reload();
                    return;
                }
                window.location.href = data.checkout_url;
            } catch (error) {
                const message = document.getElementById('message');
                message.textContent = error.message;
                message.className = 'bg-red-100 text-red-700 rounded-lg px-4 py-3 mb-4';
                this.disabled = false;
                this.textContent = 'Pay invoice';
            }
        });
    });
</script>
<?php adminFooter(); ?>
