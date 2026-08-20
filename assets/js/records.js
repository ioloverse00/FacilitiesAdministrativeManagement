(function () {
    const state = { page: 1, totalPages: 1, sort: 'updated_at', direction: 'desc', options: {}, activeItem: null };
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

    function fmt(value) {
        if (!value) return 'Not applicable';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function size(bytes) {
        const n = Number(bytes || 0);
        if (n < 1024) return `${n} B`;
        if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
        return `${(n / 1048576).toFixed(1)} MB`;
    }

    function badge(value, kind = 'status') {
        const raw = String(value || 'NONE');
        const cls = raw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-${kind}-${cls}">${esc(title(raw))}</span>`;
    }

    function fileIcon(item = {}) {
        const version = item.currentVersion || item;
        const type = String(version.mimeType || version.fileName || item.title || '').toLowerCase();
        if (type.includes('pdf')) return 'picture_as_pdf';
        if (type.includes('word') || /\.(docx?|rtf)$/i.test(type)) return 'article';
        if (type.includes('excel') || type.includes('spreadsheet') || /\.(xlsx?|csv)$/i.test(type)) return 'table_chart';
        if (type.includes('image') || /\.(png|jpe?g|gif|webp)$/i.test(type)) return 'image';
        return 'description';
    }

    function fileType(version = {}) {
        const name = String(version.fileName || '').toLowerCase();
        const mime = String(version.mimeType || '').toLowerCase();
        if (mime.includes('pdf') || name.endsWith('.pdf')) return 'PDF';
        if (mime.includes('word') || /\.(docx?|rtf)$/i.test(name)) return 'Document';
        if (mime.includes('excel') || mime.includes('spreadsheet') || /\.(xlsx?|csv)$/i.test(name)) return 'Spreadsheet';
        if (mime.includes('image') || /\.(png|jpe?g|gif|webp)$/i.test(name)) return 'Image';
        return version.mimeType || 'File';
    }

    function params() {
        const p = new URLSearchParams({ page: state.page, per_page: 10, sort: state.sort, direction: state.direction });
        const search = qs('#records-search')?.value.trim();
        if (search) p.set('search', search);
        const category = qs('#document-category-filter')?.value;
        const confidentiality = qs('#document-confidentiality-filter')?.value;
        const status = qs('#document-status-filter')?.value;
        if (category && category !== 'all') p.set('document_category_id', category);
        if (confidentiality && confidentiality !== 'all') p.set('confidentiality_level', confidentiality);
        if (status && status !== 'all') p.set('document_status', status);
        return p;
    }

    async function load() {
        qs('#records-loading-state')?.classList.remove('hidden');
        const payload = await window.FAMApi.request(api(`documents/index.php?${params()}`));
        const data = payload.data || {};
        renderSummary(data.summary || {});
        renderRows(data.items || []);
        renderPagination(data.pagination || {});
        qs('#records-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        qs('#records-loading-state')?.classList.add('hidden');
    }

    function renderSummary(summary) {
        qs('#document-active-count').textContent = summary.active ?? 0;
        qs('#document-archived-count').textContent = summary.archived ?? 0;
        qs('#document-recent-count').textContent = summary.recent ?? 0;
    }

    function renderRows(items) {
        const body = qs('#records-table');
        const empty = qs('#records-empty-state');
        qs('#records-table-count').textContent = `Showing ${items.length} document ${items.length === 1 ? 'record' : 'records'}`;
        const hasFilters = Boolean(qs('#records-search')?.value.trim())
            || ['#document-category-filter', '#document-confidentiality-filter', '#document-status-filter'].some(selector => {
                const el = qs(selector);
                return el && el.value !== 'all';
            });
        if (!items.length) {
            body.innerHTML = '';
            empty.innerHTML = hasFilters
                ? '<span class="material-symbols-outlined" aria-hidden="true">search_off</span><strong>No matching documents</strong><p>Try changing your search or filters.</p>'
                : `<span class="material-symbols-outlined" aria-hidden="true">folder_open</span><strong>No documents yet</strong><p>Official FAM documents will appear here.</p>${can('records.create') ? '<button class="btn-primary dashboard-action-button" type="button" data-document-add>Add Document</button>' : ''}`;
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        body.innerHTML = items.map(row => `<tr>
            <td><button class="document-primary-cell" type="button" data-document-action="view" data-document-id="${row.id}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">${fileIcon(row)}</span><span><strong>${esc(row.title)}</strong><small>${esc(row.documentNo)} · ${esc(title(row.confidentiality))}</small>${row.description ? `<em>${esc(row.description)}</em>` : ''}</span></button></td>
            <td>${esc(row.category)}</td>
            <td>${esc(row.relatedTo || 'General Administrative')}</td>
            <td>${esc(row.version || 'v1')}</td>
            <td>${badge(row.status, 'status')}</td>
            <td class="facility-date-cell">${esc(fmt(row.updatedAt))}</td>
            <td>${actions(row)}</td>
        </tr>`).join('');
    }

    function actions(row) {
        const items = [
            `<button type="button" data-document-action="view" data-document-id="${row.id}">View Details</button>`,
            `<a href="${esc(api(`documents/view.php?id=${row.id}`))}" target="_blank" rel="noopener">View File</a>`,
            `<a href="${esc(api(`documents/download.php?id=${row.id}`))}" target="_blank" rel="noopener">Download</a>`
        ];
        if (can('records.edit') || can('records.create')) items.push('<hr aria-hidden="true">', `<button type="button" data-document-action="version" data-document-id="${row.id}">Upload New Version</button>`);
        if (can('records.edit') && row.status !== 'ARCHIVED') items.push('<hr aria-hidden="true">', `<button class="document-danger-action" type="button" data-document-action="archive" data-document-id="${row.id}">Archive Document</button>`);
        const menuId = `document-menu-${row.id}`;
        return `<button class="facility-action-toggle" type="button" aria-label="Open document actions" aria-expanded="false" data-document-menu-toggle="${menuId}"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button><div id="${menuId}" class="facility-action-dropdown document-action-dropdown hidden" role="menu">${items.join('')}</div>`;
    }

    function renderPagination(pagination) {
        state.totalPages = Number(pagination.total_pages || 1);
        qs('#records-page-status').textContent = `Page ${pagination.page || state.page} of ${state.totalPages}`;
        qs('#records-prev-page').disabled = state.page <= 1;
        qs('#records-next-page').disabled = state.page >= state.totalPages;
    }

    async function loadOptions() {
        const payload = await window.FAMApi.request(api('documents/options.php'));
        state.options = payload.data || {};
        fill(qs('#document-category-filter'), state.options.categories || [], 'All Categories');
        fillSimple(qs('#document-confidentiality-filter'), state.options.confidentiality_levels || [], 'All Confidentiality');
        fillSimple(qs('#document-status-filter'), state.options.statuses || [], 'All Statuses');
    }

    function fill(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item.id)}">${esc(item.name)}</option>`).join('');
    }

    function fillSimple(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item)}">${esc(title(item))}</option>`).join('');
    }

    function openForm(item = null) {
        const modal = moveToTopLayer(qs('#document-dialog'));
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel document-form" enctype="multipart/form-data">
            <div class="facility-details-modal-header">
                <div><p>Document Management</p><h2>${item ? 'Upload New Version' : 'Add Document'}</h2></div>
                <button class="facility-details-modal-close" type="button" data-document-close aria-label="Close dialog">&times;</button>
            </div>
            <div class="facility-dialog-body document-form-body">
                <div class="document-form-error hidden" data-document-form-error></div>
                ${item ? `<section class="document-form-section"><h3>Document</h3><p class="document-current-file"><strong>${esc(item.title)}</strong><span>${esc(item.documentNo)} · Current version: ${esc(item.version || 'v1')}</span></p><p class="document-form-note">Uploading this file will create ${esc(`v${Number(item.currentVersionNumber || 1) + 1}`)}. Previous versions will remain available.</p></section>` : metadataFields()}
                <section class="document-form-section">
                    <h3>File</h3>
                    <label class="facility-field document-full-field document-file-field"><span>Primary file *</span><input name="file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" required></label>
                    <label class="facility-field document-full-field"><span>Version notes</span><textarea name="change_summary" rows="3" placeholder="${item ? 'Describe what changed in this version' : 'Initial upload'}"></textarea></label>
                </section>
            </div>
            <div class="facility-dialog-actions">
                <button class="btn-secondary dashboard-action-button" type="button" data-document-close>Cancel</button>
                <button class="btn-primary dashboard-action-button" type="submit">${item ? 'Upload Version' : 'Add Document'}</button>
            </div>
        </form>`;
        populateFormOptions(modal);
        modal.querySelector('[name="title"], [name="file"]')?.focus();
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function metadataFields() {
        return `<section class="document-form-section"><h3>Document Information</h3><div class="document-form-grid">
            <label class="facility-field"><span>Title *</span><input name="title" required maxlength="255"></label>
            <label class="facility-field"><span>Category *</span><select name="document_category_id" required></select></label>
            <label class="facility-field"><span>Document Date</span><input name="document_date" type="date"></label>
        </div>
        <label class="facility-field document-full-field"><span>Description</span><textarea name="description" rows="3"></textarea></label></section>
        <section class="document-form-section"><h3>Classification</h3><div class="document-form-grid">
            <label class="facility-field"><span>Confidentiality *</span><select name="confidentiality_level" required></select></label>
            <label class="facility-field"><span>Status</span><select name="status"></select></label>
        </div></section>
        <section class="document-form-section"><h3>Related Record</h3><div class="document-form-grid">
            <label class="facility-field"><span>Related Module</span><select name="related_module"></select></label>
            <label class="facility-field"><span>Related Reference</span><input name="related_reference" placeholder="RR-2026-0001, VIS-2026-0001, CON-2026-0001"></label>
        </div></section>`;
    }

    function populateFormOptions(modal) {
        const category = modal.querySelector('[name="document_category_id"]');
        if (category) category.innerHTML = '<option value="">Select category</option>' + (state.options.categories || []).map(item => `<option value="${esc(item.id)}">${esc(item.name)}</option>`).join('');
        const confidentiality = modal.querySelector('[name="confidentiality_level"]');
        if (confidentiality) confidentiality.innerHTML = (state.options.confidentiality_levels || []).map(item => `<option value="${esc(item)}">${esc(title(item))}</option>`).join('');
        const status = modal.querySelector('[name="status"]');
        if (status) status.innerHTML = (state.options.statuses || []).map(item => `<option value="${esc(item)}">${esc(title(item))}</option>`).join('');
        const related = modal.querySelector('[name="related_module"]');
        if (related) related.innerHTML = (state.options.related_modules || []).map(item => `<option value="${esc(item.code)}">${esc(item.name)}</option>`).join('');
    }

    function closeForm() {
        const modal = qs('#document-dialog');
        modal.hidden = true;
        modal.innerHTML = '';
        if (qs('#document-details-modal')?.hidden !== false) {
            document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
        }
    }

    async function submitForm(form) {
        const error = form.querySelector('[data-document-form-error]');
        error?.classList.add('hidden');
        const item = state.activeItem;
        const url = item ? api(`documents/upload-version.php?id=${item.id}`) : api('documents/create.php');
        const headers = new Headers({ Accept: 'application/json' });
        if (window.FAMApi.csrfToken) headers.set('X-CSRF-Token', window.FAMApi.csrfToken);
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, body: new FormData(form) });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) {
            const message = payload?.message || "We couldn't upload this document.";
            const errors = payload?.data?.errors || {};
            error.innerHTML = esc(Object.values(errors)[0] || message);
            error.classList.remove('hidden');
            return;
        }
        closeForm();
        window.FAMModal?.showToast?.(item ? 'New version uploaded.' : 'Document uploaded.');
        state.activeItem = null;
        await load();
    }

    async function openDetails(id) {
        const payload = await window.FAMApi.request(api(`documents/show.php?id=${id}`));
        const item = payload.data?.item;
        state.activeItem = item;
        const current = item.currentVersion || {};
        const modal = moveToTopLayer(qs('#document-details-modal'));
        modal.hidden = false;
        const uploadAction = (can('records.edit') || can('records.create')) ? '<button class="btn-primary dashboard-action-button" type="button" data-document-version-from-details><span class="material-symbols-outlined" aria-hidden="true">upgrade</span>Upload New Version</button>' : '';
        const archiveAction = can('records.edit') && item.status !== 'ARCHIVED' ? '<button class="btn-secondary dashboard-action-button document-danger-action-button" type="button" data-document-archive-from-details><span class="material-symbols-outlined" aria-hidden="true">archive</span>Archive Document</button>' : '';
        const documentInfo = detailGrid(`${detail('Document Number', item.documentNo)}${detail('Status', title(item.status))}${detail('Category', item.category)}${detail('Confidentiality', title(item.confidentiality))}${detail('Related To', item.relatedTo)}${detail('Current Version', `v${item.currentVersionNumber || current.versionNumber || 1}`)}${detail('Created By', item.createdBy)}${detail('Created At', fmt(item.createdAt))}${detail('Updated At', fmt(item.updatedAt))}${item.description ? detail('Description', item.description, 'detail-item--full') : ''}`);
        const retentionInfo = item.retention ? detailGrid(`${detail('Record Number', item.retention.recordNo)}${detail('Schedule', item.retention.scheduleName)}${detail('Retention Start', item.retention.retentionStartDate)}${detail('Scheduled Review Date', item.retention.scheduledDispositionDate)}${detail('Record Status', title(item.retention.status))}${detail('Legal Hold', title(item.retention.legalHoldStatus))}`) : '';
        modal.innerHTML = `<div class="facility-details-modal-panel visitor-details-panel document-details-panel">
            <div class="facility-details-modal-header visitor-details-header document-details-header">
                <div><p>Document Number</p><span class="facility-details-modal-request-number">${esc(item.documentNo)}</span><h2>${esc(item.title)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-document-details-close aria-label="Close details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body document-details-body">
                <div class="visitor-detail-accordion document-detail-accordion">
                    <details class="visitor-detail-disclosure" open><summary>Document Information</summary>${documentInfo}</details>
                    <details class="visitor-detail-disclosure" open><summary>Current File</summary><div class="document-file-row"><div><span class="material-symbols-outlined document-file-icon" aria-hidden="true">${fileIcon(current)}</span><div><strong>${esc(current.fileName || 'File unavailable')}</strong><small>${esc(fileType(current))} | ${esc(size(current.fileSize))}</small><small>Version ${esc(String(item.currentVersionNumber || current.versionNumber || 1))}</small></div></div><div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${item.id}`))}" target="_blank" rel="noopener">View File</a><a href="${esc(api(`documents/download.php?id=${item.id}`))}">Download</a></div></div></details>
                    ${item.retention ? `<details class="visitor-detail-disclosure"><summary>Retention</summary>${retentionInfo}<p><a class="facility-text-button" href="../pages/records-retention.html?record_id=${esc(item.retention.recordId)}">Open retention record</a></p></details>` : ''}
                    ${(item.versions || []).length ? `<details class="visitor-detail-disclosure"><summary><span>Version History</span>${uploadAction ? '<button class="facility-text-button document-summary-action" type="button" data-document-version-from-details>Upload New Version</button>' : ''}</summary><div class="document-version-list">${(item.versions || []).map(versionRow).join('')}</div></details>` : ''}
                </div>
            </div>
            <div class="facility-dialog-actions document-details-footer"><div></div><div>${archiveAction}<button class="btn-secondary dashboard-action-button" type="button" data-document-details-close>Done</button></div></div>
        </div>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('[data-document-details-close]')?.focus();
    }

    function detail(label, value, className = '') {
        return value ? `<dl class="facility-detail-row ${esc(className)}"><dt>${esc(label)}</dt><dd>${esc(value)}</dd></dl>` : '';
    }

    function detailGrid(content) {
        return `<div class="detail-grid">${content}</div>`;
    }

    function versionRow(version) {
        return `<article class="document-version-row">
            <div><div class="document-version-title"><strong>${esc(version.version)}</strong>${version.isCurrent ? badge('CURRENT', 'status') : ''}</div><span>${esc(version.fileName)} | ${esc(fileType(version))} | ${esc(size(version.fileSize))}</span><small>${esc(version.uploadedBy)} | ${esc(fmt(version.uploadedAt))}</small>${version.changeSummary ? `<p>${esc(version.changeSummary)}</p>` : ''}</div>
            <div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${state.activeItem.id}&version_id=${version.id}`))}" target="_blank" rel="noopener">View</a><a href="${esc(api(`documents/download.php?id=${state.activeItem.id}&version_id=${version.id}`))}">Download</a></div>
        </article>`;
    }

    function closeDetails() {
        const modal = qs('#document-details-modal');
        modal.hidden = true;
        modal.innerHTML = '';
        state.activeItem = null;
        if (qs('#document-dialog')?.hidden !== false) {
            document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
        }
    }

    async function archiveDocument(id) {
        const ok = await window.FAMModal.confirm('Archive this document? It will be removed from the active repository view, but its files and version history will be preserved.', { title: 'Archive Document', confirmLabel: 'Archive Document' });
        if (!ok) return;
        await window.FAMApi.request(api(`documents/archive.php?id=${id}`), { method: 'POST' });
        window.FAMModal?.showToast?.('Document archived.');
        closeDetails();
        await load();
    }

    function bind() {
        qs('#document-add')?.addEventListener('click', () => { state.activeItem = null; openForm(); });
        qs('#records-refresh')?.addEventListener('click', () => load().catch(console.error));
        qs('#records-export')?.addEventListener('click', () => window.FAMModal?.showToast?.('Document export will be enabled in a later implementation step.'));
        qs('#records-prev-page')?.addEventListener('click', () => { if (state.page > 1) { state.page--; load().catch(console.error); } });
        qs('#records-next-page')?.addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; load().catch(console.error); } });
        document.querySelectorAll('.records-table [data-sort]').forEach(button => button.addEventListener('click', () => {
            const nextSort = button.dataset.sort;
            state.direction = state.sort === nextSort && state.direction === 'asc' ? 'desc' : 'asc';
            state.sort = nextSort;
            state.page = 1;
            load().catch(console.error);
        }));
        ['#records-search', '#document-category-filter', '#document-confidentiality-filter', '#document-status-filter'].forEach(selector => {
            qs(selector)?.addEventListener(selector === '#records-search' ? 'input' : 'change', () => { state.page = 1; load().catch(console.error); });
        });
        qs('#records-reset-filters')?.addEventListener('click', () => {
            ['#records-search', '#document-category-filter', '#document-confidentiality-filter', '#document-status-filter'].forEach(selector => { const el = qs(selector); if (el) el.value = selector === '#records-search' ? '' : 'all'; });
            state.page = 1; load().catch(console.error);
        });
        document.addEventListener('click', event => {
            const add = event.target.closest('[data-document-add]');
            if (add) { state.activeItem = null; openForm(); }
            const close = event.target.closest('[data-document-close]');
            if (close) closeForm();
            const detailsClose = event.target.closest('[data-document-details-close]');
            if (detailsClose || event.target === qs('#document-details-modal')) closeDetails();
            const toggle = event.target.closest('[data-document-menu-toggle]');
            if (toggle) window.FAMTableMenus?.toggle(toggle, document.getElementById(toggle.dataset.documentMenuToggle));
            const action = event.target.closest('[data-document-action]');
            if (action) {
                window.FAMTableMenus?.close();
                const id = Number(action.dataset.documentId);
                if (action.dataset.documentAction === 'view') openDetails(id).catch(console.error);
                if (action.dataset.documentAction === 'version') openDetails(id).then(() => openForm(state.activeItem)).catch(console.error);
                if (action.dataset.documentAction === 'archive') archiveDocument(id).catch(console.error);
            }
            if (event.target.closest('[data-document-version-from-details]')) {
                event.preventDefault();
                event.stopPropagation();
                openForm(state.activeItem);
            }
            if (event.target.closest('[data-document-archive-from-details]')) archiveDocument(state.activeItem.id).catch(console.error);
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('.document-form');
            if (!form) return;
            event.preventDefault();
            submitForm(form).catch(error => {
                const box = form.querySelector('[data-document-form-error]');
                if (box) { box.textContent = error.message || "We couldn't upload this document."; box.classList.remove('hidden'); }
            });
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeForm(); closeDetails(); } });
    }

    async function init() {
        await window.FAMApi.me();
        await loadOptions();
        bind();
        await load();
    }

    document.addEventListener('fam:layout-ready', () => init().catch(error => {
        console.error(error);
        qs('#records-loading-state')?.classList.add('hidden');
        qs('#records-empty-state').innerHTML = "<strong>We couldn't load documents.</strong><p>Please refresh the page and try again.</p>";
        qs('#records-empty-state')?.classList.remove('hidden');
    }));
})();
