# Employee Portal

The Employee Portal is the requester-facing side of the FAM project. It provides the authenticated shell, employee-owned facility requests, employee-owned room reservations, notifications, read-only profile, and protected employee context APIs.

## Scope

- Pages live under `pages/employee/`.
- Shared employee components live under `components/employee/`.
- Shared and page-specific scripts live under `assets/js/employee/`.
- Protected employee endpoints live under `api/employee/`.
- The existing login/session/CSRF/auth stack is reused.
- No separate employee login, session cookie, user table, or password storage was added.
- No mock employee activity, facility request rows, room reservations, or notifications were added.

Any authenticated account with a valid `user_account.employee_reference_id` linkage can load the Employee Portal.

## Navigation

The employee navigation contains only:

- Dashboard
- My Facility Requests
- My Room Reservations
- Notifications through the header bell and Notifications page
- My Profile through the header account menu
- Logout through the shared account menu

Admin modules such as Maintenance, Assets, Procurement, Administrative Records, Visitor Management, Visitor Scanner, Reports, FAM Administration, audit logs, and user management are not exposed in the Employee Portal navigation.

## Authentication And Context

`GET /api/employee/context.php` derives the current employee from the authenticated session. It returns the current user ID, username, employee reference ID, employee number, full name, department, position, email, contact number, employment status, roles, and employee-portal-related permissions.

The endpoint does not expose password hashes, login counters, audit internals, unrelated users, or admin-only records.

If no employee record is linked, the portal shows a safe access-denied state.

## Dashboard

`GET /api/employee/dashboard.php` returns employee-owned counts only:

- open facility requests where `requested_by_employee_reference_id` is the current employee
- upcoming room reservations where `requested_by_employee_reference_id` is the current employee
- pending room reservations where `status = SUBMITTED` and `approval_status = PENDING`
- unread notifications where `recipient_user_id` is the current user

Recent activity includes employee-owned facility request and room reservation lifecycle updates.

## Room Reservations

`pages/employee/room-reservations.html` is the normal requester entry point for room reservation requests.

Employee reservation APIs live under `api/employee/reservations/`:

- `GET options.php`
- `GET list.php`
- `GET show.php?id=<id>`
- `POST availability.php`
- `POST create.php`
- `POST cancel.php?id=<id>`
- `POST check-in.php?id=<id>`
- `POST check-out.php?id=<id>`

These endpoints derive requester and department from the authenticated employee session. Browser-supplied requester, department, created-by, and owner identifiers are not trusted.

Room reservation business logic remains centralized in `ReservationService`, including numbering, conflict detection, lifecycle transitions, history, and notifications.

Employees submit reservations as `SUBMITTED / PENDING`, may cancel while `SUBMITTED` or `APPROVED`, may check in only during the server-enforced window from 30 minutes before start until scheduled end, and check out directly to `COMPLETED`.

## Permissions

Employee portal access uses:

- `employee_portal.view`
- `facility_requests.create`
- `facility_requests.view_own`
- `reservations.create`
- `reservations.view_own`
- `notifications.view_own`
- `profile.view_own`

## Data Ownership

Employee pages derive identity from the authenticated session. List and write APIs do not trust query parameters such as `?employee_id=` or `?id=<another employee>` for ownership.

Employee APIs filter by module-owned columns, such as:

- `facility_request.requested_by_employee_reference_id`
- `facility_reservation.requested_by_employee_reference_id`
- `notification.recipient_user_id`
- module-specific created-by/requester employee fields

## Notifications

The header bell and Notifications page use the live notification table. Opening a facility request notification routes to `pages/employee/facility-requests.html?request=<id>`. Opening a room reservation notification routes to `pages/employee/room-reservations.html?reservation=<id>`. The target employee APIs verify ownership before returning details.
