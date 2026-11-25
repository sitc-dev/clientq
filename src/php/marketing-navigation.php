<?php

function marketingProductTourMenu(string $tone = 'dark', bool $active = false): void
{
    $linkClass = $active
        ? 'text-highlight'
        : 'text-copy hover:text-highlight';
    $buttonClass = $active
        ? 'text-highlight'
        : 'text-muted hover:text-highlight';
    ?>
    <div class="group relative flex items-center">
        <a href="/product-tour" class="<?= $linkClass ?>">Product tour</a>
        <button type="button"
            class="<?= $buttonClass ?> ml-1 rounded p-1"
            aria-label="Show Product Tour pages"
            aria-haspopup="true">
            <svg class="h-3.5 w-3.5 transition-transform group-hover:rotate-180 group-focus-within:rotate-180"
                viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 011.06 0L10 10.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 8.28a.75.75 0 010-1.06z" clip-rule="evenodd"/>
            </svg>
        </button>
        <div class="absolute left-0 top-full z-50 hidden w-56 pt-3 group-hover:block group-focus-within:block">
            <div class="max-h-[calc(100dvh-10rem)] overflow-y-auto rounded-2xl border border-line bg-surface py-2 text-sm font-semibold text-copy shadow-xl">
                <a href="/product-tour" class="block px-4 py-2.5 hover:bg-raised hover:text-highlight">Tour overview</a>
                <a href="/waitlist-screen" class="block px-4 py-2.5 hover:bg-raised hover:text-highlight">Live waitlist</a>
                <a href="/sign-in-screen" class="block px-4 py-2.5 hover:bg-raised hover:text-highlight">Client sign-in</a>
                <a href="/staff-profiles-screen" class="block px-4 py-2.5 hover:bg-raised hover:text-highlight">Staff profiles</a>
                <a href="/team-dashboard-screen" class="block px-4 py-2.5 hover:bg-raised hover:text-highlight">Galley View</a>
            </div>
        </div>
    </div>
    <?php
}
