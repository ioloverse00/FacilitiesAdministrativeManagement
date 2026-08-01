(function () {
    const statusClassMap = {
        critical: 'fam-status-critical',
        warning: 'fam-status-warning',
        informational: 'fam-status-info',
        'due soon': 'fam-status-warning',
        'in progress': 'fam-status-progress',
        scheduled: 'fam-status-scheduled',
        pending: 'fam-status-pending',
        completed: 'fam-status-completed',
        confirmed: 'fam-status-completed',
        assigned: 'fam-status-progress',
        approved: 'fam-status-completed',
        unavailable: 'fam-status-critical',
        'needs attention': 'fam-status-warning',
        'awaiting approval': 'fam-status-pending',
        'for approval': 'fam-status-pending',
        review: 'fam-status-pending',
        conflict: 'fam-status-critical',
        'in transit': 'fam-status-scheduled'
    };

    const iconMap = {
        'Facility Requests': 'domain',
        Maintenance: 'build',
        'Maintenance Requests': 'build',
        'Asset Management': 'inventory_2',
        'Room Reservations': 'meeting_room',
        Procurement: 'shopping_bag',
        'Administrative Records': 'folder'
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    }

    function statusBadge(status) {
        const normalized = String(status || '').toLowerCase();
        return `<span class="fam-status-badge ${statusClassMap[normalized] || 'fam-status-inactive'}">${escapeHtml(status)}</span>`;
    }

    function stateMessage(message, icon = 'info') {
        return `<div class="fam-state" role="status"><span class="material-symbols-outlined" aria-hidden="true">${icon}</span><span>${escapeHtml(message)}</span></div>`;
    }

    function setLoading() {
        document.querySelectorAll('[data-state-container]').forEach(container => {
            container.innerHTML = stateMessage('Loading dashboard summary...', 'progress_activity');
        });
    }

    function formatUpdatedAt(value) {
        const date = value ? new Date(value) : new Date();
        return `Last updated: ${date.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}, ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    }

    function renderAlerts(alerts) {
        const target = document.getElementById('dashboard-alerts');
        if (!target) return;
        if (!alerts?.length) {
            target.innerHTML = stateMessage('No urgent items require attention.', 'check_circle');
            return;
        }
        target.innerHTML = alerts.map(alert => `
            <a class="fam-alert-card fam-alert-${String(alert.severity).toLowerCase()}" href="${alert.href}">
                <span class="fam-alert-severity">${escapeHtml(alert.severity)}</span>
                <span class="fam-alert-copy">${escapeHtml(alert.message)}</span>
                <span class="fam-alert-count" aria-label="${escapeHtml(alert.count)} items">${escapeHtml(alert.count)}</span>
            </a>
        `).join('');
    }

    function renderKpis(kpis) {
        const target = document.getElementById('dashboard-kpis');
        if (!target) return;
        if (!kpis?.length) {
            target.innerHTML = stateMessage('No KPI data available.', 'query_stats');
            return;
        }
        target.innerHTML = kpis.map(kpi => `
            <a class="fam-card fam-kpi-card" href="${kpi.href}" aria-label="${escapeHtml(kpi.label)}">
                <div class="fam-kpi-top">
                    <div>
                        <p>${escapeHtml(kpi.label)}</p>
                        <h3>${escapeHtml(kpi.value)}</h3>
                    </div>
                    <span class="fam-kpi-icon material-symbols-outlined" aria-hidden="true">${kpi.icon}</span>
                </div>
                <p class="fam-kpi-main-label">${escapeHtml(kpi.supporting)}</p>
                <div class="fam-kpi-footer">
                    ${statusBadge(kpi.status)}
                    <span>${escapeHtml(kpi.trend)}</span>
                </div>
            </a>
        `).join('');
    }

    function renderCharts(data) {
        const chartApi = window.FAMDashboardCharts;
        const trendState = document.getElementById('requests-trend-state');
        const trendCanvas = document.getElementById('requests-trend-chart');
        const statusState = document.getElementById('request-status-state');
        const statusCanvas = document.getElementById('request-status-chart');
        const totalTarget = document.getElementById('active-request-total');

        chartApi?.destroyAllCharts();

        if (!window.Chart || !chartApi) {
            [trendState, statusState].forEach(state => { if (state) state.innerHTML = stateMessage('Unable to load chart library.', 'error'); });
            return;
        }

        const trendValues = [
            ...(data.requestTrend?.facilityRequests || []),
            ...(data.requestTrend?.maintenanceRequests || []),
            ...(data.requestTrend?.completedRequests || [])
        ];
        if (trendCanvas && trendState) {
            if (chartApi.hasValues(trendValues)) {
                trendState.innerHTML = '';
                chartApi.createRequestsTrendChart(trendCanvas, data.requestTrend);
            } else {
                trendState.innerHTML = stateMessage('No request trend data available.', 'bar_chart');
            }
        }

        const statusValues = (data.requestStatus || []).map(item => item.value);
        const totalActive = statusValues.reduce((sum, value) => sum + Number(value || 0), 0);
        if (totalTarget) totalTarget.textContent = `Active requests: ${totalActive}`;
        if (statusCanvas && statusState) {
            if (chartApi.hasValues(statusValues)) {
                statusState.innerHTML = '';
                chartApi.createRequestStatusChart(statusCanvas, data.requestStatus);
            } else {
                statusState.innerHTML = stateMessage('No active request status data available.', 'donut_large');
            }
        }
    }

    function renderTodaySchedule(items) {
        const target = document.getElementById('today-schedule');
        if (!target) return;
        if (!items?.length) {
            target.innerHTML = stateMessage('No scheduled facility activities today.', 'event_available');
            return;
        }
        target.innerHTML = items.map(item => `
            <div class="fam-timeline-item">
                <time>${escapeHtml(item.time)}</time>
                <div>
                    <strong>${escapeHtml(item.activity)}</strong>
                    <span>${escapeHtml(item.location)} Â· ${escapeHtml(item.module)}</span>
                </div>
                ${statusBadge(item.status)}
            </div>
        `).join('');
    }

    function renderPendingActions(items) {
        const target = document.getElementById('pending-actions');
        if (!target) return;
        if (!items?.length) {
            target.innerHTML = `<tr><td colspan="7">${stateMessage('No pending actions require your review.', 'task_alt')}</td></tr>`;
            return;
        }
        target.innerHTML = items.map(item => `
            <tr>
                <td>${escapeHtml(item.reference)}</td>
                <td>${escapeHtml(item.item)}</td>
                <td>${escapeHtml(item.module)}</td>
                <td>${statusBadge(item.priority)}</td>
                <td>${escapeHtml(item.submitted)}</td>
                <td>${statusBadge(item.status)}</td>
                <td><a class="fam-table-action" href="${item.href}">${escapeHtml(item.action)}</a></td>
            </tr>
        `).join('');
    }

    function renderRecentActivity(items) {
        const target = document.getElementById('recent-activity');
        if (!target) return;
        if (!items?.length) {
            target.innerHTML = stateMessage('No recent activity.', 'history');
            return;
        }
        target.innerHTML = items.map(item => `
            <div class="fam-activity-item">
                <span class="fam-activity-icon material-symbols-outlined" aria-hidden="true">${iconMap[item.module] || 'notifications'}</span>
                <div>
                    <strong>${escapeHtml(item.activity)}</strong>
                    <span>${escapeHtml(item.module)} Â· ${escapeHtml(item.by)} Â· ${escapeHtml(item.time)}</span>
                </div>
                ${item.status ? statusBadge(item.status) : ''}
            </div>
        `).join('');
    }

    async function initializeDashboard() {
        const service = window.FAMDashboardService;
        if (!service) return;
        setLoading();
        try {
            const data = await service.getDashboardPayload();
            document.getElementById('dashboard-last-updated').textContent = formatUpdatedAt(data.generatedAt);
            renderAlerts(data.alerts);
            renderKpis(data.kpis);
            renderCharts(data);
            renderPendingActions(data.pendingActions);
            renderTodaySchedule(data.todaySchedule);
            renderRecentActivity(data.recentActivities);
        } catch (error) {
            console.error(error);
            window.FAMDashboardCharts?.destroyAllCharts();
            document.querySelectorAll('[data-state-container]').forEach(container => {
                container.innerHTML = stateMessage('Unable to load dashboard activity.', 'error');
            });
        }
    }

    document.addEventListener('fam:layout-ready', () => {
        initializeDashboard();
        document.getElementById('dashboard-refresh')?.addEventListener('click', initializeDashboard);
        window.addEventListener('fam:themechange', initializeDashboard);
        window.addEventListener('resize', () => Object.values(window.FAMDashboardCharts?.chartInstances || {}).forEach(chart => chart?.resize()));
        document.getElementById('desktop-sidebar-toggle')?.addEventListener('click', () => {
            window.setTimeout(() => Object.values(window.FAMDashboardCharts?.chartInstances || {}).forEach(chart => chart?.resize()), 320);
        });
    });
})();


