const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

async function harness({ verificationError = null, listError = null, updateError = null, cardSession = false } = {}) {
    function element() {
        return {
            children: [], listeners: {}, dataset: {}, disabled: false, textContent: '',
            append(child) { this.children.push(child); },
            replaceChildren() { this.children = []; },
            addEventListener(name, callback) { this.listeners[name] = callback; },
            querySelectorAll(tag) { return this.children.flatMap(child => [...(child.tag === tag ? [child] : []), ...child.querySelectorAll(tag)]); },
            focus() {}
        };
    }
    const elements = Object.fromEntries(['paymentMethods', 'savedCards', 'cardMessage', 'addPaymentCard'].map(id => [id, element()]));
    elements.paymentMethods.dataset.csrf = 'fixture-csrf';
    let cards = [
        { id: 'pm_first', brand: 'visa', last4: '4242', exp_month: 12, exp_year: 2035, is_default: true },
        { id: 'pm_second', brand: 'mastercard', last4: '4444', exp_month: 1, exp_year: 2035, is_default: false }
    ];
    const requests = [];
    const window = { location: { href: 'http://fixture.test/admin/profile' + (cardSession ? '?card_session=cs_test_fixture' : '') },
        history: { replaceState(_state, _title, url) { window.location.href = String(url); } } };
    const context = vm.createContext({
        URL, window,
        document: {
            getElementById: id => elements[id],
            createElement(tag) { return { ...element(), tag }; }
        },
        async fetch(_url, options) {
            const input = options.body ? JSON.parse(options.body) : null;
            requests.push(input);
            const error = input?.action === 'verify' ? verificationError : input?.action === 'default' ? updateError : input === null ? listError : null;
            if (error) return { ok: false, json: async () => ({ error }) };
            if (input?.action === 'default') cards = cards.map(card => ({ ...card, is_default: card.id === input.payment_method_id }));
            return { ok: true, json: async () => ({ success: true, cards, checkout_url: 'https://checkout.stripe.com/c/pay/fixture' }) };
        }
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'owner-cards.js'), 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    return { elements, requests, window };
}

test('owner profile shows multiple masked cards and updates the default using only payment-method IDs', async () => {
    const { elements, requests } = await harness();
    assert.equal(elements.savedCards.children.length, 2);
    assert.match(elements.savedCards.children[0].children[0].textContent, /ending in 4242.*Default/);
    assert.equal(elements.savedCards.children[0].children[1].children.length, 0);
    const makeDefault = elements.savedCards.querySelectorAll('button').find(button => button.textContent === 'Make default');
    await makeDefault.listeners.click();
    assert.deepEqual(requests[1], { action: 'default', payment_method_id: 'pm_second', csrf_token: 'fixture-csrf' });
    assert.match(elements.savedCards.children[1].children[0].textContent, /Default/);
    assert.match(elements.cardMessage.textContent, /updated/);
});

test('adding a card opens Stripe rather than accepting a card number in ClientQ', async () => {
    const { elements, requests, window } = await harness();
    await elements.addPaymentCard.listeners.click();
    assert.equal(window.location.href, 'https://checkout.stripe.com/c/pay/fixture');
    assert.deepEqual(requests[1], { action: 'add', csrf_token: 'fixture-csrf' });
});

test('failed setup verification remains visible but does not prevent inspecting saved cards', async () => {
    const { elements, requests, window } = await harness({ cardSession: true, verificationError: 'This card setup does not match your session.' });
    assert.equal(requests[0].action, 'verify');
    assert.equal(requests[1], null);
    assert.equal(elements.savedCards.children.length, 2);
    assert.equal(elements.addPaymentCard.disabled, false);
    assert.match(elements.cardMessage.textContent, /does not match/);
    assert.equal(new URL(window.location.href).searchParams.has('card_session'), false);
});

test('Stripe failures never show a successful card update', async () => {
    const initial = await harness({ listError: 'Card management unavailable.' });
    assert.equal(initial.elements.addPaymentCard.disabled, true);
    assert.match(initial.elements.cardMessage.textContent, /unavailable/);
    const update = await harness({ updateError: 'Default card update could not finish.' });
    await update.elements.savedCards.querySelectorAll('button')[0].listeners.click();
    assert.match(update.elements.cardMessage.textContent, /could not finish/);
    assert.match(update.elements.savedCards.children[0].children[0].textContent, /Default/);
});
