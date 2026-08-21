(function () {
    const state = { page: 1, totalPages: 1, sort: 'updated_at', direction: 'desc', options: {}, activeItem: null, lastFocus: null };
    const qs = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const api = path => `../api/${path}`;
    const can = permission => (window.FAMApi?.currentUser?.permissions || []).includes(permission);

    function moveToTopLayer(element) {
        if (element && element.parentElement !== document.body) document.body.appendChild(element);
        return element;
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
    }

    function fmt(value) {
        if (!value) return 'Not applicable';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function fmtDate(value) {
        if (!value) return 'Not applicable';
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function badge(value, kind = 'status') {
        const raw = String(value || '');
        const cls = raw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-${kind}-${cls}">${esc(title(raw))}</span>`;
    }

    function params() {
        const p = new URLSearchParams({ page: state.page, per_page: 10, sort: state.sort, direction: state.direction });
        const search = qs('#legal-search')?.value.trim();
        if (search) p.set('search', search);
        const map = {
            '#legal-type-filter': 'matter_type',
            '#legal-priority-filter': 'priority',
            '#legal-status-filter': 'status',
            '#legal-department-filter': 'department',
            '#legal-assignee-filter': 'assigned_employee',
        };
        Object.entries(map).forEach(([selector, key]) => {
            const value = qs(selector)?.value;
            if (value && value !== 'all') p.set(key, value);
        });
        return p;
    }

    async function load() {
        qs('#legal-loading-state')?.classList.remove('hidden');
        try {
            const payload = await window.FAMApi.request(api(`legal/index.php?${params()}`));
            const data = payload.data || {};
            renderSummary(data.summary || {});
            renderRows(data.items || []);
            renderPagination(data.pagination || {});
            qs('#legal-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        } finally {
            qs('#legal-loading-state')?.classList.add('hidden');
        }
    }

    async function loadOptions() {
        const legalPayload = await window.FAMApi.request(api('legal/options.php'));
        state.options = legalPayload.data || {};
        fillSimple(qs('#legal-type-filter'), state.options.types || [], 'All Types');
        fillSimple(qs('#legal-priority-filter'), state.options.priorities || [], 'All Priorities');
        fillSimple(qs('#legal-status-filter'), state.options.statuses || [], 'All Statuses');
        fillObjects(qs('#legal-department-filter'), state.options.departments || [], 'All Departments');
        fillObjects(qs('#legal-assignee-filter'), state.options.employees || [], 'All Assignees');
    }

    function renderSummary(summary) {
        qs('#legal-open-count').textContent = summary.open ?? 0;
        qs('#legal-critical-count').textContent = summary.critical ?? 0;
        qs('#legal-review-count').textContent = summary.underReview ?? 0;
        qs('#legal-resolved-count').textContent = summary.resolvedThisPeriod ?? 0;
    }

    function renderRows(items) {
        const body = qs('#legal-table');
        const empty = qs('#legal-empty-state');
        qs('#legal-table-count').textContent = `Showing ${items.length} legal ${items.length === 1 ? 'matter' : 'matters'}`;
        if (!items.length) {
            body.innerHTML = '';
            empty.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">gavel</span><strong>No legal matters found.</strong><p>Legal matters will appear here once created.</p>';
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        body.innerHTML = items.map(row => `<tr>
            <td><button class="document-primary-cell legal-primary-cell" type="button" data-legal-action="view" data-legal-id="${esc(row.id)}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">gavel</span><span><strong title="${esc(row.title)}">${esc(row.title)}</strong><small>${esc(row.matterNo)}</small></span></button></td>
            <td>${esc(title(row.matterType))}</td>
            <td>${badge(row.priority, 'priority')}</td>
            <td>${badge(row.status, 'status')}</td>
            <td>${esc(row.assignedTo || 'Unassigned')}</td>
            <td>${esc(fmt(row.updatedAt))}</td>
            <td>${actions(row)}</td>
        </tr>`).join('');
    }

    function actions(row) {
        const allowed = new Set(row.allowedActions || ['view']);
        const status = String(row.status || '').toUpperCase();
        const items = [];
        const add = (key, label, permission = '', danger = false) => {
            if (!allowed.has(key) && key !== 'continue_review') return;
            if (permission && !can(permission) && !can('legal.manage')) return;
            const action = key === 'continue_review' ? 'view' : key;
            items.push(`<button type="button" class="${danger ? 'document-danger-action' : ''}" data-legal-action="${action}" data-legal-id="${row.id}">${label}</button>`);
        };

        if (status === 'OPEN') {
            add('start_review', 'Review', 'legal.resolve');
            add('edit', 'Edit', 'legal.edit');
            add('cancel', 'Cancel Matter', 'legal.manage', true);
        } else if (status === 'UNDER_REVIEW' || status === 'IN_PROGRESS') {
            add('continue_review', 'Continue Review');
            add('cancel', 'Cancel Matter', 'legal.manage', true);
        } else {
            add('view', 'View Details');
            if (status === 'RESOLVED' || status === 'CLOSED' || status === 'CANCELLED') {
                add('reopen', 'Reopen Matter', 'legal.resolve');
            }
        }
        if (!items.length) {
            items.push(`<button type="button" data-legal-action="view" data-legal-id="${row.id}">View Details</button>`);
        }
        const menuId = `legal-menu-${row.id}`;
        return `<button class="facility-action-toggle" type="button" aria-label="Open legal matter actions" aria-expanded="false" data-legal-menu-toggle="${menuId}"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button><div id="${menuId}" class="facility-action-dropdown legal-action-dropdown hidden" role="menu">${items.join('')}</div>`;
    }

    function renderPagination(pagination) {
        state.totalPages = Number(pagination.total_pages || 1);
        state.page = Number(pagination.page || state.page);
        qs('#legal-page-status').textContent = `Page ${state.page} of ${state.totalPages}`;
        qs('#legal-prev-page').disabled = state.page <= 1;
        qs('#legal-next-page').disabled = state.page >= state.totalPages;
    }

    function fillSimple(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item)}">${esc(title(item))}</option>`).join('');
    }

    function fillObjects(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item.id)}">${esc(item.name)}</option>`).join('');
    }

    function employeeOptions(selected = '') {
        return `<option value="">Unassigned</option>` + (state.options.employees || []).map(item => `<option value="${esc(item.id)}" ${String(item.id) === String(selected || '') ? 'selected' : ''}>${esc(item.name)}${item.department ? ` - ${esc(item.department)}` : ''}</option>`).join('');
    }

    function partyEmployeeOptions(selected = '') {
        return `<option value="">Select employee</option>` + (state.options.employees || []).map(item => `<option value="${esc(item.id)}" ${String(item.id) === String(selected || '') ? 'selected' : ''}>${esc(item.name)}${item.department ? ` - ${esc(item.department)}` : ''}</option>`).join('');
    }

    function partyVisitorOptions(selected = '') {
        return `<option value="">Select visitor</option>` + (state.options.visitors || []).map(item => `<option value="${esc(item.id)}" ${String(item.id) === String(selected || '') ? 'selected' : ''}>${esc(item.name)}${item.organization ? ` - ${esc(item.organization)}` : ''}</option>`).join('');
    }

    function partySelectOptions(items, selected = '') {
        return (items || []).map(value => `<option value="${esc(value)}" ${String(value) === String(selected || '') ? 'selected' : ''}>${esc(title(value))}</option>`).join('');
    }

    function actionTypeOptions(selected = '') {
        return (state.options.action_types || []).map(value => `<option value="${esc(value)}" ${String(value) === String(selected || '') ? 'selected' : ''}>${esc(title(value))}</option>`).join('');
    }

    function departmentOptions(selected = '') {
        return `<option value="">Not applicable</option>` + (state.options.departments || []).map(item => `<option value="${esc(item.id)}" ${String(item.id) === String(selected || '') ? 'selected' : ''}>${esc(item.name)}</option>`).join('');
    }

    async function fetchItem(id) {
        const payload = await window.FAMApi.request(api(`legal/show.php?id=${id}`));
        return payload.data?.item;
    }

    async function openDetails(id) {
        const modal = moveToTopLayer(qs('#legal-details-modal'));
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.innerHTML = detailsShell('LEGAL MATTER', 'Loading...', 'Loading legal matter details...', '<div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">progress_activity</span><span>Loading legal matter details...</span></div>');
        modal.querySelector('[data-legal-details-close]')?.focus();
        try {
            const item = await fetchItem(id);
            if (!item) {
                state.activeItem = null;
                modal.innerHTML = detailsShell('LEGAL MATTER', 'Not found', 'Legal matter not found.', '<div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">search_off</span><span>Legal matter not found.</span></div>');
                modal.querySelector('[data-legal-details-close]')?.focus();
                return;
            }
            state.activeItem = item;
            modal.innerHTML = legalDetailsHtml(item);
            modal.querySelector('[data-legal-details-close]')?.focus();
        } catch (error) {
            state.activeItem = null;
            modal.innerHTML = detailsShell('LEGAL MATTER', 'Unable to load details', 'Unable to load legal matter details.', `<div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">error</span><span>${esc(error.message || 'Unable to load legal matter details.')}</span></div>`);
            modal.querySelector('[data-legal-details-close]')?.focus();
        }
    }

    function legalDetailsHtml(item) {
        const matterInfo = detailGrid(`${detail('Matter No.', item.matterNo)}${detail('Status', title(item.status))}${detail('Type', title(item.matterType))}${detail('Priority', title(item.priority))}${detail('Department', item.department || 'Not applicable')}${detail('Reported', fmtDate(item.reportedAt))}${detail('Title', item.title, 'detail-item--full')}${detail('Initial Note / Description', item.initialNote || item.summary || 'Not applicable', 'detail-item--full')}`);
        const assignment = assignmentDetailsHtml(item);
        const partyCount = (item.parties || []).length;
        const parties = partiesHtml2(item);
        const actionsCount = (item.actions || []).length + (item.actionSuggestions || []).length;
        const actions = actionsDeadlinesHtml(item);
        const supportingDocuments = supportingDocumentsHtml(item);
        const resolution = resolutionHtml(item);
        return `<div class="facility-details-modal-panel visitor-details-panel legal-details-panel">
            <div class="facility-details-modal-header visitor-details-header">
                <div><p>Legal Matter</p><span class="facility-details-modal-request-number">${esc(item.matterNo)}</span><h2>${esc(item.title)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-legal-details-close aria-label="Close legal matter details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body">
                ${aiSummaryHtml(item)}
                <div class="visitor-detail-accordion legal-detail-accordion">
                    <details class="visitor-detail-disclosure" open><summary>Matter Information</summary>${matterInfo}</details>
                    <details class="visitor-detail-disclosure"><summary>Parties Involved (${partyCount})</summary>${parties}</details>
                    <details class="visitor-detail-disclosure"><summary>Actions &amp; Deadlines (${actionsCount})</summary>${actions}</details>
                    <details class="visitor-detail-disclosure"><summary>Assignment</summary>${assignment}</details>
                    <details class="visitor-detail-disclosure"><summary>Supporting Documents</summary>${supportingDocuments}</details>
                    <details class="visitor-detail-disclosure"><summary>Resolution</summary>${resolution}</details>
                    <details class="visitor-detail-disclosure"><summary>Activity History</summary>${history(item.history || [])}</details>
                </div>
            </div>
            <div class="facility-dialog-actions"><div class="legal-details-workflow-actions">${detailsWorkflowActions(item)}</div><button class="btn-secondary dashboard-action-button" type="button" data-legal-details-close>Done</button></div>
        </div>`;
    }

    function isMatterReadOnly(item) {
        return String(item?.status || '').toUpperCase() === 'CLOSED';
    }

    function assignmentDetailsHtml(item) {
        const content = detailGrid(`${detail('Assigned To', item.assignedTo || 'Unassigned')}${detail('Opened', fmt(item.openedAt))}${detail('Created By', item.createdBy || 'System')}${detail('Created', fmt(item.createdAt))}${detail('Updated', fmt(item.updatedAt))}`);
        const action = can('legal.assign') && !isMatterReadOnly(item) && item.status !== 'CANCELLED'
            ? `<div class="legal-assignment-footer"><button class="btn-secondary dashboard-action-button legal-inline-action" type="button" data-legal-action="assign" data-legal-id="${esc(item.id)}">${item.assignedEmployeeId ? 'Reassign' : 'Assign Matter'}</button></div>`
            : '';
        return `<div class="legal-assignment-section">${content}${action}</div>`;
    }

    function detailsWorkflowActions(item) {
        const status = String(item.status || '').toUpperCase();
        const allowed = new Set(item.allowedActions || []);
        const buttons = [];
        const add = (key, label, permission, primary = true) => {
            if (!allowed.has(key)) return;
            if (permission && !can(permission) && !can('legal.manage')) return;
            buttons.push(`<button class="${primary ? 'btn-primary' : 'btn-secondary'} dashboard-action-button" type="button" data-legal-action="${key}" data-legal-id="${esc(item.id)}">${label}</button>`);
        };
        if (status === 'UNDER_REVIEW') add('start_processing', 'Begin Processing', 'legal.resolve');
        if (status === 'IN_PROGRESS') add('resolve', 'Resolve Matter', 'legal.resolve');
        if (status === 'RESOLVED') {
            add('close', 'Close Matter', 'legal.close');
            add('reopen', 'Reopen Matter', 'legal.resolve', false);
        }
        return buttons.join('');
    }

    function partiesHtml(item) {
        const parties = item.parties || [];
        const suggestions = item.partySuggestions || [];
        const canManage = can('legal.manage');
        const actions = canManage ? `<div class="legal-party-toolbar">
            <button class="btn-secondary dashboard-action-button" type="button" data-legal-action="add-party" data-legal-id="${esc(item.id)}">Add Party</button>
            <button class="btn-secondary dashboard-action-button" type="button" data-legal-action="reanalyze-parties" data-legal-id="${esc(item.id)}">Re-analyze Parties</button>
        </div>` : '';
        const confirmed = parties.length ? `<div class="legal-party-list">${parties.map(party => `<article class="legal-party-card">
            <div><strong>${esc(party.name)}</strong><span>${esc(title(party.role))}</span><small>${esc([title(party.type), party.subtitle || party.organization].filter(Boolean).join(' • ') || 'No additional profile context')}</small>${party.notes ? `<p>${esc(party.notes)}</p>` : ''}</div>
            ${party.aiSuggested ? '<em>AI suggested, human confirmed</em>' : ''}
        </article>`).join('')}</div>` : '<p class="legal-empty-note">No confirmed parties yet.</p>';
        const suggested = suggestions.length ? `<div class="legal-party-suggestions">${suggestions.map(suggestion => `<article class="legal-party-suggestion">
            <div><strong>${esc(suggestion.name)}</strong><span>${esc(title(suggestion.role))} • ${esc(title(suggestion.type))}</span>${suggestion.organization ? `<small>${esc(suggestion.organization)}</small>` : ''}${suggestion.context ? `<p>${esc(suggestion.context)}</p>` : ''}</div>
            <div class="legal-party-actions">
                <button class="btn-secondary dashboard-action-button" type="button" data-legal-action="accept-party-suggestion" data-legal-id="${esc(item.id)}" data-suggestion-id="${esc(suggestion.id)}">Accept</button>
                <button class="btn-secondary dashboard-action-button" type="button" data-legal-action="edit-party-suggestion" data-legal-id="${esc(item.id)}" data-suggestion-id="${esc(suggestion.id)}">Edit & Accept</button>
                <button class="btn-secondary dashboard-action-button document-danger-action" type="button" data-legal-action="dismiss-party-suggestion" data-legal-id="${esc(item.id)}" data-suggestion-id="${esc(suggestion.id)}">Dismiss</button>
            </div>
        </article>`).join('')}</div>` : '<p class="legal-empty-note">No pending AI party suggestions.</p>';
        return `<div class="legal-parties-section">
            ${actions}
            <section><h3>Confirmed Parties</h3>${confirmed}</section>
            <section><h3>AI Suggested Parties</h3><p class="document-form-note">Detected automatically from linked supporting documents. Review before adding to the matter.</p>${suggested}</section>
        </div>`;
    }

    function partiesHtml2(item) {
        const parties = item.parties || [];
        const canManage = can('legal.manage') && !isMatterReadOnly(item);
        const addAction = canManage ? `<button class="btn-secondary dashboard-action-button legal-inline-action" type="button" data-legal-action="add-party" data-legal-id="${esc(item.id)}">Add Party</button>` : '';
        const analyzeAction = canManage ? `<button class="btn-secondary dashboard-action-button legal-inline-action" type="button" data-legal-action="reanalyze-parties" data-legal-id="${esc(item.id)}">Re-analyze Parties</button>` : '';
        const bottomActions = canManage ? `<div class="legal-party-footer">${addAction}${analyzeAction}</div>` : '';
        const partyRows = parties.length ? `<div class="legal-party-list">${parties.map(party => partyEntryHtml(item, party)).join('')}</div>` : '<p class="legal-empty-note">No parties associated with this matter yet.</p>';
        return `<div class="legal-parties-section">
            ${partyRows}
            ${bottomActions}
        </div>`;
    }

    function partyEntryHtml(item, party) {
        const organization = party.organization || party.subtitle || '';
        const provenance = party.source === 'AI' || party.aiSuggested ? '<small class="legal-ai-provenance">AI Extracted</small>' : '';
        const actions = can('legal.manage') && !isMatterReadOnly(item) ? `<div class="legal-party-actions">
            <button class="btn-secondary dashboard-action-button" type="button" data-legal-action="edit-party" data-legal-id="${esc(item.id)}" data-party-id="${esc(party.id)}">Edit</button>
            <button class="btn-secondary dashboard-action-button document-danger-action" type="button" data-legal-action="dismiss-party" data-legal-id="${esc(item.id)}" data-party-id="${esc(party.id)}">Dismiss</button>
        </div>` : '';
        return `<article class="legal-party-entry">
            <div class="legal-party-summary">
                <div class="legal-party-copy">
                    <strong>${esc(party.name)}</strong>
                    <span>${esc(title(party.role))} &middot; ${esc(title(party.type))}</span>
                    ${organization ? `<small>${esc(organization)}</small>` : ''}
                    ${provenance}
                </div>
                ${actions}
            </div>
        </article>`;
    }

    function actionsDeadlinesHtml(item) {
        const legalActions = item.actions || [];
        const suggestions = item.actionSuggestions || [];
        const canManage = can('legal.manage') && !isMatterReadOnly(item);
        const visibleRows = [
            ...legalActions.map(action => actionEntryHtml(item, action)),
            ...suggestions.map(suggestion => actionSuggestionHtml(item, suggestion)),
        ];
        const rows = visibleRows.length ? `<div class="legal-action-list">${visibleRows.join('')}</div>` : '<p class="legal-empty-note">No legal actions or recommendations available.</p>';
        const footer = canManage ? `<div class="legal-party-footer"><button class="btn-secondary dashboard-action-button legal-inline-action" type="button" data-legal-action="add-action" data-legal-id="${esc(item.id)}">Add Action</button><button class="btn-secondary dashboard-action-button legal-inline-action" type="button" data-legal-action="reanalyze-actions" data-legal-id="${esc(item.id)}">Re-analyze Actions</button></div>` : '';
        return `<div class="legal-actions-section">
            ${rows}
            ${footer}
        </div>`;
    }

    function actionEntryHtml(item, action) {
        const active = !['COMPLETED', 'CANCELLED'].includes(action.status);
        const canManage = can('legal.manage') && !isMatterReadOnly(item);
        const actionButtons = canManage ? `<div class="legal-party-actions">
            ${active ? `<button class="btn-secondary dashboard-action-button" type="button" data-legal-action="edit-action" data-legal-id="${esc(item.id)}" data-action-id="${esc(action.id)}">Edit</button>` : ''}
            ${action.status === 'PENDING' ? `<button class="btn-secondary dashboard-action-button" type="button" data-legal-action="start-action" data-legal-id="${esc(item.id)}" data-action-id="${esc(action.id)}">Start</button>` : ''}
            ${active ? `<button class="btn-secondary dashboard-action-button" type="button" data-legal-action="complete-action" data-legal-id="${esc(item.id)}" data-action-id="${esc(action.id)}">Complete</button><button class="btn-secondary dashboard-action-button document-danger-action" type="button" data-legal-action="cancel-action" data-legal-id="${esc(item.id)}" data-action-id="${esc(action.id)}">Cancel</button>` : ''}
        </div>` : '';
        return `<article class="legal-action-entry">
            <div class="legal-party-summary">
                <div class="legal-party-copy">
                    <strong>${esc(action.title)}</strong>
                    <span>${esc(title(action.actionType))}${action.dueAt ? ` &middot; Due ${esc(fmt(action.dueAt))}` : ' &middot; No due date'}</span>
                    <small class="legal-action-basis">${esc(title(action.displayStatus || action.status))}</small>
                    ${action.assignedTo ? `<small>Assigned to ${esc(action.assignedTo)}</small>` : ''}
                    ${action.source === 'AI' ? `<small>AI-assisted / confirmed${action.deadlineBasis ? ` &middot; ${esc(actionBasisLabel(action.deadlineBasis))}` : ''}</small>` : ''}
                </div>
                ${actionButtons ? `<div class="legal-action-side">${actionButtons}</div>` : ''}
            </div>
        </article>`;
    }

    function actionSuggestionHtml(item, suggestion) {
        const basis = suggestion.deadlineBasis || suggestion.dateBasis || 'NO_DEADLINE';
        const targetLabel = basis === 'SOURCE_DERIVED' ? 'Due' : 'Suggested target';
        const basisLabel = actionBasisLabel(basis);
        const actions = can('legal.manage') && !isMatterReadOnly(item) ? `<div class="legal-party-actions">
            <button class="btn-secondary dashboard-action-button" type="button" data-legal-action="review-action-suggestion" data-legal-id="${esc(item.id)}" data-suggestion-id="${esc(suggestion.id)}">Review &amp; Add</button>
            <button class="btn-secondary dashboard-action-button document-danger-action" type="button" data-legal-action="dismiss-action-suggestion" data-legal-id="${esc(item.id)}" data-suggestion-id="${esc(suggestion.id)}">Dismiss</button>
        </div>` : '';
        return `<article class="legal-action-entry">
            <div class="legal-party-summary">
                <div class="legal-party-copy">
                    <strong>${esc(suggestion.title)}</strong>
                    <span>${esc(title(suggestion.actionType))}${suggestion.dueAt ? ` &middot; ${targetLabel} ${esc(fmt(suggestion.dueAt))}` : ' &middot; No suggested target'}</span>
                    <small class="legal-action-basis">${esc(basisLabel)}</small>
                </div>
                ${actions}
            </div>
        </article>`;
    }

    function actionBasisLabel(value) {
        const basis = String(value || '').toUpperCase();
        if (basis === 'SOURCE_DERIVED') return 'Source-derived deadline';
        if (basis === 'AI_RECOMMENDED') return 'AI Recommended';
        return 'No deadline basis';
    }

    function uniquePartySuggestions(suggestions) {
        const seen = new Set();
        return suggestions.filter(suggestion => {
            const name = normalizePartyIdentity(suggestion.name || suggestion.organization);
            const key = `${name}|${String(suggestion.type || '').toUpperCase()}|${String(suggestion.role || '').toUpperCase()}`;
            if (!name || seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }

    function normalizePartyIdentity(value) {
        return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
    }

    function shortPartyContext(value) {
        const text = String(value || '').trim().replace(/\s+/g, ' ');
        const firstSentence = text.match(/^(.+?[.!?])(?:\s|$)/)?.[1] || text;
        return firstSentence.length > 150 ? `${firstSentence.slice(0, 147).replace(/[\s,.;:]+$/, '')}...` : firstSentence;
    }

    function concisePartyDescription(value) {
        const text = shortPartyContext(value);
        if (!text) return '';
        const lowered = text.toLowerCase();
        if (lowered.includes('submitted') && lowered.includes('incident report')) return 'Submitted the incident report.';
        if ((lowered.includes('inspected') || lowered.includes('inspection')) && lowered.includes('post-use')) return 'Conducted the post-use inspection.';
        if (lowered.includes('associated') && lowered.includes('scheduled facility use')) return 'Associated with the scheduled facility use.';
        const withoutName = text.replace(/^[A-Z][A-Za-z.' -]+,\s*/i, '').replace(/^[A-Z][A-Za-z.' -]+\s+(?:is|was|submitted|conducted|inspected|identified)\s+/i, match => match.replace(/^[A-Z][A-Za-z.' -]+\s+/, ''));
        const cleaned = withoutName
            .replace(/\s+(?:and is|who is|who was|as a|a person who).*$/i, '')
            .replace(/\s+for this matter\.?$/i, '.')
            .trim();
        if (!cleaned || cleaned.length > 90) return '';
        return cleaned.endsWith('.') ? cleaned : `${cleaned}.`;
    }

    function summaryParagraphs(text) {
        const normalized = String(text || '').replace(/\r\n/g, '\n').trim();
        const chunks = normalized
            ? normalized.split(/\n{2,}/).map(chunk => chunk.replace(/\s*\n\s*/g, ' ').trim()).filter(Boolean)
            : ['AI summary is ready, but no content was returned.'];
        return chunks.map(chunk => `<p class="legal-ai-summary-paragraph">${esc(chunk)}</p>`).join('');
    }

    function aiSummaryHtml(item) {
        const status = item.aiSummaryStatus || 'NOT_REQUESTED';
        const docs = item.supportingDocuments || [];
        const canRegenerate = docs.length > 0 && !isMatterReadOnly(item) && can('records.view') && (can('legal.edit') || can('legal.manage'));
        const button = canRegenerate ? `<button class="btn-secondary dashboard-action-button legal-ai-summary-action" type="button" data-legal-action="regenerate-summary" data-legal-id="${esc(item.id)}">Regenerate AI Summary</button>` : '';
        let content = '';
        if (status === 'READY') {
            content = `${summaryParagraphs(item.aiSummary)}<small>Generated automatically from linked supporting documents${item.aiSummaryGeneratedAt ? ` on ${esc(fmt(item.aiSummaryGeneratedAt))}` : ''}. Review source documents for authoritative details.</small>`;
        } else if (status === 'PENDING') {
            content = '<p>Analyzing supporting documents...</p><small>Refresh this matter in a moment to see the generated summary.</small>';
        } else if (status === 'FAILED') {
            content = '<p>AI summary could not be generated. Review the supporting documents directly.</p>';
        } else if (status === 'NO_READABLE_SOURCE') {
            content = '<p>No readable supporting documents are available for AI summarization.</p>';
        } else if (status === 'STALE') {
            content = `${item.aiSummary ? summaryParagraphs(item.aiSummary) : '<p>Supporting documents have changed.</p>'}<small>Supporting documents have changed. Refresh the AI summary.</small>`;
        } else {
            content = docs.length ? '<p>No AI summary has been generated yet.</p>' : '<p>No supporting documents are available for AI summarization.</p>';
        }
        return `<section class="legal-ai-summary" aria-label="AI Matter Summary">
            <div class="legal-ai-summary-header"><div><h3>AI Matter Summary</h3><p>Generated automatically from linked supporting documents.</p></div>${button}</div>
            <div class="legal-ai-summary-content legal-ai-summary-${esc(status.toLowerCase().replace(/_/g, '-'))}">${content}</div>
        </section>`;
    }

    function resolutionHtml(item) {
        if (item.status === 'CANCELLED') {
            return detailGrid(`${detail('Cancelled', fmt(item.cancelledAt))}${detail('Cancelled By', item.cancelledBy || 'Not applicable')}${detail('Cancellation Reason', item.cancellationReason || 'Not applicable', 'detail-item--full')}`);
        }
        if (['RESOLVED', 'CLOSED'].includes(item.status)) {
            return detailGrid(`${detail('Resolved', fmt(item.resolvedAt))}${detail('Resolved By', item.resolvedBy || 'Not applicable')}${detail('Closed', fmt(item.closedAt))}${detail('Closed By', item.closedBy || 'Not applicable')}${detail('Resolution Summary', item.resolutionSummary || 'Not applicable', 'detail-item--full')}`);
        }
        return '<p class="legal-empty-note">No resolution recorded yet.</p>';
    }

    function supportingDocumentsHtml(item) {
        const docs = item.supportingDocuments || [];
        const canAttach = !isMatterReadOnly(item) && can('records.create') && (can('legal.edit') || can('legal.manage'));
        const attachButton = canAttach ? `<button class="btn-secondary dashboard-action-button legal-attach-button" type="button" data-legal-action="attach-document" data-legal-id="${esc(item.id)}">Add Supporting Document</button>` : '';
        if (!docs.length) {
            return `<div class="legal-supporting-documents-empty"><p class="legal-empty-note">No supporting documents attached.</p>${attachButton}</div>`;
        }
        return `<div class="legal-supporting-documents">
            <div class="legal-supporting-documents-list">
                ${docs.map(doc => {
                    const current = doc.currentVersion || {};
                    return `<article class="legal-supporting-document">
                        <div class="legal-supporting-copy">
                            <strong>${esc(doc.title)}</strong>
                            <small>${esc(doc.documentNo)} · ${esc(current.fileName || 'File')} · ${esc(doc.version || '')} · ${esc(title(doc.status))}</small>
                        </div>
                        <div class="document-file-actions">
                            <a href="${api(`documents/view.php?id=${doc.id}`)}" target="_blank" rel="noopener">View</a>
                            <a href="${api(`documents/download.php?id=${doc.id}`)}">Download</a>
                        </div>
                    </article>`;
                }).join('')}
            </div>
            ${attachButton ? `<div class="legal-supporting-footer">${attachButton}</div>` : ''}
        </div>`;
    }

    function detailsShell(label, reference, heading, body) {
        return `<div class="facility-details-modal-panel visitor-details-panel legal-details-panel">
            <div class="facility-details-modal-header visitor-details-header">
                <div><p>${esc(label)}</p><span class="facility-details-modal-request-number">${esc(reference)}</span><h2>${esc(heading)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-legal-details-close aria-label="Close legal matter details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body">${body}</div>
        </div>`;
    }

    function legalModalOpen() {
        return qs('#legal-dialog')?.hidden === false || qs('#legal-details-modal')?.hidden === false;
    }

    function syncLegalModalState() {
        document.body.classList.toggle('facility-details-modal-open', legalModalOpen());
    }

    function detail(label, value, className = '') {
        return `<dl class="facility-detail-row ${esc(className)}"><dt>${esc(label)}</dt><dd>${esc(value || 'Not applicable')}</dd></dl>`;
    }

    function detailGrid(content) {
        return `<div class="detail-grid">${content}</div>`;
    }

    function history(items) {
        if (!items.length) return '<div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">history</span><span>No activity beyond creation yet.</span></div>';
        return `<ol class="facility-history-list">${items.map(item => `<li><strong>${esc(title(item.type))}</strong><span>${esc(fmt(item.createdAt))}${item.actor ? ` by ${esc(item.actor)}` : ''}</span><p>${esc(item.description || '')}</p></li>`).join('')}</ol>`;
    }

    function closeDetails() {
        const modal = qs('#legal-details-modal');
        if (!modal || modal.hidden) return;
        window.FAMModal?.closeElement?.(modal, () => {
            modal.innerHTML = '';
            state.activeItem = null;
            syncLegalModalState();
            state.lastFocus?.focus?.();
            state.lastFocus = null;
        });
    }

    function openMatterForm(item = null) {
        const modal = moveToTopLayer(qs('#legal-dialog'));
        const editing = Boolean(item);
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel legal-form" data-legal-form="${editing ? 'edit' : 'create'}">
            <div class="facility-details-modal-header"><div><p>Legal Management</p><h2>${editing ? 'Edit Legal Matter' : 'New Legal Matter'}</h2></div><button class="facility-details-modal-close" type="button" data-legal-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><div class="document-form-error hidden" data-legal-error></div><section class="document-form-section">
                ${editing ? `<h3>${esc(item.matterNo)}</h3>` : ''}
                <div class="document-form-grid">
                    ${field('title', 'Title *', item?.title || '', 'text', true)}
                    <label class="facility-field"><span>Matter Type *</span><select name="matter_type" required>${(state.options.types || []).map(value => `<option value="${esc(value)}" ${value === item?.matterType ? 'selected' : ''}>${esc(title(value))}</option>`).join('')}</select></label>
                    <label class="facility-field"><span>Priority *</span><select name="priority" required>${(state.options.priorities || []).map(value => `<option value="${esc(value)}" ${value === (item?.priority || 'MEDIUM') ? 'selected' : ''}>${esc(title(value))}</option>`).join('')}</select></label>
                    <label class="facility-field"><span>Department</span><select name="department_reference_id">${departmentOptions(item?.departmentId || '')}</select></label>
                    <label class="facility-field"><span>Reported Date</span><input name="reported_at" type="date" value="${esc(item?.reportedAt || '')}"></label>
                </div>
                <label class="facility-field document-full-field"><span>Initial Note / Description</span><textarea name="initial_note" rows="5" placeholder="Optional">${esc(item?.initialNote || item?.summary || '')}</textarea></label>
            </section>
            ${!editing && can('records.create') ? `<section class="document-form-section legal-supporting-upload-section">
                <h3>Supporting Documents / Evidence *</h3>
                <p class="document-form-note">Attach at least one supporting document or evidence file to create this legal matter.</p>
                <label class="facility-field document-file-field document-full-field"><span>Files *</span><input name="supporting_documents[]" type="file" multiple required accept=".pdf,.png,.jpg,.jpeg"><small>Allowed: PDF, PNG, JPG up to 10 MB each.</small></label>
            </section>` : ''}
            </div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-legal-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">${editing ? 'Save Changes' : 'Create Matter'}</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('input[name="title"]')?.focus();
    }

    function openAssign(item) {
        const modal = moveToTopLayer(qs('#legal-dialog'));
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel legal-form legal-assign-form" data-legal-form="assign">
            <div class="facility-details-modal-header"><div><p>Legal Management</p><span class="facility-details-modal-request-number">${esc(item.matterNo)}</span><h2>Assign Legal Matter</h2></div><button class="facility-details-modal-close" type="button" data-legal-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><div class="document-form-error hidden" data-legal-error></div><section class="document-form-section"><h3>${esc(item.title)}</h3><label class="facility-field"><span>Assigned To</span><select name="assigned_employee_reference_id">${employeeOptions(item.assignedEmployeeId || '')}</select></label></section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-legal-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Save Assignment</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function openAttachDocument(item) {
        const modal = moveToTopLayer(qs('#legal-dialog'));
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel legal-form legal-attach-form" data-legal-form="attach-document">
            <div class="facility-details-modal-header"><div><p>Legal Management</p><span class="facility-details-modal-request-number">${esc(item.matterNo)}</span><h2>Add Supporting Document</h2></div><button class="facility-details-modal-close" type="button" data-legal-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><div class="document-form-error hidden" data-legal-error></div><section class="document-form-section">
                <h3>${esc(item.title)}</h3>
                <div class="document-form-grid">
                    ${field('title', 'Document Title', '', 'text', false)}
                    <label class="facility-field"><span>Document Date</span><input name="document_date" type="date" value="${new Date().toISOString().slice(0, 10)}"></label>
                </div>
                <label class="facility-field document-file-field document-full-field"><span>Primary File *</span><input name="file" type="file" required accept=".pdf,.png,.jpg,.jpeg"><small>Allowed: PDF, PNG, JPG up to 10 MB.</small></label>
                <label class="facility-field document-full-field"><span>Description</span><textarea name="description" rows="3"></textarea></label>
            </section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-legal-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Attach Document</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('input[name="title"]')?.focus();
    }

    function openPartyForm(item, suggestion = null, party = null) {
        const modal = moveToTopLayer(qs('#legal-dialog'));
        const editingSuggestion = Boolean(suggestion);
        const editingParty = Boolean(party);
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        const source = party || suggestion || {};
        modal.innerHTML = `<form class="facility-dialog-panel legal-form legal-party-form" data-legal-form="${editingParty ? 'update-party' : editingSuggestion ? 'accept-party-suggestion' : 'add-party'}" data-suggestion-id="${esc(suggestion?.id || '')}" data-party-id="${esc(party?.id || '')}">
            <div class="facility-details-modal-header"><div><p>Legal Management</p><span class="facility-details-modal-request-number">${esc(item.matterNo)}</span><h2>${editingParty ? 'Edit Party' : editingSuggestion ? 'Review & Add Party' : 'Add Party'}</h2></div><button class="facility-details-modal-close" type="button" data-legal-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><div class="document-form-error hidden" data-legal-error></div><section class="document-form-section">
                <h3>${esc(item.title)}</h3>
                <div class="document-form-grid">
                    <label class="facility-field"><span>Party Role *</span><select name="party_role" required>${partySelectOptions(state.options.party_roles || [], source.role || 'PERSON_INVOLVED')}</select></label>
                    <label class="facility-field"><span>Party Type *</span><select name="party_type" required data-party-type>${partySelectOptions(state.options.party_types || [], source.type || 'EXTERNAL_PERSON')}</select></label>
                    <label class="facility-field" data-party-employee-field><span>Linked Employee</span><select name="employee_reference_id">${partyEmployeeOptions(source.employeeReferenceId || '')}</select></label>
                    <label class="facility-field" data-party-visitor-field><span>Linked Visitor</span><select name="visitor_id">${partyVisitorOptions(source.visitorId || '')}</select></label>
                    ${field('external_name', 'Name / External Party', source.name || '', 'text', false)}
                    ${field('organization_name', 'Organization', source.organization || '', 'text', false)}
                </div>
                <label class="facility-field document-full-field"><span>Notes / Context</span><textarea name="notes" rows="4">${esc(source.context || source.notes || '')}</textarea></label>
            </section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-legal-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">${editingParty ? 'Save Party' : 'Add Party'}</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        syncPartyForm(modal.querySelector('[data-party-type]'));
    }

    function openActionForm(item, action = null, suggestion = null) {
        const modal = moveToTopLayer(qs('#legal-dialog'));
        const editingAction = Boolean(action);
        const reviewingSuggestion = Boolean(suggestion);
        const source = action || suggestion || {};
        const basis = source.deadlineBasis || source.dateBasis || '';
        const suggestionMeta = reviewingSuggestion ? `<div class="legal-recommendation-meta">
            <div><strong>Recommendation Basis</strong><span>${esc(actionBasisLabel(basis))}</span></div>
            ${source.recommendationReason ? `<div><strong>Recommendation Reason</strong><span>${esc(source.recommendationReason)}</span></div>` : ''}
            ${source.sourceDocument ? `<div><strong>Source Document</strong><span>${esc(source.sourceDocument)}</span></div>` : ''}
            ${source.sourceContext ? `<div><strong>Source Context</strong><span>${esc(source.sourceContext)}</span></div>` : ''}
        </div>` : '';
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel legal-form legal-action-form" data-legal-form="${editingAction ? 'update-action' : reviewingSuggestion ? 'accept-action-suggestion' : 'add-action'}" data-action-id="${esc(action?.id || '')}" data-suggestion-id="${esc(suggestion?.id || '')}">
            <div class="facility-details-modal-header"><div><p>Legal Management</p><span class="facility-details-modal-request-number">${esc(item.matterNo)}</span><h2>${editingAction ? 'Edit Legal Action' : reviewingSuggestion ? 'Review & Add Action' : 'Add Legal Action'}</h2></div><button class="facility-details-modal-close" type="button" data-legal-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><div class="document-form-error hidden" data-legal-error></div><section class="document-form-section">
                <h3>${esc(item.title)}</h3>
                <div class="document-form-grid">
                    ${field('title', 'Action Title *', source.title || '', 'text', true)}
                    <label class="facility-field"><span>Action Type *</span><select name="action_type" required>${actionTypeOptions(source.actionType || 'REVIEW')}</select></label>
                    <label class="facility-field"><span>Assigned To</span><select name="assigned_employee_reference_id">${employeeOptions(source.assignedEmployeeId || '')}</select></label>
                    <label class="facility-field"><span>Due Date</span><input name="due_at" type="datetime-local" value="${esc(dateTimeInputValue(source.dueAt || ''))}"></label>
                </div>
                <label class="facility-field document-full-field"><span>Description</span><textarea name="description" rows="4">${esc(source.description || '')}</textarea></label>
                ${reviewingSuggestion ? `<input type="hidden" name="deadline_basis" value="${esc(basis)}"><input type="hidden" name="recommendation_reason" value="${esc(source.recommendationReason || '')}">${suggestionMeta}` : ''}
            </section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-legal-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">${editingAction ? 'Save Action' : reviewingSuggestion ? 'Add Official Action' : 'Add Action'}</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('input[name="title"]')?.focus();
    }

    function dateTimeInputValue(value) {
        if (!value) return '';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return '';
        const pad = number => String(number).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function syncPartyForm(select) {
        const form = select?.closest('[data-legal-form]');
        if (!form) return;
        const isEmployee = select.value === 'EMPLOYEE';
        const isVisitor = select.value === 'VISITOR';
        form.querySelector('[data-party-employee-field]')?.classList.toggle('hidden', !isEmployee);
        form.querySelector('[data-party-visitor-field]')?.classList.toggle('hidden', !isVisitor);
        form.querySelector('input[name="external_name"]')?.toggleAttribute('required', !isEmployee && !isVisitor);
    }

    function field(name, label, value = '', type = 'text', required = false) {
        return `<label class="facility-field"><span>${esc(label)}</span><input name="${esc(name)}" type="${esc(type)}" value="${esc(value)}" ${required ? 'required' : ''}></label>`;
    }

    function closeDialog() {
        const modal = qs('#legal-dialog');
        if (!modal || modal.hidden) return;
        window.FAMModal?.closeElement?.(modal, () => {
            modal.innerHTML = '';
            syncLegalModalState();
            if (!legalModalOpen()) {
                state.lastFocus?.focus?.();
                state.lastFocus = null;
            }
        });
    }

    function formPayload(form) {
        return Object.fromEntries(new FormData(form).entries());
    }

    function formDataPayload(form) {
        return new FormData(form);
    }

    function showError(form, error) {
        const box = form.querySelector('[data-legal-error]');
        const message = Object.values(error.errors || {})[0] || error.message || 'Unable to complete this action.';
        if (box) {
            box.textContent = message;
            box.classList.remove('hidden');
        }
    }

    function clearError(form) {
        const box = form.querySelector('[data-legal-error]');
        if (box) {
            box.textContent = '';
            box.classList.add('hidden');
        }
    }

    function validateCreateEvidence(form) {
        const input = form.querySelector('input[name="supporting_documents[]"]');
        const files = Array.from(input?.files || []);
        if (!files.length) {
            showError(form, { errors: { supporting_documents: 'Attach at least one supporting document or evidence file to create this legal matter.' } });
            return false;
        }
        const allowedExtensions = new Set(['pdf', 'png', 'jpg', 'jpeg']);
        const allowedMime = new Set(['application/pdf', 'image/png', 'image/jpeg']);
        const maxSize = 10 * 1024 * 1024;
        for (const file of files) {
            const extension = (file.name.split('.').pop() || '').toLowerCase();
            if (!allowedExtensions.has(extension) || (file.type && !allowedMime.has(file.type))) {
                showError(form, { errors: { supporting_documents: `${file.name}: This file type is not supported.` } });
                return false;
            }
            if (file.size <= 0 || file.size > maxSize) {
                showError(form, { errors: { supporting_documents: `${file.name}: File exceeds the allowed size.` } });
                return false;
            }
        }
        clearError(form);
        return true;
    }

    function setSubmitting(form, submitting) {
        form.dataset.submitting = submitting ? 'true' : 'false';
        form.querySelectorAll('button, input, select, textarea').forEach(control => {
            if (submitting) {
                control.dataset.wasDisabled = control.disabled ? 'true' : 'false';
                control.disabled = true;
            } else if (control.dataset.wasDisabled !== 'true') {
                control.disabled = false;
                delete control.dataset.wasDisabled;
            }
        });
    }

    function triggerInitialSummary(id) {
        if (!id || !can('legal.manage')) return;
        window.FAMApi.request(api(`legal/generate-summary.php?id=${id}`), { method: 'POST', body: {} })
            .then(() => window.FAMApi.request(api(`legal/analyze-parties.php?id=${id}`), { method: 'POST', body: {} }).catch(error => console.warn('Initial legal AI party extraction failed.', error)))
            .then(() => load())
            .catch(error => {
                console.warn('Initial legal AI summary generation failed.', error);
                load().catch(console.error);
            });
    }

    async function submitForm(form) {
        if (form.dataset.submitting === 'true') return;
        const type = form.dataset.legalForm;
        const id = state.activeItem?.id;
        const path = type === 'create' ? 'legal/create.php' : type === 'edit' ? `legal/update.php?id=${id}` : type === 'attach-document' ? `legal/attach-document.php?id=${id}` : `legal/assign.php?id=${id}`;
        const suggestionId = form.dataset.suggestionId;
        const partyId = form.dataset.partyId;
        const actionId = form.dataset.actionId;
        const partyPath = type === 'add-party' ? `legal/add-party.php?id=${id}` : type === 'update-party' ? `legal/update-party.php?matter_id=${id}&party_id=${partyId}` : type === 'accept-party-suggestion' ? `legal/accept-party-suggestion.php?suggestion_id=${suggestionId}&matter_id=${id}` : '';
        const actionPath = type === 'add-action' ? `legal/add-action.php?id=${id}` : type === 'update-action' ? `legal/update-action.php?matter_id=${id}&action_id=${actionId}` : type === 'accept-action-suggestion' ? `legal/accept-action-suggestion.php?matter_id=${id}&suggestion_id=${suggestionId}` : '';
        if (type === 'create' && !validateCreateEvidence(form)) return;
        const body = ['create', 'attach-document'].includes(type) ? formDataPayload(form) : formPayload(form);
        setSubmitting(form, true);
        try {
            const payload = await window.FAMApi.request(api(actionPath || partyPath || path), { method: 'POST', body });
            const createdId = Number(payload.data?.item?.id || 0);
            closeDialog();
            const partial = payload.data?.attachment_errors?.length;
            window.FAMModal?.showToast?.(partial ? payload.message : type === 'create' ? 'Legal matter created.' : type === 'edit' ? 'Legal matter updated.' : type === 'attach-document' ? 'Supporting document attached.' : type.includes('party') ? 'Party updated.' : type.includes('action') ? 'Legal action updated.' : 'Legal matter assigned.');
            await load();
            if (type === 'create' && createdId) triggerInitialSummary(createdId);
            if (id && qs('#legal-details-modal')?.hidden === false) await openDetails(id);
        } catch (error) {
            showError(form, error);
        } finally {
            if (form.isConnected) setSubmitting(form, false);
        }
    }

    async function transition(id, action) {
        const item = await fetchItem(id);
        state.activeItem = item;
        const config = {
            start_review: { title: 'Review Legal Matter', confirmLabel: 'Start Review' },
            start_processing: { title: 'Begin Processing', confirmLabel: 'Begin Processing' },
            close: { title: 'Close Matter', confirmLabel: 'Close Matter' },
            resolve: { title: 'Resolve Matter', prompt: 'Enter the resolution summary.', inputLabel: 'Resolution Summary', confirmLabel: 'Resolve Matter', field: 'resolution_summary' },
            cancel: { title: 'Cancel Matter', prompt: 'Enter the cancellation reason.', inputLabel: 'Cancellation Reason', confirmLabel: 'Cancel Matter', field: 'cancellation_reason' },
            reopen: { title: 'Reopen Matter', prompt: 'Enter the reopen reason.', inputLabel: 'Reopen Reason', confirmLabel: 'Reopen Matter', field: 'reopen_reason' },
        }[action];
        if (!config) return;
        const body = { action };
        if (config.prompt) {
            const value = await window.FAMModal.prompt(config.prompt, '', { title: config.title, inputLabel: config.inputLabel, confirmLabel: config.confirmLabel });
            if (value === null) return;
            body[config.field] = value;
        } else if (!await window.FAMModal.confirm(`Continue with ${config.title.toLowerCase()}?`, { title: config.title, confirmLabel: config.confirmLabel })) {
            return;
        }
        await window.FAMApi.request(api(`legal/transition.php?id=${id}`), { method: 'POST', body });
        window.FAMModal?.showToast?.('Legal matter updated.');
        await load();
        if (action === 'start_review' || qs('#legal-details-modal')?.hidden === false) await openDetails(id);
    }

    function bind() {
        qs('#legal-refresh')?.addEventListener('click', () => load().catch(console.error));
        qs('#legal-new-matter')?.addEventListener('click', () => openMatterForm());
        qs('#legal-prev-page')?.addEventListener('click', () => { if (state.page > 1) { state.page--; load().catch(console.error); } });
        qs('#legal-next-page')?.addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; load().catch(console.error); } });
        document.querySelectorAll('.legal-table [data-sort]').forEach(button => button.addEventListener('click', () => {
            const nextSort = button.dataset.sort;
            state.direction = state.sort === nextSort && state.direction === 'asc' ? 'desc' : 'asc';
            state.sort = nextSort;
            state.page = 1;
            load().catch(console.error);
        }));
        ['#legal-search', '#legal-type-filter', '#legal-priority-filter', '#legal-status-filter', '#legal-department-filter', '#legal-assignee-filter'].forEach(selector => {
            qs(selector)?.addEventListener(selector === '#legal-search' ? 'input' : 'change', () => { state.page = 1; load().catch(console.error); });
        });
        document.addEventListener('click', event => {
            if (event.target.closest('[data-legal-dialog-close]')) closeDialog();
            if (event.target.closest('[data-legal-details-close]') || event.target === qs('#legal-details-modal')) closeDetails();
            const toggle = event.target.closest('[data-legal-menu-toggle]');
            if (toggle) window.FAMTableMenus?.toggle(toggle, document.getElementById(toggle.dataset.legalMenuToggle));
            const action = event.target.closest('[data-legal-action]');
            if (!action) return;
            window.FAMTableMenus?.close();
            const id = Number(action.dataset.legalId);
            const type = action.dataset.legalAction;
            if (type === 'view') openDetails(id).catch(console.error);
            if (type === 'edit') fetchItem(id).then(item => { state.activeItem = item; openMatterForm(item); }).catch(console.error);
            if (type === 'assign') fetchItem(id).then(item => { state.activeItem = item; openAssign(item); }).catch(console.error);
            if (type === 'attach-document') fetchItem(id).then(item => { state.activeItem = item; openAttachDocument(item); }).catch(console.error);
            if (type === 'add-party') fetchItem(id).then(item => { state.activeItem = item; openPartyForm(item); }).catch(console.error);
            if (type === 'edit-party') fetchItem(id).then(item => { state.activeItem = item; openPartyForm(item, null, (item.parties || []).find(p => Number(p.id) === Number(action.dataset.partyId))); }).catch(console.error);
            if (type === 'dismiss-party') dismissParty(id, Number(action.dataset.partyId)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to dismiss party.'));
            if (type === 'edit-party-suggestion') fetchItem(id).then(item => { state.activeItem = item; openPartyForm(item, (item.partySuggestions || []).find(s => Number(s.id) === Number(action.dataset.suggestionId))); }).catch(console.error);
            if (type === 'accept-party-suggestion') acceptPartySuggestion(id, Number(action.dataset.suggestionId)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to accept party suggestion.'));
            if (type === 'dismiss-party-suggestion') dismissPartySuggestion(id, Number(action.dataset.suggestionId)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to dismiss party suggestion.'));
            if (type === 'reanalyze-parties') reanalyzeParties(id).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to analyze parties.'));
            if (type === 'add-action') fetchItem(id).then(item => { state.activeItem = item; openActionForm(item); }).catch(console.error);
            if (type === 'edit-action') fetchItem(id).then(item => { state.activeItem = item; openActionForm(item, (item.actions || []).find(record => Number(record.id) === Number(action.dataset.actionId))); }).catch(console.error);
            if (type === 'review-action-suggestion') fetchItem(id).then(item => { state.activeItem = item; openActionForm(item, null, (item.actionSuggestions || []).find(record => Number(record.id) === Number(action.dataset.suggestionId))); }).catch(console.error);
            if (type === 'dismiss-action-suggestion') dismissActionSuggestion(id, Number(action.dataset.suggestionId)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to dismiss action suggestion.'));
            if (type === 'reanalyze-actions') reanalyzeActions(id).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to analyze actions.'));
            if (type === 'start-action') startAction(id, Number(action.dataset.actionId)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to start action.'));
            if (type === 'complete-action') completeAction(id, Number(action.dataset.actionId)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to complete action.'));
            if (type === 'cancel-action') cancelAction(id, Number(action.dataset.actionId)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to cancel action.'));
            if (type === 'regenerate-summary') regenerateSummary(id).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to refresh AI summary.'));
            if (['start_review', 'start_processing', 'resolve', 'close', 'cancel', 'reopen'].includes(type)) transition(id, type).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to update matter.'));
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-legal-form]');
            if (!form) return;
            event.preventDefault();
            submitForm(form).catch(console.error);
        });
        document.addEventListener('change', event => {
            const input = event.target.closest('input[name="supporting_documents[]"]');
            if (input) {
                const form = input.closest('[data-legal-form]');
                if (form && input.files?.length) clearError(form);
            }
            const partyType = event.target.closest('[data-party-type]');
            if (partyType) syncPartyForm(partyType);
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeDialog(); closeDetails(); } });
    }

    async function regenerateSummary(id) {
        if (!await window.FAMModal.confirm('Regenerate the AI matter summary from the current supporting documents?', { title: 'Regenerate AI Summary', confirmLabel: 'Regenerate' })) return;
        await window.FAMApi.request(api(`legal/regenerate-summary.php?id=${id}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('AI matter summary refreshed.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(id);
    }

    async function reanalyzeParties(id) {
        if (!await window.FAMModal.confirm('Analyze linked supporting documents for party suggestions?', { title: 'Re-analyze Parties', confirmLabel: 'Analyze' })) return;
        await window.FAMApi.request(api(`legal/analyze-parties.php?id=${id}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('AI party suggestions updated.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(id);
    }

    async function reanalyzeActions(id) {
        if (!await window.FAMModal.confirm('Analyze linked supporting documents for action and deadline suggestions?', { title: 'Re-analyze Actions', confirmLabel: 'Analyze' })) return;
        await window.FAMApi.request(api(`legal/analyze-actions.php?id=${id}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('AI action suggestions updated.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(id);
    }

    async function dismissActionSuggestion(matterId, suggestionId) {
        const reason = await window.FAMModal.prompt('Why should this action suggestion be dismissed?', '', { title: 'Dismiss Action Suggestion', inputLabel: 'Dismissal Reason', confirmLabel: 'Dismiss' });
        if (reason === null) return;
        await window.FAMApi.request(api(`legal/dismiss-action-suggestion.php?matter_id=${matterId}&suggestion_id=${suggestionId}`), { method: 'POST', body: { reason } });
        window.FAMModal?.showToast?.('Action suggestion dismissed.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(matterId);
    }

    async function startAction(matterId, actionId) {
        if (!await window.FAMModal.confirm('Start this legal action?', { title: 'Start Action', confirmLabel: 'Start' })) return;
        await window.FAMApi.request(api(`legal/start-action.php?matter_id=${matterId}&action_id=${actionId}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('Legal action started.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(matterId);
    }

    async function completeAction(matterId, actionId) {
        if (!await window.FAMModal.confirm('Mark this legal action as completed?', { title: 'Complete Action', confirmLabel: 'Complete' })) return;
        await window.FAMApi.request(api(`legal/complete-action.php?matter_id=${matterId}&action_id=${actionId}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('Legal action completed.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(matterId);
    }

    async function cancelAction(matterId, actionId) {
        const reason = await window.FAMModal.prompt('Why should this legal action be cancelled?', '', { title: 'Cancel Action', inputLabel: 'Cancellation Reason', confirmLabel: 'Cancel Action' });
        if (reason === null) return;
        await window.FAMApi.request(api(`legal/cancel-action.php?matter_id=${matterId}&action_id=${actionId}`), { method: 'POST', body: { cancellation_reason: reason } });
        window.FAMModal?.showToast?.('Legal action cancelled.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(matterId);
    }

    async function acceptPartySuggestion(matterId, suggestionId) {
        await window.FAMApi.request(api(`legal/accept-party-suggestion.php?suggestion_id=${suggestionId}&matter_id=${matterId}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('Party suggestion accepted.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(matterId);
    }

    async function dismissParty(matterId, partyId) {
        const reason = await window.FAMModal.prompt('Why should this party be dismissed?', '', { title: 'Dismiss Party', inputLabel: 'Dismissal Reason', confirmLabel: 'Dismiss' });
        if (reason === null) return;
        await window.FAMApi.request(api(`legal/dismiss-party.php?matter_id=${matterId}&party_id=${partyId}`), { method: 'POST', body: { reason } });
        window.FAMModal?.showToast?.('Party dismissed.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(matterId);
    }

    async function dismissPartySuggestion(matterId, suggestionId) {
        if (!await window.FAMModal.confirm('Dismiss this AI party suggestion?', { title: 'Dismiss Party Suggestion', confirmLabel: 'Dismiss' })) return;
        await window.FAMApi.request(api(`legal/dismiss-party-suggestion.php?suggestion_id=${suggestionId}&matter_id=${matterId}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('Party suggestion dismissed.');
        await load();
        if (qs('#legal-details-modal')?.hidden === false) await openDetails(matterId);
    }

    async function init() {
        await window.FAMApi.me();
        if (!can('legal.create')) qs('#legal-new-matter')?.classList.add('hidden');
        await loadOptions();
        bind();
        await load();
        const directId = new URLSearchParams(window.location.search).get('matter_id');
        if (directId) openDetails(Number(directId)).catch(console.error);
    }

    document.addEventListener('fam:layout-ready', () => init().catch(error => {
        console.error(error);
        qs('#legal-loading-state')?.classList.add('hidden');
        qs('#legal-empty-state').innerHTML = "<strong>We couldn't load legal matters.</strong><p>Please refresh the page and try again.</p>";
        qs('#legal-empty-state')?.classList.remove('hidden');
    }));
})();
