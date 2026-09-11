(function () {
    const state = { page: 1, totalPages: 1, sort: 'updated_at', direction: 'desc', options: {}, templateOptions: {}, activeItem: null, activeTemplate: null, view: 'documents', documentLoading: false, documentLoaded: false, templateLoading: false, templateLoaded: false };
    const qs = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const api = path => window.FAMApi?.apiUrl?.(path) || window.FAMNavigation?.apiUrl?.(path) || `/api/${String(path || '').replace(/^api\//, '')}`;
    const can = permission => (window.FAMApi?.currentUser?.permissions || []).includes(permission);

    function moveToTopLayer(element) {
        if (element && element.parentElement !== document.body) document.body.appendChild(element);
        return element;
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    function templateTypeLabel(value) {
        return {
            CLIENT_CONTRACT: 'Client Contract',
            EMPLOYEE_CONTRACT: 'Employee Contract',
            NDA: 'NDA / Confidentiality Agreement',
            CONTRACT_AMENDMENT: 'Contract Amendment',
            OTHER: 'Other'
        }[String(value || '').toUpperCase()] || title(value);
    }

    function confidentialityLabel(value) {
        return {
            PUBLIC: 'Public',
            INTERNAL: 'Internal',
            CONFIDENTIAL: 'Confidential'
        }[String(value || '').toUpperCase()] || title(value);
    }

    function templateStatusLabel(value) {
        return {
            ACTIVE: 'Active',
            RETIRED: 'Retired'
        }[String(value || '').toUpperCase()] || title(value);
    }

    function templateDisplayLabel(value, kind = '') {
        if (kind === 'template_type') return templateTypeLabel(value);
        if (kind === 'confidentiality') return confidentialityLabel(value);
        if (kind === 'template_status') return templateStatusLabel(value);
        return title(value);
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
        if (state.documentLoading) return;
        state.documentLoading = true;
        const initial = !state.documentLoaded;
        qs('#records-table')?.closest('.facility-table-card')?.setAttribute('aria-busy', 'true');
        if (initial) qs('#records-table').innerHTML = tableStateRow(7, 'Loading documents...', 'progress_activity', true);
        try {
            const payload = await window.FAMApi.request(api(`documents/index.php?${params()}`));
            const data = payload.data || {};
            renderSummary(data.summary || {});
            renderRows(data.items || []);
            renderPagination(data.pagination || {});
            qs('#records-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        } catch (error) {
            if (initial) qs('#records-table').innerHTML = tableStateRow(7, 'Unable to load documents. Try again.', 'error');
            qs('#records-table-count').textContent = 'Documents unavailable';
            window.FAMModal?.showToast(error.message || 'Unable to load documents.');
        } finally {
            qs('#records-table')?.closest('.facility-table-card')?.removeAttribute('aria-busy');
            state.documentLoading = false;
            state.documentLoaded = true;
        }
    }

    function tableStateRow(colspan, message, icon, spinning = false) {
        return `<tr class="fam-table-state-row"><td class="fam-table-state-cell" colspan="${colspan}"><div class="fam-state" role="status"><span class="material-symbols-outlined${spinning ? ' fam-spinner' : ''}" aria-hidden="true">${icon}</span><span>${esc(message)}</span></div></td></tr>`;
    }

    function renderSummary(summary) {
        qs('#document-active-count').textContent = summary.active ?? 0;
        qs('#document-archived-count').textContent = summary.archived ?? 0;
        qs('#document-recent-count').textContent = summary.recent ?? 0;
    }

    function renderRows(items) {
        const body = qs('#records-table');
        qs('#records-table-count').textContent = `Showing ${items.length} document ${items.length === 1 ? 'record' : 'records'}`;
        const hasFilters = Boolean(qs('#records-search')?.value.trim())
            || ['#document-category-filter', '#document-confidentiality-filter', '#document-status-filter'].some(selector => {
                const el = qs(selector);
                return el && el.value !== 'all';
            });
        if (!items.length) {
            body.innerHTML = tableStateRow(7, hasFilters ? 'No documents match the current search or filters.' : 'No documents yet.', hasFilters ? 'search_off' : 'folder_open');
            return;
        }
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
        const guarded = String(row.confidentiality || '').toUpperCase() === 'CONFIDENTIAL';
        const items = [
            `<button type="button" data-document-action="view" data-document-id="${row.id}">View Details</button>`,
            `<a href="${esc(api(`documents/view.php?id=${row.id}`))}" target="_blank" rel="noopener" ${guarded ? `data-document-file-action="view" data-document-id="${row.id}"` : ''}>View File</a>`
        ];
        if ((can('records.edit') || can('records.create')) && row.status !== 'ARCHIVED') items.push('<hr aria-hidden="true">', `<button type="button" data-document-action="version" data-document-id="${row.id}">Upload New Version</button>`);
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
        const uploadAction = (can('records.edit') || can('records.create')) && item.status !== 'ARCHIVED' ? '<button class="btn-primary dashboard-action-button" type="button" data-document-version-from-details><span class="material-symbols-outlined" aria-hidden="true">upgrade</span>Upload New Version</button>' : '';
        const archiveAction = can('records.edit') && item.status !== 'ARCHIVED' ? '<button class="btn-secondary dashboard-action-button document-danger-action-button" type="button" data-document-archive-from-details><span class="material-symbols-outlined" aria-hidden="true">archive</span>Archive Document</button>' : '';
        const documentInfo = detailGrid(`${detail('Document Number', item.documentNo)}${detail('Status', title(item.status))}${detail('Category', item.category)}${detail('Confidentiality', title(item.confidentiality))}${detail('Related To', item.relatedTo)}${detail('Current Version', `v${item.currentVersionNumber || current.versionNumber || 1}`)}${detail('Created By', item.createdBy)}${detail('Created At', fmt(item.createdAt))}${detail('Updated At', fmt(item.updatedAt))}${item.description ? detail('Description', item.description, 'detail-item--full') : ''}`);
        const retentionInfo = item.retention ? detailGrid(`${detail('Record Number', item.retention.recordNo)}${detail('Schedule', item.retention.scheduleName)}${detail('Retention Start', item.retention.retentionStartDate)}${detail('Scheduled Review Date', item.retention.scheduledDispositionDate)}${detail('Record Status', title(item.retention.status))}${detail('Legal Hold', title(item.retention.legalHoldStatus))}`) : '';
        const guarded = String(item.confidentiality || '').toUpperCase() === 'CONFIDENTIAL';
        modal.innerHTML = `<div class="facility-details-modal-panel visitor-details-panel document-details-panel">
            <div class="facility-details-modal-header visitor-details-header document-details-header">
                <div><p>Document Number</p><span class="facility-details-modal-request-number">${esc(item.documentNo)}</span><h2>${esc(item.title)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-document-details-close aria-label="Close details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body document-details-body">
                <div class="visitor-detail-accordion document-detail-accordion">
                    <details class="visitor-detail-disclosure" open><summary>Document Information</summary>${documentInfo}</details>
                    <details class="visitor-detail-disclosure" open><summary>Current File</summary><div class="document-file-row"><div><span class="material-symbols-outlined document-file-icon" aria-hidden="true">${fileIcon(current)}</span><div><strong>${esc(current.fileName || 'File unavailable')}</strong><small>${esc(fileType(current))} | ${esc(size(current.fileSize))}</small><small>Version ${esc(String(item.currentVersionNumber || current.versionNumber || 1))}</small></div></div><div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${item.id}`))}" target="_blank" rel="noopener" ${guarded ? `data-document-file-action="view" data-document-id="${item.id}"` : ''}>View File</a></div></div></details>
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
        const guarded = String(state.activeItem?.confidentiality || '').toUpperCase() === 'CONFIDENTIAL';
        return `<article class="document-version-row">
            <div><div class="document-version-title"><strong>${esc(version.version)}</strong>${version.isCurrent ? badge('CURRENT', 'status') : ''}</div><span>${esc(version.fileName)} | ${esc(fileType(version))} | ${esc(size(version.fileSize))}</span><small>${esc(version.uploadedBy)} | ${esc(fmt(version.uploadedAt))}</small>${version.changeSummary ? `<p>${esc(version.changeSummary)}</p>` : ''}</div>
            <div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${state.activeItem.id}&version_id=${version.id}`))}" target="_blank" rel="noopener" ${guarded ? `data-document-file-action="view" data-document-id="${state.activeItem.id}" data-document-version-id="${version.id}"` : ''}>View File</a></div>
        </article>`;
    }

    async function guardedFileAction(link) {
        try {
            await window.FAMApi.openDocumentWithStepUp({
                documentId: Number(link.dataset.documentId),
                versionId: link.dataset.documentVersionId || '',
                action: link.dataset.documentFileAction,
                url: link.href,
                target: link.target || '_self'
            });
        } catch (error) {
            window.FAMModal?.showToast?.(error.message || 'Document access was denied.');
        }
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

    function templateParams() {
        const p = new URLSearchParams();
        const search = qs('#templates-search')?.value.trim();
        const type = qs('#template-type-filter')?.value;
        const status = qs('#template-status-filter')?.value;
        if (search) p.set('search', search);
        if (type && type !== 'all') p.set('template_type', type);
        if (status && status !== 'all') p.set('status', status);
        return p;
    }

    function exportUrl() {
        const p = new URLSearchParams({ report: 'documents_records', source: 'documents' });
        const search = qs('#records-search')?.value.trim();
        const category = qs('#document-category-filter')?.value;
        const confidentiality = qs('#document-confidentiality-filter')?.value;
        const status = qs('#document-status-filter')?.value;
        if (search) p.set('search', search);
        if (category && category !== 'all') p.set('category_id', category);
        if (confidentiality && confidentiality !== 'all') p.set('confidentiality', confidentiality);
        if (status && status !== 'all') p.set('status', status);
        return api(`reports/export-csv.php?${p}`);
    }

    async function loadTemplates() {
        if (!can('document_templates.view')) return;
        if (state.templateLoading) return;
        state.templateLoading = true;
        const initial = !state.templateLoaded;
        qs('#templates-table')?.closest('.facility-table-card')?.setAttribute('aria-busy', 'true');
        if (initial) qs('#templates-table').innerHTML = tableStateRow(8, 'Loading templates...', 'progress_activity', true);
        try {
            const payload = await window.FAMApi.request(api(`document-templates/index.php?${templateParams()}`));
            state.templateOptions = payload.data?.options || state.templateOptions || {};
            fillTemplateSelect(qs('#template-type-filter'), state.templateOptions.template_types || [], 'all', 'template_type', 'All Types');
            fillTemplateSelect(qs('#template-status-filter'), state.templateOptions.statuses || [], 'all', 'template_status', 'All Statuses');
            renderTemplateSummary(payload.data?.items || []);
            renderTemplates(payload.data?.items || []);
        } catch (error) {
            const body = qs('#templates-table');
            if (body && !body.querySelector('tr:not(.fam-table-state-row)')) body.innerHTML = tableStateRow(8, 'Unable to load templates. Try again.', 'error');
            qs('#templates-table-count').textContent = 'Templates unavailable';
            window.FAMModal?.showToast(error.message || 'Unable to load templates.');
        } finally {
            qs('#templates-table')?.closest('.facility-table-card')?.removeAttribute('aria-busy');
            state.templateLoading = false;
            state.templateLoaded = true;
        }
    }

    async function ensureTemplateOptions() {
        if ((state.templateOptions.template_types || []).length && (state.templateOptions.confidentiality_levels || []).length) return;
        const payload = await window.FAMApi.request(api('document-templates/index.php'));
        state.templateOptions = payload.data?.options || state.templateOptions || {};
    }

    function renderTemplateSummary(items) {
        const active = items.filter(item => String(item.status || '').toUpperCase() === 'ACTIVE').length;
        const retired = items.filter(item => String(item.status || '').toUpperCase() === 'RETIRED').length;
        const recentCutoff = Date.now() - (14 * 24 * 60 * 60 * 1000);
        const recent = items.filter(item => {
            const time = new Date(String(item.updatedAt || '').replace(' ', 'T')).getTime();
            return !Number.isNaN(time) && time >= recentCutoff;
        }).length;
        qs('#template-active-count').textContent = active;
        qs('#template-retired-count').textContent = retired;
        qs('#template-recent-count').textContent = recent;
    }

    function renderTemplates(items) {
        const body = qs('#templates-table');
        qs('#templates-table-count').textContent = `Showing ${items.length} template ${items.length === 1 ? 'record' : 'records'}`;
        if (!items.length) {
            const filtered = Boolean(qs('#templates-search')?.value.trim())
                || ['#template-type-filter', '#template-status-filter'].some(selector => {
                    const value = qs(selector)?.value;
                    return value && value !== 'all';
                });
            body.innerHTML = tableStateRow(8, filtered ? 'No templates match the current filters.' : 'No templates found.', filtered ? 'search_off' : 'contract_edit');
            return;
        }
        body.innerHTML = items.map(item => `<tr>
            <td><button class="document-primary-cell" type="button" data-template-action="template-details" data-template-id="${esc(item.id)}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">contract_edit</span><span><strong>${esc(item.templateName)}</strong><small>${esc(item.templateCode)}</small>${item.description ? `<em>${esc(item.description)}</em>` : ''}</span></button></td>
            <td>${esc(templateTypeLabel(item.templateType))}</td>
            <td>${esc(item.currentVersion || 'No active version')}</td>
            <td>${esc(confidentialityLabel(item.confidentiality || 'INTERNAL'))}</td>
            <td>${badge(item.status, 'status')}</td>
            <td>${esc(item.effectiveFrom || 'Not set')}</td>
            <td class="facility-date-cell">${esc(fmt(item.updatedAt))}</td>
            <td>${templateActions(item)}</td>
        </tr>`).join('');
    }

    function templateActions(item) {
        const actions = [`<button type="button" role="menuitem" data-template-action="template-details" data-template-id="${esc(item.id)}">View Details</button>`];
        const status = String(item.status || '').toUpperCase();
        if (status === 'ACTIVE' && can('document_templates.edit')) actions.push(`<button type="button" role="menuitem" data-template-action="template-version" data-template-id="${esc(item.id)}">Create New Version</button>`);
        if (status === 'ACTIVE' && can('document_templates.retire')) actions.push(`<button type="button" role="menuitem" data-template-action="template-retire" data-template-id="${esc(item.id)}">Retire</button>`);
        const menuId = `template-menu-${item.id}`;
        return `<button class="facility-action-toggle" type="button" aria-label="Open template actions" aria-expanded="false" data-template-menu-toggle="${menuId}"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button><div id="${menuId}" class="facility-action-dropdown document-action-dropdown hidden" role="menu">${actions.join('')}</div>`;
    }

    async function openTemplateForm(template = null) {
        await ensureTemplateOptions();
        const modal = moveToTopLayer(qs('#document-dialog'));
        const isVersion = Boolean(template);
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel document-form" enctype="multipart/form-data" data-template-form="${isVersion ? esc(template.id) : ''}">
            <div class="facility-details-modal-header"><div><p>Template Library</p><h2>${isVersion ? 'Create New Version' : 'Create Template'}</h2></div><button class="facility-details-modal-close" type="button" data-document-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body document-form-body">
                <div class="document-form-error hidden" data-template-form-error></div>
                ${isVersion ? `<section class="document-form-section"><h3>${esc(template.templateName)}</h3><p class="document-form-note">Upload the revised master file. The previous active version remains available in history.</p></section>` : `<section class="document-form-section"><h3>Template Information</h3><div class="document-form-grid">
                    <label class="facility-field"><span>Template Name *</span><input name="template_name" required maxlength="255"></label>
                    <label class="facility-field"><span>Template Type *</span><select name="template_type" required></select></label>
                </div><label class="facility-field document-full-field"><span>Description</span><textarea name="description" rows="3"></textarea></label></section>`}
                <section class="document-form-section"><h3>Document Settings</h3><div class="document-form-grid">${isVersion ? '' : '<label class="facility-field"><span>Confidentiality *</span><select name="confidentiality_level" required></select></label>'}<label class="facility-field"><span>Effective Date</span><input name="effective_from" type="date"></label></div></section>
                <section class="document-form-section"><h3>Template File</h3><label class="facility-field document-full-field document-file-field"><span>Upload Template File *</span><input name="file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" required data-template-file-input></label><p class="document-form-note" data-template-file-note>No file selected.</p></section>
                <section class="document-form-section"><h3>Version Information</h3><label class="facility-field document-full-field"><span>Change Summary / Notes${isVersion ? ' *' : ''}</span><textarea name="change_summary" rows="3" ${isVersion ? 'required' : ''} placeholder="${isVersion ? 'Summarize what changed in this version' : 'Initial template version'}"></textarea></label></section>
            </div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-document-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Save Template</button></div>
        </form>`;
        fillTemplateSelect(modal.querySelector('[name="template_type"]'), state.templateOptions.template_types || [], 'OTHER', 'template_type');
        fillTemplateSelect(modal.querySelector('[name="confidentiality_level"]'), state.templateOptions.confidentiality_levels || [], 'INTERNAL', 'confidentiality');
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function fillTemplateSelect(select, items, selected, kind = '', firstLabel = '') {
        if (!select) return;
        const firstOption = firstLabel ? `<option value="all">${esc(firstLabel)}</option>` : '';
        select.innerHTML = firstOption + (items || []).map(item => `<option value="${esc(item)}" ${String(item) === String(selected) ? 'selected' : ''}>${esc(templateDisplayLabel(item, kind))}</option>`).join('');
        if (selected) select.value = selected;
    }

    async function submitTemplateForm(form) {
        const templateId = form.dataset.templateForm;
        const url = templateId ? api(`document-templates/create-version.php?id=${templateId}`) : api('document-templates/create.php');
        const error = form.querySelector('[data-template-form-error]');
        error?.classList.add('hidden');
        const headers = new Headers({ Accept: 'application/json' });
        if (window.FAMApi.csrfToken) headers.set('X-CSRF-Token', window.FAMApi.csrfToken);
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, body: new FormData(form) });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) {
            const errors = payload?.data?.errors || {};
            error.textContent = Object.values(errors)[0] || payload?.message || 'Template could not be saved.';
            error.classList.remove('hidden');
            return;
        }
        closeForm();
        window.FAMModal?.showToast?.(templateId ? 'Template version created.' : 'Template created.');
        await loadTemplates();
    }

    async function validateTemplateContent(form) {
        const box = form.querySelector('[data-template-validation]');
        const payload = await window.FAMApi.request(api('document-templates/validate.php'), { method: 'POST', body: { template_content: form.querySelector('[name="template_content"]')?.value || '' } });
        const result = payload.data?.validation || {};
        const issues = [...(result.unknown || []), ...(result.malformed || []), ...(result.unsupportedNamespaces || [])];
        box.textContent = result.ok ? `Valid placeholders: ${(result.valid || []).join(', ') || 'none'}` : `Invalid placeholders: ${issues.join(', ')}`;
    }

    async function openTemplateDetails(id) {
        const modal = moveToTopLayer(qs('#document-details-modal'));
        modal.hidden = false;
        modal.innerHTML = `<div class="facility-details-modal-panel visitor-details-panel document-details-panel">
            <div class="facility-details-modal-header visitor-details-header document-details-header"><div><p>Template Library</p><h2>Loading template details</h2></div><button class="facility-details-modal-close" type="button" data-document-details-close aria-label="Close details">&times;</button></div>
            <div class="facility-details-modal-body visitor-details-body document-details-body"><div class="fam-state"><span class="material-symbols-outlined fam-spinner" aria-hidden="true">progress_activity</span><span>Loading template details...</span></div></div>
        </div>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        try {
            const payload = await window.FAMApi.request(api(`document-templates/show.php?id=${id}`));
            const item = payload.data?.item;
            if (!item || !item.id) throw new Error('Template details response did not include an item.');
            renderTemplateDetails(modal, item);
        } catch (error) {
            console.error('Unable to load template details.', error);
            modal.innerHTML = `<div class="facility-details-modal-panel visitor-details-panel document-details-panel">
                <div class="facility-details-modal-header visitor-details-header document-details-header"><div><p>Template Library</p><h2>Template Details</h2></div><button class="facility-details-modal-close" type="button" data-document-details-close aria-label="Close details">&times;</button></div>
                <div class="facility-details-modal-body visitor-details-body document-details-body"><div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">error</span><span>Unable to load template details.</span></div></div>
            </div>`;
        }
    }

    function renderTemplateDetails(modal, item) {
        state.activeTemplate = item;
        const active = (item.versions || []).find(v => v.status === 'ACTIVE' && v.id === item.currentApprovedVersionId) || (item.versions || []).find(v => v.status === 'ACTIVE');
        const displayVersion = active || (item.versions || [])[0] || {};
        const guarded = String(displayVersion.confidentiality || item.confidentiality || '').toUpperCase() === 'CONFIDENTIAL';
        const controls = [
            active && item.status === 'ACTIVE' && can('document_templates.retire') ? `<button class="btn-secondary dashboard-action-button" type="button" data-template-version-action="retire" data-version-id="${active.id}">Retire</button>` : '',
            item.status !== 'RETIRED' && can('document_templates.edit') ? `<button class="btn-secondary dashboard-action-button" type="button" data-template-new-version>Create New Version</button>` : ''
        ].filter(Boolean).join('');
        modal.innerHTML = `<div class="facility-details-modal-panel visitor-details-panel document-details-panel">
            <div class="facility-details-modal-header visitor-details-header document-details-header"><div><p>Template Library</p><span class="facility-details-modal-request-number">${esc(item.templateCode || 'Template')}</span><h2>${esc(item.templateName || 'Template Details')}</h2>${badge(templateStatusLabel(item.status), 'status')}</div><button class="facility-details-modal-close" type="button" data-document-details-close aria-label="Close details">&times;</button></div>
            <div class="facility-details-modal-body visitor-details-body document-details-body"><div class="visitor-detail-accordion document-detail-accordion">
                <details class="visitor-detail-disclosure" open><summary>Template Information</summary>${detailGrid(`${detail('Template Type', templateTypeLabel(item.templateType))}${detail('Description', item.description || 'Not set', 'detail-item--full')}${detail('Confidentiality', confidentialityLabel(displayVersion.confidentiality || item.confidentiality || 'INTERNAL'))}${detail('Effective Date', item.effectiveFrom || 'Not set')}${detail('Created By', item.createdBy || 'Not set')}${detail('Created At', fmt(item.createdAt))}${detail('Updated At', fmt(item.updatedAt))}`)}</details>
                <details class="visitor-detail-disclosure" open><summary>Current Version</summary>${templateFileSection(displayVersion, guarded, item.currentVersion || 'No active version')}</details>
                <details class="visitor-detail-disclosure" open><summary>Version History</summary><div class="document-version-list">${(item.versions || []).length ? (item.versions || []).map(templateVersionRow).join('') : '<p>No version history available.</p>'}</div></details>
            </div></div>
            <div class="facility-dialog-actions document-details-footer"><div></div><div>${controls}<button class="btn-secondary dashboard-action-button" type="button" data-document-details-close>Done</button></div></div>
        </div>`;
        modal.querySelector('[data-document-details-close]')?.focus();
    }

    function templateFileSection(version, guarded, versionLabel) {
        if (!version.documentId || !version.documentVersionId) {
            return detailGrid(`${detail('Version', versionLabel)}${detail('File Name', 'Not set')}${detail('File Type / Size', 'Not set')}`);
        }
        return `<div class="document-file-row"><div><span class="material-symbols-outlined document-file-icon" aria-hidden="true">${fileIcon(version)}</span><div><strong>${esc(version.fileName || 'Template file')}</strong><small>${esc(versionLabel)} | ${esc(fileType(version))} | ${esc(size(version.fileSize || 0))}</small></div></div><div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${version.documentId}&version_id=${version.documentVersionId}`))}" target="_blank" rel="noopener" ${guarded ? `data-document-file-action="view" data-document-id="${version.documentId}" data-document-version-id="${version.documentVersionId}"` : ''}>View File</a></div></div>`;
    }

    function templateVersionRow(version) {
        const guarded = String(version.confidentiality || '').toUpperCase() === 'CONFIDENTIAL';
        const links = version.documentId && version.documentVersionId
            ? `<div class="document-file-actions"><a href="${esc(api(`documents/view.php?id=${version.documentId}&version_id=${version.documentVersionId}`))}" target="_blank" rel="noopener" ${guarded ? `data-document-file-action="view" data-document-id="${version.documentId}" data-document-version-id="${version.documentVersionId}"` : ''}>View</a></div>`
            : '<div class="document-file-actions"><span>File unavailable</span></div>';
        return `<article class="document-version-row"><div><div class="document-version-title"><strong>${esc(version.version)}</strong>${badge(templateStatusLabel(version.status), 'status')}</div><span>${esc(version.fileName || 'Template file')}</span><small>${esc(version.changeSummary || 'No summary')}</small><small>Created by ${esc(version.createdBy || 'Unknown')} | ${esc(fmt(version.createdAt))}</small>${version.effectiveFrom ? `<small>Effective ${esc(version.effectiveFrom)}</small>` : ''}${version.retiredAt ? `<small>Retired by ${esc(version.retiredBy)} | ${esc(fmt(version.retiredAt))}</small>` : ''}</div>${links}</article>`;
    }

    async function templateLifecycle(action, versionId) {
        if (!await window.FAMModal.confirm(`Update this template version?`, { title: 'Template Lifecycle' })) return;
        const payload = await window.FAMApi.request(api(`document-templates/${action}.php?template_version_id=${versionId}`), { method: 'POST' });
        window.FAMModal?.showToast?.(payload.message || 'Template updated.');
        await openTemplateDetails(payload.data?.item?.id || state.activeTemplate.id);
        await loadTemplates();
    }

    function bind() {
        qs('#document-add')?.addEventListener('click', () => { state.activeItem = null; openForm(); });
        qs('#template-add')?.addEventListener('click', () => openTemplateForm().catch(console.error));
        qs('#records-export')?.addEventListener('click', () => { window.location.href = exportUrl(); });
        document.querySelectorAll('[data-records-tab]').forEach(button => {
            button.addEventListener('click', () => activateRecordsTab(button.dataset.recordsTab));
            button.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const tabs = Array.from(document.querySelectorAll('[data-records-tab]')).filter(tab => !tab.hidden);
                const current = tabs.indexOf(button);
                const next = event.key === 'Home'
                    ? tabs[0]
                    : event.key === 'End'
                        ? tabs[tabs.length - 1]
                        : tabs[(current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
                next?.focus();
                if (next) activateRecordsTab(next.dataset.recordsTab);
            });
        });
        ['#templates-search', '#template-type-filter', '#template-status-filter'].forEach(selector => {
            qs(selector)?.addEventListener(selector === '#templates-search' ? 'input' : 'change', () => loadTemplates().catch(console.error));
        });
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
            const templateToggle = event.target.closest('[data-template-menu-toggle]');
            if (templateToggle) {
                event.preventDefault();
                event.stopPropagation();
                window.FAMTableMenus?.toggle(templateToggle, document.getElementById(templateToggle.dataset.templateMenuToggle));
                return;
            }
            const toggle = event.target.closest('[data-document-menu-toggle]');
            if (toggle) window.FAMTableMenus?.toggle(toggle, document.getElementById(toggle.dataset.documentMenuToggle));
            const templateAction = event.target.closest('[data-template-action]');
            if (templateAction) {
                event.preventDefault();
                event.stopPropagation();
                window.FAMTableMenus?.close();
                const id = Number(templateAction.dataset.templateId);
                if (templateAction.dataset.templateAction === 'template-details') openTemplateDetails(id).catch(console.error);
                if (templateAction.dataset.templateAction === 'template-version') openTemplateDetails(id).then(() => openTemplateForm(state.activeTemplate)).catch(console.error);
                if (templateAction.dataset.templateAction === 'template-retire') openTemplateDetails(id).then(() => {
                    const active = (state.activeTemplate?.versions || []).find(v => v.status === 'ACTIVE' && v.id === state.activeTemplate.currentApprovedVersionId) || (state.activeTemplate?.versions || []).find(v => v.status === 'ACTIVE');
                    if (active) templateLifecycle('retire', active.id).catch(console.error);
                }).catch(console.error);
                return;
            }
            const action = event.target.closest('[data-document-action]');
            if (action) {
                window.FAMTableMenus?.close();
                const id = Number(action.dataset.documentId);
                if (action.dataset.documentAction === 'view') openDetails(id).catch(console.error);
                if (action.dataset.documentAction === 'version') openDetails(id).then(() => openForm(state.activeItem)).catch(console.error);
                if (action.dataset.documentAction === 'archive') archiveDocument(id).catch(console.error);
            }
            const templateCreate = event.target.closest('[data-template-create]');
            if (templateCreate) openTemplateForm().catch(console.error);
            if (event.target.closest('[data-template-new-version]')) openTemplateForm(state.activeTemplate).catch(console.error);
            const templateVersionAction = event.target.closest('[data-template-version-action]');
            if (templateVersionAction) templateLifecycle(templateVersionAction.dataset.templateVersionAction, templateVersionAction.dataset.versionId).catch(console.error);
            const validateTemplate = event.target.closest('[data-template-validate]');
            if (validateTemplate) {
                event.preventDefault();
                validateTemplateContent(validateTemplate.closest('[data-template-form]')).catch(console.error);
            }
            const fileAction = event.target.closest('[data-document-file-action]');
            if (fileAction) {
                event.preventDefault();
                guardedFileAction(fileAction).catch(console.error);
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
            if (form.matches('[data-template-form]')) {
                submitTemplateForm(form).catch(error => {
                    const box = form.querySelector('[data-template-form-error]');
                    if (box) { box.textContent = error.message || 'Template could not be saved.'; box.classList.remove('hidden'); }
                });
                return;
            }
            submitForm(form).catch(error => {
                const box = form.querySelector('[data-document-form-error]');
                if (box) { box.textContent = error.message || "We couldn't upload this document."; box.classList.remove('hidden'); }
            });
        });
        document.addEventListener('change', event => {
            const fileInput = event.target.closest('[data-template-file-input]');
            if (!fileInput) return;
            const note = fileInput.closest('form')?.querySelector('[data-template-file-note]');
            const file = fileInput.files?.[0];
            if (note) note.textContent = file ? `${file.name} | ${size(file.size)}` : 'No file selected.';
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeForm(); closeDetails(); } });
    }

    function activateRecordsTab(view) {
        if (view === 'templates' && !can('document_templates.view')) return;
        state.view = view === 'templates' ? 'templates' : 'documents';
        document.querySelectorAll('[data-records-tab]').forEach(tab => {
            const active = tab.dataset.recordsTab === state.view;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.setAttribute('tabindex', active ? '0' : '-1');
        });
        document.querySelectorAll('[data-records-panel]').forEach(panel => {
            const active = panel.dataset.recordsPanel === state.view;
            panel.classList.toggle('hidden', !active);
            panel.toggleAttribute('hidden', !active);
        });
        qs('#document-add').hidden = state.view !== 'documents' || !can('records.create');
        qs('#template-add').hidden = state.view !== 'templates' || !can('document_templates.create');
        if (state.view === 'templates') loadTemplates().catch(console.error);
    }

    async function init() {
        await window.FAMApi.me();
        await loadOptions();
        if (can('document_templates.view')) document.querySelector('[data-records-tab="templates"]')?.removeAttribute('hidden');
        bind();
        activateRecordsTab('documents');
        await load();
    }

    document.addEventListener('fam:layout-ready', () => init().catch(error => {
        console.error(error);
        qs('#records-table').innerHTML = tableStateRow(7, 'Unable to load documents. Try again.', 'error');
    }));
})();
