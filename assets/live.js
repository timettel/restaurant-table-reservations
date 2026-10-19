'use strict';

const live = { snapshot:null, selectedId:null, refreshAt:0, refreshing:false, toastTimer:null, phoneRequest:0 };
const $ = (selector) => document.querySelector(selector);

document.addEventListener('DOMContentLoaded', () => {
    $('#refresh-now').addEventListener('click', () => refresh());
    $('#phone-open').addEventListener('click', openPhone);
    $('#occupy-form').addEventListener('submit', submitOccupancy);
    $('#phone-form').addEventListener('submit', submitPhone);
    $('#phone-date').addEventListener('change', loadPhoneSlots);
    $('#phone-time').addEventListener('change', loadPhoneAvailability);
    $('#phone-table').addEventListener('change', updatePhoneCapacity);
    document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    }));
    window.setInterval(tick, 1000);
    refresh();
});

async function api(action, options = {}) {
    const response = await fetch(`live-api.php?action=${action}`, { credentials:'same-origin', cache:'no-store', ...options });
    let payload;
    try { payload = await response.json(); } catch { throw new Error('Der Server hat keine gültige Antwort geliefert.'); }
    if (!response.ok || !payload.ok) {
        if (response.status === 401) window.location.href = 'admin.php';
        const error = new Error(payload.error || 'Die Anfrage ist fehlgeschlagen.');
        error.status = response.status;
        throw error;
    }
    return payload.data;
}

async function post(action, data) {
    return api(action, {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':$('meta[name="csrf-token"]').content}, body:JSON.stringify(data)});
}

async function refresh() {
    if (live.refreshing) return;
    live.refreshing = true;
    try {
        const data = await api('snapshot');
        live.snapshot = data;
        if (live.selectedId === null) {
            live.selectedId = Number((data.tables.find((table) => table.status === 'soon') || data.tables.find((table) => table.status === 'reserved') || data.tables[0])?.id ?? 0);
        }
        live.refreshAt = Date.now() + data.refresh_seconds * 1000;
        $('#live-error').hidden = true;
        $('#freshness-text').textContent = 'Aktualisiert vor wenigen Sekunden';
        $('.freshness').classList.remove('is-stale');
        $('#live-app').setAttribute('aria-busy', 'false');
        render();
    } catch (error) {
        $('#live-error').textContent = `Aktualisierung fehlgeschlagen: ${error.message}`;
        $('#live-error').hidden = false;
        $('#freshness-text').textContent = 'Aktualisierung fehlgeschlagen';
        $('.freshness').classList.add('is-stale');
        $('#live-app').setAttribute('aria-busy', 'false');
        live.refreshAt = Date.now() + 30000;
    } finally {
        live.refreshing = false;
    }
}

function tick() {
    const remaining = Math.max(0, Math.ceil((live.refreshAt - Date.now()) / 1000));
    $('#refresh-countdown').textContent = `Automatische Aktualisierung in ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`;
    if (live.snapshot) {
        const then = new Date(live.snapshot.now_epoch * 1000);
        const delta = Math.max(0, Math.floor((Date.now() - (live.refreshAt - live.snapshot.refresh_seconds * 1000)) / 60000));
        $('#live-clock').textContent = new Intl.DateTimeFormat('de-DE', {dateStyle:'medium',timeStyle:'short',timeZone:'Europe/Berlin'}).format(then);
        if (delta > 0 && !$('.freshness').classList.contains('is-stale')) $('#freshness-text').textContent = `Aktualisiert vor ${delta} Min.`;
    }
    if (remaining === 0 && live.refreshAt && !document.querySelector('dialog[open]')) refresh();
}

function render() {
    const data = live.snapshot;
    $('#live-clock').textContent = new Intl.DateTimeFormat('de-DE', {dateStyle:'medium',timeStyle:'short',timeZone:'Europe/Berlin'}).format(new Date(data.now_epoch * 1000));
    const layer = $('#live-tables');
    layer.replaceChildren();
    data.tables.forEach((table) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `live-table live-table--${table.shape}`;
        if (Number(table.id) === live.selectedId) button.classList.add('is-selected');
        button.dataset.status = table.status;
        button.style.setProperty('--x', `${table.x}%`);
        button.style.setProperty('--y', `${table.y}%`);
        button.style.setProperty('--w', `${table.w}%`);
        button.style.setProperty('--h', `${table.h}%`);
        button.setAttribute('aria-label', `Tisch ${table.label}, ${table.capacity} Plätze, ${statusLabel(table.status)}`);
        const surface = document.createElement('span');
        surface.className = 'live-table__surface';
        surface.textContent = table.label;
        button.append(surface);
        chairPositions(Number(table.capacity), table.shape).forEach((position) => {
            const chair = document.createElement('i');
            chair.className = `live-chair live-chair--${position}`;
            chair.setAttribute('aria-hidden', 'true');
            button.append(chair);
        });
        button.addEventListener('click', () => selectTable(Number(table.id)));
        layer.append(button);
    });
    const upcoming = $('#upcoming-list');
    upcoming.replaceChildren();
    if (data.upcoming.length === 0) {
        const empty = document.createElement('p'); empty.className = 'upcoming-empty'; empty.textContent = 'Keine weiteren Reservierungen im Buchungszeitraum.'; upcoming.append(empty);
    }
    data.upcoming.forEach((item) => {
        const row = document.createElement('button'); row.type = 'button'; row.className = 'upcoming-row';
        const time = document.createElement('strong'); time.textContent = item.time;
        const marker = document.createElement('i'); if (item.start_epoch <= data.now_epoch) marker.classList.add('is-current');
        const table = document.createElement('strong'); table.textContent = `Tisch ${item.table_id}`;
        const guest = document.createElement('span'); guest.textContent = item.name;
        if (item.date !== data.today) { const date = document.createElement('small'); date.textContent = new Date(item.start_epoch * 1000).toLocaleDateString('de-DE',{timeZone:'Europe/Berlin'}); guest.append(date); }
        const party = document.createElement('span'); party.className = 'party'; party.textContent = `${item.party_size} Pers.`;
        row.append(time,marker,table,guest,party);
        row.addEventListener('click', () => selectTable(item.table_id));
        upcoming.append(row);
    });
    renderSelected();
}

function selectTable(id) { live.selectedId = id; render(); $('#selected-panel').scrollIntoView({behavior:'smooth',block:'nearest'}); }

function renderSelected() {
    const panel = $('#selected-panel');
    const table = live.snapshot.tables.find((item) => Number(item.id) === live.selectedId);
    if (!table) { panel.replaceChildren(document.createElement('p')); panel.firstChild.textContent = 'Tippen Sie auf einen Tisch, um Details und Aktionen zu sehen.'; return; }
    panel.replaceChildren();
    const identity = document.createElement('div'); identity.className = 'selected-panel__identity';
    const title = document.createElement('strong'); title.textContent = `Tisch ${table.label}`;
    const meta = document.createElement('small'); meta.textContent = `${table.capacity} Plätze · ${statusLabel(table.status)}`;
    identity.append(title,meta);
    const detail = document.createElement('div'); detail.className = 'selected-panel__detail';
    const detailTitle = document.createElement('strong');
    const detailSub = document.createElement('small');
    if (table.current) { detailTitle.textContent = `Reserviert bis ${table.current.end_time} Uhr`; detailSub.textContent = `${table.current.name} · ${table.current.party_size} Personen`; }
    else if (table.occupancy) { detailTitle.textContent = `Manuell belegt bis ${formatTime(table.occupancy.expires_at)}`; detailSub.textContent = `${table.occupancy.party_size} Personen${table.occupancy.note ? ' · ' + table.occupancy.note : ''}`; }
    else if (table.next) { detailTitle.textContent = `Nächste Reservierung: ${table.next.time} Uhr`; detailSub.textContent = `${table.next.name} · ${table.next.party_size} Personen`; }
    else { detailTitle.textContent = 'Aktuell frei'; detailSub.textContent = 'Keine bevorstehende Reservierung im Buchungszeitraum.'; }
    detail.append(detailTitle,detailSub);
    const action = document.createElement('div'); action.className = 'selected-panel__action';
    if (table.occupancy && !table.current) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'button button--secondary'; button.textContent = 'Tisch freigeben'; button.addEventListener('click', () => releaseTable(table)); action.append(button);
    } else if (!table.current) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'button button--primary'; button.textContent = 'Manuell belegen'; button.addEventListener('click', () => openOccupancy(table)); action.append(button);
    } else {
        const note = document.createElement('small'); note.textContent = 'Während der Reservierung nicht manuell belegbar.'; action.append(note);
    }
    panel.append(identity,detail,action);
}

function openOccupancy(table) {
    const form = $('#occupy-form'); form.reset();
    form.elements.party_size.max = table.capacity;
    form.elements.party_size.value = Math.min(2,Number(table.capacity));
    $('#occupy-summary').textContent = `Tisch ${table.label} · ${table.capacity} Plätze`;
    $('#occupy-next').textContent = table.next ? `Nächste Reservierung: ${table.next.time} Uhr. Die Belegung muss vorher enden.` : 'Keine bevorstehende Reservierung im Buchungszeitraum.';
    $('#occupy-error').hidden = true;
    $('#occupy-dialog').showModal();
}

async function submitOccupancy(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const button = form.querySelector('[type="submit"]'); button.disabled = true;
    try {
        await post('occupy', {table_id:live.selectedId,party_size:Number(form.elements.party_size.value),duration_minutes:Number(form.elements.duration_minutes.value),note:form.elements.note.value.trim()});
        $('#occupy-dialog').close();
        showToast('Tisch wurde manuell belegt.');
        await refresh();
    } catch (error) { showFormError('occupy',error.message); }
    finally { button.disabled = false; }
}

async function releaseTable(table) {
    const button = $('.selected-panel__action button'); button.disabled = true;
    try { await post('release',{id:table.occupancy.id}); showToast('Tisch wurde freigegeben.'); await refresh(); }
    catch (error) { $('#live-error').textContent = error.message; $('#live-error').hidden = false; button.disabled = false; }
}

async function openPhone() {
    const form = $('#phone-form'); form.reset(); $('#phone-error').hidden = true;
    const date = $('#phone-date'); date.replaceChildren();
    live.snapshot.dates.filter((day) => !day.closed).forEach((day) => date.add(new Option(day.long_label,day.date)));
    if (live.snapshot.dates.some((day) => day.date === live.snapshot.today && !day.closed)) date.value = live.snapshot.today;
    $('#phone-dialog').showModal();
    await loadPhoneSlots();
}

async function loadPhoneSlots() {
    const date = $('#phone-date').value;
    const time = $('#phone-time'); time.replaceChildren();
    const table = $('#phone-table'); table.replaceChildren();
    $('#phone-availability').textContent = 'Verfügbarkeit wird geladen …';
    if (!date) { $('#phone-availability').textContent = 'Kein buchbares Datum verfügbar.'; return; }
    const request = ++live.phoneRequest;
    try {
        const data = await api(`slots&date=${encodeURIComponent(date)}`);
        if (request !== live.phoneRequest) return;
        data.slots.forEach((slot) => time.add(new Option(slot,slot)));
        if (data.slots.length === 0) { $('#phone-availability').textContent = 'Für diesen Tag sind keine Startzeiten mehr verfügbar.'; return; }
        await loadPhoneAvailability();
    } catch (error) { showFormError('phone',error.message); }
}

async function loadPhoneAvailability() {
    const date = $('#phone-date').value, time = $('#phone-time').value;
    const tableSelect = $('#phone-table'); tableSelect.replaceChildren();
    if (!date || !time) return;
    const request = ++live.phoneRequest;
    try {
        const data = await api(`availability&date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}`);
        if (request !== live.phoneRequest) return;
        const occupied = new Set(data.occupied_table_ids.map(Number));
        live.snapshot.tables.forEach((table) => {
            const option = new Option(`Tisch ${table.label} (${table.capacity} Plätze)${occupied.has(Number(table.id)) ? ' – belegt' : ''}`,String(table.id));
            option.disabled = occupied.has(Number(table.id));
            tableSelect.add(option);
        });
        if (live.selectedId && !occupied.has(live.selectedId)) tableSelect.value = String(live.selectedId);
        if (tableSelect.selectedOptions[0]?.disabled) {
            const first = Array.from(tableSelect.options).find((option) => !option.disabled);
            if (first) tableSelect.value = first.value;
        }
        $('#phone-availability').textContent = tableSelect.selectedOptions[0] && !tableSelect.selectedOptions[0].disabled
            ? `Tisch ${tableSelect.value} ist von ${time} bis ${data.end_time} Uhr frei.`
            : 'Zu dieser Uhrzeit ist kein Tisch frei.';
        updatePhoneCapacity();
    } catch (error) { showFormError('phone',error.message); }
}

function updatePhoneCapacity() {
    const table = live.snapshot?.tables.find((item) => Number(item.id) === Number($('#phone-table').value));
    if (!table) return;
    const input = $('#phone-party'); input.max = table.capacity;
    if (!input.value || Number(input.value) > Number(table.capacity)) input.value = String(Math.min(2,Number(table.capacity)));
    const time = $('#phone-time').value;
    if (time) $('#phone-availability').textContent = `Tisch ${table.label} ist ab ${time} Uhr frei. Dauer: ${live.snapshot.duration_minutes} Minuten.`;
}

async function submitPhone(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    if (!String(data.get('phone') || '').trim() && !String(data.get('email') || '').trim()) { showFormError('phone','Bitte Telefon oder E-Mail angeben.'); return; }
    if (form.elements.send_confirmation.checked && !String(data.get('email') || '').trim()) { showFormError('phone','Für die Bestätigung bitte eine E-Mail-Adresse angeben.'); return; }
    if (!form.reportValidity()) return;
    const button = form.querySelector('[type="submit"]'); button.disabled = true;
    try {
        const result = await post('phone-reservation',{
            date:String(data.get('date')),time:String(data.get('time')),table_id:Number(data.get('table_id')),
            party_size:Number(data.get('party_size')),name:String(data.get('name')).trim(),phone:String(data.get('phone')).trim(),
            email:String(data.get('email')).trim(),send_confirmation:form.elements.send_confirmation.checked,
        });
        $('#phone-dialog').close();
        live.selectedId = result.table_id;
        showToast(`Reservierung für Tisch ${result.table_label} wurde eingetragen.${form.elements.send_confirmation.checked ? (result.confirmation_sent ? ' Bestätigung versendet.' : ' E-Mail-Bestätigung fehlgeschlagen; bitte Status in der Reservierungsliste prüfen.') : ''}`);
        await refresh();
    } catch (error) {
        showFormError('phone',error.message);
        if (error.status === 409) await loadPhoneAvailability();
    } finally { button.disabled = false; }
}

function showFormError(which,message) { const box = $(`#${which}-error`); box.textContent = message; box.hidden = false; }
function showToast(message) { const box = $('#live-toast'); box.textContent = message; box.hidden = false; window.clearTimeout(live.toastTimer); live.toastTimer = window.setTimeout(() => { box.hidden = true; }, 7000); }
function formatTime(epoch) { return new Intl.DateTimeFormat('de-DE',{hour:'2-digit',minute:'2-digit',timeZone:'Europe/Berlin'}).format(new Date(epoch * 1000)) + ' Uhr'; }
function statusLabel(status) { return ({free:'Frei',soon:'Bald reserviert',reserved:'Reserviert',manual:'Manuell belegt'})[status] || status; }
function chairPositions(capacity,shape) {
    if (capacity === 2) return ['left-middle','right-middle'];
    if (capacity === 6) return ['left-top','left-middle','left-bottom','right-top','right-middle','right-bottom'];
    return shape === 'round' ? ['top-middle','right-middle','bottom-middle','left-middle'] : ['top-left','top-right','bottom-left','bottom-right'];
}
