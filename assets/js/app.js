/**
 * ==========================================================================
 * Universal UI Architecture Interactive Script (app.js)
 * Provides dynamic interactions for index.html (search, filter animations)
 * and all 15 component prototypes (password toggle, OTP focus, form feedback).
 * ==========================================================================
 */

document.addEventListener('DOMContentLoaded', () => {
    initCatalogHub();
    initAuthenticationPage();
    initComponentInteractions();
    initSidebarController();
    initProfileDropdown();
});

/**
 * 1. Catalog Hub Logic (index.html)
 * Handles real-time search filtering, URL state synchronization, and smooth transitions.
 */
function initCatalogHub() {
    const searchInput = document.querySelector('.search-box input');
    const cards = document.querySelectorAll('.component-card');
    const filterRadios = document.querySelectorAll('.filter-radio');
    const filterLabels = document.querySelectorAll('.filter-btn');

    if (!cards.length) return; // Exit if not on index.html catalog

    let currentFilter = 'all';
    let currentSearch = '';

    // Remove readonly attribute if present so user can type search queries
    if (searchInput) {
        searchInput.removeAttribute('readonly');
        searchInput.setAttribute('placeholder', 'Search templates or auth (Press "/" to focus)...');
    }

    // Determine initial state from URL parameters or hash
    const urlParams = new URLSearchParams(window.location.search);
    const initialGroup = urlParams.get('group') || window.location.hash.replace('#', '');
    if (initialGroup) {
        const targetRadio = document.querySelector(`.filter-radio#filter-${initialGroup.toLowerCase()}`) || 
                            document.querySelector(`.filter-radio[id*="${initialGroup.toLowerCase()}"]`);
        if (targetRadio) {
            targetRadio.checked = true;
            currentFilter = initialGroup;
        }
    }

    // Core Filtering Function
    const applyFilters = () => {
        cards.forEach(card => {
            const cardGroup = card.getAttribute('data-group') || '';
            const cardTitle = card.querySelector('.card-title')?.textContent || '';
            const cardDesc = card.querySelector('.card-desc')?.textContent || '';
            const cardText = (cardTitle + ' ' + cardDesc + ' ' + cardGroup).toLowerCase();

            // Check group match
            const matchesGroup = (currentFilter.toLowerCase() === 'all') || 
                                 (cardGroup.toLowerCase() === currentFilter.toLowerCase());

            // Check search query match
            const matchesSearch = !currentSearch || cardText.includes(currentSearch);

            if (matchesGroup && matchesSearch) {
                card.style.display = 'flex';
                card.style.opacity = '0';
                card.style.transform = 'translateY(12px)';
                requestAnimationFrame(() => {
                    card.style.transition = 'all 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                });
            } else {
                card.style.display = 'none';
            }
        });

        // Update URL parameter without reloading
        const url = new URL(window.location);
        if (currentFilter.toLowerCase() === 'all' && !currentSearch) {
            url.searchParams.delete('group');
            url.hash = '';
        } else if (currentFilter.toLowerCase() !== 'all') {
            url.searchParams.set('group', currentFilter);
        }
        window.history.replaceState({}, '', url);
    };

    // Listen to Radio Button Filter Changes
    filterRadios.forEach(radio => {
        radio.addEventListener('change', (e) => {
            if (e.target.checked) {
                const id = e.target.id.replace('filter-', '');
                if (id === 'all') currentFilter = 'all';
                else if (id === 'auth') currentFilter = 'Authentication';
                else if (id === 'comp') currentFilter = 'Components';
                else if (id === 'layout') currentFilter = 'Layouts';
                else if (id === 'system') currentFilter = 'Design Systems';
                applyFilters();
            }
        });
    });

    // Listen to Search Input
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            currentSearch = e.target.value.toLowerCase().trim();
            applyFilters();
        });

        // Keyboard shortcut: Press '/' to focus search box
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && document.activeElement !== searchInput) {
                e.preventDefault();
                searchInput.focus();
            } else if (e.key === 'Escape' && document.activeElement === searchInput) {
                searchInput.value = '';
                currentSearch = '';
                searchInput.blur();
                applyFilters();
            }
        });
    }

    // Add click ripple and scale effect to View buttons
    document.querySelectorAll('.btn-view').forEach(btn => {
        btn.addEventListener('click', function(e) {
            this.style.transform = 'scale(0.95)';
            setTimeout(() => { this.style.transform = ''; }, 150);
        });
    });
}

/**
 * 1.5. Subsystem Module Selector & Dashboard
 *
 * Removed: the Module Selector is now a standalone page under module_selector/code.html.
 */

function initComponentInteractions() {
    // A. Password Visibility Toggle
    const passwordInputs = document.querySelectorAll('input[type="password"]');
    passwordInputs.forEach(input => {
        const parent = input.parentElement;
        if (!parent) return;

        // Check if there is an icon or button next to it
        const toggleIcon = parent.querySelector('svg, .material-symbols-outlined, button');
        if (toggleIcon) {
            toggleIcon.style.cursor = 'pointer';
            toggleIcon.setAttribute('title', 'Toggle Password Visibility');
            toggleIcon.addEventListener('click', () => {
                if (input.type === 'password') {
                    input.type = 'text';
                    toggleIcon.style.opacity = '1';
                    toggleIcon.style.color = 'var(--primary)';
                } else {
                    input.type = 'password';
                    toggleIcon.style.opacity = '0.7';
                    toggleIcon.style.color = '';
                }
            });
        }
    });

    // B. MFA OTP Auto-Focus & Auto-Advance
    const otpInputs = document.querySelectorAll('input[maxlength="1"], input[data-otp], .otp-input');
    if (otpInputs.length > 1) {
        otpInputs.forEach((input, index) => {
            input.setAttribute('autocomplete', 'off');
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    otpInputs[index - 1].focus();
                } else if (e.key === 'ArrowLeft' && index > 0) {
                    otpInputs[index - 1].focus();
                } else if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });

            // Handle paste event for full OTP code
            input.addEventListener('paste', (e) => {
                const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim();
                if (pasteData.length === otpInputs.length && /^\d+$/.test(pasteData)) {
                    e.preventDefault();
                    pasteData.split('').forEach((char, i) => {
                        if (otpInputs[i]) otpInputs[i].value = char;
                    });
                    otpInputs[otpInputs.length - 1].focus();
                }
            });
        });
    }

    // C. Form Submission & Action Feedback
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', (e) => {
            e.preventDefault(); // Prevent page reload on static prototype
            const submitBtn = form.querySelector('button[type="submit"], input[type="submit"], .btn-primary');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.8';
                submitBtn.innerHTML = '<span>Processing...</span>';
                
                setTimeout(() => {
                    submitBtn.style.backgroundColor = '#059669'; // Success green
                    submitBtn.style.borderColor = '#059669';
                    submitBtn.style.color = '#ffffff';
                    submitBtn.innerHTML = '<span>✓ Success</span>';
                    
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.style.backgroundColor = '';
                        submitBtn.style.borderColor = '';
                        submitBtn.style.color = '';
                        submitBtn.style.opacity = '1';
                        submitBtn.innerHTML = originalText;
                        form.reset();
                    }, 1500);
                }, 800);
            }
        });
    });

    // D. Interactive Input Focus Glow
    const allInputs = document.querySelectorAll('input, select, textarea');
    allInputs.forEach(input => {
        input.addEventListener('focus', () => {
            const label = document.querySelector(`label[for="${input.id}"]`) || input.previousElementSibling;
            if (label && label.tagName === 'LABEL') {
                label.style.color = 'var(--primary)';
                label.style.transition = 'color 0.2s ease';
            }
        });
        input.addEventListener('blur', () => {
            const label = document.querySelector(`label[for="${input.id}"]`) || input.previousElementSibling;
            if (label && label.tagName === 'LABEL') {
                label.style.color = '';
            }
        });
    });
}

/**
 * 3. Sidebar Open/Close Controller
 * Replicates the smooth collapsible desktop sidebar and slide-over mobile navigation drawer
 * from the Laravel Breeze UI theme without external framework dependencies.
 */
function initSidebarController() {
    const sidebar = document.getElementById('app-sidebar');
    const desktopToggle = document.getElementById('desktop-sidebar-toggle');
    const mobileToggle = document.getElementById('mobile-sidebar-toggle');
    const mobileClose = document.getElementById('close-mobile-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const toggleIcon = document.getElementById('sidebar-toggle-icon');

    if (!sidebar) return;

    const expandedWidth = sidebar.dataset.expandedWidth || '16rem';
    const collapsedWidthClass = 'w-0';
    const collapsedClasses = [collapsedWidthClass, 'border-r-0', 'opacity-0', 'pointer-events-none', 'md:opacity-0', 'md:border-r-0', 'md:w-0'];

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

    let isDesktopOpen = true;
    let isMobileOpen = false;

    const updateIcon = () => {
        if (!toggleIcon) return;
        if (window.innerWidth < 768) {
            toggleIcon.textContent = isMobileOpen ? 'menu_open' : 'menu';
        } else {
            toggleIcon.textContent = isDesktopOpen ? 'menu_open' : 'menu';
        }
    };

    // Initialize icon on page load
    if (window.innerWidth >= 768) {
        setSidebarLayoutWidth(expandedWidth);
    }
    updateIcon();

    const openMobile = () => {
        isMobileOpen = true;
        resetSidebarLayoutWidth();
        sidebar.classList.remove('-translate-x-full', ...collapsedClasses);
        sidebar.classList.add('translate-x-0', 'border-r', 'opacity-100');
        if (backdrop) {
            backdrop.classList.remove('hidden', 'opacity-0');
            backdrop.classList.add('block', 'opacity-100', 'pointer-events-auto');
        }
        updateIcon();
        if (desktopToggle) desktopToggle.setAttribute('title', 'Close Sidebar');
    };

    const closeMobile = () => {
        isMobileOpen = false;
        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('-translate-x-full');
        if (backdrop) {
            backdrop.classList.remove('block', 'opacity-100', 'pointer-events-auto');
            backdrop.classList.add('hidden', 'opacity-0');
        }
        updateIcon();
        if (desktopToggle) desktopToggle.setAttribute('title', 'Open Navigation');
    };

    const toggleHandler = () => {
        if (window.innerWidth < 768) {
            if (isMobileOpen) {
                closeMobile();
            } else {
                openMobile();
            }
        } else {
            isDesktopOpen = !isDesktopOpen;
            if (isDesktopOpen) {
                sidebar.classList.remove(...collapsedClasses);
                setSidebarLayoutWidth(expandedWidth);
                sidebar.classList.add('border-r', 'opacity-100');
                if (desktopToggle) desktopToggle.setAttribute('title', 'Close Sidebar');
            } else {
                setSidebarLayoutWidth('0px');
                sidebar.classList.remove('border-r', 'opacity-100');
                sidebar.classList.add(...collapsedClasses);
                if (desktopToggle) desktopToggle.setAttribute('title', 'Open Sidebar');
            }
            updateIcon();
        }
    };

    if (desktopToggle) desktopToggle.addEventListener('click', toggleHandler);
    if (mobileToggle && mobileToggle !== desktopToggle) mobileToggle.addEventListener('click', toggleHandler);
    if (mobileClose) mobileClose.addEventListener('click', closeMobile);
    if (backdrop) backdrop.addEventListener('click', closeMobile);

    // Responsive Breakpoint Reset on Window Resize
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            isMobileOpen = false;
            setSidebarLayoutWidth(isDesktopOpen ? expandedWidth : '0px');
            sidebar.classList.remove('-translate-x-full', 'translate-x-0');
            if (backdrop) {
                backdrop.classList.remove('block', 'opacity-100', 'pointer-events-auto');
                backdrop.classList.add('hidden', 'opacity-0');
            }
        } else {
            resetSidebarLayoutWidth();
        }
        updateIcon();
    });
}

/**
 * Profile Dropdown Controller
 * Handles toggling the account profile dropdown menu in the header and closing when clicking outside or pressing Escape.
 */
function initProfileDropdown() {
    const toggleBtn = document.getElementById('profile-dropdown-toggle');
    const dropdownMenu = document.getElementById('profile-dropdown-menu');

    if (!toggleBtn || !dropdownMenu) return;

    let isOpen = false;

    const openDropdown = () => {
        isOpen = true;
        dropdownMenu.classList.remove('hidden', 'opacity-0', 'scale-95');
        dropdownMenu.classList.add('block', 'opacity-100', 'scale-100');
        toggleBtn.setAttribute('aria-expanded', 'true');
        const arrow = toggleBtn.querySelector('.material-symbols-outlined');
        if (arrow && arrow.textContent === 'expand_more') {
            arrow.textContent = 'expand_less';
        }
    };

    const closeDropdown = () => {
        if (!isOpen) return;
        isOpen = false;
        dropdownMenu.classList.remove('block', 'opacity-100', 'scale-100');
        dropdownMenu.classList.add('opacity-0', 'scale-95');
        toggleBtn.setAttribute('aria-expanded', 'false');
        const arrow = toggleBtn.querySelector('.material-symbols-outlined');
        if (arrow && arrow.textContent === 'expand_less') {
            arrow.textContent = 'expand_more';
        }
        setTimeout(() => {
            if (!isOpen) dropdownMenu.classList.add('hidden');
        }, 200);
    };

    toggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (isOpen) {
            closeDropdown();
        } else {
            openDropdown();
        }
    });

    document.addEventListener('click', (e) => {
        if (isOpen && !dropdownMenu.contains(e.target) && !toggleBtn.contains(e.target)) {
            closeDropdown();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen) {
            closeDropdown();
        }
    });
}

function initAuthenticationPage() {
    const authForm = document.querySelector('[data-auth-form]');
    const passwordInput = document.querySelector('[data-password-input]');
    const passwordToggle = document.querySelector('[data-password-toggle]');
    const visibilityIcon = document.querySelector('[data-visibility-icon]');
    const forgotPasswordLink = document.getElementById('forgot-password-link');

    if (!authForm) return;

    const params = new URLSearchParams(window.location.search);
    const subsystem = params.get('subsystem');

    if (subsystem && forgotPasswordLink) {
        forgotPasswordLink.href = `forgot_password_card_component_standard.html?subsystem=${encodeURIComponent(subsystem)}`;
    }

    if (passwordInput && passwordToggle) {
        passwordToggle.addEventListener('click', () => {
            const isPasswordHidden = passwordInput.type === 'password';
            passwordInput.type = isPasswordHidden ? 'text' : 'password';

            if (visibilityIcon) {
                visibilityIcon.textContent = isPasswordHidden ? 'visibility_off' : 'visibility';
            }
        });
    }

    authForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const currentParams = new URLSearchParams(window.location.search);
        const selectedSubsystem = currentParams.get('subsystem') || 'client-management';

        window.location.href = `mfa_otp_verification_card_standard.html?subsystem=${encodeURIComponent(selectedSubsystem)}`;
    });
}
