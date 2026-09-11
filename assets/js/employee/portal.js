(function () {
    const shellVersion = '20260911-corporate-footer';
    const componentPaths = {
        sidebar: 'components/employee/sidebar.html',
        header: 'components/employee/header.html',
        footer: 'components/employee/footer.html'
    };

    let contextPromise = null;
    let dashboardPromise = null;

    function text(value, fallback = 'Not available') {
        const cleaned = String(value ?? '').trim();
        return cleaned || fallback;
    }

    function initials(name) {
        const parts = text(name, 'Employee Portal').split(/\s+/).filter(Boolean);
        return (parts[0]?.[0] || 'E') + (parts[1]?.[0] || 'P');
    }

    async function loadComponent(selector, path) {
        const target = document.querySelector(selector);
        if (!target) return;
        const url = new URL(window.FAMNavigation?.appPath?.(path) || path, window.location.href);
        url.searchParams.set('v', shellVersion);
        const response = await fetch(url.toString());
        if (!response.ok) throw new Error(`Failed to load component: ${path}`);
        target.innerHTML = await response.text();
    }

    function applyPageMetadata() {
        const title = document.body.dataset.pageTitle;
        const heading = document.body.dataset.pageHeading;
        const description = document.body.dataset.pageDescription;
        if (title) document.title = title;
        const headerPage = document.getElementById('employee-header-page');
        if (headerPage) headerPage.textContent = heading || title || 'Employee Portal';
        const pageHeading = document.getElementById('page-heading');
        const pageDescription = document.getElementById('page-description');
        if (pageHeading && heading) pageHeading.textContent = heading;
        if (pageDescription && description) pageDescription.textContent = description;
    }

    function renderChromeContext(context) {
        const name = text(context.full_name, context.username || 'Employee');
        const position = text(context.position, 'Employee');
        const email = text(context.email, context.username || '');
        const avatar = initials(name).toUpperCase();
        const values = {
            'employee-header-department': 'Facilities & Administrative Services',
            'employee-header-name': name,
            'employee-header-position': position,
            'employee-header-avatar': avatar,
            'employee-menu-name': name,
            'employee-menu-email': email
        };
        Object.entries(values).forEach(([id, value]) => {
            const node = document.getElementById(id);
            if (node) node.textContent = value;
        });
    }

    function renderAccessDenied(message) {
        const content = document.getElementById('page-content');
        if (!content) return;
        content.innerHTML = `
            <div class="employee-shell">
                <section class="employee-access-state" role="alert">
                    <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                    <h1>Employee access unavailable</h1>
                    <p>${text(message, 'No employee record is linked to this account.')}</p>
                    <button type="button" class="btn-secondary dashboard-action-button" data-auth-logout>
                        <span class="material-symbols-outlined" aria-hidden="true">logout</span>
                        Log Out
                    </button>
                </section>
            </div>
        `;
    }

    async function context() {
        if (!contextPromise) {
            contextPromise = window.FAMApi.request('employee/context.php')
                .then(payload => payload.data?.context || null);
        }
        return contextPromise;
    }

    async function dashboard() {
        if (!dashboardPromise) {
            dashboardPromise = window.FAMApi.request('employee/dashboard.php')
                .then(payload => payload.data?.dashboard || null);
        }
        return dashboardPromise;
    }

    function emptyState(icon, title, copy, action = '') {
        return `
            <div class="fam-state employee-empty-state">
                <span class="material-symbols-outlined" aria-hidden="true">${icon}</span>
                <strong>${title}</strong>
                ${copy ? `<p>${copy}</p>` : ''}
                ${action ? `<div class="employee-empty-action">${action}</div>` : ''}
            </div>
        `;
    }

    function routeHref(routeKey, params = {}) {
        const href = window.FAMNavigation?.cleanHref?.(routeKey) || routeKey;
        const query = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') query.set(key, value);
        });
        const qs = query.toString();
        return qs ? `${href}?${qs}` : href;
    }

    async function initializeLayout() {
        try {
            if (!window.FAMApi) throw new Error('API client is not available.');
            await window.FAMApi.me();
            await Promise.all([
                loadComponent('#app-sidebar-slot', componentPaths.sidebar),
                loadComponent('#app-header-slot', componentPaths.header),
                loadComponent('#app-footer-slot', componentPaths.footer)
            ]);
            applyPageMetadata();
            window.FAMNavigation?.initializeActiveNavigation();
            window.FAMSidebar?.initializeSidebar();
            window.FAMProfileDropdown?.initializeProfileDropdown();
            window.FAMModal?.initializeModals?.();
            const employeeContext = await context();
            renderChromeContext(employeeContext || {});
            document.dispatchEvent(new CustomEvent('fam:employee-layout-ready', { detail: { context: employeeContext } }));
        } catch (error) {
            if (error.status === 401) {
                window.location.replace(window.FAMApi.pageLoginUrl());
                return;
            }
            if (error.status === 403) {
                renderAccessDenied(error.message);
                return;
            }
            console.error(error);
            renderAccessDenied('Employee Portal could not be loaded. Please try again.');
        }
    }

    document.addEventListener('DOMContentLoaded', initializeLayout);

    window.FAMEmployeePortal = {
        context,
        dashboard,
        emptyState,
        routeHref,
        text
    };
})();
