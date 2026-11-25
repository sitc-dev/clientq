<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/marketing-tour-layout.php';

if (!isPlatformHost()) {
    http_response_code(404);
    exit('Page not found.');
}

marketingTourHeader(
    'Terms & Conditions',
    'Terms governing access to and use of the ClientQ service.'
);
?>
    <main>
        <section class="marketing-grid marketing-grid-right bg-gradient-to-br from-canvas via-surface to-raised text-heading">
            <div class="mx-auto max-w-4xl px-6 py-16 lg:py-20">
                <p class="font-semibold text-highlight">ClientQ legal</p>
                <h1 class="mt-3 text-4xl font-bold lg:text-5xl">Terms &amp; Conditions</h1>
                <p class="mt-5 text-copy">Effective October 4, 2026</p>
            </div>
        </section>

        <article class="mx-auto max-w-4xl space-y-10 px-6 py-14 leading-7 text-copy">
            <section>
                <h2 class="text-2xl font-bold text-heading">Acceptance of terms</h2>
                <p class="mt-3">By creating an account, accessing, or using ClientQ, you agree to these Terms &amp; Conditions. If you use ClientQ for a business or organization, you represent that you have authority to bind that entity. If you do not agree, do not use the service.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">The service</h2>
                <p class="mt-3">ClientQ provides hosted tools for business waitlists, client sign-in, staff profiles, tenant administration, and related communications. Features may change as the service is improved. Example pages are demonstrations only and do not create accounts, assign staff, or store waitlist entries.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Accounts and authorized use</h2>
                <p class="mt-3">You must provide accurate account information, safeguard credentials, restrict access to authorized users, and promptly report suspected unauthorized access. You are responsible for activity performed through your account and for configuring staff permissions appropriately.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Business responsibilities</h2>
                <p class="mt-3">Business customers are responsible for their client relationships, services, waitlist decisions, staff actions, legal notices, privacy obligations, and the accuracy of information shown on their tenant sites. Businesses must obtain all consents required for text messages and must honor opt-out requests.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Acceptable use</h2>
                <p class="mt-3">You may not use ClientQ to violate law, infringe rights, send unsolicited or deceptive communications, upload malicious code, probe or disrupt the service, obtain unauthorized access, impersonate another person, or process information you are not authorized to collect.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Subscriptions and payment</h2>
                <p class="mt-3">Paid plans, billing intervals, included features, and applicable limits are described during signup or checkout. Unless otherwise stated, subscription fees are charged in advance and are non-refundable except where required by law. Failure to pay may result in suspension or termination.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Ownership</h2>
                <p class="mt-3">ClientQ and its software, design, branding, and documentation are owned by ClientQ or its licensors. Business customers retain their rights in content and data they submit and grant ClientQ permission to host and process that material solely to provide and protect the service.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Availability and disclaimers</h2>
                <p class="mt-3">ClientQ is provided on an “as is” and “as available” basis to the extent permitted by law. ClientQ does not guarantee uninterrupted operation, specific business results, wait-time accuracy, or that every defect will be corrected. Businesses should maintain appropriate procedures for service interruptions.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Limitation of liability</h2>
                <p class="mt-3">To the fullest extent permitted by law, ClientQ will not be liable for indirect, incidental, special, consequential, exemplary, or punitive damages, or for lost profits, revenues, data, goodwill, or business opportunities arising from use of the service.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Suspension and termination</h2>
                <p class="mt-3">You may stop using ClientQ at any time. ClientQ may suspend or terminate access for nonpayment, security risk, legal requirements, material breach, or activity that threatens users or the service. Provisions that by their nature should survive termination will remain effective.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Changes</h2>
                <p class="mt-3">These terms may be updated as ClientQ evolves. Material changes will be communicated through reasonable means. Continued use after updated terms take effect constitutes acceptance of the revised terms.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold text-heading">Contact</h2>
                <p class="mt-3">Questions about these terms may be submitted through the ClientQ business or support contact that provided access to the service.</p>
            </section>
        </article>
    </main>
<?php marketingTourFooter(); ?>
