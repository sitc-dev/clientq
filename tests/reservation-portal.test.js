const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

test('changing the reservation date during initial loading still initializes the staff schedule', async () => {
    const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'reservations.php'), 'utf8');
    const script = source.slice(source.indexOf('<script>') + 8, source.indexOf('</script>'))
        .replace('<?= json_encode(platformCsrfToken()) ?>', '"test-token"');
    const days = [1, 2, 3, 4, 5, 6, 7].map(value => ({ value: String(value), checked: false }));
    const elements = Object.fromEntries([
        'reservationMessage', 'staffReservations', 'openingTime', 'closingTime', 'bookingDays',
        'saveSchedule', 'staffReservationDate', 'scheduleForm', 'scheduleFields'
    ].map(id => [id, { value: '', disabled: true, addEventListener() {}, replaceChildren() {} }]));
    elements.staffReservationDate.value = '2026-11-15';
    const pending = [];
    const context = vm.createContext({
        URLSearchParams,
        console,
        document: {
            getElementById: id => elements[id],
            querySelectorAll: () => days
        },
        fetch: () => new Promise(resolve => pending.push(resolve))
    });
    vm.runInContext(script, context);
    const latest = context.loadMyReservations();
    const data = {
        success: true,
        reservations: [],
        schedule: { days: [2, 3, 4, 5, 6, 7], opening_time: '08:30', closing_time: '17:00', days_ahead: 14 }
    };
    pending[1]({ ok: true, json: async () => data });
    await latest;
    assert.equal(elements.saveSchedule.disabled, false);
    assert.equal(elements.openingTime.value, '08:30');
    assert.equal(elements.closingTime.value, '17:00');
    assert.equal(elements.bookingDays.value, 14);
    assert.equal(days[0].checked, false);
    assert.equal(days[1].checked, true);
    pending[0]({ ok: true, json: async () => ({ ...data, schedule: { ...data.schedule, opening_time: '10:00' } }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(elements.openingTime.value, '08:30');
});
