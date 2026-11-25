const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function harness({ brokenLogo = false, missingQr = false } = {}) {
    const calls = [];
    const warnings = [];
    let options;
    const context = {
        fillRect(...args) { calls.push(['fillRect', ...args]); },
        drawImage(...args) { calls.push(['drawImage', ...args]); },
        fillText(...args) { calls.push(['fillText', ...args]); },
        measureText(text) {
            return { width: text.length * 16, actualBoundingBoxAscent: 32, actualBoundingBoxDescent: 8 };
        }
    };
    const document = {
        createElement(tag) {
            if (tag === 'div') return { querySelector: () => ({ sourceQr: true }) };
            return { width: 0, height: 0, getContext: () => context, setAttribute() {} };
        }
    };
    function QRCode(element, input) { options = input; }
    QRCode.CorrectLevel = { H: 'high' };
    class Image {
        naturalWidth = 400;
        naturalHeight = 200;
        set src(value) {
            this.url = value;
            if (value) queueMicrotask(() => brokenLogo ? this.onerror() : this.onload());
        }
    }
    const window = { QRCode: missingQr ? undefined : QRCode };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'tenant-qr.js'), 'utf8'), {
        window, QRCode, Image, document, setTimeout, clearTimeout,
        console: { warn: (...args) => warnings.push(args) }
    });
    const render = input => window.ClientQTenantQr.render({
        url: 'https://demo.clientq.com/#sign-in', name: 'Demo Shop', color: '#123456', ...input
    });
    return { render, calls, warnings, options: () => options };
}

test('QR without a logo has single-line frame labels 6px from the dark code at preview size', async () => {
    const { render, calls, options } = harness();
    const { canvas, logoFailed } = await render({ logo: '' });
    const text = calls.filter(call => call[0] === 'fillText').map(call => call[1]);
    assert.deepEqual(text, ['Demo Shop', 'Powered by ClientQ']);
    assert.equal(logoFailed, false);
    assert.equal(options().text, 'https://demo.clientq.com/#sign-in');
    assert.equal(options().correctLevel, 'high');
    const images = calls.filter(call => call[0] === 'drawImage');
    const labels = calls.filter(call => call[0] === 'fillText');
    assert.equal(images.length, 1);
    assert.equal(calls.filter(call => call[0] === 'fillRect').length, 1);
    const previewScale = 320 / canvas.width;
    assert.ok(Math.abs((images[0][3] - labels[0][3] - 8) * previewScale - 6) < 0.001);
    assert.ok(Math.abs((labels[1][3] - 32 - images[0][3] - options().height) * previewScale - 6) < 0.001);
    assert.ok(labels[0][4] < canvas.width);
});

test('QR with a logo centers only the fitted logo and places attribution below the frame', async () => {
    const { render, calls, options } = harness();
    const { canvas, logoFailed } = await render({ logo: '/uploads/tenant/logo.png' });
    assert.equal(logoFailed, false);
    const images = calls.filter(call => call[0] === 'drawImage');
    assert.equal(images.length, 2);
    assert.equal(images[1][1].url, '/uploads/tenant/logo.png');
    assert.equal(images[1][4] / images[1][5], 2);
    assert.deepEqual(calls.filter(call => call[0] === 'fillText').map(call => call[1]), ['Powered by ClientQ']);
    assert.equal(images[1][2] + images[1][4] / 2, images[0][2] + options().width / 2);
    assert.equal(images[1][3] + images[1][5] / 2, images[0][3] + options().height / 2);
    const footer = calls.find(call => call[0] === 'fillText');
    assert.ok(Math.abs((footer[3] - 32 - images[0][3] - options().height) * 320 / canvas.width - 6) < 0.001);
});

test('an unavailable logo explicitly reports the name fallback', async () => {
    const { render, calls, warnings } = harness({ brokenLogo: true });
    const { logoFailed } = await render({ logo: '/missing.png' });
    assert.equal(logoFailed, true);
    assert.equal(warnings.length, 1);
    assert.deepEqual(calls.filter(call => call[0] === 'fillText').map(call => call[1]), ['Demo Shop', 'Powered by ClientQ']);
    assert.equal(calls.filter(call => call[0] === 'fillRect').length, 1);
});

test('long business names remain one complete line with a width limit and unavailable QR libraries fail explicitly', async () => {
    const { render, calls } = harness();
    const name = 'An extraordinarily long business name '.repeat(5);
    await render({ name });
    const labels = calls.filter(call => call[0] === 'fillText');
    assert.equal(labels.length, 2);
    assert.equal(labels[0][1], name);
    assert.equal(typeof labels[0][4], 'number');
    assert.equal(labels[1][1], 'Powered by ClientQ');
    await assert.rejects(harness({ missingQr: true }).render({}), /temporarily unavailable/);
});
