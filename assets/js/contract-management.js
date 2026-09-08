(function () {
    const state = { page: 1, totalPages: 1, sort: 'updated_at', direction: 'desc', options: {}, activeItem: null, loading: false, hasLoaded: false };
    let authoringState = null;
    let activePlaceholder = null;
    let returnToDetailsAfterAuthoring = null;
    let requirementUploadTrigger = null;
    let signedContractUploadTrigger = null;
    let dateReviewTrigger = null;
    const requirementUploadsInProgress = new Set();
    const qs = selector => document.querySelector(selector);
    const qsa = selector => Array.from(document.querySelectorAll(selector));
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const api = path => `../api/${path}`;
    const can = permission => (window.FAMApi?.currentUser?.permissions || []).includes(permission) || (window.FAMApi?.currentUser?.permissions || []).includes('contract.manage');

    function moveToTopLayer(element) {
        if (element && element.parentElement !== document.body) document.body.appendChild(element);
        return window.FAMModal?.bringToFront?.(element) || element;
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
    }

    function isCancelled(item) {
        return String(item?.status || '').toUpperCase() === 'CANCELLED';
    }

    function templateTypeLabel(value) {
        return {
            CLIENT_CONTRACT: 'Client Contract',
            EMPLOYEE_CONTRACT: 'Employee Contract',
            NDA: 'NDA / Confidentiality Agreement',
            CONTRACT_AMENDMENT: 'Contract Amendment',
            OTHER: 'Other',
        }[String(value || '').toUpperCase()] || title(value);
    }

    function contractTypeLabel(value) {
        return {
            CLIENT_CONTRACT: 'Client Contract',
            EMPLOYEE_CONTRACT: 'Employee Contract',
            NDA: 'NDA / Confidentiality Agreement',
            CONTRACT_AMENDMENT: 'Contract Amendment',
            OTHER: 'Other',
            SERVICE: 'Service Contract',
            SUPPLY: 'Supply Contract',
            LEASE: 'Lease Agreement',
            MAINTENANCE: 'Maintenance Contract',
        }[String(value || '').toUpperCase()] || title(value);
    }

    function confidentialityLabel(value) {
        return {
            PUBLIC: 'Public',
            INTERNAL: 'Internal',
            CONFIDENTIAL: 'Confidential',
        }[String(value || '').toUpperCase()] || title(value);
    }

    function fileSize(bytes) {
        const size = Number(bytes || 0);
        if (!size) return '';
        if (size < 1024) return `${size} B`;
        if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    }

    function fmtDate(value) {
        if (!value) return 'Not applicable';
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function fmt(value) {
        if (!value) return 'Not applicable';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function money(financial = {}) {
        const amount = Number(financial.currentAmount ?? 0);
        return `${esc(financial.currencyCode || 'PHP')} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function badge(value, kind = 'status') {
        const raw = String(value || 'NONE');
        const aliases = {
            FOR_REVIEW: 'under-review',
            FOR_APPROVAL: 'pending-approval',
            TERMINATED: 'rejected',
            REJECTED: 'rejected',
            ACTIVE: 'active',
            APPROVED: 'approved',
            DRAFT: 'draft',
            CANCELLED: 'cancelled',
            ARCHIVED: 'archived',
            EXPIRED: 'expired',
            NORMAL: 'on-track',
            EXPIRING_SOON: 'due-soon',
            NOT_APPLICABLE: 'not-required',
            PENDING: 'pending-approval',
            'PENDING / CURRENT': 'pending-approval',
            WAITING: 'on-hold',
        };
        const key = raw.toUpperCase().replace(/\s+/g, '_');
        const cls = aliases[key] || raw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-${kind}-${cls}">${esc(title(raw))}</span>`;
    }

    function expiryIndicator(item = {}) {
        const status = String(item.status || '').toUpperCase();
        const stateValue = String(item.derived?.expiryState || '').toUpperCase();
        if (!['APPROVED','ACTIVE','EXPIRED','TERMINATED'].includes(status)) return '';
        if (!stateValue || stateValue === 'NOT_APPLICABLE') return '';
        return badge(stateValue, 'status');
    }

    function registerEndDate(item = {}) {
        const status = String(item.status || '').toUpperCase();
        if (['DRAFT','FOR_REVIEW','FOR_APPROVAL','REJECTED','CANCELLED'].includes(status)) {
            return 'Not Applicable';
        }
        return fmtDate(item.dates?.endDate);
    }

    function params() {
        const p = new URLSearchParams({ page: state.page, per_page: 10, sort: state.sort, direction: state.direction });
        const search = qs('#contract-search')?.value.trim();
        if (search) p.set('search', search);
        const map = {
            '#contract-status-filter': 'contract_status',
            '#contract-type-filter': 'contract_type_id',
            '#contract-expiry-filter': 'expiry_state',
        };
        Object.entries(map).forEach(([selector, key]) => {
            const value = qs(selector)?.value;
            if (value && value !== 'all') p.set(key, value);
        });
        return p;
    }

    function exportUrl() {
        const p = new URLSearchParams({ report: 'contracts' });
        const search = qs('#contract-search')?.value.trim();
        const status = qs('#contract-status-filter')?.value;
        const type = qs('#contract-type-filter')?.value;
        const expiry = qs('#contract-expiry-filter')?.value;
        if (search) p.set('search', search);
        if (status && status !== 'all') p.set('status', status);
        if (type && type !== 'all') p.set('type_id', type);
        if (expiry && expiry !== 'all') p.set('expiry_state', expiry);
        return `../api/reports/export-csv.php?${p}`;
    }

    async function load() {
        if (state.loading) return;
        state.loading = true;
        const initial = !state.hasLoaded;
        const body = qs('#contract-table');
        body?.closest('.facility-table-card')?.setAttribute('aria-busy', 'true');
        qs('#contract-refresh')?.setAttribute('aria-busy', 'true');
        qs('#contract-refresh')?.setAttribute('disabled', 'disabled');
        if (initial && body) body.innerHTML = tableStateRow('Loading contracts...', 'progress_activity', true);
        try {
            const payload = await window.FAMApi.request(api(`contracts/index.php?${params()}`));
            const data = payload.data || {};
            renderSummary(data.summary || {});
            renderRows(data.items || []);
            renderPagination(data.pagination || {});
            qs('#contract-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        } catch (error) {
            renderError();
        } finally {
            qs('#contract-table')?.closest('.facility-table-card')?.removeAttribute('aria-busy');
            qs('#contract-refresh')?.removeAttribute('aria-busy');
            qs('#contract-refresh')?.removeAttribute('disabled');
            state.loading = false;
            state.hasLoaded = true;
        }
    }

    function tableStateRow(message, icon, spinning = false) {
        return `<tr class="fam-table-state-row"><td colspan="6"><div class="fam-state" role="status"><span class="material-symbols-outlined${spinning ? ' fam-spinner' : ''}" aria-hidden="true">${icon}</span><span>${esc(message)}</span></div></td></tr>`;
    }

    async function loadOptions() {
        const payload = await window.FAMApi.request(api('contracts/options.php'));
        state.options = payload.data || {};
        fillObjects(qs('#contract-type-filter'), state.options.contract_types || [], 'All Types');
        fillSimple(qs('#contract-status-filter'), state.options.statuses || [], 'All Statuses');
    }

    function renderSummary(summary) {
        qs('#contract-total-count').textContent = summary.total ?? 0;
        qs('#contract-active-count').textContent = summary.active ?? 0;
        qs('#contract-expiring-count').textContent = summary.expiringSoon ?? 0;
        qs('#contract-pending-count').textContent = summary.pendingReviewApproval ?? 0;
    }

    function renderRows(items) {
        const body = qs('#contract-table');
        qs('#contract-table-count').textContent = `Showing ${items.length} ${items.length === 1 ? 'contract' : 'contracts'}`;
        if (!items.length) {
            body.innerHTML = tableStateRow(hasFilters() ? 'No contracts match the current search or filters.' : 'No contracts found.', 'contract');
            return;
        }
        body.innerHTML = items.map(row => `<tr>
            <td><button class="document-primary-cell contract-primary-cell" type="button" data-contract-action="view" data-report-action="contract-details" data-contract-id="${esc(row.id)}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">contract</span><span><strong>${esc(row.contractNo)}</strong><small>${esc(contractTypeLabel(row.type?.code || row.type?.name || ''))}</small></span></button></td>
            <td><div class="contract-title-cell"><strong title="${esc(row.title)}">${esc(row.title)}</strong><small>${esc(contractTypeLabel(row.type?.code || row.type?.name || 'Contract'))}</small></div></td>
            <td>${esc(row.counterparty?.name || 'Not set')}</td>
            <td><div class="contract-date-cell"><strong>${esc(registerEndDate(row))}</strong>${expiryIndicator(row) ? `<small>${expiryIndicator(row)}</small>` : ''}</div></td>
            <td>${badge(row.status, 'status')}</td>
            <td>${actionMenu(row)}</td>
        </tr>`).join('');
    }

    function hasFilters() {
        return Boolean(qs('#contract-search')?.value.trim()) || ['#contract-status-filter','#contract-type-filter','#contract-expiry-filter'].some(selector => {
            const field = qs(selector);
            return field && field.value !== 'all';
        });
    }

    function renderError() {
        if (!state.hasLoaded) qs('#contract-table').innerHTML = tableStateRow('Unable to load contracts.', 'error');
        qs('#contract-table-count').textContent = 'Contracts unavailable';
    }

    function actionMenu(row) {
        const labels = { view: 'View Details', edit: 'Edit Contract', submit_review: 'Submit for Review', cancel: 'Cancel Contract', return_draft: 'Return to Draft', submit_approval: 'Submit for Approval', approve: 'Approve', reject: 'Reject', activate: 'Activate', terminate: 'Terminate', archive: 'Archive' };
        const items = [`<button type="button" data-contract-action="view" data-report-action="contract-details" data-contract-id="${esc(row.id)}">View Details</button>`];
        (row.allowedActions || []).filter(action => action !== 'edit').forEach(action => {
            items.push(`<button type="button" data-contract-action="${esc(action)}" data-contract-id="${esc(row.id)}">${esc(labels[action] || title(action))}</button>`);
        });
        if ((row.allowedActions || []).includes('edit')) items.splice(1, 0, `<button type="button" data-contract-action="edit" data-contract-id="${esc(row.id)}">Edit Contract</button>`);
        const menuId = `contract-menu-${row.id}`;
        return `<div class="facility-action-menu"><button class="facility-action-toggle" type="button" aria-label="Open contract actions" aria-haspopup="menu" aria-expanded="false" data-contract-menu-toggle="${menuId}"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button><div id="${menuId}" class="facility-action-dropdown contract-action-dropdown hidden" role="menu">${items.join('')}</div></div>`;
    }

    function renderPagination(pagination) {
        state.totalPages = Number(pagination.total_pages || 1);
        state.page = Number(pagination.page || state.page);
        qs('#contract-page-status').textContent = `Page ${state.page} of ${state.totalPages}`;
        qs('#contract-prev-page').disabled = state.page <= 1;
        qs('#contract-next-page').disabled = state.page >= state.totalPages;
    }

    function fillSimple(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item)}">${esc(title(item))}</option>`).join('');
    }

    function fillObjects(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item.id)}">${esc(item.name || item.code || item.number)}</option>`).join('');
    }

    function optionList(items, selected = '', empty = 'Select') {
        return `<option value="">${esc(empty)}</option>` + (items || []).map(item => `<option value="${esc(item.id)}" ${String(item.id) === String(selected || '') ? 'selected' : ''}>${esc(item.name || item.number || item.code)}${item.department ? ` - ${esc(item.department)}` : ''}</option>`).join('');
    }

    function contractTypeOptions(item = null) {
        const currentCode = item?.type?.code || '';
        const hasCurrent = currentCode && (state.options.contract_types || []).some(type => String(type.id) === String(currentCode));
        const historical = currentCode && !hasCurrent
            ? `<option value="${esc(currentCode)}" selected>${esc(contractTypeLabel(currentCode))} (Historical)</option>`
            : '';
        return `<option value="">Select type</option>${historical}` + (state.options.contract_types || []).map(type => `<option value="${esc(type.id)}" ${String(type.id) === String(currentCode) ? 'selected' : ''}>${esc(contractTypeLabel(type.code || type.name || type.id))}</option>`).join('');
    }

    function openForm(item = null) {
        if (!item && !can('contract.create')) return;
        const modal = moveToTopLayer(qs('#contract-dialog'));
        modal.hidden = false;
        const isEdit = Boolean(item);
        modal.innerHTML = `<form class="facility-dialog-panel contract-form" data-contract-form="${isEdit ? 'edit' : 'create'}" data-contract-id="${esc(item?.id || '')}">
            <div class="facility-details-modal-header">
                <div><p>Contract Management</p><h2>${isEdit ? 'Edit Draft Contract' : 'New Contract'}</h2></div>
                <button class="facility-details-modal-close" type="button" data-contract-dialog-close aria-label="Close dialog">&times;</button>
            </div>
            <div class="facility-dialog-body document-form-body">
                <div class="document-form-error hidden" data-contract-form-error></div>
                <section class="document-form-section"><h3>Contract Information</h3><div class="document-form-grid">
                    ${field('contract_title', 'Contract Title *', item?.title || '', 'text', true)}
                    ${selectField('contract_type_id', 'Contract Type *', contractTypeOptions(item))}
                    ${field('counterparty_name', 'Counterparty', item?.counterparty?.name || '', 'text', false, 'Enter counterparty name')}
                </div>${textarea('contract_description', 'Description', item?.description || '')}</section>
                <section class="document-form-section"><h3>Contract Template</h3><div class="document-form-grid">
                    ${selectField('template_id', `Template${isEdit ? '' : ' *'}`, '<option value="">Select contract type first</option>', `data-contract-template-select${isEdit ? '' : ' required'}`)}
                </div><p class="document-form-note" data-contract-template-note>Choose a contract type to load compatible active templates.</p></section>
            </div>
            <div class="facility-dialog-actions">
                <button class="btn-secondary dashboard-action-button" type="button" data-contract-dialog-close>Cancel</button>
                <button class="btn-primary dashboard-action-button" type="submit">${isEdit ? 'Save Changes' : 'Create Draft'}</button>
            </div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        bindTemplateSelector(modal, item);
        modal.querySelector('[name="contract_title"]')?.focus();
    }

    function field(name, label, value = '', type = 'text', required = false, placeholder = '') {
        const step = type === 'number' ? ' step="0.01" min="0"' : '';
        return `<label class="facility-field"><span>${esc(label)}</span><input name="${esc(name)}" type="${esc(type)}" value="${esc(value)}"${placeholder ? ` placeholder="${esc(placeholder)}"` : ''}${required ? ' required' : ''}${step}></label>`;
    }

    function textarea(name, label, value = '') {
        return `<label class="facility-field document-full-field"><span>${esc(label)}</span><textarea name="${esc(name)}" rows="3">${esc(value)}</textarea></label>`;
    }

    function selectField(name, label, options, attrs = '') {
        return `<label class="facility-field"><span>${esc(label)}</span><select name="${esc(name)}"${attrs ? ` ${attrs}` : ''}>${options}</select></label>`;
    }

    function bindTemplateSelector(modal, item = null) {
        const typeSelect = modal.querySelector('[name="contract_type_id"]');
        const selectedTemplate = item?.template?.id || '';
        typeSelect?.addEventListener('change', () => refreshTemplateOptions(modal, ''));
        refreshTemplateOptions(modal, selectedTemplate);
    }

    async function refreshTemplateOptions(modal, selected = '') {
        const typeId = modal.querySelector('[name="contract_type_id"]')?.value || '';
        const select = modal.querySelector('[data-contract-template-select]');
        const note = modal.querySelector('[data-contract-template-note]');
        if (!select) return;
        if (!typeId) {
            select.disabled = true;
            select.innerHTML = '<option value="">Select contract type first</option>';
            if (note) note.textContent = 'Choose a contract type to load compatible active templates.';
            return;
        }
        select.disabled = true;
        select.innerHTML = '<option value="">Loading templates...</option>';
        if (note) note.textContent = 'Loading compatible active templates.';
        try {
            const payload = await window.FAMApi.request(api(`contracts/eligible-templates.php?contract_type_id=${encodeURIComponent(typeId)}`));
            const templates = payload.data?.items || [];
            select.disabled = false;
            select.innerHTML = '<option value="">No template</option>' + templates.map(template => {
                const label = `${template.templateName} (${template.templateCode} - ${template.currentVersion})`;
                return `<option value="${esc(template.id)}" ${String(template.id) === String(selected || '') ? 'selected' : ''}>${esc(label)}</option>`;
            }).join('');
            if (note) {
                note.textContent = templates.length
                    ? 'The selected active version will be linked to this contract draft.'
                    : 'No compatible active templates are available for this contract type.';
            }
        } catch (error) {
            select.disabled = false;
            select.innerHTML = '<option value="">No template</option>';
            if (note) note.textContent = error.message || 'Unable to load compatible templates.';
        }
    }

    async function submitForm(form) {
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        const error = form.querySelector('[data-contract-form-error]');
        error.classList.add('hidden');
        const body = Object.fromEntries(new FormData(form).entries());
        const isEdit = form.dataset.contractForm === 'edit';
        try {
            const payload = await window.FAMApi.request(api(`contracts/${isEdit ? `update.php?id=${encodeURIComponent(form.dataset.contractId)}` : 'create.php'}`), { method: 'POST', body });
            closeDialog();
            window.FAMModal?.showToast?.(isEdit ? 'Contract updated.' : 'Contract draft created.');
            await load();
            if (payload.data?.item) {
                state.activeItem = payload.data.item;
                await openDetails(payload.data.item.id, payload.data.item);
            }
        } catch (err) {
            const errors = err.errors || err.payload?.data?.errors || {};
            error.textContent = validationMessage(errors, err.message || 'Unable to save contract.');
            error.classList.remove('hidden');
        } finally {
            submit.disabled = false;
        }
    }

    function closeDialog() {
        const modal = qs('#contract-dialog');
        modal.hidden = true;
        modal.innerHTML = '';
        authoringState = null;
        activePlaceholder = null;
        returnToDetailsAfterAuthoring = null;
        const restoreFocus = dateReviewTrigger || requirementUploadTrigger;
        dateReviewTrigger = null;
        requirementUploadTrigger = null;
        if (qs('#contract-details-modal')?.hidden !== false) document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
        restoreFocus?.focus?.();
    }

    function authoringHasUnsavedChanges() {
        if (!authoringState?.initialValues || !authoringState?.values) return false;
        return Object.entries(authoringState.values).some(([key, value]) => String(value || '') !== String(authoringState.initialValues[key] || ''));
    }

    async function closeAuthoringAndReturn(options = {}) {
        if (options.confirmDiscard && authoringHasUnsavedChanges()) {
            const confirmed = await window.FAMModal.confirm('Discard unsaved template changes?', { title: 'Discard Template Changes', confirmLabel: 'Discard' });
            if (!confirmed) return;
        }
        const contractId = returnToDetailsAfterAuthoring;
        closeDialog();
        if (contractId) {
            await openDetails(contractId);
            qsa('[data-contract-template-authoring]').find(button => String(button.dataset.contractTemplateAuthoring) === String(contractId))?.focus?.();
        }
    }

    async function openDetails(id, known = null) {
        const modal = moveToTopLayer(qs('#contract-details-modal'));
        modal.hidden = false;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.innerHTML = detailsShell('Loading...', '<div class="fam-state"><span class="material-symbols-outlined fam-spinner" aria-hidden="true">progress_activity</span><span>Loading contract details...</span></div>');
        try {
            const item = known || (await window.FAMApi.request(api(`contracts/show.php?id=${id}`))).data?.item;
            state.activeItem = item;
            modal.innerHTML = detailsHtml(item);
            modal.querySelector('[data-contract-details-close]')?.focus();
            loadGoogleAuthoringStatus(item.id).catch(error => {
                const panel = googleAuthoringPanel(item.id);
                if (panel) panel.innerHTML = googleAuthoringUnavailableHtml(item.id, error.message || 'Google authoring status is unavailable.');
                if (isLocalDebug()) console.warn('Google authoring status failed.', { status: error.status, message: error.message });
            });
        } catch (error) {
            modal.innerHTML = detailsShell('Unable to load details', `<div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">error</span><span>${esc(error.message || 'Unable to load contract details.')}</span></div>`);
        }
    }

    function detailsShell(titleText, body) {
        return `<div class="facility-details-modal-panel visitor-details-panel contract-details-panel">
            <div class="facility-details-modal-header visitor-details-header"><div><p>Contract</p><h2>${esc(titleText)}</h2></div><button class="facility-details-modal-close" type="button" data-contract-details-close aria-label="Close contract details">&times;</button></div>
            <div class="facility-details-modal-body visitor-details-body">${body}</div>
        </div>`;
    }

    function detailsHtml(item) {
        const d = item.dates || {};
        const status = String(item.status || '').toUpperCase();
        const cancelled = isCancelled(item);
        const showContractDates = status !== 'DRAFT' && !cancelled;
        const expiry = expiryIndicator(item);
        const overviewRows = [
            ['Contract No.', item.contractNo], ['Lifecycle Status', badge(item.status, 'status'), false, true], ['Type', contractTypeLabel(item.type?.code || item.type?.name)], ['Counterparty', item.counterparty?.name || 'Not set'],
        ];
        if (showContractDates) {
            overviewRows.push(
                ['Start Date', overviewDateValue(item, 'start'), false, true, 'contract-date-overview-strong'],
                ['End Date', overviewDateValue(item, 'end'), false, true, 'contract-date-overview-strong'],
            );
        }
        overviewRows.push(['Contract Administrator', item.owner?.name || item.famHandler?.name || 'Unassigned']);
        if (status !== 'DRAFT' && !cancelled && hasMeaningfulDate(d.effectiveDate)) overviewRows.push(['Effective Date', fmtDate(d.effectiveDate)]);
        if (status !== 'DRAFT' && !cancelled && hasMeaningfulDate(d.executedDate)) overviewRows.push(['Executed Date', fmtDate(d.executedDate)]);
        if (status !== 'DRAFT' && !cancelled && item.renewalType && item.renewalType !== 'NONE') overviewRows.push(['Renewal Type', title(item.renewalType)]);
        if (status !== 'DRAFT' && !cancelled && hasMeaningfulDate(item.renewalDecisionDate)) overviewRows.push(['Renewal Decision Date', fmtDate(item.renewalDecisionDate)]);
        if (status !== 'DRAFT' && !cancelled && item.noticePeriodDays !== null && item.noticePeriodDays !== undefined) overviewRows.push(['Notice Period', `${item.noticePeriodDays} days`]);
        if (status !== 'DRAFT' && !cancelled && item.budget?.name) overviewRows.push(['Budget', item.budget.name]);
        if (status !== 'DRAFT' && !cancelled && item.procurementRequest?.number) overviewRows.push(['Procurement Request', item.procurementRequest.number]);
        if (status !== 'DRAFT' && !cancelled && item.purchaseOrder?.number) overviewRows.push(['Purchase Order', item.purchaseOrder.number]);
        if (expiry) overviewRows.push(['Expiry State', expiry, false, true]);
        if (expiry && item.derived?.daysToExpiry !== null && item.derived?.daysToExpiry !== undefined) overviewRows.push(['Days to Expiry', item.derived.daysToExpiry]);
        if (status === 'TERMINATED' || item.terminationReason || d.terminationDate) {
            if (hasMeaningfulDate(d.terminationDate)) overviewRows.push(['Termination Date', fmtDate(d.terminationDate)]);
            if (item.terminationReason) overviewRows.push(['Termination Reason', item.terminationReason, true]);
        }
        if (item.description) overviewRows.push(['Description', item.description, true]);
        const overview = detailGrid(overviewRows);
        const contractDocument = contractDocumentHtml(item);
        const clientRequirements = clientRequirementsHtml(item);
        const approvalOpen = item.approval?.required ? ' open' : '';
        const approvalState = approvalSummary(item.approval, item);
        return `<div class="facility-details-modal-panel visitor-details-panel contract-details-panel">
            <div class="facility-details-modal-header visitor-details-header">
                <div><p>Contract</p><span class="facility-details-modal-request-number">${esc(item.contractNo)}</span><h2>${esc(item.title)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-contract-details-close aria-label="Close contract details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body">
                <div class="contract-workspace">
                    <details class="visitor-detail-disclosure contract-detail-disclosure" open><summary><span>Overview</span></summary>${overview}</details>
                    ${contractDocument}
                    ${clientRequirements}
                    <details class="visitor-detail-disclosure contract-detail-disclosure"${approvalOpen}><summary><span>Approvals</span><small>${esc(approvalState)}</small></summary>${approvalHtml(item.approval, item)}</details>
                    <details class="visitor-detail-disclosure contract-detail-disclosure"><summary><span>History</span></summary>${historyHtml(item.history || [])}</details>
                </div>
            </div>
            <div class="facility-dialog-actions contract-details-footer"><div class="legal-details-workflow-actions contract-details-actions">${detailsActions(item)}</div><button class="btn-secondary dashboard-action-button contract-details-done" type="button" data-contract-details-close>Done</button></div>
        </div>`;
    }

    function detailGrid(rows) {
        return `<div class="visitor-detail-grid contract-detail-grid">${rows.map(([label, value, full, html, valueClass]) => `<div class="visitor-detail-item ${full ? 'detail-item--full' : ''}"><span>${esc(label)}</span><strong${valueClass ? ` class="${esc(valueClass)}"` : ''}>${html ? value : esc(value ?? 'Not applicable')}</strong></div>`).join('')}</div>`;
    }

    function overviewDateValue(item, field) {
        const status = String(item.status || '').toUpperCase();
        const dates = item.contractDates || null;
        const contractDate = field === 'start' ? (item.dates?.startDate || '') : (item.dates?.endDate || '');
        const candidateDate = field === 'start' ? (dates?.suggestedStartDate || '') : (dates?.suggestedEndDate || '');
        const confirmedDate = field === 'start' ? (dates?.startDate || contractDate) : (dates?.endDate || contractDate);
        const showCandidate = dates && !dates.confirmed && candidateDate;
        const displayDate = showCandidate ? candidateDate : (dates?.confirmed ? confirmedDate : contractDate);
        const needsDates = ['FOR_REVIEW','FOR_APPROVAL'].includes(status);
        const label = showCandidate ? 'AI extracted' : (dates?.confirmed ? confirmedDateState(dates, field) : '');
        const value = displayDate ? fmtDate(displayDate) : (needsDates ? 'Not yet set' : 'Not applicable');
        const edit = dates?.canConfirm
            ? `<button class="contract-date-inline-edit" type="button" data-contract-dates-review="${esc(item.id)}" data-contract-date-field="${esc(field)}" aria-label="Edit ${field === 'start' ? 'start' : 'end'} date" title="Edit ${field === 'start' ? 'start' : 'end'} date"><span class="material-symbols-outlined" aria-hidden="true">edit</span></button>`
            : '';
        return `<span class="contract-date-value-row" data-contract-date-review-surface><span>${esc(value)}</span>${edit}</span>${label ? `<small class="contract-date-source-note">${esc(label)}</small>` : ''}`;
    }

    function confirmedDateState(dates, field) {
        const confirmed = field === 'start' ? dates.startDate : dates.endDate;
        const suggested = field === 'start' ? dates.suggestedStartDate : dates.suggestedEndDate;
        if (suggested && confirmed && suggested !== confirmed) return 'Manually adjusted';
        return 'Confirmed';
    }

    function hasMeaningfulDate(value) {
        return Boolean(value && fmtDate(value) !== 'Not set');
    }

    function approvalSummary(approval = {}, item = null) {
        if (isCancelled(item) && !approval.required) return 'Not applicable';
        if (!approval.required) return 'Not submitted';
        if (approval.status) return title(approval.status);
        return `${approval.completedSteps || 0}/${approval.totalSteps || 0} complete`;
    }

    function detailsActions(item) {
        const labels = { edit: 'Edit Contract', submit_review: 'Submit for Review', cancel: 'Cancel Contract', return_draft: 'Return to Draft', submit_approval: 'Submit for Approval', approve: 'Approve', reject: 'Reject', activate: 'Activate', terminate: 'Terminate', archive: 'Archive' };
        const primaryActions = new Set(['submit_review', 'submit_approval', 'activate']);
        const allowed = [...(item.allowedActions || [])].sort((a, b) => Number(primaryActions.has(a)) - Number(primaryActions.has(b)));
        const actions = allowed.map(action => `<button class="${primaryActions.has(action) ? 'btn-primary contract-details-submit' : 'btn-secondary'} dashboard-action-button" type="button" data-contract-action="${esc(action)}" data-contract-id="${esc(item.id)}">${esc(labels[action] || title(action))}</button>`);
        if (item.approval?.canApproveCurrentStep) actions.push(`<button class="btn-primary dashboard-action-button" type="button" data-contract-approval-action="approve" data-contract-id="${esc(item.id)}">Approve</button>`);
        if (item.approval?.canRejectCurrentStep) actions.push(`<button class="btn-secondary dashboard-action-button" type="button" data-contract-approval-action="reject" data-contract-id="${esc(item.id)}">Reject</button>`);
        if (item.approval?.canReturnCurrentStep) actions.push(`<button class="btn-secondary dashboard-action-button" type="button" data-contract-approval-action="return" data-contract-id="${esc(item.id)}">Return for Changes</button>`);
        return actions.join('');
    }

    function approvalHtml(approval = {}, item = null) {
        if (isCancelled(item) && !approval.required) return '<p class="legal-empty-note">This contract was cancelled before it was submitted for approval.</p>';
        if (!approval.required) return '<p class="legal-empty-note">Not submitted for approval.</p>';
        const rows = [
            ['Status', title(approval.status)],
            ['Progress', `${approval.completedSteps || 0} of ${approval.totalSteps || 0} completed`],
            ['Submitted', fmt(approval.submittedAt)],
            ['Completed', approval.completedAt ? fmt(approval.completedAt) : 'Not completed'],
        ];
        const currentStep = approval.currentStep || null;
        if (String(approval.status || '').toUpperCase() === 'PENDING' && currentStep?.name) {
            rows.push(['Current Step', currentStep.name]);
            rows.push(['Current Approver', currentStep.approverDisplay || 'Not assigned']);
        }
        return detailGrid(rows);
    }

    function contractDocumentHtml(item) {
        const template = item.template;
        const open = String(item.status || '').toUpperCase() === 'DRAFT' ? ' open' : '';
        return `<details class="visitor-detail-disclosure contract-detail-disclosure contract-document-workspace"${open}><summary><span>Contract Document</span></summary>
            ${template ? `<div class="contract-google-authoring" data-contract-google-authoring-panel="${esc(item.id)}" data-contract-status="${esc(item.status || '')}">${googleAuthoringLoadingHtml()}</div>` : ''}
            ${!template ? contractDocumentMetadataGrid(item, {}, '') : ''}
        </details>`;
    }

    function systemDocumentTitle(item) {
        const docs = item.contractArtifacts || item.documents || [];
        const doc = docs.find(row => row.isPrimary) || docs[0] || null;
        return doc ? (item.contractNo || doc.title || '') : '';
    }

    function contractDocumentMetadataGrid(item, status = {}, message = '', mode = 'authoring') {
        const template = item.template || {};
        const doc = status.document || null;
        const lifecycle = String(item.status || '').toUpperCase();
        const templateValue = template.templateName
            ? `${template.templateName}${template.currentVersion ? ` - ${template.currentVersion}` : ''}`
            : 'No contract template associated.';
        const googleValue = status.connected ? `Connected as ${status.googleEmail || 'Google account'}` : (message || 'Not connected');
        const syncValue = doc ? (doc.lastSyncedAt ? fmt(doc.lastSyncedAt) : 'Not yet synchronized') : 'No synchronized copy stored in FAM yet.';
        const systemValue = doc && doc.documentNo
            ? doc.documentNo
            : (systemDocumentTitle(item) || 'No synchronized copy stored in FAM yet.');
        if (mode === 'artifact') {
            const stateText = lifecycle === 'FOR_REVIEW'
                ? 'Finalized for review'
                : (lifecycle === 'FOR_APPROVAL' ? 'Finalized for approval' : 'Authoritative contract record');
            return `<div class="contract-document-grid">
                <div class="visitor-detail-item"><span>TEMPLATE</span><strong>${esc(templateValue)}</strong></div>
                <div class="visitor-detail-item"><span>SYSTEM DOCUMENT</span><strong>${esc(systemValue)}</strong></div>
                <div class="visitor-detail-item"><span>DOCUMENT STATE</span><strong>${esc(doc ? stateText : 'No synchronized copy stored in FAM yet.')}</strong></div>
                <div class="visitor-detail-item"><span>LAST SYNCHRONIZED</span><strong>${esc(syncValue)}</strong></div>
            </div>`;
        }
        return `<div class="contract-document-grid">
            <div class="visitor-detail-item"><span>TEMPLATE</span><strong>${esc(templateValue)}</strong></div>
            <div class="visitor-detail-item"><span>GOOGLE DOCS</span><strong>${esc(googleValue)}</strong></div>
            <div class="visitor-detail-item"><span>LAST SYNCHRONIZED</span><strong>${esc(syncValue)}</strong></div>
            <div class="visitor-detail-item"><span>SYSTEM DOCUMENT</span><strong>${esc(systemValue)}</strong></div>
        </div>`;
    }

    function finalizedContractDocumentUrl(doc) {
        const documentId = Number(doc?.syncedDocumentId || doc?.documentId || 0);
        if (documentId < 1) return '';
        const versionId = Number(doc?.syncedDocumentVersionId || doc?.documentVersionId || 0);
        const suffix = versionId > 0 ? `&version_id=${encodeURIComponent(versionId)}` : '';
        return api(`documents/view.php?id=${encodeURIComponent(documentId)}${suffix}`);
    }

    function finalizedContractDocumentAction(doc) {
        const url = finalizedContractDocumentUrl(doc);
        return url
            ? `<a class="btn-secondary dashboard-action-button" href="${esc(url)}" target="_blank" rel="noopener"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>View Contract Document</a>`
            : '';
    }

    function signedContractDocumentUrl(signed = {}) {
        const documentId = Number(signed.documentId || 0);
        const versionId = Number(signed.approvalDocumentVersionId || signed.documentVersionId || 0);
        if (documentId < 1 || versionId < 1) return '';
        return api(`documents/view.php?id=${encodeURIComponent(documentId)}&version_id=${encodeURIComponent(versionId)}`);
    }

    function signedContractWorkflowHtml(item = {}) {
        const signed = item.signedContract || {};
        const status = String(item.status || '').toUpperCase();
        const url = signedContractDocumentUrl(signed);
        const view = url ? `<a class="btn-secondary dashboard-action-button" href="${esc(url)}" target="_blank" rel="noopener" data-document-file-action="view" data-document-id="${esc(signed.documentId)}" data-document-version-id="${esc(signed.approvalDocumentVersionId || signed.documentVersionId || '')}"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>View ${status === 'FOR_REVIEW' ? 'Signed Contract' : 'Contract'}</a>` : '';
        const uploadLabel = signed.uploaded ? 'Replace' : 'Upload Signed Contract';
        const upload = signed.canUpload || signed.canReplace ? `<button class="btn-secondary dashboard-action-button" type="button" data-signed-contract-upload="${esc(item.id)}"><span class="material-symbols-outlined" aria-hidden="true">upload_file</span>${esc(uploadLabel)}</button>` : '';
        const stateText = signed.uploaded ? 'Uploaded' : 'Waiting for Signed Contract';
        const version = signed.version || (signed.documentVersionId ? `v${signed.documentVersionId}` : 'Not available');
        const documentNo = signed.documentNo || 'No signed copy uploaded yet.';
        const rows = status === 'FOR_REVIEW'
            ? `<div class="visitor-detail-item"><span>SIGNED CONTRACT</span><strong>${esc(documentNo)}</strong></div>
               <div class="visitor-detail-item"><span>SIGNED COPY</span><strong>${esc(stateText)}</strong></div>
               <div class="visitor-detail-item"><span>SIGNED VERSION</span><strong>${esc(signed.uploaded ? version : 'Not available')}</strong></div>
               <div class="visitor-detail-item"><span>UPLOADED</span><strong>${esc(signed.uploadedAt ? fmt(signed.uploadedAt) : 'Not uploaded')}</strong></div>`
            : `<div class="visitor-detail-item"><span>SIGNED VERSION</span><strong>${esc(signed.uploaded ? version : 'Not available')}</strong></div>
               <div class="visitor-detail-item"><span>SIGNED COPY</span><strong>${esc(stateText)}</strong></div>`;
        return `<div class="contract-document-grid">${rows}</div>
            <div class="contract-google-bottom">
                <p class="contract-google-message">${esc(status === 'FOR_REVIEW' && !signed.verified ? stateText : 'Authoritative signed FAM contract record.')}</p>
                <div class="contract-google-actions">${view}${upload}</div>
            </div>`;
    }

    function clientRequirementsHtml(item) {
        const data = item.clientRequirements || { installed: false, items: [], complete: 0, total: 0, requiredMissing: 0 };
        const titleText = 'Client Requirements';
        const cancelled = isCancelled(item);
        const summary = data.total
            ? (cancelled ? `${data.complete} of ${data.total} completed before cancellation` : `${data.complete}/${data.total} complete`)
            : '0 requirements';
        if (!data.installed) {
            if (isLocalDebug()) console.warn('Client requirement tracking table is unavailable.');
        return `<details class="visitor-detail-disclosure contract-detail-disclosure" data-client-requirements-section>
            <summary><span>${esc(titleText)}</span><small>${esc(summary)}</small></summary>
            <p class="document-form-note">${cancelled ? 'Requirements are retained for reference. No further action is required because this contract was cancelled.' : 'Documents required from the client or counterparty before contract approval.'}</p>
            <p class="legal-empty-note">No client requirements added yet.</p>
        </details>`;
        }
        const items = data.items || [];
        const list = items.length ? `<div class="contract-requirement-list">${items.map(requirement => clientRequirementRow(requirement, item.id)).join('')}</div>` : '<p class="legal-empty-note">No client requirements added yet.</p>';
        const readiness = data.readiness || {};
        const blocking = [
            ...(readiness.requiredIncomplete || []),
            ...(readiness.conditionalPending || []),
            ...(readiness.conditionalIncomplete || []),
        ];
        return `<details class="visitor-detail-disclosure contract-detail-disclosure" data-client-requirements-section>
            <summary><span>${esc(titleText)}</span><small>${esc(summary)}</small></summary>
            <p class="document-form-note">${cancelled ? 'Requirements are retained for reference. No further action is required because this contract was cancelled.' : 'Documents required from the client or counterparty before contract approval.'}</p>
            ${!cancelled && blocking.length ? `<p class="legal-empty-note">${esc(blocking.length)} client requirement${blocking.length === 1 ? '' : 's'} blocking approval.</p>` : ''}
            ${list}
        </details>`;
    }

    function legacyClientRequirementRow(requirement) {
        const complete = requirement.document || String(requirement.status || '').toUpperCase() === 'VERIFIED';
        const icon = complete ? 'check_box' : 'check_box_outline_blank';
        const state = requirement.verificationStatus === 'VERIFIED' ? 'Verified' : (requirement.document ? title(requirement.status || 'UPLOADED') : 'Missing');
        const doc = requirement.document;
        const actions = doc ? `<div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${doc.id}`))}" target="_blank" rel="noopener">View</a></div>` : '';
        return `<article class="document-file-row">
            <div><span class="material-symbols-outlined document-file-icon" aria-hidden="true">${icon}</span><div><strong>${esc(requirement.name)}</strong><small>${requirement.required ? 'Required' : 'Optional'} · ${esc(state)}</small>${doc ? `<small>${esc(doc.fileName || doc.documentNo)} · ${esc(doc.version)}</small>` : ''}${requirement.description ? `<small>${esc(requirement.description)}</small>` : ''}</div></div>
            ${actions}
        </article>`;
    }

    function clientRequirementRow(requirement, contractId = '') {
        const contractStatus = String(state.activeItem?.status || '').toUpperCase();
        const canPrepareRequirement = can('records.create') && (can('contract.edit') || can('contract.manage')) && contractStatus === 'DRAFT';
        const canReviewRequirement = (can('contract.review') || can('contract.manage')) && contractStatus === 'FOR_REVIEW';
        const status = String(requirement.status || 'MISSING').toUpperCase();
        const verification = String(requirement.verificationStatus || 'PENDING').toUpperCase();
        const applicability = String(requirement.applicabilityStatus || 'APPLICABLE').toUpperCase();
        const classificationRaw = String(requirement.classification || (requirement.required ? 'REQUIRED' : 'OPTIONAL')).toUpperCase();
        const complete = (classificationRaw === 'REQUIRED' && status === 'VERIFIED' && verification === 'VERIFIED')
            || (classificationRaw === 'CONDITIONAL' && (applicability === 'NOT_APPLICABLE' || (applicability === 'APPLICABLE' && status === 'VERIFIED' && verification === 'VERIFIED')));
        const icon = complete ? 'check_box' : 'check_box_outline_blank';
        const stateText = classificationRaw === 'CONDITIONAL' && applicability === 'PENDING'
            ? 'Applicability pending'
            : (applicability === 'NOT_APPLICABLE' ? 'Not applicable' : title(status || 'MISSING'));
        const classification = title(classificationRaw);
        const doc = requirement.document;
        const busy = requirementUploadsInProgress.has(Number(requirement.id));
        const canAttachOrReplace = canPrepareRequirement && applicability !== 'NOT_APPLICABLE' && status !== 'VERIFIED';
        const attach = canAttachOrReplace ? `<button class="btn-secondary dashboard-action-button" type="button" data-client-requirement-upload="${esc(contractId)}" data-requirement-id="${esc(requirement.id)}" ${busy ? 'disabled aria-busy="true"' : ''}><span class="material-symbols-outlined" aria-hidden="true">${busy ? 'progress_activity' : 'attach_file'}</span>${busy ? 'Uploading' : (doc ? 'Replace' : 'Attach')}</button>` : '';
        const verify = doc && canReviewRequirement && status === 'SUBMITTED' ? `<button class="btn-secondary dashboard-action-button" type="button" data-client-requirement-action="verify" data-contract-id="${esc(contractId)}" data-requirement-id="${esc(requirement.id)}">Verify</button>` : '';
        const reject = doc && canReviewRequirement && status === 'SUBMITTED' ? `<button class="btn-secondary dashboard-action-button" type="button" data-client-requirement-action="reject" data-contract-id="${esc(contractId)}" data-requirement-id="${esc(requirement.id)}">Reject</button>` : '';
        const notApplicable = classificationRaw === 'CONDITIONAL' && applicability === 'PENDING' && canPrepareRequirement ? `<button class="btn-secondary dashboard-action-button" type="button" data-client-requirement-action="mark_not_applicable" data-contract-id="${esc(contractId)}" data-requirement-id="${esc(requirement.id)}">Not Applicable</button>` : '';
        const workflowReviewEvidence = doc
            && applicability !== 'NOT_APPLICABLE'
            && (
                (contractStatus === 'DRAFT' && ['SUBMITTED','VERIFIED','REJECTED'].includes(status))
                || (contractStatus === 'FOR_REVIEW' && ['SUBMITTED','VERIFIED'].includes(status) && ['PENDING','VERIFIED'].includes(verification))
                || (contractStatus === 'FOR_APPROVAL' && status === 'VERIFIED' && verification === 'VERIFIED')
            );
        const guardedViewAttrs = doc && !workflowReviewEvidence ? ` data-document-file-action="view" data-document-id="${esc(doc.id)}"` : '';
        const actions = doc || attach || verify || reject || notApplicable ? `<div class="document-file-actions">${doc ? `<a class="btn-secondary dashboard-action-button" href="${esc(api(`documents/view.php?id=${doc.id}`))}" target="_blank" rel="noopener"${guardedViewAttrs}>View</a>` : ''}${attach}${verify}${reject}${notApplicable}</div>` : '';
        return `<article class="contract-requirement-row">
            <div><span class="material-symbols-outlined contract-requirement-icon" aria-hidden="true">${icon}</span><div class="contract-requirement-copy"><div class="contract-requirement-line"><strong>${esc(requirement.name)}</strong><small>${esc(classification)} - ${esc(stateText)}</small></div>${requirement.description ? `<p>${esc(requirement.description)}</p>` : ''}${busy ? '<small>Uploading evidence...</small>' : ''}</div></div>
            ${actions}
        </article>`;
    }

    async function guardedDocumentFileAction(link) {
        try {
            await window.FAMApi.openDocumentWithStepUp({
                documentId: Number(link.dataset.documentId),
                versionId: link.dataset.documentVersionId || '',
                action: link.dataset.documentFileAction,
                url: link.href,
                target: link.target || '_self',
            });
        } catch (error) {
            window.FAMModal?.showToast?.(error.message || 'Document access was denied.', { type: 'error' });
        }
    }

    function validationMessage(errors = {}, fallback = 'Unable to complete the request.') {
        const entries = Object.entries(errors);
        if (!entries.length) return fallback;
        return entries
            .map(([field, message]) => `${title(field)}: ${Array.isArray(message) ? message.join(', ') : message}`)
            .join(' ');
    }

    async function loadGoogleAuthoringStatus(contractId) {
        const panel = googleAuthoringPanel(contractId);
        if (!panel) return;
        try {
            const payload = await window.FAMApi.request(api(`contracts/google-document/status.php?id=${encodeURIComponent(contractId)}`));
            panel.innerHTML = googleAuthoringHtml(contractId, payload.data?.item || {});
        } catch (error) {
            panel.innerHTML = googleAuthoringUnavailableHtml(contractId, error.message || 'Google authoring status is unavailable.');
            throw error;
        }
    }

    function googleAuthoringPanel(contractId) {
        return qsa('[data-contract-google-authoring-panel]').find(panel => panel.dataset.contractGoogleAuthoringPanel === String(contractId)) || null;
    }

    function googleAuthoringLoadingHtml() {
        return `<section class="contract-google-authoring-panel">
            <p class="legal-empty-note">Loading Google authoring status...</p>
        </section>`;
    }

    function googleAuthoringUnavailableHtml(contractId, message) {
        const status = String(googleAuthoringPanel(contractId)?.dataset.contractStatus || state.activeItem?.status || '').toUpperCase();
        const copy = status === 'CANCELLED' ? 'This contract was cancelled before Google authoring was completed.' : message;
        return `<section class="contract-google-authoring-panel">
            <p class="legal-empty-note">${esc(copy)}</p>
            <div class="contract-google-actions">${fallbackAuthoringButton(contractId)}</div>
        </section>`;
    }

    function googleAuthoringHtml(contractId, status = {}) {
        const doc = status.document;
        const item = state.activeItem || {};
        const contractStatus = String(googleAuthoringPanel(contractId)?.dataset.contractStatus || state.activeItem?.status || '').toUpperCase();
        const cancelled = contractStatus === 'CANCELLED';
        const reasonText = status.reason || status.eligibilityReason || '';
        const fallback = cancelled || !status.enabled || !status.configured || !status.available || (!status.eligible && !doc) ? fallbackAuthoringButton(contractId) : '';
        let actions = '';
        let message = reasonText && !cancelled ? reasonText : '';
        if (status.available && !status.connected) {
            message = 'Connect your Google account to edit this contract in Google Docs.';
            actions = `<button class="btn-primary dashboard-action-button" type="button" data-google-connect><span class="material-symbols-outlined" aria-hidden="true">account_circle</span>Connect Google Account</button>`;
        } else if (status.available && !doc && status.canCreate) {
            actions = `<button class="btn-primary dashboard-action-button" type="button" data-google-create="${esc(contractId)}"><span class="material-symbols-outlined" aria-hidden="true">edit_document</span>Edit in Google Docs</button>`;
        } else if (doc && !cancelled) {
            const open = doc.webViewUrl ? `<a class="btn-primary dashboard-action-button" href="${esc(doc.webViewUrl)}" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>Open in Google Docs</a>` : '';
            const canSyncDraftCopy = contractStatus === 'DRAFT' && status.canSync && ['WORKING','FINALIZED'].includes(String(doc.status || '').toUpperCase());
            const sync = canSyncDraftCopy ? `<button class="btn-secondary dashboard-action-button" type="button" data-google-sync="${esc(contractId)}"><span class="material-symbols-outlined" aria-hidden="true">sync</span>Sync to FAM</button>` : '';
            actions = contractStatus === 'DRAFT' ? `${open}${sync}` : '';
            if (contractStatus === 'FOR_REVIEW') {
                message = 'Finalized contract ready for review.';
            } else if (contractStatus === 'FOR_APPROVAL') {
                message = 'Finalized contract submitted for approval.';
            } else if (!['DRAFT','CANCELLED'].includes(contractStatus)) {
                message = 'Authoritative FAM contract record.';
            }
        }
        if (!doc && !message) {
            message = cancelled ? 'This contract was cancelled before Google authoring was completed.' : 'No Google working document has been created yet.';
        }
        const metadataMode = contractStatus === 'DRAFT' || cancelled ? 'authoring' : 'artifact';
        const signedWorkflow = ['FOR_REVIEW','FOR_APPROVAL','APPROVED','ACTIVE','EXPIRED','TERMINATED','ARCHIVED','REJECTED'].includes(contractStatus)
            ? signedContractWorkflowHtml(item)
            : '';
        return `<section class="contract-google-authoring-panel">
            ${contractDocumentMetadataGrid(item, status, message, metadataMode)}
            ${signedWorkflow}
            ${contractStatus === 'DRAFT' || cancelled ? `<div class="contract-google-bottom">
                ${message ? `<p class="contract-google-message">${esc(message)}</p>` : '<span></span>'}
                <div class="contract-google-actions">${actions}${fallback}</div>
            </div>` : ''}
        </section>`;
    }

    function fallbackAuthoringButton(contractId) {
        return `<button class="btn-secondary dashboard-action-button" type="button" data-contract-template-authoring="${esc(contractId)}"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>Preview Stored Template</button>`;
    }

    async function connectGoogle() {
        const payload = await window.FAMApi.request(api('integrations/google/connect.php'), { method: 'POST', body: {} });
        const url = payload.data?.authorization_url;
        if (!url) throw new Error('Google authorization URL is unavailable.');
        window.location.assign(url);
    }

    async function createGoogleDocument(contractId) {
        const panel = googleAuthoringPanel(contractId);
        if (panel) panel.innerHTML = '<p class="legal-empty-note">Creating Google working document...</p>';
        const payload = await window.FAMApi.request(api(`contracts/google-document/create.php?id=${encodeURIComponent(contractId)}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('Google working document created.');
        const url = payload.data?.item?.webViewUrl;
        if (url) window.open(url, '_blank', 'noopener,noreferrer');
        await loadGoogleAuthoringStatus(contractId);
    }

    async function syncGoogleDocument(contractId) {
        const panel = googleAuthoringPanel(contractId);
        if (panel) panel.innerHTML = '<p class="legal-empty-note">Synchronizing latest Google copy...</p>';
        await window.FAMApi.request(api(`contracts/google-document/sync.php?id=${encodeURIComponent(contractId)}`), { method: 'POST', body: { format: 'docx' } });
        window.FAMModal?.showToast?.('Google working document synchronized.');
        await openDetails(contractId);
        await load();
    }

    function isLocalDebug() {
        return location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    }

    async function openTemplateAuthoring(contractId, options = {}) {
        if (options.returnToDetails) {
            returnToDetailsAfterAuthoring = contractId;
            closeDetails({ preserveModalLock: true });
        } else {
            returnToDetailsAfterAuthoring = null;
        }
        const modal = moveToTopLayer(qs('#contract-dialog'));
        modal.hidden = false;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.innerHTML = `<div class="facility-dialog-panel document-form contract-template-authoring"><div class="facility-details-modal-header"><div><p>Contract Document</p><h2>Loading template...</h2></div><button class="facility-details-modal-close" type="button" data-contract-dialog-close aria-label="Close dialog">&times;</button></div><div class="facility-dialog-body document-form-body"><div class="fam-state"><span class="material-symbols-outlined fam-spinner" aria-hidden="true">progress_activity</span><span>Loading contract template authoring workspace...</span></div></div></div>`;
        try {
            const payload = await window.FAMApi.request(api(`contracts/template-authoring.php?id=${encodeURIComponent(contractId)}`));
            renderTemplateAuthoring(modal, payload.data?.item);
        } catch (error) {
            modal.innerHTML = `<div class="facility-dialog-panel document-form"><div class="facility-details-modal-header"><div><p>Contract Document</p><h2>Preview unavailable</h2></div><button class="facility-details-modal-close" type="button" data-contract-dialog-close aria-label="Close dialog">&times;</button></div><div class="facility-dialog-body document-form-body"><p class="legal-empty-note">${esc(error.message || 'Unable to load the template workspace.')}</p></div></div>`;
        }
    }

    function renderTemplateAuthoring(modal, item = {}) {
        authoringState = {
            item,
            values: Object.fromEntries((item.fields || []).map(field => [field.fieldCode, field.value || ''])),
        };
        authoringState.initialValues = { ...authoringState.values };
        const fields = item.fields || [];
        const manual = fields.filter(field => field.source === 'MANUAL' && !field.unsupported);
        const completed = fields.filter(field => field.source === 'SYSTEM' ? field.completed : String(authoringState.values[field.fieldCode] || '').trim() !== '').length;
        const unsupported = item.unsupportedPlaceholders || [];
        const preview = item.preview || {};
        const previewBody = preview.mode === 'DOCX_PLACEHOLDER'
            ? renderTemplatePreview(preview.text || '', fields, unsupported)
            : `<p class="legal-empty-note">${esc(preview.message || 'Preview unavailable.')}</p>`;
        const remaining = Math.max(fields.length - completed, 0);
        const unsupportedHtml = unsupported.length ? `<span class="contract-template-warning">${esc(unsupported.length)} placeholder${unsupported.length === 1 ? '' : 's'} need configuration</span>` : '';
        modal.innerHTML = `<form class="facility-dialog-panel document-form contract-template-authoring" data-contract-template-values="${esc(item.contract?.id || '')}">
            <div class="facility-details-modal-header"><div><p>Contract Document</p><h2>${esc(item.template?.templateName || 'Contract Template')}</h2><span class="facility-details-modal-request-number">${esc(item.template?.templateCode || '')} ${item.template?.version ? `- ${esc(item.template.version)}` : ''}</span></div><button class="facility-details-modal-close" type="button" data-contract-dialog-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body document-form-body">
                <div class="document-form-error hidden" data-contract-form-error></div>
                <section class="document-form-section contract-preview-panel">
                    <div class="contract-authoring-summary"><p class="document-form-note">Template fields: ${esc(completed)} of ${esc(fields.length)} completed${fields.length ? ` - ${esc(remaining)} remaining` : ''}</p>${unsupportedHtml}</div>
                    <p class="document-form-note">${esc(preview.message || '')}</p>
                    <div class="contract-template-preview">${previewBody}</div>
                </section>
            </div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-contract-dialog-close>Cancel</button>${item.canEdit && manual.length ? '<button class="btn-primary dashboard-action-button" type="submit">Save Changes</button>' : ''}</div>
            <div class="contract-placeholder-popover hidden" role="dialog" aria-modal="false" aria-labelledby="contract-placeholder-editor-title" data-placeholder-editor></div>
        </form>`;
    }

    function renderTemplatePreview(text, fields = [], unsupported = []) {
        const normalize = value => String(value || '').trim().replace(/\s+/g, ' ').toUpperCase();
        const known = new Map(fields.map(field => [normalize(field.placeholder), field]));
        const unsupportedSet = new Set(unsupported.map(normalize));
        return `<pre>${esc(text).replace(/\[\s*([A-Z0-9][A-Z0-9\s\/&.,_-]{1,120})\s*\]/g, (match, name) => {
            const key = normalize(name);
            const field = known.get(key);
            const unsupportedMatch = unsupportedSet.has(key);
            if (field) {
                const value = authoringState?.values?.[field.fieldCode] || '';
                const display = field.source === 'SYSTEM' || String(value).trim() !== '' ? (value || field.value || match) : match;
                const stateClass = field.source === 'SYSTEM' ? 'auto' : (String(value).trim() !== '' ? 'filled' : 'needs-input');
                return `<button class="contract-placeholder contract-placeholder-${stateClass}" type="button" data-placeholder-code="${esc(field.fieldCode)}" data-placeholder-source="${esc(field.source)}">${esc(display)}</button>`;
            }
            if (unsupportedMatch) {
                return `<button class="contract-placeholder contract-placeholder-unsupported" type="button" data-placeholder-unsupported="${esc(key)}">${esc(match)}</button>`;
            }
            return esc(match);
        })}</pre>`;
    }

    function rerenderAuthoringPreview() {
        const preview = qs('.contract-template-preview');
        if (!preview || !authoringState?.item) return;
        const item = authoringState.item;
        preview.innerHTML = renderTemplatePreview(item.preview?.text || '', item.fields || [], item.unsupportedPlaceholders || []);
        const fields = item.fields || [];
        const completed = fields.filter(field => field.source === 'SYSTEM' ? field.completed : String(authoringState.values[field.fieldCode] || '').trim() !== '').length;
        const summary = qs('.contract-authoring-summary .document-form-note');
        if (summary) summary.textContent = `Template fields: ${completed} of ${fields.length} completed${fields.length ? ` - ${Math.max(fields.length - completed, 0)} remaining` : ''}`;
    }

    function openPlaceholderEditor(button) {
        const code = button.dataset.placeholderCode;
        const field = (authoringState?.item?.fields || []).find(item => item.fieldCode === code);
        if (!field) return;
        if (field.source === 'SYSTEM') {
            window.FAMModal?.showToast?.('This value is auto-filled from authoritative contract data.');
            return;
        }
        activePlaceholder = button;
        const editor = qs('[data-placeholder-editor]');
        if (!editor) return;
        const rect = button.getBoundingClientRect();
        const type = field.dataType === 'DATE' ? 'date' : 'text';
        editor.innerHTML = `<h3 id="contract-placeholder-editor-title">${esc(field.label)}</h3><label class="facility-field"><span>Value</span><input data-placeholder-editor-input type="${type}" value="${esc(authoringState.values[code] || '')}"></label><div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-placeholder-cancel>Cancel</button><button class="btn-primary dashboard-action-button" type="button" data-placeholder-apply="${esc(code)}">Apply</button></div>`;
        editor.classList.remove('hidden');
        editor.style.left = `${Math.min(rect.left, window.innerWidth - 340)}px`;
        editor.style.top = `${Math.min(rect.bottom + 8, window.innerHeight - 220)}px`;
        editor.querySelector('[data-placeholder-editor-input]')?.focus();
    }

    function closePlaceholderEditor() {
        const editor = qs('[data-placeholder-editor]');
        if (editor) {
            editor.classList.add('hidden');
            editor.innerHTML = '';
        }
        activePlaceholder?.focus?.();
        activePlaceholder = null;
    }

    async function submitTemplateValues(form) {
        const error = form.querySelector('[data-contract-form-error]');
        error.classList.add('hidden');
        const values = {};
        (authoringState?.item?.fields || []).forEach(field => {
            if (field.source === 'MANUAL' && !field.unsupported) values[field.fieldCode] = authoringState.values[field.fieldCode] || '';
        });
        try {
            const payload = await window.FAMApi.request(api(`contracts/save-template-values.php?id=${encodeURIComponent(form.dataset.contractTemplateValues)}`), { method: 'POST', body: { values } });
            window.FAMModal?.showToast?.('Template values saved.');
            await load();
            if (returnToDetailsAfterAuthoring) {
                await closeAuthoringAndReturn();
            } else {
                renderTemplateAuthoring(moveToTopLayer(qs('#contract-dialog')), payload.data?.item);
            }
        } catch (err) {
            const errors = err.errors || err.payload?.data?.errors || {};
            error.textContent = validationMessage(errors, err.message || 'Unable to save template values.');
            error.classList.remove('hidden');
        }
    }

    function historyHtml(rows) {
        if (!rows.length) return '<p class="legal-empty-note">No contract history yet.</p>';
        return `<ol class="contract-history-list">${rows.map(row => {
            const isCancellation = String(row.type || '').toUpperCase() === 'CANCELLED' || String(row.toStatus || '').toUpperCase() === 'CANCELLED';
            const reason = isCancellation && row.reason ? `<span>Reason: ${esc(row.reason)}</span>` : '';
            const doc = row.document;
            const documentLine = doc ? `<span>Evidence: ${esc(doc.documentNo || `Document #${doc.id}`)}${doc.fileName ? ` &middot; ${esc(doc.fileName)}` : ''}</span>` : '';
            const contractDates = row.contractDates;
            const dateLine = contractDates ? `<span>Confirmed dates: ${esc(fmtDate(contractDates.confirmedStartDate))} to ${esc(fmtDate(contractDates.confirmedEndDate))}${contractDates.sourceDocumentVersionId ? ` from version #${esc(contractDates.sourceDocumentVersionId)}` : ''}</span>` : '';
            const proposedLine = contractDates && (contractDates.proposedStartDate || contractDates.proposedEndDate) ? `<span>AI suggestion: ${esc(contractDates.proposedStartDate ? fmtDate(contractDates.proposedStartDate) : 'No start date')} to ${esc(contractDates.proposedEndDate ? fmtDate(contractDates.proposedEndDate) : 'No end date')}</span>` : '';
            return `<li class="contract-history-event"><strong>${esc(title(row.type))}</strong><span>${esc(row.description)}</span>${documentLine}${dateLine}${proposedLine}${reason}<small>${esc(row.actor || 'System')} &middot; ${esc(fmt(row.eventAt))}</small></li>`;
        }).join('')}</ol>`;
    }

    function closeDetails(options = {}) {
        const modal = qs('#contract-details-modal');
        modal.hidden = true;
        modal.innerHTML = '';
        if (!options.preserveModalLock && qs('#contract-dialog')?.hidden !== false) {
            document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
        }
    }

    async function handleTransition(id, action) {
        const config = {
            submit_review: { target_status: 'FOR_REVIEW', title: 'Submit for Review', label: 'Submit' },
            submit_approval: { target_status: 'FOR_APPROVAL', title: 'Submit for Approval', label: 'Submit' },
            approve: { target_status: 'APPROVED', title: 'Approve Contract', label: 'Approve' },
            archive: { target_status: 'ARCHIVED', title: 'Archive Contract', label: 'Archive' },
            return_draft: { target_status: 'DRAFT', title: 'Return to Draft', label: 'Return', reason: true },
            reject: { target_status: 'REJECTED', title: 'Reject Contract', label: 'Reject', reason: true },
            cancel: { target_status: 'CANCELLED', title: 'Cancel Contract', label: 'Cancel Contract', reason: true, cancel: true },
            activate: { target_status: 'ACTIVE', title: 'Activate Contract', label: 'Activate', activate: true },
            terminate: { target_status: 'TERMINATED', title: 'Terminate Contract', label: 'Terminate', terminate: true },
        }[action];
        if (!config) return;
        const body = { target_status: config.target_status };
        if (config.reason) {
            const message = config.cancel
                ? 'Cancel this draft contract? The contract and its existing documents will be preserved, but it can no longer be edited or submitted for review.'
                : `Enter the reason for ${config.title.toLowerCase()}.`;
            const inputLabel = config.cancel ? 'Cancellation reason' : 'Reason';
            const reason = await window.FAMModal.prompt(message, '', { title: config.title, inputLabel, confirmLabel: config.label });
            if (reason === null) return;
            const trimmedReason = String(reason || '').trim();
            if (!trimmedReason) {
                window.FAMModal?.showToast?.(config.cancel ? 'Cancellation reason is required.' : 'Reason is required.', { type: 'error' });
                return;
            }
            body.reason = trimmedReason;
        } else if (config.activate) {
            const executed = await window.FAMModal.prompt('Enter the executed date in YYYY-MM-DD.', new Date().toISOString().slice(0, 10), { title: config.title, inputLabel: 'Executed Date', confirmLabel: 'Continue' });
            if (!executed) return;
            const effective = await window.FAMModal.prompt('Enter the effective date in YYYY-MM-DD.', executed, { title: config.title, inputLabel: 'Effective Date', confirmLabel: config.label });
            if (!effective) return;
            body.executed_date = executed;
            body.effective_date = effective;
        } else if (config.terminate) {
            const date = await window.FAMModal.prompt('Enter the termination date in YYYY-MM-DD.', new Date().toISOString().slice(0, 10), { title: config.title, inputLabel: 'Termination Date', confirmLabel: 'Continue' });
            if (!date) return;
            const reason = await window.FAMModal.prompt('Enter the termination reason.', '', { title: config.title, inputLabel: 'Termination Reason', confirmLabel: config.label });
            if (!reason) return;
            body.termination_date = date;
            body.termination_reason = reason;
        } else if (!await window.FAMModal.confirm(`Continue with ${config.title.toLowerCase()}?`, { title: config.title, confirmLabel: config.label })) {
            return;
        }
        let payload;
        try {
            payload = await window.FAMApi.request(api(`contracts/transition.php?id=${encodeURIComponent(id)}`), { method: 'POST', body });
        } catch (error) {
            if (action === 'submit_approval' && apiErrorCode(error) === 'CLIENT_REQUIREMENTS_INCOMPLETE') {
                window.FAMModal?.showToast?.('Cannot submit for approval. Complete the client requirements first.', { type: 'error' });
                revealClientRequirements();
                return;
            }
            if (action === 'submit_approval' && apiErrorCode(error) === 'CONTRACT_DATES_UNCONFIRMED') {
                window.FAMModal?.showToast?.('Cannot submit for approval. Confirm the contract dates first.', { type: 'error' });
                revealContractDates();
                return;
            }
            if (action === 'submit_approval' && apiErrorCode(error) === 'SIGNED_CONTRACT_REQUIRED') {
                window.FAMModal?.showToast?.('Cannot submit for approval. Upload the signed contract first.', { type: 'error' });
                revealContractDocument();
                return;
            }
            throw error;
        }
        window.FAMModal?.showToast?.('Contract lifecycle updated.');
        if (payload.data?.item) {
            state.activeItem = payload.data.item;
            qs('#contract-details-modal').innerHTML = detailsHtml(payload.data.item);
            loadGoogleAuthoringStatus(payload.data.item.id).catch(() => {});
        }
        await load();
    }

    function openContractDateReview(id, focusField = 'start') {
        const item = state.activeItem;
        if (!item || Number(item.id) !== Number(id)) return;
        const dates = item.contractDates || {};
        const startValue = dates.stale ? (dates.suggestedStartDate || dates.startDate || '') : (dates.startDate || dates.suggestedStartDate || '');
        const endValue = dates.stale ? (dates.suggestedEndDate || dates.endDate || '') : (dates.endDate || dates.suggestedEndDate || '');
        const sourceLabel = dates.sourceDocumentVersionId ? `Source version #${dates.sourceDocumentVersionId}` : 'No finalized source version';
        const modal = moveToTopLayer(qs('#contract-dialog'));
        if (!modal) return;
        modal.hidden = false;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.innerHTML = `<div class="facility-dialog-panel document-form">
            <div class="facility-details-modal-header">
                <div><p>Contract Dates</p><h2>Review Contract Dates</h2><span class="facility-details-modal-request-number">${esc(item.contractNo)} - ${esc(sourceLabel)}</span></div>
                <button class="facility-details-modal-close" type="button" data-contract-dialog-close aria-label="Close dialog">&times;</button>
            </div>
            <form class="facility-dialog-body document-form-body" data-contract-dates-form data-contract-id="${esc(id)}">
                <div class="visitor-detail-grid contract-detail-grid">
                    <div class="visitor-detail-item"><span>AI START DATE</span><strong>${esc(dates.suggestedStartDate ? fmtDate(dates.suggestedStartDate) : 'No suggestion')}</strong></div>
                    <div class="visitor-detail-item"><span>AI END DATE</span><strong>${esc(dates.suggestedEndDate ? fmtDate(dates.suggestedEndDate) : 'No suggestion')}</strong></div>
                </div>
                <label class="facility-field"><span>Start Date</span><input name="start_date" type="date" value="${esc(startValue)}" required></label>
                <label class="facility-field"><span>End Date</span><input name="end_date" type="date" value="${esc(endValue)}" required></label>
                <div class="facility-dialog-actions">
                    <button class="btn-secondary dashboard-action-button" type="button" data-contract-dialog-close>Cancel</button>
                    <button class="btn-primary dashboard-action-button" type="submit">Confirm Dates</button>
                </div>
            </form>
        </div>`;
        modal.querySelector(`input[name="${focusField === 'end' ? 'end_date' : 'start_date'}"]`)?.focus();
    }

    async function submitContractDates(form) {
        const id = Number(form.dataset.contractId);
        const payload = Object.fromEntries(new FormData(form).entries());
        const response = await window.FAMApi.request(api(`contracts/confirm-dates.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: payload });
        window.FAMModal?.showToast?.('Contract dates confirmed.');
        closeDialog();
        if (response.data?.item) {
            state.activeItem = response.data.item;
            qs('#contract-details-modal').innerHTML = detailsHtml(response.data.item);
            loadGoogleAuthoringStatus(response.data.item.id).catch(() => {});
        } else {
            await openDetails(id);
        }
        await load();
    }

    async function handleApprovalAction(id, action) {
        const body = { action: action.toUpperCase() };
        if (action === 'approve') {
            if (!await window.FAMModal.confirm('Approve the current contract approval step?', { title: 'Approve Contract Step', confirmLabel: 'Approve' })) return;
        } else {
            const reason = await window.FAMModal.prompt(`Enter the reason to ${action === 'reject' ? 'reject this contract' : 'return this contract for changes'}.`, '', { title: action === 'reject' ? 'Reject Contract' : 'Return for Changes', inputLabel: 'Reason', confirmLabel: action === 'reject' ? 'Reject' : 'Return' });
            if (!reason) return;
            body.reason = reason;
        }
        const payload = await window.FAMApi.request(api(`contracts/approval-action.php?id=${encodeURIComponent(id)}`), { method: 'POST', body });
        window.FAMModal?.showToast?.('Contract approval updated.');
        if (payload.data?.item) {
            state.activeItem = payload.data.item;
            qs('#contract-details-modal').innerHTML = detailsHtml(payload.data.item);
            loadGoogleAuthoringStatus(payload.data.item.id).catch(() => {});
        } else {
            await openDetails(id);
        }
        await load();
    }

    async function handleClientRequirementAction(contractId, requirementId, action) {
        const actionMap = {
            verify: { action: 'VERIFY', title: 'Verify Requirement', confirm: 'Verify this submitted client requirement?', label: 'Verify' },
            reject: { action: 'REJECT', title: 'Reject Requirement', label: 'Reject', reason: 'Enter the rejection reason.' },
            mark_not_applicable: { action: 'MARK_NOT_APPLICABLE', title: 'Mark Not Applicable', confirm: 'Mark this conditional client requirement not applicable?', label: 'Not Applicable' },
        };
        const config = actionMap[action];
        if (!config) return;
        const body = { action: config.action, requirement_id: requirementId };
        if (config.reason) {
            const reason = await window.FAMModal.prompt(config.reason, '', { title: config.title, inputLabel: 'Reason', confirmLabel: config.label });
            if (!reason) return;
            body.reason = reason;
        } else if (!await window.FAMModal.confirm(config.confirm, { title: config.title, confirmLabel: config.label })) {
            return;
        }
        const payload = await window.FAMApi.request(api(`contracts/client-requirement-action.php?id=${encodeURIComponent(contractId)}`), { method: 'POST', body });
        window.FAMModal?.showToast?.('Client requirement updated.');
        if (payload.data?.item) {
            state.activeItem = payload.data.item;
            qs('#contract-details-modal').innerHTML = detailsHtml(payload.data.item);
            loadGoogleAuthoringStatus(payload.data.item.id).catch(() => {});
        } else {
            await openDetails(contractId);
        }
        await load();
    }

    function requirementFileInput() {
        let input = qs('#contract-requirement-file-input');
        if (input) return input;
        input = document.createElement('input');
        input.id = 'contract-requirement-file-input';
        input.type = 'file';
        input.accept = '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg';
        input.hidden = true;
        input.addEventListener('change', () => {
            const file = input.files?.[0] || null;
            const request = input._requirementUploadRequest;
            input.value = '';
            input._requirementUploadRequest = null;
            if (!file || !request) {
                restoreRequirementUploadFocus(request?.requirement?.id);
                requirementUploadTrigger = null;
                return;
            }
            uploadRequirementEvidence(request.contract, request.requirement, file).catch(showError);
        });
        document.body.appendChild(input);
        return input;
    }

    function chooseRequirementEvidence(contract, requirement, trigger) {
        if (!contract?.id || !requirement?.id || requirementUploadsInProgress.has(Number(requirement.id))) return;
        requirementUploadTrigger = trigger;
        const input = requirementFileInput();
        input._requirementUploadRequest = { contract, requirement };
        input.click();
    }

    async function uploadRequirementEvidence(contract, requirement, file) {
        const requirementId = Number(requirement.id);
        requirementUploadsInProgress.add(requirementId);
        refreshDetailsClientRequirements();
        const headers = new Headers({ Accept: 'application/json' });
        if (window.FAMApi.csrfToken) headers.set('X-CSRF-Token', window.FAMApi.csrfToken);
        const body = new FormData();
        body.set('requirement_id', String(requirementId));
        body.set('requirement_name', requirement.name || '');
        body.set('requirement_description', requirement.description || '');
        body.set('requirement_classification', requirement.classification || 'REQUIRED');
        body.set('document_date', new Date().toISOString().slice(0, 10));
        body.set('change_summary', 'Client requirement evidence upload');
        body.set('file', file);
        try {
            const response = await fetch(api(`contracts/upload-client-requirement.php?id=${encodeURIComponent(contract.id)}`), { method: 'POST', credentials: 'same-origin', headers, body });
            const payload = await response.json().catch(() => null);
            if (!response.ok || payload?.success === false) {
                const errors = payload?.data?.errors || {};
                throw new Error(Object.values(errors)[0] || payload?.message || 'Unable to attach document.');
            }
            window.FAMModal?.showToast?.('Client requirement evidence uploaded.');
            if (payload.data?.item) {
                state.activeItem = payload.data.item;
                qs('#contract-details-modal').innerHTML = detailsHtml(payload.data.item);
                loadGoogleAuthoringStatus(payload.data.item.id).catch(() => {});
            }
            await load();
        } finally {
            requirementUploadsInProgress.delete(requirementId);
            refreshDetailsClientRequirements();
            restoreRequirementUploadFocus(requirementId);
            requirementUploadTrigger = null;
        }
    }

    function restoreRequirementUploadFocus(requirementId = null) {
        const fresh = requirementId
            ? qs(`[data-client-requirement-upload][data-requirement-id="${CSS.escape(String(requirementId))}"]`)
            : null;
        (fresh || requirementUploadTrigger)?.focus?.();
    }

    function signedContractFileInput() {
        let input = qs('#contract-signed-file-input');
        if (input) return input;
        input = document.createElement('input');
        input.id = 'contract-signed-file-input';
        input.type = 'file';
        input.accept = '.pdf,.doc,.docx';
        input.hidden = true;
        input.addEventListener('change', () => {
            const file = input.files?.[0] || null;
            const request = input._signedContractUploadRequest;
            input.value = '';
            input._signedContractUploadRequest = null;
            if (!file || !request) {
                signedContractUploadTrigger = null;
                return;
            }
            uploadSignedContract(request.contractId, file).catch(showError);
        });
        document.body.appendChild(input);
        return input;
    }

    function chooseSignedContract(contractId, trigger) {
        signedContractUploadTrigger = trigger;
        const input = signedContractFileInput();
        input._signedContractUploadRequest = { contractId };
        input.click();
    }

    async function uploadSignedContract(contractId, file) {
        const headers = new Headers({ Accept: 'application/json' });
        if (window.FAMApi.csrfToken) headers.set('X-CSRF-Token', window.FAMApi.csrfToken);
        const body = new FormData();
        body.set('change_summary', 'Signed/executed contract copy uploaded');
        body.set('file', file);
        try {
            const response = await fetch(api(`contracts/upload-signed-contract.php?id=${encodeURIComponent(contractId)}`), { method: 'POST', credentials: 'same-origin', headers, body });
            const payload = await response.json().catch(() => null);
            if (!response.ok || payload?.success === false) {
                const errors = payload?.data?.errors || {};
                throw new Error(Object.values(errors)[0] || payload?.message || 'Unable to upload signed contract.');
            }
            window.FAMModal?.showToast?.('Signed contract uploaded.');
            if (payload.data?.item) {
                state.activeItem = payload.data.item;
                qs('#contract-details-modal').innerHTML = detailsHtml(payload.data.item);
                loadGoogleAuthoringStatus(payload.data.item.id).catch(() => {});
            }
            await load();
        } finally {
            signedContractUploadTrigger = null;
        }
    }

    function refreshDetailsClientRequirements() {
        const modal = qs('#contract-details-modal');
        const item = state.activeItem;
        if (!modal || modal.hidden || !item) return;
        modal.innerHTML = detailsHtml(item);
    }

    function bind() {
        if (!can('contract.create')) qs('#contract-new')?.classList.add('hidden');
        qs('#contract-new')?.addEventListener('click', () => openForm());
        qs('#contract-refresh')?.addEventListener('click', load);
        qs('#contract-export')?.addEventListener('click', () => { window.location.href = exportUrl(); });
        ['#contract-search','#contract-status-filter','#contract-type-filter','#contract-expiry-filter'].forEach(selector => {
            qs(selector)?.addEventListener('input', () => { state.page = 1; load(); });
            qs(selector)?.addEventListener('change', () => { state.page = 1; load(); });
        });
        qs('#contract-prev-page')?.addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
        qs('#contract-next-page')?.addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; load(); } });
        document.addEventListener('click', event => {
            const toggle = event.target.closest('[data-contract-menu-toggle]');
            if (toggle) {
                event.preventDefault();
                event.stopPropagation();
                return window.FAMTableMenus?.toggle?.(toggle, document.getElementById(toggle.dataset.contractMenuToggle));
            }
            const action = event.target.closest('[data-contract-action]');
            if (action) {
                event.preventDefault();
                event.stopPropagation();
                window.FAMTableMenus?.close?.();
                const id = Number(action.dataset.contractId);
                const type = action.dataset.contractAction;
                if (type === 'view') return openDetails(id).catch(showError);
                if (type === 'edit') return openDetails(id).then(() => openForm(state.activeItem)).catch(showError);
                return handleTransition(id, type).catch(showError);
            }
            const dateReview = event.target.closest('[data-contract-dates-review]');
            if (dateReview) {
                event.preventDefault();
                event.stopPropagation();
                dateReviewTrigger = dateReview;
                return openContractDateReview(Number(dateReview.dataset.contractDatesReview), dateReview.dataset.contractDateField || 'start');
            }
            const requirementUpload = event.target.closest('[data-client-requirement-upload]');
            if (requirementUpload) {
                event.preventDefault();
                event.stopPropagation();
                const requirementId = Number(requirementUpload.dataset.requirementId || 0);
                const requirement = requirementId ? (state.activeItem?.clientRequirements?.items || []).find(item => Number(item.id) === requirementId) : null;
                return chooseRequirementEvidence(state.activeItem, requirement, requirementUpload);
            }
            const fileAction = event.target.closest('[data-document-file-action]');
            if (fileAction) {
                event.preventDefault();
                event.stopPropagation();
                return guardedDocumentFileAction(fileAction);
            }
            const signedUpload = event.target.closest('[data-signed-contract-upload]');
            if (signedUpload) {
                event.preventDefault();
                event.stopPropagation();
                return chooseSignedContract(Number(signedUpload.dataset.signedContractUpload), signedUpload);
            }
            const requirementAction = event.target.closest('[data-client-requirement-action]');
            if (requirementAction) {
                event.preventDefault();
                event.stopPropagation();
                return handleClientRequirementAction(Number(requirementAction.dataset.contractId), Number(requirementAction.dataset.requirementId), requirementAction.dataset.clientRequirementAction).catch(showError);
            }
            const templateAuthoring = event.target.closest('[data-contract-template-authoring]');
            if (templateAuthoring) {
                event.preventDefault();
                event.stopPropagation();
                return openTemplateAuthoring(Number(templateAuthoring.dataset.contractTemplateAuthoring), { returnToDetails: true }).catch(showError);
            }
            const googleConnect = event.target.closest('[data-google-connect]');
            if (googleConnect) {
                event.preventDefault();
                event.stopPropagation();
                return connectGoogle().catch(showError);
            }
            const googleCreate = event.target.closest('[data-google-create]');
            if (googleCreate) {
                event.preventDefault();
                event.stopPropagation();
                return createGoogleDocument(Number(googleCreate.dataset.googleCreate)).catch(showError);
            }
            const googleSync = event.target.closest('[data-google-sync]');
            if (googleSync) {
                event.preventDefault();
                event.stopPropagation();
                return syncGoogleDocument(Number(googleSync.dataset.googleSync)).catch(showError);
            }
            const placeholder = event.target.closest('[data-placeholder-code]');
            if (placeholder) {
                event.preventDefault();
                event.stopPropagation();
                return openPlaceholderEditor(placeholder);
            }
            const unsupportedPlaceholder = event.target.closest('[data-placeholder-unsupported]');
            if (unsupportedPlaceholder) {
                event.preventDefault();
                event.stopPropagation();
                return window.FAMModal?.showToast?.('This placeholder is not configured for contract authoring.');
            }
            const applyPlaceholder = event.target.closest('[data-placeholder-apply]');
            if (applyPlaceholder) {
                event.preventDefault();
                event.stopPropagation();
                const code = applyPlaceholder.dataset.placeholderApply;
                const input = qs('[data-placeholder-editor-input]');
                if (authoringState?.values && code) authoringState.values[code] = input?.value || '';
                closePlaceholderEditor();
                rerenderAuthoringPreview();
                return;
            }
            if (event.target.closest('[data-placeholder-cancel]')) {
                event.preventDefault();
                event.stopPropagation();
                return closePlaceholderEditor();
            }
            const approvalAction = event.target.closest('[data-contract-approval-action]');
            if (approvalAction) {
                event.preventDefault();
                event.stopPropagation();
                return handleApprovalAction(Number(approvalAction.dataset.contractId), approvalAction.dataset.contractApprovalAction).catch(showError);
            }
            if (event.target.closest('[data-contract-dialog-close]')) {
                event.preventDefault();
                event.stopPropagation();
                if (returnToDetailsAfterAuthoring) {
                    closeAuthoringAndReturn({ confirmDiscard: true }).catch(showError);
                } else {
                    closeDialog();
                }
            }
            if (event.target === qs('#contract-dialog')) {
                event.preventDefault();
                event.stopPropagation();
                return closeDialog();
            }
            if (event.target.closest('[data-contract-details-close]')) closeDetails();
            if (event.target === qs('#contract-details-modal')) closeDetails();
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-contract-form]');
            if (form) { event.preventDefault(); submitForm(form); }
            const dateForm = event.target.closest('[data-contract-dates-form]');
            if (dateForm) { event.preventDefault(); submitContractDates(dateForm).catch(showError); }
            const templateValues = event.target.closest('[data-contract-template-values]');
            if (templateValues) { event.preventDefault(); submitTemplateValues(templateValues); }
        });
        qsa('[data-sort]').forEach(button => button.addEventListener('click', () => {
            const sort = button.dataset.sort;
            state.direction = state.sort === sort && state.direction === 'asc' ? 'desc' : 'asc';
            state.sort = sort;
            load();
        }));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && qs('[data-placeholder-editor]:not(.hidden)')) {
                event.preventDefault();
                event.stopPropagation();
                closePlaceholderEditor();
                return;
            }
            if (event.key === 'Escape' && qs('#contract-dialog')?.hidden === false) {
                event.preventDefault();
                event.stopPropagation();
                if (returnToDetailsAfterAuthoring) {
                    closeAuthoringAndReturn({ confirmDiscard: true }).catch(showError);
                } else {
                    closeDialog();
                }
                return;
            }
            if (event.key === 'Escape' && qs('#contract-details-modal')?.hidden === false) {
                event.preventDefault();
                event.stopPropagation();
                closeDetails();
            }
        });
    }

    function showError(error) {
        window.FAMModal?.showToast?.(error.message || 'Contract action failed.', { type: 'error' });
    }

    function apiErrorCode(error) {
        return String(error?.payload?.data?.code || error?.code || '').toUpperCase();
    }

    function revealClientRequirements() {
        const modal = qs('#contract-details-modal');
        if (!modal || modal.hidden) return;
        const section = modal.querySelector('[data-client-requirements-section]');
        if (!section) return;
        section.open = true;
        window.requestAnimationFrame(() => {
            section.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
            const summary = section.querySelector('summary');
            summary?.focus?.({ preventScroll: true });
        });
    }

    function revealContractDates() {
        const modal = qs('#contract-details-modal');
        if (!modal || modal.hidden) return;
        const surface = modal.querySelector('[data-contract-date-review-surface]');
        if (!surface) return;
        const section = surface.closest('details');
        if (section) section.open = true;
        window.requestAnimationFrame(() => {
            surface.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
            surface.querySelector('button')?.focus?.({ preventScroll: true });
        });
    }

    function revealContractDocument() {
        const modal = qs('#contract-details-modal');
        if (!modal || modal.hidden) return;
        const section = modal.querySelector('.contract-document-workspace');
        if (!section) return;
        section.open = true;
        window.requestAnimationFrame(() => {
            section.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
            section.querySelector('summary')?.focus?.({ preventScroll: true });
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        try {
            if (!window.FAMApi.currentUser) await window.FAMApi.me();
            if (!can('contract.view')) {
                renderError('You do not have permission to view contracts.');
                return;
            }
            bind();
            await loadOptions();
            await load();
        } catch (error) {
            renderError(error.message || 'Unable to initialize Contract Management.');
        }
    });
})();
