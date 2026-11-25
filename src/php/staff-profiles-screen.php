<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-tour-layout.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

$preview = <<<'HTML'
<div class="mx-auto max-w-sm overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-gray-200">
    <div class="bg-primary p-4 text-center text-sm font-bold text-white">Staff Profiles</div>
    <div class="divide-y divide-gray-200 text-xs">
        <div>
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-primary to-accent text-base font-bold text-white">T</span>
                    <span class="font-semibold text-gray-800">Taylor</span>
                </div>
                <span class="rotate-90 text-lg text-gray-500">&rsaquo;</span>
            </div>
            <div class="space-y-1 bg-gray-50 px-4 pb-4 pl-[4.75rem]">
                <p><strong class="text-gray-600">Name:</strong> Taylor Morgan</p>
                <p><strong class="text-gray-600">Goes by:</strong> Taylor</p>
                <p><strong class="text-gray-600">Position:</strong> Senior Barber</p>
                <p><strong class="text-gray-600">Experience:</strong> 12 years</p>
                <p class="pt-1 text-gray-600">Fades, beard trims, and classic cuts.</p>
            </div>
        </div>
        <div class="flex items-center justify-between p-4">
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-primary to-accent text-base font-bold text-white">A</span>
                <span class="font-semibold text-gray-800">Alex</span>
            </div>
            <span class="text-lg text-gray-500">&rsaquo;</span>
        </div>
        <div class="flex items-center justify-between p-4">
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-primary to-accent text-base font-bold text-white">C</span>
                <span class="font-semibold text-gray-800">Chris</span>
            </div>
            <span class="text-lg text-gray-500">&rsaquo;</span>
        </div>
    </div>
    <div class="border-t p-4 text-center text-xs font-semibold">
        <div class="rounded-lg bg-gray-600 py-2.5 text-white">Back to Client List View</div>
    </div>
</div>
HTML;

marketingScreenPage(
    'Public client experience',
    'Staff Profiles Screen',
    'Staff profiles help clients choose the right team member by presenting the business information maintained by the owner in a compact, mobile-friendly directory.',
    [
        ['title' => 'Expandable profiles', 'description' => 'Clients can open one profile at a time without leaving the waitlist experience.'],
        ['title' => 'Relevant service details', 'description' => 'Profiles can show name, preferred name, position, experience, and additional service information.'],
        ['title' => 'Consistent branding', 'description' => 'Profile colors and presentation match the business waitlist and sign-in screens.'],
        ['title' => 'Owner-managed information', 'description' => 'Authorized owners maintain staff details and account access from the admin portal.'],
    ],
    [
        'The owner creates employee accounts and adds profile details.',
        'Clients open Staff Profiles from the public waitlist.',
        'A client expands profiles to compare team members and experience.',
        'The client returns to sign-in and selects the preferred staff member.',
    ],
    $preview,
    ['href' => '/sign-in-screen', 'label' => 'Client sign-in'],
    ['href' => '/team-dashboard-screen', 'label' => 'Galley View']
);
