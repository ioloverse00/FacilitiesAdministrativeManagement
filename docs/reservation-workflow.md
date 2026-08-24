# Room Reservation Workflow

Room Reservations use one shared workflow service for Employee Portal self-service and Admin Portal review.

## Responsibilities

Employees:

- request rooms
- check availability
- track their own reservations
- cancel their own `SUBMITTED` or `APPROVED` reservations
- check in during the allowed window
- check out to complete room usage

Admins:

- review submitted reservations
- approve or reject requests
- monitor the operational calendar
- cancel administratively when authorized
- monitor automatic `NO_SHOW` results for approved reservations where the check-in window ended without check-in

Admins do not perform routine check-in/check-out in the normal workflow.

## Lifecycle

Normal flow:

`SUBMITTED / PENDING`
-> `APPROVED / APPROVED`
-> `CHECKED_IN`
-> `COMPLETED`

Alternative terminal paths:

- `SUBMITTED` -> `REJECTED`
- `SUBMITTED` -> `CANCELLED`
- `APPROVED` -> `CANCELLED`
- `APPROVED` -> `NO_SHOW` automatically when scheduled end has passed and no check-in was recorded

Supported lifecycle statuses:

- `SUBMITTED`
- `APPROVED`
- `REJECTED`
- `CANCELLED`
- `CHECKED_IN`
- `COMPLETED`
- `NO_SHOW`

Supported approval statuses:

- `PENDING`
- `APPROVED`
- `REJECTED`
- `CANCELLED`

Legacy statuses `PENDING`, `PENDING_APPROVAL`, and `CHECKED_OUT` are normalized by migration/code compatibility and should not be created by new workflows.

## Conflict Blocking

Only these statuses block availability:

- `SUBMITTED`
- `APPROVED`
- `CHECKED_IN`

These statuses do not block availability:

- `REJECTED`
- `CANCELLED`
- `COMPLETED`
- `NO_SHOW`

`SUBMITTED` reservations temporarily hold the room while waiting for admin approval to prevent duplicate requests for the same schedule.

Setup and cleanup buffer columns remain in `facility_reservation`, but the normal Employee Portal form does not expose buffer controls. Current centralized defaults are `0` minutes for setup and cleanup until room-specific policy is added.

## Request Letter and AI Request Summary

New Employee Portal room reservation requests require a reservation-owned Request Letter upload. The file remains attached to Room Reservations only; it is not copied into Document Management and does not create a Document Management record.

Supported request-letter formats are PDF, PNG, JPG, and JPEG with the existing 10 MB maximum. The original Request Letter remains the authoritative source for review. The AI Request Summary is advisory, read-only preview text for Employee and FAM review screens.

After a reservation and Request Letter are saved, the browser triggers one explicit reservation AI processing request. That run prepares the Request Letter once, sends one Gemini 3.6 request, and persists the summary/status on `facility_reservation`. Normal details opens, FAM review opens, and page refreshes read the persisted fields only and do not call Gemini.

The reservation AI service uses `GEMINI_RESERVATION_SUMMARY_MODEL` when configured, then the existing Gemini model fallback convention, with `gemini-3.6-flash` as the final fallback. Timeout is controlled by `GEMINI_RESERVATION_SUMMARY_TIMEOUT_SECONDS` and defaults to 45 seconds.

Retry policy is intentionally small: one retry is allowed only for timeout, transport, or provider service-unavailable failures. Quota/rate limit, authentication, invalid model, malformed output, local file validation, and unreadable-source failures are not retried. Durable statuses distinguish `PENDING`, `READY`, `TIMEOUT`, `RATE_LIMITED`, `FAILED`, and `NO_READABLE_SOURCE`.

AI failure never invalidates or rolls back a saved reservation. If summary generation fails, the reservation remains submitted and the Request Letter remains available through the authorized View/Download endpoints.

## Check-In / Check-Out

Employee check-in opens 30 minutes before scheduled start and closes at scheduled end.

Employee check-in requires:

- authenticated employee session
- ownership of the reservation
- `status = APPROVED`
- `approval_status = APPROVED`
- no existing `checked_in_at`
- server time inside the allowed window

Employee check-out requires:

- ownership of the reservation
- `status = CHECKED_IN`
- `checked_in_at` populated
- no existing `checked_out_at`

Check-out transitions directly from `CHECKED_IN` to `COMPLETED` and records `checked_out_at`.

## Automatic No-Show Reconciliation

Reservation data access runs a shared `ReservationService` reconciliation before returning list, calendar, detail, and dashboard data.

An approved reservation is automatically marked `NO_SHOW` when all are true:

- `status = APPROVED`
- `approval_status = APPROVED`
- `end_datetime < NOW()`
- `checked_in_at IS NULL`
- `deleted_at IS NULL`

The transition writes `reservation_history` with `old_status = APPROVED`, `new_status = NO_SHOW`, a system/null actor, and the reason `Reservation automatically marked as no-show after the check-in window ended.`

`NO_SHOW` is terminal and does not block future room availability. `CHECKED_IN` reservations whose scheduled end has passed are not marked `NO_SHOW`; they remain checked in until a separate completion/checkout workflow handles them.

## Endpoints

Employee endpoints:

- `GET /api/employee/reservations/options.php`
- `GET /api/employee/reservations/list.php`
- `GET /api/employee/reservations/show.php?id=<id>`
- `POST /api/employee/reservations/availability.php`
- `POST /api/employee/reservations/create.php`
- `POST /api/employee/reservations/create.php?analyze_ai=1&id=<id>`
- `POST /api/employee/reservations/cancel.php?id=<id>`
- `POST /api/employee/reservations/check-in.php?id=<id>`
- `POST /api/employee/reservations/check-out.php?id=<id>`

Admin endpoints:

- `GET /api/reservations/index.php`
- `GET /api/reservations/calendar.php`
- `GET /api/reservations/options.php`
- `GET /api/reservations/show.php?id=<id>`
- `POST /api/reservations/approve.php?id=<id>`
- `POST /api/reservations/reject.php?id=<id>`
- `POST /api/reservations/cancel.php?id=<id>`
- `POST /api/reservations/no-show.php?id=<id>`

Admin create/check-in/check-out endpoints may exist for compatibility, but normal UI does not expose routine admin reservation creation or attendance tracking. The manual no-show endpoint remains as an exception fallback; routine no-show marking is automatic.

## Data

- `facility_reservation` stores request, schedule, approval, attendance timestamps, cancellation reason, and remarks.
- `facility_reservation.ai_request_summary*` stores the persisted advisory AI Request Summary state.
- `reservation_request_letter` stores reservation-owned Request Letter metadata and storage path.
- `reservation_history` stores lifecycle transitions and actor user.
- `reservation_participant` remains the participant/attendance extension point.

Migration `database/migrations/2026_room_reservation_workflow.sql` normalizes legacy `PENDING`/`PENDING_APPROVAL` to `SUBMITTED` and `CHECKED_OUT` to `COMPLETED`.
