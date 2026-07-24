(function () {
    const moduleRoutes = {
        facilityRequests: '../pages/facility-requests.html',
        maintenance: '../pages/maintenance.html',
        assets: '../pages/assets.html',
        reservations: '../pages/room-reservations.html',
        procurement: '../pages/procurement.html',
        records: '../pages/records.html',
        reports: '../pages/reports.html'
    };

    window.FAMDashboardMockData = {
        generatedAt: '2026-07-24T10:30:00+08:00',
        alerts: [
            { severity: 'Critical', message: 'High-priority maintenance requests need same-day review.', count: 3, href: `${moduleRoutes.maintenance}?priority=critical` },
            { severity: 'Warning', message: 'Facility requests are overdue for assignment.', count: 6, href: `${moduleRoutes.facilityRequests}?status=overdue` },
            { severity: 'Warning', message: 'Procurement requests are awaiting approval.', count: 2, href: `${moduleRoutes.procurement}?status=awaiting-approval` },
            { severity: 'Informational', message: 'Administrative records are nearing expiration.', count: 4, href: `${moduleRoutes.records}?status=expiring-soon` }
        ],
        kpis: [
            { label: 'Open Facility Requests', value: 24, supporting: '6 awaiting assignment', trend: '+12% from last week', status: 'Warning', icon: 'domain', href: moduleRoutes.facilityRequests },
            { label: 'Active Maintenance Requests', value: 17, supporting: '3 high priority', trend: 'Avg resolution 2.6 days', status: 'Critical', icon: 'build', href: moduleRoutes.maintenance },
            { label: 'Assets Requiring Attention', value: 26, supporting: '5 preventive checks due', trend: 'Inspection week', status: 'Warning', icon: 'inventory_2', href: moduleRoutes.assets },
            { label: 'Reservations Today', value: 31, supporting: '1 conflict under review', trend: '76% utilization today', status: 'Informational', icon: 'meeting_room', href: moduleRoutes.reservations },
            { label: 'Pending Procurement Approvals', value: 2, supporting: '3 deliveries delayed', trend: 'Approval queue stable', status: 'Warning', icon: 'shopping_bag', href: moduleRoutes.procurement },
            { label: 'Records Requiring Review', value: 11, supporting: '4 expiring soon', trend: 'Review due this week', status: 'Informational', icon: 'folder', href: moduleRoutes.records }
        ],
        requestTrend: {
            labels: ['Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
            facilityRequests: [18, 22, 20, 27, 24, 31],
            maintenanceRequests: [12, 14, 16, 13, 19, 17],
            completedRequests: [21, 24, 25, 28, 30, 27]
        },
        requestStatus: [
            { label: 'Pending', value: 14 },
            { label: 'Assigned', value: 11 },
            { label: 'In Progress', value: 9 },
            { label: 'Scheduled', value: 8 },
            { label: 'Overdue', value: 3 }
        ],
        pendingActions: [
            { reference: 'MR-2026-014', item: 'Electrical panel inspection', module: 'Maintenance', priority: 'Critical', submitted: 'Jul 24, 2026', dueDate: '2026-07-24', status: 'Awaiting Approval', action: 'Review', href: moduleRoutes.maintenance },
            { reference: 'FR-2026-108', item: 'North Wing access repair', module: 'Facility Requests', priority: 'Critical', submitted: 'Jul 24, 2026', dueDate: '2026-07-24', status: 'Pending', action: 'Assign', href: moduleRoutes.facilityRequests },
            { reference: 'RR-2026-221', item: 'Main hall booking overlap', module: 'Room Reservations', priority: 'Warning', submitted: 'Jul 24, 2026', dueDate: '2026-07-24', status: 'Conflict', action: 'Resolve Conflict', href: moduleRoutes.reservations },
            { reference: 'PR-2026-077', item: 'Replacement projector lamps', module: 'Procurement', priority: 'Warning', submitted: 'Jul 23, 2026', dueDate: '2026-07-25', status: 'For Approval', action: 'Approve', href: moduleRoutes.procurement },
            { reference: 'AST-0043', item: 'Training Room projector unavailable', module: 'Asset Management', priority: 'Warning', submitted: 'Jul 23, 2026', dueDate: '2026-07-25', status: 'Needs Attention', action: 'View', href: moduleRoutes.assets },
            { reference: 'AR-2026-044', item: 'Contract renewal packet', module: 'Administrative Records', priority: 'Due Soon', submitted: 'Jul 22, 2026', dueDate: '2026-07-26', status: 'Review', action: 'Review', href: moduleRoutes.records }
        ],
        todaySchedule: [
            { time: '8:30 AM', activity: 'Air-conditioning inspection', module: 'Maintenance', location: 'North Wing - Floor 3', status: 'Scheduled' },
            { time: '10:00 AM', activity: 'Conference Room A reservation', module: 'Room Reservations', location: 'Main Building', status: 'Confirmed' },
            { time: '11:30 AM', activity: 'Generator load test', module: 'Facility Requests', location: 'Utility Annex', status: 'In Progress' },
            { time: '2:00 PM', activity: 'Ergonomic chair delivery', module: 'Procurement', location: 'Receiving Bay', status: 'In Transit' },
            { time: '4:00 PM', activity: 'Retention file review', module: 'Administrative Records', location: 'Records Office', status: 'Pending' }
        ],
        recentActivities: [
            { activity: 'Maintenance request MR-2026-014 assigned to Electrical Team', module: 'Maintenance Requests', by: 'Admin User', time: '10 minutes ago', status: 'Assigned' },
            { activity: 'Room reservation RR-2026-021 approved', module: 'Room Reservations', by: 'HR Coordinator', time: '25 minutes ago', status: 'Completed' },
            { activity: 'Asset AST-0043 marked under maintenance', module: 'Asset Management', by: 'Asset Custodian', time: '1 hour ago', status: 'Unavailable' },
            { activity: 'Procurement request PR-2026-010 approved', module: 'Procurement', by: 'Procurement Officer', time: '2 hours ago', status: 'Approved' },
            { activity: 'Administrative record AR-2026-039 archived', module: 'Administrative Records', by: 'Records Custodian', time: 'Yesterday', status: 'Completed' }
        ],
        requestActions: [
            { icon: 'domain_add', label: 'Facility Request', href: moduleRoutes.facilityRequests },
            { icon: 'add_task', label: 'Maintenance Request', href: moduleRoutes.maintenance },
            { icon: 'event_available', label: 'Room Reservation', href: moduleRoutes.reservations },
            { icon: 'add_shopping_cart', label: 'Procurement Request', href: moduleRoutes.procurement }
        ]
    };
})();
