(function () {
    function getActiveNavKey() {
        if (document.body.dataset.activeNav) {
            return document.body.dataset.activeNav;
        }

        const currentPage = window.location.pathname.split('/').pop().replace('.html', '');
        return currentPage || 'dashboard';
    }

    function initializeActiveNavigation() {
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
        initializeActiveNavigation
    };
})();
