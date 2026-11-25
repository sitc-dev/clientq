(() => {
    const section = document.getElementById('paymentMethods');
    if (!section) return;
    const list = document.getElementById('savedCards');
    const message = document.getElementById('cardMessage');
    const addButton = document.getElementById('addPaymentCard');
    let busy = false;
    let loaded = false;

    function showMessage(text, success = false) {
        message.textContent = text;
        message.className = success ? 'text-sm text-green-700' : 'text-sm text-red-700';
        message.focus();
    }

    function updateControls() {
        addButton.disabled = busy || !loaded;
        list.querySelectorAll('button').forEach(button => {
            button.disabled = busy;
        });
    }

    async function request(input = null) {
        const options = input === null ? {} : {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...input, csrf_token: section.dataset.csrf })
        };
        const response = await fetch('api/payment-methods', options);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Card management is unavailable.');
        return data;
    }

    async function loadCards() {
        const data = await request();
        list.replaceChildren();
        if (data.cards.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-sm text-gray-500';
            empty.textContent = 'No saved cards.';
            list.append(empty);
        }
        data.cards.forEach(card => {
            const row = document.createElement('div');
            row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3';
            const details = document.createElement('p');
            details.className = 'text-sm';
            details.textContent = `${card.brand.toUpperCase()} ending in ${card.last4} | Expires ${String(card.exp_month).padStart(2, '0')}/${card.exp_year}${card.is_default ? ' | Default' : ''}`;
            row.append(details);
            const actions = document.createElement('div');
            actions.className = 'flex gap-3';
            if (!card.is_default) {
                for (const [action, label] of [['default', 'Make default'], ['remove', 'Remove']]) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'text-sm font-semibold text-accent underline';
                    button.textContent = label;
                    button.addEventListener('click', () => runAction(async () => {
                        await request({ action, payment_method_id: card.id });
                        await loadCards();
                        showMessage(action === 'default' ? 'Default subscription card updated.' : 'Card removed.', true);
                    }));
                    actions.append(button);
                }
            }
            row.append(actions);
            list.append(row);
        });
        loaded = true;
    }

    async function runAction(action) {
        if (busy) return;
        busy = true;
        updateControls();
        message.textContent = '';
        try {
            await action();
        } catch (error) {
            showMessage(error.message || 'Card management could not finish. Please try again.');
        } finally {
            busy = false;
            updateControls();
        }
    }

    addButton.addEventListener('click', () => runAction(async () => {
        const data = await request({ action: 'add' });
        window.location.href = data.checkout_url;
    }));

    runAction(async () => {
        const url = new URL(window.location.href);
        const sessionId = url.searchParams.get('card_session');
        if (sessionId) {
            try {
                await request({ action: 'verify', session_id: sessionId });
                showMessage('Card saved. Choose Make default to use it for your subscription.', true);
            } catch (error) {
                showMessage(error.message || 'Card setup could not be verified. Check the saved cards below.');
            }
            url.searchParams.delete('card_session');
            window.history.replaceState(null, '', url);
        } else if (url.searchParams.get('card') === 'cancelled') {
            showMessage('Card entry was cancelled. Your existing default card is unchanged.');
        }
        await loadCards();
    });
})();
