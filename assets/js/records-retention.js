(function () {
    const state = { page: 1, totalPages: 1, sort: 'disposition_date', direction: 'asc', options: {}, activeItem: null };
    const qs = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const api = path => `${window.location.origin}${window.location.pathname.replace(/\/pages\/.*$/, '/') }api/${path}`;
    const can = permission => (window.FAMApi?.currentUser?.permissions || []).includes(permission);

    function moveToTopLayer(element) {
        if (element && element.parentElement !== document.body) document.body.appendChild(element);
        return element;
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    function fmtDate(value) {
        if (!value) return 'Not set';
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function fmt(value) {
        if (!value) return 'Not applicable';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function badge(value, kind = 'status') {
        const raw = String(value || 'NONE');
        const cls = raw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-${kind}-${cls}">${esc(title(raw))}</span>`;
    }

    function params() {
        const p = new URLSearchParams({ page: state.page, per_page: 10, sort: state.sort, direction: state.direction });
        const search = qs('#retention-search')?.value.trim();
        if (search) p.set('search', search);
        const map = {
            '#retention-schedule-filter': 'schedule_id',
            '#retention-status-filter': 'status',
            '#retention-due-filter': 'due_state',
            '#retention-hold-filter': 'legal_hold_status',
        };
        Object.entries(map).forEach(([selector, key]) => {
            const value = qs(selector)?.value;
            if (value && value !== 'all') p.set(key, value);
        });
        return p;
    }

    async function load() {
        qs('#retention-loading-state')?.classList.remove('hidden');
        const payload = await window.FAMApi.request(api(`retention/index.php?${params()}`));
        const data = payload.data || {};
        renderSummary(data.summary || {});
        renderRows(data.items || []);
        renderPagination(data.pagination || {});
        qs('#retention-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        qs('#retention-loading-state')?.classList.add('hidden');
    }

    function renderSummary(summary) {
        qs('#retention-active-count').textContent = summary.active ?? 0;
        qs('#retention-due-count').textContent = summary.due ?? 0;
        qs('#retention-hold-count').textContent = summary.hold ?? 0;
        qs('#retention-archived-count').textContent = summary.archived ?? 0;
        qs('#retention-disposed-count').textContent = summary.disposed ?? 0;
    }

    function renderRows(items) {
        const body = qs('#retention-table');
        const empty = qs('#retention-empty-state');
        qs('#retention-table-count').textContent = `Showing ${items.length} retention ${items.length === 1 ? 'record' : 'records'}`;
        if (!items.length) {
            body.innerHTML = '';
            empty.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">fact_check</span><strong>No retention records found.</strong><p>Records linked from Document Management will appear here for lifecycle review.</p>';
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        body.innerHTML = items.map(row => `<tr>
            <td><button class="document-primary-cell" type="button" data-retention-action="view" data-retention-id="${row.id}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">inventory</span><span><strong>${esc(row.title)}</strong><small>${esc(row.recordNo)} &middot; ${esc(title(row.confidentiality || 'Internal'))}</small></span></button></td>
            <td>${esc(row.category)}</td>
            <td>${esc(row.schedule?.name || 'Unassigned')}</td>
            <td>${esc(fmtDate(row.retentionStartDate))}</td>
            <td>${esc(fmtDate(row.scheduledDispositionDate))}</td>
            <td>${row.legalHoldStatus === 'ACTIVE' ? badge('Legal Hold', 'status') : '<span class="retention-muted">None</span>'}</td>
            <td>${badge(row.retentionStatus || row.recordStatus, 'status')}</td>
            <td>${actions(row)}</td>
        </tr>`).join('');
    }

    function actions(row) {
        const items = [`<button type="button" data-retention-action="view" data-retention-id="${row.id}">View Details</button>`];
        if (can('retention.assign')) items.push(`<button type="button" data-retention-action="assign" data-retention-id="${row.id}">Assign Schedule</button>`);
        if (can('retention.extend') && row.recordStatus !== 'DISPOSED') items.push(`<button type="button" data-retention-action="extend" data-retention-id="${row.id}">Extend Review Date</button>`);
        if (can('retention.legal_hold')) items.push(row.legalHoldStatus === 'ACTIVE'
            ? `<button type="button" data-retention-action="release-hold" data-retention-id="${row.id}">Release Legal Hold</button>`
            : `<button type="button" data-retention-action="place-hold" data-retention-id="${row.id}">Place Legal Hold</button>`);
        if (can('retention.archive') && !['ARCHIVED', 'DISPOSED'].includes(row.recordStatus)) items.push('<hr aria-hidden="true">', `<button type="button" data-retention-action="archive" data-retention-id="${row.id}">Archive Record</button>`);
        if (can('retention.dispose') && row.legalHoldStatus !== 'ACTIVE' && !['DISPOSED'].includes(row.recordStatus) && row.dueState !== 'PERMANENT') items.push(`<button class="document-danger-action" type="button" data-retention-action="dispose" data-retention-id="${row.id}">Dispose Record</button>`);
        const menuId = `retention-menu-${row.id}`;
        return `<button class="facility-action-toggle" type="button" aria-label="Open retention actions" aria-expanded="false" data-retention-menu-toggle="${menuId}"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button><div id="${menuId}" class="facility-action-dropdown retention-action-dropdown hidden" role="menu">${items.join('')}</div>`;
    }

    function renderPagination(pagination) {
        state.totalPages = Number(pagination.total_pages || 1);
        qs('#retention-page-status').textContent = `Page ${pagination.page || state.page} of ${state.totalPages}`;
        qs('#retention-prev-page').disabled = state.page <= 1;
        qs('#retention-next-page').disabled = state.page >= state.totalPages;
    }

    async function loadOptions() {
        const payload = await window.FAMApi.request(api('retention/options.php'));
        state.options = payload.data || {};
        fill(qs('#retention-schedule-filter'), state.options.schedules || [], 'All Schedules');
        fillSimple(qs('#retention-status-filter'), state.options.statuses || [], 'All Statuses');
        fillSimple(qs('#retention-due-filter'), state.options.due_states || [], 'All Review States');
        fillSimple(qs('#retention-hold-filter'), state.options.hold_states || [], 'All Holds');
    }

    function fill(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item.id)}">${esc(item.name)}</option>`).join('');
    }

    function fillSimple(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item)}">${esc(title(item))}</option>`).join('');
    }

    async function openDetails(id) {
        const payload = await window.FAMApi.request(api(`retention/show.php?id=${id}`));
        const item = payload.data?.item;
        state.activeItem = item;
        const modal = moveToTopLayer(qs('#retention-details-modal'));
        modal.hidden = false;
        modal.innerHTML = `<div class="facility-details-modal-panel visitor-details-panel retention-details-panel">
            <div class="facility-details-modal-header visitor-details-header">
                <div><p>Record Reference Number</p><span class="facility-details-modal-request-number">${esc(item.recordNo)}</span><h2>${esc(item.title)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-retention-details-close aria-label="Close details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body">
                <div class="visitor-detail-accordion retention-detail-accordion">
                    <details class="visitor-detail-disclosure" open><summary>Record Information</summary>${detail('Record Number', item.recordNo)}${detail('Title', item.title)}${detail('Category', item.category)}${detail('Source Module', title(item.sourceModule))}${detail('Department', item.department)}${detail('Owner', item.owner)}${detail('Confidentiality', title(item.confidentiality))}${item.description ? detail('Description', item.description) : ''}</details>
                    <details class="visitor-detail-disclosure" open><summary>Retention Schedule</summary>${detail('Schedule', item.schedule?.name)}${detail('Schedule Code', item.schedule?.code)}${detail('Trigger', title(item.schedule?.trigger))}${detail('Retention Period', period(item.schedule))}${detail('Disposition Action', title(item.schedule?.dispositionAction))}${detail('Legal Basis', item.schedule?.legalBasis)}</details>
                    <details class="visitor-detail-disclosure" open><summary>Compliance Status</summary>${detail('Record Status', title(item.recordStatus))}${detail('Review State', title(item.dueState))}${detail('Retention Start', fmtDate(item.retentionStartDate))}${detail('Scheduled Review Date', fmtDate(item.scheduledDispositionDate))}${detail('Legal Hold', title(item.legalHoldStatus))}${item.legalHoldReason ? detail('Hold Reason', item.legalHoldReason) : ''}${item.dispositionReason ? detail('Disposition Reason', item.dispositionReason) : ''}</details>
                    ${(item.documents || []).length ? `<details class="visitor-detail-disclosure" open><summary>Related Documents</summary><div class="retention-related-list">${item.documents.map(documentRow).join('')}</div></details>` : ''}
                    ${(item.history || []).length ? `<details class="visitor-detail-disclosure"><summary>Activity History</summary><ol class="facility-history-list">${item.history.map(historyRow).join('')}</ol></details>` : ''}
                </div>
            </div>
            <div class="facility-dialog-actions"><div></div><button class="btn-secondary dashboard-action-button" type="button" data-retention-details-close>Done</button></div>
        </div>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('[data-retention-details-close]')?.focus();
    }

    function detail(label, value) {
        return value ? `<dl class="facility-detail-row"><dt>${esc(label)}</dt><dd>${esc(value)}</dd></dl>` : '';
    }

    function period(schedule = {}) {
        if (!schedule.periodUnit) return 'Not set';
        if (String(schedule.periodUnit).toUpperCase() === 'PERMANENT') return 'Permanent';
        return `${schedule.periodValue || 0} ${title(schedule.periodUnit)}`;
    }

    function documentRow(item) {
        return `<article class="retention-related-row"><div><strong>${esc(item.title)}</strong><small>${esc(item.documentNo)} &middot; ${esc(item.version)} &middot; ${esc(title(item.confidentiality))}</small></div>${badge(item.status, 'status')}</article>`;
    }

    function historyRow(item) {
        return `<li><strong>${esc(item.title)}</strong><span>${esc(fmt(item.createdAt))}${item.actor ? ` by ${esc(item.actor)}` : ''}</span>${item.description ? `<p>${esc(item.description)}</p>` : ''}</li>`;
    }

    function closeDetails() {
        const modal = qs('#retention-details-modal');
        modal.hidden = true;
        modal.innerHTML = '';
        state.activeItem = null;
        if (qs('#retention-dialog')?.hidden !== false) document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
    }

    function openAssign(item) {
        const modal = moveToTopLayer(qs('#retention-dialog'));
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel retention-form" data-retention-form="assign">
            <div class="facility-details-modal-header"><div><p>Records Retention</p><h2>Assign Retention Schedule</h2></div><button class="facility-details-modal-close" type="button" data-retention-dialog-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body retention-form-body"><div class="document-form-error hidden" data-retention-error></div><section class="document-form-section"><h3>${esc(item.title)}</h3><div class="document-form-grid">
                <label class="facility-field"><span>Schedule *</span><select name="retention_schedule_id" required>${(state.options.schedules || []).filter(s => s.status === 'ACTIVE').map(s => `<option value="${esc(s.id)}" ${s.id === item.schedule?.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select></label>
                <label class="facility-field"><span>Retention Start *</span><input name="retention_start_date" type="date" value="${esc(item.retentionStartDate || item.recordDate || '')}" required></label>
            </div></section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-retention-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Assign Schedule</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function openScheduleForm() {
        const modal = moveToTopLayer(qs('#retention-dialog'));
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel retention-form" data-retention-form="schedule">
            <div class="facility-details-modal-header"><div><p>Records Retention</p><h2>Add Retention Schedule</h2></div><button class="facility-details-modal-close" type="button" data-retention-dialog-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body retention-form-body"><div class="document-form-error hidden" data-retention-error></div><section class="document-form-section"><h3>Schedule Information</h3><div class="document-form-grid">
                <label class="facility-field"><span>Schedule Code *</span><input name="schedule_code" maxlength="50" required></label>
                <label class="facility-field"><span>Schedule Name *</span><input name="schedule_name" maxlength="150" required></label>
                <label class="facility-field"><span>Record Category *</span><input name="record_category" maxlength="100" required></label>
                <label class="facility-field"><span>Retention Trigger *</span><select name="retention_trigger"><option value="CREATION_DATE">Creation Date</option><option value="CLOSURE_DATE">Closure Date</option><option value="EVENT_DATE">Event Date</option></select></label>
                <label class="facility-field"><span>Period Value *</span><input name="retention_period_value" type="number" min="0" value="5" required></label>
                <label class="facility-field"><span>Period Unit *</span><select name="retention_period_unit"><option value="YEARS">Years</option><option value="MONTHS">Months</option><option value="DAYS">Days</option><option value="PERMANENT">Permanent</option></select></label>
                <label class="facility-field"><span>Disposition Action *</span><select name="disposition_action"><option value="ARCHIVE">Archive</option><option value="DISPOSE">Dispose</option><option value="REVIEW">Review</option><option value="PERMANENT">Permanent</option></select></label>
                <label class="facility-field"><span>Status</span><select name="status"><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></select></label>
                <label class="facility-field"><span>Effective Date</span><input name="effective_date" type="date"></label>
            </div><label class="facility-field document-full-field"><span>Legal Basis</span><textarea name="legal_basis" rows="2"></textarea></label><label class="facility-field document-full-field"><span>Description</span><textarea name="description" rows="3"></textarea></label></section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-retention-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Save Schedule</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function closeDialog() {
        const modal = qs('#retention-dialog');
        modal.hidden = true;
        modal.innerHTML = '';
        if (qs('#retention-details-modal')?.hidden !== false) document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
    }

    async function postForm(path, form) {
        const error = form.querySelector('[data-retention-error]');
        error?.classList.add('hidden');
        const response = await fetch(api(path), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body: new FormData(form) });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) {
            error.textContent = Object.values(payload?.data?.errors || {})[0] || payload?.message || 'Unable to complete this action.';
            error.classList.remove('hidden');
            return false;
        }
        return true;
    }

    function csrfHeaders() {
        const headers = new Headers({ Accept: 'application/json' });
        if (window.FAMApi.csrfToken) headers.set('X-CSRF-Token', window.FAMApi.csrfToken);
        return headers;
    }

    async function reasonAction(id, action) {
        const config = {
            extend: ['Extend Retention', 'Enter the new scheduled review date.', 'extend.php', 'Extend Retention'],
            archive: ['Archive Record', 'Enter the archive reason.', 'archive.php', 'Archive Record'],
            dispose: ['Dispose Record', 'Enter the disposition reason. Files will not be physically deleted.', 'dispose.php', 'Dispose Record'],
            'place-hold': ['Place Legal Hold', 'Enter the legal hold reason.', 'place-hold.php', 'Place Hold'],
            'release-hold': ['Release Legal Hold', 'Enter the release reason.', 'release-hold.php', 'Release Hold'],
        }[action];
        if (!config) return;
        let formData = new FormData();
        if (action === 'extend') {
            const date = await window.FAMModal.prompt(config[1], '', { title: config[0], inputLabel: 'New review date (YYYY-MM-DD)', confirmLabel: 'Next' });
            if (!date) return;
            const reason = await window.FAMModal.prompt('Enter the reason for this extension.', '', { title: config[0], inputLabel: 'Reason', confirmLabel: config[3] });
            if (!reason) return;
            formData.append('scheduled_disposition_date', date);
            formData.append('reason', reason);
        } else {
            const reason = await window.FAMModal.prompt(config[1], '', { title: config[0], inputLabel: 'Reason', confirmLabel: config[3] });
            if (!reason) return;
            formData.append('reason', reason);
        }
        const response = await fetch(api(`retention/${config[2]}?id=${id}`), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body: formData });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) {
            throw new Error(Object.values(payload?.data?.errors || {})[0] || payload?.message || 'Unable to complete this action.');
        }
        window.FAMModal?.showToast?.(`${config[0]} completed.`);
        await load();
    }

    function bind() {
        qs('#retention-refresh')?.addEventListener('click', () => load().catch(console.error));
        qs('#retention-schedule-add')?.addEventListener('click', openScheduleForm);
        qs('#retention-prev-page')?.addEventListener('click', () => { if (state.page > 1) { state.page--; load().catch(console.error); } });
        qs('#retention-next-page')?.addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; load().catch(console.error); } });
        document.querySelectorAll('.retention-table [data-sort]').forEach(button => button.addEventListener('click', () => {
            const nextSort = button.dataset.sort;
            state.direction = state.sort === nextSort && state.direction === 'asc' ? 'desc' : 'asc';
            state.sort = nextSort;
            state.page = 1;
            load().catch(console.error);
        }));
        ['#retention-search', '#retention-schedule-filter', '#retention-status-filter', '#retention-due-filter', '#retention-hold-filter'].forEach(selector => {
            qs(selector)?.addEventListener(selector === '#retention-search' ? 'input' : 'change', () => { state.page = 1; load().catch(console.error); });
        });
        document.addEventListener('click', event => {
            const close = event.target.closest('[data-retention-dialog-close]');
            if (close) closeDialog();
            const detailsClose = event.target.closest('[data-retention-details-close]');
            if (detailsClose || event.target === qs('#retention-details-modal')) closeDetails();
            const toggle = event.target.closest('[data-retention-menu-toggle]');
            if (toggle) window.FAMTableMenus?.toggle(toggle, document.getElementById(toggle.dataset.retentionMenuToggle));
            const action = event.target.closest('[data-retention-action]');
            if (action) {
                window.FAMTableMenus?.close();
                const id = Number(action.dataset.retentionId);
                const type = action.dataset.retentionAction;
                if (type === 'view') openDetails(id).catch(console.error);
                if (type === 'assign') openDetails(id).then(() => openAssign(state.activeItem)).catch(console.error);
                if (['extend', 'archive', 'dispose', 'place-hold', 'release-hold'].includes(type)) reasonAction(id, type).catch(console.error);
            }
        });
        document.addEventListener('submit', async event => {
            const form = event.target.closest('[data-retention-form]');
            if (!form) return;
            event.preventDefault();
            const type = form.dataset.retentionForm;
            const path = type === 'schedule' ? 'retention/save-schedule.php' : `retention/assign.php?id=${state.activeItem.id}`;
            if (await postForm(path, form)) {
                closeDialog();
                if (type === 'schedule') await loadOptions();
                window.FAMModal?.showToast?.(type === 'schedule' ? 'Retention schedule saved.' : 'Retention schedule assigned.');
                await load();
            }
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeDialog(); closeDetails(); } });
    }

    async function init() {
        await window.FAMApi.me();
        await loadOptions();
        bind();
        await load();
        const directRecordId = new URLSearchParams(window.location.search).get('record_id');
        if (directRecordId) openDetails(Number(directRecordId)).catch(console.error);
    }

    document.addEventListener('fam:layout-ready', () => init().catch(error => {
        console.error(error);
        qs('#retention-loading-state')?.classList.add('hidden');
        qs('#retention-empty-state').innerHTML = "<strong>We couldn't load retention records.</strong><p>Please refresh the page and try again.</p>";
        qs('#retention-empty-state')?.classList.remove('hidden');
    }));
})();
