<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__, 3) . '/lib/sms-consent.php';
require_once dirname(__DIR__, 3) . '/lib/waitlist-hours.php';

$tenant = requireTenant();
$db = getTenantDB();
$dailyState = waitlistDailyState($db, waitlistNow());
$staffMembers = $dailyState['staff'];
$appointments = appointmentsEnabled($tenant);

?>
        <section id="sign-in" class="signup-panel hidden" role="tabpanel" aria-labelledby="signupTab"
            style="--signup-surface: <?= htmlspecialchars(mixThemeColor($theme['accent'], '#FFFFFF', 0.91)) ?>; --signup-grid: <?= htmlspecialchars(mixThemeColor($theme['accent'], '#FFFFFF', 0.84)) ?>; --appointment-surface: <?= htmlspecialchars($theme['primary_soft']) ?>; --appointment-highlight: <?= htmlspecialchars(mixThemeColor($theme['primary'], '#FFFFFF', 0.96)) ?>;">
            <?php if ($appointments): ?>
            <div role="tablist" aria-label="Sign up or reserve" class="grid grid-cols-2 border-b bg-gray-50 text-sm">
                <button type="button" id="walkinTab" role="tab" aria-selected="true" aria-controls="walk-in-signup"
                    class="reservation-mode-tab waitlist-mode-signup border-b-2 border-accent px-3 py-3 font-semibold text-gray-900">Sign Up</button>
                <button type="button" id="reservationTab" role="tab" aria-selected="false" aria-controls="reservation-schedule" tabindex="-1"
                    class="reservation-mode-tab waitlist-mode-appointment border-b-2 border-transparent px-3 py-3 font-semibold text-gray-600">Book Appointment</button>
            </div>
            <?php endif; ?>
            <div id="walk-in-signup" role="tabpanel" aria-labelledby="<?= $appointments ? 'walkinTab' : 'signupTab' ?>" class="waitlist-mode-signup">
            <div class="waitlist-mode-heading border-b px-4 py-3">
                <h2 class="font-bold text-gray-900">Join the waitlist now</h2>
                <p class="mt-1 text-sm text-gray-700">Walk-in sign-up for your visit today.</p>
            </div>
            <!-- Error Panel (hidden by default) -->
            <div id="errorPanel" class="hidden bg-red-100 border border-red-400 text-red-700 px-4 py-3 m-4 rounded-lg text-sm">
                <ul id="errorList" class="list-disc list-inside space-y-1"></ul>
            </div>

            <!-- Sign-In Form -->
            <p id="waitlistHoursMessage" role="status" aria-live="polite" class="px-4 pt-4 text-sm text-gray-700"><?= htmlspecialchars($dailyState['waitlist']['message']) ?></p>
            <form id="signInForm" class="p-4 space-y-5" novalidate>
                <fieldset id="waitlistSignupFields" class="space-y-5" <?= $dailyState['waitlist']['can_join'] ? '' : 'disabled' ?>>

                <!-- Name -->
                <div>
                    <label for="clientName" class="block text-sm font-semibold text-gray-700 mb-1">Enter name (letters only):</label>
                    <input
                        type="text"
                        id="clientName"
                        name="name"
                        placeholder="Client name"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                </div>

                <!-- No. in Party -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">No. in party (including self):</label>
                    <div class="grid grid-cols-5 gap-2" id="partyButtons">
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                        <button
                            type="button"
                            class="party-btn w-full py-2 rounded-lg border border-gray-300 bg-white text-gray-700 font-semibold shadow hover:bg-gray-100 transition"
                            data-value="<?= $i ?>"
                        >
                            <?= $i ?>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" id="noInParty" name="no_in_party" value="">
                </div>

                <!-- Staff Member -->
                <div>
                    <label for="staffSelect" class="block text-sm font-semibold text-gray-700 mb-1">Select staff member to service you:</label>
                    <select
                        id="staffSelect"
                        name="staff_id"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                        <option value="">Staff Members</option>
                        <option value="any">First available staff</option>
                        <?php foreach ($dailyState['available_staff'] as $staff): ?>
                        <option value="<?= (int)$staff['id'] ?>" data-availability-notice="<?= htmlspecialchars($staff['availability_notice'], ENT_QUOTES) ?>"><?= htmlspecialchars($staff['selection_label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p id="staffAvailabilityNotice" role="status" aria-live="polite" class="hidden mt-2 text-sm text-amber-900"></p>
                </div>

                <!-- Email
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Enter email address:</label>
                    <input
                        type="email"
                        id="clientEmail"
                        name="email"
                        placeholder="Client email address"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                </div>
                -->

                <?php renderTransactionalSmsConsent($businessName); ?>

                <!-- Phone -->
                <div>
                    <label for="clientPhone" class="block text-sm font-semibold text-gray-700 mb-1">Enter phone number:</label>
                    <input
                        type="tel"
                        id="clientPhone"
                        name="phone"
                        placeholder="(xxx)xxx-xxxx"
                        inputmode="numeric"
                        autocomplete="tel"
                        aria-describedby="phoneError"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                    <p id="phoneError" class="hidden mt-1 text-sm text-red-600">Please enter a valid 10-digit phone number.</p>
                </div>

                <!-- Action Buttons -->
                <div class="space-y-3 pt-2">
                    <button
                        type="submit"
                        id="submitBtn"
                        class="w-full bg-accent text-white py-3 rounded-lg font-semibold hover:bg-accentDark transition"
                    >
                        Submit
                    </button>
                    <button
                        type="button"
                        id="resetBtn"
                        class="w-full bg-gray-400 text-white py-3 rounded-lg font-semibold hover:bg-gray-500 transition"
                    >
                        Reset Inputs
                    </button>
                </div>

                </fieldset>
            </form>
            </div>
            <?php if ($appointments) require __DIR__ . '/reservation-schedule.php'; ?>
        </section>

        <section id="qr-code" class="signup-panel hidden p-5 text-center" role="tabpanel" aria-labelledby="qrTab">
            <h2 class="text-xl font-bold text-gray-800">Scan to sign up</h2>
            <p class="mt-2 text-sm text-gray-500">
                Display or print this code so clients can open the <?= htmlspecialchars($businessName) ?> sign-up sheet.
            </p>
            <div class="my-5 flex justify-center">
                <div id="signupQrCode" data-business-name="<?= htmlspecialchars($businessName, ENT_QUOTES) ?>"
                    data-logo="<?= htmlspecialchars($logoUrl ?? '', ENT_QUOTES) ?>" data-color="<?= htmlspecialchars($theme['primary'], ENT_QUOTES) ?>"
                    class="w-full max-w-[320px] rounded-xl border bg-white"></div>
            </div>
            <p id="signupQrUrl" class="break-all text-xs text-gray-500"></p>
            <p id="signupQrMessage" role="status" class="mt-2 text-sm text-gray-600"></p>
            <button type="button" id="downloadQr"
                class="mt-5 w-full rounded-lg bg-accent py-3 font-semibold text-white transition hover:bg-accentDark">
                Download QR Code
            </button>
        </section>

    <script src="/assets/phone-input.js"></script>
    <script src="/assets/tenant-qr.js"></script>
    <script>
        const signupUrl = `${window.location.origin}/#sign-in`;
        let signupQrGeneration = null;
        const signupTabs = Array.from(document.querySelectorAll('.signup-tab'));

        signupTabs.forEach((button, index) => {
            button.addEventListener('click', () => {
                window.location.hash = button.dataset.panel;
            });
            button.addEventListener('keydown', event => {
                let targetIndex;
                if (event.key === 'ArrowRight') targetIndex = (index + 1) % signupTabs.length;
                else if (event.key === 'ArrowLeft') targetIndex = (index + signupTabs.length - 1) % signupTabs.length;
                else if (event.key === 'Home') targetIndex = 0;
                else if (event.key === 'End') targetIndex = signupTabs.length - 1;
                else return;
                event.preventDefault();
                signupTabs[targetIndex].click();
            });
        });
        function updateSignupView(moveFocus = false) {
            const activeButton = signupTabs.find(button => '#' + button.dataset.panel === window.location.hash)
                || signupTabs[0];
            activateSignupTab(activeButton);
            if (moveFocus) activeButton.focus();
        }

        function activateSignupTab(activeButton) {
            signupTabs.forEach(button => {
                const isActive = button === activeButton;
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                button.tabIndex = isActive ? 0 : -1;
                button.classList.toggle('border-accent', isActive);
                button.classList.toggle('bg-white', isActive);
                button.classList.toggle('text-accent', isActive);
                button.classList.toggle('border-transparent', !isActive);
                button.classList.toggle('text-gray-500', !isActive);
            });

            document.querySelectorAll('.signup-panel').forEach(panel => panel.classList.add('hidden'));
            const panelId = activeButton.dataset.panel;
            document.getElementById(panelId).classList.remove('hidden');

            if (panelId === 'qr-code') {
                generateSignupQrCode();
            }
        }

        window.addEventListener('hashchange', () => updateSignupView(true));
        updateSignupView();

        async function generateSignupQrCode() {
            if (signupQrGeneration) return signupQrGeneration;
            document.getElementById('signupQrUrl').textContent = signupUrl;
            const container = document.getElementById('signupQrCode');
            const message = document.getElementById('signupQrMessage');
            const button = document.getElementById('downloadQr');
            button.disabled = true;
            message.textContent = 'Generating QR code...';
            signupQrGeneration = (async () => {
                try {
                    if (!window.ClientQTenantQr) throw new Error('QR code generation is temporarily unavailable.');
                    const result = await ClientQTenantQr.render({
                        url: signupUrl, name: container.dataset.businessName,
                        logo: container.dataset.logo, color: container.dataset.color
                    });
                    container.replaceChildren(result.canvas);
                    message.textContent = result.logoFailed ? 'The logo could not be loaded. Your business name is used instead.' : '';
                    return result.canvas;
                } catch (error) {
                    console.error('ClientQ QR generation failed:', error);
                    message.textContent = error.message || 'QR code generation failed. Please try again.';
                    return null;
                } finally {
                    button.disabled = false;
                }
            })();
            const canvas = await signupQrGeneration;
            if (!canvas) signupQrGeneration = null;
            return canvas;
        }

        document.getElementById('downloadQr').addEventListener('click', async () => {
            const canvas = await generateSignupQrCode();
            if (!canvas) return;
            try {
                const link = document.createElement('a');
                link.download = '<?= htmlspecialchars($tenant['slug'], ENT_QUOTES) ?>-signup-qr.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            } catch (error) {
                console.error('ClientQ QR download failed:', error);
                document.getElementById('signupQrMessage').textContent = 'The QR code could not be downloaded. Please try again.';
            }
        });

        // Name input: allow letters and spaces only
        document.getElementById('clientName').addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z\s]/g, '');
        });

        const phoneInput = document.getElementById('clientPhone');
        const phoneError = document.getElementById('phoneError');

        function setPhoneValidity(isValid) {
            phoneInput.classList.toggle('border-red-500', !isValid);
            phoneInput.classList.toggle('ring-1', !isValid);
            phoneInput.classList.toggle('ring-red-500', !isValid);
            phoneInput.classList.toggle('border-gray-300', isValid);
            phoneInput.setAttribute('aria-invalid', isValid ? 'false' : 'true');
            phoneError.classList.toggle('hidden', isValid);
        }

        phoneInput.addEventListener('input', function() {
            this.value = phoneDigits(this.value);
            if (this.getAttribute('aria-invalid') === 'true') {
                setPhoneValidity(phoneDigits(this.value).length === 10);
            }
        });

        phoneInput.addEventListener('blur', function() {
            this.value = formatPhone(this.value);
        });

        // Party buttons: toggle selection
        let selectedParty = null;
        document.querySelectorAll('.party-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Deselect previously selected
                document.querySelectorAll('.party-btn').forEach(b => {
                    b.classList.remove('bg-accent', 'text-white', 'shadow-none');
                    b.classList.add('bg-white', 'text-gray-700', 'shadow');
                });

                // Select this one
                this.classList.remove('bg-white', 'text-gray-700', 'shadow');
                this.classList.add('bg-accent', 'text-white', 'shadow-none');
                selectedParty = this.dataset.value;
                document.getElementById('noInParty').value = selectedParty;
            });
        });

        // Reset button
        document.getElementById('resetBtn').addEventListener('click', function() {
            document.getElementById('clientName').value = '';
            phoneInput.value = '';
            setPhoneValidity(true);
            document.getElementById('staffSelect').selectedIndex = 0;
            updateStaffAvailabilityNotice();
            document.getElementById('noInParty').value = '';
            selectedParty = null;

            document.querySelectorAll('.party-btn').forEach(b => {
                b.classList.remove('bg-accent', 'text-white', 'shadow-none');
                b.classList.add('bg-white', 'text-gray-700', 'shadow');
            });
            document.getElementById('staffSelect').addEventListener('change', () => updateStaffAvailabilityNotice());

            document.getElementById('errorPanel').classList.add('hidden');
        });

        // Form submission with validation
        document.getElementById('signInForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            if (document.getElementById('waitlistSignupFields').disabled) return;

            const errors = [];
            const name = document.getElementById('clientName').value.trim();
            const phone = phoneDigits(phoneInput.value);
            const staffId = document.getElementById('staffSelect').value;
            const partySize = document.getElementById('noInParty').value;

            // Validate name
            if (!name || name.length === 0) {
                errors.push('Please enter your name.');
            }

            // Validate party size
            if (!partySize) {
                errors.push('Please select the number in your party.');
            }

            // Validate staff selection
            if (!staffId) {
                errors.push('Please select a staff member.');
            }

            // Validate phone
            const phoneIsValid = phone.length === 10;
            setPhoneValidity(phoneIsValid);
            if (!phoneIsValid) {
                errors.push('Please enter a valid 10-digit phone number.');
            }

            if (errors.length > 0) {
                const errorPanel = document.getElementById('errorPanel');
                const errorList = document.getElementById('errorList');
                errorList.innerHTML = errors.map(e => `<li>${e}</li>`).join('');
                errorPanel.classList.remove('hidden');
                return;
            }

            // Hide errors
            document.getElementById('errorPanel').classList.add('hidden');

            // Submit via AJAX
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            try {
                const res = await fetch('/api/submit-signin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name,
                        phone,
                        staff_id: staffId,
                        no_in_party: partySize,
                        sms_consent: true
                    })
                });
                const data = await res.json();

                if (data.success) {
                    const params = new URLSearchParams({
                        confirmed: name,
                        code: data.confirmation_code
                    });
                    if (data.sms_sent === false) params.set('sms', 'unavailable');
                    window.location.href = './?' + params.toString();
                } else {
                    if (res.status === 409) loadClients();
                    const errorPanel = document.getElementById('errorPanel');
                    const errorList = document.getElementById('errorList');
                    errorList.innerHTML = `<li>${data.error || 'An error occurred. Please try again.'}</li>`;
                    errorPanel.classList.remove('hidden');
                }
            } catch (err) {
                console.error('Submit error:', err);
                const errorPanel = document.getElementById('errorPanel');
                const errorList = document.getElementById('errorList');
                errorList.innerHTML = '<li>An error occurred. Please try again.</li>';
                errorPanel.classList.remove('hidden');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit';
            }
        });
    </script>
