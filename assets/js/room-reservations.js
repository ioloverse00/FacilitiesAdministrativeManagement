(function () {
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const title = value => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const qs = id => document.getElementById(id);
    const pad = n => String(n).padStart(2, '0');
    const isoDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const toDate = value => value ? new Date(String(value).replace(' ', 'T')) : null;
    const sameDay = (a, b) => a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    const state = { view: window.matchMedia('(max-width: 520px)').matches ? 'day' : 'month', anchor: new Date(), room: 'all', status: 'all', search: '', page: 1, perPage: 10, sort: 'start_datetime', direction: 'asc', events: [], rows: [], pagination: { total: 0, total_pages: 1 }, options: {}, details: new Map() };
    const fmtTime = value => { const d = value instanceof Date ? value : toDate(value); return d && !Number.isNaN(d.getTime()) ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : 'Time unavailable'; };
    const fmtDateTime = value => { const d = toDate(value); return d && !Number.isNaN(d.getTime()) ? d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Not applicable'; };
    const badge = value => `<span class="facility-badge facility-status-${String(value || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-')}">${esc(title(value || 'Not applicable'))}</span>`;

    function range() {
        const a = new Date(state.anchor);
        if (state.view === 'day') {
            const start = new Date(a.getFullYear(), a.getMonth(), a.getDate()), end = new Date(a.getFullYear(), a.getMonth(), a.getDate() + 1);
            return { start, end, label: start.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) };
        }
        if (state.view === 'week') {
            const start = new Date(a.getFullYear(), a.getMonth(), a.getDate() - a.getDay()), end = new Date(a.getFullYear(), a.getMonth(), a.getDate() - a.getDay() + 7), labelEnd = new Date(end);
            labelEnd.setDate(labelEnd.getDate() - 1);
            return { start, end, label: `${start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} - ${labelEnd.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}` };
        }
        const start = new Date(a.getFullYear(), a.getMonth(), 1);
        start.setDate(start.getDate() - start.getDay());
        const end = new Date(start);
        end.setDate(start.getDate() + 42);
        return { start, end, label: a.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) };
    }

    function query(base = {}) {
        const p = new URLSearchParams(base);
        if (state.room !== 'all') p.set('facility_space_id', state.room);
        if (state.status !== 'all') p.set('status', state.status);
        if (state.search.trim()) p.set('search', state.search.trim());
        return p;
    }

    function renderToolbar() {
        qs('reservation-calendar-title').textContent = range().label;
        document.querySelectorAll('[data-calendar-view]').forEach(button => {
            const active = button.dataset.calendarView === state.view;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        qs('reservation-reset-filters')?.classList.toggle('hidden', state.room === 'all' && state.status === 'all' && !state.search.trim());
    }

    function populateFilters() {
        qs('reservation-room-filter').innerHTML = '<option value="all">All rooms</option>' + (state.options.facility_spaces || []).map(x => `<option value="${esc(x.id)}">${esc(x.name || x.code || 'Room')}</option>`).join('');
        qs('reservation-status-filter').innerHTML = '<option value="all">All statuses</option>' + (state.options.statuses || []).map(x => `<option value="${esc(x)}">${esc(title(x))}</option>`).join('');
    }

    function tone(event) {
        const value = String(event.approval || event.status || '').toLowerCase();
        if (value.includes('reject')) return 'danger';
        if (value.includes('cancel')) return 'muted';
        if (value.includes('pending')) return 'warning';
        if (value.includes('check') || value.includes('active')) return 'info';
        if (value.includes('complete')) return 'neutral';
        if (value.includes('approve')) return 'success';
        return 'info';
    }

    function eventButton(event, compact = false) {
        const time = fmtTime(event.start), room = event.room || 'Room unavailable', purpose = event.purpose || event.reservationNo || 'Reservation';
        const label = `${time} ${room} ${purpose} ${title(event.status)} ${title(event.approval)}`;
        return `<button class="reservation-event reservation-event-${tone(event)}" type="button" data-reservation-id="${esc(event.id)}" aria-label="${esc(label)}" title="${esc(label)}"><span class="reservation-event-time">${esc(time)}</span><strong>${esc(compact ? room : purpose)}</strong><span>${esc(compact ? purpose : room)}</span></button>`;
    }

    function days(start, end) {
        const output = [], d = new Date(start);
        while (d < end) { output.push(new Date(d)); d.setDate(d.getDate() + 1); }
        return output;
    }

    function renderCalendar() {
        const panel = qs('reservation-calendar-panel'), r = range(), grouped = new Map();
        renderToolbar();
        state.events.forEach(event => {
            const start = toDate(event.start);
            if (!start) return;
            const key = isoDate(start);
            grouped.set(key, [...(grouped.get(key) || []), event]);
        });
        panel.className = `reservation-calendar reservation-calendar-${state.view}`;
        if (state.view === 'month') {
            panel.innerHTML = '<div class="reservation-calendar-weekdays">' + ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(day => `<span>${day}</span>`).join('') + '</div>' +
                `<div class="reservation-month-grid">${days(r.start, r.end).map(day => {
                    const items = grouped.get(isoDate(day)) || [];
                    return `<section class="reservation-day-cell ${day.getMonth() !== state.anchor.getMonth() ? 'muted' : ''} ${sameDay(day, new Date()) ? 'today' : ''}" aria-label="${esc(day.toLocaleDateString())}"><div class="reservation-day-number">${day.getDate()}</div><div class="reservation-day-events">${items.slice(0, 3).map(item => eventButton(item, true)).join('')}${items.length > 3 ? `<span class="reservation-more-events">${items.length - 3} more</span>` : ''}</div></section>`;
                }).join('')}</div>`;
        } else {
            panel.innerHTML = `<div class="reservation-agenda">${days(r.start, r.end).map(day => {
                const items = grouped.get(isoDate(day)) || [];
                return `<section class="reservation-agenda-day ${sameDay(day, new Date()) ? 'today' : ''}"><header><span>${esc(day.toLocaleDateString(undefined, { weekday: 'short' }))}</span><strong>${esc(day.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))}</strong></header><div>${items.length ? items.map(item => eventButton(item)).join('') : '<p>No reservations scheduled.</p>'}</div></section>`;
            }).join('')}</div>`;
        }
        qs('reservation-calendar-message').textContent = state.events.length ? `${state.events.length} reservation${state.events.length === 1 ? '' : 's'} scheduled for this period.` : 'No reservations scheduled for this period.';
    }

    function focusRow(event) {
        return `<button class="reservation-focus-row" type="button" data-reservation-id="${esc(event.id)}"><span>${esc(fmtTime(event.start))}-${esc(fmtTime(event.end))}</span><strong>${esc(event.room || event.purpose || event.reservationNo || 'Reservation')}</strong>${badge(event.approval || event.status)}</button>`;
    }

    function renderFocusPanels() {
        const now = new Date(), ordered = [...state.events].sort((a, b) => (toDate(a.start) || 0) - (toDate(b.start) || 0));
        const today = ordered.filter(item => sameDay(toDate(item.start), now)).slice(0, 5);
        const upcoming = ordered.filter(item => (toDate(item.start) || 0) >= now).slice(0, 5);
        const pending = ordered.filter(item => String(item.approval || '').toLowerCase().includes('pending')).slice(0, 5);
        qs('reservation-today-list').innerHTML = today.length ? today.map(focusRow).join('') : '<p>No reservations scheduled for today.</p>';
        qs('reservation-upcoming-list').innerHTML = upcoming.length ? upcoming.map(focusRow).join('') : '<p>No upcoming reservations in this period.</p>';
        qs('reservation-pending-list').innerHTML = pending.length ? pending.map(focusRow).join('') : '<p>No reservations pending approval.</p>';
    }

    function renderList() {
        qs('reservation-loading-state')?.classList.add('hidden');
        const body = qs('reservation-table'), empty = qs('reservation-empty-state'), pager = document.querySelector('.reservation-workspace .facility-pagination');
        qs('reservation-table-count').textContent = state.pagination.total ? `Showing ${state.rows.length} of ${state.pagination.total} reservation records` : 'No reservation records';
        if (!state.rows.length) {
            body.innerHTML = '';
            empty.classList.remove('hidden');
            pager.classList.add('hidden');
            empty.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">event_busy</span><strong>No reservation requests found.</strong><span>Reservations will appear here when they exist in the selected period.</span>';
            return;
        }
        empty.classList.add('hidden');
        pager.classList.remove('hidden');
        const pages = Math.max(1, Number(state.pagination.total_pages || 1));
        qs('reservation-page-status').textContent = `Page ${state.page} of ${pages}`;
        qs('reservation-prev-page').disabled = state.page <= 1;
        qs('reservation-next-page').disabled = state.page >= pages;
        body.innerHTML = state.rows.map(row => `<tr><td class="facility-request-number"><strong>${esc(row.reservationNo)}</strong></td><td><div class="facility-subject-cell"><strong>${esc(row.purpose || 'Reservation')}</strong><span>${esc(row.attendees)} attendees</span></div></td><td>${esc(row.room || 'Not assigned')}</td><td class="facility-date-cell">${esc(fmtDateTime(row.start))}</td><td class="facility-date-cell">${esc(fmtDateTime(row.end))}</td><td>${esc(row.requester || 'Not available')}</td><td>${badge(row.approval)}</td><td>${badge(row.status)}</td><td class="facility-actions-cell"><button class="facility-action-toggle" type="button" data-reservation-id="${esc(row.id)}">View Details</button></td></tr>`).join('');
    }

    function detail(label, value) { return `<dl class="facility-detail-row"><dt>${esc(label)}</dt><dd>${esc(value || 'Not applicable')}</dd></dl>`; }
    function lines(items, empty) { return items?.length ? `<ul class="facility-detail-list">${items.map(item => `<li>${esc(item)}</li>`).join('')}</ul>` : `<p>${esc(empty)}</p>`; }
    function drawerShell(message, retryId) {
        return `<div class="facility-details-modal-panel"><div class="facility-details-modal-header"><div><p>Room Reservation</p><span class="facility-details-modal-request-number">Details</span><h2 id="reservation-drawer-title">Reservation Details</h2></div><button class="facility-details-modal-close" type="button" data-close-reservation-drawer aria-label="Close reservation details">&times;</button></div><div class="facility-details-modal-body" aria-live="polite"><div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>${esc(message)}</span>${retryId ? `<button class="facility-text-button" type="button" data-reservation-retry="${esc(retryId)}">Retry</button>` : ''}</div></div></div>`;
    }
    function drawerDetails(item) {
        const participants = (item.participants || []).map(p => `${p.full_name || 'Participant'} - ${title(p.participant_role || 'participant')} (${title(p.attendance_status || 'pending')})`);
        const history = (item.history || []).map(h => `${title(h.old_status || 'Created')} to ${title(h.new_status)} - ${fmtDateTime(h.changed_at)}${h.change_reason ? ` - ${h.change_reason}` : ''}`);
        return `<div class="facility-details-modal-panel"><div class="facility-details-modal-header"><div><p>Room Reservation</p><span class="facility-details-modal-request-number">${esc(item.reservationNo || 'Reservation')}</span><h2 id="reservation-drawer-title">${esc(item.purpose || 'Reservation Details')}</h2></div><button class="facility-details-modal-close" type="button" data-close-reservation-drawer aria-label="Close reservation details">&times;</button></div><div class="facility-details-modal-body"><div class="facility-detail-grid"><section><h3>General Information</h3>${detail('Reservation No.', item.reservationNo)}${detail('Status', title(item.status))}${detail('Approval Status', title(item.approval))}${detail('Purpose', item.purpose)}</section><section><h3>Schedule</h3>${detail('Start', fmtDateTime(item.start))}${detail('End', fmtDateTime(item.end))}${detail('Attendee Count', item.attendees)}</section><section><h3>Room Information</h3>${detail('Facility Space', item.room)}${detail('Building', item.building)}${detail('Floor', item.floor)}${detail('Room Type', item.roomType)}</section><section><h3>Requester</h3>${detail('Name', item.requester)}${detail('Employee No.', item.employeeNumber)}${detail('Department', item.department)}</section><section><h3>Setup Requirements</h3><p>Review setup, cleanup, amenity, and conflict notes in the source reservation workflow.</p></section><section><h3>Participants</h3>${lines(participants, 'No participants recorded.')}</section><section class="facility-detail-wide"><h3>Approval Information</h3>${detail('Approval Status', title(item.approval))}</section><section class="facility-detail-wide"><h3>History and Remarks</h3>${lines(history, 'No reservation history recorded.')}</section></div></div></div>`;
    }
    async function openDetails(id) {
        const drawer = qs('reservation-details-drawer');
        state.lastFocus = document.activeElement;
        drawer.hidden = false;
        drawer.className = 'facility-details-modal';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.setAttribute('aria-labelledby', 'reservation-drawer-title');
        document.body.classList.add('facility-details-modal-open');
        drawer.innerHTML = drawerShell('Loading reservation details...');
        drawer.querySelector('[data-close-reservation-drawer]')?.focus();
        try {
            if (!state.details.has(String(id))) {
                const payload = await window.FAMApi.request(`../api/reservations/show.php?id=${encodeURIComponent(id)}`);
                state.details.set(String(id), payload.data?.item || {});
            }
            drawer.innerHTML = drawerDetails(state.details.get(String(id)));
            drawer.querySelector('[data-close-reservation-drawer]')?.focus();
        } catch (error) {
            drawer.innerHTML = drawerShell(error.message || 'Unable to load reservation details.', id);
        }
    }
    function closeDetails() {
        const drawer = qs('reservation-details-drawer');
        drawer.hidden = true;
        drawer.innerHTML = '';
        document.body.classList.remove('facility-details-modal-open');
        state.lastFocus?.focus?.();
        state.lastFocus = null;
    }
    function trapDetailsFocus(event) {
        const drawer = qs('reservation-details-drawer');
        if (drawer?.hidden || event.key !== 'Tab') return;
        const focusable = Array.from(drawer.querySelectorAll('button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter(el => !el.disabled && el.offsetParent !== null);
        if (!focusable.length) return;
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    async function loadCalendar() {
        const r = range();
        qs('reservation-calendar-loading')?.classList.remove('hidden');
        try {
            const payload = await window.FAMApi.request(`../api/reservations/calendar.php?${query({ date_from: isoDate(r.start), date_to: isoDate(r.end) })}`);
            state.events = payload.data?.items || [];
            renderCalendar();
            renderFocusPanels();
            qs('reservation-updated').textContent = `Last updated: ${new Date().toLocaleString()}`;
        } catch (error) {
            state.events = [];
            renderCalendar();
            renderFocusPanels();
            qs('reservation-calendar-message').innerHTML = `<span role="alert">${esc(error.message || 'Unable to load reservation calendar.')}</span> <button class="facility-text-button" type="button" data-calendar-retry>Retry</button>`;
        } finally {
            qs('reservation-calendar-loading')?.classList.add('hidden');
        }
    }
    async function loadList() {
        const r = range();
        qs('reservation-loading-state')?.classList.remove('hidden');
        try {
            const payload = await window.FAMApi.request(`../api/reservations/index.php?${query({ page: state.page, per_page: state.perPage, sort: state.sort, direction: state.direction, date_from: isoDate(r.start), date_to: isoDate(r.end) })}`);
            state.rows = payload.data?.items || [];
            state.pagination = payload.data?.pagination || state.pagination;
        } catch (error) {
            state.rows = [];
            state.pagination = { total: 0, total_pages: 1 };
            window.FAMModal?.showToast(error.message || 'Unable to load reservation list.');
        } finally {
            renderList();
        }
    }
    function refreshAll() { renderToolbar(); loadCalendar(); loadList(); }
    function shift(amount) {
        if (state.view === 'month') state.anchor.setMonth(state.anchor.getMonth() + amount);
        if (state.view === 'week') state.anchor.setDate(state.anchor.getDate() + amount * 7);
        if (state.view === 'day') state.anchor.setDate(state.anchor.getDate() + amount);
        state.page = 1;
        refreshAll();
    }
    async function init() {
        renderToolbar();
        try {
            await window.FAMApi.me();
            const options = await window.FAMApi.request('../api/reservations/options.php');
            state.options = options.data || {};
            populateFilters();
            refreshAll();
        } catch (error) {
            if (error.status === 401) {
                window.location.href = window.FAMApi.pageLoginUrl();
                return;
            }
            qs('reservation-calendar-loading')?.classList.add('hidden');
            qs('reservation-loading-state')?.classList.add('hidden');
            qs('reservation-calendar-message').textContent = error.message || 'Unable to initialize reservations.';
            renderList();
        }
    }

    document.addEventListener('fam:layout-ready', init);
    qs('reservation-refresh')?.addEventListener('click', refreshAll);
    qs('reservation-export')?.addEventListener('click', () => window.FAMModal?.showToast('Reservation export will use the current calendar/list filters when enabled.'));
    qs('reservation-today')?.addEventListener('click', () => { state.anchor = new Date(); state.page = 1; refreshAll(); });
    qs('reservation-prev-period')?.addEventListener('click', () => shift(-1));
    qs('reservation-next-period')?.addEventListener('click', () => shift(1));
    document.querySelectorAll('[data-calendar-view]').forEach(button => button.addEventListener('click', () => { state.view = button.dataset.calendarView; state.page = 1; refreshAll(); }));
    qs('reservation-room-filter')?.addEventListener('change', event => { state.room = event.target.value; state.page = 1; refreshAll(); });
    qs('reservation-status-filter')?.addEventListener('change', event => { state.status = event.target.value; state.page = 1; refreshAll(); });
    qs('reservation-search')?.addEventListener('input', event => { state.search = event.target.value; state.page = 1; clearTimeout(state.timer); state.timer = setTimeout(refreshAll, 300); renderToolbar(); });
    qs('reservation-reset-filters')?.addEventListener('click', () => {
        state.room = 'all';
        state.status = 'all';
        state.search = '';
        state.page = 1;
        qs('reservation-room-filter').value = 'all';
        qs('reservation-status-filter').value = 'all';
        qs('reservation-search').value = '';
        refreshAll();
    });
    qs('reservation-prev-page')?.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); loadList(); });
    qs('reservation-next-page')?.addEventListener('click', () => { state.page += 1; loadList(); });
    document.querySelector('.reservation-table')?.addEventListener('click', event => {
        const sort = event.target.closest('[data-sort]');
        if (!sort) return;
        state.direction = state.sort === sort.dataset.sort && state.direction === 'asc' ? 'desc' : 'asc';
        state.sort = sort.dataset.sort;
        state.page = 1;
        loadList();
    });
    document.addEventListener('click', event => {
        const open = event.target.closest('[data-reservation-id]');
        if (open) return openDetails(open.dataset.reservationId);
        if (event.target.closest('[data-close-reservation-drawer]')) return closeDetails();
        if (event.target === qs('reservation-details-drawer')) return closeDetails();
        const retry = event.target.closest('[data-reservation-retry]');
        if (retry) return openDetails(retry.dataset.reservationRetry);
        if (event.target.closest('[data-calendar-retry]')) return loadCalendar();
    });
    document.addEventListener('keydown', event => {
        trapDetailsFocus(event);
        if (event.key === 'Escape' && !qs('reservation-details-drawer')?.hidden) closeDetails();
    });
})();
