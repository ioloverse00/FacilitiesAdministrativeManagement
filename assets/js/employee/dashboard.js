(function () {
    const fmtCount = value => Number(value || 0).toLocaleString();

    function renderCards(counts) {
        const target = document.getElementById('employee-summary-cards');
        if (!target) return;
        const cards = [
            [counts.pending_tasks, `Pending Task${Number(counts.pending_tasks || 0) === 1 ? '' : 's'}`],
            [counts.upcoming_reservations, 'Upcoming Reservations'],
            [counts.pending_reservations, 'Pending Reservations']
        ];
        target.innerHTML = cards.map(([value, label]) => `
            <span><strong>${fmtCount(value)}</strong> ${esc(label)}</span>
        `).join('');
        const badge = document.getElementById('notification-unread-count');
        const unread = Number(counts.unread_notifications || 0);
        if (badge) {
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.classList.toggle('hidden', unread === 0);
        }
    }

    function attentionCard(icon, titleText, copy, href, actionText = 'Open') {
        return `
            <a class="fam-card employee-attention-card" href="${esc(href)}">
                <span class="material-symbols-outlined" aria-hidden="true">${esc(icon)}</span>
                <span>
                    <strong>${esc(titleText)}</strong>
                    <small>${esc(copy)}</small>
                </span>
                <span class="employee-attention-action">${esc(actionText)}</span>
            </a>
        `;
    }

    function taskLabel(item) {
        if (item.module === 'contract_management' && item.entity_type === 'contract') return 'Approve Contract';
        return title(item.title || 'Assigned Task');
    }

    function taskCopy(item) {
        return [item.contract?.number || item.entity_reference, item.step_name].filter(Boolean).join(' - ');
    }

    function renderAttention(counts, reservation, tasks = []) {
        const target = document.getElementById('employee-attention-list');
        if (!target) return;
        const items = [];
        if (tasks.length) {
            const first = tasks[0];
            items.push(attentionCard(
                'task_alt',
                taskLabel(first),
                taskCopy(first),
                window.FAMEmployeePortal.routeHref('employee-tasks', { task: first.task_id }),
                'Review Task'
            ));
        }
        if (reservation?.allowed_actions?.check_out) {
            items.push(attentionCard('logout', 'Room is checked in', `${reservation.room || 'Room reservation'} is active.`, window.FAMEmployeePortal.routeHref('employee-room-reservations', { reservation: reservation.id }), 'Check Out'));
        } else if (reservation?.allowed_actions?.check_in) {
            items.push(attentionCard('how_to_reg', 'Check-in is open', `${reservation.room || 'Room reservation'} is ready for check-in.`, window.FAMEmployeePortal.routeHref('employee-room-reservations', { reservation: reservation.id }), 'Check In'));
        }
        if (Number(counts.pending_reservations || 0) > 0) {
            items.push(attentionCard('pending_actions', `${fmtCount(counts.pending_reservations)} pending room request${Number(counts.pending_reservations) === 1 ? '' : 's'}`, 'Waiting for admin review.', window.FAMEmployeePortal.routeHref('employee-room-reservations')));
        }
        if (Number(counts.unread_notifications || 0) > 0) {
            items.push(attentionCard('notifications', `${fmtCount(counts.unread_notifications)} unread update${Number(counts.unread_notifications) === 1 ? '' : 's'}`, 'Review recent request and reservation changes.', window.FAMEmployeePortal.routeHref('employee-notifications'), 'View'));
        }
        target.innerHTML = items.length
            ? items.slice(0, 4).join('')
            : window.FAMEmployeePortal.emptyState('task_alt', 'All clear', 'Nothing needs your attention right now.');
    }

    function fmt(value) {
        if (!value) return '';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function toDate(value) {
        if (value instanceof Date) return value;
        return value ? new Date(String(value).replace(' ', 'T')) : null;
    }

    function fmtDate(value) {
        const date = toDate(value);
        return date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : 'Date unavailable';
    }

    function fmtTime(value) {
        const date = toDate(value);
        return date && !Number.isNaN(date.getTime()) ? date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : 'Time unavailable';
    }

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
    }

    function reservationLabel(item) {
        const status = String(item.status || '').toUpperCase();
        const approval = String(item.approval || '').toUpperCase();
        if (status === 'SUBMITTED' && approval === 'PENDING') return 'Pending Approval';
        return title(status || approval || 'Reservation');
    }

    function reservationBadge(item) {
        const raw = String(item.status || item.approval || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-status-${raw}">${esc(reservationLabel(item))}</span>`;
    }

    function checkInMessage(item) {
        if (item.allowed_actions?.check_in) return 'Check-in is open now.';
        if (item.allowed_actions?.check_out) return 'You are currently checked in.';
        if (item.allowed_actions?.check_in_not_yet) {
            const start = toDate(item.start);
            if (start && !Number.isNaN(start.getTime())) {
                const opens = new Date(start.getTime() - 30 * 60000);
                return `Check-in opens at ${fmtTime(opens)}.`;
            }
        }
        if (item.allowed_actions?.check_in_ended) return 'The check-in period has ended.';
        return '';
    }

    function nextReservation(rows) {
        const now = Date.now();
        const activeStatuses = ['SUBMITTED', 'APPROVED', 'CHECKED_IN'];
        return (rows || [])
            .filter(row => activeStatuses.includes(String(row.status || '').toUpperCase()))
            .sort((a, b) => {
                const aTime = toDate(a.start)?.getTime() || Number.MAX_SAFE_INTEGER;
                const bTime = toDate(b.start)?.getTime() || Number.MAX_SAFE_INTEGER;
                if (String(a.status).toUpperCase() === 'CHECKED_IN') return -1;
                if (String(b.status).toUpperCase() === 'CHECKED_IN') return 1;
                return Math.abs(aTime - now) - Math.abs(bTime - now);
            })[0];
    }

    function renderNextUp(item) {
        const target = document.getElementById('employee-next-up');
        if (!target) return;
        if (!item) {
            target.innerHTML = window.FAMEmployeePortal.emptyState('event_available', 'No upcoming reservation', 'Your next approved or pending room reservation will appear here.');
            return;
        }
        const helper = checkInMessage(item);
        const actions = [
            item.allowed_actions?.check_in ? `<a class="btn-primary dashboard-action-button" href="${esc(window.FAMEmployeePortal.routeHref('employee-room-reservations', { reservation: item.id }))}">Check In</a>` : '',
            item.allowed_actions?.check_out ? `<a class="btn-primary dashboard-action-button" href="${esc(window.FAMEmployeePortal.routeHref('employee-room-reservations', { reservation: item.id }))}">Check Out</a>` : '',
            `<a class="btn-secondary dashboard-action-button" href="${esc(window.FAMEmployeePortal.routeHref('employee-room-reservations', { reservation: item.id }))}">View Details</a>`
        ].filter(Boolean).join('');
        target.innerHTML = `
            <article class="fam-card employee-next-card">
                <div>
                    <div class="employee-next-card-top">
                        <span class="employee-card-kicker">Room Reservation</span>
                        ${reservationBadge(item)}
                    </div>
                    <h3>${esc(item.room || 'Room reservation')}</h3>
                    <p class="employee-next-meta">${esc(fmtDate(item.start))} &middot; ${esc(fmtTime(item.start))} - ${esc(fmtTime(item.end))}</p>
                    <p>${esc(item.purpose || 'No purpose provided.')}</p>
                    ${helper ? `<p class="employee-next-helper">${esc(helper)}</p>` : ''}
                </div>
                <div class="employee-next-actions">${actions}</div>
            </article>
        `;
    }

    function renderActivity(items) {
        const activity = document.getElementById('employee-recent-activity');
        if (!activity) return;
        if (!items?.length) {
            activity.innerHTML = window.FAMEmployeePortal.emptyState('history', 'No recent activity', 'Your recent requests, reservations, and assigned task activity will appear here.');
            return;
        }
        const itemHref = item => {
            if (item.entity_type === 'facility_reservation') return window.FAMEmployeePortal.routeHref('employee-room-reservations', { reservation: item.entity_id });
            return '';
        };
        activity.innerHTML = `
            <ul class="employee-activity-list">
                ${items.map(item => {
                    const href = itemHref(item);
                    const content = `
                        <span class="material-symbols-outlined" aria-hidden="true">history</span>
                        <div>
                            <strong>${esc(item.event)}</strong>
                            <p>${esc(item.reference)} &middot; ${esc(item.subject)}</p>
                            <small>${esc(fmt(item.occurred_at))}</small>
                        </div>`;
                    return `<li>${href ? `<a href="${esc(href)}" class="employee-activity-link">${content}</a>` : content}</li>`;
                }).join('')}
            </ul>
        `;
    }

    async function init() {
        const context = await window.FAMEmployeePortal.context();
        const [dashboard, reservations] = await Promise.all([
            window.FAMEmployeePortal.dashboard(),
            window.FAMApi.request('employee/reservations/list.php?per_page=12')
        ]);
        document.getElementById('employee-welcome-name').textContent = window.FAMEmployeePortal.text(context?.full_name, 'Employee');
        document.getElementById('employee-welcome-department').textContent = window.FAMEmployeePortal.text(context?.department?.name || context?.department?.code, 'Department not available');
        const reservation = nextReservation(reservations.data?.items || []);
        renderAttention(dashboard?.counts || {}, reservation, dashboard?.assigned_tasks || []);
        renderNextUp(reservation);
        renderCards(dashboard?.counts || {});
        renderActivity(dashboard?.recent_activity || []);
    }

    document.addEventListener('fam:employee-layout-ready', () => init().catch(console.error));
})();
