# Portal Action Boundaries

This project separates operational administration from requester self-service.

## FAM Admin Portal

The FAM Admin Portal is for Facilities and Administrative Management staff. Its pages should focus on operational work after a transaction has entered the system.

Admin users may:

- receive submitted operational records
- review request details and supporting information
- assign requests or work orders to responsible personnel
- approve, reject, endorse, or cancel records where administratively appropriate
- process work, procurement coordination, reservations, records, and asset lifecycle activity
- verify completion and close workflows
- report on operational performance, SLA status, activity, and exceptions

The Admin Portal should not present ordinary employee/requester submission actions such as creating a new facility request, room reservation, procurement request, or administrative record upload unless a real admin-only workflow exists.

## Employee Portal

The Employee Portal owns requester self-service workflows. Phase 1 provides the shell at `pages/employee/`, employee-only navigation, safe employee context, empty employee pages, and a read-only profile.

Employee users may:

- submit facility requests
- submit room reservation requests
- check in to approved room reservations during the allowed window
- check out from checked-in room reservations
- submit procurement requests
- track their own requests
- receive notifications about request status, approvals, assignments, and completion

Requester-facing permission codes such as `facility_requests.create`, `reservations.create`, `procurement.create`, and `records.create` remain available for future Employee Portal use. They should not be removed from RBAC just because the Admin Portal hides requester-facing entry points.

Facility Request and Room Reservation submission forms are implemented in the Employee Portal and must use the shared module services. Room reservation attendance is requester-owned in the normal workflow: employees check themselves in and out through employee-scoped endpoints. Dashboard quick actions should navigate to those employee workspaces and must not fake record creation.

## Admin-Only Exceptions

The following actions may appear in the FAM Admin Portal only when they are explicitly implemented as admin-owned workflows:

- `Log Request on Behalf`: an admin-assisted intake workflow, distinct from generic employee submission
- `Create Internal Work Order`: a FAM-owned maintenance record that is not an employee maintenance concern submission
- `Register Asset`: an asset registry workflow owned by FAM, backed by a real write API and validation

If the backend workflow is not implemented, the Admin Portal should hide the action instead of showing a fake, disabled, or placeholder create button.

## Visitor Management Admin Boundary

Visitor Management is an Admin Portal module because reception and FAM staff own on-site intake, verification, badge issuance, review, check-in, check-out, and operational history.

Allowed admin actions include reception visitor entry, manual ID verification, badge issuance, badge-based exit lookup, badge return and check-out, approving or rejecting queued legacy visits, cancelling an administrative visit record, and viewing visitor history.

The active Phase 1 entry workflow is the authenticated Reception Console. Public visitor self-registration, OTP verification, QR pass generation, and QR scanner lookup remain compatibility/future-phase flows and must not be promoted as the primary Admin Portal workflow.

The Admin Portal must not expose anonymous visitor account flows. Public visitor self-registration remains handled by `pages/visitor-registration.html` and the unauthenticated `api/public/visitors/*` endpoints when that flow is used.

