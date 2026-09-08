(function () {
    const api = path => `../../api/employee/facility-requests/${path}`;
    const qs = selector => document.querySelector(selector);
    const qsa = selector => Array.from(document.querySelectorAll(selector));
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const title = value => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const fmt = value => {
        if (!value) return 'Not applicable';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    const trunc = (value, className = 'table-cell-truncate') => `<span class="${className}" title="${esc(value || 'Not applicable')}">${esc(value || 'Not applicable')}</span>`;
    const badge = value => `<span class="facility-badge facility-status-${String(value || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-')}">${esc(title(value || 'Not applicable'))}</span>`;
    const state = { rows: [], options: {}, search: '', status: '', loading: true, loaded: false, error: false, currentDetailsId: null, timer: null };

    function tableStateRow(message, icon, spinning = false) {
        return `<tr class="fam-table-state-row"><td colspan="8"><div class="fam-state" role="status"><span class="material-symbols-outlined${spinning ? ' fam-spinner' : ''}" aria-hidden="true">${icon}</span><span>${esc(message)}</span></div></td></tr>`;
    }

    function optionList(items, selected = '') {
        return (items || []).map(item => {
            const value = String(item.id ?? item.value ?? '');
            const label = item.name || item.full_name || item.code || value;
            return `<option value="${esc(value)}" ${String(selected) === value ? 'selected' : ''}>${esc(label)}</option>`;
        }).join('');
    }

    function filteredRows() {
        const needle = state.search.toLowerCase();
        return state.rows.filter(row => {
            const matchesStatus = !state.status || String(row.status || '') === state.status;
            const haystack = [row.request_number, row.subject, row.category?.name, row.location?.space_name].join(' ').toLowerCase();
            return matchesStatus && (!needle || haystack.includes(needle));
        });
    }

    function actions(item) {
        const buttons = [`<button type="button" role="menuitem" data-open-facility-request="${esc(item.id)}">View Details</button>`];
        if (item.allowed_actions?.cancel) {
            buttons.push(`<button type="button" role="menuitem" data-cancel-facility-request="${esc(item.id)}">Cancel Request</button>`);
        }
        return buttons.join('');
    }

    function render() {
        const body = qs('#employee-facility-requests-body');
        const cards = qs('#employee-facility-requests-cards');
        const rows = filteredRows();
        qs('#employee-request-total-count').textContent = String(state.rows.length);
        qs('#employee-request-open-count').textContent = String(state.rows.filter(row => !['CLOSED','CANCELLED','REJECTED'].includes(String(row.status || '').toUpperCase())).length);
        qs('#employee-request-count').textContent = rows.length ? `${rows.length} facility request${rows.length === 1 ? '' : 's'} found` : 'No matching facility requests';
        if (!body || !cards) return;
        if (state.loading && !state.loaded) {
            body.innerHTML = tableStateRow('Loading facility requests...', 'progress_activity', true);
            cards.innerHTML = '<div class="fam-state" role="status">Loading facility requests...</div>';
            return;
        }
        if (state.error && !state.loaded) {
            body.innerHTML = tableStateRow('Unable to load facility requests.', 'error');
            cards.innerHTML = '<div class="fam-state" role="status">Unable to load facility requests.</div>';
            return;
        }
        if (!rows.length && !state.loading) {
            const filtered = Boolean(state.search || state.status);
            const message = filtered ? 'No facility requests match the current search or filters.' : 'No facility requests found.';
            const empty = window.FAMEmployeePortal?.emptyState?.('domain_disabled', message, filtered ? 'Try changing the search or status filter.' : 'Submit a new request when you need facilities support.') || message;
            body.innerHTML = tableStateRow(message, filtered ? 'search_off' : 'domain_disabled');
            cards.innerHTML = empty;
            return;
        }
        body.innerHTML = rows.map(item => {
            const menuId = `request-${item.id}`;
            return `<tr>
                <td class="facility-request-number"><button class="facility-link-button" type="button" data-open-facility-request="${esc(item.id)}">${esc(item.request_number || 'Pending')}</button></td>
                <td>${trunc(item.subject, 'table-cell-primary')}</td>
                <td>${trunc(item.category?.name)}</td>
                <td>${trunc(item.location?.space_name || 'Not applicable')}</td>
                <td>${badge(item.priority || 'NORMAL')}</td>
                <td>${badge(item.status || 'SUBMITTED')}</td>
                <td class="facility-date-cell">${trunc(fmt(item.updated_at || item.created_at))}</td>
                <td class="facility-actions-cell"><div class="facility-action-menu"><button class="facility-action-toggle" type="button" data-employee-menu="${esc(menuId)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(item.request_number)}">&#8942;</button><div class="facility-action-dropdown hidden" data-employee-menu-panel="${esc(menuId)}" role="menu">${actions(item)}</div></div></td>
            </tr>`;
        }).join('');
        cards.innerHTML = rows.map(item => {
            const menuId = `card-${item.id}`;
            return `<article class="employee-record-card"><div class="employee-record-card-top"><button class="facility-link-button employee-record-reference" type="button" data-open-facility-request="${esc(item.id)}">${esc(item.request_number || 'Pending')}</button>${badge(item.status)}</div><h3>${esc(item.subject || 'Facility Request')}</h3><dl class="employee-record-meta"><dt>Category</dt><dd>${esc(item.category?.name || 'Not applicable')}</dd><dt>Facility</dt><dd>${esc(item.location?.space_name || 'Not applicable')}</dd><dt>Priority</dt><dd>${esc(title(item.priority || 'NORMAL'))}</dd><dt>Updated</dt><dd>${esc(fmt(item.updated_at || item.created_at))}</dd></dl><div class="employee-record-card-actions"><div class="facility-action-menu"><button class="facility-action-toggle" type="button" data-employee-menu="${esc(menuId)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(item.request_number)}">&#8942;</button><div class="facility-action-dropdown hidden" data-employee-menu-panel="${esc(menuId)}" role="menu">${actions(item)}</div></div></div></article>`;
        }).join('');
        window.FAMTableAudit?.check?.(body.closest('table'), 'employee-facility-requests-table');
    }

    function ensureDialog() {
        let dialog = qs('#employee-facility-request-dialog');
        if (!dialog) {
            dialog = document.createElement('div');
            dialog.id = 'employee-facility-request-dialog';
            dialog.className = 'facility-dialog';
            dialog.hidden = true;
            document.body.appendChild(dialog);
        }
        return dialog;
    }

    function closeDialog() {
        const dialog = qs('#employee-facility-request-dialog');
        if (dialog) window.FAMModal?.closeElement?.(dialog, () => { dialog.innerHTML = ''; });
    }

    function openForm() {
        const dialog = ensureDialog();
        const employee = state.options.current_employee || {};
        dialog.hidden = false;
        dialog.innerHTML = `<form class="facility-dialog-panel employee-request-form" data-facility-request-form>
            <div class="facility-details-modal-header"><div><p>${esc(employee.full_name || 'Employee')}</p><h2>New Facility Request</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close form">&times;</button></div>
            <div class="facility-form-error" data-form-error hidden></div>
            <div class="employee-requester-summary" aria-label="Requester information"><span><strong>${esc(employee.full_name || 'Employee')}</strong><small>${esc([employee.employee_number, employee.department?.name || employee.department?.code].filter(Boolean).join(' - '))}</small></span><span>${esc(employee.email || 'Email not available')}</span></div>
            <label class="facility-field"><span>Subject <b aria-hidden="true">*</b></span><input name="subject" required maxlength="255"></label>
            <label class="facility-field"><span>Description</span><textarea name="description" rows="4"></textarea></label>
            <div class="facility-form-grid">
                <label class="facility-field"><span>Category <b aria-hidden="true">*</b></span><select name="request_category_id" required><option value="">Select category</option>${optionList(state.options.categories)}</select></label>
                <label class="facility-field"><span>Facility Space</span><select name="facility_space_id"><option value="">Not applicable</option>${optionList(state.options.facility_spaces)}</select></label>
                <label class="facility-field"><span>Priority</span><select name="priority">${(state.options.priorities || ['NORMAL']).map(priority => `<option value="${esc(priority)}" ${priority === 'NORMAL' ? 'selected' : ''}>${esc(title(priority))}</option>`).join('')}</select></label>
                <label class="facility-field"><span>Requested Completion</span><input name="requested_completion_at" type="datetime-local"></label>
            </div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Submit Request</button></div>
        </form>`;
        dialog.querySelector('input, select, textarea, button')?.focus();
    }

    function detail(label, value) {
        return `<dl class="facility-detail-row"><dt>${esc(label)}</dt><dd>${esc(value || 'Not applicable')}</dd></dl>`;
    }

    function detailsHtml(item) {
        const history = (item.history || []).map(row => `${title(row.old_status || 'Created')} to ${title(row.new_status)} - ${fmt(row.changed_at)}${row.change_reason ? ` - ${row.change_reason}` : ''}`);
        const actions = item.allowed_actions?.cancel ? `<div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-cancel-facility-request="${esc(item.id)}">Cancel Request</button></div>` : '';
        return `<div class="facility-dialog-panel employee-request-details">
            <div class="facility-details-modal-header"><div><p>Facility Request</p><span class="facility-details-modal-request-number">${esc(item.request_number || 'Pending')}</span><h2>${esc(item.subject || 'Request Details')}</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close details">&times;</button></div>
            <div class="facility-details-modal-body"><div class="facility-detail-grid">
                <section><h3>Request Summary</h3><div class="detail-grid">${detail('Status', title(item.status))}${detail('Priority', title(item.priority))}${detail('Category', item.category?.name)}${detail('Requested Completion', fmt(item.lifecycle?.requested_completion_at))}</div></section>
                <section><h3>Requester and Location</h3><div class="detail-grid">${detail('Requester', item.requested_by?.full_name)}${detail('Department', item.requested_by?.department?.name)}${detail('Facility Space', item.location?.space_name)}${detail('Building', item.location?.building_name)}</div></section>
                <section class="facility-detail-wide"><h3>Description</h3><p>${esc(item.description || 'No description provided.')}</p></section>
                <section class="facility-detail-wide"><h3>History</h3>${history.length ? `<ul class="facility-detail-list">${history.map(line => `<li>${esc(line)}</li>`).join('')}</ul>` : '<p>No history recorded.</p>'}</section>
            </div></div>${actions}
        </div>`;
    }

    async function openDetails(id) {
        const dialog = ensureDialog();
        state.currentDetailsId = id;
        dialog.hidden = false;
        dialog.innerHTML = '<div class="facility-dialog-panel"><div class="fam-state"><span class="material-symbols-outlined fam-spinner" aria-hidden="true">progress_activity</span><span>Loading request details...</span></div></div>';
        try {
            const payload = await window.FAMApi.request(api(`show.php?id=${encodeURIComponent(id)}`));
            dialog.innerHTML = detailsHtml(payload.data?.item || {});
        } catch (error) {
            dialog.innerHTML = `<div class="facility-dialog-panel"><div class="facility-details-modal-header"><div><p>Facility Request</p><h2>Request Details</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close details">&times;</button></div><div class="fam-state" role="alert"><span class="material-symbols-outlined" aria-hidden="true">lock</span><span>${esc(error.message || 'Unable to load request details.')}</span></div></div>`;
        }
    }

    async function submitForm(form) {
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        const body = Object.fromEntries(new FormData(form).entries());
        if (!body.facility_space_id) body.facility_space_id = null;
        try {
            const payload = await window.FAMApi.request(api('create.php'), { method: 'POST', body });
            window.FAMModal?.showToast?.('Facility request submitted.');
            closeDialog();
            await load();
            const id = payload.data?.item?.id;
            if (id) openDetails(id);
        } catch (error) {
            const box = form.querySelector('[data-form-error]');
            if (box) {
                box.textContent = error.message || 'Unable to submit request.';
                box.hidden = false;
            }
        } finally {
            button.disabled = false;
        }
    }

    async function cancelRequest(id) {
        const reason = await window.FAMModal?.prompt?.('Reason for cancelling this request:', 'Cancelled by requester.', { title: 'Cancel Facility Request', confirmLabel: 'Cancel Request' });
        if (reason === null) return;
        await window.FAMApi.request(api(`cancel.php?id=${encodeURIComponent(id)}`), { method: 'POST', body: { reason: reason || 'Cancelled by requester.' } });
        window.FAMModal?.showToast?.('Facility request cancelled.');
        await load();
        if (state.currentDetailsId) openDetails(state.currentDetailsId);
    }

    async function load() {
        if (state.loading && state.loaded) return;
        state.loading = true;
        state.error = false;
        render();
        try {
            const payload = await window.FAMApi.request(api('list.php?per_page=50'));
            state.rows = payload.data?.items || [];
        } catch (error) {
            state.error = true;
            if (!state.loaded) state.rows = [];
            window.FAMModal?.showToast?.(error.message || 'Unable to load facility requests.');
        } finally {
            state.loading = false;
            render();
            state.loaded = true;
        }
    }

    async function init() {
        const options = await window.FAMApi.request(api('options.php'));
        state.options = options.data || {};
        qs('#employee-facility-request-status').innerHTML = '<option value="">All Statuses</option>' + (state.options.statuses || []).map(status => `<option value="${esc(status)}">${esc(title(status))}</option>`).join('');
        await load();
        const id = new URLSearchParams(window.location.search).get('request');
        if (id && /^\d+$/.test(id)) openDetails(id);
    }

    qs('#employee-new-facility-request')?.addEventListener('click', openForm);
    qs('#employee-facility-request-search')?.addEventListener('input', event => {
        state.search = event.target.value.trim();
        clearTimeout(state.timer);
        state.timer = setTimeout(render, 150);
    });
    qs('#employee-facility-request-status')?.addEventListener('change', event => {
        state.status = event.target.value;
        render();
    });
    document.addEventListener('click', event => {
        if (event.target.closest('[data-close-dialog]') || event.target === qs('#employee-facility-request-dialog')) return closeDialog();
        const menuToggle = event.target.closest('[data-employee-menu]');
        if (menuToggle) return window.FAMTableMenus?.toggle(menuToggle, qs(`[data-employee-menu-panel="${CSS.escape(menuToggle.dataset.employeeMenu)}"]`));
        const open = event.target.closest('[data-open-facility-request]');
        if (open) return openDetails(open.dataset.openFacilityRequest);
        const cancel = event.target.closest('[data-cancel-facility-request]');
        if (cancel) return cancelRequest(cancel.dataset.cancelFacilityRequest).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to cancel request.'));
        const form = event.target.closest('[data-facility-request-form]');
        if (form && event.type === 'submit') return false;
    });
    document.addEventListener('submit', event => {
        const form = event.target.closest('[data-facility-request-form]');
        if (!form) return;
        event.preventDefault();
        submitForm(form);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !qs('#employee-facility-request-dialog')?.hidden) closeDialog();
    });
    document.addEventListener('fam:employee-layout-ready', () => init().catch(error => {
        state.loading = false;
        render();
        window.FAMModal?.showToast?.(error.message || 'Unable to initialize facility requests.');
    }));
})();
