(function () {
    const sections = [
        ['visitors', 'Visitors', 'block'],
        ['legal', 'Legal', 'policy'],
    ];

    const state = {
        active: 'visitors',
        blacklist: { query: '', candidates: [], selected: null, entries: [], reason: '', loading: false },
        rules: { items: [], categories: [], statuses: [], search: '', category: 'all', status: 'all', loading: false, loaded: false, error: '' },
        lastFocus: null,
        bound: false,
    };

    const qs = selector => document.querySelector(selector);
    const api = path => `../api/${path}`;
    const can = permission => (window.FAMApi?.currentUser?.permissions || []).includes(permission);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function isBlacklistAdmin() {
        const roles = window.FAMApi?.currentUser?.roles || [];
        return can('administration.view') && roles.some(role => String(role?.code || '').toUpperCase() === 'FAM_SUPER_ADMIN');
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
    }

    function fmt(value) {
        if (!value) return 'Not available';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function fmtDate(value) {
        if (!value) return 'Not set';
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function badge(value, kind = 'status') {
        const raw = String(value || '');
        const cls = raw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-${kind}-${cls}">${esc(title(raw))}</span>`;
    }

    function moveToTopLayer(element) {
        if (element && element.parentElement !== document.body) document.body.appendChild(element);
        return element;
    }

    function sectionHeader(titleText, description) {
        return `<div class="admin-panel-header"><div><p>Administrative Settings</p><h2>${esc(titleText)}</h2><span>${esc(description)}</span></div></div>`;
    }

    function stateBlock(message, icon = 'info') {
        return `<div class="fam-state" role="status"><span class="material-symbols-outlined" aria-hidden="true">${esc(icon)}</span><span>${esc(message)}</span></div>`;
    }

    function formPayload(form) {
        const data = {};
        new FormData(form).forEach((value, key) => { data[key] = value; });
        return data;
    }

    function field(name, label, value = '', type = 'text', required = false) {
        return `<label class="facility-field"><span>${esc(label)}</span><input name="${esc(name)}" type="${esc(type)}" value="${esc(value)}" ${required ? 'required' : ''}></label>`;
    }

    function detail(label, value) {
        return `<article><span>${esc(label)}</span><strong>${esc(value || 'Not available')}</strong></article>`;
    }

    function clearError(form) {
        const target = form.querySelector('[data-settings-error]');
        if (target) {
            target.textContent = '';
            target.classList.add('hidden');
        }
    }

    function showError(form, error) {
        const target = form.querySelector('[data-settings-error]');
        if (!target) {
            window.FAMModal?.showToast?.(error.message || 'Unable to save changes.');
            return;
        }
        target.textContent = error.message || 'Unable to save changes.';
        target.classList.remove('hidden');
    }

    function setSubmitting(form, submitting) {
        form.dataset.submitting = submitting ? 'true' : 'false';
        form.querySelectorAll('button, input, select, textarea').forEach(control => {
            control.disabled = submitting;
        });
    }

    function renderNavigation() {
        const nav = qs('#admin-category-nav');
        const select = qs('#admin-category-select');
        if (!nav || !select) return;
        nav.innerHTML = sections.map(([key, label, icon]) => `<button class="${state.active === key ? 'active' : ''}" type="button" data-admin-section="${esc(key)}"><span class="material-symbols-outlined" aria-hidden="true">${esc(icon)}</span><span>${esc(label)}</span></button>`).join('');
        select.innerHTML = sections.map(([key, label]) => `<option value="${esc(key)}" ${state.active === key ? 'selected' : ''}>${esc(label)}</option>`).join('');
    }

    function blacklistPanel() {
        if (!isBlacklistAdmin()) {
            return `${sectionHeader('Visitor Blacklist', 'Restrict visitor registration for blocked individuals.')}
                ${stateBlock('Visitor Blacklist requires FAM Super Admin access.', 'lock')}`;
        }

        const candidates = state.blacklist.candidates.map(candidate => `<button class="facility-text-button admin-blacklist-candidate" type="button" data-blacklist-select="${esc(candidate.id)}">
            <strong>${esc(candidate.name)}</strong><span>${esc(candidate.email || candidate.phone || 'No contact on file')}</span>
        </button>`).join('');
        const selected = state.blacklist.selected ? `<article class="fam-card admin-selected-visitor"><h3>${esc(state.blacklist.selected.name)}</h3><p>${esc(state.blacklist.selected.email || 'No email')} - ${esc(state.blacklist.selected.phone || 'No phone')}</p></article>` : '';
        const rows = state.blacklist.entries.map(entry => `<tr>
            <td>${esc(entry.name)}</td>
            <td>${esc(entry.email || 'Not available')}</td>
            <td>${esc(entry.phone || 'Not available')}</td>
            <td>${esc(entry.reason || 'No reason supplied')}</td>
            <td>${esc(fmt(entry.blacklisted_at))}</td>
            <td><button class="facility-text-button document-danger-action" type="button" data-blacklist-remove="${esc(entry.id)}">Unblacklist</button></td>
        </tr>`).join('');

        return `${sectionHeader('Visitor Blacklist', 'Block visitor self-registration and scanner entry for selected people.')}
            <div class="admin-blacklist-layout">
                <section class="fam-card admin-blacklist-search">
                    <h3>Add Visitor To Blacklist</h3>
                    <label class="facility-field"><span>Search Visitors</span><input id="blacklist-search" type="search" value="${esc(state.blacklist.query)}" placeholder="Name, email, or phone"></label>
                    <div class="admin-blacklist-results">${state.blacklist.loading ? stateBlock('Searching visitors...', 'progress_activity') : candidates || stateBlock('Search for an existing visitor record.', 'person_search')}</div>
                    ${selected}
                    <label class="facility-field document-full-field"><span>Blacklist Reason *</span><textarea id="blacklist-reason" rows="4" required>${esc(state.blacklist.reason)}</textarea></label>
                    <button class="btn-primary dashboard-action-button" type="button" data-blacklist-add ${state.blacklist.selected && state.blacklist.reason.trim() ? '' : 'disabled'}>Add To Blacklist</button>
                </section>
                <section class="fam-card admin-blacklist-table-card">
                    <div class="facility-table-header admin-inner-header"><div><h3>Blacklisted Visitors</h3><p>${state.blacklist.entries.length} active records</p></div></div>
                    <div class="facility-table-scroll"><table class="facility-requests-table"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Reason</th><th>Blacklisted</th><th>Actions</th></tr></thead><tbody>${rows || `<tr><td colspan="6">${stateBlock('No visitors are currently blacklisted.', 'block')}</td></tr>`}</tbody></table></div>
                </section>
            </div>`;
    }

    function rulesParams() {
        const params = new URLSearchParams({ action: 'list' });
        if (state.rules.search.trim()) params.set('search', state.rules.search.trim());
        if (state.rules.category !== 'all') params.set('category', state.rules.category);
        if (state.rules.status !== 'all') params.set('status', state.rules.status);
        return params;
    }

    function rulesStateRow(message, icon, spinning = false) {
        return `<tr class="fam-table-state-row"><td class="fam-table-state-cell" colspan="6"><div class="fam-state" role="status"><span class="material-symbols-outlined${spinning ? ' fam-spinner' : ''}" aria-hidden="true">${esc(icon)}</span><span>${esc(message)}</span></div></td></tr>`;
    }

    function rulesRows() {
        if (state.rules.loading) return rulesStateRow('Loading policies...', 'progress_activity', true);
        if (state.rules.error) return rulesStateRow(state.rules.error, 'error');
        if (!state.rules.items.length) return rulesStateRow('No Rules & Regulations match the current filters.', 'policy');
        const canManage = can('legal.manage');
        return state.rules.items.map(policy => `<tr>
            <td><button class="document-primary-cell legal-primary-cell" type="button" data-settings-rule-action="view-policy" data-policy-id="${esc(policy.id)}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">policy</span><span><strong>${esc(policy.policyCode)}</strong><small>${esc(policy.title)}</small></span></button></td>
            <td>${esc(policy.category || 'Uncategorized')}</td>
            <td>${badge(policy.status, 'status')}</td>
            <td>${esc(policy.provisionCount || 0)}${policy.latestVersion ? ` / latest v${esc(policy.latestVersion)}` : ''}</td>
            <td>${esc(fmt(policy.updatedAt))}</td>
            <td><div class="facility-action-menu"><button class="facility-action-toggle" type="button" data-settings-rule-menu-toggle="settings-rule-menu-${esc(policy.id)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(policy.policyCode)}">&#8942;</button><div id="settings-rule-menu-${esc(policy.id)}" class="facility-action-dropdown hidden" role="menu">
                <button type="button" role="menuitem" data-settings-rule-action="view-policy" data-policy-id="${esc(policy.id)}">View</button>
                ${canManage ? `<button type="button" role="menuitem" data-settings-rule-action="edit-policy" data-policy-id="${esc(policy.id)}">Edit</button><button type="button" role="menuitem" data-settings-rule-action="add-provision" data-policy-id="${esc(policy.id)}">Add Provision</button><button type="button" role="menuitem" class="document-danger-action" data-settings-rule-action="deactivate-policy" data-policy-id="${esc(policy.id)}">Deactivate</button>` : ''}
            </div></div></td>
        </tr>`).join('');
    }

    function legalPanel() {
        if (!can('legal.view')) {
            return `${sectionHeader('Rules & Regulations', 'Maintain policy and provision master data for Legal Management.')}
                ${stateBlock('Rules & Regulations requires Legal view access.', 'lock')}`;
        }
        return `${sectionHeader('Rules & Regulations', 'Maintain policy and provision master data for Legal Management.')}
            <div class="facility-table-card">
                <div class="facility-table-header">
                    <div><h3>Policies</h3><p>${state.rules.items.length} ${state.rules.items.length === 1 ? 'policy' : 'policies'}</p></div>
                    <div class="facility-header-controls legal-controls">
                        <label class="facility-field facility-search-field"><span class="sr-only">Search rules</span><input id="settings-rules-search" type="search" value="${esc(state.rules.search)}" placeholder="Search policies..."></label>
                        <label class="facility-field document-filter-field"><span class="sr-only">Rule category</span><select id="settings-rules-category-filter"><option value="all">All Categories</option>${state.rules.categories.map(value => `<option value="${esc(value)}" ${state.rules.category === value ? 'selected' : ''}>${esc(value)}</option>`).join('')}</select></label>
                        <label class="facility-field document-filter-field"><span class="sr-only">Rule status</span><select id="settings-rules-status-filter"><option value="all">All Statuses</option>${state.rules.statuses.map(value => `<option value="${esc(value)}" ${state.rules.status === value ? 'selected' : ''}>${esc(title(value))}</option>`).join('')}</select></label>
                        ${can('legal.manage') ? '<button class="btn-secondary dashboard-action-button" type="button" data-settings-rule-action="create-policy"><span class="material-symbols-outlined" aria-hidden="true">add_circle</span>Add Policy</button>' : ''}
                    </div>
                </div>
                <div class="facility-table-scroll">
                    <table class="facility-requests-table legal-rules-table">
                        <thead><tr><th>Policy</th><th>Category</th><th>Status</th><th>Provisions</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody>${rulesRows()}</tbody>
                    </table>
                </div>
            </div>`;
    }

    function renderPanel() {
        const panel = qs('#admin-panel-card');
        if (!panel) return;
        panel.innerHTML = state.active === 'legal' ? legalPanel() : blacklistPanel();
    }

    function render() {
        renderNavigation();
        renderPanel();
    }

    async function loadBlacklist() {
        if (!isBlacklistAdmin()) return;
        const payload = await window.FAMApi.request(api('settings/visitor-blacklist.php'));
        state.blacklist.entries = payload.data?.items || [];
        if (state.active === 'visitors') renderPanel();
    }

    async function searchVisitors() {
        if (!isBlacklistAdmin()) return;
        const query = state.blacklist.query.trim();
        state.blacklist.candidates = [];
        state.blacklist.selected = null;
        if (query.length < 2) {
            renderPanel();
            return;
        }
        state.blacklist.loading = true;
        renderPanel();
        try {
            const payload = await window.FAMApi.request(api(`settings/visitor-search.php?search=${encodeURIComponent(query)}`));
            state.blacklist.candidates = payload.data?.items || [];
        } finally {
            state.blacklist.loading = false;
            if (state.active === 'visitors') renderPanel();
        }
    }

    async function addBlacklistEntry() {
        if (!state.blacklist.selected || !state.blacklist.reason.trim()) return;
        await window.FAMApi.request(api('settings/visitor-blacklist.php'), {
            method: 'POST',
            body: { visitor_id: state.blacklist.selected.id, reason: state.blacklist.reason.trim() },
        });
        window.FAMModal?.showToast?.('Visitor blacklisted.');
        state.blacklist = { ...state.blacklist, query: '', candidates: [], selected: null, reason: '' };
        await loadBlacklist();
    }

    async function removeBlacklistEntry(id) {
        if (!await window.FAMModal.confirm('Remove this visitor from the blacklist?', { title: 'Unblacklist Visitor', confirmLabel: 'Unblacklist' })) return;
        await window.FAMApi.request(api(`settings/visitor-blacklist.php?id=${id}`), { method: 'DELETE' });
        window.FAMModal?.showToast?.('Visitor removed from blacklist.');
        await loadBlacklist();
    }

    async function loadRules() {
        if (!can('legal.view')) return;
        state.rules.loading = true;
        state.rules.error = '';
        renderPanel();
        try {
            const payload = await window.FAMApi.request(api(`legal/rules.php?${rulesParams()}`));
            const data = payload.data || {};
            state.rules.items = data.items || [];
            state.rules.categories = data.categories || [];
            state.rules.statuses = data.statuses || [];
            state.rules.loaded = true;
        } catch (error) {
            state.rules.error = error.message || 'Unable to load Rules & Regulations.';
        } finally {
            state.rules.loading = false;
            if (state.active === 'legal') renderPanel();
        }
    }

    function openDialog(html) {
        const modal = moveToTopLayer(qs('#admin-dialog'));
        if (!modal) return;
        state.lastFocus = document.activeElement;
        modal.hidden = false;
        modal.innerHTML = html;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function closeDialog() {
        const modal = qs('#admin-dialog');
        if (modal) {
            modal.hidden = true;
            modal.innerHTML = '';
        }
        document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
        state.lastFocus?.focus?.();
        state.lastFocus = null;
    }

    async function openPolicyDetails(policyId) {
        const payload = await window.FAMApi.request(api(`legal/rules.php?action=show&policy_id=${policyId}`));
        const policy = payload.data?.item;
        if (!policy) return;
        openDialog(`<div class="facility-dialog-panel legal-form">
            <div class="facility-details-modal-header"><div><p>Rules &amp; Regulations</p><span class="facility-details-modal-request-number">${esc(policy.policyCode)}</span><h2>${esc(policy.title)}</h2></div><button class="facility-details-modal-close" type="button" data-settings-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><section class="document-form-section">
                <div class="contract-detail-grid">${detail('Category', policy.category || 'Uncategorized')}${detail('Status', title(policy.status))}${detail('Source Document', policy.sourceDocument || 'Not linked')}${detail('Updated', fmt(policy.updatedAt))}</div>
                <div class="legal-action-list">${(policy.provisions || []).map(provision => `<article class="legal-action-entry"><div class="legal-party-summary"><div class="legal-party-copy"><strong>${esc(provision.provisionCode)} v${esc(provision.versionNumber)}</strong><span>${esc(provision.sectionTitle || 'Untitled provision')} &middot; ${esc(title(provision.status))}</span><p>${esc(provision.provisionText)}</p><small>${provision.effectiveFrom ? `From ${esc(fmtDate(provision.effectiveFrom))}` : 'No start date'}${provision.effectiveUntil ? ` / Until ${esc(fmtDate(provision.effectiveUntil))}` : ''}</small></div>${can('legal.manage') ? `<div class="legal-party-actions"><button class="btn-secondary dashboard-action-button" type="button" data-settings-rule-action="revise-provision" data-policy-id="${esc(policy.id)}" data-provision-id="${esc(provision.id)}">Revise</button><button class="btn-secondary dashboard-action-button document-danger-action" type="button" data-settings-rule-action="deactivate-provision" data-policy-id="${esc(policy.id)}" data-provision-id="${esc(provision.id)}">Deactivate</button></div>` : ''}</div></article>`).join('') || '<p class="legal-empty-note">No provisions yet.</p>'}</div>
            </section></div>
            <div class="facility-dialog-actions">${can('legal.manage') ? `<button class="btn-secondary dashboard-action-button" type="button" data-settings-rule-action="add-provision" data-policy-id="${esc(policy.id)}">Add Provision</button>` : ''}<button class="btn-primary dashboard-action-button" type="button" data-settings-dialog-close>Done</button></div>
        </div>`);
    }

    async function openPolicyForm(policyId = null) {
        const policy = policyId ? (await window.FAMApi.request(api(`legal/rules.php?action=show&policy_id=${policyId}`))).data?.item : null;
        openDialog(`<form class="facility-dialog-panel legal-form" data-settings-form="${policy ? 'update-policy' : 'create-policy'}" data-policy-id="${esc(policy?.id || '')}">
            <div class="facility-details-modal-header"><div><p>Rules &amp; Regulations</p><h2>${policy ? 'Edit Policy' : 'Add Policy'}</h2></div><button class="facility-details-modal-close" type="button" data-settings-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><div class="document-form-error hidden" data-settings-error></div><section class="document-form-section">
                <div class="document-form-grid">
                    ${field('policy_code', 'Policy Code *', policy?.policyCode || '', 'text', true)}
                    ${field('title', 'Title *', policy?.title || '', 'text', true)}
                    ${field('category', 'Category', policy?.category || '', 'text', false)}
                    ${field('source_document_id', 'Source Document ID', policy?.sourceDocumentId || '', 'number', false)}
                    ${field('source_document_version_id', 'Source Version ID', policy?.sourceDocumentVersionId || '', 'number', false)}
                </div>
            </section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-settings-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">${policy ? 'Save Policy' : 'Create Policy'}</button></div>
        </form>`);
    }

    async function openProvisionForm(policyId, provisionId = null) {
        const policy = (await window.FAMApi.request(api(`legal/rules.php?action=show&policy_id=${policyId}`))).data?.item;
        const provision = (policy?.provisions || []).find(item => Number(item.id) === Number(provisionId));
        const revising = Boolean(provision);
        openDialog(`<form class="facility-dialog-panel legal-form" data-settings-form="${revising ? 'revise-provision' : 'add-provision'}" data-policy-id="${esc(policyId)}" data-provision-id="${esc(provisionId || '')}">
            <div class="facility-details-modal-header"><div><p>Rules &amp; Regulations</p><span class="facility-details-modal-request-number">${esc(policy?.policyCode || '')}</span><h2>${revising ? 'Revise Provision' : 'Add Provision'}</h2></div><button class="facility-details-modal-close" type="button" data-settings-dialog-close aria-label="Close form">&times;</button></div>
            <div class="facility-dialog-body legal-form-body"><div class="document-form-error hidden" data-settings-error></div><section class="document-form-section">
                <div class="document-form-grid">
                    ${field('provision_code', 'Provision Code *', provision?.provisionCode || '', 'text', true)}
                    ${field('section_title', 'Section / Title', provision?.sectionTitle || '', 'text', false)}
                    ${field('effective_from', 'Effective From', '', 'date', false)}
                    ${field('effective_until', 'Effective Until', '', 'date', false)}
                    ${field('source_document_id', 'Source Document ID', provision?.sourceDocumentId || policy?.sourceDocumentId || '', 'number', false)}
                    ${field('source_document_version_id', 'Source Version ID', provision?.sourceDocumentVersionId || policy?.sourceDocumentVersionId || '', 'number', false)}
                </div>
                <label class="facility-field document-full-field"><span>Provision Text *</span><textarea name="provision_text" rows="6" required>${esc(provision?.provisionText || '')}</textarea></label>
            </section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-settings-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">${revising ? 'Create Revised Version' : 'Add Provision'}</button></div>
        </form>`);
    }

    async function submitSettingsForm(form) {
        if (form.dataset.submitting === 'true' || !can('legal.manage')) return;
        const type = form.dataset.settingsForm;
        const policyId = form.dataset.policyId;
        const provisionId = form.dataset.provisionId;
        const paths = {
            'create-policy': 'legal/rules.php?action=create-policy',
            'update-policy': `legal/rules.php?action=update-policy&policy_id=${policyId}`,
            'add-provision': `legal/rules.php?action=add-provision&policy_id=${policyId}`,
            'revise-provision': `legal/rules.php?action=revise-provision&provision_id=${provisionId}`,
        };
        const path = paths[type];
        if (!path) return;
        setSubmitting(form, true);
        clearError(form);
        try {
            await window.FAMApi.request(api(path), { method: 'POST', body: formPayload(form) });
            closeDialog();
            window.FAMModal?.showToast?.('Rules & Regulations updated.');
            await loadRules();
            if (['add-provision', 'revise-provision'].includes(type) && policyId) await openPolicyDetails(policyId);
        } catch (error) {
            showError(form, error);
        } finally {
            if (form.isConnected) setSubmitting(form, false);
        }
    }

    async function deactivatePolicy(policyId) {
        if (!can('legal.manage')) return;
        if (!await window.FAMModal.confirm('Deactivate this policy? Historical Legal Matter snapshots will remain unchanged.', { title: 'Deactivate Policy', confirmLabel: 'Deactivate' })) return;
        await window.FAMApi.request(api(`legal/rules.php?action=deactivate-policy&policy_id=${policyId}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('Policy deactivated.');
        await loadRules();
    }

    async function deactivateProvision(provisionId, policyId) {
        if (!can('legal.manage')) return;
        if (!await window.FAMModal.confirm('Deactivate this provision? Historical Legal Matter snapshots will remain unchanged.', { title: 'Deactivate Provision', confirmLabel: 'Deactivate' })) return;
        await window.FAMApi.request(api(`legal/rules.php?action=deactivate-provision&provision_id=${provisionId}`), { method: 'POST', body: {} });
        window.FAMModal?.showToast?.('Provision deactivated.');
        await loadRules();
        if (policyId) await openPolicyDetails(policyId);
    }

    async function activateSection(key) {
        state.active = key;
        render();
        if (key === 'visitors') await loadBlacklist();
        if (key === 'legal' && can('legal.view') && !state.rules.loaded) await loadRules();
    }

    function bind() {
        if (state.bound) return;
        state.bound = true;
        document.addEventListener('click', event => {
            const sectionButton = event.target.closest('[data-admin-section]');
            if (sectionButton) {
                activateSection(sectionButton.dataset.adminSection).catch(console.error);
                return;
            }
            const select = event.target.closest('[data-blacklist-select]');
            if (select) {
                state.blacklist.selected = state.blacklist.candidates.find(candidate => Number(candidate.id) === Number(select.dataset.blacklistSelect)) || null;
                renderPanel();
                return;
            }
            const add = event.target.closest('[data-blacklist-add]');
            if (add) {
                addBlacklistEntry().catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to blacklist visitor.'));
                return;
            }
            const remove = event.target.closest('[data-blacklist-remove]');
            if (remove) {
                removeBlacklistEntry(Number(remove.dataset.blacklistRemove)).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to remove visitor from blacklist.'));
                return;
            }
            if (event.target.closest('[data-settings-dialog-close]')) {
                closeDialog();
                return;
            }
            const toggle = event.target.closest('[data-settings-rule-menu-toggle]');
            if (toggle) {
                window.FAMTableMenus?.toggle(toggle, document.getElementById(toggle.dataset.settingsRuleMenuToggle));
                return;
            }
            const action = event.target.closest('[data-settings-rule-action]');
            if (!action) return;
            window.FAMTableMenus?.close();
            const policyId = Number(action.dataset.policyId || 0);
            const provisionId = Number(action.dataset.provisionId || 0);
            const type = action.dataset.settingsRuleAction;
            const manageActions = ['create-policy', 'edit-policy', 'add-provision', 'revise-provision', 'deactivate-policy', 'deactivate-provision'];
            if (manageActions.includes(type) && !can('legal.manage')) return;
            if (type === 'create-policy') openPolicyForm().catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to add policy.'));
            if (type === 'view-policy') openPolicyDetails(policyId).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to open policy.'));
            if (type === 'edit-policy') openPolicyForm(policyId).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to edit policy.'));
            if (type === 'add-provision') openProvisionForm(policyId).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to add provision.'));
            if (type === 'revise-provision') openProvisionForm(policyId, provisionId).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to revise provision.'));
            if (type === 'deactivate-policy') deactivatePolicy(policyId).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to deactivate policy.'));
            if (type === 'deactivate-provision') deactivateProvision(provisionId, policyId).catch(error => window.FAMModal?.showToast?.(error.message || 'Unable to deactivate provision.'));
        });
        document.addEventListener('input', event => {
            if (event.target.matches('#blacklist-search')) {
                state.blacklist.query = event.target.value;
                window.clearTimeout(state.blacklistTimer);
                state.blacklistTimer = window.setTimeout(() => searchVisitors().catch(console.error), 250);
            }
            if (event.target.matches('#blacklist-reason')) {
                state.blacklist.reason = event.target.value;
                renderPanel();
            }
            if (event.target.matches('#settings-rules-search')) {
                state.rules.search = event.target.value;
                window.clearTimeout(state.rulesTimer);
                state.rulesTimer = window.setTimeout(() => loadRules().catch(console.error), 250);
            }
        });
        document.addEventListener('change', event => {
            if (event.target.matches('#admin-category-select')) activateSection(event.target.value).catch(console.error);
            if (event.target.matches('#settings-rules-category-filter')) {
                state.rules.category = event.target.value;
                loadRules().catch(console.error);
            }
            if (event.target.matches('#settings-rules-status-filter')) {
                state.rules.status = event.target.value;
                loadRules().catch(console.error);
            }
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-settings-form]');
            if (!form) return;
            event.preventDefault();
            submitSettingsForm(form).catch(console.error);
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || event.defaultPrevented) return;
            if (qs('#admin-dialog')?.hidden === false) {
                event.preventDefault();
                closeDialog();
            }
        });
    }

    document.addEventListener('fam:layout-ready', async () => {
        try {
            await window.FAMApi.me();
            if (!can('administration.view')) {
                window.location.replace('../errors/403.html');
                return;
            }
            if (!isBlacklistAdmin() && can('legal.view')) state.active = 'legal';
            qs('#admin-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
            render();
            bind();
            if (state.active === 'visitors') await loadBlacklist();
            if (state.active === 'legal') await loadRules();
        } catch (error) {
            if (String(error.message || '').includes('403')) window.location.href = '../errors/403.html';
            else window.FAMModal?.showToast?.(error.message || 'Unable to load Administrative Settings.');
        }
    });
})();
