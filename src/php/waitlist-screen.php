<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-tour-layout.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

$preview = <<<'HTML'
<div class="mx-auto max-w-sm overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-gray-200">
    <div class="bg-primary p-4 text-center text-white">
        <div class="mx-auto mb-2 flex h-12 w-28 items-center justify-center rounded-lg bg-white px-2 text-xs font-black text-primary">YOUR<br>BUSINESS</div>
        <p class="text-sm font-bold">Your Business Client Waitlist</p>
    </div>
    <div class="border-b p-3">
        <div class="flex items-center justify-between rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs text-gray-600">
            <span>All Staff</span><span class="text-gray-400">&#9662;</span>
        </div>
    </div>
    <div class="overflow-hidden text-[10px] sm:text-xs">
        <div class="grid grid-cols-[1.6rem_1fr_1fr_3.5rem_3rem] bg-gray-200 px-2 py-2 font-bold text-gray-700">
            <span>#</span><span>Client</span><span>Staff</span><span class="text-right">Time waiting</span><span class="text-center">Party</span>
        </div>
        <div class="grid grid-cols-[1.6rem_1fr_1fr_3.5rem_3rem] items-center border-b px-2 py-3">
            <span class="text-gray-500">1</span><strong>Jordan</strong><span class="text-gray-600">Taylor</span><span class="text-right font-mono">08:14</span><span class="text-center">2</span>
        </div>
        <div class="grid grid-cols-[1.6rem_1fr_1fr_3.5rem_3rem] items-center border-b px-2 py-3">
            <span class="text-gray-500">2</span><strong>Morgan</strong><span class="text-gray-600">Alex</span><span class="text-right font-mono">04:27</span><span class="text-center">1</span>
        </div>
        <div class="grid grid-cols-[1.6rem_1fr_1fr_3.5rem_3rem] items-center border-b px-2 py-3">
            <span class="text-gray-500">3</span><strong>Casey</strong><span class="text-gray-600">Taylor</span><span class="text-right font-mono">01:05</span><span class="text-center">3</span>
        </div>
    </div>
    <div class="space-y-2 border-t p-4 text-center text-xs font-semibold">
        <div class="rounded-lg bg-accent py-2.5 text-white">Sign In</div>
        <div class="rounded-lg bg-gray-600 py-2.5 text-white">See Staff Profiles</div>
        <a href="/example-waitlist" class="block pt-2 font-bold text-accent hover:text-accentDark">Open full example waitlist &rarr;</a>
    </div>
</div>
HTML;

marketingScreenPage(
    'Public client experience',
    'Live Waitlist Screen',
    'The waitlist gives clients a simple, branded view of who is waiting, which staff member they selected, and how long each visit has been in the queue.',
    [
        ['title' => 'Live queue order', 'description' => 'Clients appear in sign-in order and the screen refreshes automatically as the queue changes.'],
        ['title' => 'Staff filtering', 'description' => 'Visitors can view the full waitlist or focus on clients assigned to a particular staff member.'],
        ['title' => 'Clear wait details', 'description' => 'Each row shows the client name, selected staff member, party size, and elapsed wait time.'],
        ['title' => 'Business branding', 'description' => 'The screen uses the tenant business name, logo, colors, and dedicated ClientQ address.'],
    ],
    [
        'A client opens the business waitlist from its dedicated ClientQ address.',
        'The client reviews the current queue and available staff profiles.',
        'After signing in, the new entry appears in the appropriate place in the queue.',
        'Staff remove completed or cancelled visits from their secure dashboard.',
    ],
    $preview,
    ['href' => '/product-tour', 'label' => 'Tour overview'],
    ['href' => '/sign-in-screen', 'label' => 'Client sign-in']
);
