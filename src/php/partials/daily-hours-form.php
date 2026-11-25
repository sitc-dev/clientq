<?php

require_once dirname(__DIR__, 3) . '/lib/waitlist-hours.php';
$isWaitlistHours = $dailyHoursScope === 'waitlist';
$dailyHoursToday = waitlistNow()->format('Y-m-d');
?>
        <form data-daily-hours="<?= htmlspecialchars($dailyHoursScope) ?>" data-csrf="<?= htmlspecialchars(platformCsrfToken()) ?>"
            class="border-b p-4 md:p-6 space-y-4 max-w-xl">
            <h2 class="font-semibold"><?= $isWaitlistHours ? 'Daily waitlist hours' : 'My daily waitlist availability' ?></h2>
            <p class="text-sm text-gray-500">
                <?= $isWaitlistHours
                    ? 'Set when clients may join the waitlist for a specific day, or close it for the day. The existing queue remains visible.'
                    : 'Set your own start and end times for a specific workday, or mark yourself off. Clients can join before your start time and will see a notice. Off-duty and finished staff are hidden from signup.' ?>
                Each day defaults to 8:00 AM-4:30 PM. All times are Eastern time. Appointment schedules are separate.
            </p>
            <label class="block text-sm font-semibold">Workday
                <input name="date" type="date" value="<?= $dailyHoursToday ?>" min="<?= $dailyHoursToday ?>" required
                    class="mt-1 block w-full border rounded-lg px-3 py-2">
            </label>
            <fieldset data-hours-fields disabled class="space-y-4">
                <label class="flex items-center gap-2 text-sm">
                    <input name="is_closed" type="checkbox">
                    <?= $isWaitlistHours ? 'Close the waitlist for this day' : 'I am not working this day' ?>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold"><?= $isWaitlistHours ? 'Waitlist opens' : 'I start at' ?>
                        <input name="opening_time" type="time" value="08:00" required
                            class="mt-1 block w-full border rounded-lg px-3 py-2">
                    </label>
                    <label class="block text-sm font-semibold"><?= $isWaitlistHours ? 'Waitlist closes' : 'I finish at' ?>
                        <input name="closing_time" type="time" value="16:30" required
                            class="mt-1 block w-full border rounded-lg px-3 py-2">
                    </label>
                </div>
                <button type="submit" class="w-full bg-accent text-white px-4 py-3 rounded-lg font-semibold hover:bg-accentDark disabled:opacity-50">
                    <?= $isWaitlistHours ? 'Save waitlist hours' : 'Save my availability' ?>
                </button>
            </fieldset>
            <p data-hours-message role="status" aria-live="polite" tabindex="-1" class="text-sm text-gray-600">Loading daily hours...</p>
        </form>
        <script src="/assets/daily-hours.js" defer></script>
