<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-tour-layout.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

$preview = <<<'HTML'
<div class="mx-auto max-w-sm overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-gray-200">
    <div class="bg-primary p-4 text-center text-sm font-bold text-white">Client Sign-In</div>
    <div class="grid grid-cols-2 border-b bg-gray-50 text-center text-xs font-semibold">
        <div class="border-b-2 border-accent bg-white px-2 py-3 text-accent">Sign Up</div>
        <div class="border-b-2 border-transparent px-2 py-3 text-gray-500">Show QR</div>
    </div>
    <div class="space-y-4 p-4 text-xs">
        <div>
            <p class="mb-1 font-semibold text-gray-700">Enter name (letters only):</p>
            <div class="rounded-lg border border-gray-300 px-3 py-2 text-gray-400">Client name</div>
        </div>
        <div>
            <p class="mb-2 font-semibold text-gray-700">No. in party (including self):</p>
            <div class="grid grid-cols-5 gap-1.5">
                <span class="rounded-md border p-1.5 text-center">1</span><span class="rounded-md bg-accent p-1.5 text-center text-white">2</span><span class="rounded-md border p-1.5 text-center">3</span><span class="rounded-md border p-1.5 text-center">4</span><span class="rounded-md border p-1.5 text-center">5</span>
                <span class="rounded-md border p-1.5 text-center">6</span><span class="rounded-md border p-1.5 text-center">7</span><span class="rounded-md border p-1.5 text-center">8</span><span class="rounded-md border p-1.5 text-center">9</span><span class="rounded-md border p-1.5 text-center">10</span>
            </div>
        </div>
        <div>
            <p class="mb-1 font-semibold text-gray-700">Select staff member to service you:</p>
            <div class="flex justify-between rounded-lg border border-gray-300 px-3 py-2 text-gray-500"><span>Taylor</span><span>&#9662;</span></div>
        </div>
        <div>
            <p class="mb-1 font-semibold text-gray-700">Enter phone number:</p>
            <div class="rounded-lg border border-gray-300 px-3 py-2 text-gray-500">(404)555-1234</div>
        </div>
        <details class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-[10px] text-gray-600">
            <summary class="cursor-pointer font-semibold marker:text-primary">Transactional and informational text message opt-in</summary>
            <label class="mt-2 flex items-start gap-2 leading-relaxed">
                <input type="checkbox" class="mt-0.5 h-3 w-3 shrink-0 accent-primary">
                <span>By checking this box, I agree to receive waitlist confirmations, estimated-wait updates, service notices, and related customer-service texts. Message frequency varies. Msg &amp; data rates may apply. Reply STOP to opt out or HELP for help.</span>
            </label>
        </details>
        <div class="space-y-2 pt-1 text-center font-semibold">
            <div class="rounded-lg bg-accent py-2.5 text-white">Submit</div>
            <div class="rounded-lg bg-gray-400 py-2.5 text-white">Reset Inputs</div>
            <div class="rounded-lg border-2 border-accent bg-white py-2 text-accent">Show QR Code</div>
        </div>
    </div>
    <div class="border-t p-4 text-center text-xs font-semibold">
        <div class="rounded-lg bg-gray-600 py-2.5 text-white">Back to Client List View</div>
    </div>
</div>
HTML;

marketingScreenPage(
    'Public client experience',
    'Client Sign-In Screen',
    'The mobile-friendly sign-in screen collects the information a service team needs while keeping the process quick enough for a front desk, kiosk, or QR code.',
    [
        ['title' => 'Simple client details', 'description' => 'The form collects the client name, party size, preferred staff member, and a validated 10-digit phone number.'],
        ['title' => 'Explicit SMS consent', 'description' => 'Clients open an accordion disclosure and check a box before opting in to transactional and informational service texts.'],
        ['title' => 'Input validation', 'description' => 'Invalid phone numbers receive a clear error message and a red field outline before anything is submitted.'],
        ['title' => 'QR-code access', 'description' => 'Businesses can display or download a QR code that opens the sign-in screen on a client phone.'],
    ],
    [
        'The client opens the form directly or scans the business QR code.',
        'The client enters a name, selects party size and staff, and provides a phone number.',
        'ClientQ validates the form and formats the phone number for easy review.',
        'The client receives a confirmation and the visit appears on the live waitlist.',
    ],
    $preview,
    ['href' => '/waitlist-screen', 'label' => 'Live waitlist'],
    ['href' => '/staff-profiles-screen', 'label' => 'Staff profiles']
);
