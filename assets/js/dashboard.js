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

    const dashboardState = {
        modules: {}
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

    function hasModule(module) {
        return dashboardState.modules?.[module] === true;
    }

    function hasAnyModule(modules) {
        return modules.some(hasModule);
    }

    function roleCodes() {
        const roles = window.FAMApi?.currentUser?.roles || [];
        return roles.map(role => String(role?.code || '').toUpperCase()).filter(Boolean);
    }

    function isStaffAnalyticsDashboard() {
        const codes = roleCodes();
        const persona = String(window.FAMApi?.currentUser?.persona?.code || '').toUpperCase();
        return (codes.includes('FAM_STAFF') || persona === 'FAM_STAFF')
            && !codes.includes('FAM_SUPER_ADMIN')
            && !codes.includes('FAM_ADMIN')
            && persona !== 'FAM_SUPER_ADMIN'
            && persona !== 'FAM_ADMIN';
    }

    function setElementHidden(element, hidden) {
        if (!element) return;
        element.hidden = hidden;
        element.classList.toggle('hidden', hidden);
        element.setAttribute('aria-hidden', String(hidden));
    }

    function dashboardCard(name) {
        return document.querySelector(`[data-dashboard-card="${name}"]`);
    }

    function dashboardSection(name) {
        return document.querySelector(`[data-dashboard-section="${name}"]`);
    }

    function applyDashboardComposition(modules = dashboardState.modules, charts = {}) {
        dashboardState.modules = modules || {};
        const canReservations = hasModule('reservations');
        const canRetention = hasModule('retention');
        const canContracts = hasModule('contracts');
        const canLegal = hasModule('legal');
        const canOperationalOverview = hasAnyModule(['reservations', 'visitors', 'retention']);
        const overviewHasValues = window.FAMDashboardCharts?.hasValues?.(charts?.operationalOverview?.values) === true;
        const showOperationalOverview = canOperationalOverview && (overviewHasValues || !isStaffAnalyticsDashboard());
        const showAnalytics = isStaffAnalyticsDashboard() && (canContracts || canLegal);

        setElementHidden(dashboardCard('reservation-activity'), !canReservations);
        setElementHidden(dashboardCard('operational-overview'), !showOperationalOverview);
        setElementHidden(dashboardSection('insights'), !canReservations && !showOperationalOverview);
        setElementHidden(dashboardCard('contract-workload'), !showAnalytics || !canContracts);
        setElementHidden(dashboardCard('legal-category'), !showAnalytics || !canLegal);
        setElementHidden(dashboardSection('contract-legal-analytics'), !showAnalytics);
        setElementHidden(dashboardCard('today-schedule'), !canReservations);
        setElementHidden(dashboardCard('retention-attention'), !canRetention);
        setElementHidden(dashboardSection('operations'), !canReservations && !canRetention);
    }

    function setLoading() {
        const updated = document.getElementById('dashboard-last-updated');
        if (updated) {
            updated.hidden = true;
            updated.textContent = '';
        }
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
        if (!hasModule('reservations')) {
            setElementHidden(dashboardCard('reservation-activity'), true);
            setElementHidden(dashboardCard('today-schedule'), true);
        }
        if (!hasModule('retention')) {
            setElementHidden(dashboardCard('retention-attention'), true);
        }
        if (!hasModule('contracts')) {
            setElementHidden(dashboardCard('contract-workload'), true);
        }
        if (!hasModule('legal')) {
            setElementHidden(dashboardCard('legal-category'), true);
        }
        ['reservation-activity-state', 'operational-overview-state', 'contract-workload-state', 'legal-category-state'].forEach(id => {
            const target = document.getElementById(id);
            if (id === 'reservation-activity-state' && !hasModule('reservations')) return;
            if (id === 'contract-workload-state' && !hasModule('contracts')) return;
            if (id === 'legal-category-state' && !hasModule('legal')) return;
            if (target) target.innerHTML = `<div class="fam-dashboard-skeleton-chart" aria-hidden="true"><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-lg"></span><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-md"></span><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-sm"></span></div>`;
        });
        setChartVisibility('reservation-activity-chart', false);
        setChartVisibility('operational-overview-chart', false);
        setChartVisibility('contract-workload-chart', false);
        setChartVisibility('legal-category-chart', false);
        ['today-schedule', 'retention-attention', 'recent-activity'].forEach(id => {
            const target = document.getElementById(id);
            if (!target) return;
            if (id === 'today-schedule' && !hasModule('reservations')) return;
            if (id === 'retention-attention' && !hasModule('retention')) return;
            target.setAttribute('aria-busy', 'true');
            target.innerHTML = `<div class="fam-dashboard-skeleton-list" aria-hidden="true">${Array.from({ length: id === 'recent-activity' ? 4 : 3 }, () => `<div class="fam-dashboard-skeleton-row"><div><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-lg"></span><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-md"></span></div><span class="fam-skeleton fam-skeleton-line fam-skeleton-line-sm"></span></div>`).join('')}</div><span class="sr-only">Loading dashboard content...</span>`;
        });
        applyDashboardComposition();
    }

    function formatUpdatedAt(value) {
        const date = value ? new Date(value) : new Date();
        return `Last updated: ${date.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}, ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    }

    function renderKpis(kpis) {
        const target = document.getElementById('dashboard-kpis');
        if (!target) return;
        target.removeAttribute('aria-busy');
        target.classList.toggle('fam-kpi-grid-compact', isStaffAnalyticsDashboard() && (kpis?.length || 0) <= 2);
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
        if (!hasModule('reservations')) {
            setChartVisibility('reservation-activity-chart', false);
            renderChartState('reservation-activity-state', '');
        } else if (!chartTools.hasValues(reservationActivity.values)) {
            setChartVisibility('reservation-activity-chart', false);
            renderChartState('reservation-activity-state', 'No reservation activity in the last 7 days.', 'event_busy');
        } else {
            setChartVisibility('reservation-activity-chart', true);
            renderChartState('reservation-activity-state', '');
            chartTools.createReservationActivityChart(reservationCanvas, reservationActivity);
        }

        const operationalOverview = charts?.operationalOverview || {};
        const overviewCanvas = document.getElementById('operational-overview-chart');
        const showEmptyOverview = hasAnyModule(['reservations', 'visitors', 'retention']);
        if (!chartTools.hasValues(operationalOverview.values) && !showEmptyOverview) {
            setChartVisibility('operational-overview-chart', false);
            renderChartState('operational-overview-state', '');
        } else if (!chartTools.hasValues(operationalOverview.values)) {
            setChartVisibility('operational-overview-chart', false);
            renderChartState('operational-overview-state', 'No active operational items require attention.', 'check_circle');
        } else {
            setChartVisibility('operational-overview-chart', true);
            renderChartState('operational-overview-state', '');
            chartTools.createOperationalOverviewChart(overviewCanvas, operationalOverview);
        }

        const contractWorkload = charts?.contractWorkload || {};
        const contractCanvas = document.getElementById('contract-workload-chart');
        if (!isStaffAnalyticsDashboard() || !hasModule('contracts')) {
            setChartVisibility('contract-workload-chart', false);
            renderChartState('contract-workload-state', '');
        } else if (!chartTools.hasValues(contractWorkload.values)) {
            setChartVisibility('contract-workload-chart', false);
            renderChartState('contract-workload-state', 'No contract workload data available.', 'contract');
        } else {
            setChartVisibility('contract-workload-chart', true);
            renderChartState('contract-workload-state', '');
            chartTools.createContractWorkloadChart(contractCanvas, contractWorkload);
        }

        const legalCategory = charts?.legalCategoryDistribution || {};
        const legalCanvas = document.getElementById('legal-category-chart');
        if (!isStaffAnalyticsDashboard() || !hasModule('legal')) {
            setChartVisibility('legal-category-chart', false);
            renderChartState('legal-category-state', '');
        } else if (!chartTools.hasValues(legalCategory.values)) {
            setChartVisibility('legal-category-chart', false);
            renderChartState('legal-category-state', 'No open legal matters to display.', 'gavel');
        } else {
            setChartVisibility('legal-category-chart', true);
            renderChartState('legal-category-state', '');
            chartTools.createLegalCategoryChart(legalCanvas, legalCategory);
        }
    }

    function renderTodaySchedule(items) {
        const target = document.getElementById('today-schedule');
        if (!target) return;
        if (!hasModule('reservations')) {
            target.removeAttribute('aria-busy');
            target.innerHTML = '';
            return;
        }
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
                    <span>${escapeHtml(item.location)} · ${escapeHtml(item.module)}</span>
                </div>
                ${statusBadge(item.status)}
            </div>
        `).join('');
    }

    function renderRetentionAttention(items) {
        const target = document.getElementById('retention-attention');
        if (!target) return;
        if (!hasModule('retention')) {
            target.removeAttribute('aria-busy');
            target.innerHTML = '';
            return;
        }
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
                    <span>${escapeHtml(item.reference)} · ${escapeHtml(item.schedule)} · ${escapeHtml(item.reviewDate || 'No review date')}</span>
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
        target.innerHTML = items.map(item => {
            const metadata = [item.module, item.reference, item.by, item.time].filter(Boolean).join(' · ');
            return `
            <div class="fam-activity-item">
                <span class="fam-activity-icon material-symbols-outlined" aria-hidden="true">${iconMap[item.module] || 'notifications'}</span>
                <div>
                    <strong>${escapeHtml(item.activity)}</strong>
                    ${metadata ? `<span>${escapeHtml(metadata)}</span>` : ''}
                </div>
            </div>
        `;
        }).join('');
    }

    async function initializeDashboard() {
        const service = window.FAMDashboardService;
        if (!service) return;
        const initial = !document.getElementById('dashboard-kpis')?.children.length;
        if (initial) setLoading();
        try {
            const data = await service.getDashboardPayload();
            applyDashboardComposition(data.modules, data.charts);
            const updated = document.getElementById('dashboard-last-updated');
            if (updated) {
                updated.textContent = formatUpdatedAt(data.generatedAt);
                updated.hidden = false;
            }
            renderKpis(data.kpis);
            renderCharts(data.charts);
            renderTodaySchedule(data.todaySchedule);
            renderRetentionAttention(data.retentionAttention);
            renderRecentActivity(data.recentActivities);
            applyDashboardComposition(data.modules, data.charts);
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
            const updated = document.getElementById('dashboard-last-updated');
            if (updated) {
                updated.textContent = 'Last updated unavailable';
                updated.hidden = false;
            }
        }
    }

    function bindDashboard() {
        if (document.body.dataset.dashboardInitialized === 'true') return;
        document.body.dataset.dashboardInitialized = 'true';
        initializeDashboard();
        window.addEventListener('fam:themechange', initializeDashboard);
    }

    document.addEventListener('fam:shell-ready', bindDashboard);
    document.addEventListener('fam:layout-ready', bindDashboard);
})();


