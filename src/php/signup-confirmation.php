<?php

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-footer.php';
require_once __DIR__ . '/marketing-theme.php';
require_once dirname(__DIR__, 2) . '/lib/business-registration.php';

requirePlatformHost();
$pending = pendingBusinessRegistration();
if ($pending === null) {
    header('Location: /signup');
    exit;
}

$plans = ['starter' => planDisplayName('starter') . ' - $39/month', 'growth' => planDisplayName('growth') . ' - $64/month', 'pro' => planDisplayName('pro') . ' - $99/month'];
$csrfToken = platformCsrfToken();
$paymentSignup = signupPaymentRecord();
$paymentStarted = $paymentSignup !== null;
$paymentProvider = $paymentSignup['provider'] ?? null;
$appointments = appointmentsEnabled($pending);
$appointmentAddon = $pending['plan'] === 'starter' && $appointments;
$paypalAvailable = paypalConfigured($pending['plan'], $appointments);
$stripeAvailable = stripeBillingConfigured();
[$planName, $monthlyPrice] = explode(' - ', $plans[$pending['plan']]);
if ($appointmentAddon) {
    $monthlyPrice = '$44/month';
}
$tenantWebsite = tenantUrl($pending['slug'], '/');
$themePrimary = normalizeThemeColor(
    $pending['theme_primary'] ?? null,
    TENANT_THEME_DEFAULT_PRIMARY
);
$themeAccent = normalizeThemeColor(
    $pending['theme_accent'] ?? null,
    TENANT_THEME_DEFAULT_ACCENT
);
$hasLogo = is_array($pending['logo'] ?? null);
$phone = sprintf(
    '(%s)%s-%s',
    substr($pending['phone'], 0, 3),
    substr($pending['phone'], 3, 3),
    substr($pending['phone'], 6)
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Signup - ClientQ</title>
    <link rel="icon" href="/assets/clientq-logo-marketing.svg" type="image/svg+xml">
    <?php marketingTheme(); ?>
</head>
<body class="marketing-grid min-h-screen bg-canvas bg-gradient-to-br from-canvas via-surface to-raised px-6 py-10 text-heading">
    <main class="mx-auto max-w-6xl pb-[30px]">
        <a href="/" aria-label="clientQ home">
            <img src="/assets/clientq-logo-marketing.svg" alt="clientQ" class="h-11 w-auto">
        </a>

        <section class="mt-8 rounded-3xl border border-line bg-surface p-6 text-heading shadow-2xl sm:p-8">
            <div id="reviewPanel">
                <div class="grid items-start gap-8 md:grid-cols-2">
                <div class="min-w-0">
                <p class="font-semibold text-highlight">Review before creating</p>
                <h1 class="mt-1 text-3xl font-bold">Confirm your ClientQ details</h1>
                <p class="mt-2 text-muted">Review these details before subscribing. After your first monthly payment is verified, ClientQ automatically creates your waitlist page and owner/staff portal.</p>

                <?php if ($hasLogo): ?>
                    <div class="mt-6 rounded-2xl border border-line bg-surface p-5 text-center">
                        <img src="/api/pending-business-logo?version=<?= (int)$pending['created_at'] ?>"
                            alt="<?= htmlspecialchars($pending['business_name']) ?> logo"
                            class="mx-auto max-h-28 max-w-full object-contain">
                        <div class="mt-4 flex justify-center gap-3 text-xs font-mono">
                            <span class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                                <span class="h-5 w-5 rounded-full border" style="background: <?= htmlspecialchars($themePrimary) ?>"></span>
                                <?= htmlspecialchars($themePrimary) ?>
                            </span>
                            <span class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                                <span class="h-5 w-5 rounded-full border" style="background: <?= htmlspecialchars($themeAccent) ?>"></span>
                                <?= htmlspecialchars($themeAccent) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <dl class="mt-7 divide-y divide-line rounded-2xl border border-line bg-surface [&_dd]:min-w-0 [&_dd]:break-words">
                    <div class="grid gap-1 p-4 lg:grid-cols-[7rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-muted">Business</dt>
                        <dd><?= htmlspecialchars($pending['business_name']) ?></dd>
                    </div>
                    <div class="grid gap-1 p-4 lg:grid-cols-[7rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-muted">Plan</dt>
                        <dd><?= htmlspecialchars($plans[$pending['plan']]) ?></dd>
                    </div>
                    <div class="grid gap-1 p-4 lg:grid-cols-[7rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-muted">Appointments</dt>
                        <dd><?= $pending['plan'] !== 'starter' ? 'Included' : ($appointments ? 'Add-on: +$5/month' : 'Not selected (waitlist only)') ?></dd>
                    </div>
                    <div class="grid gap-1 p-4 lg:grid-cols-[7rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-muted">Owner</dt>
                        <dd><?= htmlspecialchars($pending['owner_name']) ?></dd>
                    </div>
                    <div class="grid gap-1 p-4 lg:grid-cols-[7rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-muted">Email</dt>
                        <dd><?= htmlspecialchars($pending['email']) ?></dd>
                    </div>
                    <div class="grid gap-1 p-4 lg:grid-cols-[7rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-muted">Phone</dt>
                        <dd><?= htmlspecialchars($phone) ?></dd>
                    </div>
                </dl>
                </div>

                <section id="paypalCheckout" aria-labelledby="paypalHeading" class="min-w-0 overflow-hidden rounded-2xl border border-line bg-white">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-raised px-5 py-4">
                        <h2 id="paypalHeading" class="font-bold">Subscription checkout</h2>
                        <span aria-label="PayPal" class="text-2xl font-bold italic tracking-tight"><span class="text-[#003087]">Pay</span><span class="text-[#0070BA]">Pal</span></span>
                    </div>
                    <div class="p-5">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="text-sm text-muted">Your subscription</p>
                                <p class="mt-1 text-lg font-bold"><?= htmlspecialchars($planName) ?> plan</p>
                            </div>
                            <p class="text-2xl font-bold text-[#003087]"><?= htmlspecialchars($monthlyPrice) ?></p>
                        </div>
                        <?php if ($appointmentAddon): ?>
                        <p class="mt-3 text-sm text-copy">Waitlist: $39/month + appointment scheduling: $5/month = $44/month total.</p>
                        <?php endif; ?>
                        <p class="mt-3 text-sm text-copy">Billed in USD. Renews automatically every month until you cancel through your payment provider. No trial or setup fee.</p>
                        <div class="mt-5 rounded-xl border border-line p-4">
                            <p class="font-bold">Credit or debit card</p>
                            <p class="mt-1 text-sm text-copy">Secure card checkout through Stripe. No PayPal account required.</p>
                            <?php if ($stripeAvailable && stripeBillingEnvironment() === 'test'): ?>
                            <p class="mt-2 text-sm font-semibold text-highlight">Stripe test mode: use a test card. No real money is charged.</p>
                            <?php elseif (!$stripeAvailable): ?>
                            <p role="status" class="mt-2 text-sm text-red-700">Card checkout is not configured yet. PayPal remains available when configured.</p>
                            <?php endif; ?>
                            <button id="cardSubscribeButton" <?= $stripeAvailable && $paymentProvider !== 'paypal' ? '' : 'disabled' ?> class="marketing-metal mt-3 w-full rounded-full bg-action px-5 py-3 font-semibold text-white hover:bg-actionHover disabled:opacity-50 disabled:cursor-not-allowed"><?= $paymentProvider === 'stripe' ? 'Resume card checkout' : 'Pay with credit/debit card' ?></button>
                        </div>
                        <p class="mt-5 text-sm font-semibold">Or subscribe with PayPal</p>
                    <?php if ((getenv('CLIENTQ_PAYPAL_ENVIRONMENT') ?: 'sandbox') === 'sandbox'): ?>
                        <p class="mt-3 rounded-lg bg-raised px-3 py-2 text-sm font-semibold text-highlight">Sandbox checkout uses a PayPal test buyer account. No real money is charged.</p>
                    <?php endif; ?>
                    <?php if (!$paypalAvailable): ?>
                        <p role="status" class="mt-3 text-sm text-red-700">PayPal checkout is not configured for this plan yet.</p>
                    <?php elseif (($_GET['payment'] ?? '') === 'cancelled'): ?>
                        <p role="status" class="mt-3 text-sm text-copy">You returned without completing checkout. No account was created. Resume the same checkout below.</p>
                    <?php elseif ($paymentStarted): ?>
                        <p role="status" class="mt-3 text-sm text-copy">Your <?= $paymentProvider === 'stripe' ? 'card' : 'PayPal' ?> signup is saved. Check payment below to finish; do not start another subscription.</p>
                    <?php endif; ?>
                        <div class="mt-5">
                            <paypal-button id="paypalSubscribeButton" type="subscribe" hidden></paypal-button>
                            <button id="confirmButton" <?= $paypalAvailable && $paymentProvider !== 'stripe' ? '' : 'disabled' ?> class="w-full rounded-full bg-[#FFC439] px-5 py-3 font-semibold text-[#003087] hover:bg-[#F2BA36] focus:outline-none focus:ring-2 focus:ring-[#0070BA] focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed">
                                <?= $paymentProvider === 'paypal' ? 'Resume PayPal checkout' : 'Subscribe with PayPal' ?>
                            </button>
                        </div>
                        <p class="mt-3 text-center text-xs text-muted">Stripe or PayPal securely handles payment details. ClientQ never receives your card number or PayPal password.</p>
                        <button id="verifyPaymentButton" <?= ($paymentProvider === 'stripe' ? $stripeAvailable : $paypalAvailable) ? '' : 'disabled' ?> class="<?= $paymentStarted ? '' : 'hidden ' ?>mt-4 w-full rounded-full border border-line px-5 py-3 text-center font-semibold hover:bg-raised disabled:opacity-50">Check payment and create account</button>
                    </div>
                </section>
                </div>
                <div id="message" role="alert" aria-live="assertive" tabindex="-1" class="hidden mt-5 rounded-lg px-4 py-3 text-sm"></div>
                    <?php if (!$paymentStarted): ?>
                <a id="backToSignup" href="/signup" class="mt-5 block rounded-full border border-line px-5 py-3 text-center font-semibold hover:bg-raised">Go back</a>
                    <?php endif; ?>
            </div>

            <div id="successPanel" class="hidden text-center">
                <p class="font-semibold text-green-700">ClientQ created</p>
                <h1 class="mt-1 text-3xl font-bold"><?= htmlspecialchars($pending['business_name']) ?> is ready</h1>
                <p class="mt-3 text-muted">Your first payment was verified. Your waitlist page and owner/staff portal were created successfully.</p>
                <p class="mt-3 break-all text-sm font-semibold text-copy"><?= htmlspecialchars($tenantWebsite) ?></p>
                <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:justify-center">
                    <a href="<?= htmlspecialchars($tenantWebsite) ?>" target="_blank" rel="noopener noreferrer"
                        class="rounded-full border border-line bg-surface px-7 py-3 font-semibold text-heading hover:bg-raised">
                        Open tenant website
                    </a>
                    <a id="loginLink" href="#" target="_blank" rel="noopener noreferrer" class="marketing-metal rounded-full bg-action px-7 py-3 font-semibold text-white hover:bg-actionHover">
                        Open tenant admin
                    </a>
                </div>
            </div>
        </section>
    </main>
    <?php marketingFooter(); ?>

    <script>
        const csrfToken = <?= json_encode($csrfToken) ?>;
        const checkoutButton = document.getElementById('confirmButton');
        const verifyButton = document.getElementById('verifyPaymentButton');
        const paypalButton = document.getElementById('paypalSubscribeButton');
        const cardButton = document.getElementById('cardSubscribeButton');
        const paypalAvailable = <?= json_encode($paypalAvailable) ?>;
        const stripeAvailable = <?= json_encode($stripeAvailable) ?>;
        let paymentStarted = <?= json_encode($paymentStarted) ?>;
        let paymentProvider = <?= json_encode($paymentProvider) ?>;
        let checkoutOperations = 0;

        function updateCheckoutControls() {
            const busy = checkoutOperations > 0;
            const paypalDisabled = busy || !paypalAvailable || paymentProvider === 'stripe';
            checkoutButton.disabled = paypalDisabled;
            paypalButton.toggleAttribute('disabled', paypalDisabled);
            cardButton.disabled = busy || !stripeAvailable || paymentProvider === 'paypal';
            verifyButton.disabled = busy || !paymentStarted || !(paymentProvider === 'stripe' ? stripeAvailable : paypalAvailable);
        }

        function showPaymentMessage(text) {
            const message = document.getElementById('message');
            message.textContent = text;
            message.className = 'mt-5 rounded-lg px-4 py-3 text-sm bg-red-100 text-red-700';
            message.focus();
        }

        function markPaymentStarted(provider = 'paypal') {
            paymentStarted = true;
            paymentProvider = provider;
            verifyButton.classList.remove('hidden');
            document.getElementById('backToSignup')?.classList.add('hidden');
            updateCheckoutControls();
        }

        async function signupRequest(url) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken })
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Unable to complete your signup.');
            return data;
        }

        async function finishSignup() {
            const data = await signupRequest('/api/register-business');
            document.getElementById('loginLink').href = data.login_url;
            document.getElementById('reviewPanel').classList.add('hidden');
            document.getElementById('successPanel').classList.remove('hidden');
        }

        async function runSignupAction(button, action, loadingText, fromPayPalApproval = false) {
            if ((!fromPayPalApproval && checkoutOperations > 0) || (!paypalAvailable && !stripeAvailable)) return;
            checkoutOperations++;
            const message = document.getElementById('message');
            const label = button.textContent;
            updateCheckoutControls();
            button.textContent = loadingText;
            message.classList.add('hidden');
            try {
                await action();
            } catch (error) {
                showPaymentMessage(error.message);
            } finally {
                checkoutOperations--;
                updateCheckoutControls();
                button.textContent = label;
            }
        }

        checkoutButton.addEventListener('click', () => {
            if (!paypalAvailable || paymentProvider === 'stripe') return;
            runSignupAction(checkoutButton, async () => {
                const data = await signupRequest('/api/create-paypal-subscription');
                markPaymentStarted();
                if (data.verify_payment) {
                    await finishSignup();
                } else {
                    window.location.href = data.approval_url;
                }
            }, 'Opening PayPal...');
        });
        cardButton.addEventListener('click', () => {
            if (!stripeAvailable || paymentProvider === 'paypal') return;
            return runSignupAction(cardButton, async () => {
                const data = await signupRequest('/api/create-stripe-subscription');
                markPaymentStarted('stripe');
                if (data.verify_payment) {
                    await finishSignup();
                } else {
                    window.location.href = data.checkout_url;
                }
            }, 'Opening secure card checkout...');
        });
        verifyButton.addEventListener('click', () => runSignupAction(verifyButton, finishSignup, 'Checking payment...'));
            <?php if (($_GET['payment'] ?? '') === 'approved' && $paymentStarted && ($paymentProvider === 'stripe' ? $stripeAvailable : $paypalAvailable)): ?>
            runSignupAction(verifyButton, finishSignup, 'Checking payment...');
            <?php endif; ?>

        async function onPayPalWebSdkLoaded() {
            try {
                const sdk = await window.paypal.createInstance({
                    clientId: <?= json_encode(paypalSetting('CLIENT_ID')) ?>,
                    components: ['paypal-subscriptions'],
                    pageType: 'checkout'
                });
                const methods = await sdk.findEligibleMethods({ paymentFlow: 'RECURRING_PAYMENT', currencyCode: 'USD' });
                if (!methods.isEligible('paypal')) {
                    showPaymentMessage('Embedded PayPal checkout is not available in this browser. Use the PayPal button to continue on PayPal.');
                    return;
                }
                const session = sdk.createPayPalSubscriptionPaymentSession({
                    onApprove: () => runSignupAction(verifyButton, finishSignup, 'Checking payment...', true),
                    onCancel: () => showPaymentMessage('PayPal checkout was cancelled. No account was created. Resume checkout or check an existing payment below.'),
                    onError: () => showPaymentMessage('PayPal checkout could not finish. Check an existing payment before retrying; do not pay twice.')
                });
                paypalButton.addEventListener('click', async () => {
                    if (checkoutOperations > 0 || paymentProvider === 'stripe') return;
                    checkoutOperations++;
                    updateCheckoutControls();
                    document.getElementById('message').classList.add('hidden');
                    try {
                        const subscription = signupRequest('/api/create-paypal-subscription').then(data => {
                            markPaymentStarted();
                            if (!data.subscription_id) throw new Error('PayPal did not return a subscription. Please try again.');
                            return { subscriptionId: data.subscription_id };
                        });
                        await session.start({ presentationMode: 'auto' }, subscription);
                    } catch (error) {
                        showPaymentMessage(error.message || 'PayPal checkout could not start. Please try again.');
                    } finally {
                        checkoutOperations--;
                        updateCheckoutControls();
                    }
                });
                if (!paymentStarted && checkoutOperations === 0) {
                    checkoutButton.classList.add('hidden');
                    paypalButton.removeAttribute('hidden');
                }
            } catch (error) {
                showPaymentMessage('Embedded PayPal checkout could not load. Use the PayPal button to continue securely on PayPal.');
            }
        }
    </script>
    <?php if ($paypalAvailable): ?>
    <script async src="<?= paypalEnvironment() === 'sandbox' ? 'https://www.sandbox.paypal.com/web-sdk/v6/core' : 'https://www.paypal.com/web-sdk/v6/core' ?>"
        onload="onPayPalWebSdkLoaded()" onerror="showPaymentMessage('Embedded PayPal checkout could not load. Use the PayPal button to continue securely on PayPal.')"></script>
    <?php endif; ?>
</body>
</html>
