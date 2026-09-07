(function () {
    const route = (key, fallback) => window.FAMNavigation?.cleanHref?.(key) || fallback;
    const moduleRoutes = {
        reservations: route('room-reservations', '../pages/room-reservations.html'),
        visitors: route('visitor-management', '../pages/visitor-management.html'),
        documents: route('records', '../pages/records.html'),
        retention: route('records-retention', '../pages/records-retention.html'),
        contracts: route('contract-management', '../pages/contract-management.html'),
        legal: route('legal-management', '../pages/legal-management.html')
    };
    const modulePermissions = { reservations: 'reservations.view', visitors: 'visitors.view', documents: 'records.view', retention: 'records.view', contracts: 'contract.view', legal: 'legal.view' };
    const moduleLabels = { RESERVATIONS: 'Room Reservations', VISITORS: 'Visitor Management', documents: 'Document Management', retention: 'Records Retention', contract_management: 'Contract Management', LEGAL_MANAGEMENT: 'Legal Management' };
    function plural(value, singular, pluralText = `${singular}s`) { return `${Number(value || 0)} ${Number(value || 0) === 1 ? singular : pluralText}`; }
    function status(value, warningLabel, okLabel = 'On Track') { return Number(value || 0) > 0 ? warningLabel : okLabel; }
    function hasPermission(permission) {
        const permissions = window.FAMApi?.currentUser?.permissions || [];
        return !permission || permissions.includes(permission);
    }
    function card(moduleKey, item) { return { ...item, href: moduleRoutes[moduleKey], permission: modulePermissions[moduleKey] }; }
    function kpiArray(k = {}) { return [
        card('reservations', { label: "Today's Reservations", value: k.reservationsToday || 0, supporting: plural(k.reservationsPendingApproval, 'pending approval', 'pending approvals'), trend: 'Facilities Reservation', status: status(k.reservationsToday, 'Scheduled'), icon: 'meeting_room' }),
        card('visitors', { label: 'Visitors Currently Inside', value: k.visitorsCheckedIn || 0, supporting: `${plural(k.visitorsToday, 'visitor')} scheduled today`, trend: 'Visitor Management', status: status(k.visitorsCheckedIn, 'Active'), icon: 'badge' }),
        card('documents', { label: 'Active Documents', value: k.activeDocuments || 0, supporting: `${plural(k.recentDocuments, 'document')} updated in last 30 days`, trend: 'Document Management', status: 'Informational', icon: 'folder' }),
        card('retention', { label: 'Retention Attention', value: k.recordsDispositionDue || 0, supporting: `${plural(k.recordsDispositionDue, 'record')} due or overdue for review`, trend: 'Records Retention & Compliance', status: status(k.recordsDispositionDue, 'Review'), icon: 'fact_check' }),
        card('contracts', { label: 'Contract Review Workload', value: k.contractsPendingReviewApproval || 0, supporting: `${plural(k.contractsExpiringSoon, 'active contract')} expiring soon`, trend: plural(k.contractsActive, 'active contract'), status: status(k.contractsPendingReviewApproval, 'For Approval'), icon: 'contract' }),
        card('legal', { label: 'Open Legal Matters', value: k.legalOpen || 0, supporting: plural(k.legalCritical, 'critical matter'), trend: 'Legal Management', status: status(k.legalOpen, 'Needs Attention'), icon: 'gavel' })
    ].filter(item => hasPermission(item.permission)); }
    function formatTime(value) { return value ? new Date(value.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''; }
    function formatDateTime(value) { return value ? new Date(value.replace(' ', 'T')).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''; }
    function schedule(items = []) { return items.slice(0, 5).map(item => ({ time: formatTime(item.startsAt), activity: item.room || item.title || item.reference || '', location: [item.reference, item.title].filter(Boolean).join(' · '), module: 'Facilities Reservation', status: item.status || '' })); }
    function retentionAttention(items = []) { return items.slice(0, 5).map(item => ({ reference: item.recordNo || '', title: item.title || '', schedule: item.schedule?.name || '', reviewDate: item.scheduledDispositionDate || '', status: item.dueState || item.retentionStatus || '' })); }
    function activity(items = []) { return items.slice(0, 5).map(item => ({ activity: item.activity || '', module: moduleLabels[item.module] || item.module || '', by: item.reference || 'System', time: formatDateTime(item.time), status: item.status || '' })); }
    function chartSeries(series = {}) { return { labels: Array.isArray(series.labels) ? series.labels : [], values: Array.isArray(series.values) ? series.values.map(value => Number(value || 0)) : [] }; }
    function charts(data = {}) { return { reservationActivity: chartSeries(data.reservation_activity), operationalOverview: chartSeries(data.operational_overview) }; }
    function normalizeDashboardPayload(data) { return { generatedAt: data.generatedAt || new Date().toISOString(), kpis: kpiArray(data.kpis || {}), charts: charts(data.charts || {}), todaySchedule: schedule(data.todaySchedule || []), retentionAttention: retentionAttention(data.retentionAttention || []), recentActivities: activity(data.recentActivities || []) }; }
    async function getDashboardPayload() { const payload = await window.FAMApi.request('../api/dashboard/index.php'); return normalizeDashboardPayload(payload.data?.dashboard || {}); }
    window.FAMDashboardService = { getDashboardPayload, normalizeDashboardPayload };
})();
