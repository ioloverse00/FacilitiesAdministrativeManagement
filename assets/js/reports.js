(function () {
  const REPORT_DEFINITIONS = {
    facility_requests: {
      tabTitle: 'Facility Requests',
      eyebrow: 'Facility Requests Report',
      fallbackTitle: 'Facility Requests Report',
      filters: ['date_from', 'date_to', 'status', 'priority', 'department_id'],
      analysis: {
        categorical: { dataset: 'charts.Priority', title: 'Requests by Priority', type: 'bar' },
        distribution: { dataset: 'charts.Status', title: 'Request Status Distribution', type: 'doughnut' },
        timeline: { dataset: 'charts.Request Activity Over Time', title: 'Request Activity Over Time', type: 'line' }
      },
      table: { title: 'Facility Request Register', rows: 'rows', columns: 'columns', count: 'rows.length', badgeColumns: ['Status', 'Approval'] },
      csv: true
    },
    documents_records: {
      tabTitle: 'Documents & Records',
      eyebrow: 'Documents & Records',
      fallbackTitle: 'Documents & Records Report',
      description: data => data.description || 'Metadata reporting over documents and retention records.',
      filters: context => context.source === 'records'
        ? ['source', 'date_from', 'date_to', 'department_id', 'status', 'retention_schedule_id', 'retention_state']
        : ['source', 'date_from', 'date_to', 'status', 'category_id', 'confidentiality'],
      analysis: context => context.source === 'records' ? {
        categorical: { dataset: 'records.charts.Records by Schedule', title: 'Records by Schedule', type: 'horizontalBar' },
        distribution: { dataset: 'records.charts.Records by Retention State', title: 'Records by Retention State', type: 'doughnut' },
        timeline: { dataset: 'records.charts.Upcoming Disposition Timeline', title: 'Upcoming Disposition Timeline', type: 'line' }
      } : {
        categorical: { dataset: 'documents.charts.Documents by Category', title: 'Documents by Category', type: 'horizontalBar' },
        distribution: { dataset: 'documents.charts.Confidentiality Distribution', title: 'Confidentiality Distribution', type: 'doughnut' },
        timeline: { dataset: 'documents.charts.Document Activity Over Time', title: 'Document Activity Over Time', type: 'line' }
      },
      table: context => ({
        title: context.source === 'records' ? 'Records Retention' : 'Documents',
        rows: context.source === 'records' ? 'records.rows' : 'documents.rows',
        columns: context.source === 'records' ? 'records.columns' : 'documents.columns',
        count: context.source === 'records' ? 'records.count' : 'documents.count',
        badgeColumns: context.source === 'records' ? ['Status', 'Retention State'] : ['Status', 'Confidentiality']
      }),
      csv: true
    },
    contracts: {
      tabTitle: 'Contracts',
      eyebrow: 'Contracts Report',
      fallbackTitle: 'Contracts Report',
      fallbackDescription: 'Contract lifecycle, type, and expiry analysis',
      filters: ['date_basis', 'date_from', 'date_to', 'status', 'type_id', 'department_id'],
      analysis: {
        categorical: { dataset: 'charts.Contracts by Type', title: 'Contracts by Type', type: 'horizontalBar' },
        distribution: { dataset: 'charts.Contracts by Lifecycle Status', title: 'Contracts by Lifecycle Status', type: 'doughnut' },
        timeline: { dataset: 'charts.Contract Expiry Timeline', title: 'Contract Expiry Timeline', type: 'line' }
      },
      table: { title: 'Contract Register', rows: 'rows', columns: 'columns', count: 'record_count', badgeColumns: ['Status'] },
      csv: true
    },
    legal_management: {
      tabTitle: 'Legal Matter',
      eyebrow: 'Legal Matter',
      fallbackTitle: 'Legal Matter Report',
      filters: ['date_from', 'date_to', 'type', 'priority', 'status', 'department_id', 'assignee_id'],
      analysis: {
        categorical: { dataset: 'charts.Legal Matters by Type', title: 'Legal Matters by Type', type: 'horizontalBar' },
        distribution: { dataset: 'charts.Legal Matter Status Distribution', title: 'Legal Matter Status Distribution', type: 'doughnut' },
        timeline: { dataset: 'charts.Legal Matter Activity Over Time', title: 'Legal Matter Activity Over Time', type: 'line' }
      },
      table: { title: 'Legal Matter Register', rows: 'rows', columns: 'columns', count: 'record_count', badgeColumns: ['Priority', 'Status'], empty: 'No legal matters match the selected filters.' },
      csv: false
    }
  };

  const FILTER_DEFINITIONS = {
    source: { label: 'Source', type: 'select', options: 'sources', defaultValue: 'documents', requiredChoice: true },
    date_basis: { label: 'Date Basis', type: 'select', options: 'date_basises', defaultValue: 'effective_start', requiredChoice: true },
    date_from: { label: 'From', type: 'date' },
    date_to: { label: 'To', type: 'date' },
    status: { label: context => context.report === 'contracts' ? 'Lifecycle Status' : 'Status', type: 'select', options: context => context.report === 'documents_records' && context.source === 'records' ? 'record_statuses' : 'statuses' },
    priority: { label: 'Priority', type: 'select', options: 'priorities' },
    type: { label: context => context.report === 'legal_management' ? 'Matter Type' : 'Type', type: 'select', options: 'types' },
    confidentiality: { label: 'Confidentiality', type: 'select', options: 'confidentiality_levels' },
    department_id: { label: 'Department', type: 'select', options: 'departments' },
    category_id: { label: 'Category', type: 'select', options: 'categories' },
    type_id: { label: 'Contract Type', type: 'select', options: 'contract_types' },
    retention_schedule_id: { label: 'Retention Schedule', type: 'select', options: 'retention_schedules' },
    retention_state: { label: 'Retention State', type: 'select', options: 'retention_states' },
    assignee_id: { label: 'Assignee', type: 'select', options: 'assignees' }
  };

  const REPORT_ORDER = ['facility_requests', 'documents_records', 'contracts', 'legal_management'];
  const state = { report: 'contracts', filtersByReport: {}, data: null, loading: false };
  const charts = {};
  const qs = selector => document.querySelector(selector);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const title = value => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
  const number = value => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
  const palette = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#0f766e', '#7c3aed', '#64748b', '#0891b2', '#be123c', '#4b5563', '#65a30d', '#9333ea'];
  const statusColors = {
    draft: '#f59e0b',
    'for review': '#2563eb',
    'for approval': '#7c3aed',
    approved: '#16a34a',
    active: '#0f766e',
    expired: '#64748b',
    rejected: '#dc2626',
    cancelled: '#4b5563',
    canceled: '#4b5563'
  };

  function currentFilters() {
    return state.filtersByReport[state.report] || {};
  }

  function setCurrentFilters(filters) {
    state.filtersByReport[state.report] = filters;
  }

  function context(data = state.data) {
    const filters = currentFilters();
    return {
      report: state.report,
      filters,
      source: state.report === 'documents_records' ? (filters.source || data?.source || 'documents') : ''
    };
  }

  function definition() {
    return REPORT_DEFINITIONS[state.report] || REPORT_DEFINITIONS.contracts;
  }

  function params() {
    const query = new URLSearchParams({ report: state.report });
    Object.entries(currentFilters()).forEach(([key, value]) => { if (value) query.set(key, value); });
    return query.toString();
  }

  function renderTabs(available = []) {
    const allowed = new Map(available.map(item => [item.key, item]));
    qs('#reports-tabs').innerHTML = REPORT_ORDER.map(key => {
      const report = REPORT_DEFINITIONS[key];
      const item = allowed.get(key);
      const disabled = item && !item.available;
      return `<button class="reports-tab ${state.report === key ? 'active' : ''}" type="button" role="tab" aria-selected="${state.report === key}" data-report="${esc(key)}" ${disabled ? 'disabled title="Permission required"' : ''}>${esc(report.tabTitle)}</button>`;
    }).join('');
  }

  function showLoading(show) {
    qs('#reports-loading-state')?.classList.toggle('hidden', !show);
  }

  function showError(message) {
    const box = qs('#reports-error');
    if (!box) return;
    box.hidden = !message;
    box.textContent = message || '';
  }

  async function load() {
    if (state.loading) return;
    state.loading = true;
    showLoading(true);
    showError('');
    destroyCharts();
    qs('#reports-panel').innerHTML = '';
    try {
      const payload = await window.FAMApi.request(`../api/reports/overview.php?${params()}`);
      state.data = payload.data || {};
      updateTimestamp();
      renderReport();
    } catch (error) {
      qs('#reports-panel').innerHTML = emptyState(error.message || 'Unable to load report data.', 'error');
      hideFilters();
      syncExportControls();
      showError(error.message || 'Unable to load report data.');
    } finally {
      showLoading(false);
      state.loading = false;
    }
  }

  function renderReport() {
    const def = definition();
    const ctx = context();
    renderTabs(state.data.available_reports || []);
    renderFilters(def, ctx, state.data.filter_options || {});
    qs('#reports-panel').innerHTML = `
      <div class="reports-section-stack reports-${state.report.replace(/_/g, '-')}-report">
        ${renderAnalysis(def, ctx)}
        ${renderDetailedReport(def, ctx)}
      </div>`;
    syncExportControls();
    createConfiguredCharts(def, ctx);
  }

  function renderAnalysis(def, ctx) {
    const slots = analysisSlots(def, ctx);
    return `<div class="reports-analysis-chart-grid">
        ${chartBox('reports-categorical-chart', chartEntry(slots.categorical, 'categorical'))}
        ${chartBox('reports-distribution-chart', chartEntry(slots.distribution, 'distribution'))}
        ${chartBox('reports-timeline-chart', chartEntry({ ...slots.timeline, wide: true }, 'timeline'))}
      </div>`;
  }

  function renderDetailedReport(def, ctx) {
    const config = tableConfig(def, ctx);
    const columns = getPath(state.data, config.columns) || [];
    const rows = getPath(state.data, config.rows) || [];
    const count = getCount(config.count, rows);
    const countHtml = typeof count === 'number' ? `<span class="reports-record-count">${esc(number(count))} matching records</span>` : '';
    return `<section class="facility-table-card reports-analysis-card">
      <div class="facility-table-header">
        <div>
          <p class="facility-section-eyebrow">Detailed Report</p>
          <h2>${esc(valueOf(config.title, state.data, ctx) || 'Report Register')}</h2>
        </div>
        <div class="reports-detail-controls">
          ${countHtml}
          ${exportActionsHtml()}
        </div>
      </div>
      ${table(columns, rows, config)}
    </section>`;
  }

  function exportActionsHtml() {
    return `<button id="reports-export-csv" class="btn-primary dashboard-action-button" type="button"><span class="material-symbols-outlined" aria-hidden="true">download</span>Export CSV</button>
      <button id="reports-export-pdf" class="btn-primary dashboard-action-button reports-export-button" type="button" disabled title="PDF export is queued for the next reporting phase."><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>Export PDF</button>`;
  }

  function renderFilters(def, ctx, options) {
    const form = qs('#reports-filters');
    if (!form) return;
    form.className = 'reports-filter-grid is-ready';
    form.classList.add(`reports-${state.report.replace(/_/g, '-')}-filters`);
    const fields = filterFields(def, ctx, options);
    form.querySelectorAll('label[data-filter]').forEach(label => {
      const name = label.dataset.filter;
      const field = fields.find(item => item.name === name);
      const visible = Boolean(field);
      label.hidden = !visible;
      label.classList.toggle('hidden', !visible);
      label.style.order = visible ? String(fields.indexOf(field) + 1) : '';
      if (visible) populateFilter(label, field, ctx, options);
    });
    const actions = form.querySelector('.reports-filter-actions');
    if (actions) actions.hidden = fields.length === 0;
  }

  function filterFields(def, ctx, options) {
    const configured = valueOf(def.filters, state.data, ctx) || [];
    return configured.map(name => ({ name, ...FILTER_DEFINITIONS[name] }))
      .filter(field => field.type === 'date' || optionList(field, ctx, options).length || field.requiredChoice);
  }

  function populateFilter(label, field, ctx, options) {
    const labelText = label.querySelector('span');
    if (labelText) labelText.textContent = valueOf(field.label, ctx) || field.name;
    const input = label.querySelector('input, select');
    if (!input) return;
    input.value = currentFilters()[field.name] || field.defaultValue || '';
    if (field.type !== 'select') return;
    const list = optionList(field, ctx, options);
    const emptyOption = field.requiredChoice ? '' : '<option value="">All</option>';
    input.innerHTML = emptyOption + list.map(item => {
      const value = typeof item === 'object' ? item.id : item;
      const text = typeof item === 'object' ? item.name : title(item);
      return `<option value="${esc(value)}">${esc(text)}</option>`;
    }).join('');
    input.value = currentFilters()[field.name] || field.defaultValue || '';
  }

  function hideFilters() {
    const form = qs('#reports-filters');
    form?.querySelectorAll('label[data-filter]').forEach(label => {
      label.hidden = true;
      label.classList.add('hidden');
    });
    const actions = form?.querySelector('.reports-filter-actions');
    if (actions) actions.hidden = true;
  }

  function optionList(field, ctx, options) {
    const key = valueOf(field.options, ctx);
    return key ? (options[key] || []) : [];
  }

  function syncExportControls() {
    const csv = qs('#reports-export-csv');
    if (!csv) return;
    const enabled = state.data?.export?.csv === true && definition().csv !== false;
    csv.disabled = !enabled;
    csv.classList.toggle('is-disabled', !enabled);
    csv.title = enabled ? '' : 'CSV export is not available for this report yet.';
  }

  function updateTimestamp() {
    const updated = qs('#reports-updated');
    if (!updated) return;
    updated.textContent = `Last updated: ${new Date().toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
  }

  function createConfiguredCharts(def, ctx) {
    const slots = analysisSlots(def, ctx);
    createChart('reports-categorical-chart', chartEntry(slots.categorical, 'categorical'));
    createChart('reports-distribution-chart', chartEntry(slots.distribution, 'distribution'));
    createChart('reports-timeline-chart', chartEntry({ ...slots.timeline, wide: true }, 'timeline'));
  }

  function analysisSlots(def, ctx) {
    return valueOf(def.analysis, ctx, state.data) || {};
  }

  function chartEntry(chart, slot) {
    if (!chart) return [slotTitle(slot), { type: slotType(slot), rows: [], unavailable: true }];
    const config = getPath(state.data, chart.dataset);
    const titleText = chart.title || chart.dataset?.split('.').pop() || slotTitle(slot);
    if (!config) return [titleText, { type: chart.type || slotType(slot), rows: [], wide: chart.wide, unavailable: true }];
    if (Array.isArray(config)) {
      return [titleText, { type: chart.type || slotType(slot), rows: config, wide: chart.wide }];
    }
    return [titleText, { ...config, type: chart.type || config.type || slotType(slot), wide: chart.wide ?? config.wide }];
  }

  function chartBox(id, entry) {
    if (!entry) return emptyState('No chart is configured for this report.');
    const [heading, config] = entry;
    const rows = Array.isArray(config) ? config : config.rows;
    const wide = !Array.isArray(config) && config.wide;
    const type = !Array.isArray(config) && config.type ? config.type : 'bar';
    const classes = ['fam-card', 'fam-chart-card', 'reports-chart-card', `reports-chart-card-${type}`];
    if (wide) classes.push('reports-chart-card-wide');
    if (!hasChartData(rows)) return `<article class="${classes.join(' ')}"><div class="fam-card-header"><h3>${esc(heading)}</h3></div>${emptyState(config.unavailable ? 'No dataset is configured for this chart slot yet.' : 'No data available for the selected filters.')}</article>`;
    if (isLowInformationChart(type, rows)) return `<article class="${classes.join(' ')} reports-chart-card-compact"><div class="fam-card-header"><h3>${esc(heading)}</h3></div><p class="reports-chart-note">${esc(lowInformationMessage(heading, rows))}</p></article>`;
    return `<article class="${classes.join(' ')}"><div class="fam-card-header"><h3>${esc(heading)}</h3></div><div class="fam-chart-box"><canvas id="${esc(id)}"></canvas></div></article>`;
  }

  function createChart(id, entry) {
    if (!entry || !window.Chart) return;
    const [, config] = entry;
    const rows = Array.isArray(config) ? config : config.rows;
    const type = !Array.isArray(config) && config.type ? config.type : 'bar';
    const chartType = type === 'horizontalBar' ? 'bar' : type;
    const canvas = qs(`#${id}`);
    if (!canvas || !hasChartData(rows) || isLowInformationChart(type, rows)) return;
    const labels = rows.map(row => title(row.label));
    const data = rows.map(row => Number(row.value || 0));
    const colors = labels.map((label, index) => statusColors[label.toLowerCase()] || palette[index % palette.length]);
    const isDoughnut = chartType === 'doughnut';
    const isLine = chartType === 'line';
    const isHorizontal = type === 'horizontalBar';
    const isSingleLinePoint = isLine && data.length === 1;
    charts[id] = new Chart(canvas, {
      type: chartType,
      data: {
        labels,
        datasets: [{
          label: reportNoun(),
          data,
          borderWidth: isLine ? 2 : 1,
          borderColor: isLine ? '#2563eb' : colors,
          backgroundColor: isLine ? 'rgba(37, 99, 235, 0.12)' : colors,
          pointBackgroundColor: isLine ? '#2563eb' : colors,
          pointBorderColor: '#fff',
          pointRadius: isSingleLinePoint ? 6 : (isLine ? 3 : 0),
          pointHoverRadius: isSingleLinePoint ? 7 : (isLine ? 4 : 0),
          tension: isLine ? 0.28 : 0,
          fill: isLine && !isSingleLinePoint,
          showLine: !isSingleLinePoint
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        cutout: isDoughnut ? '62%' : undefined,
        indexAxis: isHorizontal ? 'y' : 'x',
        plugins: {
          legend: { display: isDoughnut, position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
          tooltip: { callbacks: { label: context => `${context.label}: ${number(context.parsed?.y ?? context.parsed?.x ?? context.parsed)} ${reportNoun()}` } }
        },
        scales: isDoughnut ? {} : {
          x: { beginAtZero: isHorizontal, offset: isSingleLinePoint, ticks: { precision: 0, maxRotation: isLine ? 0 : 45, autoSkip: true } },
          y: { beginAtZero: !isHorizontal, suggestedMax: isSingleLinePoint ? Math.max(2, data[0] + 1) : undefined, ticks: { precision: 0 } }
        }
      }
    });
  }

  function table(columns, rows, config = {}) {
    if (!rows.length) return emptyState(config.empty || 'No records match the selected filters.', 'table_chart');
    return `<div class="facility-table-scroll reports-table-scroll"><table class="facility-requests-table reports-summary-table"><thead><tr>${columns.map(column => `<th>${esc(column)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map((cell, index) => `<td>${cellHtml(columns[index], cell, config)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  }

  function cellHtml(column, cell, config) {
    if ((config.badgeColumns || []).includes(column)) {
      const label = title(cell);
      const className = label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'unknown';
      return `<span class="facility-badge facility-status-${esc(className)}">${esc(label)}</span>`;
    }
    return esc(cell);
  }

  function emptyState(message, icon = 'bar_chart') {
    return `<div class="facility-empty-state reports-empty-state"><span class="material-symbols-outlined" aria-hidden="true">${esc(icon)}</span><strong>${esc(message)}</strong></div>`;
  }

  function hasChartData(rows = []) {
    return Array.isArray(rows) && rows.some(row => Number(row.value || 0) > 0);
  }

  function isLowInformationChart(type, rows = []) {
    if (type === 'line' || !Array.isArray(rows) || rows.length !== 1) return false;
    return Number(rows[0]?.value || 0) > 0;
  }

  function lowInformationMessage(heading, rows = []) {
    const row = rows[0] || {};
    const label = title(row.label);
    const count = number(row.value || 0);
    if (/confidentiality/i.test(heading)) return `All matching documents are ${label}.`;
    if (/category/i.test(heading)) return `All ${count} matching documents are in ${label}.`;
    if (/retention state/i.test(heading)) return `All ${count} matching records are ${label}.`;
    return `All matching records are grouped as ${label}.`;
  }

  function destroyCharts() {
    Object.values(charts).forEach(chart => chart?.destroy?.());
    Object.keys(charts).forEach(key => delete charts[key]);
  }

  function readFilters(form) {
    const fields = filterFields(definition(), context(), state.data?.filter_options || []);
    const allowed = new Set(fields.map(field => field.name));
    const next = {};
    Array.from(form.elements).forEach(element => {
      if (!element.name || !allowed.has(element.name) || element.disabled || element.closest('[hidden], .hidden')) return;
      if (element.value) next[element.name] = element.value;
    });
    setCurrentFilters(next);
  }

  function tableConfig(def, ctx) {
    return valueOf(def.table, ctx, state.data) || {};
  }

  function getCount(path, rows) {
    if (path === 'rows.length') return rows.length;
    const value = getPath(state.data, path);
    return value === undefined || value === null ? rows.length : Number(value);
  }

  function getPath(source, path) {
    if (!source || !path) return undefined;
    return String(path).split('.').reduce((value, key) => value?.[key], source);
  }

  function valueOf(value, ...args) {
    return typeof value === 'function' ? value(...args) : value;
  }

  function reportNoun() {
    return ({
      contracts: 'contracts',
      documents_records: 'items',
      facility_requests: 'requests',
      legal_management: 'matters'
    })[state.report] || 'records';
  }

  function slotTitle(slot) {
    return ({ categorical: 'Categorical Chart', distribution: 'Distribution', timeline: 'Activity Over Time' })[slot] || 'Chart';
  }

  function slotType(slot) {
    return ({ categorical: 'bar', distribution: 'doughnut', timeline: 'line' })[slot] || 'bar';
  }

  function bind() {
    qs('#reports-refresh')?.addEventListener('click', load);
    qs('#reports-panel')?.addEventListener('click', event => {
      const csv = event.target.closest('#reports-export-csv');
      const pdf = event.target.closest('#reports-export-pdf');
      if (!csv && !pdf) return;
      if (pdf) {
        showError('PDF export is planned for the next reporting phase after selecting a Composer PDF library.');
        return;
      }
      if (csv.disabled || state.data?.export?.csv !== true || definition().csv === false) {
        showError('CSV export is not available for this report yet.');
        return;
      }
      window.location.href = `../api/reports/export-csv.php?${params()}`;
    });
    qs('#reports-reset')?.addEventListener('click', () => { setCurrentFilters({}); load(); });
    qs('#reports-filters')?.addEventListener('submit', event => {
      event.preventDefault();
      readFilters(event.currentTarget);
      load();
    });
    document.addEventListener('click', event => {
      const tab = event.target.closest('[data-report]');
      if (!tab || tab.disabled) return;
      state.report = tab.dataset.report;
      if (!state.filtersByReport[state.report]) setCurrentFilters({});
      load();
    });
    qs('#reports-filters')?.addEventListener('change', event => {
      if (state.report !== 'documents_records' || event.target.name !== 'source') return;
      setCurrentFilters({ source: event.target.value || 'documents' });
      load();
    });
  }

  document.addEventListener('fam:layout-ready', () => {
    bind();
    renderTabs();
    load();
  });
})();
