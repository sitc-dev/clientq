const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const script = fs.readFileSync(path.join(__dirname, '..', 'assets', 'daily-hours.js'), 'utf8');

function harness() {
    const controls = {};
    for (const name of ['date', 'is_closed', 'opening_time', 'closing_time']) {
        controls[name] = {
            value: name === 'date' ? '2026-11-19' : '',
            checked: false, disabled: false, handlers: {},
            addEventListener(event, handler) { this.handlers[event] = handler; },
            checkValidity() { return true; }
        };
    }
    const fields = { disabled: true };
    const message = { textContent: '', focus() {} };
    const form = {
        dataset: { dailyHours: 'staff', csrf: 'fixture-token' },
        elements: { namedItem: name => controls[name] },
        handlers: {},
        querySelector: selector => selector === '[data-hours-fields]' ? fields : message,
        addEventListener(event, handler) { this.handlers[event] = handler; },
        reportValidity() { return true; }
    };
    const requests = [];
    vm.runInNewContext(script, {
        document: { querySelectorAll: () => [form] },
        URLSearchParams,
        fetch(url, options) {
            return new Promise(resolve => requests.push({
                url, options,
                respond(data, ok = true) { resolve({ ok, async json() { return data; } }); }
            }));
        }
    });
    const flush = () => new Promise(resolve => setImmediate(resolve));
    const schedule = (opening = '08:00', closing = '16:30') => ({
        success: true, schedule: { is_closed: false, opening_time: opening, closing_time: closing }
    });
    return { controls, fields, message, form, requests, flush, schedule };
}

test('switching workdays ignores stale hours and prevents saving the previous date', async () => {
    const h = harness();
    h.controls.date.value = '2026-11-20';
    const changed = h.controls.date.handlers.change();
    h.requests[1].respond(h.schedule('10:00', '18:00'));
    await changed;
    h.requests[0].respond(h.schedule('09:00', '15:00'));
    await h.flush();
    assert.equal(h.controls.opening_time.value, '10:00');
    assert.equal(h.controls.closing_time.value, '18:00');
    assert.equal(h.fields.disabled, false);
    const saved = h.form.handlers.submit({ preventDefault() {} });
    assert.equal(h.controls.date.disabled, true);
    assert.deepEqual(JSON.parse(h.requests[2].options.body), {
        csrf_token: 'fixture-token', scope: 'staff', date: '2026-11-20',
        is_closed: false, opening_time: '10:00', closing_time: '18:00'
    });
    h.requests[2].respond({ success: true });
    await saved;
    assert.equal(h.controls.date.disabled, false);
    assert.equal(h.message.textContent, 'Daily hours saved.');
});

test('loading errors keep hours disabled rather than saving made-up defaults', async () => {
    const h = harness();
    h.requests[0].respond({ error: 'Not authenticated.' }, false);
    await h.flush();
    assert.equal(h.fields.disabled, true);
    assert.equal(h.message.textContent, 'Not authenticated.');
    await h.form.handlers.submit({ preventDefault() {} });
    assert.equal(h.requests.length, 1);
});

test('off-day saves retain hours and surface server failures without reporting success', async () => {
    const h = harness();
    h.requests[0].respond(h.schedule());
    await h.flush();
    h.controls.is_closed.checked = true;
    h.controls.is_closed.handlers.change();
    assert.equal(h.controls.opening_time.disabled, true);
    const saved = h.form.handlers.submit({ preventDefault() {} });
    const body = JSON.parse(h.requests[1].options.body);
    assert.equal(body.is_closed, true);
    assert.equal(body.opening_time, '08:00');
    assert.equal(body.closing_time, '16:30');
    h.requests[1].respond({ error: 'Daily hours could not be saved.' }, false);
    await saved;
    assert.equal(h.message.textContent, 'Daily hours could not be saved.');
    assert.equal(h.fields.disabled, false);
});
