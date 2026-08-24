(function () {
    const state = { page: 1, totalPages: 1, sort: 'updated_at', direction: 'desc', options: {}, activeItem: null, loading: false };
    const qs = selector => document.querySelector(selector);
    const qsa = selector => Array.from(document.querySelectorAll(selector));
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const api = path => `../api/${path}`;
    const can = permission => (window.FAMApi?.currentUser?.permissions || []).includes(permission) || (window.FAMApi?.currentUser?.permissions || []).includes('contract.manage');

    function moveToTopLayer(element) {
        if (element && element.parentElement !== document.body) document.body.appendChild(element);
        return element;
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
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
            '#contract-department-filter': 'owning_department_reference_id',
            '#contract-handler-filter': 'fam_handler_employee_reference_id',
            '#contract-expiry-filter': 'expiry_state',
        };
        Object.entries(map).forEach(([selector, key]) => {
            const value = qs(selector)?.value;
            if (value && value !== 'all') p.set(key, value);
        });
        return p;
    }

    async function load() {
        if (state.loading) return;
        state.loading = true;
        qs('#contract-loading-state')?.classList.remove('hidden');
        try {
            const payload = await window.FAMApi.request(api(`contracts/index.php?${params()}`));
            const data = payload.data || {};
            renderSummary(data.summary || {});
            renderRows(data.items || []);
            renderPagination(data.pagination || {});
            qs('#contract-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        } catch (error) {
            renderError(error.message || 'Unable to load contracts.');
        } finally {
            qs('#contract-loading-state')?.classList.add('hidden');
            state.loading = false;
        }
    }

    async function loadOptions() {
        const payload = await window.FAMApi.request(api('contracts/options.php'));
        state.options = payload.data || {};
        fillObjects(qs('#contract-type-filter'), state.options.contract_types || [], 'All Types');
        fillObjects(qs('#contract-department-filter'), state.options.departments || [], 'All Departments');
        fillObjects(qs('#contract-handler-filter'), state.options.contract_administrators || state.options.fam_handlers || [], 'All Administrators');
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
        const empty = qs('#contract-empty-state');
        qs('#contract-table-count').textContent = `Showing ${items.length} ${items.length === 1 ? 'contract' : 'contracts'}`;
        if (!items.length) {
            body.innerHTML = '';
            empty.innerHTML = `<span class="material-symbols-outlined" aria-hidden="true">contract</span><strong>No contracts found.</strong><p>${hasFilters() ? 'Try changing your search or filters.' : 'Contract records will appear here once created.'}</p>`;
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        body.innerHTML = items.map(row => `<tr>
            <td><button class="document-primary-cell contract-primary-cell" type="button" data-contract-action="view" data-report-action="contract-details" data-contract-id="${esc(row.id)}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">contract</span><span><strong>${esc(row.contractNo)}</strong><small>${esc(row.type?.code || row.type?.name || '')}</small></span></button></td>
            <td><div class="contract-title-cell"><strong title="${esc(row.title)}">${esc(row.title)}</strong><small>${esc(row.type?.name || 'Contract')}</small></div></td>
            <td>${esc(row.supplier?.name || 'Not set')}</td>
            <td>${esc(row.owningDepartment?.name || 'Not set')}</td>
            <td><div class="contract-date-cell"><strong>${esc(registerEndDate(row))}</strong>${expiryIndicator(row) ? `<small>${expiryIndicator(row)}</small>` : ''}</div></td>
            <td>${badge(row.status, 'status')}</td>
            <td>${esc(row.owner?.name || row.famHandler?.name || 'Unassigned')}</td>
            <td>${actionMenu(row)}</td>
        </tr>`).join('');
    }

    function hasFilters() {
        return Boolean(qs('#contract-search')?.value.trim()) || ['#contract-status-filter','#contract-type-filter','#contract-department-filter','#contract-handler-filter','#contract-expiry-filter'].some(selector => {
            const field = qs(selector);
            return field && field.value !== 'all';
        });
    }

    function renderError(message) {
        qs('#contract-table').innerHTML = '';
        const empty = qs('#contract-empty-state');
        empty.innerHTML = `<span class="material-symbols-outlined" aria-hidden="true">error</span><strong>Unable to load contracts.</strong><p>${esc(message)}</p>`;
        empty.classList.remove('hidden');
    }

    function actionMenu(row) {
        const labels = { view: 'View Details', edit: 'Edit Contract', submit_review: 'Submit for Review', cancel: 'Cancel', return_draft: 'Return to Draft', submit_approval: 'Submit for Approval', approve: 'Approve', reject: 'Reject', activate: 'Activate', terminate: 'Terminate', archive: 'Archive' };
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

    function openForm(item = null) {
        if (!item && !can('contract.create')) return;
        const modal = moveToTopLayer(qs('#contract-dialog'));
        modal.hidden = false;
        const isEdit = Boolean(item);
        const dates = item?.dates || {};
        const financial = item?.financial || {};
        modal.innerHTML = `<form class="facility-dialog-panel contract-form" data-contract-form="${isEdit ? 'edit' : 'create'}" data-contract-id="${esc(item?.id || '')}">
            <div class="facility-details-modal-header">
                <div><p>Contract Management</p><h2>${isEdit ? 'Edit Draft Contract' : 'New Contract'}</h2></div>
                <button class="facility-details-modal-close" type="button" data-contract-dialog-close aria-label="Close dialog">&times;</button>
            </div>
            <div class="facility-dialog-body document-form-body">
                <div class="document-form-error hidden" data-contract-form-error></div>
                <section class="document-form-section"><h3>Contract Information</h3><div class="document-form-grid">
                    ${field('contract_title', 'Contract Title *', item?.title || '', 'text', true)}
                    ${selectField('contract_type_id', 'Contract Type *', optionList(state.options.contract_types, item?.type?.id, 'Select type'))}
                    ${selectField('owning_department_reference_id', 'Owning Department *', optionList(state.options.departments, item?.owningDepartment?.id, 'Select department'))}
                    ${selectField('contract_owner_employee_reference_id', 'Contract Administrator *', optionList(state.options.contract_administrators || state.options.contract_owners, item?.owner?.id || item?.famHandler?.id, 'Select administrator'))}
                </div>${textarea('contract_description', 'Description', item?.description || '')}</section>
                <section class="document-form-section"><h3>References</h3><div class="document-form-grid">
                    ${selectField('supplier_reference_id', 'Supplier / Counterparty', optionList(state.options.suppliers, item?.supplier?.id, 'None'))}
                    ${selectField('budget_reference_id', 'Budget Reference', optionList(state.options.budgets, item?.budget?.id, 'None'))}
                    ${selectField('procurement_request_id', 'Procurement Request', optionList(state.options.procurement_requests, item?.procurementRequest?.id, 'None'))}
                    ${selectField('purchase_order_reference_id', 'Purchase Order', optionList(state.options.purchase_orders, item?.purchaseOrder?.id, 'None'))}
                </div></section>
                <section class="document-form-section"><h3>Dates &amp; Value</h3><div class="document-form-grid">
                    ${field('start_date', 'Start Date *', dates.startDate || '', 'date', true)}
                    ${field('effective_date', 'Effective Date', dates.effectiveDate || '', 'date')}
                    ${field('end_date', 'End Date *', dates.endDate || '', 'date', true)}
                    ${field('original_amount', 'Original Amount *', financial.originalAmount ?? 0, 'number', true)}
                    ${field('current_amount', 'Current Amount', financial.currentAmount ?? financial.originalAmount ?? 0, 'number')}
                    ${selectField('currency_code', 'Currency *', (state.options.currencies || ['PHP']).map(value => `<option value="${esc(value)}" ${String(financial.currencyCode || 'PHP') === String(value) ? 'selected' : ''}>${esc(value)}</option>`).join(''))}
                </div></section>
                <section class="document-form-section"><h3>Renewal &amp; Risk</h3><div class="document-form-grid">
                    ${field('notice_period_days', 'Notice Period Days', item?.noticePeriodDays ?? '', 'number')}
                    ${selectField('renewal_type', 'Renewal Type', (state.options.renewal_types || ['NONE']).map(value => `<option value="${esc(value)}" ${String(item?.renewalType || 'NONE') === String(value) ? 'selected' : ''}>${esc(title(value))}</option>`).join(''))}
                    ${field('renewal_decision_date', 'Renewal Decision Date', item?.renewalDecisionDate || '', 'date')}
                    ${selectField('risk_level', 'Risk Level', `<option value="">Not set</option>` + (state.options.risk_levels || []).map(value => `<option value="${esc(value)}" ${String(item?.riskLevel || '') === String(value) ? 'selected' : ''}>${esc(title(value))}</option>`).join(''))}
                </div></section>
            </div>
            <div class="facility-dialog-actions">
                <button class="btn-secondary dashboard-action-button" type="button" data-contract-dialog-close>Cancel</button>
                <button class="btn-primary dashboard-action-button" type="submit">${isEdit ? 'Save Changes' : 'Create Draft'}</button>
            </div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('[name="contract_title"]')?.focus();
    }

    function field(name, label, value = '', type = 'text', required = false) {
        const step = type === 'number' ? ' step="0.01" min="0"' : '';
        return `<label class="facility-field"><span>${esc(label)}</span><input name="${esc(name)}" type="${esc(type)}" value="${esc(value)}"${required ? ' required' : ''}${step}></label>`;
    }

    function textarea(name, label, value = '') {
        return `<label class="facility-field document-full-field"><span>${esc(label)}</span><textarea name="${esc(name)}" rows="3">${esc(value)}</textarea></label>`;
    }

    function selectField(name, label, options) {
        return `<label class="facility-field"><span>${esc(label)}</span><select name="${esc(name)}">${options}</select></label>`;
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
            if (payload.data?.item) await openDetails(payload.data.item.id, payload.data.item);
            await load();
        } catch (err) {
            const errors = err.data?.errors || {};
            error.textContent = Object.values(errors)[0] || err.message || 'Unable to save contract.';
            error.classList.remove('hidden');
        } finally {
            submit.disabled = false;
        }
    }

    function closeDialog() {
        const modal = qs('#contract-dialog');
        modal.hidden = true;
        modal.innerHTML = '';
        if (qs('#contract-details-modal')?.hidden !== false) document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
    }

    async function openDetails(id, known = null) {
        const modal = moveToTopLayer(qs('#contract-details-modal'));
        modal.hidden = false;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.innerHTML = detailsShell('Loading...', '<div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">progress_activity</span><span>Loading contract details...</span></div>');
        try {
            const item = known || (await window.FAMApi.request(api(`contracts/show.php?id=${id}`))).data?.item;
            state.activeItem = item;
            modal.innerHTML = detailsHtml(item);
            modal.querySelector('[data-contract-details-close]')?.focus();
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
        const overview = detailGrid([
            ['Contract No.', item.contractNo], ['Lifecycle Status', badge(item.status, 'status'), false, true], ['Type', item.type?.name], ['Supplier / Counterparty', item.supplier?.name || 'Not set'],
            ['Owning Department', item.owningDepartment?.name], ['Contract Administrator', item.owner?.name || item.famHandler?.name || 'Unassigned'],
            ['Current Value', money(item.financial)], ['Start Date', fmtDate(d.startDate)], ['Effective Date', fmtDate(d.effectiveDate)], ['End Date', fmtDate(d.endDate)],
            ['Risk Level', item.riskLevel ? title(item.riskLevel) : 'Not set'], ['Description', item.description || 'Not applicable', true],
        ]);
        const referenceRows = [
            item.budget ? ['Budget', item.budget.name] : null,
            item.procurementRequest ? ['Procurement Request', item.procurementRequest.number] : null,
            item.purchaseOrder ? ['Purchase Order', item.purchaseOrder.number] : null,
        ].filter(Boolean);
        const references = referenceRows.length ? detailGrid(referenceRows) : '<p class="legal-empty-note">No budget, procurement request, or purchase order linked.</p>';
        const renewalRows = [
            d.executedDate ? ['Executed Date', fmtDate(d.executedDate)] : null,
            item.renewalType && item.renewalType !== 'NONE' ? ['Renewal Type', title(item.renewalType)] : null,
            item.renewalDecisionDate ? ['Renewal Decision Date', fmtDate(item.renewalDecisionDate)] : null,
            item.noticePeriodDays !== null ? ['Notice Period', `${item.noticePeriodDays} days`] : null,
        ].filter(Boolean);
        const renewal = renewalRows.length ? detailGrid(renewalRows) : '<p class="legal-empty-note">No renewal or execution dates recorded yet.</p>';
        const operationalRows = [
            expiryIndicator(item) ? ['Expiry State', expiryIndicator(item), false, true] : null,
            item.derived?.daysToExpiry !== null && item.derived?.daysToExpiry !== undefined ? ['Days to Expiry', item.derived.daysToExpiry] : null,
        ].filter(Boolean);
        const operational = operationalRows.length ? `<details class="visitor-detail-disclosure"><summary>Operational Status</summary>${detailGrid(operationalRows)}</details>` : '';
        const termination = String(item.status || '').toUpperCase() === 'TERMINATED' || item.terminationReason || d.terminationDate ? `<details class="visitor-detail-disclosure"><summary>Termination</summary>${detailGrid([['Termination Date', fmtDate(d.terminationDate)], ['Termination Reason', item.terminationReason || 'Not applicable', true]])}</details>` : '';
        return `<div class="facility-details-modal-panel visitor-details-panel contract-details-panel">
            <div class="facility-details-modal-header visitor-details-header">
                <div><p>Contract</p><span class="facility-details-modal-request-number">${esc(item.contractNo)}</span><h2>${esc(item.title)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-contract-details-close aria-label="Close contract details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body">
                <div class="visitor-detail-accordion contract-detail-accordion">
                    <details class="visitor-detail-disclosure" open><summary>Overview</summary>${overview}</details>
                    <details class="visitor-detail-disclosure"><summary>References</summary>${references}</details>
                    <details class="visitor-detail-disclosure"><summary>Renewal &amp; Dates</summary>${renewal}</details>
                    ${operational}
                    ${termination}
                    <details class="visitor-detail-disclosure" open><summary>Approvals</summary>${approvalHtml(item.approval)}</details>
                    <details class="visitor-detail-disclosure" open><summary>Documents (${(item.documents || []).length})</summary>${documentsHtml(item)}</details>
                    <details class="visitor-detail-disclosure"><summary>History</summary>${historyHtml(item.history || [])}</details>
                </div>
            </div>
            <div class="facility-dialog-actions"><div class="legal-details-workflow-actions contract-details-actions">${detailsActions(item)}</div><button class="btn-secondary dashboard-action-button" type="button" data-contract-details-close>Done</button></div>
        </div>`;
    }

    function detailGrid(rows) {
        return `<div class="visitor-detail-grid">${rows.map(([label, value, full, html]) => `<div class="visitor-detail-item ${full ? 'detail-item--full' : ''}"><span>${esc(label)}</span><strong>${html ? value : esc(value ?? 'Not applicable')}</strong></div>`).join('')}</div>`;
    }

    function detailsActions(item) {
        const labels = { edit: 'Edit Contract', submit_review: 'Submit for Review', cancel: 'Cancel', return_draft: 'Return to Draft', submit_approval: 'Submit for Approval', approve: 'Approve', reject: 'Reject', activate: 'Activate', terminate: 'Terminate', archive: 'Archive' };
        const actions = (item.allowedActions || []).map(action => `<button class="btn-secondary dashboard-action-button" type="button" data-contract-action="${esc(action)}" data-contract-id="${esc(item.id)}">${esc(labels[action] || title(action))}</button>`);
        if (item.approval?.canApproveCurrentStep) actions.push(`<button class="btn-primary dashboard-action-button" type="button" data-contract-approval-action="approve" data-contract-id="${esc(item.id)}">Approve</button>`);
        if (item.approval?.canRejectCurrentStep) actions.push(`<button class="btn-secondary dashboard-action-button" type="button" data-contract-approval-action="reject" data-contract-id="${esc(item.id)}">Reject</button>`);
        if (item.approval?.canReturnCurrentStep) actions.push(`<button class="btn-secondary dashboard-action-button" type="button" data-contract-approval-action="return" data-contract-id="${esc(item.id)}">Return for Changes</button>`);
        if (can('records.create') && (can('contract.edit') || can('contract.manage'))) actions.push(`<button class="btn-primary dashboard-action-button" type="button" data-contract-action="attach_document" data-contract-id="${esc(item.id)}"><span class="material-symbols-outlined" aria-hidden="true">upload_file</span>Attach Contract Document</button>`);
        return actions.join('');
    }

    function approvalHtml(approval = {}) {
        if (!approval.required) return '<p class="legal-empty-note">Not submitted for approval.</p>';
        const current = approval.currentStep?.name ? `<p class="contract-current-step">Current Step: <strong>${esc(approval.currentStep.name)}</strong></p>` : '';
        const progress = `<div class="visitor-detail-grid"><div class="visitor-detail-item"><span>Status</span><strong>${esc(title(approval.status))}</strong></div><div class="visitor-detail-item"><span>Progress</span><strong>${esc(approval.completedSteps || 0)} of ${esc(approval.totalSteps || 0)} completed</strong></div><div class="visitor-detail-item"><span>Submitted</span><strong>${esc(fmt(approval.submittedAt))}</strong></div><div class="visitor-detail-item"><span>Completed</span><strong>${esc(approval.completedAt ? fmt(approval.completedAt) : 'Not completed')}</strong></div></div>`;
        const currentSequence = approval.currentStep?.sequence;
        const steps = (approval.steps || []).length ? `<ol class="contract-approval-list">${approval.steps.map(step => {
            const isCurrent = step.sequence === currentSequence && String(approval.status) === 'PENDING';
            const statusText = isCurrent ? `${title(step.status || step.decision)} / Current` : title(step.status || step.decision);
            const dateText = step.actedAt ? `Approved ${fmt(step.actedAt)}` : (isCurrent && step.assignedAt ? 'Current' : '');
            return `<li class="contract-approval-step"><div class="contract-approval-main"><strong>${esc(step.sequence)}. ${esc(step.name)}</strong><span>${esc(step.approverDisplay || 'Configured approver')}</span></div><div class="contract-approval-meta">${badge(statusText, 'status')}${dateText ? `<small>${esc(dateText)}</small>` : ''}</div>${step.comment ? `<p>${esc(step.comment)}</p>` : ''}</li>`;
        }).join('')}</ol>` : '<p class="legal-empty-note">No approval steps recorded.</p>';
        const previous = (approval.previousRequests || []).length ? `<h3 class="legal-section-subtitle">Previous Cycles</h3><ol class="contract-history-list">${approval.previousRequests.map(row => `<li class="contract-history-event"><strong>${esc(title(row.status))}</strong><span>${esc(row.totalSteps)} configured step(s)</span><small>${esc(fmt(row.submittedAt))}${row.completedAt ? ` - ${esc(fmt(row.completedAt))}` : ''}</small></li>`).join('')}</ol>` : '';
        return `${progress}${current}${steps}${previous}`;
    }

    function documentsHtml(item) {
        const docs = item.documents || [];
        if (!docs.length) return '<p class="legal-empty-note">No contract documents attached yet.</p>';
        return `<div class="document-version-list">${docs.map(doc => `<article class="document-file-row">
            <div><span class="material-symbols-outlined document-file-icon" aria-hidden="true">description</span><div><strong>${esc(doc.title)}</strong><small>${esc(doc.documentNo)} · ${esc(doc.version)}${doc.isPrimary ? ' · Primary Contract Document' : ' · Supporting Document'}</small><small>${esc(doc.fileName || doc.category)} · Uploaded ${esc(fmt(doc.uploadedAt))}</small>${doc.expirationDate ? `<small>Expires ${esc(fmtDate(doc.expirationDate))}</small>` : ''}</div></div>
            <div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${doc.id}`))}" target="_blank" rel="noopener">View</a><a href="${esc(api(`documents/download.php?id=${doc.id}`))}" target="_blank" rel="noopener">Download</a></div>
        </article>`).join('')}</div>`;
    }

    function historyHtml(rows) {
        if (!rows.length) return '<p class="legal-empty-note">No contract history yet.</p>';
        return `<ol class="contract-history-list">${rows.map(row => `<li class="contract-history-event"><strong>${esc(title(row.type))}</strong><span>${esc(row.description)}</span><small>${esc(row.actor || 'System')} - ${esc(fmt(row.eventAt))}</small></li>`).join('')}</ol>`;
    }

    function closeDetails() {
        const modal = qs('#contract-details-modal');
        modal.hidden = true;
        modal.innerHTML = '';
        document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
    }

    async function handleTransition(id, action) {
        const config = {
            submit_review: { target_status: 'FOR_REVIEW', title: 'Submit for Review', label: 'Submit' },
            submit_approval: { target_status: 'FOR_APPROVAL', title: 'Submit for Approval', label: 'Submit' },
            approve: { target_status: 'APPROVED', title: 'Approve Contract', label: 'Approve' },
            archive: { target_status: 'ARCHIVED', title: 'Archive Contract', label: 'Archive' },
            return_draft: { target_status: 'DRAFT', title: 'Return to Draft', label: 'Return', reason: true },
            reject: { target_status: 'REJECTED', title: 'Reject Contract', label: 'Reject', reason: true },
            cancel: { target_status: 'CANCELLED', title: 'Cancel Contract', label: 'Cancel', reason: true },
            activate: { target_status: 'ACTIVE', title: 'Activate Contract', label: 'Activate', activate: true },
            terminate: { target_status: 'TERMINATED', title: 'Terminate Contract', label: 'Terminate', terminate: true },
        }[action];
        if (!config) return;
        const body = { target_status: config.target_status };
        if (config.reason) {
            const reason = await window.FAMModal.prompt(`Enter the reason for ${config.title.toLowerCase()}.`, '', { title: config.title, inputLabel: 'Reason', confirmLabel: config.label });
            if (!reason) return;
            body.reason = reason;
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
        const payload = await window.FAMApi.request(api(`contracts/transition.php?id=${encodeURIComponent(id)}`), { method: 'POST', body });
        window.FAMModal?.showToast?.('Contract lifecycle updated.');
        if (payload.data?.item) {
            state.activeItem = payload.data.item;
            qs('#contract-details-modal').innerHTML = detailsHtml(payload.data.item);
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
        } else {
            await openDetails(id);
        }
        await load();
    }

    function openUpload(contract) {
        const modal = moveToTopLayer(qs('#contract-dialog'));
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel document-form" enctype="multipart/form-data" data-contract-upload="${esc(contract.id)}">
            <div class="facility-details-modal-header"><div><p>Contract Document</p><h2>Attach Contract Document</h2></div><button class="facility-details-modal-close" type="button" data-contract-dialog-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body document-form-body">
                <div class="document-form-error hidden" data-contract-form-error></div>
                ${field('title', 'Document Title *', `${contract.title} Document`, 'text', true)}
                ${textarea('description', 'Description', '')}
                <div class="document-form-grid">
                    ${field('document_date', 'Document Date', new Date().toISOString().slice(0, 10), 'date')}
                    ${selectField('confidentiality_level', 'Confidentiality', '<option value="CONFIDENTIAL">Confidential</option><option value="RESTRICTED">Restricted</option><option value="INTERNAL">Internal</option>')}
                    <label class="facility-field"><span>Primary Document</span><select name="is_primary_document"><option value="">Supporting Document</option><option value="1">Primary Contract Document</option></select></label>
                </div>
                <label class="facility-field document-full-field document-file-field"><span>File *</span><input name="file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" required></label>
                ${textarea('change_summary', 'Version Notes', 'Initial contract document upload')}
            </div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-contract-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Attach Document</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    async function submitUpload(form) {
        const error = form.querySelector('[data-contract-form-error]');
        error.classList.add('hidden');
        const headers = new Headers({ Accept: 'application/json' });
        if (window.FAMApi.csrfToken) headers.set('X-CSRF-Token', window.FAMApi.csrfToken);
        const response = await fetch(api(`contracts/attach-document.php?id=${encodeURIComponent(form.dataset.contractUpload)}`), { method: 'POST', credentials: 'same-origin', headers, body: new FormData(form) });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) {
            const errors = payload?.data?.errors || {};
            error.textContent = Object.values(errors)[0] || payload?.message || 'Unable to attach document.';
            error.classList.remove('hidden');
            return;
        }
        closeDialog();
        window.FAMModal?.showToast?.('Contract document attached.');
        if (payload.data?.item) {
            state.activeItem = payload.data.item;
            qs('#contract-details-modal').innerHTML = detailsHtml(payload.data.item);
        }
        await load();
    }

    function bind() {
        if (!can('contract.create')) qs('#contract-new')?.classList.add('hidden');
        qs('#contract-new')?.addEventListener('click', () => openForm());
        qs('#contract-refresh')?.addEventListener('click', load);
        ['#contract-search','#contract-status-filter','#contract-type-filter','#contract-department-filter','#contract-handler-filter','#contract-expiry-filter'].forEach(selector => {
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
                if (type === 'attach_document') return openUpload(state.activeItem);
                return handleTransition(id, type).catch(showError);
            }
            const approvalAction = event.target.closest('[data-contract-approval-action]');
            if (approvalAction) {
                event.preventDefault();
                event.stopPropagation();
                return handleApprovalAction(Number(approvalAction.dataset.contractId), approvalAction.dataset.contractApprovalAction).catch(showError);
            }
            if (event.target.closest('[data-contract-dialog-close]')) closeDialog();
            if (event.target.closest('[data-contract-details-close]')) closeDetails();
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-contract-form]');
            if (form) { event.preventDefault(); submitForm(form); }
            const upload = event.target.closest('[data-contract-upload]');
            if (upload) { event.preventDefault(); submitUpload(upload); }
        });
        qsa('[data-sort]').forEach(button => button.addEventListener('click', () => {
            const sort = button.dataset.sort;
            state.direction = state.sort === sort && state.direction === 'asc' ? 'desc' : 'asc';
            state.sort = sort;
            load();
        }));
    }

    function showError(error) {
        window.FAMModal?.showToast?.(error.message || 'Contract action failed.', { type: 'error' });
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
