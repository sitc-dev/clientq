(() => {
    const form = document.getElementById('reservationForm');
    const staff = document.getElementById('reservationStaff');
    const date = document.getElementById('reservationDate');
    const party = document.getElementById('reservationParty');
    const slots = document.getElementById('reservationSlots');
    const availability = document.getElementById('reservationAvailability');
    const error = document.getElementById('reservationError');
    const confirmation = document.getElementById('reservationConfirmation');
    const submit = document.getElementById('bookReservation');
    const reset = document.getElementById('resetReservation');
    const defaultDateLimits = { min: date.min, max: date.max };
    let request = 0;
    let booking = false;
    const tabs = [...document.querySelectorAll('.reservation-mode-tab')];
    function activate(tab) {
        tabs.forEach(button => {
            const selected = button === tab;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            button.classList.toggle('border-accent', selected);
            button.classList.toggle('text-gray-900', selected);
            button.classList.toggle('border-transparent', !selected);
            button.classList.toggle('text-gray-600', !selected);
            document.getElementById(button.getAttribute('aria-controls')).classList.toggle('hidden', !selected);
        });
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', event => {
            const directions = { ArrowRight: (index + 1) % 2, ArrowLeft: (index + 1) % 2, Home: 0, End: 1 };
            if (!(event.key in directions)) return;
            event.preventDefault();
            const target = tabs[directions[event.key]];
            activate(target);
            target.focus();
        });
    });
    function showError(message) {
        error.textContent = message;
        error.classList.remove('hidden');
    }
    function readableDate(value) {
        return new Intl.DateTimeFormat('en-US', { dateStyle: 'long', timeZone: 'America/New_York' })
            .format(new Date(value + 'T12:00:00-05:00'));
    }
    async function loadSlots(resetDate = false) {
        const current = ++request;
        const selected = slots.querySelector('input:checked')?.value;
        slots.replaceChildren();
        submit.disabled = true;
        error.classList.add('hidden');
        if (!staff.value) {
            availability.textContent = 'No reservations available. Select a staff member to check available times.';
            return;
        }
        availability.textContent = 'Loading available reservations...';
        const params = new URLSearchParams({ staff_id: staff.value, party_size: party.value });
        if (!resetDate) params.set('date', date.value);
        try {
            const response = await fetch('/api/reservation-slots?' + params, { cache: 'no-store' });
            const data = await response.json();
            if (current !== request) return;
            if (!response.ok || !data.success || !Array.isArray(data.slots)) {
                throw new Error(data.error || 'Available reservations could not be loaded.');
            }
            date.min = data.min_date;
            date.max = data.max_date;
            date.value = data.date;
            availability.textContent = data.slots.length ? 'Choose an available time (Eastern).' : `No reservations available on ${readableDate(data.date)}`;
            for (const slot of data.slots) {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 rounded-lg border p-3 text-sm cursor-pointer';
                const input = document.createElement('input');
                input.type = 'radio';
                input.name = 'reservation_time';
                input.value = slot.value;
                input.required = true;
                input.checked = !resetDate && slot.value === selected;
                const text = document.createElement('span');
                text.textContent = slot.label;
                label.append(input, text);
                slots.append(label);
            }
            submit.disabled = booking || data.slots.length === 0;
        } catch (failure) {
            if (current !== request) return;
            console.error('Reservation availability failed:', failure);
            availability.textContent = 'Availability could not be loaded.';
            showError(failure.message);
        }
    }
    staff.addEventListener('change', () => loadSlots(true));
    date.addEventListener('change', () => loadSlots());
    party.addEventListener('change', () => loadSlots());
    const phone = document.getElementById('reservationPhone');
    phone.addEventListener('input', () => { phone.value = phoneDigits(phone.value); });
    phone.addEventListener('blur', () => { phone.value = formatPhone(phone.value); });
    reset.addEventListener('click', () => {
        if (booking) return;
        form.reset();
        date.min = defaultDateLimits.min;
        date.max = defaultDateLimits.max;
        confirmation.classList.add('hidden');
        loadSlots();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (booking) return;
        const time = slots.querySelector('input:checked')?.value;
        if (!time || phoneDigits(phone.value).length !== 10) {
            showError('Choose an available time and enter a valid 10-digit phone number.');
            return;
        }
        error.classList.add('hidden');
        confirmation.classList.add('hidden');
        booking = true;
        submit.disabled = true;
        reset.disabled = true;
        submit.textContent = 'Reserving...';
        const details = {
            csrf_token: form.dataset.csrf, staff_id: staff.value, date: date.value, time,
            party_size: party.value, name: document.getElementById('reservationName').value.trim(), phone: phoneDigits(phone.value)
        };
        try {
            const response = await fetch('/api/book-reservation', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(details)
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                if (response.status === 409) await loadSlots();
                throw new Error(data.error || 'The reservation could not be saved.');
            }
            confirmation.textContent = `Reservation confirmed for ${readableDate(details.date)} at ${timeLabel(details.time)}, for ${details.party_size} person(s). Confirmation code: ${data.reservation.confirmation_code}. Sign in to the waitlist when you arrive.`;
            confirmation.classList.remove('hidden');
            await loadSlots();
        } catch (failure) {
            console.error('Reservation booking failed:', failure);
            showError(failure.message);
        } finally {
            booking = false;
            reset.disabled = false;
            submit.textContent = 'Reserve Time';
            submit.disabled = slots.children.length === 0;
        }
    });
    function timeLabel(value) {
        const [hours, minutes] = value.split(':').map(Number);
        return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${hours >= 12 ? 'PM' : 'AM'} Eastern`;
    }
    setInterval(() => {
        if (!document.hidden && !booking && !document.getElementById('sign-in').classList.contains('hidden')
            && !document.getElementById('reservation-schedule').classList.contains('hidden') && staff.value) loadSlots();
    }, 30000);
})();
