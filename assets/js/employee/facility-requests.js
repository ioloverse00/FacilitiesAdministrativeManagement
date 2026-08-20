(function () {
    const state = {
        context: null,
        options: null,
        rows: [],
        search: '',
        status: '',
        timer: null,
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

    function statusLabel(status) {
        return {
            SUBMITTED: 'Submitted',
            PENDING_APPROVAL: 'Pending Review',
            APPROVED: 'Approved',
            ASSIGNED: 'Assigned',
            IN_PROGRESS: 'In Progress',
            COMPLETED: 'Completed',
            VERIFIED: 'Verified',
            CLOSED: 'Closed',
            REJECTED: 'Rejected',
            CANCELLED: 'Cancelled'
        }[String(status || '').toUpperCase()] || title(status);
    }

    function api(path) {
        return `../../api/employee/facility-requests/${path}`;
    }

    function emptyRow(titleText = 'No facility requests yet', copy = 'Requests you submit will appear here for tracking.') {
        return `<tr><td colspan="7">${window.FAMEmployeePortal.emptyState('domain', titleText, copy)}</td></tr>`;
    }

    function badge(status) {
        return `<span class="facility-badge facility-status-${String(status || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-')}">${esc(statusLabel(status))}</span>`;
    }

    function rowHtml(item) {
        const canCancel = Boolean(item.allowed_actions?.cancel);
        const actions = [
            `<button type="button" role="menuitem" data-open-request="${item.id}">View Details</button>`,
            canCancel ? `<button type="button" role="menuitem" data-cancel-request="${item.id}">Cancel Request</button>` : ''
        ].filter(Boolean).join('');
        return `
            <tr>
                <td><button class="facility-link-button table-cell-primary" type="button" data-open-request="${item.id}">${esc(item.request_number)}</button></td>
                <td><span class="table-cell-truncate" title="${esc(item.subject)}">${esc(item.subject)}</span></td>
                <td><span class="table-cell-truncate" title="${esc(item.category?.name)}">${esc(item.category?.name || 'Not available')}</span></td>
                <td>${esc(title(item.priority))}</td>
                <td>${badge(item.status)}</td>
                <td>${esc(fmt(item.updated_at || item.created_at))}</td>
                <td class="facility-actions-cell">
                    <div class="facility-action-menu">
                        <button class="facility-action-toggle" type="button" data-employee-menu="${item.id}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(item.request_number)}">&#8942;</button>
                        <div class="facility-action-dropdown hidden" data-employee-menu-panel="${item.id}" role="menu">${actions}</div>
                    </div>
                </td>
            </tr>
        `;
    }

    function mobileCardHtml(item) {
        const menuId = `request-card-${item.id}`;
        const canCancel = Boolean(item.allowed_actions?.cancel);
        const actions = [
            `<button type="button" role="menuitem" data-open-request="${esc(item.id)}">View Details</button>`,
            canCancel ? `<button type="button" role="menuitem" data-cancel-request="${esc(item.id)}">Cancel Request</button>` : ''
        ].filter(Boolean).join('');
        return `
            <article class="employee-record-card">
                <div class="employee-record-card-top">
                    <button class="facility-link-button employee-record-reference" type="button" data-open-request="${esc(item.id)}">${esc(item.request_number)}</button>
                    ${badge(item.status)}
                </div>
                <h3>${esc(item.subject || 'Facility request')}</h3>
                <dl class="employee-record-meta">
                    <div><dt>Category</dt><dd>${esc(item.category?.name || 'Not available')}</dd></div>
                    <div><dt>Priority</dt><dd>${esc(title(item.priority))}</dd></div>
                    <div><dt>Updated</dt><dd>${esc(fmt(item.updated_at || item.created_at))}</dd></div>
                </dl>
                <div class="employee-record-card-actions">
                    <div class="facility-action-menu">
                        <button class="facility-action-toggle" type="button" data-employee-menu="${esc(menuId)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(item.request_number)}">&#8942;</button>
                        <div class="facility-action-dropdown hidden" data-employee-menu-panel="${esc(menuId)}" role="menu">${actions}</div>
                    </div>
                </div>
            </article>
        `;
    }

    function isActive(item) {
        return !['COMPLETED', 'VERIFIED', 'CLOSED', 'CANCELLED', 'REJECTED'].includes(String(item.status || '').toUpperCase());
    }

    function activeCard(item) {
        return `
            <article class="fam-card employee-active-card">
                <div>
                    <span class="employee-card-kicker">${esc(item.request_number)}</span>
                    <h3>${esc(item.subject || 'Facility request')}</h3>
                    <p>${esc(item.category?.name || 'No category')} &middot; Updated ${esc(fmt(item.updated_at || item.created_at))}</p>
                </div>
                <div>
                    ${badge(item.status)}
                    <button class="facility-link-button" type="button" data-open-request="${esc(item.id)}">View Details</button>
                </div>
            </article>
        `;
    }

    function renderActiveRequests() {
        const target = qs('#employee-active-requests');
        if (!target) return;
        const active = state.rows.filter(isActive).slice(0, 3);
        target.innerHTML = active.length
            ? active.map(activeCard).join('')
            : window.FAMEmployeePortal.emptyState('task_alt', 'No active requests', 'New and in-progress facility requests will appear here.');
    }

    function render() {
        const body = qs('#employee-facility-requests-body');
        const cards = qs('#employee-facility-requests-cards');
        if (!body) return;
        renderActiveRequests();
        if (!state.rows.length) {
            const titleText = state.search || state.status ? 'No matching requests' : 'No facility requests yet';
            const copy = state.search || state.status ? 'Try adjusting your search or status filter.' : 'Requests you submit will appear here for tracking.';
            body.innerHTML = emptyRow(
                titleText,
                copy
            );
            if (cards) cards.innerHTML = window.FAMEmployeePortal.emptyState('domain', titleText, copy);
            return;
        }
        body.innerHTML = state.rows.map(rowHtml).join('');
        if (cards) cards.innerHTML = state.rows.map(mobileCardHtml).join('');
    }

    async function load() {
        const params = new URLSearchParams({ per_page: '50' });
        if (state.search) params.set('search', state.search);
        if (state.status) params.set('status', state.status);
        const response = await window.FAMApi.request(api(`list.php?${params}`));
        state.rows = response.data?.items || [];
        render();
    }

    function optionList(items, selected = '') {
        return (items || []).map(item => `<option value="${esc(item.id)}" ${String(item.id) === String(selected) ? 'selected' : ''}>${esc(item.name || item.code || item.id)}</option>`).join('');
    }

    function priorityOptions(selected = 'NORMAL') {
        return (state.options?.priorities || ['LOW', 'NORMAL', 'MEDIUM', 'HIGH', 'CRITICAL']).map(priority => `<option value="${esc(priority)}" ${priority === selected ? 'selected' : ''}>${esc(title(priority))}</option>`).join('');
    }

    function ensureDialog() {
        let dialog = qs('#employee-request-dialog');
        if (!dialog) {
            dialog = document.createElement('div');
            dialog.id = 'employee-request-dialog';
            dialog.className = 'facility-dialog';
            dialog.hidden = true;
            document.body.appendChild(dialog);
        }
        if (dialog.parentElement !== document.body) document.body.appendChild(dialog);
        return dialog;
    }

    function closeDialog() {
        const dialog = qs('#employee-request-dialog');
        if (!dialog) return;
        window.FAMModal?.closeElement?.(dialog, () => {
            dialog.innerHTML = '';
            document.body.classList.remove('fam-modal-open');
            state.lastFocus?.focus?.();
        });
    }

    function employeeSummary() {
        const context = state.context || {};
        return `
            <div class="employee-requester-summary" aria-label="Requester information">
                <span><strong>${esc(context.full_name || 'Employee')}</strong><small>${esc([context.employee_number, context.department?.name || context.department?.code].filter(Boolean).join(' - '))}</small></span>
                <span>${esc(context.email || 'Email not available')}</span>
            </div>
        `;
    }

    function openCreateForm() {
        const dialog = ensureDialog();
        state.lastFocus = document.activeElement;
        dialog.hidden = false;
        document.body.classList.add('fam-modal-open');
        dialog.innerHTML = `
            <form class="facility-dialog-panel employee-request-form" data-request-form>
                <div class="facility-details-modal-header">
                    <div>
                        <p>Employee Portal</p>
                        <h2>Submit Facility Request</h2>
                    </div>
                    <button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close request form">&times;</button>
                </div>
                <div class="facility-form-error" data-form-error hidden></div>
                ${employeeSummary()}
                <div class="facility-form-grid">
                    <label class="facility-field"><span>Subject</span><input name="subject" required maxlength="160"></label>
                    <label class="facility-field"><span>Category</span><select name="request_category_id" required><option value="">Select category</option>${optionList(state.options?.categories)}</select></label>
                    <label class="facility-field"><span>Priority</span><select name="priority">${priorityOptions()}</select></label>
                    <label class="facility-field"><span>Location</span><select name="facility_space_id"><option value="">Not applicable</option>${optionList(state.options?.facility_spaces)}</select></label>
                    <label class="facility-field"><span>Preferred Schedule</span><input name="requested_completion_at" type="datetime-local"></label>
                </div>
                <label class="facility-field"><span>Description</span><textarea name="description" rows="4" required></textarea></label>
                <div class="facility-dialog-actions">
                    <button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button>
                    <button class="btn-primary dashboard-action-button" type="submit">Submit Request</button>
                </div>
            </form>
        `;
        dialog.querySelector('input, select, textarea, button')?.focus();
    }

    function detailsRow(label, value, className = '') {
        return `<dl class="facility-detail-row ${esc(className)}"><dt>${esc(label)}</dt><dd>${esc(value || 'Not available')}</dd></dl>`;
    }

    function detailsGrid(content) {
        return `<div class="detail-grid">${content}</div>`;
    }

    async function openDetails(id) {
        const dialog = ensureDialog();
        state.lastFocus = document.activeElement;
        dialog.hidden = false;
        document.body.classList.add('fam-modal-open');
        dialog.innerHTML = `<div class="facility-dialog-panel"><div class="facility-details-modal-header"><div><p>My Facility Requests</p><h2>Loading request</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close request details">&times;</button></div><div class="facility-details-modal-body">${window.FAMEmployeePortal.emptyState('progress_activity', 'Loading request details', '')}</div></div>`;
        try {
            const response = await window.FAMApi.request(api(`show.php?id=${encodeURIComponent(id)}`));
            const item = response.data?.item;
            const history = (item.history || []).map(row => `<li><strong>${esc(statusLabel(row.new_status))}</strong><span>${esc(fmt(row.changed_at))}</span>${row.change_reason ? `<p>${esc(row.change_reason)}</p>` : ''}</li>`).join('');
            const requestDetails = detailsGrid(`${detailsRow('Status', statusLabel(item.status))}${detailsRow('Category', item.category?.name)}${detailsRow('Priority', title(item.priority))}${detailsRow('Location', item.location?.space_name)}${detailsRow('Submitted', fmt(item.created_at))}${detailsRow('Updated', fmt(item.updated_at))}`);
            const scheduleDetails = detailsGrid(`${detailsRow('Preferred Schedule', fmt(item.lifecycle?.requested_completion_at))}${detailsRow('Completed At', fmt(item.lifecycle?.completed_at))}${detailsRow('Resolution Summary', item.lifecycle?.resolution_summary, 'detail-item--full')}`);
            dialog.innerHTML = `
                <div class="facility-dialog-panel employee-request-details">
                    <div class="facility-details-modal-header">
                        <div>
                            <p>My Facility Requests</p>
                            <span class="facility-details-modal-request-number">${esc(item.request_number)}</span>
                            <h2>${esc(item.subject)}</h2>
                        </div>
                        <button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close request details">&times;</button>
                    </div>
                    <div class="facility-details-modal-body">
                        <div class="visitor-detail-accordion">
                            <details class="visitor-detail-disclosure" open><summary>Request Details</summary>${requestDetails}</details>
                            <details class="visitor-detail-disclosure" open><summary>Description</summary><p>${esc(item.description || 'No description provided.')}</p></details>
                            <details class="visitor-detail-disclosure"><summary>Schedule and Resolution</summary>${scheduleDetails}</details>
                            <details class="visitor-detail-disclosure"><summary>Activity History</summary>${history ? `<ol class="facility-history-list visitor-activity-timeline">${history}</ol>` : '<p>No activity history recorded.</p>'}</details>
                        </div>
                    </div>
                    <div class="facility-dialog-actions">
                        ${item.allowed_actions?.cancel ? `<button class="btn-secondary dashboard-action-button" type="button" data-cancel-request="${item.id}">Cancel Request</button>` : ''}
                        <button class="btn-primary dashboard-action-button" type="button" data-close-dialog>Done</button>
                    </div>
                </div>
            `;
        } catch (error) {
            dialog.innerHTML = `<div class="facility-dialog-panel"><div class="facility-details-modal-header"><div><p>My Facility Requests</p><h2>Request unavailable</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close request details">&times;</button></div><div class="facility-details-modal-body">${window.FAMEmployeePortal.emptyState('error', error.message || 'Unable to load request details.', '')}</div></div>`;
        }
    }

    function setErrors(form, error) {
        form.querySelectorAll('.facility-field-error').forEach(node => node.remove());
        Object.entries(error.errors || error.payload?.data?.errors || {}).forEach(([name, message]) => {
            const field = form.querySelector(`[name="${CSS.escape(name)}"]`);
            field?.insertAdjacentHTML('afterend', `<span class="facility-field-error">${esc(message)}</span>`);
        });
        const box = form.querySelector('[data-form-error]');
        if (box) {
            box.textContent = error.message || 'Please review the highlighted fields.';
            box.hidden = false;
        }
    }

    async function submitForm(form) {
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        form.querySelector('[data-form-error]').hidden = true;
        try {
            const body = Object.fromEntries(new FormData(form).entries());
            const response = await window.FAMApi.request(api('create.php'), { method: 'POST', body });
            closeDialog();
            await load();
            await openDetails(response.data?.item?.id);
        } catch (error) {
            setErrors(form, error);
        } finally {
            button.disabled = false;
        }
    }

    async function cancelRequest(id) {
        if (!await window.FAMModal.confirm('Cancel this facility request?', { title: 'Cancel Facility Request', confirmLabel: 'Cancel Request' })) return;
        await window.FAMApi.request(api(`cancel.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: { reason: 'Cancelled by requester.' } });
        closeDialog();
        await load();
    }

    function bind() {
        qs('#employee-submit-request')?.addEventListener('click', openCreateForm);
        qs('#employee-request-search')?.addEventListener('input', event => {
            state.search = event.target.value.trim();
            clearTimeout(state.timer);
            state.timer = setTimeout(() => load().catch(console.error), 250);
        });
        qs('#employee-request-status')?.addEventListener('change', event => {
            state.status = event.target.value;
            load().catch(console.error);
        });
        document.addEventListener('click', event => {
            const close = event.target.closest('[data-close-dialog]');
            if (close || event.target === qs('#employee-request-dialog')) return closeDialog();
            const menuToggle = event.target.closest('[data-employee-menu]');
            if (menuToggle) {
                const panel = qs(`[data-employee-menu-panel="${CSS.escape(menuToggle.dataset.employeeMenu)}"]`);
                window.FAMTableMenus?.toggle(menuToggle, panel);
                return;
            }
            const open = event.target.closest('[data-open-request]');
            if (open) return openDetails(open.dataset.openRequest);
            const cancel = event.target.closest('[data-cancel-request]');
            if (cancel) return cancelRequest(cancel.dataset.cancelRequest).catch(console.error);
            window.FAMTableMenus?.close();
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-request-form]');
            if (!form) return;
            event.preventDefault();
            submitForm(form);
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !qs('#employee-request-dialog')?.hidden) closeDialog();
        });
    }

    async function init(event) {
        state.context = event.detail?.context || await window.FAMEmployeePortal.context();
        const options = await window.FAMApi.request(api('options.php'));
        state.options = options.data || {};
        bind();
        await load();
        const requestId = new URLSearchParams(window.location.search).get('request');
        if (requestId && /^\d+$/.test(requestId)) openDetails(requestId);
    }

    document.addEventListener('fam:employee-layout-ready', event => init(event).catch(error => {
        console.error(error);
        const body = qs('#employee-facility-requests-body');
        if (body) body.innerHTML = emptyRow('Unable to load requests', error.message || 'Please try again.');
    }));
})();
