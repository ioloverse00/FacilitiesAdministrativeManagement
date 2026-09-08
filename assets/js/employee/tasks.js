(function () {
    const state = {
        rows: [],
        summary: { pending: 0 },
        filter: 'all',
        activeTaskId: null,
        lastFocus: null
    };

    const qs = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const title = value => String(value || '').toLowerCase().split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
    const fmt = value => {
        if (!value) return 'Not available';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    const money = contract => {
        const amount = Number(contract?.amount);
        if (!Number.isFinite(amount)) return 'Not available';
        return `${contract.currency || 'PHP'} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    function api(path) {
        return `employee/approvals/${path}`;
    }

    function badge(status) {
        const raw = String(status || 'pending').toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-status-${raw}">${esc(statusLabel({ status }))}</span>`;
    }

    function emptyState(titleText = 'No assigned tasks', copy = "You're all caught up. New tasks assigned to you will appear here.") {
        return window.FAMEmployeePortal.emptyState('task_alt', titleText, copy);
    }

    function taskLabel(item) {
        if (item.module === 'contract_management' && item.entity_type === 'contract') return 'Approve Contract';
        return title(item.title || 'Assigned Task');
    }

    function moduleLabel(item) {
        if (item.module === 'contract_management') return 'Contract Management';
        return title(item.module || 'Workflow');
    }

    function isActionable(item) {
        return Boolean(item?.is_actionable);
    }

    function statusLabel(item) {
        const status = String(item?.status || '').toUpperCase();
        const decision = String(item?.decision || '').toUpperCase();
        if (status === 'COMPLETED' && decision === 'APPROVED') return 'Approved';
        if (status === 'COMPLETED') return 'Completed';
        if (status === 'CANCELLED' && decision === 'RETURNED') return 'Returned';
        if (status === 'CANCELLED' && decision === 'REJECTED') return 'Rejected';
        if (status === 'CANCELLED') return 'Cancelled';
        return title(status || decision || 'Pending');
    }

    function visibleRows() {
        if (state.filter === 'pending') return state.rows.filter(isActionable);
        if (state.filter === 'completed') return state.rows.filter(item => !isActionable(item));
        return state.rows;
    }

    function rowHtml(item) {
        const actionLabel = isActionable(item) ? 'Review' : 'View';
        return `
            <tr>
                <td><button class="facility-link-button table-cell-primary" type="button" data-open-approval="${esc(item.task_id)}">${esc(taskLabel(item))}</button><span class="table-cell-subtext">${esc(item.step_name || '')}</span></td>
                <td><span>${esc(item.contract?.number || item.entity_reference)}</span><span class="table-cell-subtext">${esc(item.contract?.title || '')}</span></td>
                <td>${esc(moduleLabel(item))}</td>
                <td>${esc(fmt(item.assigned_at || item.submitted_at))}</td>
                <td>${badge(statusLabel(item))}</td>
                <td><button class="btn-secondary dashboard-action-button" type="button" data-open-approval="${esc(item.task_id)}"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>${esc(actionLabel)}</button></td>
            </tr>
        `;
    }

    function cardHtml(item) {
        const actionLabel = isActionable(item) ? 'Review' : 'View';
        return `
            <article class="employee-record-card">
                <div class="employee-record-card-top">
                    <button class="facility-link-button employee-record-reference" type="button" data-open-approval="${esc(item.task_id)}">${esc(item.contract?.number || item.entity_reference)}</button>
                    ${badge(statusLabel(item))}
                </div>
                <h3>${esc(taskLabel(item))}</h3>
                <dl class="employee-record-meta">
                    <div><dt>Reference</dt><dd>${esc(item.contract?.number || item.entity_reference)}</dd></div>
                    <div><dt>Module</dt><dd>${esc(moduleLabel(item))}</dd></div>
                    <div><dt>Assigned</dt><dd>${esc(fmt(item.assigned_at || item.submitted_at))}</dd></div>
                </dl>
                <button class="btn-secondary dashboard-action-button" type="button" data-open-approval="${esc(item.task_id)}"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>${esc(actionLabel)}</button>
            </article>
        `;
    }

    function render() {
        const body = qs('#employee-tasks-body');
        const cards = qs('#employee-tasks-cards');
        const count = qs('#employee-approval-pending-count');
        const scope = qs('#employee-approval-scope');
        const rows = visibleRows();
        const pending = Number(state.summary.pending || state.rows.filter(isActionable).length);
        if (count) count.textContent = String(pending);
        if (scope) scope.textContent = state.rows.length
            ? 'Pending tasks stay actionable. Completed tasks remain available for history.'
            : "You're all caught up. New tasks assigned to you will appear here.";
        if (!body) return;
        if (!rows.length) {
            const titleText = state.filter === 'pending' ? 'No pending tasks' : state.filter === 'completed' ? 'No completed tasks' : 'No assigned tasks';
            const copy = state.filter === 'all' ? "You're all caught up. New tasks assigned to you will appear here." : 'Try switching to another task status filter.';
            body.innerHTML = `<tr><td colspan="6">${emptyState(titleText, copy)}</td></tr>`;
            if (cards) cards.innerHTML = emptyState(titleText, copy);
            return;
        }
        body.innerHTML = rows.map(rowHtml).join('');
        if (cards) cards.innerHTML = rows.map(cardHtml).join('');
    }

    async function load() {
        const response = await window.FAMApi.request(api('list.php'));
        state.rows = response.data?.items || [];
        state.summary = response.data?.summary || { pending: 0 };
        render();
    }

    function ensureDialog() {
        let dialog = qs('#employee-approval-dialog');
        if (!dialog) {
            dialog = document.createElement('div');
            dialog.id = 'employee-approval-dialog';
            dialog.className = 'facility-dialog';
            dialog.hidden = true;
            document.body.appendChild(dialog);
        }
        if (dialog.parentElement !== document.body) document.body.appendChild(dialog);
        return dialog;
    }

    function closeDialog() {
        const dialog = qs('#employee-approval-dialog');
        if (!dialog) return;
        window.FAMModal?.closeElement?.(dialog, () => {
            dialog.innerHTML = '';
            document.body.classList.remove('fam-modal-open');
            state.activeTaskId = null;
            state.lastFocus?.focus?.();
        });
    }

    function detailRow(label, value) {
        return `<div><dt>${esc(label)}</dt><dd>${esc(value || 'Not available')}</dd></div>`;
    }

    function stepTimeline(steps) {
        return (steps || []).map(step => `
            <li class="employee-approval-step ${String(step.status || '').toLowerCase()}">
                <span>${esc(step.sequence)}. ${esc(step.name)}</span>
                <strong>${esc(title(step.status || step.decision || 'Pending'))}</strong>
                <small>${esc(step.approverDisplay || 'Configured approver')}</small>
            </li>
        `).join('');
    }

    function renderDetail(item) {
        const dialog = ensureDialog();
        const contract = item.contract || {};
        const approval = item.approval || {};
        const financial = contract.financial || null;
        const dates = contract.dates || {};
        const availableActions = approval.available_actions || [];
        const task = item.task || {};
        const readOnly = availableActions.length === 0;
        state.activeTaskId = item.task?.id;
        state.lastFocus = document.activeElement;
        dialog.hidden = false;
        document.body.classList.add('fam-modal-open');
        dialog.innerHTML = `
            <section class="facility-dialog-panel employee-request-form employee-approval-panel" role="dialog" aria-modal="true" aria-labelledby="employee-approval-title">
                <div class="facility-details-modal-header">
                    <div>
                        <p>Assigned Task</p>
                        <h2 id="employee-approval-title">${esc(taskLabel({ module: 'contract_management', entity_type: 'contract' }))}</h2>
                    </div>
                    <button class="facility-details-modal-close" type="button" data-close-approval aria-label="Close approval details">&times;</button>
                </div>
                <div class="facility-details-modal-body">
                    <section class="fam-section">
                        <div class="fam-section-heading employee-section-heading">
                            <h3>Task Context</h3>
                            <p>${readOnly ? 'This task has already been completed.' : 'Current actionable work assigned to your employee account.'}</p>
                        </div>
                        <dl class="facility-details-grid">
                            ${detailRow('Task', taskLabel({ module: 'contract_management', entity_type: 'contract' }))}
                            ${detailRow('Reference', contract.number)}
                            ${detailRow('Workflow Step', approval.current_step?.name)}
                            ${detailRow('Assigned To', approval.current_step?.approver)}
                            ${detailRow('Assigned Date', fmt(task.assigned_at || approval.current_step?.assigned_at))}
                            ${detailRow('Completed Date', task.completed_at || approval.current_step?.decided_at ? fmt(task.completed_at || approval.current_step?.decided_at) : '')}
                            ${detailRow('Status', statusLabel({ status: task.status, decision: approval.current_step?.decision }))}
                        </dl>
                    </section>
                    <section class="fam-section">
                        <div class="fam-section-heading employee-section-heading">
                            <h3>Contract Information</h3>
                            <p>${esc(contract.title || 'Contract approval')}</p>
                        </div>
                        <dl class="facility-details-grid">
                            ${detailRow('Contract Number', contract.number)}
                            ${detailRow('Title', contract.title)}
                            ${detailRow('Counterparty', contract.counterparty?.name || contract.supplier?.name)}
                            ${detailRow('Type', contract.type?.name || contract.type?.code)}
                            ${detailRow('Owning Department', contract.owning_department?.name)}
                            ${detailRow('Contract Owner', contract.contract_owner?.name)}
                            ${financial && Object.prototype.hasOwnProperty.call(financial, 'currentAmount') ? detailRow('Value', `${financial.currencyCode || 'PHP'} ${Number(financial.currentAmount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`) : ''}
                            ${detailRow('Effective Date', dates.effectiveDate || dates.startDate)}
                            ${detailRow('End Date', dates.endDate)}
                            ${detailRow('Risk', title(contract.risk_level))}
                            ${detailRow('Status', title(contract.status))}
                            ${detailRow('Contract Administrator', contract.contract_administrator?.name)}
                            ${detailRow('Assigned Approver', approval.current_step?.approver)}
                        </dl>
                        <div class="facility-details-description">
                            <h3>Description</h3>
                            <p>${esc(contract.description || 'No description provided.')}</p>
                        </div>
                    </section>
                    <section class="fam-section">
                        <div class="fam-section-heading employee-section-heading">
                            <h3>Approval Progress</h3>
                            <p>Step ${esc(approval.current_step_number)} of ${esc(approval.total_steps)}</p>
                        </div>
                        <ol class="employee-approval-steps">${stepTimeline(approval.steps)}</ol>
                    </section>
                </div>
                <div class="facility-dialog-actions">
                    ${readOnly ? '<button class="btn-primary dashboard-action-button" type="button" data-close-approval>Done</button>' : `
                    <button class="btn-secondary dashboard-action-button" type="button" data-approval-action="return"><span class="material-symbols-outlined" aria-hidden="true">reply</span>Return</button>
                    <button class="btn-secondary dashboard-action-button" type="button" data-approval-action="reject"><span class="material-symbols-outlined" aria-hidden="true">block</span>Reject</button>
                    <button class="btn-primary dashboard-action-button" type="button" data-approval-action="approve"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>Approve</button>
                    `}
                </div>
            </section>
        `;
        window.FAMModal?.bringToFront?.(dialog);
    }

    async function openApproval(taskId) {
        const response = await window.FAMApi.request(api(`show.php?task_id=${encodeURIComponent(taskId)}`));
        renderDetail(response.data?.item || {});
    }

    async function openDeepLinkedTask() {
        const taskId = new URLSearchParams(window.location.search).get('task');
        if (!taskId) return;
        try {
            await openApproval(taskId);
        } catch (error) {
            window.FAMModal.showToast(error.message || 'This approval task is no longer available or is not assigned to you.', { type: 'error' });
        }
    }

    async function approvalAction(action) {
        const taskId = state.activeTaskId;
        if (!taskId) return;
        let comment = '';
        if (action === 'approve') {
            const confirmed = await window.FAMModal.confirm('Approve this contract at your current approval step?', { title: 'Approve Contract', confirmLabel: 'Approve' });
            if (!confirmed) return;
        } else {
            const label = action === 'reject' ? 'Reject Contract' : 'Return Contract';
            const value = await window.FAMModal.prompt(`${label}. Enter the required reason.`, '', { title: label, inputLabel: 'Reason', confirmLabel: title(action) });
            if (value === null) return;
            comment = String(value || '').trim();
            if (!comment) {
                window.FAMModal.showToast('Reason is required.', { type: 'error' });
                return;
            }
        }
        const response = await window.FAMApi.request(api('action.php'), { method: 'POST', body: { task_id: taskId, action, comment } });
        const result = response.data?.item || {};
        const message = result.approval_status === 'APPROVED'
            ? 'Contract approval workflow completed.'
            : 'Contract approval submitted. The workflow has advanced to the next step.';
        window.FAMModal.showToast(message);
        closeDialog();
        await load();
    }

    function bindEvents() {
        qs('#employee-task-status-filter')?.addEventListener('change', event => {
            state.filter = event.target.value || 'all';
            render();
        });
        document.addEventListener('click', event => {
            const opener = event.target.closest('[data-open-approval]');
            if (opener) {
                openApproval(opener.dataset.openApproval).catch(error => window.FAMModal.showToast(error.message || 'Unable to load approval task.', { type: 'error' }));
                return;
            }
            if (event.target.closest('[data-close-approval]')) {
                closeDialog();
                return;
            }
            const action = event.target.closest('[data-approval-action]');
            if (action) {
                approvalAction(action.dataset.approvalAction).catch(error => window.FAMModal.showToast(error.message || 'Approval action failed.', { type: 'error' }));
            }
        });
    }

    document.addEventListener('fam:employee-layout-ready', () => {
        bindEvents();
        load().then(openDeepLinkedTask).catch(error => {
            const body = qs('#employee-tasks-body');
            if (body) body.innerHTML = `<tr><td colspan="6">${emptyState('Unable to load assigned tasks', error.message || 'Please try again.')}</td></tr>`;
        });
    });
})();
