<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-navigation.php';
require_once __DIR__ . '/marketing-footer.php';
require_once __DIR__ . '/marketing-theme.php';
if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

$plans = [
    ['id' => 'starter', 'name' => planDisplayName('starter'), 'price' => '39', 'description' => 'For independent professionals and small teams.', 'features' => ['Up to 10 employee profiles', 'Waitlist branded with your logo', 'Owner and employee portal', 'Phone-based client sign-in', 'Optional appointment scheduling: +$5/month ($44/month total)']],
    ['id' => 'growth', 'name' => planDisplayName('growth'), 'price' => '64', 'description' => 'For busy service businesses with growing teams.', 'features' => ['Up to 25 employee profiles', 'Everything in Essentials', 'Appointment scheduling included'], 'featured' => true],
    ['id' => 'pro', 'name' => planDisplayName('pro'), 'price' => '99', 'description' => 'For established service businesses with larger teams.', 'features' => ['Up to 50 employee profiles', 'Everything in Select']],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing - ClientQ</title>
    <link rel="icon" href="/assets/clientq-logo-marketing.svg" type="image/svg+xml">
    <?php marketingTheme(); ?>
</head>
<body class="marketing-grid bg-canvas text-heading min-h-screen">
    <header class="sticky top-0 z-50 border-b border-line bg-surface/95 backdrop-blur">
        <nav class="max-w-6xl mx-auto px-6 py-5 flex flex-wrap items-center justify-between gap-4">
            <a href="/" aria-label="clientQ home">
                <img src="/assets/clientq-logo-marketing.svg" alt="clientQ" class="h-11 w-auto">
            </a>
            <div class="flex items-center gap-5 text-sm font-semibold">
                <?php marketingProductTourMenu('dark'); ?>
                <a href="/pricing" class="text-highlight">View pricing</a>
                <a href="/signup" class="marketing-metal bg-action text-white px-5 py-2.5 rounded-full hover:bg-actionHover">Sign up</a>
            </div>
        </nav>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-16">
        <div class="text-center max-w-2xl mx-auto mb-14">
            <p class="text-highlight font-semibold">Simple monthly pricing</p>
            <h1 class="text-4xl lg:text-5xl font-bold mt-2">Choose the right ClientQ</h1>
            <p class="text-copy mt-4">Start with the plan that fits your current team. Billing integration can be connected before production launch.</p>
        </div>

        <div class="grid lg:grid-cols-3 gap-6 items-stretch">
            <?php foreach ($plans as $plan): ?>
            <?php $planColors = ['starter' => 'border-line', 'growth' => 'border-action', 'pro' => 'border-line']; ?>
            <article class="relative bg-surface border-2 <?= $planColors[$plan['id']] ?> rounded-3xl p-7 flex flex-col <?= !empty($plan['featured']) ? 'ring-4 ring-action/10 shadow-lg -translate-y-2' : 'shadow-sm' ?>">
                <?php if (!empty($plan['featured'])): ?>
                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-raised text-heading text-xs font-bold px-4 py-1 rounded-full">MOST POPULAR</span>
                <?php endif; ?>
                <h2 class="text-2xl font-bold"><?= htmlspecialchars($plan['name']) ?></h2>
                <p class="text-muted mt-2 min-h-12"><?= htmlspecialchars($plan['description']) ?></p>
                <p class="mt-6"><span class="text-4xl font-bold">$<?= $plan['price'] ?></span><span class="text-muted">/month</span></p>
                <ul class="space-y-3 my-7 flex-1">
                    <?php foreach ($plan['features'] as $feature): ?>
                    <li class="flex gap-2"><span class="text-highlight font-bold">✓</span><span><?= htmlspecialchars($feature) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <a href="/signup?plan=<?= urlencode($plan['id']) ?>"
                    class="marketing-metal block text-center py-3 rounded-full font-semibold text-white <?= !empty($plan['featured']) ? 'bg-action hover:bg-actionHover' : 'bg-steel hover:bg-steelHover' ?>">
                    Choose <?= htmlspecialchars($plan['name']) ?>
                </a>
            </article>
            <?php endforeach; ?>
        </div>
    </main>
    <?php marketingFooter(); ?>
</body>
</html>
