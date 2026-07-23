(function () {
    function initializeSidebar() {
        const sidebar = document.getElementById('app-sidebar');
        const desktopToggle = document.getElementById('desktop-sidebar-toggle');
        const mobileToggle = document.getElementById('mobile-sidebar-toggle');
        const mobileClose = document.getElementById('close-mobile-sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        const toggleIcon = document.getElementById('sidebar-toggle-icon');

        if (!sidebar) return;

        const expandedWidth = sidebar.dataset.expandedWidth || '18rem';
        const collapsedClasses = ['w-0', 'border-r-0', 'opacity-0', 'pointer-events-none', 'md:opacity-0', 'md:border-r-0', 'md:w-0'];
        let isDesktopOpen = true;
        let isMobileOpen = false;

        const setToggleState = isOpen => {
            if (desktopToggle) {
                desktopToggle.setAttribute('aria-expanded', String(isOpen));
                desktopToggle.setAttribute('title', isOpen ? 'Close Sidebar' : 'Open Sidebar');
            }
            if (mobileToggle) {
                mobileToggle.setAttribute('aria-expanded', String(isOpen));
            }
            if (toggleIcon) {
                toggleIcon.textContent = isOpen ? 'menu_open' : 'menu';
            }
        };

        const setSidebarLayoutWidth = value => {
            sidebar.style.width = value;
            sidebar.style.minWidth = value;
            sidebar.style.flexBasis = value;
        };

        const resetSidebarLayoutWidth = () => {
            sidebar.style.width = '';
            sidebar.style.minWidth = '';
            sidebar.style.flexBasis = '';
        };

        const openMobile = () => {
            isMobileOpen = true;
            resetSidebarLayoutWidth();
            sidebar.classList.remove('-translate-x-full', ...collapsedClasses);
            sidebar.classList.add('translate-x-0', 'border-r', 'opacity-100');
            if (backdrop) {
                backdrop.classList.remove('hidden', 'opacity-0');
                backdrop.classList.add('block', 'opacity-100', 'pointer-events-auto');
            }
            setToggleState(true);
        };

        const closeMobile = () => {
            isMobileOpen = false;
            sidebar.classList.remove('translate-x-0');
            sidebar.classList.add('-translate-x-full');
            if (backdrop) {
                backdrop.classList.remove('block', 'opacity-100', 'pointer-events-auto');
                backdrop.classList.add('hidden', 'opacity-0');
            }
            setToggleState(false);
        };

        const toggleDesktop = () => {
            isDesktopOpen = !isDesktopOpen;
            if (isDesktopOpen) {
                sidebar.classList.remove(...collapsedClasses);
                sidebar.classList.add('border-r', 'opacity-100');
                setSidebarLayoutWidth(expandedWidth);
            } else {
                sidebar.classList.remove('border-r', 'opacity-100');
                sidebar.classList.add(...collapsedClasses);
                setSidebarLayoutWidth('0px');
            }
            setToggleState(isDesktopOpen);
        };

        const toggleHandler = () => {
            if (window.innerWidth < 768) {
                isMobileOpen ? closeMobile() : openMobile();
                return;
            }
            toggleDesktop();
        };

        if (window.innerWidth >= 768) {
            setSidebarLayoutWidth(expandedWidth);
        }
        setToggleState(window.innerWidth >= 768 ? isDesktopOpen : isMobileOpen);

        if (desktopToggle) desktopToggle.addEventListener('click', toggleHandler);
        if (mobileToggle && mobileToggle !== desktopToggle) mobileToggle.addEventListener('click', toggleHandler);
        if (mobileClose) mobileClose.addEventListener('click', closeMobile);
        if (backdrop) backdrop.addEventListener('click', closeMobile);

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && isMobileOpen) {
                closeMobile();
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                isMobileOpen = false;
                setSidebarLayoutWidth(isDesktopOpen ? expandedWidth : '0px');
                sidebar.classList.remove('-translate-x-full', 'translate-x-0');
                if (backdrop) {
                    backdrop.classList.remove('block', 'opacity-100', 'pointer-events-auto');
                    backdrop.classList.add('hidden', 'opacity-0');
                }
                setToggleState(isDesktopOpen);
            } else {
                resetSidebarLayoutWidth();
                setToggleState(isMobileOpen);
            }
        });
    }

    window.FAMSidebar = {
        initializeSidebar
    };
})();
