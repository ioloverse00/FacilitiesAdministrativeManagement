(function () {
    const moduleRoutes = { reservations: '../pages/room-reservations.html', documents: '../pages/records.html', retention: '../pages/records-retention.html' };
    function plural(value, singular, pluralText = `${singular}s`) { return `${Number(value || 0)} ${Number(value || 0) === 1 ? singular : pluralText}`; }
    function status(value, warningLabel, okLabel = 'On Track') { return Number(value || 0) > 0 ? warningLabel : okLabel; }
    function kpiArray(k = {}) { return [
        { label: "Today's Reservations", value: k.reservationsToday || 0, supporting: `${plural(k.reservations, 'total reservation')} recorded`, trend: 'Scheduled today', status: status(k.reservationsToday, 'Scheduled'), icon: 'meeting_room', href: moduleRoutes.reservations },
        { label: 'Document Records', value: k.records || 0, supporting: 'Official document and record registry', trend: 'Document Management', status: 'Informational', icon: 'folder', href: moduleRoutes.documents },
        { label: 'Retention Reviews', value: k.recordsDispositionDue || 0, supporting: `${plural(k.recordsDispositionDue, 'record')} due for review`, trend: 'Records Retention', status: status(k.recordsDispositionDue, 'Review'), icon: 'fact_check', href: moduleRoutes.retention }
    ]; }
    function trend(rows = []) { return { labels: rows.map(r => r.date), facilityRequests: rows.map(r => Number(r.value || 0)), maintenanceRequests: [], completedRequests: [] }; }
    function pending(items = []) { return items.slice(0, 6).map(item => ({ reference: item.reference || '', item: item.item || '', module: item.module || '', priority: item.priority || 'Informational', submitted: item.submitted || '', dueDate: item.dueDate || '', status: item.status || '', action: 'View', href: '../pages/reports.html' })); }
    function schedule(items = []) { return items.slice(0, 5).map(item => ({ time: item.startsAt ? new Date(item.startsAt.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '', activity: item.title || item.reference || '', location: item.reference || '', module: 'Facilities Reservation', status: item.status || '' })); }
    function activity(items = []) { return items.slice(0, 5).map(item => ({ activity: item.activity || '', module: item.module || '', by: item.reference || 'System', time: item.time || '', status: item.status || '' })); }
    function normalizeDashboardPayload(data) { return { generatedAt: data.generatedAt || new Date().toISOString(), alerts: (data.alerts || []).slice(0, 4), kpis: kpiArray(data.kpis || {}), requestTrend: trend(data.requestTrend || []), requestStatus: data.requestStatus || [], pendingActions: pending(data.pendingActions || []), todaySchedule: schedule(data.todaySchedule || []), recentActivities: activity(data.recentActivities || []), requestActions: [] }; }
    async function getDashboardPayload() { const payload = await window.FAMApi.request('../api/dashboard/index.php'); return normalizeDashboardPayload(payload.data?.dashboard || {}); }
    window.FAMDashboardService = { getDashboardPayload, normalizeDashboardPayload };
})();
