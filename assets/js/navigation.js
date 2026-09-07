(function () {
    const cleanRouteMap = {
        dashboard: 'dashboard',
        'room-reservations': 'facilities-reservation',
        'visitor-management': 'visitor-management',
        'contract-management': 'contract-management',
        'legal-management': 'legal-management',
        records: 'document-management',
        'records-retention': 'records-retention',
        settings: 'fam-administration',
        'under-maintenance': 'under-maintenance'
    };

    const legacyPageMap = {
        'dashboard.html': 'dashboard',
        'room-reservations.html': 'room-reservations',
        'visitor-management.html': 'visitor-management',
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

        const path = window.location.pathname;
        const pagesIndex = path.indexOf('/pages/');
        if (pagesIndex >= 0) return path.slice(0, pagesIndex + 1);

        const cleanRoutePattern = /\/(?:dashboard|facilities-reservation|visitor-management|contract-management|legal-management|document-management|records-retention|fam-administration|under-maintenance)\/?$/;
        if (cleanRoutePattern.test(path)) return path.replace(cleanRoutePattern, '/');

        return path.endsWith('/') ? path : path.replace(/[^/]*$/, '');
    }

    function cleanHref(routeKey) {
        const route = cleanRouteMap[routeKey] || routeKey;
        if (!route) return appBasePath();
        return `${appBasePath()}${route}`;
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
        appBasePath
    };
})();
