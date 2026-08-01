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

The future Employee Portal owns requester self-service workflows.

Employee users may:

- submit facility requests
- submit room reservation requests
- submit procurement requests
- track their own requests
- receive notifications about request status, approvals, assignments, and completion

Requester-facing permission codes such as `facility_requests.create`, `reservations.create`, `procurement.create`, and `records.create` remain available for future Employee Portal use. They should not be removed from RBAC just because the Admin Portal hides requester-facing entry points.

## Admin-Only Exceptions

The following actions may appear in the FAM Admin Portal only when they are explicitly implemented as admin-owned workflows:

- `Log Request on Behalf`: an admin-assisted intake workflow, distinct from generic employee submission
- `Create Internal Work Order`: a FAM-owned maintenance record that is not an employee maintenance concern submission
- `Register Asset`: an asset registry workflow owned by FAM, backed by a real write API and validation

If the backend workflow is not implemented, the Admin Portal should hide the action instead of showing a fake, disabled, or placeholder create button.
