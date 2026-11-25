const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function harness() {
    function element(value = '') {
        const classes = new Set(['hidden']);
        return {
            value, disabled: false, children: [], listeners: {},
            classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) },
            addEventListener(name, callback) { this.listeners[name] = callback; },
            querySelector() { return this.selected ? { value: this.selected } : null; },
            replaceChildren() { this.children = []; this.selected = null; }
        };
    }
    const ids = [
        'reservationForm', 'reservationStaff', 'reservationDate', 'reservationParty', 'reservationSlots',
        'reservationAvailability', 'reservationError', 'reservationConfirmation', 'bookReservation',
        'resetReservation', 'reservationPhone', 'reservationName'
    ];
    const elements = Object.fromEntries(ids.map(id => [id, element()]));
    Object.assign(elements.reservationDate, { value: '2026-11-15', min: '2026-11-15', max: '2026-11-29' });
    elements.reservationParty.value = '1';
    elements.reservationForm.dataset = { csrf: 'test-token' };
    let resets = 0;
    elements.reservationForm.reset = () => {
        resets++;
        for (const id of ['reservationStaff', 'reservationName', 'reservationPhone']) elements[id].value = '';
        elements.reservationDate.value = '2026-11-15';
        elements.reservationParty.value = '1';
        elements.reservationSlots.selected = null;
    };
    const requests = [];
    const pending = [];
    const context = vm.createContext({
        URLSearchParams, Intl, Date,
        console: { error() {} },
        setInterval() {},
        phoneDigits: value => value.replace(/\D/g, ''),
        document: { getElementById: id => elements[id], querySelectorAll: () => [] },
        fetch(url, options) {
            requests.push({ url, options });
            return new Promise(resolve => pending.push(resolve));
        }
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'reservations.js'), 'utf8'), context);
    return { elements, pending, requests, resets: () => resets };
}

test('appointment reset clears fields and messages, restores date bounds, and ignores outstanding availability', async () => {
    const { elements, pending, requests, resets } = harness();
    elements.reservationStaff.value = '2';
    const loading = elements.reservationStaff.listeners.change();
    elements.reservationName.value = 'Test Client';
    elements.reservationPhone.value = '2025550123';
    elements.reservationParty.value = '3';
    elements.reservationDate.max = '2026-11-22';
    elements.reservationError.classList.remove('hidden');
    elements.reservationConfirmation.classList.remove('hidden');
    elements.resetReservation.listeners.click();
    assert.equal(resets(), 1);
    for (const id of ['reservationStaff', 'reservationName', 'reservationPhone']) assert.equal(elements[id].value, '');
    assert.equal(elements.reservationParty.value, '1');
    assert.equal(elements.reservationDate.value, '2026-11-15');
    assert.equal(elements.reservationDate.max, '2026-11-29');
    assert.equal(elements.bookReservation.disabled, true);
    assert.equal(elements.reservationError.classList.contains('hidden'), true);
    assert.equal(elements.reservationConfirmation.classList.contains('hidden'), true);
    pending[0]({ ok: true, json: async () => ({
        success: true, slots: [{ value: '08:30', label: '8:30 AM' }],
        date: '2026-11-16', min_date: '2026-11-15', max_date: '2026-11-22'
    }) });
    await loading;
    assert.equal(elements.reservationSlots.children.length, 0);
    assert.equal(elements.reservationDate.value, '2026-11-15');
    assert.equal(elements.reservationDate.max, '2026-11-29');
    assert.equal(elements.reservationAvailability.textContent, 'No reservations available. Select a staff member to check available times.');
    assert.equal(requests.length, 1);
});

test('a staff member with no published availability has no slots and cannot be booked', async () => {
    const { elements, pending } = harness();
    elements.reservationStaff.value = '2';
    const loading = elements.reservationStaff.listeners.change();
    pending[0]({ ok: true, json: async () => ({
        success: true, slots: [], date: '2026-11-16', min_date: '2026-11-15', max_date: '2026-11-29'
    }) });
    await loading;
    assert.match(elements.reservationAvailability.textContent, /^No reservations available on /);
    assert.equal(elements.reservationSlots.children.length, 0);
    assert.equal(elements.bookReservation.disabled, true);
});

test('reset is blocked while a booking is being saved and enabled again afterward', async () => {
    const { elements, pending, requests, resets } = harness();
    elements.reservationStaff.value = '2';
    elements.reservationName.value = 'Test Client';
    elements.reservationPhone.value = '2025550123';
    elements.reservationSlots.selected = '08:30';
    const saving = elements.reservationForm.listeners.submit({ preventDefault() {} });
    assert.equal(elements.resetReservation.disabled, true);
    elements.resetReservation.listeners.click();
    assert.equal(resets(), 0);
    assert.equal(elements.reservationName.value, 'Test Client');
    assert.equal(requests[0].url, '/api/book-reservation');
    pending[0]({ ok: false, status: 503, json: async () => ({ error: 'Test booking failure' }) });
    await saving;
    assert.equal(elements.resetReservation.disabled, false);
});
