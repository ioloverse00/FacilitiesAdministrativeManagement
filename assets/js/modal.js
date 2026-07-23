(function () {
    let lastFocusedElement = null;

    function getFocusableElements(container) {
        return Array.from(container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'));
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
        });

        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('[data-modal-backdrop]:not(.hidden)').forEach(modal => closeModal(modal.id));
        });
    }

    window.FAMModal = {
        initializeModals,
        openModal,
        closeModal,
        confirmAction,
        showToast
    };

    window.openDashboardModal = openModal;
    window.closeDashboardModal = closeModal;
    window.confirmDashboardAction = confirmAction;
})();
