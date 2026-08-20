(function () {
    const moduleRoutes = { reservations: '../pages/room-reservations.html', visitors: '../pages/visitor-management.html', documents: '../pages/records.html', retention: '../pages/records-retention.html' };
    function plural(value, singular, pluralText = `${singular}s`) { return `${Number(value || 0)} ${Number(value || 0) === 1 ? singular : pluralText}`; }
    function status(value, warningLabel, okLabel = 'On Track') { return Number(value || 0) > 0 ? warningLabel : okLabel; }
    function kpiArray(k = {}) { return [
        { label: "Today's Reservations", value: k.reservationsToday || 0, supporting: plural(k.reservationsPendingApproval, 'pending approval', 'pending approvals'), trend: 'Facilities Reservation', status: status(k.reservationsToday, 'Scheduled'), icon: 'meeting_room', href: moduleRoutes.reservations },
        { label: 'Visitors Checked In', value: k.visitorsCheckedIn || 0, supporting: `${plural(k.visitorsToday, 'visitor')} scheduled today`, trend: 'Visitor Management', status: status(k.visitorsCheckedIn, 'Active'), icon: 'badge', href: moduleRoutes.visitors },
        { label: 'Active Documents', value: k.activeDocuments || 0, supporting: plural(k.recentDocuments, 'recent update'), trend: 'Document Management', status: 'Informational', icon: 'folder', href: moduleRoutes.documents },
        { label: 'Retention Reviews Due', value: k.recordsDispositionDue || 0, supporting: `${plural(k.recordsDispositionDue, 'record')} due for review`, trend: 'Records Retention & Compliance', status: status(k.recordsDispositionDue, 'Review'), icon: 'fact_check', href: moduleRoutes.retention }
    ]; }
    function formatTime(value) { return value ? new Date(value.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''; }
    function formatDateTime(value) { return value ? new Date(value.replace(' ', 'T')).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''; }
    function schedule(items = []) { return items.slice(0, 5).map(item => ({ time: formatTime(item.startsAt), activity: item.room || item.title || item.reference || '', location: [item.reference, item.title].filter(Boolean).join(' · '), module: 'Facilities Reservation', status: item.status || '' })); }
    function retentionAttention(items = []) { return items.slice(0, 5).map(item => ({ reference: item.recordNo || '', title: item.title || '', schedule: item.schedule?.name || '', reviewDate: item.scheduledDispositionDate || '', status: item.dueState || item.retentionStatus || '' })); }
    function activity(items = []) { return items.slice(0, 5).map(item => ({ activity: item.activity || '', module: item.module || '', by: item.reference || 'System', time: formatDateTime(item.time), status: item.status || '' })); }
    function chartSeries(series = {}) { return { labels: Array.isArray(series.labels) ? series.labels : [], values: Array.isArray(series.values) ? series.values.map(value => Number(value || 0)) : [] }; }
    function charts(data = {}) { return { reservationActivity: chartSeries(data.reservation_activity), operationalOverview: chartSeries(data.operational_overview) }; }
    function normalizeDashboardPayload(data) { return { generatedAt: data.generatedAt || new Date().toISOString(), alerts: (data.alerts || []).slice(0, 4), kpis: kpiArray(data.kpis || {}), charts: charts(data.charts || {}), todaySchedule: schedule(data.todaySchedule || []), retentionAttention: retentionAttention(data.retentionAttention || []), recentActivities: activity(data.recentActivities || []), requestActions: [] }; }
    async function getDashboardPayload() { const payload = await window.FAMApi.request('../api/dashboard/index.php'); return normalizeDashboardPayload(payload.data?.dashboard || {}); }
    window.FAMDashboardService = { getDashboardPayload, normalizeDashboardPayload };
})();
