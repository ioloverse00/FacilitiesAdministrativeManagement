# Module Data Ownership

This document records which FAM module owns each operational data area so future pages do not duplicate records or invent parallel tables when a focused extension is enough.

| Module | Owns | Shared References | Notes |
| --- | --- | --- | --- |
| Facility Requests | `facility_request`, request lifecycle and SLA intake records | `employee_reference`, `department_reference`, `facility_space`, `building` | Primary employee/admin facility issue workflow. |
| Maintenance | `maintenance_work_order`, `maintenance_history`, maintenance materials and preventive plans | `facility_request`, `asset`, `facility_space`, `employee_reference`, `inventory_item_reference` | Work orders may originate from facility requests or internal maintenance planning. |
| Asset Management | `asset`, `asset_history`, category and assignment state | `facility_space`, `building`, `employee_reference`, `supplier_reference` | Owns asset lifecycle, not maintenance execution. |
| Room Reservations | `facility_reservation`, `reservation_participant`, `reservation_history` | `facility_space`, `building`, `employee_reference`, `department_reference` | Owns room booking and schedule records. |
| Visitor Management | `visitor`, `visit`, `visitor_badge`, `visitor_visit_history`, `visitor_sequence`, `visitor_registration_challenge` | `employee_reference`, `department_reference`, `facility_space`, `activity_event`, `audit_log` | Owns visitor intake, public pre-registration handoff, review, identity verification, check-in/out, badge issue/return, and visitor history. Employee accounts are not created for visitors. |
| Procurement | `procurement_request`, procurement items and procurement history | `facility_request`, `maintenance_work_order`, `budget_reference`, `supplier_reference`, `inventory_item_reference` | Owns procurement coordination records. |
| Document Management | `document`, `document_version`, document categories, current document metadata | `record`, `record_document`, `department_reference`, `employee_reference` | Owns official FAM document storage, classification, versioning, retrieval, and logical archive behavior. |
| Records Retention & Compliance | `record`, `record_document`, `retention_schedule` | `document`, source module records, `department_reference`, `employee_reference`, `audit_log`, `activity_event` | Owns retention schedule assignment, controlled trigger basis, authoritative trigger-date resolution, policy eligibility dates, effective review dates, legal holds, archive state, and logical disposition. Documents and versions remain owned by Document Management; physical deletion is not part of retention disposition. |
| Reports | Aggregated read models only | All operational modules | Reports should read from source modules and avoid owning transactional data. |
| Employee Portal | Requester-facing views over employee-owned records | `user_account`, `employee_reference`, `facility_request`, `facility_reservation`, `notification` | Does not own separate transactional tables. It filters module records through the authenticated employee identity. |

## Current Top-Level Delivery Scope

The Admin Portal primary navigation is aligned to the official FAM scope:

- Facilities Reservation
- Visitor Management
- Document Management
- Records Retention & Compliance
- Legal Management, pending a usable implementation
- Contract Management, pending a usable implementation

Shared platform pages remain visible:

- Dashboard
- Reports & Analytics
- FAM Administration

The following built/supporting features are preserved for direct access, integrations, reports, settings references, and future work, but are not exposed as current Admin top-level modules:

- Facility Requests
- Maintenance Requests
- Asset Management
- Procurement Requests

## Visitor Management Ownership Rules

- Reuse the existing `visitor` and `visit` tables for visitor profile and visit lifecycle data.
- Treat the authenticated Reception Console as the active Phase 1 intake workflow for normal on-site visitor entry.
- Use `visitor_badge` for reusable physical badge inventory and issue/return state. Direct reception check-in requires an available badge and moves the badge to `ISSUED`.
- Use `visitor_visit_history` for lifecycle history instead of writing ad hoc status notes into the visit record.
- Write audit/activity telemetry for create, review, check-in, check-out, and update actions.
- Keep applicant references as metadata on visits until a separate admissions/applicant module owns applicant source data.
- Store public OTP challenges in `visitor_registration_challenge`; never store or expose plaintext OTP values.
- Public registration, OTP, and visitor pass QR fields remain compatibility/future-phase workflows; they are not the primary Phase 1 reception workflow.
- Do not store raw ID images, OCR output, or scanned document files in Visitor Management Phase 1.

## Employee Portal Ownership Rules

- Reuse `user_account.employee_reference_id` for authenticated employee context.
- Do not accept employee IDs from query parameters for employee-owned records.
- Facility request ownership is `facility_request.requested_by_employee_reference_id`.
- Room reservation ownership is `facility_reservation.requested_by_employee_reference_id`.
- Notification ownership is `notification.recipient_user_id`.
- Employee profile fields are HR-owned through `employee_reference` and remain read-only until a real profile-update endpoint exists.

## Room Reservation Ownership Rules

- Reuse `facility_reservation` for admin-created and requester-created reservations.
- Use `ReservationService` for numbering, validation, conflict detection, and lifecycle transitions.
- Store status changes in `reservation_history`; do not create page-specific history tables.
- Treat `reservation_participant` as the shared participant/attendance extension point.
- Employee Portal reservation pages must filter by `facility_reservation.requested_by_employee_reference_id`.
- Normal reservations originate from the Employee Portal. Admins review, approve, reject, monitor, cancel administratively, and mark no-show exceptions.
- Employee self check-in/check-out is ownership-scoped and writes to the same `facility_reservation` and `reservation_history` records.

## Records Retention Ownership Rules

- `retention_schedule.retention_trigger_basis` is the controlled trigger taxonomy used by the backend calculation engine.
- `record.retention_trigger_date` is the authoritative business-event date, not necessarily the document date.
- `record.policy_eligibility_date` preserves the original policy-calculated eligibility date.
- `record.administrative_review_date_override` represents an authorized review postponement.
- `record.scheduled_disposition_date` remains the effective review date for compatibility with existing queue/list behavior.
- Records with missing business-event triggers remain `WAITING_FOR_TRIGGER`; source modules are not rebuilt or reactivated merely to satisfy retention.
- Legal Hold takes precedence over disposition regardless of trigger or eligibility state.
