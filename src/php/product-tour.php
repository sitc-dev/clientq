<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-tour-layout.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

$screens = [
    [
        'href' => '/waitlist-screen',
        'number' => '01',
        'title' => 'Live waitlist',
        'description' => 'A branded, automatically refreshed queue showing client order, selected staff member, party size, and current wait time.',
        'color' => 'border-line',
        'background' => 'from-surface to-raised',
        'badge' => 'bg-raised text-highlight',
        'hover' => 'marketing-tour-card-steel',
    ],
    [
        'href' => '/sign-in-screen',
        'number' => '02',
        'title' => 'Client sign-in',
        'description' => 'A mobile-friendly form for client details, party size, staff selection, SMS consent, and QR-code access.',
        'color' => 'border-line',
        'background' => 'from-surface to-raised',
        'badge' => 'bg-raised text-highlight',
        'hover' => 'marketing-tour-card-red',
    ],
    [
        'href' => '/staff-profiles-screen',
        'number' => '03',
        'title' => 'Staff profiles',
        'description' => 'An easy way for clients to review team members, roles, experience, and service information before joining the queue.',
        'color' => 'border-line',
        'background' => 'from-surface to-raised',
        'badge' => 'bg-raised text-highlight',
        'hover' => 'marketing-tour-card-steel',
    ],
    [
        'href' => '/team-dashboard-screen',
        'number' => '04',
        'title' => 'Galley View',
        'description' => 'A full-width branded waitlist for shared displays, showing all staff assignments and rotating queue pages automatically.',
        'color' => 'border-line',
        'background' => 'from-surface to-raised',
        'badge' => 'bg-raised text-highlight',
        'hover' => 'marketing-tour-card-red',
    ],
];

marketingTourHeader(
    'Product Tour',
    'Explore the public waitlist, client sign-in, staff profiles, and Galley View included with ClientQ.'
);
?>
    <main>
        <section class="marketing-grid marketing-grid-right overflow-hidden border-b border-line bg-gradient-to-br from-canvas via-surface to-raised text-heading">
            <div class="relative max-w-6xl mx-auto px-6 py-20 text-center lg:py-28">
                <p class="font-semibold text-highlight">ClientQ product tour</p>
                <h1 class="mx-auto mt-3 max-w-4xl text-4xl font-bold leading-tight lg:text-6xl">See every step of the client waitlist experience.</h1>
                <p class="mx-auto mt-6 max-w-3xl text-xl text-copy">
                    From the first sign-in to Galley View, each screen keeps clients informed and gives service teams a simple queue to manage.
                </p>
            </div>
        </section>

        <section class="max-w-6xl mx-auto px-6 py-16">
            <div class="grid gap-6 md:grid-cols-2">
                <?php foreach ($screens as $screen): ?>
                <a href="<?= htmlspecialchars($screen['href']) ?>"
                    class="marketing-tour-card <?= $screen['hover'] ?> rounded-3xl border-2 <?= $screen['color'] ?> bg-gradient-to-br <?= $screen['background'] ?> p-7 shadow-sm">
                    <span class="marketing-tour-card-label inline-flex rounded-full px-3 py-1 text-sm font-bold <?= $screen['badge'] ?>"><?= $screen['number'] ?></span>
                    <h2 class="mt-2 text-2xl font-bold"><?= htmlspecialchars($screen['title']) ?></h2>
                    <p class="mt-3 leading-relaxed text-copy"><?= htmlspecialchars($screen['description']) ?></p>
                    <span class="marketing-tour-card-label mt-6 inline-block font-semibold text-highlight">Explore this screen &rarr;</span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="max-w-6xl mx-auto px-6 pb-[30px]">
            <div class="rounded-3xl border border-line bg-surface px-7 py-12 text-center shadow-sm">
                <h2 class="text-3xl font-bold">Ready to create your own branded waitlist?</h2>
                <p class="mt-3 text-copy">Choose a plan, add your team, and share your business sign-in page.</p>
                <div class="mt-7 flex flex-wrap justify-center gap-3">
                    <a href="/pricing" class="marketing-metal rounded-full bg-action px-6 py-3 font-semibold text-white hover:bg-actionHover">View pricing</a>
                    <a href="/signup" class="marketing-metal rounded-full bg-steel px-6 py-3 font-semibold text-white hover:bg-steelHover">Sign up</a>
                </div>
            </div>
        </section>
    </main>
<?php marketingTourFooter(); ?>
