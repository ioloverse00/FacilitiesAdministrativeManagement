# Mock Data Audit

Operational mock/static data has been removed from active frontend module scripts.

| File | Variable/function | Module | Status | Notes |
| --- | --- | --- | --- | --- |
| assets/js/dashboard.mock-data.js | FAMDashboardMockData | Dashboard | Removed | Deleted; dashboard now calls /api/dashboard/index.php. |
| assets/js/dashboard-service.js | getDashboardPayload | Dashboard | Replaced | Uses FAMApi and live dashboard API. |
| assets/js/maintenance.js | workOrders array and fake setTimeout loading | Maintenance | Replaced | Module now uses /api/maintenance/index.php and /show.php. |
| assets/js/assets.js | assets array | Asset Management | Replaced | Module now uses /api/assets/index.php and /show.php. |
| assets/js/room-reservations.js | reservations array and calendar sample events | Room Reservations | Replaced | Module now uses /api/reservations/index.php and /show.php. |
| assets/js/procurement.js | requests array and fake action toasts | Procurement | Replaced | Module now uses /api/procurement/index.php and /show.php. |
| assets/js/records.js | records array | Administrative Records | Replaced | Module now uses /api/records/index.php and /show.php. |
| assets/js/reports.js | report templates, recentReports, insights | Reports | Replaced | Module now uses /api/reports/overview.php. Report generation/export are deferred. |
| assets/js/profile-dropdown.js | notificationSeed | Header Notifications | Removed | Seed rows removed; dropdown shows empty state until a notification API is implemented. |
| assets/js/settings.js | reference/admin sample rows | FAM Administration | Retained for now | Outside this task's requested major operational pages; some rows are reference/admin configuration placeholders, not transactional module rows. |

Allowed retained static values include labels, icons, status display helpers, table column definitions, route mappings, and deferred-workflow messages.