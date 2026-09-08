(function () {
    const cleanRouteMap = {
        dashboard: 'dashboard',
        'room-reservations': 'facilities-reservation',
        'visitor-management': 'visitor-management',
        'visitor-scanner': 'visitor-scanner/',
        'contract-management': 'contract-management',
        'legal-management': 'legal-management',
        records: 'document-management',
        'records-retention': 'records-retention',
        settings: 'fam-administration',
        'under-maintenance': 'under-maintenance',
        'employee-dashboard': 'employee/',
        'employee-tasks': 'employee/tasks',
        'employee-facility-requests': 'employee/facility-requests',
        'employee-room-reservations': 'employee/reservations',
        'employee-notifications': 'employee/notifications',
        'employee-profile': 'employee/profile'
    };

    const legacyPageMap = {
        'dashboard.html': 'dashboard',
        'room-reservations.html': 'room-reservations',
        'visitor-management.html': 'visitor-management',
        'visitor-scanner.html': 'visitor-scanner',
        'contract-management.html': 'contract-management',
        'legal-management.html': 'legal-management',
        'records.html': 'records',
        'records-retention.html': 'records-retention',
        'fam-administration-maintenance.html': 'settings',
        'under-maintenance.html': 'under-maintenance'
    };

    function appBasePath() {
        const configuredBase = document.body?.dataset?.appBasePath;
        if (configuredBase) return configuredBase.endsWith('/') ? configuredBase : `${configuredBase}/`;

        const script = document.currentScript || document.querySelector('script[src*="assets/js/navigation.js"]');
        if (script?.src) {
            try {
                const url = new URL(script.src, window.location.href);
                if (url.origin === window.location.origin) {
                    const marker = '/assets/js/navigation.js';
                    const index = url.pathname.indexOf(marker);
                    if (index >= 0) return `${url.pathname.slice(0, index) || ''}/`.replace(/\/{2,}/g, '/');
                }
            } catch (_) {}
        }

        return '/';
    }

    function appPath(path = '') {
        const base = appBasePath().replace(/\/+$/, '');
        const normalized = String(path || '').replace(/^\/+/, '');
        return normalized ? `${base}/${normalized}`.replace(/^\/\//, '/') : `${base || '/'}`;
    }

    function apiUrl(path) {
        if (/^https?:\/\//i.test(path)) return path;
        const normalized = String(path || '')
            .replace(/^\/+/, '')
            .replace(/^(\.\.\/|\.\/)+/, '')
            .replace(/^api\/?/, '');
        return appPath(`api/${normalized}`);
    }

    function cleanHref(routeKey) {
        const route = cleanRouteMap[routeKey] || routeKey;
        if (!route) return appBasePath();
        return appPath(route);
    }

    function applyCleanRouteLinks(root = document) {
        root.querySelectorAll('[data-clean-route]').forEach(link => {
            link.setAttribute('href', cleanHref(link.dataset.cleanRoute));
        });
    }

    function getActiveNavKey() {
        if (document.body.dataset.activeNav) {
            return document.body.dataset.activeNav;
        }

        const cleanPath = window.location.pathname.replace(appBasePath().replace(/\/+$/, ''), '').replace(/^\/+|\/+$/g, '');
        if (cleanPath === 'employee') return 'employee-dashboard';
        const employeeRouteEntry = Object.entries(cleanRouteMap).find(([key, route]) => key.startsWith('employee-') && route.replace(/\/+$/g, '') === cleanPath);
        if (employeeRouteEntry) return employeeRouteEntry[0];
        const segment = window.location.pathname.split('/').filter(Boolean).pop() || 'dashboard';
        const routeEntry = Object.entries(cleanRouteMap).find(([, route]) => route === segment);
        if (routeEntry) return routeEntry[0];
        return legacyPageMap[segment] || segment.replace('.html', '') || 'dashboard';
    }

    function initializeActiveNavigation() {
        applyCleanRouteLinks();
        const activeKey = getActiveNavKey();

        document.querySelectorAll('[data-nav-key]').forEach(link => {
            const isActive = link.dataset.navKey === activeKey;
            link.classList.toggle('active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    window.FAMNavigation = {
        initializeActiveNavigation,
        applyCleanRouteLinks,
        cleanHref,
        appBasePath,
        appPath,
        apiUrl
    };
})();
