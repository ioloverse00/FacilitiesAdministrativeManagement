(function () {
    const moduleRoutes = { facility: '../pages/facility-requests.html', maintenance: '../pages/maintenance.html', assets: '../pages/assets.html', reservations: '../pages/room-reservations.html', procurement: '../pages/procurement.html', records: '../pages/records.html' };
    function kpiArray(k = {}) { return [
        { label: 'Facility Requests', value: k.facilityRequests || 0, supporting: 'Live facility request records', trend: 'Database-backed', status: 'Informational', icon: 'domain', href: moduleRoutes.facility },
        { label: 'Maintenance Work Orders', value: k.maintenance || 0, supporting: 'Live maintenance records', trend: 'Database-backed', status: 'Informational', icon: 'build', href: moduleRoutes.maintenance },
        { label: 'Assets', value: k.assets || 0, supporting: 'Live asset records', trend: 'Database-backed', status: 'Informational', icon: 'inventory_2', href: moduleRoutes.assets },
        { label: 'Room Reservations', value: k.reservations || 0, supporting: 'Live reservation records', trend: 'Database-backed', status: 'Informational', icon: 'meeting_room', href: moduleRoutes.reservations },
        { label: 'Procurement Requests', value: k.procurement || 0, supporting: 'Live procurement records', trend: 'Database-backed', status: 'Informational', icon: 'shopping_bag', href: moduleRoutes.procurement },
        { label: 'Administrative Records', value: k.records || 0, supporting: 'Live record entries', trend: 'Database-backed', status: 'Informational', icon: 'folder', href: moduleRoutes.records }
    ]; }
    function trend(rows = []) { return { labels: rows.map(r => r.date), facilityRequests: rows.map(r => Number(r.value || 0)), maintenanceRequests: [], completedRequests: [] }; }
    function pending(items = []) { return items.slice(0, 6).map(item => ({ reference: item.reference || '', item: item.item || '', module: item.module || '', priority: item.priority || 'Informational', submitted: item.submitted || '', dueDate: item.dueDate || '', status: item.status || '', action: 'View', href: '../pages/reports.html' })); }
    function schedule(items = []) { return items.slice(0, 5).map(item => ({ time: item.startsAt ? new Date(item.startsAt.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '', activity: item.title || item.reference || '', location: item.reference || '', module: 'Room Reservations', status: item.status || '' })); }
    function activity(items = []) { return items.slice(0, 5).map(item => ({ activity: item.activity || '', module: item.module || '', by: item.reference || 'System', time: item.time || '', status: item.status || '' })); }
    function normalizeDashboardPayload(data) { return { generatedAt: data.generatedAt || new Date().toISOString(), alerts: (data.alerts || []).slice(0, 4), kpis: kpiArray(data.kpis || {}), requestTrend: trend(data.requestTrend || []), requestStatus: data.requestStatus || [], pendingActions: pending(data.pendingActions || []), todaySchedule: schedule(data.todaySchedule || []), recentActivities: activity(data.recentActivities || []), requestActions: [] }; }
    async function getDashboardPayload() { const payload = await window.FAMApi.request('../api/dashboard/index.php'); return normalizeDashboardPayload(payload.data?.dashboard || {}); }
    window.FAMDashboardService = { getDashboardPayload, normalizeDashboardPayload };
})();
