(function () {
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const title = value => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const total = rows => (rows || []).reduce((sum, item) => sum + Number(item.value || 0), 0);
    const list = (heading, rows) => `<article class="report-card"><div class="report-card-body"><h3>${esc(heading)}</h3>${rows?.length ? `<p>${rows.map(r => `${esc(title(r.label))}: ${esc(r.value)}`).join('<br>')}</p>` : '<p>No operational data available.</p>'}</div></article>`;
    function renderEmpty() {
        document.getElementById('reports-empty-state')?.classList.remove('hidden');
        document.getElementById('reports-empty-state').innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">bar_chart</span><strong>No operational data is available for the selected period.</strong><span>Reports will populate from live database records only.</span>';
    }
    async function load() {
        const loading = document.getElementById('reports-loading-state');
        loading?.classList.remove('hidden');
        try {
            const payload = await window.FAMApi.request('../api/reports/overview.php');
            const o = payload.data?.overview || {};
            document.getElementById('reports-updated').textContent = `Last updated: ${new Date().toLocaleString()}`;
            document.getElementById('report-categories').innerHTML = '';
            document.getElementById('report-library').innerHTML = [
                list('Facility Requests by Status', o.facilityRequestsByStatus), list('Facility Requests by Priority', o.facilityRequestsByPriority),
                list('Maintenance by Status', o.maintenanceByStatus), list('Assets by Condition', o.assetsByCondition),
                list('Reservations by Status', o.reservationsByStatus), list('Procurement by Status', o.procurementByStatus),
                list('Records by Status', o.recordsByStatus), list('SLA Overview', o.sla)
            ].join('');
            document.getElementById('recent-reports-table').innerHTML = '';
            document.getElementById('recent-reports-count').textContent = 'No generated report files';
            document.getElementById('recent-reports-empty-state').classList.remove('hidden');
            document.getElementById('recent-reports-empty-state').innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">history</span><strong>No generated reports found.</strong><span>Report file generation is deferred to a later workflow.</span>';
            document.getElementById('reports-insights').innerHTML = '';
            if (!Object.values(o).some(v => Array.isArray(v) && v.length && total(v) > 0)) renderEmpty();
        } catch (error) {
            window.FAMModal?.showToast(error.message || 'Unable to load reports overview.');
            renderEmpty();
        } finally { loading?.classList.add('hidden'); }
    }
    document.addEventListener('fam:layout-ready', () => {
        document.getElementById('reports-refresh')?.addEventListener('click', load);
        document.getElementById('generate-report-main')?.addEventListener('click', () => window.FAMModal?.showToast('Report generation will be enabled in a later implementation step.'));
        document.getElementById('export-history')?.addEventListener('click', () => window.FAMModal?.showToast('Report export will be enabled in a later implementation step.'));
        load();
    });
})();
