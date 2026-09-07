(function () {
    const state = { page: 1, totalPages: 1, sort: 'disposition_date', direction: 'asc', options: {}, activeItem: null, activeRecommendation: null, analysisByRecord: {} };
    const qs = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const api = path => `${window.location.origin}${window.FAMNavigation?.appBasePath?.() || '/'}api/${path}`;
    const can = permission => (window.FAMApi?.currentUser?.permissions || []).includes(permission);

    function moveToTopLayer(element) {
        if (element && element.parentElement !== document.body) document.body.appendChild(element);
        return element;
    }

    function title(value) {
        return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    function retentionStatusLabel(value) {
        return {
            WAITING_FOR_TRIGGER: 'Waiting Trigger',
            ACTIVE: 'Active',
            DUE_FOR_REVIEW: 'Due for Review',
            OVERDUE: 'Overdue',
            ON_HOLD: 'Legal Hold',
            ARCHIVED: 'Archived',
            DISPOSED: 'Disposed',
            PERMANENT: 'Permanent',
        }[String(value || '').toUpperCase()] || title(value);
    }

    function fmtDate(value) {
        if (!value) return 'Not set';
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function fmtPolicyDate(value, emptyText = 'Not calculated') {
        return value ? fmtDate(value) : emptyText;
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

    function retentionStatusBadge(value) {
        const raw = String(value || 'ACTIVE').toUpperCase();
        const cls = raw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        return `<span class="facility-badge facility-status-${cls}">${esc(retentionStatusLabel(raw))}</span>`;
    }

    function params() {
        const p = new URLSearchParams({ page: state.page, per_page: 10, sort: state.sort, direction: state.direction });
        const search = qs('#retention-search')?.value.trim();
        if (search) p.set('search', search);
        const map = {
            '#retention-schedule-filter': 'schedule_id',
            '#retention-status-filter': 'status',
            '#retention-hold-filter': 'legal_hold_status',
        };
        Object.entries(map).forEach(([selector, key]) => {
            const value = qs(selector)?.value;
            if (value && value !== 'all') p.set(key, value);
        });
        return p;
    }

    function exportUrl() {
        const p = new URLSearchParams({ report: 'documents_records', source: 'records' });
        const search = qs('#retention-search')?.value.trim();
        const schedule = qs('#retention-schedule-filter')?.value;
        const status = qs('#retention-status-filter')?.value;
        const hold = qs('#retention-hold-filter')?.value;
        if (search) p.set('search', search);
        if (schedule && schedule !== 'all') p.set('retention_schedule_id', schedule);
        if (status && status !== 'all') p.set('status', status);
        if (hold && hold !== 'all') p.set('legal_hold_status', hold);
        return `../api/reports/export-csv.php?${p}`;
    }

    async function load() {
        qs('#retention-table-count').textContent = 'Loading retention records...';
        qs('#retention-table')?.closest('.facility-table-card')?.setAttribute('aria-busy', 'true');
        qs('#retention-refresh')?.setAttribute('aria-busy', 'true');
        qs('#retention-refresh')?.setAttribute('disabled', 'disabled');
        qs('#retention-loading-state')?.classList.remove('hidden');
        try {
            const payload = await window.FAMApi.request(api(`retention/index.php?${params()}`));
            const data = payload.data || {};
            renderSummary(data.summary || {});
            renderRows(data.items || []);
            renderPagination(data.pagination || {});
            qs('#retention-updated').textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
        } finally {
            qs('#retention-loading-state')?.classList.add('hidden');
            qs('#retention-table')?.closest('.facility-table-card')?.removeAttribute('aria-busy');
            qs('#retention-refresh')?.removeAttribute('aria-busy');
            qs('#retention-refresh')?.removeAttribute('disabled');
        }
    }

    function renderSummary(summary) {
        qs('#retention-active-count').textContent = summary.active ?? 0;
        qs('#retention-waiting-count').textContent = summary.waiting ?? 0;
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
            <td><button class="document-primary-cell" type="button" data-retention-action="view" data-retention-id="${row.id}"><span class="material-symbols-outlined document-file-icon" aria-hidden="true">inventory</span><span><strong title="${esc(row.title)}">${esc(row.title)}</strong><small title="${esc(row.recordNo)}">${esc(row.recordNo)}</small></span></button></td>
            <td>${esc(row.category)}</td>
            <td><span class="table-cell-primary" title="${esc(row.schedule?.name || 'Unassigned')}">${esc(row.schedule?.name || 'Unassigned')}</span><span class="table-cell-secondary">${esc(period(row.schedule))}</span></td>
            <td>${reviewCell(row)}</td>
            <td>${holdCell(row)}</td>
            <td>${retentionStatusBadge(row.retentionStatus || row.dueState)}</td>
            <td>${actions(row)}</td>
        </tr>`).join('');
    }

    function reviewPrimary(row) {
        if (row.dueState === 'PERMANENT') return 'Permanent';
        if (row.dueState === 'WAITING_FOR_TRIGGER') return 'Not calculated';
        return row.effectiveReviewDate ? fmtDate(row.effectiveReviewDate) : 'Not set';
    }

    function reviewCell(row) {
        const secondary = reviewSecondary(row);
        return `<span class="table-cell-primary">${esc(reviewPrimary(row))}</span>${secondary ? `<span class="table-cell-secondary">${esc(secondary)}</span>` : ''}`;
    }

    function reviewSecondary(row) {
        if (row.dueState === 'PERMANENT') return 'No disposition date';
        if (row.dueState === 'WAITING_FOR_TRIGGER') return 'Waiting for retention trigger';
        if (!row.effectiveReviewDate) {
            if (scheduleMissing(row)) return 'Assign retention schedule';
            if (!row.retentionTriggerDate) return 'Set retention trigger date';
            return '';
        }
        if (row.dueState === 'OVERDUE') return overdueText(row.effectiveReviewDate);
        if (row.dueState === 'DUE_FOR_REVIEW') return 'Due for review';
        if (row.dueState === 'ON_HOLD') return 'On hold';
        if (row.dueState === 'ARCHIVED' || row.dueState === 'DISPOSED') return title(row.dueState);
        return 'Not due';
    }

    function scheduleMissing(row) {
        const schedule = row.schedule || {};
        const name = String(schedule.name || '').trim().toLowerCase();
        return !Number(schedule.id || 0) || !name || name === 'unassigned';
    }

    function overdueText(value) {
        const due = new Date(`${value}T00:00:00`);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (Number.isNaN(due.getTime())) return 'Overdue';
        const days = Math.max(1, Math.floor((today - due) / 86400000));
        return `Overdue by ${days} ${days === 1 ? 'day' : 'days'}`;
    }

    function holdCell(row) {
        if (row.legalHoldStatus === 'ACTIVE') {
            return badge('On Hold', 'status');
        }
        return '<span class="retention-muted">None</span>';
    }

    function actions(row) {
        const items = [`<button type="button" data-retention-action="view" data-retention-id="${row.id}">View Details</button>`];
        const allowed = new Set(row.allowedActions || []);
        if (allowed.has('analyze') && can('retention.review')) items.push(`<button type="button" data-retention-action="analyze-recommendation" data-retention-id="${row.id}">${row.hasDispositionRecommendation ? 'Re-analyze Disposition' : 'Analyze Disposition'}</button>`);
        if (allowed.has('assign') && can('retention.assign')) items.push(`<button type="button" data-retention-action="assign" data-retention-id="${row.id}">Assign Schedule</button>`);
        if (allowed.has('extend') && can('retention.extend')) items.push(`<button type="button" data-retention-action="extend" data-retention-id="${row.id}">Extend Review Date</button>`);
        if (allowed.has('release-hold') && can('retention.legal_hold')) items.push(`<button type="button" data-retention-action="release-hold" data-retention-id="${row.id}">Release Legal Hold</button>`);
        if (allowed.has('place-hold') && can('retention.legal_hold')) items.push(`<button type="button" data-retention-action="place-hold" data-retention-id="${row.id}">Place Legal Hold</button>`);
        if (allowed.has('archive') && can('retention.archive')) items.push('<hr aria-hidden="true">', `<button type="button" data-retention-action="archive" data-retention-id="${row.id}">Archive Record</button>`);
        if (allowed.has('dispose') && can('retention.dispose')) items.push(`<button class="document-danger-action" type="button" data-retention-action="dispose" data-retention-id="${row.id}">Dispose Record</button>`);
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
        fillStatus(qs('#retention-status-filter'), state.options.statuses || state.options.due_states || [], 'All Statuses');
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

    function fillStatus(select, items, first) {
        if (!select) return;
        select.innerHTML = `<option value="all">${esc(first)}</option>` + items.map(item => `<option value="${esc(item)}">${esc(retentionStatusLabel(item))}</option>`).join('');
    }

    async function openDetails(id) {
        const [item, recommendation] = await Promise.all([fetchItem(id), fetchRecommendation(id)]);
        state.activeItem = item;
        state.activeRecommendation = recommendation;
        const modal = moveToTopLayer(qs('#retention-details-modal'));
        modal.hidden = false;
        const recordInfo = detailGrid(`${detail('Record Number', item.recordNo)}${detail('Record Status', title(item.recordStatus))}${detail('Category', item.category)}${detail('Confidentiality', title(item.confidentiality))}${detail('Source Module', title(item.sourceModule))}${detail('Department', item.department)}${detail('Owner', item.owner)}${item.description ? detail('Description', item.description, 'detail-item--full') : ''}`);
        const showEffectiveReview = item.effectiveReviewDate && item.effectiveReviewDate !== item.policyEligibilityDate;
        const scheduleInfo = detailGrid(`${detail('Schedule', item.schedule?.name)}${detail('Schedule Code', item.schedule?.code)}${detail('Trigger Basis', item.retentionTriggerLabel || item.schedule?.triggerLabel)}${detail('Trigger Date', fmtPolicyDate(item.retentionTriggerDate, 'Not available'))}${detail('Retention Period', period(item.schedule))}${detail('Policy Eligibility Date', fmtPolicyDate(item.policyEligibilityDate))}${detail('Policy Status', policyStatus(item))}${showEffectiveReview ? detail('Effective Review Date', fmtDate(item.effectiveReviewDate)) : ''}${item.administrativeReviewDateOverride ? detail('Administrative Review Override', fmtDate(item.administrativeReviewDateOverride)) : ''}${detail('Disposition Rule', title(item.schedule?.dispositionAction))}${detail('Legal Basis', item.schedule?.legalBasis, 'detail-item--full')}`);
        const complianceInfo = detailGrid(`${detail('Review State', retentionStatusLabel(item.retentionStatus || item.dueState))}${detail('Legal Hold', title(item.legalHoldStatus))}${item.legalHoldReason ? detail('Hold Reason', item.legalHoldReason, 'detail-item--full') : ''}${item.dispositionReason ? detail('Disposition Reason', item.dispositionReason, 'detail-item--full') : ''}`);
        const recommendationHtml = recommendationSection(recommendation, item, state.analysisByRecord[item.id] || null);
        modal.innerHTML = `<div class="facility-details-modal-panel visitor-details-panel retention-details-panel">
            <div class="facility-details-modal-header visitor-details-header">
                <div><p>Record Reference Number</p><span class="facility-details-modal-request-number">${esc(item.recordNo)}</span><h2>${esc(item.title)}</h2></div>
                <button class="facility-details-modal-close" type="button" data-retention-details-close aria-label="Close details">&times;</button>
            </div>
            <div class="facility-details-modal-body visitor-details-body">
                <div class="visitor-detail-accordion retention-detail-accordion">
                    ${recommendationHtml}
                    <details class="visitor-detail-disclosure" open><summary>Record Information</summary>${recordInfo}</details>
                    <details class="visitor-detail-disclosure" open><summary>Retention Schedule</summary>${scheduleInfo}</details>
                    <details class="visitor-detail-disclosure" open><summary>Compliance Status</summary>${complianceInfo}</details>
                    ${(item.documents || []).length ? `<details class="visitor-detail-disclosure" open><summary>Related Documents</summary><div class="retention-related-list">${item.documents.map(documentRow).join('')}</div></details>` : ''}
                    ${(item.history || []).length ? `<details class="visitor-detail-disclosure"><summary>Activity History</summary><ol class="facility-history-list">${item.history.map(historyRow).join('')}</ol></details>` : ''}
                </div>
            </div>
            <div class="facility-dialog-actions"><div></div><button class="btn-secondary dashboard-action-button" type="button" data-retention-details-close>Done</button></div>
        </div>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('[data-retention-details-close]')?.focus();
    }

    async function fetchItem(id) {
        const payload = await window.FAMApi.request(api(`retention/show.php?id=${id}`));
        return payload.data?.item;
    }

    async function fetchRecommendation(id) {
        const payload = await window.FAMApi.request(api(`retention/recommendation.php?id=${id}`));
        return payload.data?.recommendation || null;
    }

    function recommendationSection(recommendation, item, analysis = null) {
        const allowed = new Set(item?.allowedActions || []);
        const canAnalyze = can('retention.review') && allowed.has('analyze');
        const analyzeLabel = recommendation ? 'Re-analyze Disposition' : 'Analyze Disposition';
        const analysisBlocked = item?.dueState === 'WAITING_FOR_TRIGGER';
        const actions = canAnalyze ? `<div class="retention-ai-actions"><button class="btn-secondary dashboard-action-button" type="button" data-retention-action="analyze-recommendation" data-retention-id="${item.id}">${analyzeLabel}</button>${recommendation?.status === 'PENDING' ? `<button class="btn-primary dashboard-action-button" type="button" data-retention-action="review-recommendation" data-retention-recommendation-id="${recommendation.id}">Review Recommendation</button>` : ''}</div>` : '';
        if (!recommendation) {
            const isUnavailable = analysis?.status === 'UNAVAILABLE';
            const isPolicyState = analysis && !isUnavailable;
            const cardTitle = isPolicyState ? 'Policy Status' : 'AI Recommendation';
            const heading = isUnavailable ? 'Unavailable' : (isPolicyState ? title(analysis.status) : (analysisBlocked ? 'Waiting for retention trigger' : 'No recommendation'));
            const message = analysis?.message || (analysisBlocked ? 'Disposition analysis is unavailable until the authoritative retention trigger date is established.' : 'No AI-assisted recommendation has been generated for this record.');
            return `<section class="retention-ai-card">
                <div class="retention-ai-card-header">
                    <div><h3>${esc(cardTitle)}</h3><p class="retention-muted">${esc(heading)}</p></div>
                    ${isUnavailable ? badge('Unavailable', 'status') : ''}
                </div>
                <p class="retention-ai-reason">${esc(message)}</p>
                ${actions}
            </section>`;
        }
        const flags = (recommendation.contextFlags || []).map(flag => `<span>${esc(title(flag))}</span>`).join('');
        return `<section class="retention-ai-card">
            <div class="retention-ai-card-header">
                <div><h3>AI Disposition Recommendation</h3><p class="retention-muted">${esc(recommendation.sourceProvider || 'System')} ${recommendation.evaluatedAt ? `&middot; ${esc(fmt(recommendation.evaluatedAt))}` : ''}</p></div>
                <div class="retention-ai-badges">${badge(recommendation.recommendedAction, 'status')}${badge(recommendation.status, 'status')}</div>
            </div>
            <p class="retention-ai-reason">${esc(recommendation.reason)}</p>
            <div class="retention-ai-meta">
                <span>Policy eligible: ${esc(fmtDate(recommendation.policyEligibleDate))}</span>
                <span>Hold: ${esc(title(recommendation.legalHoldStatus))}</span>
                <span>Needs review: ${recommendation.needsReview ? 'Yes' : 'No'}</span>
            </div>
            ${flags ? `<div class="retention-ai-flags">${flags}</div>` : ''}
            ${actions}
        </section>`;
    }

    function detail(label, value, className = '') {
        return value ? `<dl class="facility-detail-row ${esc(className)}"><dt>${esc(label)}</dt><dd>${esc(value)}</dd></dl>` : '';
    }

    function policyStatus(item) {
        if (item.dueState === 'WAITING_FOR_TRIGGER') return 'Waiting Trigger';
        if (item.dueState === 'PERMANENT') return 'Permanent retention';
        if (!item.policyEligibilityDate) return 'Not calculated';
        return retentionStatusLabel(item.retentionStatus || item.dueState || 'ACTIVE');
    }

    function detailGrid(content) {
        return `<div class="detail-grid">${content}</div>`;
    }

    function period(schedule = {}) {
        if (!schedule.periodUnit) return 'Not set';
        const unit = String(schedule.periodUnit).toUpperCase();
        if (unit === 'PERMANENT') return 'Permanent';
        const value = Number(schedule.periodValue || 0);
        const label = {
            DAY: 'day',
            DAYS: 'day',
            MONTH: 'month',
            MONTHS: 'month',
            YEAR: 'year',
            YEARS: 'year',
        }[unit] || unit.toLowerCase();
        return `${value} ${label}${value === 1 ? '' : 's'}`;
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
        state.activeRecommendation = null;
        if (qs('#retention-dialog')?.hidden !== false) document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
    }

    function openAssign(item) {
        const modal = moveToTopLayer(qs('#retention-dialog'));
        const schedules = (state.options.schedules || []).filter(s => s.status === 'ACTIVE');
        const selectedSchedule = schedules.find(s => Number(s.id) === Number(item.schedule?.id)) || schedules[0] || {};
        const isManual = isManualTriggerSchedule(selectedSchedule);
        const reasonRequired = requiresScheduleCorrectionReason(item);
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel retention-form" data-retention-form="assign">
            <div class="facility-details-modal-header"><div><p>Records Retention</p><h2>Assign Retention Schedule</h2></div><button class="facility-details-modal-close" type="button" data-retention-dialog-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body retention-form-body"><div class="document-form-error hidden" data-retention-error></div><section class="document-form-section"><h3>${esc(item.title)}</h3><div class="document-form-grid">
                <label class="facility-field"><span>Schedule *</span><select name="retention_schedule_id" required>${schedules.map(s => `<option value="${esc(s.id)}" data-trigger-basis="${esc(s.triggerBasis)}" data-trigger-label="${esc(s.triggerLabel)}" ${s.id === item.schedule?.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select></label>
                <label class="facility-field"><span>Trigger Basis</span><input name="trigger_basis_display" value="${esc(selectedSchedule.triggerLabel || item.retentionTriggerLabel || 'Not available')}" readonly></label>
                <label class="facility-field"><span>Trigger Date</span><input name="trigger_date_display" value="${esc(triggerPreview(item, isManual))}" readonly></label>
                <label class="facility-field"><span>Policy Status</span><input name="policy_status_display" value="${esc(assignPolicyStatus(item, isManual))}" readonly></label>
                <label class="facility-field document-full-field" data-manual-trigger-field ${isManual ? '' : 'hidden'}><span>Manual Trigger Date *</span><input name="retention_trigger_date" type="date" value="${esc(item.retentionTriggerDate || '')}" ${isManual ? 'required' : ''}></label>
            </div><label class="facility-field document-full-field"><span>Correction Reason${reasonRequired ? ' *' : ''}</span><textarea name="reason" rows="3" ${reasonRequired ? 'required' : ''} placeholder="${reasonRequired ? 'Required because this record already has resolved trigger or review activity.' : 'Optional unless correcting a resolved trigger or reviewed record.'}"></textarea></label><p class="retention-muted" data-trigger-help>${esc(triggerHelp(isManual))}</p></section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-retention-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Assign Schedule</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function openExtend(item) {
        const modal = moveToTopLayer(qs('#retention-dialog'));
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel retention-form" data-retention-form="extend">
            <div class="facility-details-modal-header"><div><p>Records Retention</p><h2>Extend Review Date</h2></div><button class="facility-details-modal-close" type="button" data-retention-dialog-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body retention-form-body"><div class="document-form-error hidden" data-retention-error></div><section class="document-form-section"><h3>${esc(item.title)}</h3><div class="document-form-grid">
                <label class="facility-field"><span>New Effective Review Date *</span><input name="scheduled_disposition_date" type="date" value="${esc(item.effectiveReviewDate || '')}" required></label>
            </div><label class="facility-field document-full-field"><span>Reason *</span><textarea name="reason" rows="3" required></textarea></label></section></div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-retention-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Extend Retention</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
        modal.querySelector('input[name="scheduled_disposition_date"]')?.focus();
    }

    function openRecommendationReview(recommendation) {
        if (!recommendation) return;
        const modal = moveToTopLayer(qs('#retention-dialog'));
        const permitted = finalActionOptions(state.activeItem || {}, recommendation);
        modal.hidden = false;
        modal.innerHTML = `<form class="facility-dialog-panel retention-form" data-retention-form="recommendation-review">
            <div class="facility-details-modal-header"><div><p>Records Retention</p><h2>Review AI Disposition Recommendation</h2></div><button class="facility-details-modal-close" type="button" data-retention-dialog-close aria-label="Close dialog">&times;</button></div>
            <div class="facility-dialog-body retention-form-body"><div class="document-form-error hidden" data-retention-error></div>
                <section class="document-form-section retention-recommendation-review">
                    <h3>${esc(state.activeItem?.title || 'Retention Record')}</h3>
                    <div class="detail-grid">${detail('Recommended Action', title(recommendation.recommendedAction))}${detail('Recommendation Status', title(recommendation.status))}${detail('Reason', recommendation.reason, 'detail-item--full')}</div>
                    <div class="document-form-grid">
                        <label class="facility-field"><span>Decision *</span><select name="decision" required><option value="APPROVED">Approve</option><option value="MODIFIED">Modify</option><option value="REJECTED">Reject</option></select></label>
                        <label class="facility-field"><span>Final Action *</span><select name="approved_action" required>${permitted.map(action => `<option value="${action}" ${action === recommendation.recommendedAction ? 'selected' : ''}>${title(action)}</option>`).join('')}</select></label>
                    </div>
                    <label class="facility-field document-full-field"><span>Review Reason</span><textarea name="review_reason" rows="3" placeholder="Required when modifying or rejecting the recommendation."></textarea></label>
                </section>
            </div>
            <div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-retention-dialog-close>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Submit Review</button></div>
        </form>`;
        document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
    }

    function finalActionOptions(item, recommendation) {
        const actions = new Set(['REVIEW']);
        (item.permittedDispositionActions || []).forEach(action => actions.add(String(action).toUpperCase()));
        if (String(recommendation?.recommendedAction || '').toUpperCase() === 'RETAIN') actions.add('RETAIN');
        return Array.from(actions).filter(action => action !== 'DISPOSE' || (item.permittedDispositionActions || []).includes('DISPOSE'));
    }

    function closeDialog() {
        const modal = qs('#retention-dialog');
        modal.hidden = true;
        modal.innerHTML = '';
        if (qs('#retention-details-modal')?.hidden !== false) document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
    }

    function updateAssignTriggerDisplay(select) {
        const option = select.selectedOptions?.[0];
        const form = select.closest('form');
        const basis = option?.dataset.triggerBasis || '';
        const label = option?.dataset.triggerLabel || title(basis);
        const display = form?.querySelector('input[name="trigger_basis_display"]');
        const triggerDisplay = form?.querySelector('input[name="trigger_date_display"]');
        const policyDisplay = form?.querySelector('input[name="policy_status_display"]');
        const field = form?.querySelector('[data-manual-trigger-field]');
        const input = form?.querySelector('input[name="retention_trigger_date"]');
        const help = form?.querySelector('[data-trigger-help]');
        if (display) display.value = label;
        const isManual = basis === 'MANUAL_TRIGGER';
        if (triggerDisplay) triggerDisplay.value = triggerPreview(state.activeItem || {}, isManual);
        if (policyDisplay) policyDisplay.value = assignPolicyStatus(state.activeItem || {}, isManual);
        if (field) field.hidden = !isManual;
        if (input) {
            input.required = isManual;
            if (!isManual) input.value = '';
        }
        if (help) help.textContent = triggerHelp(isManual);
    }

    function isManualTriggerSchedule(schedule) {
        return String(schedule?.triggerBasis || '').toUpperCase() === 'MANUAL_TRIGGER';
    }

    function selectedAssignScheduleIsManual(form) {
        const option = form?.querySelector('select[name="retention_schedule_id"]')?.selectedOptions?.[0];
        return String(option?.dataset.triggerBasis || '').toUpperCase() === 'MANUAL_TRIGGER';
    }

    function requiresScheduleCorrectionReason(item) {
        return Boolean(item?.policyEligibilityDate || item?.lastReviewedAt || String(item?.retentionTriggerState || '').toUpperCase() === 'RESOLVED');
    }

    function triggerHelp(isManual) {
        return isManual
            ? 'This schedule allows an authorized manual trigger date.'
            : 'Trigger date is resolved from the linked source event. This record will remain Waiting Trigger until that event is available.';
    }

    function triggerPreview(item, isManual) {
        if (isManual) return item.retentionTriggerDate ? fmtDate(item.retentionTriggerDate) : 'Manual date required';
        return item.retentionTriggerDate ? fmtDate(item.retentionTriggerDate) : 'Not available';
    }

    function assignPolicyStatus(item, isManual) {
        if (isManual && !item.retentionTriggerDate) return 'Manual trigger date required';
        if (item.retentionTriggerDate && item.policyEligibilityDate) return policyStatus(item);
        return 'Waiting for retention trigger';
    }

    async function postForm(path, form) {
        const error = form.querySelector('[data-retention-error]');
        error?.classList.add('hidden');
        const formData = new FormData(form);
        if (form.dataset.retentionForm === 'assign' && !selectedAssignScheduleIsManual(form)) {
            formData.delete('retention_trigger_date');
        }
        const response = await fetch(api(path), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body: formData });
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
        const reason = await window.FAMModal.prompt(config[1], '', { title: config[0], inputLabel: 'Reason', confirmLabel: config[3] });
        if (!reason) return;
        formData.append('reason', reason);
        const response = await fetch(api(`retention/${config[2]}?id=${id}`), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body: formData });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) {
            throw new Error(Object.values(payload?.data?.errors || {})[0] || payload?.message || 'Unable to complete this action.');
        }
        window.FAMModal?.showToast?.(`${config[0]} completed.`);
        await load();
    }

    async function analyzeRecommendation(id) {
        const response = await fetch(api(`retention/analyze-recommendation.php?id=${id}`), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders() });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) {
            throw new Error(Object.values(payload?.data?.errors || {})[0] || payload?.message || 'Unable to analyze this retention record.');
        }
        state.analysisByRecord[id] = payload.data?.analysis || null;
        if (payload.data?.recommendation) state.activeRecommendation = payload.data.recommendation;
        window.FAMModal?.showToast?.(payload.data?.recommendation ? 'Disposition recommendation generated.' : (payload.message || 'AI recommendation unavailable.'));
        if (state.activeItem?.id === id) await openDetails(id);
    }

    function bind() {
        qs('#retention-refresh')?.addEventListener('click', () => load().catch(console.error));
        qs('#retention-export')?.addEventListener('click', () => { window.location.href = exportUrl(); });
        qs('#retention-prev-page')?.addEventListener('click', () => { if (state.page > 1) { state.page--; load().catch(console.error); } });
        qs('#retention-next-page')?.addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; load().catch(console.error); } });
        document.querySelectorAll('.retention-table [data-sort]').forEach(button => button.addEventListener('click', () => {
            const nextSort = button.dataset.sort;
            state.direction = state.sort === nextSort && state.direction === 'asc' ? 'desc' : 'asc';
            state.sort = nextSort;
            state.page = 1;
            load().catch(console.error);
        }));
        ['#retention-search', '#retention-schedule-filter', '#retention-status-filter', '#retention-hold-filter'].forEach(selector => {
            qs(selector)?.addEventListener(selector === '#retention-search' ? 'input' : 'change', () => { state.page = 1; load().catch(console.error); });
        });
        document.addEventListener('change', event => {
            const assignSchedule = event.target.closest('select[name="retention_schedule_id"]');
            if (assignSchedule) updateAssignTriggerDisplay(assignSchedule);
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
                if (type === 'analyze-recommendation') analyzeRecommendation(id).then(load).catch(console.error);
                if (type === 'review-recommendation') openRecommendationReview(state.activeRecommendation || { id: Number(action.dataset.retentionRecommendationId) });
                if (type === 'assign') fetchItem(id).then(item => {
                    closeDetails();
                    state.activeItem = item;
                    openAssign(item);
                }).catch(console.error);
                if (type === 'extend') fetchItem(id).then(item => {
                    closeDetails();
                    state.activeItem = item;
                    openExtend(item);
                }).catch(console.error);
                if (['archive', 'dispose', 'place-hold', 'release-hold'].includes(type)) reasonAction(id, type).catch(console.error);
            }
        });
        document.addEventListener('submit', async event => {
            const form = event.target.closest('[data-retention-form]');
            if (!form) return;
            event.preventDefault();
            const type = form.dataset.retentionForm;
            const path = type === 'recommendation-review' ? `retention/review-recommendation.php?id=${state.activeRecommendation?.id || ''}` : `retention/${type === 'extend' ? 'extend' : 'assign'}.php?id=${state.activeItem.id}`;
            if (await postForm(path, form)) {
                closeDialog();
                window.FAMModal?.showToast?.(type === 'extend' ? 'Retention extended.' : (type === 'recommendation-review' ? 'Disposition recommendation reviewed.' : 'Retention schedule assigned.'));
                await load();
                if (state.activeItem?.id) await openDetails(state.activeItem.id);
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
