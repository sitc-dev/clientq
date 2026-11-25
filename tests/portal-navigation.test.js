const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const layout = fs.readFileSync(path.join(__dirname, '..', 'admin', 'layout.php'), 'utf8');
const script = layout.match(/<script id="portalNavigationScript">([\s\S]*?)<\/script>/)[1];

function harness() {
    function element(id, attributes = {}) {
        const classes = new Set(id === 'portalNavigation' ? ['hidden'] : []);
        return {
            attributes,
            listeners: {},
            focused: false,
            classList: {
                contains: name => classes.has(name),
                toggle(name, force) {
                    if (force) classes.add(name);
                    else classes.delete(name);
                }
            },
            setAttribute(name, value) { this.attributes[name] = value; },
            getAttribute(name) { return this.attributes[name]; },
            addEventListener(name, callback) { this.listeners[name] = callback; },
            contains(target) { return target === this || target.within === id; },
            focus() { this.focused = true; }
        };
    }

    const toggle = element('portalMenuToggle', { 'aria-expanded': 'false' });
    const navigation = element('portalNavigation');
    const icon = element('portalMenuIcon');
    const elements = { portalMenuToggle: toggle, portalNavigation: navigation, portalMenuIcon: icon };
    const documentEvents = {};
    const mediaEvents = {};
    vm.runInNewContext(script, {
        document: {
            getElementById: id => elements[id],
            addEventListener(name, callback) { documentEvents[name] = callback; }
        },
        window: {
            matchMedia() {
                return { addEventListener(name, callback) { mediaEvents[name] = callback; } };
            }
        }
    });
    return { toggle, navigation, icon, documentEvents, mediaEvents };
}

test('hamburger toggles the navigation and accessible state, and selecting a link closes it', () => {
    const { toggle, navigation } = harness();
    toggle.listeners.click();
    assert.equal(navigation.classList.contains('hidden'), false);
    assert.equal(navigation.classList.contains('flex'), true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(toggle.getAttribute('aria-label'), 'Close navigation menu');
    toggle.listeners.click();
    assert.equal(navigation.classList.contains('hidden'), true);
    assert.equal(navigation.classList.contains('flex'), false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(toggle.getAttribute('aria-label'), 'Open navigation menu');
    toggle.listeners.click();
    navigation.listeners.click({ target: { closest: () => ({}) } });
    assert.equal(navigation.classList.contains('hidden'), true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
});

test('Escape restores focus while outside clicks dismiss without stealing focus', () => {
    const { toggle, navigation, documentEvents } = harness();
    toggle.listeners.click();
    documentEvents.click({ target: { within: 'portalNavigation' } });
    assert.equal(navigation.classList.contains('hidden'), false);
    documentEvents.keydown({ key: 'Tab' });
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    documentEvents.keydown({ key: 'Escape' });
    assert.equal(navigation.classList.contains('hidden'), true);
    assert.equal(toggle.focused, true);
    toggle.focused = false;
    toggle.listeners.click();
    documentEvents.click({ target: {} });
    assert.equal(navigation.classList.contains('hidden'), true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(toggle.focused, false);
});

test('crossing the desktop breakpoint clears an open mobile menu', () => {
    const { toggle, navigation, mediaEvents } = harness();
    toggle.listeners.click();
    mediaEvents.change({ matches: true });
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(navigation.classList.contains('hidden'), true);
    mediaEvents.change({ matches: false });
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(navigation.classList.contains('hidden'), true);
});
