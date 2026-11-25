<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../lib/reservations.php';
requireAuth();
$staff = getLoggedInStaff();
$appointments = appointmentsEnabled(requireTenant());
adminHeader('My Appointment Schedule', $staff, 'reservations');
?>
<div class="p-4 md:p-6 space-y-8">
    <div id="reservationMessage" role="status" aria-live="polite" class="hidden rounded-lg p-3 text-sm"></div>
    <?php if (!$appointments): ?>
    <p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Appointment scheduling is not included in this Essentials subscription. New online bookings are disabled. Existing reservations are preserved and can still be viewed and cancelled below.</p>
    <?php endif; ?>
    <form id="scheduleForm" class="max-w-2xl space-y-5">
        <fieldset id="scheduleFields" <?= $appointments ? '' : 'disabled' ?> class="space-y-5 disabled:opacity-50">
        <h2 class="text-xl font-bold">My availability</h2>
        <p class="text-sm text-gray-600">No appointments are available until you choose working days and save your own schedule. This applies to owners and staff. Each party member reserves 45 minutes. Slots can start before closing; the final slot may finish after closing. All dates and times are Eastern.</p>
        <fieldset class="flex flex-wrap gap-4">
            <legend class="font-semibold mb-2">Working days</legend>
            <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $day => $label): ?>
            <label class="flex items-center gap-2"><input type="checkbox" name="working_day" value="<?= $day ?>"><?= $label ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label for="openingTime" class="block font-semibold mb-1">Opening time</label><input id="openingTime" type="time" required class="w-full rounded-lg border px-3 py-2"></div>
            <div><label for="closingTime" class="block font-semibold mb-1">Closing time</label><input id="closingTime" type="time" required max="23:15" class="w-full rounded-lg border px-3 py-2"></div>
            <div><label for="bookingDays" class="block font-semibold mb-1">Days ahead</label><input id="bookingDays" type="number" min="1" max="90" required class="w-full rounded-lg border px-3 py-2"></div>
        </div>
        <p class="text-sm text-gray-600">Changing availability does not cancel existing reservations. Uncheck every day to stop accepting new reservations.</p>
        <button id="saveSchedule" disabled class="rounded-lg bg-accent px-5 py-3 font-semibold text-white hover:bg-accentDark disabled:opacity-50">Save My Schedule</button>
        </fieldset>
    </form>
    <section class="space-y-4">
        <h2 class="text-xl font-bold">My reservations</h2>
        <label for="staffReservationDate" class="block font-semibold">Choose date</label>
        <input id="staffReservationDate" type="date" value="<?= reservationNow()->format('Y-m-d') ?>" class="rounded-lg border px-3 py-2">
        <div id="staffReservations" class="grid gap-3 md:grid-cols-2"></div>
    </section>
</div>
<script>
    const reservationToken = <?= json_encode(platformCsrfToken()) ?>;
    const reservationMessage = document.getElementById('reservationMessage');
    function reservationNotice(message, success) {
        reservationMessage.textContent = message;
        reservationMessage.className = `rounded-lg p-3 text-sm ${success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
    }
    let reservationRequest = 0;
    let reservationScheduleLoaded = false;
    async function loadMyReservations(loadSchedule = false) {
        loadSchedule = loadSchedule || !reservationScheduleLoaded;
        const request = ++reservationRequest;
        const container = document.getElementById('staffReservations');
        container.textContent = 'Loading reservations...';
        try {
            const response = await fetch('api/reservations?' + new URLSearchParams({ date: document.getElementById('staffReservationDate').value }), { cache: 'no-store' });
            const data = await response.json();
            if (request !== reservationRequest) return;
            if (!response.ok || !data.success) throw new Error(data.error || 'Reservations could not be loaded.');
            if (loadSchedule) {
                document.querySelectorAll('[name="working_day"]').forEach(input => { input.checked = data.schedule.days.includes(Number(input.value)); });
                document.getElementById('openingTime').value = data.schedule.opening_time;
                document.getElementById('closingTime').value = data.schedule.closing_time;
                document.getElementById('bookingDays').value = data.schedule.days_ahead;
                document.getElementById('saveSchedule').disabled = data.appointments_enabled === false;
                document.getElementById('scheduleFields').disabled = data.appointments_enabled === false;
                reservationScheduleLoaded = true;
            }
            container.replaceChildren();
            if (!data.reservations.length) container.textContent = 'No reservations for this date.';
            for (const booking of data.reservations) {
                const card = document.createElement('article');
                card.className = 'rounded-xl border bg-gray-50 p-4 space-y-2';
                const details = document.createElement('p');
                details.className = 'whitespace-pre-line text-sm';
                const formatTime = value => {
                    const [hours, minutes] = value.slice(11, 16).split(':').map(Number);
                    return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${hours >= 12 ? 'PM' : 'AM'}`;
                };
                details.textContent = `${booking.name}\n${formatTime(booking.starts_at)} - ${formatTime(booking.ends_at)} Eastern\nPhone: ${booking.phone}\nParty: ${booking.party_size} | Code: ${booking.confirmation_code}`;
                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700';
                cancel.textContent = 'Cancel Reservation';
                cancel.addEventListener('click', async () => {
                    if (!confirm('Cancel this reservation? This cannot be undone.')) return;
                    cancel.disabled = true;
                    try {
                        await saveReservationSettings({ action: 'cancel', id: booking.id });
                        reservationNotice('Reservation cancelled. Its slots are available again.', true);
                        await loadMyReservations();
                    } catch (error) {
                        console.error('Reservation cancellation failed:', error);
                        reservationNotice(error.message, false);
                        cancel.disabled = false;
                    }
                });
                card.append(details, cancel);
                container.append(card);
            }
        } catch (error) {
            if (request !== reservationRequest) return;
            console.error('Staff reservations failed:', error);
            container.textContent = 'Reservations could not be loaded.';
            reservationNotice(error.message, false);
        }
    }
    async function saveReservationSettings(details) {
        const response = await fetch('api/reservations', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...details, csrf_token: reservationToken })
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Reservation settings could not be saved.');
    }
    document.getElementById('scheduleForm').addEventListener('submit', async event => {
        event.preventDefault();
        const button = document.getElementById('saveSchedule');
        button.disabled = true;
        try {
            await saveReservationSettings({
                action: 'schedule',
                days: [...document.querySelectorAll('[name="working_day"]:checked')].map(input => Number(input.value)),
                opening_time: document.getElementById('openingTime').value,
                closing_time: document.getElementById('closingTime').value,
                days_ahead: document.getElementById('bookingDays').value
            });
            reservationNotice('Your reservation schedule was saved.', true);
        } catch (error) {
            console.error('Reservation schedule failed:', error);
            reservationNotice(error.message, false);
        } finally { button.disabled = false; }
    });
    document.getElementById('staffReservationDate').addEventListener('change', () => loadMyReservations());
    loadMyReservations(true);
</script>
<?php adminFooter(); ?>
