(function () {
    const state = {
        filter: 'all',
        items: [],
        unread: 0,
        loading: false
    };

    const qs = selector => document.querySelector(selector);
    const qsa = selector => Array.from(document.querySelectorAll(selector));
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const fmt = value => {
        if (!value) return 'Just now';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    const dayKey = value => {
        const date = value ? new Date(String(value).replace(' ', 'T')) : new Date();
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
        const itemDay = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
        if (itemDay === today) return 'Today';
        if (itemDay === today - 86400000) return 'Yesterday';
        return 'Older';
    };

    function api(path) {
        return path;
    }

    function href(item) {
        const routeHref = window.FAMEmployeePortal?.routeHref || ((route, params = {}) => {
            const base = window.FAMNavigation?.cleanHref?.(route) || '#';
            const query = new URLSearchParams(params).toString();
            return query ? `${base}?${query}` : base;
        });
        if (item.module_code === 'RESERVATIONS' || item.related_entity_type === 'facility_reservation' || String(item.action_url || '').includes('employee/room-reservations.html')) {
            return routeHref('employee-room-reservations', { reservation: item.related_entity_id });
        }
        if (item.module_code === 'FACILITY_REQUESTS' || item.related_entity_type === 'facility_request' || String(item.action_url || '').includes('employee/facility-requests.html')) {
            return routeHref('employee-facility-requests', { request: item.related_entity_id });
        }
        if (item.module_code === 'contract_management' || item.related_entity_type === 'contract' || String(item.action_url || '').includes('employee/approvals.html') || String(item.action_url || '').includes('employee/tasks.html')) {
            const taskId = item.metadata?.workflow_task_id || '';
            return routeHref('employee-tasks', { task: taskId });
        }
        return '#';
    }

    function moduleMeta(item) {
        if (item.module_code === 'RESERVATIONS' || item.related_entity_type === 'facility_reservation') {
            return { icon: 'calendar_month', label: 'Room Reservations' };
        }
        if (item.module_code === 'FACILITY_REQUESTS' || item.related_entity_type === 'facility_request') {
            return { icon: 'domain', label: 'Facility Request' };
        }
        if (item.module_code === 'contract_management' || item.related_entity_type === 'contract') {
            return { icon: 'approval_delegation', label: 'Contract Approval' };
        }
        return { icon: 'notifications', label: 'Notifications' };
    }

    function filtered() {
        if (state.filter === 'unread') return state.items.filter(item => !item.is_read);
        if (state.filter === 'read') return state.items.filter(item => item.is_read);
        return state.items;
    }

    function updateBadge() {
        const badge = qs('#notification-unread-count');
        if (!badge) return;
        badge.textContent = state.unread > 99 ? '99+' : String(state.unread);
        badge.classList.toggle('hidden', state.unread === 0);
    }

    function emptyState(title = 'You have no notifications.', copy = 'Updates about your requests and reservations will appear here.') {
        return window.FAMEmployeePortal.emptyState('notifications_none', title, copy);
    }

    function render() {
        const target = qs('#employee-notifications-state');
        if (!target) return;

        qsa('[data-employee-notification-filter]').forEach(button => {
            const active = button.dataset.employeeNotificationFilter === state.filter;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });

        updateBadge();

        if (state.loading) {
            target.innerHTML = '<div class="notification-list notification-page-list"><div class="notification-skeleton-card"><span></span><div><span></span><span></span></div></div><div class="notification-skeleton-card"><span></span><div><span></span><span></span></div></div></div>';
            return;
        }

        const items = filtered();
        if (!items.length) {
            target.innerHTML = emptyState(
                state.filter === 'unread' ? 'No unread notifications.' : state.filter === 'read' ? 'No read notifications.' : 'You have no notifications.',
                state.filter === 'all' ? 'Updates about your requests and reservations will appear here.' : 'Try switching to another notification filter.'
            );
            return;
        }

        const groups = items.reduce((bucket, item) => {
            const key = dayKey(item.created_at);
            bucket[key] = bucket[key] || [];
            bucket[key].push(item);
            return bucket;
        }, {});

        target.innerHTML = `<div class="notification-list notification-page-list">${['Today', 'Yesterday', 'Older'].filter(key => groups[key]?.length).map(key => `
            <section class="employee-notification-group" aria-label="${esc(key)} notifications">
                <h3>${esc(key)}</h3>
                ${groups[key].map(item => {
                    const meta = moduleMeta(item);
                    return `
                        <article class="notification-card ${item.is_read ? 'read' : 'unread'}">
                            <a href="${esc(href(item))}" class="notification-card-main" data-employee-notification-open="${esc(item.id)}">
                                <span class="notification-type-icon material-symbols-outlined" aria-hidden="true">${esc(meta.icon)}</span>
                                <span class="notification-card-copy">
                                    <span class="notification-card-title">${esc(item.title)}</span>
                                    <span class="notification-card-message">${esc(item.message)}</span>
                                    <span class="notification-card-meta">
                                        <span>${esc(fmt(item.created_at))}</span>
                                        <span>${esc(meta.label)}</span>
                                        ${item.related_reference ? `<span class="notification-card-badge">${esc(item.related_reference)}</span>` : ''}
                                    </span>
                                </span>
                                <span class="notification-unread-dot" aria-hidden="true"></span>
                                <span class="material-symbols-outlined notification-chevron" aria-hidden="true">chevron_right</span>
                            </a>
                        </article>
                    `;
                }).join('')}
            </section>
        `).join('')}</div>`;
    }

    async function load(quiet = false) {
        if (state.loading) return;
        state.loading = !quiet;
        render();
        try {
            const payload = await window.FAMApi.request(api('employee/notifications/list.php?per_page=50'));
            state.items = payload.data?.items || [];
            state.unread = Number(payload.data?.unread_count || 0);
        } catch (error) {
            const target = qs('#employee-notifications-state');
            if (target) target.innerHTML = emptyState('Unable to load notifications.', error.message || 'Please try again.');
        } finally {
            state.loading = false;
            render();
        }
    }

    async function markRead(id) {
        const item = state.items.find(row => String(row.id) === String(id));
        if (item && !item.is_read) {
            item.is_read = true;
            state.unread = Math.max(0, state.unread - 1);
            render();
        }
        try {
            await window.FAMApi.request(api(`notifications/mark-read.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: {} });
        } catch {
            await load(true);
        }
    }

    async function markAllRead() {
        const hadUnread = state.unread > 0;
        state.items.forEach(item => { item.is_read = true; });
        state.unread = 0;
        render();
        try {
            const payload = await window.FAMApi.request(api('notifications/mark-all-read.php'), { method: 'POST', body: {} });
            state.unread = Number(payload.data?.unread_count || 0);
            window.FAMModal?.showToast?.('Notifications marked as read.');
        } catch {
            if (hadUnread) await load(true);
        }
    }

    function bind() {
        qsa('[data-employee-notification-filter]').forEach(button => {
            button.addEventListener('click', () => {
                state.filter = button.dataset.employeeNotificationFilter || 'all';
                render();
            });
        });

        qs('[data-employee-notifications-mark-all]')?.addEventListener('click', () => {
            markAllRead();
        });

        document.addEventListener('click', event => {
            const link = event.target.closest('[data-employee-notification-open]');
            if (!link) return;
            event.preventDefault();
            const target = link.getAttribute('href');
            markRead(link.dataset.employeeNotificationOpen).finally(() => {
                if (target) window.location.href = target;
            });
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') load(true);
        });
        setInterval(() => {
            if (document.visibilityState === 'visible') load(true);
        }, 45000);
    }

    document.addEventListener('fam:employee-layout-ready', () => {
        bind();
        load();
    });
})();
