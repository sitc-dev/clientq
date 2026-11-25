const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const root = path.join(__dirname, '..');
const phoneScript = fs.readFileSync(path.join(root, 'assets/phone-input.js'), 'utf8');

test('phone entry strips non-digits, caps ten digits, and formats complete numbers', () => {
    const context = vm.createContext({});
    vm.runInContext(phoneScript, context);
    assert.equal(context.phoneDigits('abc(202)555-0199 ext 123'), '2025550199');
    assert.equal(context.formatPhone('2025550199'), '(202)555-0199');
    assert.equal(context.formatPhone('(202)555-0199'), '(202)555-0199');
    assert.equal(context.formatPhone('20255'), '20255');
    assert.equal(context.formatPhone(''), '');
});

test('staff profile formats saved phones and submits structured names and ten digits', async () => {
    const elements = {};
    for (const id of ['phone', 'first_name', 'last_name', 'address', 'city', 'state', 'zip', 'email', 'goes_by',
        'position', 'experience', 'additional_info', 'profileForm', 'passwordForm']) {
        elements[id] = { value: '', handlers: {}, addEventListener(event, handler) { this.handlers[event] = handler; } };
    }
    elements.phone.value = '2025550199';
    elements.first_name.value = 'Mary Jane';
    elements.last_name.value = 'Smith';
    elements.email.value = 'staff@example.com';
    elements.zip.value = '02139-1234';
    let payload;
    const context = vm.createContext({
        document: { getElementById: id => elements[id] ||= { value: '' } },
        setTimeout() {},
        async fetch(url, options) {
            assert.equal(url, 'api/update-profile');
            payload = JSON.parse(options.body);
            return { async json() { return { success: true }; } };
        },
    });
    vm.runInContext(phoneScript, context);
    const profile = fs.readFileSync(path.join(root, 'admin/profile.php'), 'utf8');
    const anchor = profile.indexOf('const phoneInput');
    const start = profile.lastIndexOf('<script>', anchor) + '<script>'.length;
    vm.runInContext(profile.slice(start, profile.indexOf('</script>', start)), context);
    assert.equal(elements.phone.value, '(202)555-0199');
    elements.phone.value = '2025550199abc999';
    elements.phone.handlers.input.call(elements.phone);
    assert.equal(elements.phone.value, '2025550199');
    elements.phone.handlers.blur.call(elements.phone);
    assert.equal(elements.phone.value, '(202)555-0199');
    await elements.profileForm.handlers.submit({ preventDefault() {} });
    assert.equal(payload.phone, '2025550199');
    assert.equal(payload.first_name, 'Mary Jane');
    assert.equal(payload.last_name, 'Smith');
    assert.equal(payload.email, 'staff@example.com');
    assert.equal(payload.zip, '02139-1234');
    assert.equal(Object.hasOwn(payload, 'name'), false);
});
