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
        if (form.matches('[data-auth-form]')) return;
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
                    submitBtn.innerHTML = '<span>ÃƒÂ¢Ã…â€œÃ¢â‚¬Å“ Success</span>';
                    
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
    const isPagesRoute = window.location.pathname.includes('/pages/');
    const basePath = () => {
        if (window.FAMNavigation?.appBasePath) return window.FAMNavigation.appBasePath();
        const configuredBase = document.body?.dataset?.appBasePath;
        if (configuredBase) return configuredBase.endsWith('/') ? configuredBase : `${configuredBase}/`;
        return '/';
    };
    const apiPrefix = `${basePath()}api/`;
    const pagesPrefix = isPagesRoute ? '' : 'pages/';

    const userPortalClassification = user => {
        const persona = user?.persona || {};
        const portal = persona.portal === 'employee' || persona.portal === 'fam' ? persona.portal : 'none';
        return {
            portal,
            persona: String(persona.code || 'UNAUTHORIZED_OR_UNRESOLVED'),
            employeeAllowed: persona.is_employee_portal_allowed === true,
            famAllowed: persona.is_fam_portal_allowed === true
        };
    };

    const defaultPortalPath = user => {
        const classification = userPortalClassification(user);
        if (classification.portal === 'employee') return `${pagesPrefix}employee/dashboard.html`;
        if (classification.portal === 'fam') return `${basePath()}dashboard`;
        return `${pagesPrefix}login.html`;
    };

    const normalizeNextPath = next => {
        const value = String(next || '').trim();
        if (!value) return null;
        try {
            const parsed = new URL(value, window.location.href);
            if (parsed.origin !== window.location.origin) return null;
            return `${parsed.pathname}${parsed.search}${parsed.hash}`;
        } catch {
            return null;
        }
    };

    const isEmployeePortalPath = path => /(^|\/)pages\/employee\//.test(path) || /^employee\//.test(path);

    const isFamPortalPath = path => {
        if (isEmployeePortalPath(path)) return false;
        return /(^|\/)pages\/[^/]+\.html/.test(path)
            || /^[^/]+\.html/.test(path)
            || /^\/?(dashboard|facilities-reservation|visitor-management|contract-management|legal-management|document-management|records-retention|fam-administration|under-maintenance)(\/)?$/.test(path);
    };

    const localRedirectPath = path => {
        const normalized = normalizeNextPath(path);
        if (!normalized) return null;
        const pagesIndex = normalized.indexOf('/pages/');
        if (pagesIndex >= 0) return normalized.slice(pagesIndex + 1);
        return normalized.replace(/^\/+/, '');
    };

    const redirectPathAfterLogin = user => {
        const classification = userPortalClassification(user);
        const next = new URLSearchParams(window.location.search).get('next');
        if (!next) return defaultPortalPath(user);
        const decodedNext = localRedirectPath(next);
        if (!decodedNext) return defaultPortalPath(user);
        if (classification.portal === 'employee' && isFamPortalPath(decodedNext)) return defaultPortalPath(user);
        if (classification.portal === 'fam' && isEmployeePortalPath(decodedNext)) return defaultPortalPath(user);
        if (classification.portal === 'none') return defaultPortalPath(user);
        return decodedNext;
    };

    if (subsystem && forgotPasswordLink) {
        forgotPasswordLink.href = `${pagesPrefix}forgot-password.html?subsystem=${encodeURIComponent(subsystem)}`;
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

    authForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submitBtn = authForm.querySelector('button[type="submit"]');
        const errorBox = document.getElementById('auth-error');
        const originalSubmitText = submitBtn?.innerHTML;
        if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; }
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.textContent = 'Sending verification code...';
        }
        try {
            const formData = new FormData(authForm);
            const response = await fetch(`${apiPrefix}auth/login.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: formData.get('username'), password: formData.get('password') })
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || payload?.success === false) throw new Error(payload?.message || 'Sign in failed.');
            if (payload?.data?.mfa_required) {
                renderMfaChallenge(payload.data);
                return;
            }
            window.location.href = redirectPathAfterLogin(payload?.data?.user);
        } catch (error) {
            if (errorBox) { errorBox.textContent = error.message || 'Sign in failed.'; errorBox.hidden = false; }
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                if (originalSubmitText !== undefined) submitBtn.innerHTML = originalSubmitText;
            }
        }
    });

    function renderMfaChallenge(challenge) {
        let challengeId = challenge.challenge_id || '';
        let resend = Number(challenge.resend_cooldown_seconds || 30);
        let remaining = Number(challenge.expires_in_seconds || 60);
        const authCard = authForm.closest('.auth-card');
        authCard?.classList.add('is-mfa');
        authForm.innerHTML = `
            <div class="login-mfa-panel">
                <button class="login-mfa-back" type="button" data-login-mfa-back aria-label="Back to sign in">
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                </button>
                <div class="login-mfa-heading">
                    <h2>Verify your sign-in</h2>
                    <p>We sent a verification code to<br><strong>${escapeHtml(challenge.email_hint || 'your registered email')}</strong></p>
                    <p>Code expires in <strong data-login-mfa-countdown>${remaining}s</strong>.</p>
                </div>
                <fieldset class="login-otp-group" aria-describedby="auth-error">
                    <legend class="sr-only">Verification code</legend>
                    ${Array.from({ length: 6 }, (_, index) => `<input class="login-otp-cell" data-login-otp-cell aria-label="Verification code digit ${index + 1} of 6" inputmode="numeric" autocomplete="${index === 0 ? 'one-time-code' : 'off'}" maxlength="1" pattern="[0-9]" type="text">`).join('')}
                </fieldset>
            </div>
            <div id="auth-error" class="facility-form-error" role="alert" aria-live="polite" hidden></div>
            <div class="login-mfa-actions">
                <button class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-on-primary bg-primary hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-all active:scale-[0.98]" type="button" data-login-mfa-verify>Verify Sign-In</button>
                <p class="login-mfa-resend-copy">Didn't receive a code?<br><button type="button" data-login-mfa-resend>Resend code</button></p>
            </div>
        `;
        const otpCells = Array.from(authForm.querySelectorAll('[data-login-otp-cell]'));
        const box = authForm.querySelector('#auth-error');
        const resendButton = authForm.querySelector('[data-login-mfa-resend]');
        const verifyButton = authForm.querySelector('[data-login-mfa-verify]');
        const countdown = authForm.querySelector('[data-login-mfa-countdown]');
        const otpValue = () => otpCells.map(input => input.value).join('');
        const syncVerifyState = () => {
            if (verifyButton) verifyButton.disabled = otpValue().length !== 6;
        };
        const clearOtp = () => {
            otpCells.forEach(input => { input.value = ''; });
            syncVerifyState();
            otpCells[0]?.focus();
        };
        const fillFrom = (startIndex, digits) => {
            let index = Math.max(0, startIndex);
            digits.replace(/\D/g, '').split('').forEach(digit => {
                if (index >= otpCells.length) return;
                otpCells[index].value = digit;
                index += 1;
            });
            syncVerifyState();
            otpCells[Math.min(index, otpCells.length - 1)]?.focus();
        };
        const renderResend = () => {
            if (countdown) countdown.textContent = `${Math.max(0, remaining)}s`;
            if (!resendButton) return;
            resendButton.disabled = resend > 0;
            resendButton.textContent = resend > 0 ? `Resend code · ${resend}s` : 'Resend code';
        };
        renderResend();
        syncVerifyState();
        const timer = setInterval(() => {
            resend -= 1;
            remaining -= 1;
            renderResend();
            if (!document.body.contains(authForm)) clearInterval(timer);
        }, 1000);
        otpCells.forEach((input, index) => {
            input.addEventListener('input', () => {
                const digits = input.value.replace(/\D/g, '');
                input.value = '';
                if (digits) fillFrom(index, digits);
                else syncVerifyState();
            });
            input.addEventListener('keydown', event => {
                if (event.key === 'Backspace' && input.value === '' && index > 0) {
                    event.preventDefault();
                    otpCells[index - 1].value = '';
                    syncVerifyState();
                    otpCells[index - 1].focus();
                } else if (event.key === 'ArrowLeft' && index > 0) {
                    event.preventDefault();
                    otpCells[index - 1].focus();
                } else if (event.key === 'ArrowRight' && index < otpCells.length - 1) {
                    event.preventDefault();
                    otpCells[index + 1].focus();
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    verify();
                }
            });
            input.addEventListener('paste', event => {
                event.preventDefault();
                const text = (event.clipboardData || window.clipboardData)?.getData('text') || '';
                fillFrom(index, text);
            });
        });
        otpCells[0]?.focus();
        authForm.querySelector('[data-login-mfa-back]')?.addEventListener('click', () => window.location.reload());
        resendButton?.addEventListener('click', async () => {
            if (box) { box.hidden = true; box.textContent = ''; }
            try {
                const response = await fetch(`${apiPrefix}auth/resend-mfa.php`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: '{}'
                });
                const payload = await response.json().catch(() => null);
                if (!response.ok || payload?.success === false) throw new Error(payload?.message || 'Unable to resend code.');
                challengeId = payload.data?.challenge_id || challengeId;
                resend = Number(payload.data?.resend_cooldown_seconds || 30);
                remaining = Number(payload.data?.expires_in_seconds || 60);
                renderResend();
                clearOtp();
            } catch (error) {
                if (box) { box.textContent = error.message || 'Unable to resend code.'; box.hidden = false; }
            }
        });
        const verify = async () => {
            const submit = authForm.querySelector('[data-login-mfa-verify]');
            if (otpValue().length !== 6) return;
            if (box) { box.hidden = true; box.textContent = ''; }
            if (submit) submit.disabled = true;
            try {
                const response = await fetch(`${apiPrefix}auth/verify-mfa.php`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ challenge_id: challengeId, otp: otpValue() })
                });
                const payload = await response.json().catch(() => null);
                if (!response.ok || payload?.success === false) throw new Error(payload?.message || 'Verification failed.');
                window.location.href = redirectPathAfterLogin(payload?.data?.user);
            } catch (error) {
                if (box) { box.textContent = error.message || 'Verification failed.'; box.hidden = false; }
                clearOtp();
            } finally {
                if (submit) submit.disabled = false;
                syncVerifyState();
            }
        };
        authForm.querySelector('[data-login-mfa-verify]')?.addEventListener('click', verify);
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
    }
}





