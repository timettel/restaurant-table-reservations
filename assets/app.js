'use strict';

const state = {
    config: null,
    selectedDate: null,
    selectedDateLabel: '',
    selectedTime: null,
    selectedTable: null,
    occupied: new Set(),
    guestCount: 2,
    captcha: null,
    captchaRequest: 0,
    submitting: false,
};

const elements = {};

document.addEventListener('DOMContentLoaded', () => {
    Object.assign(elements, {
        app: document.querySelector('#booking-app'),
        dateList: document.querySelector('#date-list'),
        timeList: document.querySelector('#time-list'),
        tablesLayer: document.querySelector('#tables-layer'),
        selectionStatus: document.querySelector('#selection-status'),
        globalError: document.querySelector('#global-error'),
        dialog: document.querySelector('#reservation-dialog'),
        form: document.querySelector('#reservation-form'),
        success: document.querySelector('#success-state'),
        dialogTitle: document.querySelector('#dialog-title'),
        dialogSummary: document.querySelector('#dialog-summary'),
        dialogError: document.querySelector('#dialog-error'),
        guestCount: document.querySelector('#guest-count'),
        capacityNote: document.querySelector('#capacity-note'),
        submit: document.querySelector('#submit-reservation'),
        successMessage: document.querySelector('#success-message'),
        captchaQuestion: document.querySelector('#captcha-question'),
        captchaAnswer: document.querySelector('#captcha-answer'),
    });

    document.querySelector('#dialog-close').addEventListener('click', closeDialog);
    document.querySelector('#dialog-cancel').addEventListener('click', closeDialog);
    document.querySelector('#success-close').addEventListener('click', closeSuccess);
    document.querySelector('#guest-minus').addEventListener('click', () => changeGuestCount(-1));
    document.querySelector('#guest-plus').addEventListener('click', () => changeGuestCount(1));
    document.querySelector('#captcha-refresh').addEventListener('click', loadCaptcha);
    elements.form.addEventListener('submit', submitReservation);
    elements.form.addEventListener('input', () => {
        elements.dialogError.hidden = true;
    });
    elements.dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeDialog();
    });

    initialise();
});

async function initialise() {
    try {
        const response = await api('api.php?action=bootstrap');
        state.config = response.data;
        renderDates();
        renderTables();

        for (const day of state.config.dates) {
            if (!day.closed) {
                const selected = await selectDate(day.date, false);
                if (selected) break;
            }
        }
        elements.app.setAttribute('aria-busy', 'false');
    } catch (error) {
        showGlobalError(error.message);
    }
}

function renderDates() {
    elements.dateList.replaceChildren();
    state.config.dates.forEach((day) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'date-button';
        button.disabled = day.closed;
        button.dataset.date = day.date;
        button.setAttribute('aria-pressed', String(day.date === state.selectedDate));

        const icon = document.createElement('span');
        icon.className = 'date-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg>';
        const text = document.createElement('span');
        text.className = 'date-copy';
        text.textContent = day.label;
        const meta = document.createElement('small');
        meta.textContent = day.closed ? day.reason : 'Reservierbar';
        text.append(meta);
        const arrow = document.createElement('span');
        arrow.className = 'date-arrow';
        arrow.setAttribute('aria-hidden', 'true');
        arrow.textContent = '›';

        button.append(icon, text, arrow);
        button.addEventListener('click', () => selectDate(day.date, true));
        elements.dateList.append(button);
    });
}

async function selectDate(date, announceEmpty = true) {
    try {
        const response = await api(`api.php?action=slots&date=${encodeURIComponent(date)}`);
        const day = state.config.dates.find((item) => item.date === date);
        state.selectedDate = date;
        state.selectedDateLabel = day?.long_label || date;
        state.selectedTime = null;
        state.selectedTable = null;
        state.occupied = new Set();
        renderDates();
        renderSlots(response.data.slots);
        renderTables();

        if (response.data.slots.length > 0) {
            await selectTime(response.data.slots[0]);
            return true;
        }
        if (announceEmpty) {
            elements.selectionStatus.textContent = 'Für diesen Tag sind keine Startzeiten mehr verfügbar.';
        }
        return false;
    } catch (error) {
        showGlobalError(error.message);
        return false;
    }
}

function renderSlots(slots) {
    elements.timeList.replaceChildren();
    if (slots.length === 0) {
        const empty = document.createElement('span');
        empty.className = 'empty-slots';
        empty.textContent = 'Keine Zeiten verfügbar';
        elements.timeList.append(empty);
        return;
    }

    slots.forEach((time) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'time-button';
        button.textContent = time;
        button.dataset.time = time;
        button.setAttribute('aria-pressed', String(time === state.selectedTime));
        button.addEventListener('click', () => selectTime(time));
        elements.timeList.append(button);
    });
}

async function selectTime(time) {
    state.selectedTime = time;
    state.selectedTable = null;
    document.querySelectorAll('.time-button').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.time === time));
    });

    try {
        const response = await api(`api.php?action=availability&date=${encodeURIComponent(state.selectedDate)}&time=${encodeURIComponent(time)}`);
        state.occupied = new Set(response.data.occupied_table_ids.map(Number));
        renderTables();
        elements.selectionStatus.textContent = `${state.selectedDateLabel} · ${time}–${response.data.end_time} Uhr · Bitte einen freien Tisch wählen.`;
    } catch (error) {
        showGlobalError(error.message);
    }
}

function renderTables() {
    if (!state.config) return;
    elements.tablesLayer.replaceChildren();
    state.config.tables.forEach((table) => {
        const occupied = state.occupied.has(Number(table.id));
        const selected = state.selectedTable?.id === table.id;
        const node = document.createElement('button');
        node.type = 'button';
        node.className = `table-node table-node--${table.shape}`;
        if (occupied) node.classList.add('is-occupied');
        if (selected) node.classList.add('is-selected');
        node.style.setProperty('--x', `${table.x}%`);
        node.style.setProperty('--y', `${table.y}%`);
        node.style.setProperty('--w', `${table.w}%`);
        node.style.setProperty('--h', `${table.h}%`);
        node.disabled = occupied || !state.selectedDate || !state.selectedTime;
        node.setAttribute('aria-label', occupied
            ? `Tisch ${table.label}, ${table.capacity} Plätze, belegt`
            : `Tisch ${table.label}, ${table.capacity} Plätze, frei`);

        const surface = document.createElement('span');
        surface.className = 'table-surface';
        surface.innerHTML = `<strong>${escapeHtml(table.label)}</strong><small>${table.capacity} Plätze</small>`;
        node.append(surface);
        chairPositions(table.capacity, table.shape).forEach((position) => {
            const chair = document.createElement('i');
            chair.className = `chair chair--${position}`;
            chair.setAttribute('aria-hidden', 'true');
            node.append(chair);
        });
        node.addEventListener('click', () => openReservation(table));
        elements.tablesLayer.append(node);
    });
}

function chairPositions(capacity, shape) {
    if (capacity === 2) return ['left-middle', 'right-middle'];
    if (capacity === 6) return ['left-top', 'left-middle', 'left-bottom', 'right-top', 'right-middle', 'right-bottom'];
    return shape === 'round'
        ? ['top-middle', 'right-middle', 'bottom-middle', 'left-middle']
        : ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
}

function openReservation(table) {
    state.selectedTable = table;
    state.guestCount = Math.min(2, table.capacity);
    renderTables();
    elements.form.reset();
    elements.form.hidden = false;
    elements.success.hidden = true;
    elements.dialogError.hidden = true;
    elements.dialogTitle.textContent = `Tisch ${table.label} reservieren`;
    const end = addMinutes(state.selectedTime, state.config.duration_minutes);
    elements.dialogSummary.textContent = `${state.selectedDateLabel} · ${state.selectedTime}–${end} Uhr`;
    elements.capacityNote.textContent = `An diesem Tisch sind bis zu ${table.capacity} Gäste möglich.`;
    updateGuestCount();
    elements.dialog.showModal();
    loadCaptcha();
    window.setTimeout(() => elements.form.elements.name.focus(), 50);
}

function closeDialog() {
    if (state.submitting) return;
    elements.dialog.close();
}

function closeSuccess() {
    elements.dialog.close();
    selectTime(state.selectedTime);
}

function changeGuestCount(delta) {
    if (!state.selectedTable) return;
    state.guestCount = Math.max(1, Math.min(state.selectedTable.capacity, state.guestCount + delta));
    updateGuestCount();
}

function updateGuestCount() {
    elements.guestCount.textContent = String(state.guestCount);
    document.querySelector('#guest-minus').disabled = state.guestCount <= 1;
    document.querySelector('#guest-plus').disabled = state.guestCount >= state.selectedTable.capacity;
}

async function submitReservation(event) {
    event.preventDefault();
    const formData = new FormData(elements.form);
    const phone = String(formData.get('phone') || '').trim();
    const email = String(formData.get('email') || '').trim();
    if (!elements.form.reportValidity()) return;
    if (!state.captcha) { showDialogError('Bitte laden Sie eine neue Sicherheitsfrage.'); return; }

    state.submitting = true;
    elements.submit.disabled = true;
    elements.submit.textContent = 'Wird reserviert …';
    try {
        const response = await api('api.php?action=reserve', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                booking_token: state.config.booking_token,
                captcha_token: state.captcha.token,
                captcha_answer: String(formData.get('captcha_answer') || '').trim(),
                date: state.selectedDate,
                time: state.selectedTime,
                table_id: state.selectedTable.id,
                party_size: state.guestCount,
                name: String(formData.get('name') || ''),
                phone,
                email,
                website: String(formData.get('website') || ''),
            }),
        });
        elements.form.hidden = true;
        elements.success.hidden = false;
        elements.successMessage.textContent = `Tisch ${response.data.table_label} ist am ${response.data.date_label} von ${response.data.start_time} bis ${response.data.end_time} Uhr für Sie reserviert.`;
        document.querySelector('#success-close').focus();
    } catch (error) {
        showDialogError(error.message);
        await loadCaptcha();
        if (error.status === 409) {
            elements.dialog.close();
            await selectTime(state.selectedTime);
        }
    } finally {
        state.submitting = false;
        elements.submit.disabled = state.captcha === null;
        elements.submit.textContent = 'Verbindlich reservieren';
    }
}

async function loadCaptcha() {
    const request = ++state.captchaRequest;
    state.captcha = null;
    elements.captchaQuestion.textContent = 'Frage wird geladen …';
    elements.captchaAnswer.value = '';
    elements.submit.disabled = true;
    try {
        const response = await api('api.php?action=captcha');
        if (request !== state.captchaRequest) return;
        state.captcha = response.data;
        elements.captchaQuestion.textContent = response.data.question;
        if (!state.submitting) elements.submit.disabled = false;
    } catch (error) {
        if (request !== state.captchaRequest) return;
        elements.captchaQuestion.textContent = 'Frage konnte nicht geladen werden.';
        showDialogError(error.message);
    }
}

async function api(url, options = {}) {
    const response = await fetch(url, {...options, credentials: 'same-origin'});
    let payload;
    try {
        payload = await response.json();
    } catch {
        throw new Error('Der Server hat keine gültige Antwort geliefert.');
    }
    if (!response.ok || !payload.ok) {
        const error = new Error(payload.error || 'Die Anfrage ist fehlgeschlagen.');
        error.status = response.status;
        throw error;
    }
    return payload;
}

function showGlobalError(message) {
    elements.globalError.textContent = message;
    elements.globalError.hidden = false;
    elements.app.setAttribute('aria-busy', 'false');
}

function showDialogError(message) {
    elements.dialogError.textContent = message;
    elements.dialogError.hidden = false;
}

function addMinutes(time, minutes) {
    const [hours, mins] = time.split(':').map(Number);
    const total = hours * 60 + mins + Number(minutes);
    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

function escapeHtml(value) {
    const span = document.createElement('span');
    span.textContent = String(value);
    return span.innerHTML;
}
