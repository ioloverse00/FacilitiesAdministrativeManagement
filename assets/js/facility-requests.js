(function () {
  const pageSize = 6;
  const qs = s => document.querySelector(s);
  const qsa = s => Array.from(document.querySelectorAll(s));
  const state = {
    user: null, options: {}, rows: [],
    page: 1, search: '', status: 'all', priority: 'all', category_id: 'all', department_id: 'all', assigned_to: 'all', sla_status: 'all', dateRange: 'all', sortKey: 'created_at', sortDirection: 'desc',
    pagination: { page: 1, per_page: pageSize, total: 0, total_pages: 1 }, loading: true, tableLoading: false, accessDenied: false, openActionMenu: null, openColumnMenu: null, detailsId: null, searchTimer: null
  };
  const sortMap = { requestNo: 'request_number', subject: 'subject', priority: 'priority', status: 'status', submitted: 'created_at', sla: 'resolution_due_at', slaState: 'resolution_due_at' };
  const columns = {
    category: { label: 'Category', filterKey: 'category_id', options: () => (state.options.categories || []).map(x => ({ value: String(x.id), label: x.name || x.code })) },
    priority: { label: 'Priority', filterKey: 'priority', sortKey: 'priority', options: () => (state.options.priorities || []).map(x => ({ value: x, label: title(x) })) },
    status: { label: 'Status', filterKey: 'status', sortKey: 'status', options: () => (state.options.statuses || []).map(x => ({ value: x, label: title(x) })) },
    slaState: { label: 'SLA', sortKey: 'sla' },
    assignedTo: { label: 'Assigned To' },
    submitted: { label: 'Submitted', filterKey: 'dateRange', sortKey: 'submitted', options: () => [{ value: 'today', label: 'Today' }, { value: '7', label: 'Last 7 Days' }, { value: '30', label: 'Last 30 Days' }] }
  };
  function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function title(v) { return String(v || '').toLowerCase().split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' '); }
  function slug(v) { return String(v || '').toLowerCase().replace(/_/g, '-').replace(/\s+/g, '-').replace(/[()]/g, ''); }
  function can(p) { return (state.user?.permissions || []).includes(p); }
  function toast(m) { window.FAMModal?.showToast?.(m); }
  function errMsg(e) { if (e.status === 403) return 'You do not have access to this action.'; if (e.status === 404) return 'Facility request was not found.'; if (e.status === 422) return 'Please review the highlighted fields.'; if (e.status >= 500) return 'The server could not complete the request. Please try again.'; return e.message || 'Request failed.'; }
  function fmt(v) { if (!v) return 'Not applicable'; const d = new Date(String(v).replace(' ', 'T')); if (Number.isNaN(d.getTime())) return String(v); return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' }).format(d); }
  function updated() { const n = new Date(); return `Last updated: ${n.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}, ${n.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`; }
  function badge(v, type) { return `<span class="facility-badge facility-${type}-${slug(v)}">${esc(title(v))}</span>`; }
  function trunc(v, className = 'table-cell-truncate') { const text = v || 'Not applicable'; return `<span class="${className}" title="${esc(text)}">${esc(text)}</span>`; }
  function slaBadge(row) { const s = row.sla?.status || 'NOT_APPLICABLE'; const icon = s === 'OVERDUE' ? 'error' : s === 'DUE_SOON' ? 'schedule' : s === 'ON_TRACK' ? 'check_circle' : 'info'; return `<span class="facility-sla facility-sla-${slug(s)}" title="${esc(row.sla?.resolution_due_at ? 'Due ' + fmt(row.sla.resolution_due_at) : 'No SLA target')}"><span class="material-symbols-outlined" aria-hidden="true">${icon}</span>${esc(s === 'NOT_APPLICABLE' ? 'Not applicable' : title(s))}</span>`; }
  async function auth() { try { const r = await window.FAMApi.me(); state.user = r.user; state.accessDenied = !can('facility_requests.view'); } catch (e) { if (e.status === 401) window.location.href = `login.html?next=${encodeURIComponent(window.location.pathname + window.location.search)}`; else throw e; } }
  function dateParams() { if (state.dateRange === 'all') return {}; const now = new Date(); const end = now.toISOString().slice(0, 10); if (state.dateRange === 'today') return { date_from: end, date_to: end }; const days = Number(state.dateRange); if (!days) return {}; const start = new Date(now); start.setDate(now.getDate() - days); return { date_from: start.toISOString().slice(0, 10), date_to: end }; }
  function params() { const p = new URLSearchParams({ page: String(state.page), per_page: String(pageSize), sort: state.sortKey, direction: state.sortDirection }); if (state.search.trim()) p.set('search', state.search.trim()); ['status','priority','category_id','department_id'].forEach(k => { if (state[k] !== 'all') p.set(k, state[k]); }); const d = dateParams(); if (d.date_from) p.set('date_from', d.date_from); if (d.date_to) p.set('date_to', d.date_to); return p; }
  async function loadOptions() { const r = await window.FAMApi.request('../api/facility-requests/options.php'); state.options = r.data || {}; }
  async function loadList(quiet = false) { state.tableLoading = true; if (!quiet) state.loading = true; renderTable(); try { const r = await window.FAMApi.request(`../api/facility-requests/index.php?${params()}`); state.rows = r.data?.items || []; state.pagination = r.data?.pagination || state.pagination; qs('#facility-requests-updated').textContent = updated(); } catch (e) { toast(errMsg(e)); state.rows = []; } finally { state.loading = false; state.tableLoading = false; renderTable(); resetVisible(); } }
  function renderTable() {
    window.FAMTableMenus?.close();
    if (state.accessDenied) return accessDenied();
    const loading = qs('#facility-loading-state'), body = qs('#facility-requests-table'), empty = qs('#facility-empty-state'), count = qs('#facility-table-count'), pager = qs('.facility-pagination');
    renderHeaders();
    window.FAMTableAudit?.check(body?.closest('table'), 'facility-requests-table');
    if (state.loading || state.tableLoading) { loading.classList.remove('hidden'); body.innerHTML = ''; empty.classList.add('hidden'); pager.classList.add('hidden'); return; }
    loading.classList.add('hidden'); const total = Number(state.pagination.total || 0); const pages = Math.max(1, Number(state.pagination.total_pages || 1));
    count.textContent = total ? `${total} request record${total === 1 ? '' : 's'}` : 'No matching request records';
    if (!state.rows.length) { body.innerHTML = ''; empty.classList.remove('hidden'); pager.classList.add('hidden'); const filtered = activeFilters(); empty.innerHTML = `<span class="material-symbols-outlined" aria-hidden="true">${filtered ? 'search_off' : 'inbox'}</span><strong>${filtered ? 'No requests match the current search or filters.' : 'No facility requests have been submitted.'}</strong><span>${filtered ? '<button class="facility-text-button" type="button" data-empty-clear>Clear Filters</button>' : 'Submitted requests will appear here for review.'}</span>`; return; }
    empty.classList.add('hidden'); pager.classList.remove('hidden'); qs('#facility-page-status').textContent = `Page ${state.pagination.page || state.page} of ${pages}`; qs('#facility-prev-page').disabled = state.page <= 1; qs('#facility-next-page').disabled = state.page >= pages;
    body.innerHTML = state.rows.map(row => `<tr data-request-id="${row.id}"><td class="facility-request-number"><button type="button" class="facility-link-button" data-open-details="${row.id}">${trunc(row.request_number || 'Not assigned', 'table-cell-primary')}</button></td><td><div class="facility-subject-cell table-cell-stack"><button type="button" class="facility-link-button" data-open-details="${row.id}">${trunc(row.subject || 'Untitled request', 'table-cell-primary')}</button></div></td><td>${trunc(row.category?.name || 'No category')}</td><td>${badge(row.priority || 'NORMAL', 'priority')}</td><td>${badge(row.status || 'SUBMITTED', 'status')}</td><td class="facility-date-cell">${trunc(fmt(row.created_at))}</td><td class="facility-actions-cell"><div class="facility-action-menu"><button class="facility-action-toggle" type="button" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(row.request_number)}" data-action-menu-toggle="${row.id}">&#8942;</button><div class="facility-action-dropdown hidden" data-action-menu-panel="${row.id}" role="menu">${actions(row).map(a => `<button type="button" role="menuitem" data-request-action="${a.key}" data-request-id="${row.id}">${esc(a.label)}</button>`).join('')}</div></div></td></tr>`).join('');
    window.FAMTableAudit?.check(body.closest('table'), 'facility-requests-table');
  }
  function accessDenied() { qs('#facility-loading-state')?.classList.add('hidden'); const empty = qs('#facility-empty-state'); empty.classList.remove('hidden'); empty.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">lock</span><strong>Access denied</strong><span>You do not have permission to view facility requests.</span>'; }
  function actions(row) { const a = []; if (can('facility_requests.view')) a.push(['view','View Details']); if (can('facility_requests.assign') && ['SUBMITTED','APPROVED','ASSIGNED'].includes(row.status)) a.push(['assign', row.assigned_to ? 'Update Assignment' : 'Assign']); if ((can('facility_requests.edit') || can('facility_requests.assign')) && row.status === 'ASSIGNED') a.push(['start','Start Work']); if (can('facility_requests.complete') && row.status === 'IN_PROGRESS') a.push(['complete','Complete']); if (can('facility_requests.verify') && row.status === 'COMPLETED') a.push(['verify','Verify']); if ((can('facility_requests.verify') || can('facility_requests.manage')) && row.status === 'VERIFIED') a.push(['close','Close']); if (can('facility_requests.manage') && !['COMPLETED','VERIFIED','CLOSED','CANCELLED'].includes(row.status)) a.push(['cancel','Cancel Request']); return a.map(x => ({ key: x[0], label: x[1] })); }
  function renderHeaders() { Object.entries(columns).forEach(([key, cfg]) => { const h = qs('[data-column="' + key + '"]'); if (!h) return; const sk = sortMap[cfg.sortKey || key] || cfg.sortKey || key, sortable = Boolean(cfg.sortKey), filterable = Boolean(cfg.filterKey && cfg.options), fv = filterable ? state[cfg.filterKey] : 'all', sorted = sortable && state.sortKey === sk, filtered = filterable && fv !== 'all'; if (!sortable && !filterable) { h.innerHTML = '<span class="facility-column-label">' + esc(cfg.label) + '</span>'; return; } h.innerHTML = '<div class="facility-column-menu"><button class="facility-column-trigger ' + (sorted || filtered ? 'active' : '') + '" type="button" aria-haspopup="menu" aria-expanded="' + (state.openColumnMenu === key) + '" data-column-menu-toggle="' + key + '"><span>' + esc(cfg.label) + '</span>' + (filtered ? '<span class="facility-filter-dot" aria-label="Filtered"></span>' : '') + '<span class="facility-sort-indicator ' + (sorted ? 'facility-sort-' + state.sortDirection : '') + '" aria-hidden="true"></span></button><div class="facility-column-dropdown ' + (state.openColumnMenu === key ? '' : 'hidden') + '" role="menu">' + headerMenu(key, cfg) + '</div></div>'; }); positionColumn(); }
  function headerMenu(key, cfg) { const sk = sortMap[cfg.sortKey || key] || cfg.sortKey || key, filterable = Boolean(cfg.filterKey && cfg.options), fv = filterable ? state[cfg.filterKey] : 'all', opts = filterable ? cfg.options() : []; const sortGroup = cfg.sortKey ? '<div class="facility-column-menu-group"><span>Sort</span><button type="button" role="menuitem" data-column-sort="' + key + '" data-sort-direction="asc">Sort Ascending</button><button type="button" role="menuitem" data-column-sort="' + key + '" data-sort-direction="desc">Sort Descending</button>' + (state.sortKey === sk ? '<button type="button" role="menuitem" data-column-clear-sort="' + key + '">Clear Sort</button>' : '') + '</div>' : ''; const filterGroup = filterable ? '<div class="facility-column-menu-group"><span>Filter</span><button type="button" role="menuitem" data-column-filter="' + key + '" data-filter-value="all">All</button>' + opts.map(o => '<button class="' + (String(fv) === String(o.value) ? 'selected' : '') + '" type="button" role="menuitem" data-column-filter="' + key + '" data-filter-value="' + esc(o.value) + '">' + esc(o.label) + '</button>').join('') + (fv !== 'all' ? '<button type="button" role="menuitem" data-column-clear-filter="' + key + '">Clear Filter</button>' : '') + '</div>' : ''; return sortGroup + filterGroup; }
  function positionAction() {}
  function positionColumn() { const d = qs('.facility-column-dropdown:not(.hidden)'), t = qs('[data-column-menu-toggle][aria-expanded="true"]'); if (!d || !t) return; const r = t.getBoundingClientRect(); d.style.top = `${Math.min(window.innerHeight - d.getBoundingClientRect().height - 16, r.bottom + 8)}px`; d.style.left = `${Math.min(Math.max(16, r.left), window.innerWidth - d.getBoundingClientRect().width - 16)}px`; }
  function activeFilters() { return Boolean(state.search.trim()) || ['status','priority','category_id','department_id','dateRange'].some(k => state[k] !== 'all'); }
  function resetVisible() { qs('#facility-reset-filters')?.classList.toggle('hidden', !activeFilters()); }
  function resetFilters() { Object.assign(state, { search: '', status: 'all', priority: 'all', category_id: 'all', department_id: 'all', assigned_to: 'all', sla_status: 'all', dateRange: 'all', sortKey: 'created_at', sortDirection: 'desc', page: 1, openActionMenu: null, openColumnMenu: null }); const s = qs('#facility-search'); if (s) s.value = ''; loadList(true); }
  function ensureDrawer() {
    const d = qs('#facility-request-drawer');
    if (d.parentElement !== document.body) document.body.appendChild(d);
    d.className = 'facility-details-modal';
    d.setAttribute('role', 'dialog');
    d.setAttribute('aria-modal', 'true');
    d.setAttribute('aria-labelledby', 'facility-drawer-title');
  }
  function shell(msg) {
    return `<div class="facility-details-modal-panel"><div class="facility-details-modal-header"><div><p>Facility Request</p><span class="facility-details-modal-request-number">Loading</span><h2 id="facility-drawer-title">Request Details</h2></div><button class="facility-details-modal-close" type="button" data-close-drawer aria-label="Close request details">&times;</button></div><div class="facility-details-modal-body" aria-live="polite"><div class="fam-state"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>${esc(msg)}</span></div></div></div>`;
  }
  async function openDetails(id) {
    ensureDrawer();
    state.lastFocusedElement = document.activeElement;
    state.openActionMenu = null;
    state.openColumnMenu = null;
    state.detailsId = id;
    const d = qs('#facility-request-drawer');
    d.hidden = false;
    document.body.classList.add('facility-details-modal-open');
    d.innerHTML = shell('Loading request details...');
    d.querySelector('[data-close-drawer]')?.focus();
    try {
      const r = await window.FAMApi.request(`../api/facility-requests/show.php?id=${id}`);
      d.innerHTML = detailsHtml(r.data.item);
      d.querySelector('[data-close-drawer]')?.focus();
    } catch (e) {
      d.innerHTML = shell(errMsg(e));
    }
  }
  function detail(k, v, className = '') { return `<dl class="facility-detail-row ${esc(className)}"><dt>${esc(k)}</dt><dd>${esc(v || 'Not applicable')}</dd></dl>`; }
  function detailGrid(content) { return `<div class="detail-grid">${content}</div>`; }
  function lines(items) { return items.length ? `<ul class="facility-detail-list">${items.map(x => `<li>${esc(x)}</li>`).join('')}</ul>` : '<p>Not applicable</p>'; }
  function history(items) { return items?.length ? `<ol class="facility-history-list">${items.map(x => `<li><strong>${esc(title(x.old_status || 'Created'))} to ${esc(title(x.new_status))}</strong><span>${esc(fmt(x.changed_at))} by ${esc(x.changed_by_username || 'System')}</span><p>${esc(x.change_reason || 'No remarks')}</p></li>`).join('')}</ol>` : '<p>No history recorded.</p>'; }
  function detailsHtml(item) {
    const summary = detailGrid(`${detail('Category', item.category?.name)}${detail('Priority', title(item.priority))}${detail('Status', title(item.status))}${detail('Submitted', fmt(item.created_at))}`);
    const requester = detailGrid(`${detail('Requester', item.requested_by?.full_name)}${detail('Employee No.', item.requested_by?.employee_number)}${detail('Department', item.requested_by?.department?.name)}${detail('Space', item.location?.space_name || 'Not applicable')}${detail('Building', item.location?.building_name || 'Not applicable')}`);
    const assignment = detailGrid(`${detail('Assigned To', item.assigned_to?.full_name || 'Unassigned')}`);
    const sla = detailGrid(`${detail('Status', title(item.sla?.status || 'NOT_APPLICABLE'))}${detail('Resolution Due', fmt(item.sla?.resolution_due_at))}${detail('Minutes Remaining', item.sla?.minutes_remaining ?? 'Not applicable')}`);
    return `<div class="facility-details-modal-panel facility-request-details-panel"><div class="facility-details-modal-header"><div><p>Facility Request</p><span class="facility-details-modal-request-number">${esc(item.request_number)}</span><h2 id="facility-drawer-title">${esc(item.subject || 'Request Details')}</h2></div><button class="facility-details-modal-close" type="button" data-close-drawer aria-label="Close request details">&times;</button></div><div class="facility-details-modal-body"><div class="visitor-detail-accordion facility-request-detail-accordion"><details class="visitor-detail-disclosure" open><summary>Request Summary</summary>${summary}</details><details class="visitor-detail-disclosure" open><summary>Requester and Location</summary>${requester}</details><details class="visitor-detail-disclosure" open><summary>Assignment</summary>${assignment}</details><details class="visitor-detail-disclosure" open><summary>Description</summary><p>${esc(item.description || 'No description provided.')}</p></details><details class="visitor-detail-disclosure"><summary>SLA Tracking</summary>${sla}</details><details class="visitor-detail-disclosure"><summary>Approval Summary</summary>${lines((item.approval_summary || []).map(x => `${title(x.approval_status)} - step ${x.current_step_number || 0} of ${x.total_steps || 0}`))}</details><details class="visitor-detail-disclosure"><summary>Workflow Tasks</summary>${lines((item.workflow_tasks || []).map(x => `${x.task_number}: ${title(x.task_status)} - ${x.task_title}`))}</details><details class="visitor-detail-disclosure"><summary>AI Recommendations</summary>${lines((item.ai_recommendations || []).map(x => `${title(x.feature_type)} - ${x.generated_summary || x.suggested_priority || 'Recommendation recorded'}`))}</details><details class="visitor-detail-disclosure"><summary>Recent History</summary>${history(item.history || [])}</details></div></div></div>`;
  }
  function ensureDialog() { if (qs('#facility-dialog')) return; const d = document.createElement('div'); d.id = 'facility-dialog'; d.className = 'facility-dialog'; d.hidden = true; d.setAttribute('role','dialog'); d.setAttribute('aria-modal','true'); document.body.appendChild(d); }
  function opt(items, selected, label) { return (items || []).map(x => `<option value="${x.id}" ${String(x.id) === String(selected) ? 'selected' : ''}>${esc(x[label] || x.code || x.full_name)}</option>`).join(''); }
  function inputDate(v) { return v ? String(v).replace(' ', 'T').slice(0, 16) : ''; }
  function formHtml(titleText, submit, item = {}) {
    const cat = item.category?.id || '', space = item.location?.space_id || '', pri = item.priority || 'NORMAL';
    const deptId = item.requested_by?.department?.id || state.options.current_employee?.department?.id || state.user?.department?.id || '';
    const deptName = item.requested_by?.department?.name || state.options.current_employee?.department?.name || state.user?.department?.name || 'Not assigned';
    return `<form class="facility-dialog-panel" data-request-form><div class="facility-details-modal-header"><div><p>${esc(state.options.current_employee?.full_name || state.user?.full_name || 'Current user')} - ${esc(deptName)}</p><h2>${esc(titleText)}</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close form">&times;</button></div><div class="facility-form-error" data-form-error hidden></div><input type="hidden" name="department_reference_id" value="${esc(deptId)}"><label class="facility-field"><span>Subject</span><input name="subject" value="${esc(item.subject || '')}" required></label><label class="facility-field"><span>Description</span><textarea name="description" rows="4">${esc(item.description || '')}</textarea></label><div class="facility-form-grid"><label class="facility-field"><span>Category</span><select name="request_category_id" required>${opt(state.options.categories, cat, 'name')}</select></label><label class="facility-field"><span>Facility Space</span><select name="facility_space_id"><option value="">Not applicable</option>${opt(state.options.facility_spaces, space, 'name')}</select></label><label class="facility-field"><span>Priority</span><select name="priority">${(state.options.priorities || ['NORMAL']).map(p => `<option value="${esc(p)}" ${p === pri ? 'selected' : ''}>${esc(title(p))}</option>`).join('')}</select></label><label class="facility-field"><span>Requested Completion</span><input name="requested_completion_at" type="datetime-local" value="${inputDate(item.lifecycle?.requested_completion_at)}"></label></div><div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">${esc(submit)}</button></div></form>`;
  }
  function openForm(item) { if (!item) return; ensureDialog(); const d = qs('#facility-dialog'); d.hidden = false; d.innerHTML = formHtml('Edit Facility Request', 'Save Changes', item); bindForm(item.id); d.querySelector('input, select, textarea, button')?.focus(); }
  async function openEdit(id) { try { const r = await window.FAMApi.request(`../api/facility-requests/show.php?id=${id}`); openForm(r.data.item); } catch (e) { toast(errMsg(e)); } }
  function clearErrors(form) { qsa('.facility-field-error').forEach(x => x.remove()); const box = form.querySelector('[data-form-error]'); if (box) box.hidden = true; }
  function showErrors(form, e) { const box = form.querySelector('[data-form-error]'); if (box) { box.textContent = errMsg(e); box.hidden = false; } Object.entries(e.errors || {}).forEach(([k, m]) => { const f = form.querySelector(`[name="${k}"]`); if (f) f.insertAdjacentHTML('afterend', `<span class="facility-field-error">${esc(m)}</span>`); }); }
  function closeDialog() { const d = qs('#facility-dialog'); if (d) window.FAMModal?.closeElement?.(d, () => { d.innerHTML = ''; }); }
  function bindForm(id) { qs('[data-request-form]')?.addEventListener('submit', async ev => { ev.preventDefault(); const form = ev.currentTarget, btn = form.querySelector('[type="submit"]'); btn.disabled = true; clearErrors(form); const data = Object.fromEntries(new FormData(form).entries()); if (!data.facility_space_id) data.facility_space_id = null; try { await window.FAMApi.request(id ? `../api/facility-requests/update.php?id=${id}` : '../api/facility-requests/create.php', { method: 'POST', body: data }); closeDialog(); toast(id ? 'Facility request updated.' : 'Facility request created.'); await loadList(true); if (id) openDetails(id); } catch (e) { showErrors(form, e); toast(errMsg(e)); } finally { btn.disabled = false; } }); }
  function openAssign(id) { ensureDialog(); const d = qs('#facility-dialog'); d.hidden = false; d.innerHTML = `<form class="facility-dialog-panel" data-assign-form><div class="facility-details-modal-header"><div><p>Assignment</p><h2>Assign Facility Request</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close form">&times;</button></div><div class="facility-form-error" data-form-error hidden></div><label class="facility-field"><span>Assigned To</span><select name="assigned_to_employee_reference_id" required>${opt(state.options.assignees, '', 'full_name')}</select></label><label class="facility-field"><span>Remarks</span><textarea name="note" rows="3"></textarea></label><div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Assign</button></div></form>`; qs('[data-assign-form]').addEventListener('submit', async ev => { ev.preventDefault(); const form = ev.currentTarget, btn = form.querySelector('[type="submit"]'); btn.disabled = true; try { await window.FAMApi.request(`../api/facility-requests/assign.php?id=${id}`, { method: 'POST', body: Object.fromEntries(new FormData(form).entries()) }); closeDialog(); toast('Facility request assigned.'); await loadList(true); if (state.detailsId === id) openDetails(id); } catch (e) { showErrors(form, e); toast(errMsg(e)); } finally { btn.disabled = false; } }); }
  async function transition(id, status, label) { const needs = ['COMPLETED','VERIFIED','CLOSED','CANCELLED'].includes(status); const reason = needs ? await window.FAMModal.prompt(`${label} remarks`, '', { title: `${title(label)} Facility Request`, confirmLabel: title(label) }) : 'Status updated from Facility Requests page'; if (needs && reason === null) return; try { await window.FAMApi.request(`../api/facility-requests/transition.php?id=${id}`, { method: 'POST', body: { status, reason } }); toast(`Request ${label}.`); await loadList(true); if (state.detailsId === id) openDetails(id); } catch (e) { toast(errMsg(e)); if ([404,409].includes(e.status)) loadList(true); } }
  function bindEvents() {
    qs('#facility-search')?.addEventListener('input', e => { state.search = e.target.value; state.page = 1; clearTimeout(state.searchTimer); state.searchTimer = setTimeout(() => loadList(true), 350); resetVisible(); });
    qsa('[data-sort]').forEach(b => b.addEventListener('click', () => { const key = sortMap[b.dataset.sort] || b.dataset.sort; state.sortDirection = state.sortKey === key && state.sortDirection === 'asc' ? 'desc' : 'asc'; state.sortKey = key; state.page = 1; loadList(true); }));
    qs('#facility-prev-page')?.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); loadList(true); });
    qs('#facility-next-page')?.addEventListener('click', () => { state.page += 1; loadList(true); });
    qs('#facility-reset-filters')?.addEventListener('click', resetFilters);
    qs('#facility-refresh')?.addEventListener('click', () => loadList(true));
    qs('#facility-export')?.addEventListener('click', () => toast('Export uses the current filtered live view.'));
    qs('#facility-empty-state')?.addEventListener('click', e => { if (e.target.closest('[data-empty-clear]')) resetFilters(); });
    qs('.facility-requests-table')?.addEventListener('click', tableClick);
    document.addEventListener('click', docClick);
    document.addEventListener('keydown', facilityDrawerEscapeCapture, true);
    document.addEventListener('keydown', e => {
      if (e.key === 'Tab' && !qs('#facility-request-drawer')?.hidden) trapDrawerFocus(e);
      if (e.key !== 'Escape') return;
      if (!qs('#facility-dialog')?.hidden) closeDialog();
      else if (!qs('#facility-request-drawer')?.hidden) closeDrawer();
      else if (state.openActionMenu || state.openColumnMenu) { state.openActionMenu = null; state.openColumnMenu = null; renderTable(); }
    });
  }
  function facilityDrawerEscapeCapture(e) { if (e.key === 'Escape' && !qs('#facility-request-drawer')?.hidden && (!qs('#facility-dialog') || qs('#facility-dialog').hidden)) { e.preventDefault(); closeDrawer(); } }
  function tableClick(e) {
    const detail = e.target.closest('[data-open-details]'); if (detail) return openDetails(Number(detail.dataset.openDetails));
    const col = e.target.closest('[data-column-menu-toggle]'); if (col) { state.openColumnMenu = state.openColumnMenu === col.dataset.columnMenuToggle ? null : col.dataset.columnMenuToggle; state.openActionMenu = null; return renderTable(); }
    const sort = e.target.closest('[data-column-sort]'); if (sort) { const cfg = columns[sort.dataset.columnSort]; state.sortKey = sortMap[cfg.sortKey || sort.dataset.columnSort] || cfg.sortKey || sort.dataset.columnSort; state.sortDirection = sort.dataset.sortDirection; state.page = 1; state.openColumnMenu = null; return loadList(true); }
    const clearSort = e.target.closest('[data-column-clear-sort]'); if (clearSort) { state.sortKey = 'created_at'; state.sortDirection = 'desc'; state.openColumnMenu = null; return loadList(true); }
    const filter = e.target.closest('[data-column-filter]'); if (filter) { const cfg = columns[filter.dataset.columnFilter]; state[cfg.filterKey] = filter.dataset.filterValue; state.page = 1; state.openColumnMenu = null; return loadList(true); }
    const clear = e.target.closest('[data-column-clear-filter]'); if (clear) { const cfg = columns[clear.dataset.columnClearFilter]; state[cfg.filterKey] = 'all'; state.page = 1; state.openColumnMenu = null; return loadList(true); }
    const toggle = e.target.closest('[data-action-menu-toggle]'); if (toggle) { state.openActionMenu = null; state.openColumnMenu = null; renderHeaders(); return window.FAMTableMenus?.toggle(toggle, qs(`[data-action-menu-panel="${CSS.escape(toggle.dataset.actionMenuToggle)}"]`)); }
    const action = e.target.closest('[data-request-action]'); if (!action) return; handleRequestAction(action);
  }
  function handleRequestAction(action) { const id = Number(action.dataset.requestId); state.openActionMenu = null; window.FAMTableMenus?.close(); const map = { view: () => openDetails(id), history: () => openDetails(id), edit: () => openEdit(id), assign: () => openAssign(id), start: () => transition(id, 'IN_PROGRESS', 'started'), complete: () => transition(id, 'COMPLETED', 'completed'), verify: () => transition(id, 'VERIFIED', 'verified'), close: () => transition(id, 'CLOSED', 'closed'), cancel: () => transition(id, 'CANCELLED', 'cancelled') }; map[action.dataset.requestAction]?.(); }
  function docClick(e) {
    const requestAction = e.target.closest('[data-request-action]'); if (requestAction) return handleRequestAction(requestAction);
    const drawer = qs('#facility-request-drawer');
    if (e.target.closest('[data-close-drawer]')) return closeDrawer();
    if (drawer && !drawer.hidden && e.target === drawer) return closeDrawer();
    if (e.target.closest('[data-close-dialog]')) return closeDialog();
    if (e.target.closest('.facility-action-menu') || e.target.closest('.facility-action-dropdown') || e.target.closest('.facility-column-menu')) return;
    if (state.openActionMenu || state.openColumnMenu) { state.openActionMenu = null; state.openColumnMenu = null; renderTable(); }
  }
  function trapDrawerFocus(e) {
    const drawer = qs('#facility-request-drawer');
    const focusable = Array.from(drawer?.querySelectorAll('button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])') || []).filter(el => !el.disabled && el.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
  function closeDrawer() {
    const d = qs('#facility-request-drawer');
    if (d) {
      window.FAMModal?.closeElement?.(d, () => {
        d.innerHTML = '';
        document.body.classList.remove('facility-details-modal-open');
        state.detailsId = null;
        state.lastFocusedElement?.focus?.();
        state.lastFocusedElement = null;
      });
    }
  }
  function openDeepLinkedRequest() {
    const id = new URLSearchParams(window.location.search).get('request');
    if (id && /^\d+$/.test(id)) openDetails(Number(id));
  }
  async function init() { closeDrawer(); bindEvents(); renderTable(); try { await auth(); if (state.accessDenied) return renderTable(); await loadOptions(); await loadList(); openDeepLinkedRequest(); } catch (e) { state.loading = false; renderTable(); toast(errMsg(e)); } }
  document.addEventListener('fam:layout-ready', init);
})();




