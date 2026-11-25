<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-tour-layout.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

$preview = <<<'HTML'
<div class="overflow-hidden rounded-2xl bg-gray-100 shadow-lg ring-1 ring-gray-200">
    <div class="flex items-center justify-center gap-4 bg-primary px-3 py-3 text-white">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-[9px] font-black text-primary">YB</div>
        <h2 class="text-base font-bold">Your Business Waitlist</h2>
    </div>
    <div class="overflow-x-auto bg-white">
        <table class="w-full text-xs">
            <thead class="bg-gray-200 text-gray-700">
                <tr>
                    <th scope="col" class="px-2 py-2 text-left">#</th>
                    <th scope="col" class="px-2 py-2 text-left">Client</th>
                    <th scope="col" class="px-2 py-2 text-left">Staff</th>
                    <th scope="col" class="px-2 py-2 text-right">Time waiting</th>
                    <th scope="col" class="px-2 py-2 text-center">No. in Party</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b">
                    <td class="px-2 py-2 text-gray-600">1</td><td class="px-2 py-2 font-medium">Jordan</td><td class="px-2 py-2 text-gray-600">Taylor</td><td class="px-2 py-2 text-right font-mono">08:14</td><td class="px-2 py-2 text-center">2</td>
                </tr>
                <tr class="border-b">
                    <td class="px-2 py-2 text-gray-600">2</td><td class="px-2 py-2 font-medium">Morgan</td><td class="px-2 py-2 text-gray-600">Alex</td><td class="px-2 py-2 text-right font-mono">04:27</td><td class="px-2 py-2 text-center">1</td>
                </tr>
                <tr class="border-b">
                    <td class="px-2 py-2 text-gray-600">3</td><td class="px-2 py-2 font-medium">Casey</td><td class="px-2 py-2 text-gray-600">Taylor</td><td class="px-2 py-2 text-right font-mono">01:05</td><td class="px-2 py-2 text-center">3</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="border-t bg-white px-2 py-2 text-center text-[10px] text-gray-600">Page 1 of 2 | Updates every 30 seconds</p>
</div>
HTML;

marketingScreenPage(
    'A waitlist built for shared displays',
    'Galley View',
    'Display your branded waitlist on a full-width screen so everyone can see client order, staff assignments, wait times, and party sizes at a glance.',
    [
        ['title' => 'Full-width waitlist', 'description' => 'A compact table makes the public queue easy to read on a shared monitor or waiting-room display.'],
        ['title' => 'All staff in one view', 'description' => 'Show each waiting client alongside their assigned staff member, elapsed wait time, and party size.'],
        ['title' => 'Automatic page rotation', 'description' => 'The display refreshes every 30 seconds and cycles through waitlist pages automatically.'],
        ['title' => 'Focused business branding', 'description' => 'Your business name, logo, and theme colors frame the queue without sign-up tabs, filters, or staff administration controls.'],
    ],
    [
        'Open /galley on your business ClientQ address.',
        'Place the browser on a shared display where waiting clients can see it.',
        'Clients join through the public sign-up page or QR code.',
        'Galley View refreshes and rotates pages automatically as the queue changes.',
    ],
    $preview,
    ['href' => '/staff-profiles-screen', 'label' => 'Staff profiles'],
    ['href' => '/product-tour', 'label' => 'Tour overview']
);
