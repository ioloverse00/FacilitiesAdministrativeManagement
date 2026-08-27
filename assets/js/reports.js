(function () {
  const sections = [
    { key: 'overview', label: 'Overview', endpoint: 'overview.php' },
    { key: 'reservations', label: 'Room Reservations', endpoint: 'reservations.php' },
    { key: 'visitors', label: 'Visitor Management', endpoint: 'visitors.php' },
    { key: 'contracts', label: 'Contract Management', endpoint: 'contracts.php' },
    { key: 'retention', label: 'Records Retention', endpoint: 'retention.php' }
  ];
  const charts = {};
  const state = { section: 'overview', filters: currentMonthRange(), data: null };
  const qs = s => document.querySelector(s);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const title = v => String(v || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  const fmt = v => Number(v || 0).toLocaleString();
  const money = v => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function currentMonthRange() {
    const now = new Date();
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    const to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    return { from: isoDate(from), to: isoDate(to) };
  }

  function isoDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function params() {
    return new URLSearchParams({ from: state.filters.from, to: state.filters.to }).toString();
  }

  function activeSection() {
    return sections.find(item => item.key === state.section) || sections[0];
  }

  function initFilters() {
    const form = qs('#reports-filters');
    if (!form) return;
    form.elements.from.value = state.filters.from;
    form.elements.to.value = state.filters.to;
    const select = qs('#reports-section-select');
    select.innerHTML = sections.map(item => `<option value="${esc(item.key)}">${esc(item.label)}</option>`).join('');
    select.value = state.section;
  }

  function renderTabs() {
    const target = qs('#reports-tabs');
    if (!target) return;
    target.innerHTML = sections.map(item => `<button class="reports-tab ${item.key === state.section ? 'active' : ''}" type="button" role="tab" aria-selected="${item.key === state.section ? 'true' : 'false'}" data-report-section="${esc(item.key)}">${esc(item.label)}</button>`).join('');
  }

  function showLoading(show) {
    qs('#reports-loading-state')?.classList.toggle('hidden', !show);
  }

  function showError(message = '') {
    const box = qs('#reports-error');
    if (!box) return;
    box.hidden = !message;
    box.textContent = message;
  }

  async function load() {
    showLoading(true);
    showError('');
    destroyCharts();
    try {
      const section = activeSection();
      const payload = await window.FAMApi.request(`../api/reports/${section.endpoint}?${params()}`);
      state.data = payload.data || {};
      render();
      qs('#reports-updated').textContent = `Last updated: ${new Date().toLocaleString()}`;
    } catch (error) {
      qs('#reports-panel').innerHTML = emptyState(error.message || 'Unable to load reports.');
      showError(error.message || 'Unable to load reports.');
    } finally {
      showLoading(false);
    }
  }

  function render() {
    renderTabs();
    qs('#reports-section-select').value = state.section;
    if (state.section === 'overview') return renderOverview(state.data.sections || {});
    renderSection(state.data, activeSection().label);
  }

  function renderOverview(sectionsData) {
    const cards = sections.filter(s => s.key !== 'overview').map(s => {
      const data = sectionsData[s.key] || {};
      const kpis = data.kpis || {};
      const first = Object.entries(kpis).slice(0, 4);
      return `<article class="fam-card report-card">
        <div class="report-card-body">
          <h3>${esc(s.label)}</h3>
          <dl class="reports-mini-kpis">${first.map(([k, v]) => `<div><dt>${esc(title(k))}</dt><dd>${esc(displayValue(k, v))}</dd></div>`).join('')}</dl>
          <button class="btn-secondary dashboard-action-button" type="button" data-report-section="${esc(s.key)}">Open Analysis</button>
        </div>
      </article>`;
    }).join('');
    qs('#reports-panel').innerHTML = `<div class="reports-grid">${cards || emptyState('No data for selected period.')}</div>`;
  }

  function renderSection(data, heading) {
    const kpis = data.kpis || {};
    const breakdowns = data.breakdowns || {};
    const table = data.table || [];
    qs('#reports-panel').innerHTML = `<div class="facility-table-card reports-analysis-card">
      <div class="facility-table-header">
        <div>
          <h2>${esc(heading)}</h2>
          <p>${esc(state.filters.from)} to ${esc(state.filters.to)}</p>
        </div>
        ${data.links?.module ? `<a class="btn-secondary dashboard-action-button" href="${esc(data.links.module)}"><span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>Open Module</a>` : ''}
      </div>
      ${kpiGrid(kpis)}
      <div class="fam-chart-grid reports-chart-grid">
        ${chartBox('trend-chart', `${heading} Trend`, data.trend)}
        ${chartBox('breakdown-chart', 'Primary Breakdown', breakdowns.status || breakdowns.state || breakdowns.type || [])}
      </div>
      ${summaryTable(table)}
    </div>`;
    createChart('trend-chart', data.trend || [], 'line');
    createChart('breakdown-chart', breakdowns.status || breakdowns.state || breakdowns.type || [], 'bar');
  }

  function kpiGrid(kpis) {
    const entries = Object.entries(kpis);
    if (!entries.length) return emptyState('No data for selected period.');
    return `<div class="fam-kpi-grid reports-kpi-grid">${entries.map(([key, value]) => `<article class="fam-kpi-card">
      <span>${esc(title(key))}</span>
      <strong>${esc(displayValue(key, value))}</strong>
    </article>`).join('')}</div>`;
  }

  function displayValue(key, value) {
    if (value === null || value === undefined) return 'No data';
    if (key.includes('rate')) return `${Number(value || 0).toFixed(1)}%`;
    if (key.includes('value')) return money(value);
    if (key.includes('minutes')) return `${Number(value || 0).toFixed(1)} min`;
    return fmt(value);
  }

  function chartBox(id, heading, rows = []) {
    return `<article class="fam-card fam-chart-card">
      <div class="fam-card-header"><h3>${esc(heading)}</h3></div>
      ${hasValues(rows) ? `<div class="fam-chart-box"><canvas id="${esc(id)}" role="img" aria-label="${esc(heading)}"></canvas></div>` : emptyState('No data for selected period.')}
    </article>`;
  }

  function summaryTable(rows = []) {
    if (!rows.length) return emptyState('No data for selected period.');
    return `<div class="facility-table-scroll"><table class="facility-requests-table reports-summary-table">
      <thead><tr><th>Label</th><th>Context</th><th>Count</th></tr></thead>
      <tbody>${rows.map(row => `<tr><td>${esc(row.label)}</td><td>${esc(row.secondary || '')}</td><td>${esc(fmt(row.value))}</td></tr>`).join('')}</tbody>
    </table></div>`;
  }

  function emptyState(message) {
    return `<div class="facility-empty-state"><span class="material-symbols-outlined" aria-hidden="true">bar_chart</span><strong>${esc(message)}</strong></div>`;
  }

  function hasValues(rows = []) {
    return rows.some(row => Number(row.value || 0) > 0);
  }

  function createChart(id, rows, type) {
    const canvas = qs(`#${id}`);
    if (!canvas || !window.Chart || !hasValues(rows)) return;
    charts[id] = new Chart(canvas, {
      type,
      data: {
        labels: rows.map(row => title(row.label)),
        datasets: [{ label: 'Count', data: rows.map(row => Number(row.value || 0)), tension: 0.25, borderWidth: 2 }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
  }

  function destroyCharts() {
    Object.values(charts).forEach(chart => chart?.destroy?.());
    Object.keys(charts).forEach(key => delete charts[key]);
  }

  function setSection(key) {
    state.section = sections.some(item => item.key === key) ? key : 'overview';
    load();
  }

  function bind() {
    qs('#reports-refresh')?.addEventListener('click', load);
    qs('#reports-filters')?.addEventListener('submit', event => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form.reportValidity()) return;
      state.filters = { from: form.elements.from.value, to: form.elements.to.value };
      state.section = form.elements.section.value || state.section;
      load();
    });
    document.addEventListener('click', event => {
      const button = event.target.closest('[data-report-section]');
      if (button) setSection(button.dataset.reportSection);
    });
  }

  document.addEventListener('fam:layout-ready', () => {
    initFilters();
    renderTabs();
    bind();
    load();
  });
})();
