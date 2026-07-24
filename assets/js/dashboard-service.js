(function () {
    const clone = value => JSON.parse(JSON.stringify(value));

    function normalizeDashboardPayload(payload) {
        const data = clone(payload || {});
        const severityRank = { Critical: 0, Warning: 1, Informational: 2 };

        const visibleAlerts = (data.alerts || [])
            .sort((a, b) => (severityRank[a.severity] ?? 3) - (severityRank[b.severity] ?? 3))
            .slice(0, 4);

        const blockingAlerts = visibleAlerts.filter(alert => alert.severity === 'Critical' || alert.severity === 'Warning');

        return {
            generatedAt: data.generatedAt || new Date().toISOString(),
            alerts: (blockingAlerts.length >= 4 ? blockingAlerts : visibleAlerts).slice(0, 4),
            kpis: (data.kpis || []).slice(0, 6),
            requestTrend: data.requestTrend || { labels: [], facilityRequests: [], maintenanceRequests: [], completedRequests: [] },
            requestStatus: data.requestStatus || [],
            pendingActions: (data.pendingActions || []).slice().sort(sortPendingActions).slice(0, 6),
            todaySchedule: (data.todaySchedule || []).slice(0, 5),
            recentActivities: (data.recentActivities || []).slice(0, 5),
            requestActions: (data.requestActions || []).slice(0, 4)
        };
    }

    function sortPendingActions(a, b) {
        const priorityRank = { Critical: 0, Warning: 1, 'Due Soon': 2, Informational: 3 };
        const rankDelta = (priorityRank[a.priority] ?? 4) - (priorityRank[b.priority] ?? 4);
        if (rankDelta) return rankDelta;
        const dueDelta = new Date(a.dueDate || a.submitted) - new Date(b.dueDate || b.submitted);
        if (Number.isFinite(dueDelta) && dueDelta) return dueDelta;
        return new Date(a.submitted) - new Date(b.submitted);
    }

    async function getDashboardPayload() {
        // TODO: Replace mock data with GET /api/fam/dashboard-summary.
        return normalizeDashboardPayload(window.FAMDashboardMockData);
    }

    window.FAMDashboardService = {
        getDashboardPayload,
        normalizeDashboardPayload
    };
})();
