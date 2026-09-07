(function () {
    const componentPaths = {
        sidebar: `${window.FAMNavigation?.appBasePath?.() || '../'}components/sidebar.html`,
        header: `${window.FAMNavigation?.appBasePath?.() || '../'}components/header.html`,
        footer: `${window.FAMNavigation?.appBasePath?.() || '../'}components/footer.html`
    };

    async function loadComponent(selector, path) {
        const target = document.querySelector(selector);
        if (!target) return;

        const response = await fetch(path);
        if (!response.ok) {
            throw new Error(`Failed to load component: ${path}`);
        }
        target.innerHTML = await response.text();
    }


    function applyPermissionVisibility() {
        const permissions = window.FAMApi?.currentUser?.permissions || [];
        document.querySelectorAll('[data-requires-permission]').forEach(element => {
            const required = element.dataset.requiresPermission;
            if (required && !permissions.includes(required)) {
                element.classList.add('hidden');
                element.setAttribute('aria-hidden', 'true');
            } else if (required) {
                element.classList.remove('hidden');
                element.removeAttribute('aria-hidden');
            }
        });
    }

    function hasPermission(permission) {
        return (window.FAMApi?.currentUser?.permissions || []).includes(permission);
    }

    function enforcePagePermission() {
        const required = document.body.dataset.requiresPermission;
        if (!required || hasPermission(required)) {
            return true;
        }

        window.location.replace(`${window.FAMNavigation?.appBasePath?.() || '../'}errors/403.html`);
        return false;
    }
    function applyPageMetadata() {
        const config = window.pageConfig || {};
        const title = config.title || document.body.dataset.pageTitle;
        const breadcrumb = config.breadcrumb || document.body.dataset.breadcrumbTitle || title;
        const heading = config.heading || document.body.dataset.pageHeading;
        const description = config.description || document.body.dataset.pageDescription;

        if (title) document.title = title;
        const breadcrumbTarget = document.getElementById('page-breadcrumb-title');
        const headingTarget = document.getElementById('page-heading');
        const descriptionTarget = document.getElementById('page-description');

        if (breadcrumbTarget && breadcrumb) breadcrumbTarget.textContent = breadcrumb;
        if (headingTarget && heading) headingTarget.textContent = heading;
        if (descriptionTarget && description) descriptionTarget.textContent = description;
    }


    function ensureApiClient() {
        if (window.FAMApi) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-api-client]');
            if (existing) {
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            const script = document.createElement('script');
            script.src = '../assets/js/api-client.js';
            script.defer = true;
            script.dataset.apiClient = 'true';
            script.addEventListener('load', resolve, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }


    function ensureDetailsModal() {
        if (window.FAMDetailsModal) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-details-modal]');
            if (existing) {
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            const script = document.createElement('script');
            script.src = '../assets/js/details-modal.js';
            script.defer = true;
            script.dataset.detailsModal = 'true';
            script.addEventListener('load', resolve, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }

    function ensureLiveModule() {
        if (window.FAMLiveModule) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-live-module]');
            if (existing) {
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            const script = document.createElement('script');
            script.src = '../assets/js/live-module.js';
            script.defer = true;
            script.dataset.liveModule = 'true';
            script.addEventListener('load', resolve, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }
    async function verifyProtectedSession() {
        if (!window.FAMApi) return true;
        try {
            const auth = await window.FAMApi.me();
            const persona = auth.user?.persona || {};
            if (persona.is_fam_portal_allowed !== true) {
                if (persona.is_employee_portal_allowed === true) {
                    window.location.replace(`${window.FAMNavigation?.appBasePath?.() || '../'}pages/employee/dashboard.html`);
                } else {
                    window.location.replace(`${window.FAMNavigation?.appBasePath?.() || '../'}errors/403.html`);
                }
                return false;
            }
            return true;
        } catch (error) {
            if (error.status === 401) {
                window.location.replace(window.FAMApi.pageLoginUrl());
                return false;
            }
            throw error;
        }
    }
    async function initializeLayout() {
        try {
            await ensureApiClient();
            if (!await verifyProtectedSession()) return;
            if (!enforcePagePermission()) return;
            await ensureDetailsModal();
            await ensureLiveModule();

            await Promise.all([
                loadComponent('#app-sidebar-slot', componentPaths.sidebar),
                loadComponent('#app-header-slot', componentPaths.header),
                loadComponent('#app-footer-slot', componentPaths.footer)
            ]);

            applyPageMetadata();
            applyPermissionVisibility();
            window.FAMNavigation?.initializeActiveNavigation();
            window.FAMSidebar?.initializeSidebar();
            window.FAMProfileDropdown?.initializeProfileDropdown();
            window.FAMModal?.initializeModals();
            document.dispatchEvent(new CustomEvent('fam:layout-ready'));
        } catch (error) {
            console.error(error);
            const content = document.getElementById('app-content');
            if (content) {
                content.insertAdjacentHTML('afterbegin', '<div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Shared layout components could not be loaded. Serve this project through a local web server instead of opening the file directly.</div>');
            }
        }
    }


    window.addEventListener('pageshow', event => {
        const navigation = performance.getEntriesByType?.('navigation')?.[0];
        if (event.persisted || navigation?.type === 'back_forward') {
            ensureApiClient().then(() => verifyProtectedSession()).catch(error => console.error(error));
        }
    });
    document.addEventListener('DOMContentLoaded', initializeLayout);

    window.FAMLayout = {
        loadComponent,
        initializeLayout,
        applyPageMetadata,
        applyPermissionVisibility
    };
})();

