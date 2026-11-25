<?php

require_once __DIR__ . '/marketing-navigation.php';
require_once __DIR__ . '/marketing-footer.php';
require_once __DIR__ . '/marketing-theme.php';

function marketingTourHeader(string $title, string $description, string $active = 'tour'): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - ClientQ</title>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <link rel="icon" href="/assets/clientq-logo-marketing.svg" type="image/svg+xml">
    <?php marketingTheme(); ?>
</head>
<body class="marketing-grid min-h-screen bg-canvas text-heading">
    <nav class="sticky top-0 z-50 border-b border-line bg-surface/95 backdrop-blur">
        <div class="max-w-6xl mx-auto px-6 py-5 flex flex-wrap items-center justify-between gap-4">
            <a href="/" aria-label="ClientQ home">
                <img src="/assets/clientq-logo-marketing.svg" alt="ClientQ" class="h-11 w-auto">
            </a>
            <div class="flex items-center gap-5 text-sm font-semibold">
                <?php marketingProductTourMenu('dark', $active === 'tour'); ?>
                <a href="/pricing" class="<?= $active === 'pricing' ? 'text-highlight' : 'text-copy hover:text-highlight' ?>">View pricing</a>
                <a href="/signup" class="marketing-metal rounded-full bg-action px-5 py-2.5 text-white hover:bg-actionHover">Sign up</a>
            </div>
        </div>
    </nav>
    <?php
}

function marketingTourFooter(): void
{
    marketingFooter();
    ?>
</body>
</html>
    <?php
}

function marketingScreenPage(
    string $eyebrow,
    string $title,
    string $description,
    array $features,
    array $steps,
    string $preview,
    ?array $previous,
    ?array $next
): void {
    $metalStyles = [
        'border-action/[0.65] bg-action/[0.65] text-black',
        'border-steel/[0.65] bg-steel/[0.65] text-black',
    ];
    marketingTourHeader($title, $description);
    ?>
    <main>
        <section class="marketing-grid marketing-grid-right overflow-hidden border-b border-line bg-gradient-to-br from-canvas via-surface to-raised text-heading">
            <div class="relative max-w-6xl mx-auto px-6 py-16 lg:py-24 grid gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <p class="font-semibold text-highlight"><?= htmlspecialchars($eyebrow) ?></p>
                    <h1 class="mt-3 text-4xl font-bold leading-tight lg:text-5xl"><?= htmlspecialchars($title) ?></h1>
                    <p class="mt-5 text-lg leading-relaxed text-copy"><?= htmlspecialchars($description) ?></p>
                </div>
                <div data-product-preview class="rounded-3xl border border-line bg-white/95 p-5 text-black shadow-2xl ring-8 ring-white/5 [color-scheme:light]">
                    <?= $preview ?>
                </div>
            </div>
        </section>

        <section class="relative overflow-hidden">
            <div class="relative max-w-6xl mx-auto px-6 py-16">
            <div class="grid gap-12 lg:grid-cols-2 lg:items-start">
                <div>
                    <p class="font-semibold text-highlight">What this screen does</p>
                    <h2 class="mt-2 text-3xl font-bold">Built for a faster, clearer visit</h2>
                    <div class="mt-8 grid gap-4">
                        <?php foreach ($features as $index => $feature): ?>
                        <article class="marketing-metal rounded-2xl border p-5 <?= $metalStyles[$index % count($metalStyles)] ?>">
                            <h3 class="font-bold"><?= htmlspecialchars($feature['title']) ?></h3>
                            <p class="mt-1"><?= htmlspecialchars($feature['description']) ?></p>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="rounded-3xl border border-line bg-surface p-6 shadow-sm lg:p-8">
                    <p class="font-semibold text-highlight">Typical workflow</p>
                    <h2 class="mt-2 text-3xl font-bold">How it fits into ClientQ</h2>
                    <ol class="mt-8 space-y-5">
                        <?php foreach ($steps as $index => $step): ?>
                        <li class="flex gap-4">
                            <span class="marketing-metal flex h-9 w-9 shrink-0 items-center justify-center rounded-full border font-bold <?= $metalStyles[$index % count($metalStyles)] ?>"><?= $index + 1 ?></span>
                            <p class="pt-1 text-copy"><?= htmlspecialchars($step) ?></p>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>
            </div>
        </section>

        <section class="max-w-6xl mx-auto px-6 pb-[30px]">
            <div class="flex flex-col gap-4 rounded-3xl border border-line bg-surface p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-semibold text-highlight">Continue the tour</p>
                    <p class="text-sm text-copy">See how the ClientQ screens work together.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <?php if ($previous): ?>
                        <a href="<?= htmlspecialchars($previous['href']) ?>" class="rounded-full bg-surface px-5 py-2.5 font-semibold text-highlight hover:bg-raised">&larr; <?= htmlspecialchars($previous['label']) ?></a>
                    <?php endif; ?>
                    <?php if ($next): ?>
                        <a href="<?= htmlspecialchars($next['href']) ?>" class="marketing-metal rounded-full bg-action px-5 py-2.5 font-semibold text-white hover:bg-actionHover"><?= htmlspecialchars($next['label']) ?> &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
    <?php
    marketingTourFooter();
}
