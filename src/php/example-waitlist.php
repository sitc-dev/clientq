<?php
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/sms-consent.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

$exampleBusinessName = 'Northside Barber';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Northside Barber Client Waitlist</title>
    <meta name="description" content="Join the Northside Barber client waitlist.">
    <link rel="icon" href="/assets/clientq-logo-dark.svg" type="image/svg+xml">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#111827',
                        accent: '#DC2626',
                        accentDark: '#B91C1C',
                        soft: '#F3F4F6',
                        primarySoft: '#FEE2E2'
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gray-100">
    <main class="mx-auto max-w-md px-4 py-8">
        <section class="overflow-hidden rounded-2xl bg-white shadow-lg">
            <div class="bg-primary p-5 text-center text-white">
                <div class="mx-auto mb-3 flex h-16 w-40 items-center justify-center rounded-lg bg-white px-3 font-black text-primary">
                    NORTHSIDE<br>BARBER
                </div>
                <h1 class="text-lg font-bold"><?= htmlspecialchars($exampleBusinessName) ?> Client Waitlist</h1>
            </div>

            <div role="tablist" aria-label="Northside Barber waitlist pages" class="grid grid-cols-2 border-b bg-gray-50 text-sm">
                <button type="button" role="tab" aria-selected="true" aria-controls="exampleWaitlistPanel"
                    data-example-panel="exampleWaitlistPanel"
                    class="example-tab border-b-2 border-accent bg-white px-3 py-3 font-semibold text-accent">
                    Waitlist
                </button>
                <button type="button" role="tab" aria-selected="false" aria-controls="exampleSignUpPanel"
                    data-example-panel="exampleSignUpPanel"
                    class="example-tab border-b-2 border-transparent px-3 py-3 font-semibold text-gray-500 hover:text-gray-700">
                    Sign Up
                </button>
            </div>

            <section id="exampleWaitlistPanel" class="example-panel" role="tabpanel">
                <div class="border-b p-3">
                    <label for="exampleStaffFilter" class="sr-only">Filter by staff member</label>
                    <select id="exampleStaffFilter"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                        <option>All Staff</option>
                        <option>Taylor</option>
                        <option>Alex</option>
                        <option>Jordan</option>
                    </select>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-200 text-gray-700">
                            <tr>
                                <th class="px-2 py-2 text-left">#</th>
                                <th class="px-2 py-2 text-left">Client</th>
                                <th class="px-2 py-2 text-left">Staff</th>
                                <th class="px-2 py-2 text-right">Time waiting</th>
                                <th class="px-2 py-2 text-center">Party</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b">
                                <td class="px-2 py-3 text-gray-500">1</td>
                                <td class="px-2 py-3 font-medium">Jordan</td>
                                <td class="px-2 py-3 text-gray-600">Taylor</td>
                                <td class="px-2 py-3 text-right font-mono">08:14</td>
                                <td class="px-2 py-3 text-center">2</td>
                            </tr>
                            <tr class="border-b">
                                <td class="px-2 py-3 text-gray-500">2</td>
                                <td class="px-2 py-3 font-medium">Morgan</td>
                                <td class="px-2 py-3 text-gray-600">Alex</td>
                                <td class="px-2 py-3 text-right font-mono">04:27</td>
                                <td class="px-2 py-3 text-center">1</td>
                            </tr>
                            <tr>
                                <td class="px-2 py-3 text-gray-500">3</td>
                                <td class="px-2 py-3 font-medium">Casey</td>
                                <td class="px-2 py-3 text-gray-600">Jordan</td>
                                <td class="px-2 py-3 text-right font-mono">01:05</td>
                                <td class="px-2 py-3 text-center">3</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="space-y-3 border-t p-4">
                    <button id="exampleSignInButton" type="button"
                        class="block w-full rounded-lg bg-accent py-3 text-center font-semibold text-white transition hover:bg-accentDark">
                        Sign In
                    </button>
                    <a href="/staff-profiles-screen"
                        class="block w-full rounded-lg bg-gray-600 py-3 text-center font-semibold text-white transition hover:bg-gray-700">
                        See Staff Profiles
                    </a>
                </div>
            </section>

            <section id="exampleSignUpPanel" class="example-panel hidden" role="tabpanel">
                <div class="border-b bg-gray-50 px-5 py-4 text-center">
                    <h2 class="text-lg font-bold text-gray-800">Northside Barber Client Sign-In</h2>
                </div>
                <div id="exampleErrorPanel"
                    class="m-4 hidden rounded-lg border border-red-400 bg-red-100 px-4 py-3 text-sm text-red-700">
                    <ul id="exampleErrorList" class="list-inside list-disc space-y-1"></ul>
                </div>
                <form id="exampleSignInForm" action="/example-waitlist" method="get" class="space-y-5 p-5" novalidate>
                    <div>
                        <label for="exampleName" class="mb-1 block text-sm font-semibold text-gray-700">
                            Enter name (letters only):
                        </label>
                        <input id="exampleName" type="text" placeholder="Client name"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-gray-700">No. in party (including self):</p>
                        <div id="examplePartyButtons" class="grid grid-cols-5 gap-2">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <button type="button" data-example-party="<?= $i ?>"
                                    class="example-party-btn w-full rounded-lg border border-gray-300 bg-white py-2 font-semibold text-gray-700 shadow transition hover:bg-gray-100">
                                    <?= $i ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div>
                        <label for="exampleStaff" class="mb-1 block text-sm font-semibold text-gray-700">
                            Select staff member to service you:
                        </label>
                        <select id="exampleStaff"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                            <option value="">Staff Members</option>
                            <option value="taylor">Taylor</option>
                            <option value="alex">Alex</option>
                            <option value="jordan">Jordan</option>
                        </select>
                    </div>

                    <?php renderTransactionalSmsConsent('Sede IT Consulting, LLC', ', the developer of this application'); ?>

                    <div>
                        <label for="examplePhone" class="mb-1 block text-sm font-semibold text-gray-700">
                            Enter phone number:
                        </label>
                        <input id="examplePhone" type="tel" placeholder="(xxx)xxx-xxxx" inputmode="numeric"
                            aria-describedby="examplePhoneError"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent">
                        <p id="examplePhoneError" class="mt-1 hidden text-sm text-red-600">
                            Please enter a valid 10-digit phone number.
                        </p>
                    </div>

                    <div class="space-y-3 pt-2">
                        <button type="submit"
                            class="w-full rounded-lg bg-accent py-3 font-semibold text-white transition hover:bg-accentDark">
                            Submit
                        </button>
                        <button type="button" id="exampleResetButton"
                            class="w-full rounded-lg bg-gray-400 py-3 font-semibold text-white transition hover:bg-gray-500">
                            Reset Inputs
                        </button>
                        <button type="button" id="exampleQrButton"
                            class="w-full rounded-lg border-2 border-accent bg-white py-3 font-semibold text-accent transition hover:bg-primarySoft">
                            Show QR Code
                        </button>
                    </div>

                    <p id="exampleMessage" class="hidden rounded-lg bg-green-100 px-4 py-3 text-sm font-semibold text-green-800"></p>

                </form>
            </section>
        </section>
    </main>

    <script>
        const exampleForm = document.getElementById('exampleSignInForm');
        const examplePhone = document.getElementById('examplePhone');
        const examplePhoneError = document.getElementById('examplePhoneError');
        const exampleErrorPanel = document.getElementById('exampleErrorPanel');
        const exampleErrorList = document.getElementById('exampleErrorList');
        const exampleMessage = document.getElementById('exampleMessage');
        let examplePartySize = '';

        function activateExampleTab(activeButton) {
            document.querySelectorAll('.example-tab').forEach(button => {
                const isActive = button === activeButton;
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                button.classList.toggle('border-accent', isActive);
                button.classList.toggle('bg-white', isActive);
                button.classList.toggle('text-accent', isActive);
                button.classList.toggle('border-transparent', !isActive);
                button.classList.toggle('text-gray-500', !isActive);
            });

            document.querySelectorAll('.example-panel').forEach(panel => panel.classList.add('hidden'));
            document.getElementById(activeButton.dataset.examplePanel).classList.remove('hidden');
        }

        document.querySelectorAll('.example-tab').forEach(button => {
            button.addEventListener('click', () => activateExampleTab(button));
        });

        if (new URLSearchParams(window.location.search).get('tab') === 'signup') {
            activateExampleTab(document.querySelector('[data-example-panel="exampleSignUpPanel"]'));
        }

        document.getElementById('exampleSignInButton').addEventListener('click', () => {
            activateExampleTab(document.querySelector('[data-example-panel="exampleSignUpPanel"]'));
        });

        document.querySelectorAll('.example-party-btn').forEach(button => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.example-party-btn').forEach(partyButton => {
                    partyButton.classList.remove('bg-accent', 'text-white', 'shadow-none');
                    partyButton.classList.add('bg-white', 'text-gray-700', 'shadow');
                });
                button.classList.remove('bg-white', 'text-gray-700', 'shadow');
                button.classList.add('bg-accent', 'text-white', 'shadow-none');
                examplePartySize = button.dataset.exampleParty;
            });
        });

        function examplePhoneDigits(value) {
            return value.replace(/\D/g, '').substring(0, 10);
        }

        examplePhone.addEventListener('input', () => {
            examplePhone.value = examplePhoneDigits(examplePhone.value);
            examplePhoneError.classList.add('hidden');
        });

        exampleForm.addEventListener('submit', event => {
            event.preventDefault();
            const errors = [];
            const name = document.getElementById('exampleName').value.trim();
            const staff = document.getElementById('exampleStaff').value;
            const phoneIsValid = examplePhoneDigits(examplePhone.value).length === 10;

            if (!name || !/^[a-zA-Z\s]+$/.test(name)) {
                errors.push('Please enter a name using letters only.');
            }
            if (!examplePartySize) {
                errors.push('Please select the number in your party.');
            }
            if (!staff) {
                errors.push('Please select a staff member.');
            }
            if (!phoneIsValid) {
                errors.push('Please enter a valid 10-digit phone number.');
                examplePhoneError.classList.remove('hidden');
            }

            if (errors.length > 0) {
                exampleErrorList.innerHTML = errors.map(error => `<li>${error}</li>`).join('');
                exampleErrorPanel.classList.remove('hidden');
                exampleMessage.classList.add('hidden');
                return;
            }

            exampleErrorPanel.classList.add('hidden');
            exampleMessage.textContent = 'Your Northside Barber waitlist sign-up has been submitted.';
            exampleMessage.classList.remove('hidden');
        });

        document.getElementById('exampleResetButton').addEventListener('click', () => {
            exampleForm.reset();
            examplePartySize = '';
            examplePhoneError.classList.add('hidden');
            exampleErrorPanel.classList.add('hidden');
            exampleMessage.classList.add('hidden');
            document.querySelectorAll('.example-party-btn').forEach(button => {
                button.classList.remove('bg-accent', 'text-white', 'shadow-none');
                button.classList.add('bg-white', 'text-gray-700', 'shadow');
            });
        });

        document.getElementById('exampleQrButton').addEventListener('click', () => {
            exampleMessage.textContent = 'QR code access is temporarily unavailable. Please share this page address instead.';
            exampleMessage.classList.remove('hidden');
        });
    </script>
</body>
</html>
