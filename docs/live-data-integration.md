# Live Data Integration

All major FAM operational pages now read live data through authenticated PHP APIs. Empty operational tables return empty arrays, zero summaries, and empty states instead of sample rows.

| Module | Page | Frontend JS | API endpoints | Tables | Permission | Supported now | Deferred |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | pages/dashboard.html | assets/js/dashboard-service.js, assets/js/dashboard.js | GET /api/dashboard/index.php | facility_request, maintenance_work_order, asset, facility_reservation, procurement_request, record, workflow_task, notification, activity_event, sla_tracking | dashboard.view | Live KPIs, alerts, trends, pending tasks, schedule, recent activity | Dashboard write actions |
| Maintenance | pages/maintenance.html | assets/js/maintenance.js, assets/js/live-module.js | GET /api/maintenance/index.php, show.php, options.php | maintenance_work_order, maintenance_history, maintenance_material, preventive_maintenance_plan, facility_request, facility_space, building, asset, employee_reference, inventory_item_reference | maintenance.view | Live list, summary, search, pagination, details | Create/update/assignment workflows |
| Asset Management | pages/assets.html | assets/js/assets.js, assets/js/live-module.js | GET /api/assets/index.php, show.php, options.php | asset, asset_category, asset_history, facility_space, building, employee_reference, supplier_reference, maintenance_work_order | assets.view | Live list, summary, search, pagination, details | Asset registration/transfer/update workflows |
| Facilities Reservation | pages/room-reservations.html | assets/js/room-reservations.js | GET /api/reservations/calendar.php, index.php, show.php, options.php; POST /api/reservations/approve.php, reject.php, cancel.php, no-show.php | facility_reservation, reservation_participant, reservation_history, facility_space, building, employee_reference, department_reference | reservations.view plus approve/edit/manage as needed | Calendar-first monitoring workspace, room/status/search filters, admin approval/rejection/cancellation/no-show exception handling | Route and internals may still use room-reservations naming for compatibility |
| Visitor Management | pages/visitor-management.html | assets/js/visitor-management.js | GET/POST /api/visitors/*.php | visitor, visit, visitor_badge, visitor_visit_history, visitor_sequence, employee_reference, department_reference, facility_space, activity_event, audit_log | visitors.view | Live list, summary, search, filters, sorting, details drawer, walk-in registration, review, check-in, check-out, badge issue/return | Public visitor portal, OTP, QR, visitor self-service |
| Procurement | pages/procurement.html | assets/js/procurement.js, assets/js/live-module.js | GET /api/procurement/index.php, show.php, options.php | procurement_request, procurement_request_item, procurement_history, purchase_order_reference, facility_request, maintenance_work_order, employee_reference, department_reference, budget_reference, supplier_reference, inventory_item_reference | procurement.view | Live list, summary, pagination, details | Procurement create/approval/external sync workflows |
| Document Management | pages/records.html | assets/js/records.js | GET /api/documents/index.php, show.php, options.php, download.php, view.php; POST /api/documents/create.php, upload-version.php, archive.php | document, document_version, document_category, record, record_document, retention_schedule, employee_reference | records.view/create/edit | Live list, summary, upload, secure download/view, version history, new version upload, logical archive | Documents remain the source repository for file storage and versioning |
| Records Retention & Compliance | pages/records-retention.html | assets/js/records-retention.js | GET /api/retention/index.php, show.php, options.php, schedules.php; POST /api/retention/assign.php, extend.php, archive.php, dispose.php, place-hold.php, release-hold.php, save-schedule.php | record, record_document, retention_schedule, document, activity_event | retention.view/manage_schedules/assign/review/extend/archive/dispose/legal_hold | Live compliance queue, schedule management, server-side disposition calculation, legal holds, archive, logical disposition, activity history | No physical file deletion; Legal and Contract modules remain out of scope |
| Reports & Analytics | pages/reports.html | assets/js/reports.js | GET /api/reports/overview.php | Aggregates over facility_request, maintenance_work_order, asset, facility_reservation, procurement_request, record, sla_tracking | reports.view | Live aggregate overview and no-data states | Report generation/export files |
| Employee Portal | pages/employee/*.html | assets/js/employee/*.js | GET /api/employee/context.php, dashboard.php; /api/employee/facility-requests/*; /api/employee/reservations/*; /api/employee/notifications/* | user_account, employee_reference, facility_request, facility_reservation, notification | Employee session guard plus requester permissions | Authenticated shell, employee context, facility request submission/tracking, room reservation submission/tracking, availability checks, self check-in/check-out, employee notifications, read-only profile | Participant management, full RBAC routing |

## Notes

- All endpoints reuse the existing session/auth stack and `jsonResponse()` shape.
- All list/detail endpoints require authentication and module-specific permissions.
- SQL uses prepared statements and whitelisted sort columns.
- Soft-deleted records are excluded where the table has `deleted_at`.
- Document files are served through authorized API endpoints; raw `storage_path` values are not returned to the browser.
- Facility Requests and Room Reservations now have server-side write foundations.
- Employee Portal Phase 1 reuses authentication but intentionally avoids broad permission grants and write workflows.

## Room Reservations Calendar

- `GET /api/reservations/calendar.php` powers the primary calendar. The frontend requests only the visible range with `date_from` and `date_to`.
- The Admin UI emphasizes the calendar and requests queue. The legacy list endpoint remains available for compatibility.
- `GET /api/reservations/options.php` supplies room and status filter choices.
- `GET /api/reservations/show.php?id=<id>` loads the details drawer when a calendar event, operational row, or list action is selected.
- Supported calendar views are Month, Week, and Day. Mobile defaults to Day for a compact schedule.
- Reservation writes are centralized in `ReservationService`, including approval, rejection, cancellation, employee self check-in/check-out, no-show exceptions, history, notifications, and conflict detection.
- Normal check-in/check-out belongs to Employee Portal. The Admin UI is for review, monitoring, cancellation, and no-show exception handling.

## Visitor Management

- Visitor Management is the first writable admin module after Facility Requests in this live-data pass.
- The admin page uses authenticated API endpoints for list, detail, options, walk-in registration, review, check-in, check-out, update, history, and badge inventory.
- Search and filters are server-backed and combine in the same request state.
- Visitor check-in and check-out write both visit history and operational telemetry through ctivity_event and udit_log.
- Public visitor registration, OTP verification, QR codes, and anonymous workflows are intentionally deferred outside the Admin Portal.

