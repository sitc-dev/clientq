const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function harness({ eligible = true, sdkError = false, approve = true, paymentError = null, stripeAvailable = false, paypalAvailable = true, provider = null, cardVerify = false, checkoutError = null } = {}) {
    function element() {
        const classes = new Set();
        const attributes = new Set();
        return {
            textContent: 'Subscribe with PayPal', disabled: false, listeners: {},
            classList: {
                add: name => classes.add(name), remove: name => classes.delete(name),
                contains: name => classes.has(name)
            },
            addEventListener(name, callback) { this.listeners[name] = callback; },
            toggleAttribute(name, enabled) { enabled ? attributes.add(name) : attributes.delete(name); },
            removeAttribute(name) { attributes.delete(name); },
            hasAttribute(name) { return attributes.has(name); },
            focus() {}
        };
    }
    const ids = ['confirmButton', 'verifyPaymentButton', 'paypalSubscribeButton', 'cardSubscribeButton', 'backToSignup', 'message', 'loginLink', 'reviewPanel', 'successPanel'];
    const elements = Object.fromEntries(ids.map(id => [id, element()]));
    elements.paypalSubscribeButton.toggleAttribute('hidden', true);
    elements.verifyPaymentButton.classList.add('hidden');
    elements.successPanel.classList.add('hidden');
    const requests = [];
    let callbacks;
    let startCount = 0;
    const context = vm.createContext({
        document: { getElementById: id => elements[id] },
        window: {
            location: { href: '' },
            paypal: {
                async createInstance() {
                    if (sdkError) throw new Error('SDK failed');
                    return {
                        async findEligibleMethods() { return { isEligible: () => eligible }; },
                        createPayPalSubscriptionPaymentSession(options) {
                            callbacks = options;
                            return {
                                async start(options, subscription) {
                                    startCount++;
                                    assert.equal(typeof subscription.then, 'function', 'Pass the promise without awaiting it before starting PayPal.');
                                    assert.equal(options.presentationMode, 'auto');
                                    assert.equal((await subscription).subscriptionId, 'I-TEST');
                                    if (approve) {
                                        await callbacks.onApprove({ subscriptionId: 'I-TEST' });
                                    } else {
                                        callbacks.onCancel({ subscriptionId: 'I-TEST' });
                                    }
                                }
                            };
                        }
                    };
                }
            }
        },
        async fetch(url, options) {
            requests.push({ url, options });
            if (checkoutError && url.startsWith('/api/create-')) {
                return { ok: false, json: async () => ({ error: checkoutError }) };
            }
            if (url === '/api/create-paypal-subscription') {
                return { ok: true, json: async () => ({ subscription_id: 'I-TEST', approval_url: 'https://www.sandbox.paypal.com/approve' }) };
            }
            if (url === '/api/create-stripe-subscription') {
                return { ok: true, json: async () => ({ checkout_url: 'https://checkout.stripe.com/c/pay/cs_test_fixture', verify_payment: cardVerify }) };
            }
            return {
                ok: !paymentError,
                json: async () => paymentError ? { error: paymentError } : { success: true, login_url: '/admin/login' }
            };
        }
    });
    const page = fs.readFileSync(path.join(__dirname, '..', 'src', 'php', 'signup-confirmation.php'), 'utf8');
    const script = page.match(/<script>\s*const csrfToken[\s\S]*?<\/script>/)[0]
        .replace(/^<script>|<\/script>$/g, '')
        .replace(/<\?= json_encode\(([\s\S]*?)\) \?>/g, (_, value) => {
            if (value === '$paypalAvailable') return JSON.stringify(paypalAvailable);
            if (value === '$stripeAvailable') return JSON.stringify(stripeAvailable);
            if (value === '$paymentStarted') return JSON.stringify(provider !== null);
            if (value === '$paymentProvider') return JSON.stringify(provider);
            return JSON.stringify('test-public-value');
        })
        .replace(/<\?php if[\s\S]*?<\?php endif; \?>/g, '');
    vm.runInContext(script, context);
    return { context, elements, requests, starts: () => startCount };
}

test('embedded PayPal approval verifies payment before showing account success', async () => {
    const { context, elements, requests, starts } = harness();
    await context.onPayPalWebSdkLoaded();
    assert.equal(elements.confirmButton.classList.contains('hidden'), true);
    assert.equal(elements.paypalSubscribeButton.hasAttribute('hidden'), false);
    await elements.paypalSubscribeButton.listeners.click();
    assert.equal(starts(), 1);
    assert.deepEqual(requests.map(request => request.url), ['/api/create-paypal-subscription', '/api/register-business']);
    assert.equal(JSON.parse(requests[1].options.body).csrf_token, 'test-public-value');
    assert.equal(elements.successPanel.classList.contains('hidden'), false);
    assert.equal(elements.reviewPanel.classList.contains('hidden'), true);
    assert.equal(elements.verifyPaymentButton.disabled, false);
});

test('cancelled PayPal checkout keeps verification available without creating an account', async () => {
    const { context, elements, requests } = harness({ approve: false });
    await context.onPayPalWebSdkLoaded();
    await elements.paypalSubscribeButton.listeners.click();
    assert.equal(requests.length, 1);
    assert.match(elements.message.textContent, /cancelled/);
    assert.equal(elements.successPanel.classList.contains('hidden'), true);
    assert.equal(elements.verifyPaymentButton.classList.contains('hidden'), false);
    assert.equal(elements.backToSignup.classList.contains('hidden'), true);
});

test('PayPal approval cannot show success when server payment verification fails', async () => {
    const { context, elements } = harness({ paymentError: 'Payment is still pending; do not pay twice.' });
    await context.onPayPalWebSdkLoaded();
    await elements.paypalSubscribeButton.listeners.click();
    assert.equal(elements.successPanel.classList.contains('hidden'), true);
    assert.match(elements.message.textContent, /still pending/);
    assert.equal(elements.verifyPaymentButton.disabled, false);
});

test('unavailable SDK and ineligible browsers retain explicit hosted checkout fallback', async () => {
    for (const settings of [{ sdkError: true }, { eligible: false }]) {
        const { context, elements, requests } = harness(settings);
        await context.onPayPalWebSdkLoaded();
        assert.equal(elements.confirmButton.classList.contains('hidden'), false);
        assert.equal(elements.paypalSubscribeButton.hasAttribute('hidden'), true);
        assert.match(elements.message.textContent, /Use the PayPal button/);
        assert.equal(requests.length, 0);
    }
});

test('card checkout works without PayPal and does not provision before verified payment', async () => {
    const { context, elements, requests } = harness({ stripeAvailable: true, paypalAvailable: false });
    await elements.cardSubscribeButton.listeners.click();
    assert.deepEqual(requests.map(request => request.url), ['/api/create-stripe-subscription']);
    assert.equal(context.window.location.href, 'https://checkout.stripe.com/c/pay/cs_test_fixture');
    assert.equal(elements.successPanel.classList.contains('hidden'), true);
    assert.equal(elements.verifyPaymentButton.classList.contains('hidden'), false);
    assert.equal(elements.confirmButton.disabled, true);
    assert.equal(elements.verifyPaymentButton.disabled, false);
});

test('resuming completed card checkout verifies server-side and shows the tenant links', async () => {
    const { elements, requests } = harness({ stripeAvailable: true, provider: 'stripe', cardVerify: true });
    await elements.cardSubscribeButton.listeners.click();
    assert.deepEqual(requests.map(request => request.url), ['/api/create-stripe-subscription', '/api/register-business']);
    assert.equal(elements.successPanel.classList.contains('hidden'), false);
    assert.equal(elements.loginLink.href, '/admin/login');
});

test('a started payment cannot switch providers and risk a second subscription', async () => {
    const paypal = harness({ stripeAvailable: true, provider: 'paypal' });
    await paypal.elements.cardSubscribeButton.listeners.click();
    assert.equal(paypal.requests.length, 0);
    const stripe = harness({ stripeAvailable: true, provider: 'stripe' });
    await stripe.context.onPayPalWebSdkLoaded();
    await stripe.elements.paypalSubscribeButton.listeners.click();
    assert.equal(stripe.requests.length, 0);
});

test('card checkout and payment-verification failures never show account success', async () => {
    for (const settings of [{ checkoutError: 'Card checkout unavailable.' }, { cardVerify: true, paymentError: 'Card payment is pending.' }]) {
        const { elements } = harness({ stripeAvailable: true, ...settings });
        await elements.cardSubscribeButton.listeners.click();
        assert.equal(elements.successPanel.classList.contains('hidden'), true);
        assert.match(elements.message.textContent, /unavailable|pending/);
        assert.equal(elements.cardSubscribeButton.disabled, false);
    }
});
