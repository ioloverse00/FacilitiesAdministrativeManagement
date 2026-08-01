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
    const notificationSeed = [];

    const typeMeta = {
        facility: { icon: 'domain', label: 'Facility Requests' },
        maintenance: { icon: 'build', label: 'Maintenance' },
        assets: { icon: 'inventory_2', label: 'Assets' },
        reservation: { icon: 'calendar_month', label: 'Reservations' },
        procurement: { icon: 'assignment', label: 'Procurement' },
        records: { icon: 'folder', label: 'Administrative Records' },
        reports: { icon: 'bar_chart', label: 'Reports' },
        ai: { icon: 'auto_awesome', label: 'AI' },
        system: { icon: 'settings', label: 'System' }
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
        initializeAccountMenu();
        initializeNotificationCenter();
        initializeThemeControl();
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

        if (!toggle || !panel || !list || toggle.dataset.initialized === 'true') return;
        toggle.dataset.initialized = 'true';

        let isOpen = false;
        let filter = 'all';
        const notifications = notificationSeed.map(item => ({ ...item }));

        const unreadCount = () => notifications.filter(item => item.unread).length;

        const updateCount = () => {
            const count = unreadCount();
            const label = count > 99 ? '99+' : String(count);
            countBadge.textContent = label;
            countBadge.classList.toggle('hidden', count === 0);
            summary.textContent = `${count} unread`;
            toggle.setAttribute('aria-label', count ? `Open notifications, ${label} unread` : 'Open notifications');
        };

        const filteredItems = () => notifications.filter(item => {
            if (filter === 'unread') return item.unread;
            if (filter === 'mentions') return false;
            if (filter === 'system') return item.type === 'system';
            return true;
        });

        const renderLoading = () => {
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
                const meta = typeMeta[item.type] || typeMeta.system;
                return `
                    <article class="notification-card ${item.unread ? 'unread' : 'read'}" data-notification-id="${item.id}" data-module="${item.type}" data-priority="${item.priority}" data-period="${item.time.includes('Yesterday') || item.time.includes('days') ? 'this-week' : 'today'}">
                        <a href="${item.href}" class="notification-card-main" data-notification-open="${item.id}">
                            <span class="notification-type-icon material-symbols-outlined" aria-hidden="true">${meta.icon}</span>
                            <span class="notification-card-copy">
                                <span class="notification-card-title">${item.title}</span>
                                <span class="notification-card-message">${item.message}</span>
                                <span class="notification-card-meta">
                                    <span>${item.time}</span>
                                    <span>${meta.label}</span>
                                    ${item.badge ? `<span class="notification-card-badge">${item.badge}</span>` : ''}
                                </span>
                            </span>
                            <span class="notification-unread-dot" aria-hidden="true"></span>
                            <span class="material-symbols-outlined notification-chevron" aria-hidden="true">chevron_right</span>
                        </a>
                        <div class="notification-card-actions" aria-label="Notification actions">
                            <button type="button" data-notification-read="${item.id}">${item.unread ? 'Mark as Read' : 'Read'}</button>
                            <a href="${item.href}" data-notification-open="${item.id}">Open</a>
                            <button type="button" data-notification-dismiss="${item.id}">Dismiss</button>
                        </div>
                    </article>
                `;
            }).join('');
        };

        const openPanel = () => {
            isOpen = true;
            window.FAMHeaderMenus?.closeProfile?.();
            panel.classList.remove('hidden', 'opacity-0', 'scale-95');
            panel.classList.add('block', 'opacity-100', 'scale-100');
            toggle.setAttribute('aria-expanded', 'true');
            render();
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
            notifications.forEach(item => { item.unread = false; });
            render();
        });

        list.addEventListener('click', event => {
            const readId = event.target.closest('[data-notification-read]')?.dataset.notificationRead;
            const dismissId = event.target.closest('[data-notification-dismiss]')?.dataset.notificationDismiss;
            const openId = event.target.closest('[data-notification-open]')?.dataset.notificationOpen;

            if (readId) {
                event.preventDefault();
                const item = notifications.find(entry => entry.id === readId);
                if (item) item.unread = false;
                render();
            }

            if (dismissId) {
                event.preventDefault();
                const index = notifications.findIndex(entry => entry.id === dismissId);
                if (index >= 0) notifications.splice(index, 1);
                render();
            }

            if (openId) {
                const item = notifications.find(entry => entry.id === openId);
                if (item) item.unread = false;
                updateCount();
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

        render();
    }

    window.FAMProfileDropdown = {
        initializeProfileDropdown
    };
})();
