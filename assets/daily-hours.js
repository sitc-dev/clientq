document.querySelectorAll('[data-daily-hours]').forEach(form => {
    const date = form.elements.namedItem('date');
    const closed = form.elements.namedItem('is_closed');
    const opening = form.elements.namedItem('opening_time');
    const closing = form.elements.namedItem('closing_time');
    const fields = form.querySelector('[data-hours-fields]');
    const message = form.querySelector('[data-hours-message]');
    let revision = 0;
    let loadedDate = null;
    let saving = false;

    function showMessage(text, failed = false) {
        message.textContent = text;
        message.className = failed ? 'text-sm text-red-700' : 'text-sm text-gray-600';
    }

    function updateClosed() {
        opening.disabled = closed.checked;
        closing.disabled = closed.checked;
    }

    async function load() {
        const request = ++revision;
        const requestedDate = date.value;
        fields.disabled = true;
        loadedDate = null;
        if (!date.checkValidity()) {
            showMessage('Choose today or a future workday.', true);
            return;
        }
        showMessage('Loading daily hours...');
        try {
            const params = new URLSearchParams({ scope: form.dataset.dailyHours, date: requestedDate });
            const response = await fetch('/admin/api/daily-hours?' + params, { cache: 'no-store' });
            const data = await response.json();
            if (request !== revision) return;
            if (!response.ok || !data.success) throw new Error(data.error || 'Daily hours could not be loaded.');
            closed.checked = data.schedule.is_closed;
            opening.value = data.schedule.opening_time;
            closing.value = data.schedule.closing_time;
            loadedDate = requestedDate;
            fields.disabled = false;
            updateClosed();
            showMessage('Choose hours for this workday, then save.');
        } catch (error) {
            if (request !== revision) return;
            showMessage(error.message || 'Daily hours could not be loaded. Choose the date again to retry.', true);
        }
    }

    date.addEventListener('change', load);
    closed.addEventListener('change', updateClosed);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || fields.disabled || loadedDate !== date.value) return;
        if (!form.reportValidity()) return;
        saving = true;
        fields.disabled = true;
        date.disabled = true;
        showMessage('Saving daily hours...');
        try {
            const response = await fetch('/admin/api/daily-hours', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: form.dataset.csrf,
                    scope: form.dataset.dailyHours,
                    date: loadedDate,
                    is_closed: closed.checked,
                    opening_time: opening.value,
                    closing_time: closing.value
                })
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Daily hours could not be saved.');
            showMessage('Daily hours saved.');
        } catch (error) {
            showMessage(error.message || 'Daily hours could not be saved. Please try again.', true);
        } finally {
            saving = false;
            date.disabled = false;
            fields.disabled = false;
            updateClosed();
            message.focus();
        }
    });
    load();
});
