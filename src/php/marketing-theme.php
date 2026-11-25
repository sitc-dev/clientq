<?php

function marketingTheme(): void
{
    ?>
    <meta name="color-scheme" content="light">
    <style>
        :focus-visible {
            outline: 2px solid #315B79;
            outline-offset: 3px;
        }
        .marketing-grid {
            position: relative;
            isolation: isolate;
        }
        .marketing-grid::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(49, 91, 121, 0.13) 1px, transparent 1px),
                linear-gradient(90deg, rgba(49, 91, 121, 0.13) 1px, transparent 1px);
            background-size: 40px 40px;
            -webkit-mask-image: linear-gradient(to right, #000, transparent 85%);
            mask-image: linear-gradient(to right, #000, transparent 85%);
        }
        .marketing-grid-right::before {
            -webkit-mask-image: linear-gradient(to left, #000, transparent 85%);
            mask-image: linear-gradient(to left, #000, transparent 85%);
        }
        .marketing-metal {
            background-image: linear-gradient(120deg,
                rgba(255, 255, 255, 0.14),
                transparent 35%,
                rgba(255, 255, 255, 0.1) 48%,
                rgba(0, 0, 0, 0.12));
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.4),
                inset 0 -1px 0 rgba(0, 0, 0, 0.2),
                0 3px 8px rgba(37, 48, 59, 0.12);
        }
        .marketing-tour-card {
            position: relative;
            isolation: isolate;
            transition: transform 300ms ease, border-color 300ms ease, box-shadow 300ms ease;
        }
        .marketing-tour-card-steel {
            --tour-metal: 49, 91, 121;
            --tour-ink: #25455D;
        }
        .marketing-tour-card-red {
            --tour-metal: 157, 51, 68;
            --tour-ink: #7E2635;
        }
        .marketing-tour-card::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            border-radius: inherit;
            pointer-events: none;
            background-color: rgba(var(--tour-metal), 0.18);
            background-image: linear-gradient(120deg,
                rgba(255, 255, 255, 0.6),
                transparent 35%,
                rgba(255, 255, 255, 0.35) 48%,
                transparent);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
            opacity: 0;
            transition: opacity 300ms ease;
        }
        .marketing-tour-card .marketing-tour-card-label {
            color: var(--tour-ink);
        }
        .marketing-tour-card:is(:hover, :focus-visible) {
            outline-color: var(--tour-ink);
            border-color: rgb(var(--tour-metal));
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(var(--tour-metal), 0.15);
        }
        .marketing-tour-card:is(:hover, :focus-visible)::before {
            opacity: 1;
        }
        @media (prefers-reduced-motion: reduce) {
            .marketing-tour-card,
            .marketing-tour-card::before {
                transition: none;
            }
            .marketing-tour-card:is(:hover, :focus-visible) {
                transform: none;
            }
        }
    </style>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        canvas: '#F1F3F5',
                        surface: '#FFFFFF',
                        raised: '#E2E7EC',
                        line: '#C1CBD4',
                        heading: '#25303B',
                        copy: '#465360',
                        muted: '#4F5C69',
                        highlight: '#315B79',
                        action: '#9D3344',
                        actionHover: '#7E2635',
                        steel: '#315B79',
                        steelHover: '#25455D',
                        primary: <?= json_encode(TENANT_THEME_DEFAULT_PRIMARY) ?>,
                        accent: <?= json_encode(TENANT_THEME_DEFAULT_ACCENT) ?>,
                        accentDark: <?= json_encode(mixThemeColor(TENANT_THEME_DEFAULT_ACCENT, '#000000', 0.18)) ?>
                    }
                }
            }
        }
    </script>
    <?php
}
