(function () {
    const state = { filter: 'all', items: [], unread: 0, loading: false };
    const qs = selector => document.querySelector(selector);
    const qsa = selector => Array.from(document.querySelectorAll(selector));
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const fmt = value => {
        const date = new Date(String(value || '').replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? 'Just now' : date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    const apiUrl = path => window.FAMApi?.apiUrl?.(path) || window.FAMNavigation?.apiUrl?.(path) || `/api/${String(path || '').replace(/^api\//, '')}`;
    const href = item => item.related_entity_id && item.module_code === 'FACILITY_REQUESTS' ? `facility-requests.html?request=${encodeURIComponent(item.related_entity_id)}` : '#';
    const filtered = () => state.filter === 'unread' ? state.items.filter(item => !item.is_read) : state.filter === 'read' ? state.items.filter(item => item.is_read) : state.items;

    function updateBadge() {
        const badge = qs('#notification-unread-count');
        if (!badge) return;
        badge.textContent = state.unread > 99 ? '99+' : String(state.unread);
        badge.classList.toggle('hidden', state.unread === 0);
    }

    function empty(title, copy) {
        return `<div class="employee-empty-state"><span class="material-symbols-outlined" aria-hidden="true">notifications_none</span><strong>${esc(title)}</strong><p>${esc(copy)}</p></div>`;
    }

    function render() {
        const target = qs('#notifications-page-state');
        if (!target) return;
        qsa('[data-notification-page-filter]').forEach(button => {
            const active = button.dataset.notificationPageFilter === state.filter;
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
            target.innerHTML = empty(state.filter === 'unread' ? 'No unread notifications.' : 'You have no notifications.', 'Facility request and system updates will appear here.');
            return;
        }
        target.innerHTML = `<div class="notification-list notification-page-list">${items.map(item => `
            <article class="notification-card ${item.is_read ? 'read' : 'unread'}">
                <a href="${esc(href(item))}" class="notification-card-main" data-notification-page-open="${esc(item.id)}">
                    <span class="notification-type-icon material-symbols-outlined" aria-hidden="true">domain</span>
                    <span class="notification-card-copy">
                        <span class="notification-card-title">${esc(item.title)}</span>
                        <span class="notification-card-message">${esc(item.message)}</span>
                        <span class="notification-card-meta"><span>${esc(fmt(item.created_at))}</span><span>Facility Requests</span>${item.related_reference ? `<span class="notification-card-badge">${esc(item.related_reference)}</span>` : ''}</span>
                    </span>
                    <span class="notification-unread-dot" aria-hidden="true"></span>
                    <span class="material-symbols-outlined notification-chevron" aria-hidden="true">chevron_right</span>
                </a>
            </article>
        `).join('')}</div>`;
    }

    async function load(quiet = false) {
        if (state.loading) return;
        state.loading = !quiet;
        render();
        try {
            const payload = await window.FAMApi.request(apiUrl('notifications/index.php?per_page=50'));
            state.items = payload.data?.items || [];
            state.unread = Number(payload.data?.unread_count || 0);
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
            await window.FAMApi.request(apiUrl(`notifications/mark-read.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: {} });
        } catch {
            await load(true);
        }
    }

    function bind() {
        qsa('[data-notification-page-filter]').forEach(button => button.addEventListener('click', () => {
            state.filter = button.dataset.notificationPageFilter || 'all';
            render();
        }));
        document.addEventListener('click', event => {
            const link = event.target.closest('[data-notification-page-open]');
            if (!link) return;
            event.preventDefault();
            const target = link.getAttribute('href');
            markRead(link.dataset.notificationPageOpen).finally(() => {
                if (target && target !== '#') window.location.href = target;
            });
        });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') load(true);
        });
        setInterval(() => {
            if (document.visibilityState === 'visible') load(true);
        }, 45000);
    }

    document.addEventListener('fam:layout-ready', () => {
        bind();
        load();
    });
})();
