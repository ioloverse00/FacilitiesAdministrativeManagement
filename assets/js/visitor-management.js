(function () {
  const qs = s => document.querySelector(s);
  const qsa = s => Array.from(document.querySelectorAll(s));
  const state = {
    user: null,
    options: {},
    rows: [],
    page: 1,
    perPage: 10,
    total: 0,
    totalPages: 1,
    filters: { search: '', visitor_type: 'all', visit_status: 'all' },
    sort: 'scheduled_start_at',
    direction: 'desc',
    timer: null,
    lastFocus: null,
    openColumnMenu: null,
    loading: false,
    hasLoaded: false,
    error: ''
  };
  const can = p => (state.user?.permissions || []).includes(p) || (state.user?.permissions || []).includes('visitors.manage');
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const clean = v => String(v || '').replace(/\s+/g, ' ').trim();
  const title = v => String(v || '').toLowerCase().split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  const statusLabel = v => ({ PRE_REGISTERED:'Pending Verification', PENDING_REVIEW:'Pending Verification', APPROVED:'Approved', REJECTED:'Rejected', ARRIVED:'Pending Verification', CHECKED_IN:'Checked In', CHECKED_OUT:'Checked Out', CANCELLED:'Cancelled', NO_SHOW:'No Show', EXPIRED:'Expired' }[String(v || '').toUpperCase()] || title(v));
  const fmt = v => { if (!v) return 'Not applicable'; const d = new Date(String(v).replace(' ', 'T')); return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleString(undefined, { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' }); };
  const badge = (v, type = 'status') => `<span class="facility-badge facility-${type}-${String(v || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-')}">${esc(type === 'status' ? statusLabel(v || 'Not applicable') : title(v || 'Not applicable'))}</span>`;
  const trunc = (v, className = 'table-cell-truncate') => `<span class="${className}" title="${esc(v || 'Not applicable')}">${esc(v || 'Not applicable')}</span>`;
  function toast(m) { window.FAMModal?.showToast?.(m); }
  function tableStateRow(message, icon, spinning = false) { return `<tr class="fam-table-state-row"><td class="fam-table-state-cell" colspan="8"><div class="fam-state" role="status"><span class="material-symbols-outlined${spinning ? ' fam-spinner' : ''}" aria-hidden="true">${icon}</span><span>${esc(message)}</span></div></td></tr>`; }
  function params() { const p = new URLSearchParams({ page: state.page, per_page: state.perPage, sort: state.sort, direction: state.direction }); Object.entries(state.filters).forEach(([k, v]) => { if (v && v !== 'all') p.set(k, v); }); return p; }
  function exportUrl() { const p = params(); p.delete('page'); p.delete('per_page'); return `../api/visitors/export-csv.php?${p}`; }
  function activeFilters() { return Object.values(state.filters).some(v => v && v !== 'all'); }
  function syncToolbar() {
    const search = qs('#visitor-search');
    const type = qs('#visitor-type-filter');
    const status = qs('#visitor-status-filter');
    if (search) search.value = state.filters.search || '';
    if (type) type.value = state.filters.visitor_type || 'all';
    if (status) status.value = state.filters.visit_status || 'all';
  }
  function renderSummary(summary = {}) { qs('#visitor-summary').innerHTML = [["Today's Visitors", summary.today], ['Currently Checked In', summary.checked_in], ['Checked Out Today', summary.checked_out_today], ['Available Badges', summary.available_badges], ['Legacy Pending Review', summary.pending_review]].map(([k, v]) => `<span><strong>${Number(v || 0)}</strong>${esc(k)}</span>`).join(''); }
  function renderLoading(initial) {
    const body = qs('#visitor-table'), pager = qs('.visitor-management-workspace .facility-pagination'), card = qs('#visitor-table')?.closest('.facility-table-card'), refresh = qs('#visitor-refresh');
    card?.classList.toggle('fam-loading-region', initial);
    card?.setAttribute('aria-busy', 'true');
    refresh?.setAttribute('aria-busy', 'true');
    refresh?.setAttribute('disabled', 'disabled');
    if (initial) pager?.classList.add('hidden');
    if (initial && body) body.innerHTML = tableStateRow('Loading visitor records...', 'progress_activity', true);
  }
  function clearLoading() {
    const card = qs('#visitor-table')?.closest('.facility-table-card'), refresh = qs('#visitor-refresh');
    card?.classList.remove('fam-loading-region');
    card?.removeAttribute('aria-busy');
    refresh?.removeAttribute('aria-busy');
    refresh?.removeAttribute('disabled');
  }
  function renderTable() {
    window.FAMTableMenus?.close();
    const body = qs('#visitor-table'), pager = qs('.visitor-management-workspace .facility-pagination');
    window.FAMTableAudit?.check(body?.closest('table'), 'visitor-management-table');
    clearLoading();
    if (state.error) {
      qs('#visitor-table-count').textContent = 'Visitor records unavailable';
      if (!state.hasLoaded) {
        pager?.classList.add('hidden');
        if (body) body.innerHTML = tableStateRow('Unable to load visitor records. Try again.', 'error');
      }
      return;
    }
    qs('#visitor-table-count').textContent = state.total ? `Showing ${state.rows.length} of ${state.total} visitor records` : 'No visitor records';
    if (!state.rows.length) {
      pager?.classList.add('hidden');
      if (body) body.innerHTML = tableStateRow(activeFilters() ? 'No visitor records match the current filters.' : 'No visitor records found.', 'badge');
      return;
    }
    pager?.classList.remove('hidden');
    qs('#visitor-page-status').textContent = `Page ${state.page} of ${Math.max(1, state.totalPages)}`;
    qs('#visitor-prev-page').disabled = state.page <= 1;
    qs('#visitor-next-page').disabled = state.page >= state.totalPages;
    body.innerHTML = state.rows.map(row => rowHtml(row)).join('');
    window.FAMTableAudit?.check(body.closest('table'), 'visitor-management-table');
  }
  function rowHtml(row) { const visitor = row.visitor || {}, dest = row.destination_department?.name || row.facility_space?.name || 'Not assigned', actions = rowActions(row), name = clean(visitor.full_name) || 'Unnamed visitor', secondary = visitor.organization_name || visitor.email_address || visitor.mobile_number || 'External visitor'; return `<tr><td class="facility-request-number"><button class="facility-link-button" type="button" data-open-visitor="${row.id}">${trunc(row.visitor_reference_number, 'table-cell-primary')}</button></td><td><div class="facility-subject-cell visitor-name-cell table-cell-stack">${trunc(name, 'table-cell-primary')}${trunc(secondary, 'table-cell-secondary')}</div></td><td>${badge(visitor.visitor_type || row.visitor_type, 'status')}</td><td>${trunc(dest)}</td><td class="facility-date-cell">${trunc(fmt(row.actual_check_in_at))}</td><td class="facility-date-cell">${trunc(fmt(row.actual_check_out_at))}</td><td>${badge(row.visit_status, 'status')}</td><td class="facility-actions-cell"><div class="facility-action-menu"><button class="facility-action-toggle" type="button" data-open-visitor-menu="${row.id}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(row.visitor_reference_number)}">&#8942;</button><div class="facility-action-dropdown hidden" data-visitor-menu="${row.id}" role="menu">${actions.map(a => `<button type="button" role="menuitem" data-visitor-action="${a.key}" data-visitor-id="${row.id}">${esc(a.label)}</button>`).join('')}</div></div></td></tr>`; }
  function rowActions(row) { const a = [{ key:'view', label:'View Details' }]; if (can('visitors.approve') && ['PENDING_REVIEW', 'PRE_REGISTERED'].includes(row.visit_status)) { a.push({ key:'approve', label:'Approve' }); a.push({ key:'reject', label:'Reject' }); } if (can('visitors.checkin') && ['APPROVED', 'ARRIVED'].includes(row.visit_status)) a.push({ key:'checkin', label:'Check In' }); if (can('visitors.checkout') && row.visit_status === 'CHECKED_IN') a.push({ key:'checkout', label:'Check Out' }); if (can('visitors.review') && !['CHECKED_IN', 'CHECKED_OUT', 'CANCELLED', 'REJECTED'].includes(row.visit_status)) a.push({ key:'cancel', label:'Cancel' }); return a; }
  async function load() {
    if (state.loading) return;
    state.loading = true;
    state.error = '';
    renderLoading(!state.hasLoaded);
    try {
      const r = await window.FAMApi.request(`../api/visitors/index.php?${params()}`);
      state.rows = r.data?.items || [];
      state.total = Number(r.data?.pagination?.total || 0);
      state.totalPages = Number(r.data?.pagination?.total_pages || 1);
      renderSummary(r.data?.summary || {});
      qs('#visitor-updated').textContent = `Last updated: ${new Date().toLocaleString()}`;
    } catch (e) {
      if (e.status === 401) return window.location.href = window.FAMApi.pageLoginUrl();
      state.error = e.message || 'Unable to load visitors.';
      const hasRenderedRows = Boolean(qs('#visitor-table')?.querySelector('tr:not(.fam-table-state-row)'));
      if (!hasRenderedRows) {
        state.rows = [];
        state.total = 0;
        state.totalPages = 1;
      }
      toast('Unable to load visitor records. Try again.');
    } finally {
      state.loading = false;
      renderTable();
      state.hasLoaded = true;
      syncToolbar();
    }
  }
  async function loadOptions() { const me = await window.FAMApi.me(); const opt = await window.FAMApi.request('../api/visitors/options.php'); state.user = me.user; state.options = opt.data || {}; fillSimple(qs('#visitor-type-filter'), state.options.visitor_types || [], 'All Types', title); fillSimple(qs('#visitor-status-filter'), state.options.visit_statuses || [], 'All Statuses', statusLabel); }
  function fillSimple(select, items, first, labeler = title) { if (!select) return; select.innerHTML = `<option value="all">${esc(first)}</option>` + (items || []).map(item => `<option value="${esc(item)}">${esc(labeler(item))}</option>`).join(''); }
  function field(name, label, type = 'text') { return `<label class="facility-field"><span>${label}</span><input name="${name}" type="${type}"></label>`; }
  function selectField(name, label, items, placeholder) { return `<label class="facility-field"><span>${label}</span><select name="${name}"><option value="">${placeholder}</option>${(items || []).map(x => `<option value="${esc(x.id || x)}">${esc(x.name || title(x.full_name || x))}</option>`).join('')}</select></label>`; }
  function moveToTopLayer(el) { if (el && el.parentElement !== document.body) document.body.appendChild(el); return el; }
  async function openDetails(id) { if (!id) return; const drawer = moveToTopLayer(qs('#visitor-details-drawer')); state.lastFocus = document.activeElement; drawer.hidden = false; drawer.className = 'facility-details-modal'; drawer.setAttribute('role', 'dialog'); drawer.setAttribute('aria-modal', 'true'); document.body.classList.add('facility-details-modal-open'); drawer.innerHTML = shell('Loading visitor details...'); drawer.querySelector('[data-close-drawer]')?.focus(); try { const r = await window.FAMApi.request(`../api/visitors/show.php?id=${id}`); drawer.innerHTML = detailsHtml(r.data.item); drawer.querySelector('[data-close-drawer]')?.focus(); } catch (e) { drawer.innerHTML = shell(e.message || 'Unable to load visitor details.'); } }
  function shell(m) { return `<div class="facility-details-modal-panel"><div class="facility-details-modal-header"><div><p>Visitor Management</p><h2>Visitor Details</h2></div><button class="facility-details-modal-close" type="button" data-close-drawer aria-label="Close visitor details">&times;</button></div><div class="facility-details-modal-body"><div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>${esc(m)}</span></div></div></div>`; }
  function detail(k, v, className = '') { return `<dl class="facility-detail-row ${esc(className)}"><dt>${esc(k)}</dt><dd>${esc(v || 'Not applicable')}</dd></dl>`; }
  function detailGrid(content) { return `<div class="detail-grid visitor-detail-content">${content}</div>`; }
  function scheduleValue(v) { return v ? fmt(v) : 'Not Scheduled'; }
  function duration(item) {
    const start = item.actual_check_in_at ? new Date(String(item.actual_check_in_at).replace(' ', 'T')) : null;
    const end = item.actual_check_out_at ? new Date(String(item.actual_check_out_at).replace(' ', 'T')) : null;
    if (!start || !end || Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) return 'Not applicable';
    const mins = Math.round((end - start) / 60000);
    const hours = Math.floor(mins / 60);
    const rest = mins % 60;
    return hours ? `${hours} hr ${rest} min` : `${rest} min`;
  }
  function historyLabel(event) { return ({ VISITOR_WALKIN_REGISTERED:'Registration Submitted', VISITOR_CREATED:'Registration Submitted', VISITOR_REVIEWED:'Reviewed', VISITOR_APPROVED:'Reviewed', VISITOR_REJECTED:'Reviewed', VISITOR_CHECKED_IN:'Checked In', VISITOR_CHECKED_OUT:'Checked Out', VISITOR_UPDATED:'Reviewed', BADGE_ISSUED:'Badge Issued', BADGE_RETURNED:'Badge Returned' }[String(event || '').toUpperCase()] || title(event)); }
  function detailsHtml(item) {
    const v = item.visitor || {}, h = item.history || [], idv = item.identity_verification || {};
    const badgeInfo = item.badge ? item.badge.badge_number : 'Not issued';
    const hasBadge = Boolean(item.badge);
    const hasRemarks = Boolean(clean(item.remarks));
    const visitorInfo = detailGrid(`${detail('Name', clean(v.full_name))}${detail('Mobile', v.mobile_number)}${detail('Type', title(v.visitor_type))}${detail('Organization / School', v.organization_name)}${detail('Email', v.email_address)}`);
    const visitDetails = detailGrid(`${detail('Purpose', item.visit_purpose)}${detail('Status', statusLabel(item.visit_status))}${detail('Approval', title(item.approval_status))}${detail('Department', item.destination_department?.name)}${detail('Host', item.host?.full_name)}${detail('Facility Space', item.facility_space?.name)}`);
    const timeline = detailGrid(`${detail('Scheduled Start', scheduleValue(item.scheduled_start_at))}${detail('Scheduled End', scheduleValue(item.scheduled_end_at))}${detail('Checked In', fmt(item.actual_check_in_at))}${detail('Checked Out', fmt(item.actual_check_out_at))}${detail('Duration', duration(item))}`);
    const badgeDetails = detailGrid(`${detail('Badge', badgeInfo)}${detail('Badge Status', item.badge?.status)}`);
    const identityDetails = detailGrid(`${detail('Verified', idv.verified ? 'Yes' : 'No')}${detail('ID Type', idv.identification_type)}${detail('ID Last Four', idv.identification_last4)}${detail('Verified At', fmt(idv.verified_at))}`);
    const registrationDetails = detailGrid(`${detail('Registration Source', title(item.registration_source))}${detail('Applicant Reference', item.applicant_reference)}${detail('Company / School', item.company_or_school)}`);
    const historyHtml = h.length ? `<ol class="facility-history-list visitor-activity-timeline">${h.map(x => `<li><strong>${esc(historyLabel(x.event_type))}</strong><span>${esc(fmt(x.timestamp))} by ${esc(x.actor || 'System')}</span><p>${esc(x.remarks || 'No remarks')}</p></li>`).join('')}</ol>` : '<p>No activity history recorded.</p>';
    const badgeSection = hasBadge ? `<details class="visitor-detail-disclosure"><summary>Badge Information</summary>${badgeDetails}</details>` : '';
    const remarksSection = hasRemarks ? `<details class="visitor-detail-disclosure" open><summary>Remarks</summary><p>${esc(item.remarks)}</p></details>` : '';
    return `<div class="facility-details-modal-panel visitor-details-panel"><div class="facility-details-modal-header visitor-details-header"><div><p>Visitor Reference Number</p><span class="facility-details-modal-request-number">${esc(item.visitor_reference_number)}</span><h2>${esc(clean(v.full_name))}</h2></div><button class="facility-details-modal-close" type="button" data-close-drawer aria-label="Close visitor details">&times;</button></div><div class="facility-details-modal-body visitor-details-body"><div class="visitor-detail-accordion"><details class="visitor-detail-disclosure" open><summary>Visitor Information</summary>${visitorInfo}</details><details class="visitor-detail-disclosure" open><summary>Visit Details</summary>${visitDetails}</details><details class="visitor-detail-disclosure" open><summary>Schedule and Attendance</summary>${timeline}</details>${badgeSection}${remarksSection}<details class="visitor-detail-disclosure"><summary>Identity Verification</summary>${identityDetails}</details><details class="visitor-detail-disclosure"><summary>Registration Details</summary>${registrationDetails}</details><details class="visitor-detail-disclosure"><summary>Activity History</summary>${historyHtml}</details></div></div></div>`;
  }
  function closeDrawer() { const d = qs('#visitor-details-drawer'); window.FAMModal?.closeElement?.(d, () => { d.innerHTML = ''; document.body.classList.remove('facility-details-modal-open'); state.lastFocus?.focus?.(); }); }
  function closeDialog() { const d = qs('#visitor-dialog'); window.FAMModal?.closeElement?.(d, () => { d.innerHTML = ''; }); }
  async function action(key, id) { if (key === 'view') return openDetails(id); if (key === 'approve' || key === 'reject' || key === 'cancel') { const act = { approve:'APPROVE', reject:'REJECT', cancel:'CANCEL' }[key]; const remarks = key === 'approve' ? 'Approved from Visitor Management.' : await window.FAMModal.prompt(`${title(key)} remarks`, '', { title: `${title(key)} Visitor`, confirmLabel: title(key) }); if (remarks === null) return; await window.FAMApi.request(`../api/visitors/review.php?id=${id}`, { method:'POST', body:{ action:act, remarks } }); toast('Visitor action completed.'); return load(); } if (key === 'checkin') return openCheckin(id); if (key === 'checkout') { if (!await window.FAMModal.confirm('Check this visitor out and return any issued badge?', { title: 'Check Out Visitor', confirmLabel: 'Check Out' })) return; await window.FAMApi.request(`../api/visitors/check-out.php?id=${id}`, { method:'POST', body:{ remarks:'Checked out from Visitor Management.' } }); toast('Visitor checked out.'); return load(); } }
  function openCheckin(id) { const d = moveToTopLayer(qs('#visitor-dialog')); d.hidden = false; d.innerHTML = `<form class="facility-dialog-panel" data-checkin-form data-id="${id}"><div class="facility-details-modal-header"><div><p>Visitor Management</p><h2>Check In Visitor</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close form">&times;</button></div><label class="visitor-check-filter"><input name="identity_verified" type="checkbox" value="1" checked> Identity verified</label><div class="facility-form-grid">${selectField('identification_type', 'Identification Type', state.options.identity_document_types, 'Optional')}${field('identification_last4', 'ID Last Four')}${selectField('badge_id', 'Badge / Pass', state.options.available_badges, 'No badge')}</div><label class="facility-field"><span>Remarks</span><textarea name="remarks" rows="3">Verified at reception.</textarea></label><div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Check In</button></div></form>`; }
  async function submitCheckin(form) { const data = Object.fromEntries(new FormData(form).entries()); data.identity_verified = Boolean(data.identity_verified); await window.FAMApi.request(`../api/visitors/check-in.php?id=${form.dataset.id}`, { method:'POST', body:data }); closeDialog(); toast('Visitor checked in.'); await load(); openDetails(form.dataset.id); }
  function resetFilters() { state.filters = { search:'', visitor_type:'all', visit_status:'all' }; state.sort = 'scheduled_start_at'; state.direction = 'desc'; state.page = 1; state.openColumnMenu = null; syncToolbar(); load(); }
  function bind() {
    qs('#visitor-refresh')?.addEventListener('click', load);
    qs('#visitor-export')?.addEventListener('click', () => { window.location.href = exportUrl(); });
    qs('#visitor-prev-page')?.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); load(); });
    qs('#visitor-next-page')?.addEventListener('click', () => { state.page += 1; load(); });
    qs('#visitor-search')?.addEventListener('input', e => { state.filters.search = e.target.value; state.page = 1; clearTimeout(state.timer); state.timer = setTimeout(load, 300); });
    qs('#visitor-type-filter')?.addEventListener('change', e => { state.filters.visitor_type = e.target.value || 'all'; state.page = 1; load(); });
    qs('#visitor-status-filter')?.addEventListener('change', e => { state.filters.visit_status = e.target.value || 'all'; state.page = 1; load(); });
    qsa('[data-visitor-sort]').forEach(b => b.addEventListener('click', () => { state.sort = b.dataset.visitorSort; state.direction = state.direction === 'asc' ? 'desc' : 'asc'; state.page = 1; load(); }));
    document.addEventListener('click', e => {
      const open = e.target.closest('[data-open-visitor]');
      if (open) return openDetails(open.dataset.openVisitor);
      const menu = e.target.closest('[data-open-visitor-menu]');
      if (menu) { state.openColumnMenu = null; return window.FAMTableMenus?.toggle(menu, qs(`[data-visitor-menu="${CSS.escape(menu.dataset.openVisitorMenu)}"]`)); }
      const act = e.target.closest('[data-visitor-action]');
      if (act) return action(act.dataset.visitorAction, act.dataset.visitorId).catch(err => toast(err.message || 'Action failed.'));
      if (e.target.closest('[data-close-drawer]') || e.target === qs('#visitor-details-drawer')) return closeDrawer();
      if (e.target.closest('[data-close-dialog]')) return closeDialog();
      if (!e.target.closest('.facility-action-menu') && !e.target.closest('.facility-action-dropdown')) window.FAMTableMenus?.close();
    });
    document.addEventListener('submit', e => { if (e.target.matches('[data-checkin-form]')) { e.preventDefault(); submitCheckin(e.target).catch(err => toast(err.message || 'Check-in failed.')); } });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { if (!qs('#visitor-dialog')?.hidden) closeDialog(); else if (!qs('#visitor-details-drawer')?.hidden) closeDrawer(); } });
  }
  document.addEventListener('fam:layout-ready', async () => { bind(); try { await loadOptions(); await load(); } catch (e) { if (e.status === 401) window.location.href = window.FAMApi.pageLoginUrl(); else { state.error = 'Unable to load visitor records.'; renderTable(); toast('Unable to initialize Visitor Management. Try again.'); } } });
})();





