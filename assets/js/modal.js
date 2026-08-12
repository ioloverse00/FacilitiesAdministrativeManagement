(function () {
    let lastFocusedElement = null;

    function getFocusableElements(container) {
        return Array.from(container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
            .filter(element => !element.disabled && element.offsetParent !== null);
    }

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    }

    function visibleModalElements() {
        return Array.from(document.querySelectorAll('.facility-dialog, .facility-details-modal, .facility-drawer, .admin-org-drawer-backdrop, [data-modal-backdrop]'))
            .filter(element => !element.hidden && !element.classList.contains('hidden'));
    }

    function syncModalScrollLock() {
        document.body.classList.toggle('fam-modal-open', visibleModalElements().length > 0);
    }

    function trapFocus(event, container) {
        if (event.key !== 'Tab') return;
        const focusable = getFocusableElements(container);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function observeSharedModalState() {
        const observer = new MutationObserver(syncModalScrollLock);
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['hidden', 'class']
        });
        syncModalScrollLock();
    }

    function openModal(modalId, targetName) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        const deleteTarget = document.getElementById('delete-target-name');
        if (targetName && deleteTarget) {
            deleteTarget.textContent = targetName;
        }

        lastFocusedElement = document.activeElement;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        void modal.offsetWidth;
        modal.classList.remove('opacity-0');
        modal.classList.add('opacity-100');

        const dialog = modal.querySelector('[role="dialog"]') || modal.firstElementChild;
        if (dialog) {
            dialog.classList.remove('scale-95');
            dialog.classList.add('scale-100');
        }

        document.body.style.overflow = 'hidden';
        syncModalScrollLock();
        getFocusableElements(modal)[0]?.focus();
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        modal.setAttribute('aria-hidden', 'true');

        const dialog = modal.querySelector('[role="dialog"]') || modal.firstElementChild;
        if (dialog) {
            dialog.classList.remove('scale-100');
            dialog.classList.add('scale-95');
        }

        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
            syncModalScrollLock();
            lastFocusedElement?.focus?.();
        }, 200);
    }

    function showToast(toastMessage) {
        const container = document.getElementById('dash-toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'bg-surface text-on-surface px-5 py-3.5 rounded-2xl shadow-xl border border-outline-variant/40 flex items-center gap-3 transform translate-y-4 opacity-0 transition-all duration-300 pointer-events-auto';
        toast.innerHTML = '<div class="w-7 h-7 rounded-full bg-green-100 text-green-700 flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-sm font-bold">check</span></div><span class="text-sm font-medium"></span>';
        toast.querySelector('span:last-child').textContent = toastMessage;
        container.appendChild(toast);
        void toast.offsetWidth;
        toast.classList.remove('translate-y-4', 'opacity-0');
        setTimeout(() => {
            toast.classList.add('translate-y-4', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    function confirmAction(modalId, toastMessage) {
        closeModal(modalId);
        showToast(toastMessage);
    }

    function ensureActionDialog() {
        let modal = document.getElementById('fam-action-dialog');
        if (modal) return modal;
        modal = document.createElement('aside');
        modal.id = 'fam-action-dialog';
        modal.className = 'facility-dialog fam-action-dialog';
        modal.hidden = true;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'fam-action-dialog-title');
        document.body.appendChild(modal);
        return modal;
    }

    function actionDialog(options = {}) {
        return new Promise(resolve => {
            const modal = ensureActionDialog();
            const needsInput = options.input === true;
            const title = options.title || 'Confirm Action';
            const message = options.message || 'Continue with this action?';
            const confirmLabel = options.confirmLabel || 'Continue';
            const cancelLabel = options.cancelLabel || 'Cancel';
            const defaultValue = options.defaultValue || '';
            lastFocusedElement = document.activeElement;
            modal.hidden = false;
            modal.innerHTML = `
                <form class="facility-dialog-panel fam-action-dialog-panel">
                    <div class="facility-details-modal-header">
                        <div>
                            <p>${needsInput ? 'Input Required' : 'Confirmation'}</p>
                            <h2 id="fam-action-dialog-title">${esc(title)}</h2>
                        </div>
                        <button class="facility-details-modal-close" type="button" data-action-dialog-cancel aria-label="Close dialog">&times;</button>
                    </div>
                    <div class="fam-action-dialog-body">
                        <p>${esc(message)}</p>
                        ${needsInput ? `<label class="facility-field"><span>${esc(options.inputLabel || 'Remarks')}</span><textarea name="value" rows="3">${esc(defaultValue)}</textarea></label>` : ''}
                    </div>
                    <div class="facility-dialog-actions">
                        <button class="btn-secondary dashboard-action-button" type="button" data-action-dialog-cancel>${esc(cancelLabel)}</button>
                        <button class="btn-primary dashboard-action-button" type="submit">${esc(confirmLabel)}</button>
                    </div>
                </form>
            `;
            const finish = value => {
                animateElementClose(modal, () => {
                    modal.innerHTML = '';
                    lastFocusedElement?.focus?.();
                    lastFocusedElement = null;
                    resolve(value);
                });
            };
            modal.querySelector('[data-action-dialog-cancel]')?.focus();
            modal.querySelectorAll('[data-action-dialog-cancel]').forEach(button => button.addEventListener('click', () => finish(null), { once: true }));
            modal.querySelector('form')?.addEventListener('submit', event => {
                event.preventDefault();
                finish(needsInput ? modal.querySelector('[name="value"]')?.value ?? '' : true);
            }, { once: true });
        });
    }

    function confirmDialog(message, options = {}) {
        return actionDialog({ ...options, message, input: false });
    }

    function promptDialog(message, defaultValue = '', options = {}) {
        return actionDialog({ ...options, message, defaultValue, input: true });
    }

    function closeTableMenus(exceptPanel = null) {
        document.querySelectorAll('.facility-action-dropdown[data-fam-menu-open="true"]').forEach(panel => {
            if (panel === exceptPanel) return;
            panel.classList.add('hidden');
            panel.dataset.famMenuOpen = 'false';
            const triggerId = panel.dataset.famMenuTrigger;
            if (triggerId) document.getElementById(triggerId)?.setAttribute('aria-expanded', 'false');
        });
    }

    function positionTableMenu(toggle, panel) {
        if (!toggle || !panel) return;
        if (!toggle.id) toggle.id = 'fam-table-menu-' + Math.random().toString(36).slice(2);
        if (panel.parentElement !== document.body) document.body.appendChild(panel);
        panel.dataset.famMenuTrigger = toggle.id;
        panel.style.position = 'fixed';
        panel.style.zIndex = '10050';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
        panel.style.width = 'max-content';
        panel.style.maxWidth = 'min(16rem, calc(100vw - 24px))';
        panel.style.visibility = 'hidden';
        panel.classList.remove('hidden');
        const toggleRect = toggle.getBoundingClientRect();
        const panelRect = panel.getBoundingClientRect();
        const top = Math.min(window.innerHeight - panelRect.height - 12, toggleRect.bottom + 8);
        const left = Math.max(12, Math.min(window.innerWidth - panelRect.width - 12, toggleRect.right - panelRect.width));
        panel.style.top = `${Math.max(12, top)}px`;
        panel.style.left = `${left}px`;
        panel.style.visibility = '';
    }

    function toggleTableMenu(toggle, panel) {
        if (!toggle || !panel) return;
        const willOpen = panel.classList.contains('hidden') || panel.dataset.famMenuOpen !== 'true';
        closeTableMenus(panel);
        if (!willOpen) {
            panel.classList.add('hidden');
            panel.dataset.famMenuOpen = 'false';
            toggle.setAttribute('aria-expanded', 'false');
            return;
        }
        positionTableMenu(toggle, panel);
        panel.dataset.famMenuOpen = 'true';
        toggle.setAttribute('aria-expanded', 'true');
    }

    function animateElementClose(element, afterClose) {
        if (!element || element.hidden || element.classList.contains('fam-modal-closing')) return;
        element.classList.add('fam-modal-closing');
        window.setTimeout(() => {
            element.classList.remove('fam-modal-closing');
            element.hidden = true;
            afterClose?.();
            syncModalScrollLock();
        }, 150);
    }

    function initializeModals() {
        document.addEventListener('click', event => {
            const opener = event.target.closest('[data-open-modal]');
            const closer = event.target.closest('[data-close-modal]');
            const confirmer = event.target.closest('[data-confirm-modal]');
            const modalBackdrop = event.target.matches('[data-modal-backdrop]') ? event.target : null;

            if (opener) {
                openModal(opener.dataset.openModal, opener.dataset.modalTargetName);
            }
            if (closer) {
                closeModal(closer.dataset.closeModal);
            }
            if (confirmer) {
                confirmAction(confirmer.dataset.confirmModal, confirmer.dataset.toastMessage || 'Action completed.');
            }
            if (modalBackdrop) {
                closeModal(modalBackdrop.id);
            }

            if (!event.target.closest('.facility-action-dropdown') && !event.target.closest('.facility-action-toggle')) {
                closeTableMenus();
            }
        });

        document.addEventListener('keydown', event => {
            const topModal = visibleModalElements().at(-1);
            if (topModal) trapFocus(event, topModal);
            if (event.key !== 'Escape') return;
            closeTableMenus();
            document.querySelectorAll('[data-modal-backdrop]:not(.hidden)').forEach(modal => closeModal(modal.id));
        });

        window.addEventListener('resize', () => closeTableMenus());
        document.addEventListener('scroll', () => closeTableMenus(), true);

        observeSharedModalState();
    }

    window.FAMModal = {
        initializeModals,
        openModal,
        closeModal,
        confirmAction,
        showToast,
        confirm: confirmDialog,
        prompt: promptDialog
    };

    window.FAMModal.closeElement = animateElementClose;

    window.FAMTableMenus = {
        close: closeTableMenus,
        toggle: toggleTableMenu,
        position: positionTableMenu
    };

    window.openDashboardModal = openModal;
    window.closeDashboardModal = closeModal;
    window.confirmDashboardAction = confirmAction;
})();
