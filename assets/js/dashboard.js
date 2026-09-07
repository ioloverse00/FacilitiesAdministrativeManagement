(function () {
    const statusClassMap = {
        critical: 'fam-status-critical',
        warning: 'fam-status-warning',
        informational: 'fam-status-info',
        'due soon': 'fam-status-warning',
        'in progress': 'fam-status-progress',
        scheduled: 'fam-status-scheduled',
        active: 'fam-status-progress',
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
        'Facilities Reservation': 'meeting_room',
        RESERVATIONS: 'meeting_room',
        VISITORS: 'badge',
        documents: 'folder',
        retention: 'fact_check',
        'Room Reservations': 'meeting_room',
        'Visitor Management': 'badge',
        'Document Management': 'folder',
        'Records Retention': 'fact_check',
        'Records Retention & Compliance': 'fact_check',
        'Contract Management': 'contract',
        'Legal Management': 'gavel'
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    }

    function statusBadge(status) {
        const normalized = String(status || '').toLowerCase();
        return `<span class="fam-status-badge ${statusClassMap[normalized] || 'fam-status-inactive'}">${escapeHtml(status)}</span>`;
    }

    function stateMessage(message, icon = 'info') {
        const spinner = icon === 'progress_activity' ? ' fam-spinner' : '';
        return `<div class="fam-state" role="status"><span class="material-symbols-outlined${spinner}" aria-hidden="true">${icon}</span><span>${escapeHtml(message)}</span></div>`;
    }

    function setLoading() {
        const kpis = document.getElementById('dashboard-kpis');
        if (kpis) {
            kpis.setAttribute('aria-busy', 'true');
            kpis.innerHTML = Array.from({ length: 6 }, () => `
                <article class="fam-card fam-kpi-card fam-dashboard-skeleton-card" aria-hidden="true">
                    <div class="fam-kpi-top">
                        <div>
                            <span class="fam-skeleton fam-skeleton-line fam-skeleton-line-md"></span>
                            <span class="fam-skeleton fam-skeleton-line fam-skeleton-line-sm"></span>
                        </div>
                        <span class="fam-skeleton fam-dashboard-skeleton-icon"></span>
                    </div>
                    <span class="fam-skeleton fam-skeleton-line fam-skeleton-line-lg"></span>
                    <div class="fam-kpi-footer">
                        <span class="fam-skeleton fam-skeleton-line fam-skeleton-line-sm"></span>
                        <span class="fam-skeleton fam-skeleton-line fam-skeleton-line-md"></span>
                    </div>
                </article>
            `).join('');
        }
        ['reservation-activity-state', 'operational-overview-state'].forEach(id => {
            const target = document.getElementById(id);
            if (target) target.innerHTML = `<div class="fam-dashboard-skeleton-chart" aria-hidden="true"><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-lg"></span><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-md"></span><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-sm"></span></div>`;
        });
        setChartVisibility('reservation-activity-chart', false);
        setChartVisibility('operational-overview-chart', false);
        ['today-schedule', 'retention-attention', 'recent-activity'].forEach(id => {
            const target = document.getElementById(id);
            if (!target) return;
            target.setAttribute('aria-busy', 'true');
            target.innerHTML = `<div class="fam-dashboard-skeleton-list" aria-hidden="true">${Array.from({ length: id === 'recent-activity' ? 4 : 3 }, () => `<div class="fam-dashboard-skeleton-row"><div><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-lg"></span><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-md"></span></div><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-sm"></span></div>`).join('')}</div><span class="sr-only">Loading dashboard content...</span>`;
        });
    }

    function formatUpdatedAt(value) {
        const date = value ? new Date(value) : new Date();
        return `Last updated: ${date.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}, ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    }

    function renderKpis(kpis) {
        const target = document.getElementById('dashboard-kpis');
        if (!target) return;
        target.removeAttribute('aria-busy');
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

    function renderChartState(targetId, message, icon = 'bar_chart') {
        const target = document.getElementById(targetId);
        if (!target) return;
        target.innerHTML = message ? stateMessage(message, icon) : '';
    }

    function setChartVisibility(canvasId, isVisible) {
        const box = document.getElementById(canvasId)?.closest('.fam-chart-box');
        if (box) box.hidden = !isVisible;
    }

    function renderCharts(charts) {
        const chartTools = window.FAMDashboardCharts;
        if (!chartTools) return;
        chartTools.destroyAllCharts();

        const reservationActivity = charts?.reservationActivity || {};
        const reservationCanvas = document.getElementById('reservation-activity-chart');
        if (!chartTools.hasValues(reservationActivity.values)) {
            setChartVisibility('reservation-activity-chart', false);
            renderChartState('reservation-activity-state', 'No reservation activity in the last 7 days.', 'event_busy');
        } else {
            setChartVisibility('reservation-activity-chart', true);
            renderChartState('reservation-activity-state', '');
            chartTools.createReservationActivityChart(reservationCanvas, reservationActivity);
        }

        const operationalOverview = charts?.operationalOverview || {};
        const overviewCanvas = document.getElementById('operational-overview-chart');
        if (!chartTools.hasValues(operationalOverview.values)) {
            setChartVisibility('operational-overview-chart', false);
            renderChartState('operational-overview-state', 'No active operational items require attention.', 'check_circle');
        } else {
            setChartVisibility('operational-overview-chart', true);
            renderChartState('operational-overview-state', '');
            chartTools.createOperationalOverviewChart(overviewCanvas, operationalOverview);
        }
    }

    function renderTodaySchedule(items) {
        const target = document.getElementById('today-schedule');
        if (!target) return;
        target.removeAttribute('aria-busy');
        if (!items?.length) {
            target.innerHTML = stateMessage('No scheduled facility activities today.', 'event_available');
            return;
        }
        target.innerHTML = items.map(item => `
            <div class="fam-timeline-item">
                <time>${escapeHtml(item.time)}</time>
                <div>
                    <strong>${escapeHtml(item.activity)}</strong>
                    <span>${escapeHtml(item.location)} � ${escapeHtml(item.module)}</span>
                </div>
                ${statusBadge(item.status)}
            </div>
        `).join('');
    }

    function renderRetentionAttention(items) {
        const target = document.getElementById('retention-attention');
        if (!target) return;
        target.removeAttribute('aria-busy');
        if (!items?.length) {
            target.innerHTML = stateMessage('No retention records are due for review.', 'fact_check');
            return;
        }
        target.innerHTML = items.map(item => `
            <div class="fam-activity-item">
                <span class="fam-activity-icon material-symbols-outlined" aria-hidden="true">fact_check</span>
                <div>
                    <strong>${escapeHtml(item.title || item.reference)}</strong>
                    <span>${escapeHtml(item.reference)} � ${escapeHtml(item.schedule)} � ${escapeHtml(item.reviewDate || 'No review date')}</span>
                </div>
                ${statusBadge(item.status)}
            </div>
        `).join('');
    }

    function renderRecentActivity(items) {
        const target = document.getElementById('recent-activity');
        if (!target) return;
        target.removeAttribute('aria-busy');
        if (!items?.length) {
            target.innerHTML = stateMessage('No recent activity.', 'history');
            return;
        }
        target.innerHTML = items.map(item => `
            <div class="fam-activity-item">
                <span class="fam-activity-icon material-symbols-outlined" aria-hidden="true">${iconMap[item.module] || 'notifications'}</span>
                <div>
                    <strong>${escapeHtml(item.activity)}</strong>
                    <span>${escapeHtml(item.module)} � ${escapeHtml(item.by)} � ${escapeHtml(item.time)}</span>
                </div>
            </div>
        `).join('');
    }

    async function initializeDashboard() {
        const service = window.FAMDashboardService;
        if (!service) return;
        const refresh = document.getElementById('dashboard-refresh');
        const initial = !document.getElementById('dashboard-kpis')?.children.length;
        refresh?.setAttribute('aria-busy', 'true');
        refresh?.setAttribute('disabled', 'disabled');
        if (initial) setLoading();
        try {
            const data = await service.getDashboardPayload();
            document.getElementById('dashboard-last-updated').textContent = formatUpdatedAt(data.generatedAt);
            renderKpis(data.kpis);
            renderCharts(data.charts);
            renderTodaySchedule(data.todaySchedule);
            renderRetentionAttention(data.retentionAttention);
            renderRecentActivity(data.recentActivities);
        } catch (error) {
            console.error(error);
            window.FAMDashboardCharts?.destroyAllCharts();
            setChartVisibility('reservation-activity-chart', false);
            setChartVisibility('operational-overview-chart', false);
            renderChartState('reservation-activity-state', 'Unable to load dashboard activity.', 'error');
            renderChartState('operational-overview-state', 'Unable to load dashboard activity.', 'error');
            document.querySelectorAll('[data-state-container]').forEach(container => {
                container.removeAttribute('aria-busy');
                container.innerHTML = stateMessage('Unable to load dashboard activity.', 'error');
            });
        } finally {
            refresh?.removeAttribute('aria-busy');
            refresh?.removeAttribute('disabled');
        }
    }

    document.addEventListener('fam:layout-ready', () => {
        initializeDashboard();
        document.getElementById('dashboard-refresh')?.addEventListener('click', initializeDashboard);
        window.addEventListener('fam:themechange', initializeDashboard);
    });
})();


