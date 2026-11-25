<?php
require_once __DIR__ . '/marketing-navigation.php';
require_once __DIR__ . '/marketing-footer.php';
require_once __DIR__ . '/marketing-theme.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClientQ - The waitlist built for service businesses</title>
    <link rel="icon" href="/assets/clientq-logo-marketing.svg" type="image/svg+xml">
    <?php marketingTheme(); ?>
    <style>
        .tenant-photo-slide {
            opacity: 0;
            transform: scale(1.025);
            transition: opacity 900ms ease, transform 6s ease;
        }
        .tenant-photo-slide.is-active {
            opacity: 1;
            transform: scale(1);
        }
        .tenant-photo-dot[aria-current="true"] {
            width: 2rem;
            background: #9D3344;
        }
        .industry-card-grid figcaption {
            min-height: 7rem;
        }
        @media (prefers-reduced-motion: reduce) {
            .tenant-photo-slide {
                transition: opacity 150ms linear;
                transform: none;
            }
        }
    </style>
</head>
<body class="marketing-grid bg-canvas text-heading">
    <header class="sticky top-0 z-50 border-b border-line bg-surface/95 backdrop-blur">
        <nav class="relative max-w-7xl mx-auto px-6 py-5 flex items-center justify-between">
            <a href="/" aria-label="clientQ home">
                <img src="/assets/clientq-logo-marketing.svg" alt="clientQ" class="h-11 w-auto">
            </a>
            <div class="hidden items-center gap-4 text-sm font-semibold md:flex">
                <?php marketingProductTourMenu('dark'); ?>
                <a href="/pricing" class="text-copy hover:text-highlight">View pricing</a>
                <a href="/signup" class="marketing-metal bg-action text-white hover:bg-actionHover px-5 py-2.5 rounded-full">Sign up</a>
            </div>
            <button id="mobileMenuButton" type="button"
                class="inline-flex h-11 w-11 items-center justify-center border border-line text-copy hover:border-line hover:text-highlight md:hidden"
                aria-controls="mobileMenu" aria-expanded="false" aria-label="Open navigation menu">
                <svg id="mobileMenuOpenIcon" class="h-6 w-6" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16"/>
                </svg>
                <svg id="mobileMenuCloseIcon" class="hidden h-6 w-6" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
            <div id="mobileMenu"
                class="absolute inset-x-6 top-full z-50 hidden max-h-[calc(100dvh-6rem)] overflow-y-auto rounded-2xl border border-line bg-surface p-3 text-sm font-semibold text-copy shadow-xl md:hidden">
                <details class="group">
                    <summary class="flex cursor-pointer list-none items-center justify-between rounded-xl px-4 py-3 hover:bg-raised hover:text-highlight">
                        <span>Product tour</span>
                        <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20"
                            fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd"
                                d="M5.22 7.22a.75.75 0 011.06 0L10 10.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 8.28a.75.75 0 010-1.06z"
                                clip-rule="evenodd"/>
                        </svg>
                    </summary>
                    <div class="ml-4 border-l border-line pl-3">
                        <a href="/product-tour" class="block rounded-lg px-3 py-2.5 hover:bg-raised hover:text-highlight">Tour overview</a>
                        <a href="/waitlist-screen" class="block rounded-lg px-3 py-2.5 hover:bg-raised hover:text-highlight">Live waitlist</a>
                        <a href="/sign-in-screen" class="block rounded-lg px-3 py-2.5 hover:bg-raised hover:text-highlight">Client sign-in</a>
                        <a href="/staff-profiles-screen" class="block rounded-lg px-3 py-2.5 hover:bg-raised hover:text-highlight">Staff profiles</a>
                        <a href="/team-dashboard-screen" class="block rounded-lg px-3 py-2.5 hover:bg-raised hover:text-highlight">Galley View</a>
                    </div>
                </details>
                <a href="/pricing" class="block rounded-xl px-4 py-3 hover:bg-raised hover:text-highlight">View pricing</a>
                <a href="/signup" class="marketing-metal mt-2 block rounded-xl bg-action px-4 py-3 text-center text-white hover:bg-actionHover">Sign up</a>
            </div>
        </nav>
    </header>

        <script>
            (() => {
                const button = document.getElementById('mobileMenuButton');
                const menu = document.getElementById('mobileMenu');
                const openIcon = document.getElementById('mobileMenuOpenIcon');
                const closeIcon = document.getElementById('mobileMenuCloseIcon');

                function setMenuOpen(open) {
                    button.setAttribute('aria-expanded', open ? 'true' : 'false');
                    button.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
                    menu.classList.toggle('hidden', !open);
                    openIcon.classList.toggle('hidden', open);
                    closeIcon.classList.toggle('hidden', !open);
                }

                button.addEventListener('click', () => {
                    setMenuOpen(button.getAttribute('aria-expanded') !== 'true');
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
                        setMenuOpen(false);
                        button.focus();
                    }
                });
            })();
        </script>

    <section class="marketing-grid overflow-hidden border-b border-line bg-canvas text-heading">
        <div id="tenantPhotoCarousel" class="relative mx-auto flex max-w-7xl flex-col gap-8 px-6 py-10 lg:grid lg:grid-cols-2 lg:items-stretch lg:gap-x-12 lg:py-16">
            <div class="relative order-2 h-72 overflow-hidden rounded-[2rem] border border-line bg-raised sm:h-96 lg:h-full lg:min-h-[560px]" aria-hidden="true">
                <img src="/assets/tenant-photos/barbershop.jpg" alt=""
                    class="tenant-photo-slide is-active absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="0" fetchpriority="high">
                <img src="/assets/tenant-photos/salon.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="1" loading="lazy">
                <img src="/assets/tenant-photos/auto-service.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="2" loading="lazy">
                <img src="/assets/tenant-photos/clinic.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="3" loading="lazy">
                <img src="/assets/tenant-photos/restaurant.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="4" loading="lazy">
                <img src="/assets/tenant-photos/nail-salon.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="5" loading="lazy">
                <img src="/assets/tenant-photos/massage-practice.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="6" loading="lazy">
                <img src="/assets/tenant-photos/tax-office.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="7" loading="lazy">
                <img src="/assets/tenant-photos/pet-groomer.jpg" alt=""
                    class="tenant-photo-slide absolute inset-0 h-full w-full object-cover object-center"
                    data-slide="8" loading="lazy">
            </div>
            <div class="order-1 min-w-0">
                <section class="max-w-xl py-4 lg:py-6">
                    <p class="text-highlight font-semibold mb-4">A better front door for your business</p>
                    <h1 class="text-4xl lg:text-6xl font-bold leading-tight tracking-tight mb-6">
                        Keep clients informed and your team moving.
                    </h1>
                    <p class="text-xl text-copy max-w-2xl mb-8">
                        ClientQ gives barbershops, salons, clinics, repair shops, and other service businesses a branded online waitlist and staff portal.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <a href="/product-tour" class="marketing-metal bg-action text-white hover:bg-actionHover px-6 py-3 rounded-full font-semibold">Take the product tour</a>
                        <a href="/pricing" class="marketing-metal bg-steel text-white hover:bg-steelHover px-6 py-3 rounded-full font-semibold">View pricing</a>
                    </div>
                    <div class="mt-9 grid max-w-lg grid-cols-3 gap-3 text-sm">
                        <div class="rounded-2xl border border-line bg-canvas p-3">
                            <strong class="block text-sm text-heading">Any device</strong>
                            <span class="text-xs text-copy">Easy client access</span>
                        </div>
                        <div class="rounded-2xl border border-line bg-canvas p-3">
                            <strong class="block text-sm text-heading">Your brand</strong>
                            <span class="text-xs text-copy">Logo and colors</span>
                        </div>
                        <div class="rounded-2xl border border-line bg-canvas p-3">
                            <strong class="block text-sm text-heading">Live queue</strong>
                            <span class="text-xs text-copy">Real-time updates</span>
                        </div>
                    </div>
                    <div class="mt-7 flex flex-col items-start gap-3 border-t border-line pt-5">
                        <span id="tenantPhotoCaption" class="text-sm font-semibold text-muted" aria-live="polite">Built for barbershops and grooming businesses</span>
                        <span class="flex justify-center gap-2" aria-label="Choose a tenant example">
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="0" aria-label="Show barbershop photo" aria-current="true"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="1" aria-label="Show salon photo" aria-current="false"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="2" aria-label="Show auto service photo" aria-current="false"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="3" aria-label="Show clinic photo" aria-current="false"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="4" aria-label="Show restaurant photo" aria-current="false"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="5" aria-label="Show nail salon photo" aria-current="false"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="6" aria-label="Show massage practice photo" aria-current="false"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="7" aria-label="Show tax office photo" aria-current="false"></button>
                            <button type="button" class="tenant-photo-dot h-2.5 w-2.5 rounded-full bg-muted transition-all"
                                data-carousel-button="8" aria-label="Show pet groomer photo" aria-current="false"></button>
                        </span>
                    </div>
                </section>
            </div>

            <div class="order-3 flex flex-wrap justify-center gap-x-2 gap-y-1 border-t border-line pt-6 text-xs font-semibold text-muted lg:col-span-2">
                <span>Barbershops</span>
                <span aria-hidden="true">•</span>
                <span>Salons</span>
                <span aria-hidden="true">•</span>
                <span>Auto service</span>
                <span aria-hidden="true">•</span>
                <span>Clinics</span>
                <span aria-hidden="true">•</span>
                <span>Restaurants</span>
                <span aria-hidden="true">•</span>
                <span>Nail salons</span>
                <span aria-hidden="true">•</span>
                <span>Massage</span>
                <span aria-hidden="true">•</span>
                <span>Tax offices</span>
                <span aria-hidden="true">•</span>
                <span>Pet groomers</span>
            </div>
        </div>
    </section>

    <script>
        (() => {
            const carousel = document.getElementById('tenantPhotoCarousel');
            const slides = [...carousel.querySelectorAll('[data-slide]')];
            const buttons = [...carousel.querySelectorAll('[data-carousel-button]')];
            const caption = document.getElementById('tenantPhotoCaption');
            const captions = [
                'Built for barbershops and grooming businesses',
                'Designed for salons and appointment-based teams',
                'Ready for repair shops and service centers',
                'Organized for clinics and patient arrivals',
                'Useful for restaurants and busy front-of-house teams',
                'Polished for nail salons and manicure teams',
                'Calm and coordinated for massage practices',
                'Professional arrivals for tax and advisory offices',
                'Simple check-ins for pet grooming appointments'
            ];
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let activeIndex = 0;
            let timer = null;

            function showSlide(index) {
                activeIndex = index;
                slides.forEach((slide, slideIndex) => {
                    slide.classList.toggle('is-active', slideIndex === index);
                });
                buttons.forEach((button, buttonIndex) => {
                    button.setAttribute('aria-current', buttonIndex === index ? 'true' : 'false');
                });
                caption.textContent = captions[index];
            }

            function restart() {
                if (reduceMotion) return;
                window.clearInterval(timer);
                timer = window.setInterval(() => {
                    showSlide((activeIndex + 1) % slides.length);
                }, 5500);
            }

            buttons.forEach((button, index) => {
                button.addEventListener('click', () => {
                    showSlide(index);
                    restart();
                });
            });

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    window.clearInterval(timer);
                } else {
                    restart();
                }
            });

            restart();
        })();
    </script>

    <main>
        <section class="bg-surface">
            <div class="max-w-6xl mx-auto px-6 py-16 lg:py-20">
                <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div class="max-w-2xl">
                        <p class="font-semibold text-highlight">Made for real service businesses</p>
                        <h2 class="mt-2 text-3xl font-bold lg:text-4xl">A familiar experience in every kind of workplace</h2>
                    </div>
                    <p class="max-w-md text-copy">Give clients one simple way to check in while your team keeps the business moving.</p>
                </div>
                <div class="industry-card-grid mt-10 grid items-stretch gap-5 md:grid-cols-3">
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/barbershop.jpg" alt="Barber cutting a Black customer's hair"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Barbershops</strong>
                            <p class="mt-1 text-sm text-copy">Manage walk-ins and individual barber queues.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/salon.jpg" alt="Colorful cosmetics used by salon and beauty professionals"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Salons and wellness</strong>
                            <p class="mt-1 text-sm text-copy">Keep arrivals organized across a busy team.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/auto-service.jpg" alt="Technician servicing a vehicle in an auto shop"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Repair and auto service</strong>
                            <p class="mt-1 text-sm text-copy">Replace crowded waiting rooms with live updates.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/clinic.jpg" alt="Diverse team of doctors and nurses in a clinic"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Clinics and patient services</strong>
                            <p class="mt-1 text-sm text-copy">Coordinate arrivals and reduce front-desk congestion.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/restaurant.jpg" alt="Colorful restaurant dining room ready for guests"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Restaurants and hospitality</strong>
                            <p class="mt-1 text-sm text-copy">Keep walk-in guests informed during peak service.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/nail-salon.jpg" alt="Nail technician and customer choosing manicure colors"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Nail salons</strong>
                            <p class="mt-1 text-sm text-copy">Organize appointments and walk-ins across manicure stations.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/massage-practice.jpg" alt="Asian massage therapist providing a seated neck massage"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Massage practices</strong>
                            <p class="mt-1 text-sm text-copy">Coordinate client arrivals while treatment rooms stay calm.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/tax-office.jpg" alt="Diverse couple meeting with a tax advisor"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Tax offices</strong>
                            <p class="mt-1 text-sm text-copy">Manage seasonal appointments and document-ready arrivals.</p>
                        </figcaption>
                    </figure>
                    <figure class="group flex h-full flex-col overflow-hidden rounded-3xl bg-surface shadow-lg">
                        <img src="/assets/tenant-photos/pet-groomer.jpg" alt="Pet groomer trimming a small dog's coat"
                            class="h-72 w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <figcaption class="flex-1 p-5 text-heading">
                            <strong class="text-xl">Pet groomers</strong>
                            <p class="mt-1 text-sm text-copy">Keep pet drop-offs and pickups moving on schedule.</p>
                        </figcaption>
                    </figure>
                </div>
            </div>
        </section>

        <section class="max-w-6xl mx-auto px-6 py-20">
            <div class="text-center max-w-2xl mx-auto mb-12">
                <p class="text-highlight font-semibold">Built around your business</p>
                <h2 class="text-3xl lg:text-4xl font-bold mt-2">One place for clients, employees, and owners</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-6">
                <article class="bg-surface border-2 border-line rounded-3xl p-6 shadow-sm">
                    <div class="marketing-metal w-12 h-12 bg-action rounded-2xl mb-5"></div>
                    <h3 class="text-xl font-bold mb-2">Branded client experience</h3>
                    <p class="text-copy">Use your business name, logo, and dedicated ClientQ address for a professional waitlist.</p>
                    <a href="/waitlist-screen" class="mt-4 inline-block font-semibold text-highlight hover:underline">Explore the waitlist &rarr;</a>
                </article>
                <article class="bg-surface border-2 border-line rounded-3xl p-6 shadow-sm">
                    <div class="marketing-metal w-12 h-12 bg-steel rounded-2xl mb-5"></div>
                    <h3 class="text-xl font-bold mb-2">Employee profiles</h3>
                    <p class="text-copy">Maintain employee information, logins, service details, and individual queues.</p>
                    <a href="/staff-profiles-screen" class="mt-4 inline-block font-semibold text-highlight hover:underline">Explore staff profiles &rarr;</a>
                </article>
                <article class="bg-surface border-2 border-line rounded-3xl p-6 shadow-sm">
                    <div class="marketing-metal w-12 h-12 bg-raised rounded-2xl mb-5"></div>
                    <h3 class="text-xl font-bold mb-2">Galley View</h3>
                    <p class="text-copy">Show your branded waitlist on a shared display with staff assignments, wait times, and automatic page rotation.</p>
                    <a href="/team-dashboard-screen" class="mt-4 inline-block font-semibold text-highlight hover:underline">Explore Galley View &rarr;</a>
                </article>
            </div>
        </section>

        <section class="marketing-grid marketing-grid-right overflow-hidden bg-canvas">
            <div class="max-w-6xl mx-auto grid gap-10 px-6 py-16 lg:grid-cols-[0.8fr_1.2fr] lg:items-center lg:py-20">
                <div>
                    <p class="font-semibold text-highlight">One system, your identity</p>
                    <h2 class="mt-2 text-3xl font-bold lg:text-4xl">Your tenant site feels like part of your business</h2>
                    <p class="mt-5 text-lg text-copy">ClientQ combines your public sign-up page, live waitlist, and team tools without forcing every business into the same look.</p>
                    <a href="/product-tour" class="marketing-metal mt-7 inline-block rounded-full bg-action px-6 py-3 font-semibold text-white hover:bg-actionHover">See every screen</a>
                </div>
                <figure class="rounded-[2rem] border border-line bg-gradient-to-br from-surface to-raised p-4 shadow-xl sm:p-7">
                    <img src="/assets/tenant-showcase.svg"
                        alt="Examples of a barbershop, wellness clinic, and auto service business using ClientQ"
                        class="w-full rounded-2xl">
                    <figcaption class="mt-4 text-center text-sm font-semibold text-copy">A branded client experience with a shared ClientQ workflow.</figcaption>
                </figure>
            </div>
        </section>

        <section class="marketing-grid border-t border-line bg-gradient-to-r from-surface to-canvas">
            <div class="max-w-4xl mx-auto px-6 py-16 text-center">
                <h2 class="text-3xl font-bold mb-4">Ready to make waiting easier?</h2>
                <p class="text-heading/80 mb-7">Choose the plan that fits your team and create your business portal.</p>
                <a href="/pricing" class="marketing-metal inline-block bg-action text-white px-7 py-3 rounded-full font-semibold hover:bg-actionHover">Compare plans</a>
            </div>
        </section>
    </main>
    <?php marketingFooter(); ?>
</body>
</html>
