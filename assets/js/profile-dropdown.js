(function () {
    function initializeProfileDropdown() {
        const toggleBtn = document.getElementById('profile-dropdown-toggle');
        const dropdownMenu = document.getElementById('profile-dropdown-menu');

        if (!toggleBtn || !dropdownMenu) return;

        let isOpen = false;

        const getMenuItems = () => Array.from(dropdownMenu.querySelectorAll('a, button'));

        const openDropdown = () => {
            isOpen = true;
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
            if (isOpen && !dropdownMenu.contains(event.target) && !toggleBtn.contains(event.target)) {
                closeDropdown();
            }
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && isOpen) {
                closeDropdown();
                toggleBtn.focus();
            }
        });
    }

    window.FAMProfileDropdown = {
        initializeProfileDropdown
    };
})();
