<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-tour-layout.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

marketingTourHeader(
    'Privacy Statement',
    'How ClientQ collects, uses, protects, and shares information.'
);
?>
    <main>
        <section class="marketing-grid marketing-grid-right bg-gradient-to-br from-canvas via-surface to-raised text-heading">
            <div class="mx-auto max-w-4xl px-6 py-16 lg:py-20">
                <p class="font-semibold text-highlight">ClientQ legal</p>
                <h1 class="mt-3 text-4xl font-bold lg:text-5xl">Privacy Statement</h1>
                <p class="mt-5 text-copy">Effective October 4, 2026</p>
            </div>
        </section>

        <article class="mx-auto max-w-4xl space-y-10 px-6 py-14 leading-7 text-copy">
            <section>
                <h2 class="text-2xl font-bold text-heading">Overview</h2>
                <p class="mt-3">ClientQ provides waitlist and client-management tools to service businesses. This statement explains the information ClientQ processes when businesses create accounts, staff members use the service, and clients join a business waitlist.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Information we collect</h2>
                <p class="mt-3">We may collect business and account information such as names, business names, email addresses, telephone numbers, login credentials, branding preferences, subscription records, and support communications. When a client joins a tenant's waitlist, we may process the client's name, telephone number, party size, selected staff member, waitlist status, and SMS consent record.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">How information is used</h2>
                <p class="mt-3">Information is used to provide and secure ClientQ, create and administer tenant accounts, operate waitlists, deliver requested transactional or informational messages, process subscriptions, respond to support requests, prevent abuse, and comply with legal obligations. ClientQ does not use waitlist SMS consent to send unrelated marketing messages.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Business customers and client data</h2>
                <p class="mt-3">Each business customer controls the client information submitted to its tenant site and is responsible for its own notices, lawful processing, and staff access. ClientQ processes that information to provide the service on the business customer's behalf. Tenant operational data is maintained separately from the central platform registry.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Sharing and service providers</h2>
                <p class="mt-3">Information may be shared with infrastructure, payment, communications, and professional service providers only as needed to operate ClientQ. Information may also be disclosed when required by law, to protect users or the service, or as part of a merger, financing, acquisition, or sale of assets. ClientQ does not sell personal information.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Retention and security</h2>
                <p class="mt-3">Information is retained for as long as reasonably necessary to provide the service, satisfy contractual and legal requirements, resolve disputes, and enforce agreements. ClientQ uses administrative and technical safeguards designed to protect information, but no internet service can guarantee absolute security.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Your choices</h2>
                <p class="mt-3">Business account holders may review and update account information through ClientQ or request assistance. Clients should contact the business whose waitlist they joined to request access, correction, or deletion of tenant-controlled information. Message recipients may reply STOP to opt out of text messages and HELP for help.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Changes to this statement</h2>
                <p class="mt-3">This statement may be updated as ClientQ changes. The effective date above identifies the latest revision. Continued use of ClientQ after an update is subject to the revised statement.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Contact</h2>
                <p class="mt-3">Questions or privacy requests may be submitted through the ClientQ business or support contact that provided access to the service.</p>
            </section>
        </article>
    </main>
<?php marketingTourFooter(); ?>
