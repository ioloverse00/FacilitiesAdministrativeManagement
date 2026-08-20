(function () {
    const viewActions = new Set(['view details', 'view record', 'view history', 'view timeline', 'preview', 'view parameters']);
    let lastFocused = null;

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    }

    function title(value) {
        return String(value || '').replace(/[-_]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    function isFacilityRequestTarget(target) {
        return !!target.closest('#facility-requests-table, #facility-request-drawer');
    }

    function isVisitorManagementTarget(target) {
        return !!target.closest('.visitor-management-workspace, #visitor-details-drawer, [data-visitor-action], [data-open-visitor], [data-open-visitor-menu], [data-visitor-menu]');
    }

    function isRoomReservationTarget(target) {
        return !!target.closest('.reservation-workspace, #reservation-details-drawer, [data-open-reservation-details], [data-reservation-menu], [data-reservation-menu-panel], [data-reservation-id]');
    }

    function isDocumentManagementTarget(target) {
        return !!target.closest('.records-workspace, #document-dialog, #document-details-modal, [data-document-action], [data-document-menu-toggle]');
    }

    function isRecordsRetentionTarget(target) {
        return !!target.closest('.retention-workspace, #retention-dialog, #retention-details-modal, [data-retention-action], [data-retention-menu-toggle]');
    }

    function isLegalManagementTarget(target) {
        return !!target.closest('.legal-workspace, #legal-dialog, #legal-details-modal, [data-legal-action], [data-legal-menu-toggle]');
    }

    function actionLabel(target) {
        if (target.dataset.calendarReservation) return 'View Details';
        const explicit = target.dataset.requestAction
            || target.dataset.reservationAction
            || target.dataset.reportAction
            || target.dataset.historyAction
            || target.textContent;
        return String(explicit || '').trim();
    }

    function headerLabel(th, index) {
        const explicit = th.querySelector('.facility-column-trigger span:first-child, [data-sort], button span:first-child')?.textContent?.trim();
        if (explicit) return explicit.replace(/\s+/g, ' ');

        const clone = th.cloneNode(true);
        clone.querySelectorAll('.facility-column-dropdown, .facility-action-dropdown, [role="menu"]').forEach(node => node.remove());
        const text = clone.textContent.trim().replace(/\s+/g, ' ');
        return text || `Field ${index + 1}`;
    }
    function rowData(target) {
        const row = target.closest('tr');
        if (row) {
            const headers = Array.from(row.closest('table')?.querySelectorAll('thead th') || []).map(headerLabel);
            return Array.from(row.children).map((cell, index) => ({
                label: headers[index] || `Field ${index + 1}`,
                value: cell.textContent.trim().replace(/\s+/g, ' ')
            })).filter(item => item.value && item.label.toLowerCase() !== 'actions');
        }

        const card = target.closest('article, .fam-card, .facility-table-card, .report-card, .reservation-event-card');
        if (!card) return [];
        const heading = card.querySelector('h2, h3, strong')?.textContent?.trim();
        const text = Array.from(card.querySelectorAll('p, small, span')).map(el => el.textContent.trim()).filter(Boolean).slice(0, 10);
        return [heading ? { label: 'Title', value: heading } : null, ...text.map((value, index) => ({ label: `Detail ${index + 1}`, value }))].filter(Boolean);
    }

    function modalTitle(target, label) {
        const row = target.closest('tr');
        if (row) {
            const first = row.children[0]?.textContent?.trim().replace(/\s+/g, ' ');
            const second = row.children[1]?.textContent?.trim().replace(/\s+/g, ' ');
            return second || first || label;
        }
        return target.closest('.reservation-event-card, article, .fam-card, .report-card')?.querySelector('h2, h3, strong, small')?.textContent?.trim() || label;
    }

    function ensureModal() {
        let modal = document.getElementById('shared-details-modal');
        if (modal) return modal;
        modal = document.createElement('aside');
        modal.id = 'shared-details-modal';
        modal.className = 'facility-details-modal';
        modal.hidden = true;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'shared-details-modal-title');
        document.body.appendChild(modal);
        return modal;
    }

    function render(label, titleText, data) {
        const modal = ensureModal();
        modal.innerHTML = `<div class="facility-details-modal-panel shared-details-modal-panel">
            <div class="facility-details-modal-header">
                <div>
                    <p>${esc(label)}</p>
                    <span class="facility-details-modal-request-number">${esc(document.body.dataset.pageTitle || document.title || 'Details')}</span>
                    <h2 id="shared-details-modal-title">${esc(titleText || 'Details')}</h2>
                </div>
                <button class="facility-details-modal-close" type="button" data-shared-details-close aria-label="Close details">&times;</button>
            </div>
            <div class="facility-details-modal-body">
                <div class="facility-detail-grid">
                    ${data.length ? data.map(item => `<section><h3>${esc(item.label)}</h3><p>${esc(item.value || 'Not applicable')}</p></section>`).join('') : '<section><h3>Details</h3><p>No additional details available.</p></section>'}
                </div>
            </div>
        </div>`;
        return modal;
    }

    function show(label, titleText, data) {
        const modal = render(label, titleText, data);
        lastFocused = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('facility-details-modal-open');
        modal.querySelector('[data-shared-details-close]')?.focus();
    }

    function open(target) {
        const label = actionLabel(target);
        show(label, modalTitle(target, label), rowData(target));
    }

    function close() {
        const modal = document.getElementById('shared-details-modal');
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        modal.innerHTML = '';
        document.body.classList.remove('facility-details-modal-open');
        lastFocused?.focus?.();
        lastFocused = null;
    }

    function maybeHandle(event) {
        const target = event.target.closest('button, a');
        if (!target || isFacilityRequestTarget(target) || isVisitorManagementTarget(target) || isRoomReservationTarget(target) || isDocumentManagementTarget(target) || isRecordsRetentionTarget(target) || isLegalManagementTarget(target)) return;
        const label = actionLabel(target).toLowerCase();
        if (!viewActions.has(label)) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation?.();
        open(target);
    }

    document.addEventListener('click', maybeHandle, true);
    document.addEventListener('click', event => {
        const modal = document.getElementById('shared-details-modal');
        if (!modal || modal.hidden) return;
        if (event.target.closest('[data-shared-details-close]') || event.target === modal) close();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') close();
    });

    window.FAMDetailsModal = { open, show, close };
})();
