(function () {

    let logoutInFlight = false;

    function setLogoutBusy(button, busy) {
        if (!button) return;
        if (busy) {
            button.dataset.originalText = button.textContent.trim();
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            const label = button.querySelector('span:last-child') || button;
            label.textContent = 'Logging out...';
            return;
        }
        button.disabled = false;
        button.removeAttribute('aria-busy');
        const label = button.querySelector('span:last-child') || button;
        label.textContent = button.dataset.originalText || 'Log Out';
    }

    function announceLogoutError(message) {
        window.FAMModal?.showToast?.(message);
        let live = document.getElementById('auth-logout-status');
        if (!live) {
            live = document.createElement('div');
            live.id = 'auth-logout-status';
            live.className = 'sr-only';
            live.setAttribute('aria-live', 'polite');
            document.body.appendChild(live);
        }
        live.textContent = message;
    }

    async function handleLogoutClick(event) {
        const button = event.target.closest('[data-auth-logout]');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        if (logoutInFlight) return;
        logoutInFlight = true;
        setLogoutBusy(button, true);
        try {
            if (!window.FAMApi) throw new Error('Auth helper is not available.');
            await window.FAMApi.logout();
            window.FAMHeaderMenus?.closeProfile?.();
            window.FAMModal?.closeModal?.('dash-logout-modal');
            window.location.replace(window.FAMApi.loginUrl());
        } catch (error) {
            logoutInFlight = false;
            setLogoutBusy(button, false);
            announceLogoutError('Unable to log out. Please try again.');
        }
    }
    const typeMeta = {
        facility: { icon: 'domain', label: 'Facility Requests' },
        facility_requests: { icon: 'domain', label: 'Facility Requests' },
        maintenance: { icon: 'build', label: 'Maintenance' },
        assets: { icon: 'inventory_2', label: 'Assets' },
        reservation: { icon: 'calendar_month', label: 'Reservations' },
        procurement: { icon: 'assignment', label: 'Procurement' },
        records: { icon: 'folder', label: 'Administrative Records' },
        reports: { icon: 'bar_chart', label: 'Reports' },
        ai: { icon: 'auto_awesome', label: 'AI' },
        system: { icon: 'settings', label: 'System' }
    };

    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const text = (value, fallback = '') => String(value ?? '').trim() || fallback;
    const initials = name => {
        const parts = text(name, 'FAM User').split(/\s+/).filter(Boolean);
        return ((parts[0]?.[0] || 'F') + (parts[1]?.[0] || 'M')).toUpperCase();
    };
    const appBasePath = () => {
        if (window.FAMNavigation?.appBasePath) return window.FAMNavigation.appBasePath();
        const marker = '/pages/';
        const path = window.location.pathname;
        const index = path.indexOf(marker);
        if (index >= 0) return path.slice(0, index + 1);
        return path.endsWith('/') ? path : path.replace(/[^/]*$/, '');
    };
    const cleanHref = route => window.FAMNavigation?.cleanHref?.(route) || `${appBasePath()}${route}`;
    const underMaintenanceHref = () => cleanHref('under-maintenance');
    const notificationApi = path => `${appBasePath()}api/notifications/${path}`;
    const isEmployeePortal = () => window.location.pathname.includes('/pages/employee/');
    const fmtTime = value => {
        if (!value) return 'Just now';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    const notificationHref = item => {
        const url = String(item.action_url || '').trim();
        if (/^https?:\/\//i.test(url) || url.startsWith('/')) return url;
        if (isEmployeePortal() && item.module_code === 'FACILITY_REQUESTS' && item.related_entity_id) {
            return `facility-requests.html?request=${encodeURIComponent(item.related_entity_id)}`;
        }
        if (isEmployeePortal() && item.module_code === 'RESERVATIONS' && item.related_entity_id) {
            return `room-reservations.html?reservation=${encodeURIComponent(item.related_entity_id)}`;
        }
        if (url.startsWith('pages/')) return `${appBasePath()}${url}`;
        return url || '#';
    };


    function initializeThemeControl() {
        const control = document.querySelector('[data-theme-control]');
        if (!control || control.dataset.initialized === 'true') return;
        control.dataset.initialized = 'true';
        const buttons = Array.from(control.querySelectorAll('[data-theme-option]'));
        const sync = () => {
            const preference = window.FAMTheme?.preference?.() || 'system';
            buttons.forEach(button => {
                const active = button.dataset.themeOption === preference;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', String(active));
            });
        };
        control.addEventListener('click', event => {
            const button = event.target.closest('[data-theme-option]');
            if (!button) return;
            window.FAMTheme?.setPreference?.(button.dataset.themeOption);
            sync();
        });
        window.addEventListener('fam:themechange', sync);
        sync();
    }
    function initializeProfileDropdown() {
        if (document.body.dataset.logoutHandlerInitialized !== 'true') {
            document.body.dataset.logoutHandlerInitialized = 'true';
            document.addEventListener('click', handleLogoutClick);
        }
        renderAuthenticatedIdentity();
        routeAccountPlaceholders();
        initializeAccountMenu();
        initializeNotificationCenter();
        initializeThemeControl();
    }

    function routeAccountPlaceholders() {
        document.querySelectorAll('[data-account-placeholder]').forEach(link => {
            link.setAttribute('href', underMaintenanceHref());
        });
    }

    function renderAuthenticatedIdentity() {
        const user = window.FAMApi?.currentUser || {};
        const name = text(user.full_name, text(user.username, 'FAM User'));
        const position = text(user.position, 'Position not available');
        const email = text(user.email, text(user.username));
        const values = {
            'fam-header-avatar': initials(name),
            'fam-header-name': name,
            'fam-header-position': position,
            'fam-menu-name': name,
            'fam-menu-email': email
        };

        Object.entries(values).forEach(([id, value]) => {
            const node = document.getElementById(id);
            if (node) node.textContent = value;
        });
    }

    function initializeAccountMenu() {
        const toggleBtn = document.getElementById('profile-dropdown-toggle');
        const dropdownMenu = document.getElementById('profile-dropdown-menu');

        if (!toggleBtn || !dropdownMenu || toggleBtn.dataset.initialized === 'true') return;
        toggleBtn.dataset.initialized = 'true';

        let isOpen = false;
        const getMenuItems = () => Array.from(dropdownMenu.querySelectorAll('a, button'));

        const openDropdown = () => {
            isOpen = true;
            window.FAMHeaderMenus?.closeNotifications?.();
            dropdownMenu.classList.remove('hidden', 'opacity-0', 'scale-95');
            dropdownMenu.classList.add('block', 'opacity-100', 'scale-100');
            toggleBtn.setAttribute('aria-expanded', 'true');
        };

        const closeDropdown = () => {
            if (!isOpen) return;
            isOpen = false;
            dropdownMenu.classList.remove('block', 'opacity-100', 'scale-100');
            dropdownMenu.classList.add('opacity-0', 'scale-95');
            toggleBtn.setAttribute('aria-expanded', 'false');
            setTimeout(() => {
                if (!isOpen) dropdownMenu.classList.add('hidden');
            }, 200);
        };

        window.FAMHeaderMenus = Object.assign(window.FAMHeaderMenus || {}, { closeProfile: closeDropdown });

        toggleBtn.addEventListener('click', event => {
            event.stopPropagation();
            isOpen ? closeDropdown() : openDropdown();
        });

        dropdownMenu.addEventListener('keydown', event => {
            const items = getMenuItems();
            const currentIndex = items.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                items[(currentIndex + 1) % items.length]?.focus();
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                items[(currentIndex - 1 + items.length) % items.length]?.focus();
            }
        });

        document.addEventListener('click', event => {
            if (isOpen && !dropdownMenu.contains(event.target) && !toggleBtn.contains(event.target)) closeDropdown();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && isOpen) {
                closeDropdown();
                toggleBtn.focus();
            }
        });
    }

    function initializeNotificationCenter() {
        const toggle = document.getElementById('notification-dropdown-toggle');
        const panel = document.getElementById('notification-dropdown-menu');
        const list = document.getElementById('notification-list');
        const countBadge = document.getElementById('notification-unread-count');
        const summary = document.getElementById('notification-unread-summary');
        const markAll = document.getElementById('notification-mark-all-read');
        const tabs = Array.from(document.querySelectorAll('[data-notification-filter]'));

        if (!toggle || toggle.dataset.initialized === 'true') return;
        toggle.dataset.initialized = 'true';

        let isOpen = false;
        let filter = 'all';
        let notifications = [];
        let unread = 0;
        let loading = false;
        let pollingTimer = null;

        const unreadCount = () => unread;

        const updateCount = () => {
            const count = unreadCount();
            const label = count > 99 ? '99+' : String(count);
            if (countBadge) {
                countBadge.textContent = label;
                countBadge.classList.toggle('hidden', count === 0);
            }
            if (summary) summary.textContent = `${count} unread`;
            toggle.setAttribute('aria-label', count ? `Open notifications, ${label} unread` : 'Open notifications');
        };

        const filteredItems = () => notifications.filter(item => {
            if (filter === 'unread') return !item.is_read;
            if (filter === 'read') return item.is_read;
            if (filter === 'system') return item.module_code === 'SYSTEM';
            return true;
        });

        const loadNotifications = async (quiet = false) => {
            if (loading || !window.FAMApi) return;
            loading = true;
            if (!quiet && list) renderLoading();
            try {
                const payload = await window.FAMApi.request(notificationApi('index.php?per_page=8'), { skipAuthRedirect: true });
                notifications = payload.data?.items || [];
                unread = Number(payload.data?.unread_count || 0);
                updateCount();
                if (list) render();
            } catch (error) {
                if (list && !quiet) {
                    list.innerHTML = `<div class="notification-empty-state"><span class="material-symbols-outlined" aria-hidden="true">notifications_off</span><strong>Notifications unavailable.</strong><p>${esc(error.message || 'Please try again.')}</p></div>`;
                }
            } finally {
                loading = false;
            }
        };

        const markRead = async id => {
            const item = notifications.find(entry => String(entry.id) === String(id));
            if (item && !item.is_read) {
                item.is_read = true;
                unread = Math.max(0, unread - 1);
                updateCount();
                if (list) render();
            }
            try {
                await window.FAMApi.request(notificationApi(`mark-read.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: {} });
            } catch {
                await loadNotifications(true);
            }
        };

        const startPolling = () => {
            if (pollingTimer) return;
            pollingTimer = setInterval(() => {
                if (document.visibilityState === 'visible') loadNotifications(true);
            }, 45000);
        };

        const renderLoading = () => {
            if (!list) return;
            list.innerHTML = Array.from({ length: 3 }).map(() => `
                <div class="notification-skeleton-card" aria-hidden="true">
                    <span></span><div><span></span><span></span></div>
                </div>
            `).join('');
        };

        const render = () => {
            const items = filteredItems();
            updateCount();

            tabs.forEach(tab => {
                const isActive = tab.dataset.notificationFilter === filter;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', String(isActive));
            });

            if (!items.length) {
                list.innerHTML = `
                    <div class="notification-empty-state">
                        <span class="material-symbols-outlined" aria-hidden="true">notifications_none</span>
                        <strong>You're all caught up.</strong>
                        <p>No new notifications.</p>
                    </div>
                `;
                return;
            }

            list.innerHTML = items.map(item => {
                const module = String(item.module_code || '').toLowerCase();
                const meta = typeMeta[module] || typeMeta.system;
                const href = notificationHref(item);
                return `
                    <article class="notification-card ${item.is_read ? 'read' : 'unread'}" data-notification-id="${esc(item.id)}" data-module="${esc(module)}" data-priority="${esc(item.priority || 'NORMAL')}">
                        <a href="${esc(href)}" class="notification-card-main" data-notification-open="${esc(item.id)}">
                            <span class="notification-type-icon material-symbols-outlined" aria-hidden="true">${meta.icon}</span>
                            <span class="notification-card-copy">
                                <span class="notification-card-title">${esc(item.title)}</span>
                                <span class="notification-card-message">${esc(item.message)}</span>
                                <span class="notification-card-meta">
                                    <span>${esc(fmtTime(item.created_at))}</span>
                                    <span>${meta.label}</span>
                                    ${item.related_reference ? `<span class="notification-card-badge">${esc(item.related_reference)}</span>` : ''}
                                </span>
                            </span>
                            <span class="notification-unread-dot" aria-hidden="true"></span>
                            <span class="material-symbols-outlined notification-chevron" aria-hidden="true">chevron_right</span>
                        </a>
                        <div class="notification-card-actions" aria-label="Notification actions">
                            <button type="button" data-notification-read="${esc(item.id)}">${item.is_read ? 'Read' : 'Mark as Read'}</button>
                            <a href="${esc(href)}" data-notification-open="${esc(item.id)}">Open</a>
                        </div>
                    </article>
                `;
            }).join('');
        };

        if (!panel || !list) {
            loadNotifications(true);
            startPolling();
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') loadNotifications(true);
            });
            return;
        }

        const openPanel = () => {
            isOpen = true;
            window.FAMHeaderMenus?.closeProfile?.();
            panel.classList.remove('hidden', 'opacity-0', 'scale-95');
            panel.classList.add('block', 'opacity-100', 'scale-100');
            toggle.setAttribute('aria-expanded', 'true');
            loadNotifications(true);
        };

        const closePanel = () => {
            if (!isOpen) return;
            isOpen = false;
            panel.classList.remove('block', 'opacity-100', 'scale-100');
            panel.classList.add('opacity-0', 'scale-95');
            toggle.setAttribute('aria-expanded', 'false');
            setTimeout(() => {
                if (!isOpen) panel.classList.add('hidden');
            }, 200);
        };

        window.FAMHeaderMenus = Object.assign(window.FAMHeaderMenus || {}, { closeNotifications: closePanel });

        toggle.addEventListener('click', event => {
            event.stopPropagation();
            isOpen ? closePanel() : openPanel();
        });

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                filter = tab.dataset.notificationFilter;
                render();
            });
        });

        markAll?.addEventListener('click', () => {
            notifications.forEach(item => { item.is_read = true; });
            unread = 0;
            render();
            window.FAMApi.request(notificationApi('mark-all-read.php'), { method: 'POST', body: {} }).catch(() => loadNotifications(true));
        });

        list.addEventListener('click', event => {
            const readId = event.target.closest('[data-notification-read]')?.dataset.notificationRead;
            const openId = event.target.closest('[data-notification-open]')?.dataset.notificationOpen;

            if (readId) {
                event.preventDefault();
                markRead(readId);
            }

            if (openId) {
                event.preventDefault();
                const href = event.target.closest('[data-notification-open]')?.getAttribute('href') || '#';
                markRead(openId).finally(() => {
                    if (href && href !== '#') window.location.href = href;
                });
            }
        });

        panel.addEventListener('keydown', event => {
            const items = Array.from(panel.querySelectorAll('button, a'));
            const currentIndex = items.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                items[(currentIndex + 1) % items.length]?.focus();
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                items[(currentIndex - 1 + items.length) % items.length]?.focus();
            }
        });

        document.addEventListener('click', event => {
            if (isOpen && !panel.contains(event.target) && !toggle.contains(event.target)) closePanel();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && isOpen) {
                closePanel();
                toggle.focus();
            }
        });

        loadNotifications();
        startPolling();
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') loadNotifications(true);
        });
    }

    window.FAMProfileDropdown = {
        initializeProfileDropdown,
        renderAuthenticatedIdentity
    };
})();
