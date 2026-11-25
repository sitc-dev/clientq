<?php

function marketingFooter(): void
{
    $productLinks = [
        ['href' => '/product-tour', 'label' => 'Product tour'],
        ['href' => '/waitlist-screen', 'label' => 'Live waitlist'],
        ['href' => '/sign-in-screen', 'label' => 'Client sign-in'],
        ['href' => '/staff-profiles-screen', 'label' => 'Staff profiles'],
        ['href' => '/team-dashboard-screen', 'label' => 'Galley View'],
    ];
    ?>
    <footer class="marketing-grid marketing-grid-right border-t border-line bg-raised text-copy">
        <div class="mx-auto grid max-w-6xl gap-10 px-6 py-12 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <a href="/" aria-label="ClientQ home" class="inline-block">
                    <img src="/assets/clientq-logo-marketing.svg" alt="ClientQ" class="h-10 w-auto">
                </a>
                <p class="mt-4 max-w-xs text-sm leading-6 text-muted">
                    A clearer way for service businesses to manage client sign-ins, waitlists, and staff assignments.
                </p>
            </div>

            <nav aria-label="Product links">
                <h2 class="font-bold text-heading">Product</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    <?php foreach ($productLinks as $link): ?>
                        <li><a href="<?= htmlspecialchars($link['href']) ?>" class="hover:text-highlight"><?= htmlspecialchars($link['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <nav aria-label="ClientQ links">
                <h2 class="font-bold text-heading">ClientQ</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    <li><a href="/" class="hover:text-highlight">Home</a></li>
                    <li><a href="/pricing" class="hover:text-highlight">Pricing</a></li>
                    <li><a href="/signup" class="hover:text-highlight">Business sign up</a></li>
                    <li><a href="/example-waitlist" class="hover:text-highlight">Example waitlist</a></li>
                    <li><a href="/example-waitlist?tab=signup" class="hover:text-highlight">Example client sign up</a></li>
                </ul>
            </nav>

            <nav aria-label="Legal links">
                <h2 class="font-bold text-heading">Legal</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    <li><a href="/privacy" class="hover:text-highlight">Privacy Statement</a></li>
                    <li><a href="/terms" class="hover:text-highlight">Terms &amp; Conditions</a></li>
                </ul>
            </nav>
        </div>

        <div class="border-t border-line">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 px-6 py-6 text-xs text-muted sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; <?= date('Y') ?> ClientQ. All rights reserved.</p>
                <p>Transactional messages are sent only with the recipient's consent.</p>
            </div>
        </div>
    </footer>
    <?php
}
