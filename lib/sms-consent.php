<?php

function renderTransactionalSmsConsent(string $applicationName = 'ClientQ', string $applicationDescription = ''): void
{
    ?>
    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 text-xs leading-relaxed text-gray-600">
        By providing your phone number, you agree to receive an appointment sign-up confirmation and event reminders from
        <strong class="text-gray-800"><?= htmlspecialchars($applicationName) ?></strong><?= htmlspecialchars($applicationDescription) ?>.
        Message and data rates may apply. Reply STOP to opt out or HELP for help. View our
        <a href="<?= htmlspecialchars(platformUrl('/privacy')) ?>"
            class="font-semibold text-accent underline hover:text-accentDark">Privacy Statement</a>
        and
        <a href="<?= htmlspecialchars(platformUrl('/terms')) ?>"
            class="font-semibold text-accent underline hover:text-accentDark">Terms &amp; Conditions</a>.
    </div>
    <?php
}
