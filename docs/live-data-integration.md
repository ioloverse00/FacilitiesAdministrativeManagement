# Live Data Integration

All major FAM operational pages now read live data through authenticated PHP APIs. Empty operational tables return empty arrays, zero summaries, and empty states instead of sample rows.

| Module | Page | Frontend JS | API endpoints | Tables | Permission | Supported now | Deferred |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | pages/dashboard.html | assets/js/dashboard-service.js, assets/js/dashboard.js | GET /api/dashboard/index.php | facility_request, maintenance_work_order, asset, facility_reservation, procurement_request, record, workflow_task, notification, activity_event, sla_tracking | dashboard.view | Live KPIs, alerts, trends, pending tasks, schedule, recent activity | Dashboard write actions |
| Maintenance | pages/maintenance.html | assets/js/maintenance.js, assets/js/live-module.js | GET /api/maintenance/index.php, show.php, options.php | maintenance_work_order, maintenance_history, maintenance_material, preventive_maintenance_plan, facility_request, facility_space, building, asset, employee_reference, inventory_item_reference | maintenance.view | Live list, summary, search, pagination, details | Create/update/assignment workflows |
| Asset Management | pages/assets.html | assets/js/assets.js, assets/js/live-module.js | GET /api/assets/index.php, show.php, options.php | asset, asset_category, asset_history, facility_space, building, employee_reference, supplier_reference, maintenance_work_order | assets.view | Live list, summary, search, pagination, details | Asset registration/transfer/update workflows |
| Room Reservations | pages/room-reservations.html | assets/js/room-reservations.js | GET /api/reservations/calendar.php, index.php, show.php, options.php | facility_reservation, reservation_participant, reservation_history, facility_space, building, employee_reference, department_reference | reservations.view | Calendar-first scheduling workspace, synchronized live list, room/status/search filters, read-only details drawer | Reservation create/approval/check-in workflows |
| Procurement | pages/procurement.html | assets/js/procurement.js, assets/js/live-module.js | GET /api/procurement/index.php, show.php, options.php | procurement_request, procurement_request_item, procurement_history, purchase_order_reference, facility_request, maintenance_work_order, employee_reference, department_reference, budget_reference, supplier_reference, inventory_item_reference | procurement.view | Live list, summary, pagination, details | Procurement create/approval/external sync workflows |
| Administrative Records | pages/records.html | assets/js/records.js, assets/js/live-module.js | GET /api/records/index.php, show.php, options.php | record, record_document, retention_schedule, document, document_version, document_category, department_reference, employee_reference | records.view | Live list, summary, pagination, details without storage paths | Upload/versioning/disposition workflows |
| Reports & Analytics | pages/reports.html | assets/js/reports.js | GET /api/reports/overview.php | Aggregates over facility_request, maintenance_work_order, asset, facility_reservation, procurement_request, record, sla_tracking | reports.view | Live aggregate overview and no-data states | Report generation/export files |

## Notes

- All endpoints reuse the existing session/auth stack and `jsonResponse()` shape.
- All list/detail endpoints require authentication and module-specific permissions.
- SQL uses prepared statements and whitelisted sort columns.
- Soft-deleted records are excluded where the table has `deleted_at`.
- Document version `storage_path` is not returned by Records details.
- Facility Requests remains the only fully writable business module.

## Room Reservations Calendar

- `GET /api/reservations/calendar.php` powers the primary calendar. The frontend requests only the visible range with `date_from` and `date_to`.
- `GET /api/reservations/index.php` powers the secondary reservation list using the same visible date range, search term, room filter, and status filter where practical.
- `GET /api/reservations/options.php` supplies room and status filter choices.
- `GET /api/reservations/show.php?id=<id>` loads the details drawer when a calendar event, operational row, or list action is selected.
- Supported calendar views are Month, Week, and Day. Mobile defaults to Day for a compact schedule.
- Current admin behavior is read-only for reservations. Approval, rejection, check-in/check-out, cancellation, and requester-facing creation remain future workflows until real write endpoints are implemented.
