(function () {
    const componentPaths = {
        sidebar: '../components/sidebar.html',
        header: '../components/header.html',
        footer: '../components/footer.html'
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

    async function initializeLayout() {
        try {
            await Promise.all([
                loadComponent('#app-sidebar-slot', componentPaths.sidebar),
                loadComponent('#app-header-slot', componentPaths.header),
                loadComponent('#app-footer-slot', componentPaths.footer)
            ]);

            applyPageMetadata();
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

    document.addEventListener('DOMContentLoaded', initializeLayout);

    window.FAMLayout = {
        loadComponent,
        initializeLayout,
        applyPageMetadata
    };
})();
