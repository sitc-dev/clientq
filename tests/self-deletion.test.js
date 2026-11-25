const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const page = fs.readFileSync(path.join(__dirname, '..', 'src', 'php', 'remove.php'), 'utf8');
const script = page.slice(page.lastIndexOf('<script>') + '<script>'.length, page.lastIndexOf('</script>'));

function harness(search) {
    const elements = {};
    for (const [, id] of page.matchAll(/id="([^"]+)"/g)) {
        const classes = new Set();
        elements[id] = {
            value: '',
            handlers: {},
            classList: {
                add: name => classes.add(name),
                remove: name => classes.delete(name)
            },
            addEventListener(event, handler) { this.handlers[event] = handler; },
            focus() { this.focused = true; }
        };
    }
    const requests = [];
    const context = vm.createContext({
        URLSearchParams,
        window: { location: { search } },
        document: { getElementById: id => elements[id] },
        console,
        async fetch(url, options) {
            requests.push({ url, body: JSON.parse(options.body) });
            return { ok: true, async json() { return { success: true }; } };
        }
    });
    vm.runInContext(script, context);
    return { context, elements, requests };
}

test('self-deletion keeps the record reference in the link, not in a visible form field', async () => {
    const { context, elements, requests } = harness('?signup=4173');
    assert.doesNotMatch(page, /id="signupId"|Sign-up reference/);
    elements.removePhone.value = '(202)555-0101';
    await context.removalRequest('request_code');
    assert.deepEqual(requests, [{
        url: '/api/remove-client',
        body: { action: 'request_code', signup_id: '4173', phone: '2025550101' }
    }]);
});

test('missing or invalid deletion links give an actionable error without sending a request', async () => {
    for (const search of ['', '?signup=0', '?signup=-1', '?signup=not-a-reference']) {
        const { context, requests } = harness(search);
        await assert.rejects(context.removalRequest('request_code'), /Open the deletion link from your confirmation text/);
        assert.equal(requests.length, 0);
    }
});

test('cancelling returns focus to the phone field and preserves the deletion link identity', async () => {
    const { context, elements, requests } = harness('?signup=4173');
    elements.removePhone.value = '2025550101';
    elements.cancelDeleteBtn.handlers.click();
    assert.equal(elements.removePhone.focused, true);
    assert.equal(elements.removePhone.readOnly, false);
    await context.removalRequest('request_code');
    assert.equal(requests[0].body.signup_id, '4173');
});
