(function () {
    const state = { context: null, options: null, rows: [], selectedRoom: '', search: '', status: '', timer: null, availabilityTimer: null, availabilityAnchor: new Date(), detailsTimer: null, currentDetailsId: null, lastFocus: null, aiJobs: new Set() };
    const qs = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const title = value => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const toDate = value => value instanceof Date ? value : (value ? new Date(String(value).replace(' ', 'T')) : null);
    const pad = value => String(value).padStart(2, '0');
    const isoDate = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const fmtDate = value => { const d = toDate(value); return d && !Number.isNaN(d.getTime()) ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : 'Not available'; };
    const fmtTime = value => { const d = toDate(value); return d && !Number.isNaN(d.getTime()) ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : 'Not available'; };
    const fmtDateTime = value => { const d = toDate(value); return d && !Number.isNaN(d.getTime()) ? d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Not available'; };
    const fileSize = bytes => {
        const value = Number(bytes || 0);
        if (!value) return '';
        if (value < 1048576) return `${Math.ceil(value / 1024)} KB`;
        return `${(value / 1048576).toFixed(value < 10485760 ? 1 : 0)} MB`;
    };
    const api = path => `employee/reservations/${path}`;
    const requestLetterUrl = (id, mode) => {
        const path = `employee/reservations/request-letter.php?id=${encodeURIComponent(id)}&mode=${encodeURIComponent(mode)}`;
        return window.FAMApi?.apiUrl?.(path) || window.FAMNavigation?.apiUrl?.(path) || `/api/${path}`;
    };
    function statusLabel(item) {
        const status = String(item.status || '').toUpperCase();
        const approval = String(item.approval || '').toUpperCase();
        if (status === 'SUBMITTED' && approval === 'PENDING') return 'Pending Approval';
        return title(status || approval || 'Not available');
    }

    function badge(item) {
        const raw = String(item.status || item.approval || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-status-${raw}">${esc(statusLabel(item))}</span>`;
    }

    function emptyRow(titleText = 'No room reservations yet', copy = 'Your room reservation requests will appear here.') {
        return `<tr><td colspan="7">${window.FAMEmployeePortal.emptyState('event_busy', titleText, copy, '')}</td></tr>`;
    }

    function selectedRoom() {
        return (state.options?.facility_spaces || []).find(room => String(room.id) === String(state.selectedRoom));
    }

    function capacityLabel(item) {
        return item?.capacityLabel || item?.capacity_label || (item?.capacity ? `Capacity: ${item.capacity} ${String(item.capacityUnit || item.capacity_unit || 'PAX').toLowerCase()}` : '');
    }

    function roomMeta(room) {
        return [room?.building_name, capacityLabel(room), room?.location_description].filter(Boolean).join(' - ');
    }

    function roomMedia(room) {
        return window.FAMFacilityImages?.mediaHtml?.(room, { alt: room?.name || room?.code || 'Facility image' })
            || '<div class="facility-image-placeholder"><span class="material-symbols-outlined" aria-hidden="true">meeting_room</span><span>No image yet</span></div>';
    }

    function renderRoomCards() {
        const grid = qs('#employee-room-card-grid');
        if (!grid) return;
        const rooms = state.options?.facility_spaces || [];
        const count = qs('#employee-room-selection-count');
        if (count) count.textContent = rooms.length ? `${rooms.length} facilit${rooms.length === 1 ? 'y' : 'ies'} available` : 'No reservable facilities';
        const message = qs('#employee-room-selection-message');
        const headerButton = qs('#employee-request-room');
        if (headerButton) headerButton.disabled = !state.selectedRoom;
        if (!rooms.length) {
            grid.innerHTML = window.FAMEmployeePortal.emptyState('meeting_room', 'No facilities available', 'Please contact Facilities Administrative Management.');
            if (message) message.textContent = '';
            return;
        }
        grid.innerHTML = rooms.map(room => {
            const selected = String(room.id) === String(state.selectedRoom);
            return `<article class="employee-room-card${selected ? ' selected' : ''}" data-room-card="${esc(room.id)}">
                <div class="employee-room-card-media">${roomMedia(room)}</div>
                <div class="employee-room-card-body">
                    <div>
                        <h3>${esc(room.name || room.code || 'Facility')}</h3>
                        <p>${esc(title(room.type || 'Facility'))}</p>
                        <p>${esc(roomMeta(room) || 'Facility details unavailable')}</p>
                    </div>
                    <div class="employee-room-card-actions">
                        <button class="${selected ? 'btn-primary' : 'btn-secondary'} dashboard-action-button" type="button" data-select-room="${esc(room.id)}">${selected ? 'Selected' : 'Select'}</button>
                        <button class="facility-page-button" type="button" data-request-room="${esc(room.id)}">Choose Schedule</button>
                    </div>
                </div>
            </article>`;
        }).join('');
        if (message) message.textContent = state.selectedRoom ? `${selectedRoom()?.name || 'Selected facility'} is selected. Review availability before requesting.` : 'Select a facility to view availability and enable the request form.';
    }

    function selectRoom(id, openRequest = false) {
        if (!id) return;
        state.selectedRoom = String(id);
        sessionStorage.setItem('fam_employee_selected_room', state.selectedRoom);
        renderRoomCards();
        loadAvailability().catch(error => {
            const message = qs('#employee-availability-message');
            if (message) message.textContent = error.message || 'Unable to load availability.';
        });
        if (openRequest) openForm({ facility_space_id: state.selectedRoom });
    }

    function availabilityRange() {
        const anchor = new Date(state.availabilityAnchor);
        const start = new Date(anchor.getFullYear(), anchor.getMonth(), anchor.getDate() - anchor.getDay());
        const end = new Date(start);
        end.setDate(start.getDate() + 6);
        return { start, end };
    }

    function eventTone(event) {
        const status = String(event.status || '').toUpperCase();
        if (status === 'CHECKED_IN') return 'checked-in';
        if (status === 'SUBMITTED') return 'submitted';
        return 'approved';
    }

    function renderAvailability(events = []) {
        const panel = qs('#employee-availability-calendar');
        const message = qs('#employee-availability-message');
        const subtitle = qs('#employee-availability-subtitle');
        if (!panel) return;
        const room = selectedRoom();
        if (!room) {
            panel.innerHTML = window.FAMEmployeePortal.emptyState('event_available', 'Select a facility', 'Availability appears after choosing a facility.');
            if (message) message.textContent = 'Select a facility to view availability.';
            if (subtitle) subtitle.textContent = 'Select a facility to view its schedule.';
            return;
        }
        const { start, end } = availabilityRange();
        if (subtitle) subtitle.textContent = `${room.name || 'Selected facility'} - ${start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} to ${end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`;
        const byDay = new Map();
        events.forEach(event => {
            const date = toDate(event.start);
            if (!date) return;
            const key = isoDate(date);
            byDay.set(key, [...(byDay.get(key) || []), event]);
        });
        const days = [];
        for (let index = 0; index < 7; index += 1) {
            const day = new Date(start);
            day.setDate(start.getDate() + index);
            const key = isoDate(day);
            const items = (byDay.get(key) || []).sort((a, b) => (toDate(a.start) || 0) - (toDate(b.start) || 0));
            days.push(`<section class="employee-availability-day"><header><span>${esc(day.toLocaleDateString(undefined, { weekday: 'short' }))}</span><strong>${esc(day.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))}</strong></header><div>${items.length ? items.map(event => `<div class="employee-availability-event reservation-event-${eventTone(event)}"><span>${esc(fmtTime(event.start))} - ${esc(fmtTime(event.end))}</span><strong>${event.ownership === 'SELF' ? esc(event.reservation_number || 'Your reservation') : 'Reserved'}</strong><small>${event.ownership === 'SELF' ? esc(event.purpose || statusLabel(event)) : 'Unavailable'}</small></div>`).join('') : '<p class="employee-availability-open">No blocking reservations</p>'}</div></section>`);
        }
        panel.innerHTML = days.join('');
        if (message) message.textContent = 'Visual availability is informational. Final conflict checks run when you submit.';
    }

    async function loadAvailability() {
        if (!qs('#employee-availability-calendar')) return;
        const room = selectedRoom();
        if (!room) return renderAvailability([]);
        const { start, end } = availabilityRange();
        const params = new URLSearchParams({ facility_space_id: state.selectedRoom, date_from: isoDate(start), date_to: isoDate(end) });
        const response = await window.FAMApi.request(api(`calendar.php?${params}`));
        renderAvailability(response.data?.events || []);
    }

    function rowHtml(item) {
        const actions = [
            `<button type="button" role="menuitem" data-open-reservation="${esc(item.id)}">View Details</button>`,
            item.allowed_actions?.check_in ? `<button type="button" role="menuitem" data-check-in-reservation="${esc(item.id)}">Check In</button>` : '',
            item.allowed_actions?.check_out ? `<button type="button" role="menuitem" data-check-out-reservation="${esc(item.id)}">Check Out</button>` : '',
            item.allowed_actions?.cancel ? `<button type="button" role="menuitem" data-cancel-reservation="${esc(item.id)}">Cancel Reservation</button>` : ''
        ].filter(Boolean).join('');
        return `<tr>
            <td><button class="facility-link-button table-cell-primary" type="button" data-open-reservation="${esc(item.id)}">${esc(item.reservationNo)}</button></td>
            <td><span class="table-cell-truncate" title="${esc(item.room)}">${esc(item.room || 'Not available')}</span></td>
            <td><span class="table-cell-truncate" title="${esc(title(item.reservationType || 'MEETING'))}">${esc(title(item.reservationType || 'MEETING'))}</span></td>
            <td>${esc(fmtDate(item.start))}</td>
            <td>${esc(fmtTime(item.start))} - ${esc(fmtTime(item.end))}</td>
            <td>${badge(item)}</td>
            <td class="facility-actions-cell"><div class="facility-action-menu"><button class="facility-action-toggle" type="button" data-employee-menu="${esc(item.id)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(item.reservationNo)}">&#8942;</button><div class="facility-action-dropdown hidden" data-employee-menu-panel="${esc(item.id)}" role="menu">${actions}</div></div></td>
        </tr>`;
    }

    function mobileCardHtml(item) {
        const menuId = `reservation-card-${item.id}`;
        const actions = [
            `<button type="button" role="menuitem" data-open-reservation="${esc(item.id)}">View Details</button>`,
            item.allowed_actions?.check_in ? `<button type="button" role="menuitem" data-check-in-reservation="${esc(item.id)}">Check In</button>` : '',
            item.allowed_actions?.check_out ? `<button type="button" role="menuitem" data-check-out-reservation="${esc(item.id)}">Check Out</button>` : '',
            item.allowed_actions?.cancel ? `<button type="button" role="menuitem" data-cancel-reservation="${esc(item.id)}">Cancel Reservation</button>` : ''
        ].filter(Boolean).join('');
        return `
            <article class="employee-record-card">
                <div class="employee-record-card-top">
                    <button class="facility-link-button employee-record-reference" type="button" data-open-reservation="${esc(item.id)}">${esc(item.reservationNo)}</button>
                    ${badge(item)}
                </div>
                <h3>${esc(item.room || 'Room reservation')}</h3>
                <dl class="employee-record-meta">
                    <div><dt>Date</dt><dd>${esc(fmtDate(item.start))}</dd></div>
                    <div><dt>Time</dt><dd>${esc(fmtTime(item.start))} - ${esc(fmtTime(item.end))}</dd></div>
                    <div><dt>Type</dt><dd>${esc(title(item.reservationType || 'MEETING'))}</dd></div>
                </dl>
                <div class="employee-record-card-actions">
                    <div class="facility-action-menu">
                        <button class="facility-action-toggle" type="button" data-employee-menu="${esc(menuId)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(item.reservationNo)}">&#8942;</button>
                        <div class="facility-action-dropdown hidden" data-employee-menu-panel="${esc(menuId)}" role="menu">${actions}</div>
                    </div>
                </div>
            </article>
        `;
    }

    function render() {
        const body = qs('#employee-room-reservations-body');
        const cards = qs('#employee-room-reservations-cards');
        if (!body) return;
        qs('#employee-room-loading')?.classList.add('hidden');
        const count = qs('#employee-reservation-count');
        if (count) count.textContent = state.rows.length ? `${state.rows.length} reservation${state.rows.length === 1 ? '' : 's'} found` : 'No matching room reservations';
        const now = Date.now();
        if (qs('#employee-upcoming-count')) qs('#employee-upcoming-count').textContent = state.rows.filter(row => (toDate(row.start)?.getTime() || 0) >= now && !['CANCELLED','REJECTED','COMPLETED','NO_SHOW'].includes(String(row.status))).length;
        if (qs('#employee-pending-count')) qs('#employee-pending-count').textContent = state.rows.filter(row => String(row.status).toUpperCase() === 'SUBMITTED' && String(row.approval).toUpperCase() === 'PENDING').length;
        const emptyTitle = state.search || state.status ? 'No matching reservations' : 'No room reservations yet';
        const emptyCopy = state.search || state.status ? 'Try adjusting your search or status filter.' : 'Your room reservation requests will appear here.';
        body.innerHTML = state.rows.length ? state.rows.map(rowHtml).join('') : emptyRow(emptyTitle, emptyCopy);
        if (cards) cards.innerHTML = state.rows.length ? state.rows.map(mobileCardHtml).join('') : window.FAMEmployeePortal.emptyState('event_busy', emptyTitle, emptyCopy, '');
    }

    async function load() {
        const body = qs('#employee-room-reservations-body');
        if (!body) return;
        const params = new URLSearchParams({ per_page: '50' });
        if (state.search) params.set('search', state.search);
        if (state.status) params.set('status', state.status);
        const response = await window.FAMApi.request(api(`list.php?${params}`));
        state.rows = response.data?.items || [];
        render();
    }

    function optionList(items) {
        return (items || []).map(item => `<option value="${esc(item.id)}" data-capacity="${esc(item.capacity || '')}" data-capacity-unit="${esc(item.capacityUnit || 'PAX')}">${esc(item.name || item.code || 'Room')}${capacityLabel(item) ? ` (${esc(capacityLabel(item))})` : ''}</option>`).join('');
    }

    function ensureDialog() {
        let dialog = qs('#employee-reservation-dialog');
        if (!dialog) {
            dialog = document.createElement('div');
            dialog.id = 'employee-reservation-dialog';
            dialog.className = 'facility-dialog';
            dialog.hidden = true;
            document.body.appendChild(dialog);
        }
        if (dialog.parentElement !== document.body) document.body.appendChild(dialog);
        return dialog;
    }

    function closeDialog() {
        const dialog = qs('#employee-reservation-dialog');
        if (!dialog) return;
        window.FAMModal?.closeElement?.(dialog, () => {
            dialog.innerHTML = '';
            document.body.classList.remove('fam-modal-open');
            state.currentDetailsId = null;
            clearInterval(state.detailsTimer);
            state.lastFocus?.focus?.();
        });
    }

    function requestLetterUpload() {
        return `<label class="facility-field document-file-field employee-request-letter-field"><span>Request Letter <b aria-hidden="true">*</b></span><input name="request_letter" type="file" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg" required><small>Allowed: PDF, PNG, JPG/JPEG up to 10 MB each.</small></label>`;
    }

    function openForm(prefill = {}) {
        const roomId = prefill.facility_space_id || state.selectedRoom;
        if (!roomId) {
            window.FAMModal?.showToast('Select a facility before starting a request.');
            qs('#employee-room-card-grid')?.focus?.();
            return;
        }
        const dialog = ensureDialog();
        state.lastFocus = document.activeElement;
        dialog.hidden = false;
        document.body.classList.add('fam-modal-open');
        dialog.innerHTML = `<form class="facility-dialog-panel employee-request-form employee-reservation-form" data-reservation-form>
            <div class="facility-details-modal-header"><div><p>Employee Portal</p><h2 id="reservation-form-title">Request Facility</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close reservation form">&times;</button></div>
            <div class="employee-reservation-form-body">
                <div class="facility-form-error" data-form-error hidden></div>
                <section class="employee-form-section"><h3>Facility</h3><label class="facility-field"><span>Facility <b aria-hidden="true">*</b></span><select name="facility_space_id" required><option value="">Select facility</option>${optionList(state.options?.facility_spaces)}</select></label><p class="fam-muted" data-room-summary>Select a facility to view capacity and location.</p></section>
                <section class="employee-form-section"><h3>Schedule</h3><div class="facility-form-grid"><label class="facility-field"><span>Date <b aria-hidden="true">*</b></span><input name="date" type="date" required></label><label class="facility-field"><span>Start Time <b aria-hidden="true">*</b></span><input name="start_time" type="time" required></label><label class="facility-field"><span>End Time <b aria-hidden="true">*</b></span><input name="end_time" type="time" required></label></div><p class="reservation-availability-state" data-availability-state aria-live="polite">Choose a room and schedule to check availability.</p></section>
                <section class="employee-form-section"><h3>Reservation Details</h3><div class="facility-form-grid"><label class="facility-field"><span>Expected Attendees <b aria-hidden="true">*</b></span><input name="expected_attendees" type="number" min="1" value="1" required></label><label class="facility-field"><span>Reservation Type</span><select name="reservation_type"><option value="MEETING">Meeting</option><option value="TRAINING">Training</option><option value="EVENT">Event</option><option value="OTHER">Other</option></select></label></div>${requestLetterUpload()}</section>
            </div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Submit Reservation</button></div>
        </form>`;
        const roomField = dialog.querySelector('[name="facility_space_id"]');
        roomField.value = String(roomId);
        roomField.dispatchEvent(new Event('input', { bubbles: true }));
        if (prefill.date) dialog.querySelector('[name="date"]').value = prefill.date;
        dialog.querySelector('select, input, textarea, button')?.focus();
    }

    function formPayload(form, multipart = false) {
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        const payload = {
            facility_space_id: data.facility_space_id,
            start_datetime: data.date && data.start_time ? `${data.date}T${data.start_time}` : '',
            end_datetime: data.date && data.end_time ? `${data.date}T${data.end_time}` : '',
            expected_attendees: data.expected_attendees,
            reservation_type: data.reservation_type || 'MEETING'
        };
        if (!multipart) return payload;
        const body = new FormData();
        Object.entries(payload).forEach(([key, value]) => body.append(key, value ?? ''));
        const file = form.querySelector('[name="request_letter"]')?.files?.[0];
        if (file) body.append('request_letter', file);
        return body;
    }

    function scheduleValidationMessage(payload) {
        if (!payload.start_datetime || !payload.end_datetime) return '';
        const start = toDate(payload.start_datetime);
        const end = toDate(payload.end_datetime);
        if (!start || !end || Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return 'Please enter a valid reservation schedule.';
        const minutes = (end.getTime() - start.getTime()) / 60000;
        if (minutes <= 0) return 'End time must be later than the start time. For noon, choose 12:00 PM instead of 12:00 AM.';
        if (minutes < 15) return 'Reservation must be at least 15 minutes.';
        return '';
    }

    function setErrors(form, error) {
        form.querySelectorAll('.facility-field-error').forEach(node => node.remove());
        Object.entries(error.errors || error.payload?.data?.errors || {}).forEach(([name, message]) => {
            const field = form.querySelector(`[name="${CSS.escape(name)}"]`) || (name === 'start_datetime' ? form.querySelector('[name="date"]') : null) || (name === 'end_datetime' ? form.querySelector('[name="end_time"]') : null);
            field?.insertAdjacentHTML('afterend', `<span class="facility-field-error">${esc(message)}</span>`);
        });
        const box = form.querySelector('[data-form-error]');
        if (box) {
            const errors = error.errors || error.payload?.data?.errors || {};
            box.textContent = errors.schedule
                ? 'The selected room is unavailable during this schedule.'
                : (error.status === 422 ? 'Please review the highlighted fields.' : "We couldn't submit your reservation. Please try again.");
            box.hidden = false;
        }
    }

    function availabilityReady(payload) {
        return Boolean(payload.facility_space_id && payload.start_datetime && payload.end_datetime);
    }

    async function checkAvailability(form) {
        const status = form.querySelector('[data-availability-state]');
        const payload = formPayload(form);
        delete status.dataset.available;
        if (!payload.facility_space_id && !payload.start_datetime && !payload.end_datetime) {
            status.textContent = 'Choose a room and schedule to check availability.';
            return;
        }
        if (!availabilityReady(payload)) {
            status.textContent = 'Complete the room and schedule to check availability.';
            return;
        }
        const scheduleMessage = scheduleValidationMessage(payload);
        if (scheduleMessage) {
            status.textContent = scheduleMessage;
            status.dataset.available = 'false';
            return;
        }
        status.textContent = 'Checking availability...';
        try {
            const response = await window.FAMApi.request(api('availability.php'), { method: 'POST', body: payload });
            status.textContent = response.data?.available ? 'Available' : 'The selected room is unavailable during this schedule.';
            status.dataset.available = String(Boolean(response.data?.available));
        } catch (error) {
            const errors = error.errors || error.payload?.data?.errors || {};
            status.textContent = errors.schedule || (error.status === 422 ? 'Please review the reservation schedule.' : "We couldn't check availability. Please try again.");
            status.dataset.available = 'false';
        }
    }

    async function submitForm(form) {
        if (!form.reportValidity()) return;
        const button = form.querySelector('[type="submit"]');
        const payload = formPayload(form);
        const scheduleMessage = scheduleValidationMessage(payload);
        if (scheduleMessage) {
            const endField = form.querySelector('[name="end_time"]');
            form.querySelectorAll('.facility-field-error').forEach(node => node.remove());
            endField?.insertAdjacentHTML('afterend', `<span class="facility-field-error">${esc(scheduleMessage)}</span>`);
            const box = form.querySelector('[data-form-error]');
            if (box) {
                box.textContent = 'Please review the reservation schedule.';
                box.hidden = false;
            }
            form.querySelector('[data-availability-state]').textContent = scheduleMessage;
            endField?.focus();
            return;
        }
        button.disabled = true;
        form.querySelector('[data-form-error]').hidden = true;
        try {
            const response = await window.FAMApi.request(api('create.php'), { method: 'POST', body: formPayload(form, true) });
            closeDialog();
            await load();
            window.FAMModal?.showToast(`Reservation ${response.data?.item?.reservationNo || ''} submitted for review.`);
            triggerRequestSummary(response.data?.item?.id);
            await openDetails(response.data?.item?.id);
        } catch (error) {
            setErrors(form, error);
        } finally {
            button.disabled = false;
        }
    }

    async function triggerRequestSummary(id) {
        if (!id || state.aiJobs.has(String(id))) return;
        state.aiJobs.add(String(id));
        try {
            const response = await window.FAMApi.request(api(`create.php?id=${encodeURIComponent(id)}&analyze_ai=1`), { method: 'POST', body: {} });
            const item = response.data?.item;
            if (item?.id) {
                state.rows = state.rows.map(row => String(row.id) === String(item.id) ? item : row);
                if (String(state.currentDetailsId || '') === String(item.id)) refreshDetails(item.id).catch(console.error);
            }
        } catch (error) {
            console.warn('Reservation AI summary unavailable:', error.message || error);
        } finally {
            state.aiJobs.delete(String(id));
        }
    }

    function detail(label, value, className = '') { return `<dl class="facility-detail-row ${esc(className)}"><dt>${esc(label)}</dt><dd>${esc(value || 'Not available')}</dd></dl>`; }
    function detailGrid(content) { return `<div class="detail-grid">${content}</div>`; }
    function requestLetterLinks(item) {
        if (!item.request_letter) return '<p>No request letter is attached to this reservation.</p>';
        const letter = item.request_letter || {};
        const meta = [
            letter.fileName,
            String(letter.extension || letter.mimeType || '').toUpperCase(),
            fileSize(letter.fileSize),
            letter.uploadedAt ? `Uploaded ${fmtDateTime(letter.uploadedAt)}` : ''
        ].filter(Boolean).join(' · ');
        return `<article class="document-file-row reservation-request-letter-row"><div><span class="material-symbols-outlined document-file-icon" aria-hidden="true">description</span><div><strong>Request Letter</strong>${meta ? `<small>${esc(meta)}</small>` : ''}</div></div><div class="document-file-actions"><a href="${esc(requestLetterUrl(item.id, 'view'))}" target="_blank" rel="noopener">View</a><a href="${esc(requestLetterUrl(item.id, 'download'))}" target="_blank" rel="noopener">Download</a></div></article>`;
    }
    function actionHelper(item) {
        if (item.allowed_actions?.check_in_not_yet) return 'Check-in will be available 30 minutes before your reservation.';
        if (item.allowed_actions?.check_in_ended) return 'The check-in period has ended.';
        return '';
    }
    function workflowActions(item) {
        const actions = [];
        const helper = actionHelper(item);
        if (item.allowed_actions?.check_in) actions.push(`<button class="btn-primary dashboard-action-button" type="button" data-check-in-reservation="${esc(item.id)}">Check In</button>`);
        if (item.allowed_actions?.check_out) actions.push(`<button class="btn-primary dashboard-action-button" type="button" data-check-out-reservation="${esc(item.id)}">Check Out</button>`);
        if (item.allowed_actions?.cancel) actions.push(`<button class="btn-secondary dashboard-action-button" type="button" data-cancel-reservation="${esc(item.id)}">Cancel Reservation</button>`);
        actions.push('<button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Done</button>');
        return `<div class="facility-dialog-actions employee-reservation-detail-footer">${helper ? `<p class="fam-muted employee-reservation-action-note">${esc(helper)}</p>` : '<span aria-hidden="true"></span>'}<div class="employee-reservation-footer-actions">${actions.join('')}</div></div>`;
    }
    function renderDetails(dialog, item) {
        const history = (item.history || []).map(row => `<li><strong>${esc(title(row.new_status))}</strong><span>${esc(fmtDateTime(row.changed_at))}</span>${row.change_reason ? `<p>${esc(row.change_reason)}</p>` : ''}</li>`).join('');
        const reason = item.lifecycle?.cancellation_reason || item.lifecycle?.remarks || '';
        const reservationDetails = detailGrid(`${detail('Reservation Number', item.reservationNo)}${detail('Status', statusLabel(item))}${detail('Approval Status', title(item.approval))}${detail('Expected Attendees', item.attendees)}${detail('Reservation Type', title(item.reservationType || 'MEETING'))}${String(reason).trim() ? detail('Cancellation / Rejection Reason', reason, 'detail-item--full') : ''}`);
        const scheduleRoom = detailGrid(`${detail('Room', item.room)}${detail('Building', item.building)}${detail('Date', fmtDate(item.start))}${detail('Start Time', fmtTime(item.start))}${detail('End Time', fmtTime(item.end))}${detail('Approved At', fmtDateTime(item.lifecycle?.approved_at))}${detail('Check In Time', fmtDateTime(item.lifecycle?.checked_in_at))}${detail('Check Out Time', fmtDateTime(item.lifecycle?.checked_out_at))}`);
        dialog.innerHTML = `<div class="facility-dialog-panel employee-request-details">
            <div class="facility-details-modal-header"><div><p>My Room Reservations</p><span class="facility-details-modal-request-number">${esc(item.reservationNo)}</span><h2>${esc(item.room || 'Reservation Details')}</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close reservation details">&times;</button></div>
            <div class="facility-details-modal-body"><div class="visitor-detail-accordion">
                <details class="visitor-detail-disclosure" open><summary>Reservation Details</summary>${reservationDetails}</details>
                <details class="visitor-detail-disclosure" open><summary>Schedule and Room</summary>${scheduleRoom}</details>
                <details class="visitor-detail-disclosure" open><summary>Request Letter</summary>${requestLetterLinks(item)}</details>
                <details class="visitor-detail-disclosure"><summary>Activity History</summary>${history ? `<ol class="facility-history-list visitor-activity-timeline">${history}</ol>` : '<p>No activity history recorded.</p>'}</details>
            </div></div>
            ${workflowActions(item)}
        </div>`;
    }
    async function openDetails(id) {
        const dialog = ensureDialog();
        state.lastFocus = document.activeElement;
        state.currentDetailsId = id;
        dialog.hidden = false;
        document.body.classList.add('fam-modal-open');
        dialog.innerHTML = `<div class="facility-dialog-panel"><div class="facility-details-modal-header"><div><p>My Room Reservations</p><h2>Loading reservation</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close reservation details">&times;</button></div><div class="facility-details-modal-body">${window.FAMEmployeePortal.emptyState('progress_activity', 'Loading reservation details', '')}</div></div>`;
        try {
            const response = await window.FAMApi.request(api(`show.php?id=${encodeURIComponent(id)}`));
            const item = response.data?.item;
            renderDetails(dialog, item);
            clearInterval(state.detailsTimer);
            state.detailsTimer = setInterval(() => {
                if (!state.currentDetailsId || document.hidden || qs('#employee-reservation-dialog')?.hidden) return;
                refreshDetails(state.currentDetailsId).catch(console.error);
            }, 60000);
        } catch (error) {
            dialog.innerHTML = `<div class="facility-dialog-panel"><div class="facility-details-modal-header"><div><p>My Room Reservations</p><h2>Reservation unavailable</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close reservation details">&times;</button></div><div class="facility-details-modal-body">${window.FAMEmployeePortal.emptyState('error', error.message || 'Unable to load reservation details.', '')}</div></div>`;
        }
    }

    async function refreshDetails(id) {
        const dialog = ensureDialog();
        const response = await window.FAMApi.request(api(`show.php?id=${encodeURIComponent(id)}`));
        renderDetails(dialog, response.data?.item || {});
    }

    async function checkInReservation(id) {
        const item = state.rows.find(row => String(row.id) === String(id));
        const label = item ? `${item.reservationNo} for ${item.room || 'this room'}` : 'this reservation';
        if (!await window.FAMModal.confirm(`You are checking in to ${label}.`, { title: 'Check In to Reservation?', confirmLabel: 'Check In' })) return;
        await window.FAMApi.request(api(`check-in.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: {} });
        await load();
        await refreshDetails(id);
        window.FAMModal?.showToast('Reservation checked in.');
    }

    async function checkOutReservation(id) {
        if (!await window.FAMModal.confirm('Checking out will mark this reservation as completed.', { title: 'End Room Usage?', cancelLabel: 'Continue Using Room', confirmLabel: 'Check Out' })) return;
        await window.FAMApi.request(api(`check-out.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: {} });
        await load();
        await refreshDetails(id);
        window.FAMModal?.showToast('Reservation completed.');
    }

    async function cancelReservation(id) {
        const reason = await window.FAMModal.prompt('Reason for cancelling this reservation:', 'Cancelled by requester.', { title: 'Cancel Reservation', confirmLabel: 'Cancel Reservation' });
        if (reason === null) return;
        await window.FAMApi.request(api(`cancel.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: { reason } });
        await load();
        if (state.currentDetailsId) await refreshDetails(id);
        else closeDialog();
        window.FAMModal?.showToast('Reservation cancelled.');
    }

    function bind() {
        qs('#employee-request-room')?.addEventListener('click', () => openForm());
        qs('#employee-availability-today')?.addEventListener('click', () => { state.availabilityAnchor = new Date(); loadAvailability().catch(console.error); });
        qs('#employee-availability-prev')?.addEventListener('click', () => { state.availabilityAnchor.setDate(state.availabilityAnchor.getDate() - 7); loadAvailability().catch(console.error); });
        qs('#employee-availability-next')?.addEventListener('click', () => { state.availabilityAnchor.setDate(state.availabilityAnchor.getDate() + 7); loadAvailability().catch(console.error); });
        qs('#employee-reservation-search')?.addEventListener('input', event => { state.search = event.target.value.trim(); clearTimeout(state.timer); state.timer = setTimeout(() => load().catch(console.error), 250); });
        qs('#employee-reservation-status')?.addEventListener('change', event => { state.status = event.target.value; load().catch(console.error); });
        document.addEventListener('input', event => {
            const form = event.target.closest('[data-reservation-form]');
            if (!form) return;
            if (event.target.name === 'facility_space_id') {
                const room = state.options?.facility_spaces?.find(item => String(item.id) === String(event.target.value));
                form.querySelector('[data-room-summary]').textContent = room ? roomMeta(room) : 'Select a facility to view capacity and location.';
                state.selectedRoom = event.target.value;
                sessionStorage.setItem('fam_employee_selected_room', state.selectedRoom);
                renderRoomCards();
                loadAvailability().catch(console.error);
            }
            clearTimeout(state.availabilityTimer);
            state.availabilityTimer = setTimeout(() => checkAvailability(form), 400);
        });
        document.addEventListener('click', event => {
            if (event.target.closest('[data-open-room-form]')) return openForm();
            const selectRoomButton = event.target.closest('[data-select-room]');
            if (selectRoomButton) return selectRoom(selectRoomButton.dataset.selectRoom);
            const requestRoomButton = event.target.closest('[data-request-room]');
            if (requestRoomButton) return selectRoom(requestRoomButton.dataset.requestRoom, true);
            if (event.target.closest('[data-close-dialog]') || event.target === qs('#employee-reservation-dialog')) return closeDialog();
            const menuToggle = event.target.closest('[data-employee-menu]');
            if (menuToggle) return window.FAMTableMenus?.toggle(menuToggle, qs(`[data-employee-menu-panel="${CSS.escape(menuToggle.dataset.employeeMenu)}"]`));
            const open = event.target.closest('[data-open-reservation]');
            if (open) return openDetails(open.dataset.openReservation);
            const checkIn = event.target.closest('[data-check-in-reservation]');
            if (checkIn) return checkInReservation(checkIn.dataset.checkInReservation).catch(error => window.FAMModal?.showToast(error.message || 'Unable to check in.'));
            const checkOut = event.target.closest('[data-check-out-reservation]');
            if (checkOut) return checkOutReservation(checkOut.dataset.checkOutReservation).catch(error => window.FAMModal?.showToast(error.message || 'Unable to check out.'));
            const cancel = event.target.closest('[data-cancel-reservation]');
            if (cancel) return cancelReservation(cancel.dataset.cancelReservation).catch(console.error);
            window.FAMTableMenus?.close();
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-reservation-form]');
            if (!form) return;
            event.preventDefault();
            submitForm(form);
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && !qs('#employee-reservation-dialog')?.hidden) closeDialog(); });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && state.currentDetailsId && !qs('#employee-reservation-dialog')?.hidden) refreshDetails(state.currentDetailsId).catch(console.error);
        });
    }

    async function init(event) {
        state.context = event.detail?.context || await window.FAMEmployeePortal.context();
        const options = await window.FAMApi.request(api('options.php'));
        state.options = options.data || {};
        const storedRoom = sessionStorage.getItem('fam_employee_selected_room');
        state.selectedRoom = storedRoom && (state.options.facility_spaces || []).some(room => String(room.id) === String(storedRoom))
            ? String(storedRoom)
            : '';
        const statusSelect = qs('#employee-reservation-status');
        if (statusSelect) statusSelect.innerHTML = '<option value="">All Statuses</option>' + (state.options.statuses || []).map(status => `<option value="${esc(status)}">${esc(title(status))}</option>`).join('');
        bind();
        renderRoomCards();
        await loadAvailability();
        await load();
        const reservationId = new URLSearchParams(window.location.search).get('reservation');
        if (reservationId && /^\d+$/.test(reservationId)) openDetails(reservationId);
    }

    document.addEventListener('fam:employee-layout-ready', event => init(event).catch(error => {
        console.error(error);
        qs('#employee-room-loading')?.classList.add('hidden');
        const body = qs('#employee-room-reservations-body');
        if (body) body.innerHTML = emptyRow('Unable to load room reservations', error.message || 'Please try again.');
    }));
})();
