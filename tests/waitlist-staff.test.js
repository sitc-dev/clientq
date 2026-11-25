const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const page = fs.readFileSync(path.join(__dirname, '..', 'index.php'), 'utf8');
const start = page.indexOf('function updateStaffDropdowns');
const end = page.indexOf('// Staff filter change', start);
const script = page.slice(start, end);

function dropdown(value, placeholder, defaultValue) {
    return {
        value,
        options: [{ value: defaultValue, textContent: placeholder, dataset: {} }, { value: '1', textContent: 'Old name', dataset: {} }],
        get selectedOptions() { return this.options.filter(option => option.value === this.value); },
        replacements: 0,
        events: [],
        dispatchEvent(event) {
            this.events.push(event.type);
        },
        replaceChildren(...options) {
            this.options = options;
            this.replacements++;
        }
    };
}

function harness(responses) {
    const elements = {
        staffFilter: dropdown('1', 'All Staff', 'all'),
        staffSelect: dropdown('1', 'Staff Members', ''),
        reservationStaff: dropdown('1', 'Staff Members', ''),
        clientList: { innerHTML: '' },
        pagination: { classList: { add() {}, remove() {} } },
        staffAvailabilityNotice: { textContent: '', classList: { toggle() {} } },
        waitlistSignupFields: { disabled: false },
        waitlistHoursMessage: { textContent: '' }
    };
    const requests = [];
    const context = vm.createContext({
        URLSearchParams,
        Event: class { constructor(type) { this.type = type; } },
        console: { error() {} },
        document: {
            getElementById: id => elements[id],
            createElement() {
                return {
                    value: '',
                    textContent: '',
                    dataset: {},
                    get innerHTML() {
                        return this.textContent.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
                    }
                };
            }
        },
        async fetch(url) {
            requests.push(new URL(url, 'http://example.test'));
            const response = responses.shift();
            return { ok: true, async json() {
                return {
                    available_staff: response.staff.map(member => ({ ...member, selection_label: member.goes_by, availability_notice: '' })),
                    waitlist: { can_join: true, message: 'Open' },
                    ...response
                };
            } };
        }
    });
    vm.runInContext(
        'const isGalleyView = false; const clientsPerPage = 10; let currentPage = 1;'
        + 'let currentStaffFilter = "1"; let totalPages = 1; let clientRequest = 0;' + script,
        context
    );
    return { context, elements, requests };
}

test('live waitlist refresh updates both nickname dropdowns and keeps selections without rebuilding unchanged options', async () => {
    const response = {
        staff: [{ id: 1, goes_by: 'Mo & Co <Stylist>' }],
        clients: [{ id: 1, name: 'Client', staff_name: 'Mo & Co <Stylist>', wait_time: '01:00', no_in_party: 1 }],
        total_pages: 1
    };
    const { context, elements } = harness([response, response]);
    await context.loadClients();
    for (const id of ['staffFilter', 'staffSelect', 'reservationStaff']) {
        assert.equal(elements[id].options.find(option => option.value === '1').textContent, 'Mo & Co <Stylist>');
        assert.equal(elements[id].value, '1');
        assert.equal(elements[id].replacements, 1);
        assert.equal(elements[id].options.some(option => option.value === 'any'), id !== 'reservationStaff');
    }
    assert.match(elements.clientList.innerHTML, /Mo &amp; Co &lt;Stylist&gt;/);
    await context.loadClients();
    assert.equal(elements.staffFilter.replacements, 1);
    assert.equal(elements.staffSelect.replacements, 1);
});

test('First available staff selection survives live refresh without appearing in appointment choices', async () => {
    const response = { staff: [{ id: 2, goes_by: 'Taylor' }], clients: [], total_pages: 1 };
    const { context, elements, requests } = harness([response]);
    elements.staffFilter.value = 'any';
    elements.staffSelect.value = 'any';
    vm.runInContext('currentStaffFilter = "any";', context);
    await context.loadClients();
    assert.equal(elements.staffFilter.value, 'any');
    assert.equal(elements.staffSelect.value, 'any');
    assert.equal(elements.staffSelect.options.find(option => option.value === 'any').textContent, 'First available staff');
    assert.equal(elements.reservationStaff.options.some(option => option.value === 'any'), false);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].searchParams.get('staff'), 'any');
});

test('removing selected staff resets signup and refetches the unfiltered waitlist', async () => {
    const response = { staff: [{ id: 2, goes_by: 'Taylor' }], clients: [], total_pages: 1 };
    const { context, elements, requests } = harness([response, response]);
    await context.loadClients();
    assert.equal(elements.staffFilter.value, 'all');
    assert.equal(elements.staffSelect.value, '');
    assert.equal(elements.reservationStaff.value, '');
    assert.deepEqual(elements.reservationStaff.events, ['change']);
    assert.equal(elements.staffFilter.options[0].textContent, 'All Staff');
    assert.equal(elements.staffSelect.options[0].textContent, 'Staff Members');
    assert.equal(requests.length, 2);
    assert.equal(requests[0].searchParams.get('staff'), '1');
    assert.equal(requests[1].searchParams.get('staff'), 'all');
});

test('waitlist positions close the gap after a client is removed without using permanent record IDs', async () => {
    const staff = [{ id: 1, goes_by: 'Taylor' }];
    const clients = [101, 305, 907].map(id => ({
        id, name: `Client ${id}`, staff_name: 'Taylor', wait_time: '01:00', no_in_party: 1
    }));
    const { context, elements } = harness([
        { staff, clients, total_pages: 1 },
        { staff, clients: clients.slice(1), total_pages: 1 }
    ]);
    function positions() {
        return [...elements.clientList.innerHTML.matchAll(/<tr\b[^>]*>\s*<td\b[^>]*>(\d+)<\/td>/g)]
            .map(match => Number(match[1]));
    }
    await context.loadClients();
    assert.deepEqual(positions(), [1, 2, 3]);
    await context.loadClients();
    assert.deepEqual(positions(), [1, 2]);
    assert.doesNotMatch(elements.clientList.innerHTML, /Client 101/);
    assert.match(elements.clientList.innerHTML, /Client 305/);
    assert.match(elements.clientList.innerHTML, /Client 907/);
});

test('off-duty staff remain in queue and appointment filters but not the signup dropdown', async () => {
    const response = {
        staff: [{ id: 1, goes_by: 'Taylor' }, { id: 2, goes_by: 'Morgan' }],
        available_staff: [{ id: 2, goes_by: 'Morgan', selection_label: 'Morgan (starts at 10:00 AM)', availability_notice: 'Morgan starts at 10:00 AM today.' }],
        clients: [], total_pages: 1
    };
    const { context, elements } = harness([response]);
    await context.loadClients();
    assert.equal(elements.staffSelect.value, '');
    assert.equal(elements.staffSelect.options.some(option => option.value === '1'), false);
    assert.equal(elements.staffFilter.options.some(option => option.value === '1'), true);
    assert.equal(elements.reservationStaff.options.some(option => option.value === '1'), true);
    elements.staffSelect.value = '2';
    context.updateStaffAvailabilityNotice();
    assert.equal(elements.staffAvailabilityNotice.textContent, 'Morgan starts at 10:00 AM today.');
    elements.staffSelect.value = 'any';
    context.updateStaffAvailabilityNotice();
    assert.equal(elements.staffAvailabilityNotice.textContent, '');
});

test('live refresh closes and reopens signup without hiding the queue', async () => {
    const clients = [{ id: 1, name: 'Waiting Client', staff_name: 'Taylor', wait_time: '01:00', no_in_party: 1 }];
    const base = { staff: [{ id: 1, goes_by: 'Taylor' }], clients, total_pages: 1 };
    const { context, elements } = harness([
        { ...base, waitlist: { can_join: false, message: 'The waitlist is closed for today.' } },
        { ...base, waitlist: { can_join: true, message: 'Open until 4:30 PM.' } }
    ]);
    await context.loadClients();
    assert.equal(elements.waitlistSignupFields.disabled, true);
    assert.equal(elements.waitlistHoursMessage.textContent, 'The waitlist is closed for today.');
    assert.match(elements.clientList.innerHTML, /Waiting Client/);
    await context.loadClients();
    assert.equal(elements.waitlistSignupFields.disabled, false);
    assert.equal(elements.waitlistHoursMessage.textContent, 'Open until 4:30 PM.');
});
