<?php
require_once dirname(__DIR__, 3) . '/lib/reservations.php';
require_once dirname(__DIR__, 3) . '/lib/sms-consent.php';
$reservationToday = reservationNow();
?>
<div id="reservation-schedule" role="tabpanel" aria-labelledby="reservationTab" class="hidden waitlist-mode-appointment">
    <div class="waitlist-mode-heading border-b px-4 py-3">
        <h2 class="font-bold text-gray-900">Book an appointment</h2>
        <p class="mt-1 text-sm text-gray-700">Choose a date and available time.</p>
    </div>
    <form id="reservationForm" data-csrf="<?= htmlspecialchars(platformCsrfToken()) ?>" class="p-4 space-y-5">
        <p class="text-sm text-gray-600">Reserve with your preferred staff member. Each person takes 45 minutes. All times are Eastern. Sign in to the waitlist when you arrive.</p>
        <div>
            <label for="reservationName" class="block text-sm font-semibold mb-1">Name</label>
            <input id="reservationName" required maxlength="100" pattern="[a-zA-Z ]+" autocomplete="name"
                class="w-full rounded-lg border px-3 py-2">
        </div>
        <div>
            <label for="reservationParty" class="block text-sm font-semibold mb-1">No. in party (including self)</label>
            <select id="reservationParty" class="w-full rounded-lg border px-3 py-2">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?= $i ?>"><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label for="reservationStaff" class="block text-sm font-semibold mb-1">Select staff member</label>
            <select id="reservationStaff" required class="w-full rounded-lg border px-3 py-2 focus:ring-2 focus:ring-accent">
                <option value="">Staff Members</option>
                <?php foreach ($staffMembers as $staff): ?>
                <option value="<?= (int)$staff['id'] ?>"><?= htmlspecialchars(staffDisplayName($staff)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php renderTransactionalSmsConsent($businessName); ?>
        <div>
            <label for="reservationPhone" class="block text-sm font-semibold mb-1">Phone number</label>
            <input id="reservationPhone" type="tel" required autocomplete="tel" inputmode="tel" placeholder="(xxx)xxx-xxxx"
                class="w-full rounded-lg border px-3 py-2">
        </div>
        <div>
            <label for="reservationDate" class="block text-sm font-semibold mb-1">Reservation date</label>
            <input type="date" id="reservationDate" required value="<?= $reservationToday->format('Y-m-d') ?>"
                min="<?= $reservationToday->format('Y-m-d') ?>" max="<?= $reservationToday->modify('+14 days')->format('Y-m-d') ?>"
                class="w-full min-w-0 rounded-lg border px-3 py-2 focus:ring-2 focus:ring-accent">
        </div>
        <fieldset>
            <legend class="text-sm font-semibold mb-2">Available times</legend>
            <p id="reservationAvailability" role="status" aria-live="polite" class="text-sm text-gray-600">No reservations available. Select a staff member to check available times.</p>
            <div id="reservationSlots" class="grid grid-cols-2 gap-2"></div>
        </fieldset>
        <p id="reservationError" role="alert" class="hidden rounded-lg bg-red-100 p-3 text-sm text-red-800"></p>
        <p id="reservationConfirmation" role="status" class="hidden rounded-lg bg-green-100 p-3 text-sm text-green-800"></p>
        <div class="space-y-3 pt-2">
            <button id="bookReservation" type="submit" disabled class="w-full rounded-lg bg-accent py-3 font-semibold text-white hover:bg-accentDark disabled:opacity-50">Reserve Time</button>
            <button id="resetReservation" type="button" class="w-full rounded-lg bg-gray-600 py-3 font-semibold text-white hover:bg-gray-700 disabled:opacity-50">Reset Inputs</button>
        </div>
    </form>
</div>
<script src="/assets/reservations.js" defer></script>
