<?php

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-navigation.php';
require_once __DIR__ . '/marketing-footer.php';
require_once __DIR__ . '/marketing-theme.php';
if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}
require_once __DIR__ . '/paypal.php';
require_once __DIR__ . '/stripe-billing.php';

$plans = ['starter' => planDisplayName('starter') . ' - $39/month', 'growth' => planDisplayName('growth') . ' - $64/month', 'pro' => planDisplayName('pro') . ' - $99/month'];
$selectedPlan = $_GET['plan'] ?? 'growth';
if (!isset($plans[$selectedPlan])) {
    $selectedPlan = 'growth';
}
$selectedAppointments = $selectedPlan === 'starter' && ($_GET['appointments'] ?? '') === '1';
$monthlyAmounts = ['starter' => 39, 'growth' => 64, 'pro' => 99];
$csrfToken = platformCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - ClientQ</title>
    <link rel="icon" href="/assets/clientq-logo-marketing.svg" type="image/svg+xml">
    <?php marketingTheme(); ?>
    <script src="/assets/logo-palette.js"></script>
    <script src="/assets/phone-input.js"></script>
</head>
<body class="marketing-grid bg-canvas bg-gradient-to-br from-canvas via-surface to-raised text-heading min-h-screen">
    <header class="sticky top-0 z-50 border-b border-line bg-surface/95 backdrop-blur">
        <nav class="max-w-6xl mx-auto px-6 py-5 flex flex-wrap items-center justify-between gap-4">
            <a href="/" aria-label="clientQ home">
                <img src="/assets/clientq-logo-marketing.svg" alt="clientQ" class="h-11 w-auto">
            </a>
            <div class="flex items-center gap-5 text-sm font-semibold">
                <?php marketingProductTourMenu('light'); ?>
                <a href="/pricing" class="text-copy hover:text-highlight">View pricing</a>
                <a href="/signup" class="marketing-metal rounded-full bg-action px-5 py-2.5 text-white hover:bg-actionHover">Sign up</a>
            </div>
        </nav>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-10">
        <div class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-start">
        <aside class="min-w-0 rounded-3xl border border-line bg-surface p-6 shadow-xl lg:sticky lg:top-28 lg:p-8">
            <p class="font-semibold text-highlight">Owner tools in your staff portal</p>
            <h1 class="mt-2 text-3xl font-bold">Run your waitlist and team from one place.</h1>
            <p class="mt-4 leading-relaxed text-copy">
                Every ClientQ includes secure owner tools for managing the client experience and keeping employee information current.
            </p>
            <div class="mt-7 space-y-4">
                <article class="rounded-2xl bg-raised p-4">
                    <h2 class="font-bold">Live client queue</h2>
                    <p class="mt-1 text-sm text-copy">Review waiting clients, phone numbers, party sizes, elapsed wait times, and confirmation codes.</p>
                </article>
                <article class="rounded-2xl bg-raised p-4">
                    <h2 class="font-bold">Employee management</h2>
                    <p class="mt-1 text-sm text-copy">Create employee accounts, assign roles, update profiles, and deactivate access when needed.</p>
                </article>
                <article class="rounded-2xl bg-raised p-4">
                    <h2 class="font-bold">Business branding</h2>
                    <p class="mt-1 text-sm text-copy">Upload your logo and choose colors used across the public waitlist, sign-in, and staff profile screens.</p>
                </article>
                <article class="rounded-2xl bg-raised p-4">
                    <h2 class="font-bold">Secure account controls</h2>
                    <p class="mt-1 text-sm text-copy">Maintain your profile and password while keeping staff access isolated to your business.</p>
                </article>
            </div>
            <a href="/team-dashboard-screen" class="mt-7 inline-block font-semibold text-highlight underline decoration-highlight/40 decoration-2 underline-offset-4 hover:text-actionHover">
                Explore Galley View
            </a>
        </aside>

        <section class="min-w-0 bg-surface text-heading rounded-3xl shadow-2xl p-6 lg:p-8 border border-line">
            <h1 class="text-3xl font-bold mb-1">Create your ClientQ</h1>
            <p class="text-muted mb-6">Set up your staff portal, owner login, and dedicated database.</p>
            <div id="message" role="alert" aria-live="assertive" tabindex="-1"
                class="hidden rounded-lg px-4 py-3 mb-4 text-sm"></div>

            <form id="signupForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" for="plan">Plan</label>
                    <select id="plan" class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none">
                        <?php foreach ($plans as $id => $label): ?>
                        <option value="<?= $id ?>" <?= $id === $selectedPlan ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="appointmentAddon" class="<?= $selectedPlan === 'starter' ? '' : 'hidden ' ?>rounded-xl border border-line bg-raised p-4">
                    <label class="flex items-start gap-3" for="appointmentsEnabled">
                        <input id="appointmentsEnabled" type="checkbox" <?= $selectedAppointments ? 'checked' : '' ?> class="mt-1">
                        <span><span class="font-semibold">Add appointment scheduling for $5/month</span><span class="mt-1 block text-sm text-copy">Essentials includes your waitlist for $39/month. Add staff schedules and online appointment booking for a total of $44/month.</span></span>
                    </label>
                </div>
                <p id="appointmentsIncluded" class="<?= $selectedPlan === 'starter' ? 'hidden ' : '' ?>text-sm text-copy">Appointment scheduling is included in Select and Premier at no additional charge.</p>
                <div>
                    <label class="block text-sm font-semibold mb-1" for="businessName">Business name</label>
                    <input id="businessName" required maxlength="120" class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none" placeholder="Acme Barbers">
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" for="firstName">First name</label>
                        <input id="firstName" required maxlength="50" autocomplete="given-name" class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" for="lastName">Last name</label>
                        <input id="lastName" required maxlength="50" autocomplete="family-name" class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none">
                    </div>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" for="email">Owner email/Username</label>
                        <input id="email" type="email" required maxlength="255" autocomplete="email" class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" for="confirmEmail">Confirm owner email</label>
                        <input id="confirmEmail" type="email" required maxlength="255" autocomplete="email" class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" for="phone">Phone number</label>
                    <input id="phone" type="tel" required inputmode="numeric" autocomplete="tel" placeholder="(xxx)xxx-xxxx"
                        class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" for="logo">Business logo <span class="font-normal text-muted">(optional)</span></label>
                    <input id="logo" type="file" accept="image/png,image/jpeg,image/webp"
                        class="block w-full text-sm border border-muted bg-surface rounded-lg file:border-0 file:bg-raised file:px-4 file:py-3 file:mr-4">
                    <p class="text-xs text-muted mt-1">PNG, JPEG, or WebP up to 2 MB. ClientQ will select accessible colors from the logo.</p>
                    <div id="brandingPreview" class="hidden mt-3 rounded-xl border border-line bg-surface p-4">
                        <img id="logoPreview" class="max-h-24 max-w-full object-contain mx-auto" alt="Business logo preview">
                        <div class="mt-3 flex justify-center gap-3 text-xs font-mono">
                            <span class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                                <span id="primarySwatch" class="h-5 w-5 rounded-full border"></span>
                                <span id="primaryLabel"></span>
                            </span>
                            <span class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                                <span id="accentSwatch" class="h-5 w-5 rounded-full border"></span>
                                <span id="accentLabel"></span>
                            </span>
                        </div>
                    </div>
                    <input type="hidden" id="themePrimary" value="<?= htmlspecialchars(TENANT_THEME_DEFAULT_PRIMARY) ?>">
                    <input type="hidden" id="themeAccent" value="<?= htmlspecialchars(TENANT_THEME_DEFAULT_ACCENT) ?>">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" for="password">Password</label>
                    <input id="password" type="password" required minlength="8" autocomplete="new-password" class="w-full border border-muted bg-surface rounded-lg px-3 py-2 focus:ring-2 focus:ring-action focus:outline-none">
                    <p class="text-xs text-muted mt-1">At least 8 characters.</p>
                </div>
                <fieldset class="rounded-2xl border border-line bg-raised p-4">
                    <legend class="px-1 text-sm font-semibold">Payment</legend>
                    <p class="font-bold">Monthly subscription</p>
                    <p id="signupTotal" class="mt-2 text-lg font-bold">$<?= $monthlyAmounts[$selectedPlan] + ($selectedAppointments ? 5 : 0) ?>/month total</p>
                    <p class="mt-1 text-sm text-copy">Review your details, then pay by credit/debit card through Stripe or with PayPal. Your plan renews monthly until you cancel. Your first payment must be verified before your waitlist page and owner/staff portal are created.</p>
                    <?php if ((getenv('CLIENTQ_PAYPAL_ENVIRONMENT') ?: 'sandbox') === 'sandbox'): ?>
                    <p class="mt-2 text-sm font-semibold text-highlight">PayPal sandbox: test payments only, no real charges.</p>
                    <?php endif; ?>
                    <p id="paymentUnavailable" role="status" class="mt-2 text-sm text-red-700 <?= paypalConfigured($selectedPlan, $selectedAppointments) || stripeBillingConfigured() ? 'hidden' : '' ?>">Checkout is not configured for this selection yet. You can review your signup, but payment and account creation are unavailable.</p>
                </fieldset>
                <button id="submitButton" class="marketing-metal w-full bg-action hover:bg-actionHover text-white py-3 rounded-full font-semibold transition">
                    Review signup
                </button>
            </form>
        </section>
        </div>
    </main>
    <?php marketingFooter(); ?>

    <script>
        const businessInput = document.getElementById('businessName');
        const phoneInput = document.getElementById('phone');
        const emailInput = document.getElementById('email');
        const confirmEmailInput = document.getElementById('confirmEmail');
        const logoInput = document.getElementById('logo');
        const paypalPlansAvailable = <?= json_encode(array_map('paypalConfigured', array_keys($plans))) ?>;
        const paypalAppointmentsAvailable = <?= json_encode(paypalConfigured('starter', true)) ?>;
        const stripeAvailable = <?= json_encode(stripeBillingConfigured()) ?>;
        const monthlyAmounts = <?= json_encode($monthlyAmounts) ?>;
        const planInput = document.getElementById('plan');
        const appointmentsInput = document.getElementById('appointmentsEnabled');
        function updateSignupPlan() {
            const essentials = planInput.value === 'starter';
            const addon = essentials && appointmentsInput.checked;
            document.getElementById('appointmentAddon').classList.toggle('hidden', !essentials);
            document.getElementById('appointmentsIncluded').classList.toggle('hidden', essentials);
            document.getElementById('signupTotal').textContent = `$${monthlyAmounts[planInput.value] + (addon ? 5 : 0)}/month total`;
            document.getElementById('paymentUnavailable').classList.toggle('hidden', stripeAvailable || (addon ? paypalAppointmentsAvailable : paypalPlansAvailable[planInput.selectedIndex]));
        }
        planInput.addEventListener('change', updateSignupPlan);
        appointmentsInput.addEventListener('change', updateSignupPlan);

        function validateEmailConfirmation() {
            const matches = emailInput.value.trim().toLowerCase() === confirmEmailInput.value.trim().toLowerCase();
            confirmEmailInput.setCustomValidity(matches ? '' : 'Email addresses do not match.');
            return matches;
        }
        emailInput.addEventListener('input', validateEmailConfirmation);
        confirmEmailInput.addEventListener('input', validateEmailConfirmation);

        function slugify(value) {
            return value.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 63);
        }

        phoneInput.addEventListener('input', function() {
            this.value = phoneDigits(this.value);
            this.setCustomValidity('');
        });

        phoneInput.addEventListener('blur', function() {
            this.value = formatPhone(this.value);
        });

        logoInput.addEventListener('change', async function() {
            const file = this.files[0];
            if (!file) {
                document.getElementById('brandingPreview').classList.add('hidden');
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                this.value = '';
                this.setCustomValidity('Logo must be no larger than 2 MB.');
                this.reportValidity();
                return;
            }

            this.setCustomValidity('');
            const preview = document.getElementById('logoPreview');
            preview.src = URL.createObjectURL(file);
            document.getElementById('brandingPreview').classList.remove('hidden');

            try {
                const palette = await ClientQLogoPalette.extract(file);
                setPalette(palette.primary, palette.accent);
            } catch (error) {
                setPalette(
                    <?= json_encode(TENANT_THEME_DEFAULT_PRIMARY) ?>,
                    <?= json_encode(TENANT_THEME_DEFAULT_ACCENT) ?>
                );
            }
        });

        function setPalette(primary, accent) {
            document.getElementById('themePrimary').value = primary;
            document.getElementById('themeAccent').value = accent;
            document.getElementById('primarySwatch').style.background = primary;
            document.getElementById('accentSwatch').style.background = accent;
            document.getElementById('primaryLabel').textContent = primary;
            document.getElementById('accentLabel').textContent = accent;
        }

        document.getElementById('signupForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = document.getElementById('submitButton');
            const message = document.getElementById('message');
            const phone = phoneDigits(phoneInput.value);

            if (!validateEmailConfirmation()) {
                confirmEmailInput.reportValidity();
                return;
            }

            if (phone.length !== 10) {
                phoneInput.setCustomValidity('Enter a valid 10-digit phone number.');
                phoneInput.reportValidity();
                return;
            }

            button.disabled = true;
            button.textContent = 'Reviewing...';
            message.classList.add('hidden');

            try {
                const formData = new FormData();
                formData.append('csrf_token', <?= json_encode($csrfToken) ?>);
                formData.append('plan', document.getElementById('plan').value);
                formData.append('appointments_enabled', planInput.value !== 'starter' || appointmentsInput.checked ? '1' : '0');
                formData.append('business_name', businessInput.value);
                formData.append('slug', slugify(businessInput.value));
                formData.append('first_name', document.getElementById('firstName').value);
                formData.append('last_name', document.getElementById('lastName').value);
                formData.append('email', document.getElementById('email').value);
                formData.append('phone', phone);
                formData.append('password', document.getElementById('password').value);
                formData.append('theme_primary', document.getElementById('themePrimary').value);
                formData.append('theme_accent', document.getElementById('themeAccent').value);
                if (logoInput.files[0]) formData.append('logo', logoInput.files[0]);

                const response = await fetch('/api/prepare-business-registration', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Unable to create your account.');
                window.location.href = data.confirmation_url;
            } catch (error) {
                message.textContent = error.message;
                message.className = 'rounded-lg px-4 py-3 mb-4 text-sm bg-red-100 text-red-700';
                message.focus();
            } finally {
                button.disabled = false;
                button.textContent = 'Review signup';
            }
        });
    </script>
</body>
</html>
