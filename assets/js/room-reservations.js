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
    function reservationQueueStatus(event) {
        const status = String(event.status || '').toUpperCase();
        const approval = String(event.approval || '').toUpperCase();
        if (status === 'CHECKED_IN') return 'CHECKED_IN';
        if (status === 'COMPLETED') return 'CHECKED_OUT';
        if (status === 'REJECTED' || approval === 'REJECTED') return 'REJECTED';
        if (status === 'CANCELLED' || approval === 'CANCELLED') return 'CANCELLED';
        if (status === 'SUBMITTED' || approval === 'PENDING') return 'PENDING';
        if (status === 'APPROVED' || approval === 'APPROVED') return 'APPROVED';
        return status || approval || 'Not applicable';
    }
    const trunc = (value, className = 'table-cell-truncate') => `<span class="${className}" title="${esc(value || 'Not applicable')}">${esc(value || 'Not applicable')}</span>`;

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
        const value = `${event.approval || ''} ${event.status || ''}`.toLowerCase();
        if (value.includes('reject')) return 'danger';
        if (value.includes('cancel')) return 'muted';
        if (value.includes('pending') || value.includes('submit')) return 'warning';
        if (value.includes('check') || value.includes('active')) return 'info';
        if (value.includes('complete')) return 'info';
        if (value.includes('approve')) return 'success';
        return 'info';
    }

    function eventButton(event) {
        const time = fmtTime(event.start), room = event.room || 'Room unavailable', purpose = event.purpose || event.reservationNo || 'Reservation';
        const label = `${time} ${room} ${purpose} ${title(event.status)} ${title(event.approval)}`;
        return `<button class="reservation-event reservation-event-${tone(event)}" type="button" data-reservation-id="${esc(event.id)}" aria-label="${esc(label)}" title="${esc(label)}"><span class="reservation-event-time">${esc(time)}</span><strong>${esc(room)}</strong><span>${esc(purpose)}</span></button>`;
    }

    function days(start, end) {
        const output = [], d = new Date(start);
        while (d < end) { output.push(new Date(d)); d.setDate(d.getDate() + 1); }
        return output;
    }

    function renderCalendar() {
        const panel = qs('reservation-calendar-panel'), r = range(), grouped = new Map();
        const visible = state.status === 'all'
            ? state.events.filter(event => ['SUBMITTED','APPROVED','CHECKED_IN','COMPLETED'].includes(String(event.status || '').toUpperCase()))
            : state.events;
        renderToolbar();
        visible.forEach(event => {
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
                    return `<section class="reservation-day-cell ${day.getMonth() !== state.anchor.getMonth() ? 'muted' : ''} ${sameDay(day, new Date()) ? 'today' : ''}" aria-label="${esc(day.toLocaleDateString())}"><div class="reservation-day-number">${day.getDate()}</div><div class="reservation-day-events">${items.slice(0, 3).map(item => eventButton(item)).join('')}${items.length > 3 ? `<span class="reservation-more-events">${items.length - 3} more</span>` : ''}</div></section>`;
                }).join('')}</div>`;
        } else {
            panel.innerHTML = `<div class="reservation-agenda">${days(r.start, r.end).map(day => {
                const items = grouped.get(isoDate(day)) || [];
                return `<section class="reservation-agenda-day ${sameDay(day, new Date()) ? 'today' : ''}"><header><span>${esc(day.toLocaleDateString(undefined, { weekday: 'short' }))}</span><strong>${esc(day.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))}</strong></header><div>${items.length ? items.map(item => eventButton(item)).join('') : '<p>No reservations scheduled.</p>'}</div></section>`;
            }).join('')}</div>`;
        }
        qs('reservation-calendar-message').textContent = visible.length ? `${visible.length} operational reservation${visible.length === 1 ? '' : 's'} scheduled for this period.` : 'No operational reservations scheduled for this period.';
    }

    function focusRow(event) {
        const room = event.room || 'Room unavailable';
        const purpose = event.purpose || event.reservationNo || 'Reservation';
        return `<button class="reservation-focus-row reservation-event-${tone(event)}" type="button" data-reservation-id="${esc(event.id)}"><span>${esc(fmtTime(event.start))}-${esc(fmtTime(event.end))}</span><strong>${esc(room)}</strong><small>${esc(purpose)}</small>${badge(reservationQueueStatus(event))}</button>`;
    }

    function renderFocusPanels() {
        const now = new Date(), ordered = [...state.events].sort((a, b) => (toDate(a.start) || 0) - (toDate(b.start) || 0));
        const today = ordered.filter(item => sameDay(toDate(item.start), now)).slice(0, 5);
        const upcoming = ordered.filter(item => (toDate(item.start) || 0) >= now).slice(0, 5);
        const pending = ordered.filter(item => `${item.approval || ''} ${item.status || ''}`.toLowerCase().match(/pending|submit/)).slice(0, 5);
        const recent = [...ordered].reverse().filter(item => `${item.approval || ''} ${item.status || ''}`.toLowerCase().match(/reject|cancel/)).slice(0, 5);
        const todayList = qs('reservation-today-list');
        const upcomingList = qs('reservation-upcoming-list');
        const pendingList = qs('reservation-pending-list');
        const recentList = qs('reservation-recent-list');
        if (todayList) todayList.innerHTML = today.length ? today.map(focusRow).join('') : '<p>No reservations scheduled for today.</p>';
        if (upcomingList) upcomingList.innerHTML = upcoming.length ? upcoming.map(focusRow).join('') : '<p>No upcoming reservations in this period.</p>';
        if (pendingList) pendingList.innerHTML = pending.length ? pending.map(focusRow).join('') : '<p>No reservations pending approval.</p>';
        if (recentList) recentList.innerHTML = recent.length ? recent.map(focusRow).join('') : '<p>No rejected or cancelled reservations in this period.</p>';
    }

    function renderList() {
        const body = qs('reservation-table');
        if (!body) return;
        qs('reservation-loading-state')?.classList.add('hidden');
        const empty = qs('reservation-empty-state'), pager = document.querySelector('.reservation-workspace .facility-pagination');
        window.FAMTableAudit?.check(body?.closest('table'), 'reservation-table');
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
        body.innerHTML = state.rows.map(row => {
            const actions = `<button type="button" role="menuitem" data-open-reservation-details="${esc(row.id)}">View Details</button>`;
            return `<tr><td class="facility-request-number">${trunc(row.reservationNo, 'table-cell-primary')}</td><td><div class="facility-subject-cell table-cell-stack">${trunc(row.purpose || 'Reservation', 'table-cell-primary')}${trunc(`${row.attendees || 0} attendees`, 'table-cell-secondary')}</div></td><td>${trunc(row.room || 'Not assigned')}</td><td class="facility-date-cell">${trunc(fmtDateTime(row.start))}</td><td>${badge(row.status)}</td><td class="facility-actions-cell"><div class="facility-action-menu"><button class="facility-action-toggle" type="button" data-reservation-menu="${esc(row.id)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(row.reservationNo)}">&#8942;</button><div class="facility-action-dropdown hidden" data-reservation-menu-panel="${esc(row.id)}" role="menu">${actions}</div></div></td></tr>`;
        }).join('');
        window.FAMTableAudit?.check(body.closest('table'), 'reservation-table');
    }

    function detail(label, value) { return `<dl class="facility-detail-row"><dt>${esc(label)}</dt><dd>${esc(value || 'Not applicable')}</dd></dl>`; }
    function lines(items, empty) { return items?.length ? `<ul class="facility-detail-list">${items.map(item => `<li>${esc(item)}</li>`).join('')}</ul>` : `<p>${esc(empty)}</p>`; }
    function drawerShell(message, retryId) {
        return `<div class="facility-details-modal-panel"><div class="facility-details-modal-header"><div><p>Room Reservation</p><span class="facility-details-modal-request-number">Details</span><h2 id="reservation-drawer-title">Reservation Details</h2></div><button class="facility-details-modal-close" type="button" data-close-reservation-drawer aria-label="Close reservation details">&times;</button></div><div class="facility-details-modal-body" aria-live="polite"><div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>${esc(message)}</span>${retryId ? `<button class="facility-text-button" type="button" data-reservation-retry="${esc(retryId)}">Retry</button>` : ''}</div></div></div>`;
    }
    function drawerDetails(item) {
        const participants = (item.participants || []).map(p => `${p.full_name || 'Participant'} - ${title(p.participant_role || 'participant')} (${title(p.attendance_status || 'pending')})`);
        const history = (item.history || []).map(h => `${title(h.old_status || 'Created')} to ${title(h.new_status)} - ${fmtDateTime(h.changed_at)}${h.change_reason ? ` - ${h.change_reason}` : ''}`);
        const actions = adminActions(item);
        return `<div class="facility-details-modal-panel"><div class="facility-details-modal-header"><div><p>Room Reservation</p><span class="facility-details-modal-request-number">${esc(item.reservationNo || 'Reservation')}</span><h2 id="reservation-drawer-title">${esc(item.purpose || 'Reservation Details')}</h2></div><button class="facility-details-modal-close" type="button" data-close-reservation-drawer aria-label="Close reservation details">&times;</button></div><div class="facility-details-modal-body"><div class="facility-detail-grid"><section><h3>General Information</h3>${detail('Reservation No.', item.reservationNo)}${detail('Status', title(item.status))}${detail('Approval Status', title(item.approval))}${detail('Purpose', item.purpose)}</section><section><h3>Schedule</h3>${detail('Start', fmtDateTime(item.start))}${detail('End', fmtDateTime(item.end))}${detail('Attendee Count', item.attendees)}</section><section><h3>Room Information</h3>${detail('Facility Space', item.room)}${detail('Building', item.building)}${detail('Floor', item.floor)}${detail('Room Type', item.roomType)}${detail('Capacity', item.capacity)}</section><section><h3>Requester</h3>${detail('Name', item.requester)}${detail('Employee No.', item.employeeNumber)}${detail('Department', item.department)}</section><section><h3>Setup Requirements</h3>${detail('Setup Requirements', item.lifecycle?.setup_requirements)}${detail('Setup Buffer', `${item.lifecycle?.setup_buffer_minutes || 0} min`)}${detail('Cleanup Buffer', `${item.lifecycle?.cleanup_buffer_minutes || 0} min`)}</section><section><h3>Attendance</h3>${detail('Checked In', fmtDateTime(item.lifecycle?.checked_in_at))}${detail('Checked Out', fmtDateTime(item.lifecycle?.checked_out_at))}</section><section><h3>Participants</h3>${lines(participants, 'No participants recorded.')}</section><section class="facility-detail-wide"><h3>History and Remarks</h3>${lines(history, 'No reservation history recorded.')}</section></div></div>${actions ? `<div class="facility-dialog-actions">${actions}</div>` : ''}</div>`;
    }
    function adminActions(item) {
        const status = String(item.status || '').toUpperCase();
        const buttons = [];
        if (status === 'SUBMITTED') {
            buttons.push(`<button class="btn-primary dashboard-action-button" type="button" data-reservation-action="approve" data-action-id="${esc(item.id)}">Approve</button>`);
            buttons.push(`<button class="btn-secondary dashboard-action-button" type="button" data-reservation-action="reject" data-action-id="${esc(item.id)}">Reject</button>`);
        }
        if (['SUBMITTED','APPROVED'].includes(status)) buttons.push(`<button class="btn-secondary dashboard-action-button" type="button" data-reservation-action="cancel" data-action-id="${esc(item.id)}">Cancel Reservation</button>`);
        if (status === 'APPROVED' && String(item.approval || '').toUpperCase() === 'APPROVED' && !item.lifecycle?.checked_in_at && toDate(item.end) < new Date()) {
            buttons.push(`<button class="btn-secondary dashboard-action-button" type="button" data-reservation-action="no-show" data-action-id="${esc(item.id)}">Mark No Show</button>`);
        }
        return buttons.join('');
    }
    async function openDetails(id) {
        const drawer = qs('reservation-details-drawer');
        if (drawer.parentElement !== document.body) document.body.appendChild(drawer);
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
        window.FAMModal?.closeElement?.(drawer, () => {
            drawer.innerHTML = '';
            document.body.classList.remove('facility-details-modal-open');
            state.lastFocus?.focus?.();
            state.lastFocus = null;
        });
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
        if (!qs('reservation-table')) return;
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
    async function refreshAll() { renderToolbar(); await loadCalendar(); }
    async function processReservation(action, id) {
        const body = {};
        if (['reject','cancel','no-show'].includes(action)) {
            const labels = {
                reject: ['Reason for rejecting this reservation:', 'Reservation rejected.', 'Reject Reservation', 'Reject'],
                cancel: ['Reason for cancelling this reservation:', 'Reservation cancelled.', 'Cancel Reservation', 'Cancel Reservation'],
                'no-show': ['Reason for marking this reservation as no-show:', 'Reservation marked as no-show.', 'Mark No Show', 'Mark No Show'],
            }[action];
            const reason = await window.FAMModal.prompt(labels[0], labels[1], { title: labels[2], confirmLabel: labels[3] });
            if (reason === null) return;
            body.reason = reason;
        }
        if (action === 'approve' && !await window.FAMModal.confirm('Approve this room reservation?', { title: 'Approve Reservation', confirmLabel: 'Approve' })) return;
        await window.FAMApi.request(`../api/reservations/${action}.php?id=${encodeURIComponent(id)}`, { method: 'POST', body });
        state.details.delete(String(id));
        await refreshAll();
        await openDetails(id);
        window.FAMModal?.showToast('Reservation updated.');
    }
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
        if (qs('reservation-search')) qs('reservation-search').value = '';
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
        const menuToggle = event.target.closest('[data-reservation-menu]');
        if (menuToggle) {
            event.preventDefault();
            event.stopPropagation();
            return window.FAMTableMenus?.toggle(menuToggle, document.querySelector(`[data-reservation-menu-panel="${CSS.escape(menuToggle.dataset.reservationMenu)}"]`));
        }
        const detailAction = event.target.closest('[data-open-reservation-details]');
        if (detailAction) return openDetails(detailAction.dataset.openReservationDetails);
        const open = event.target.closest('[data-reservation-id]');
        if (open) return openDetails(open.dataset.reservationId);
        const action = event.target.closest('[data-reservation-action]');
        if (action) return processReservation(action.dataset.reservationAction, action.dataset.actionId).catch(error => window.FAMModal?.showToast(error.message || 'Unable to update reservation.'));
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
    document.addEventListener('fam:layout-ready', () => {
        const reservationId = new URLSearchParams(window.location.search).get('reservation');
        if (reservationId && /^\d+$/.test(reservationId)) setTimeout(() => openDetails(reservationId), 500);
    });
})();
