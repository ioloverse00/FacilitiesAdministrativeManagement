# Visitor Management

Visitor Management is a Reception/Security Operations Dashboard for submitted visitor records. Normal visitor registration originates outside the Admin Portal through the future public QR-based registration flow.

## Business Workflow

The target workflow is:

1. Visitor arrives at the facility.
2. Visitor scans the Visitor Registration QR Code.
3. Public Visitor Registration Landing Page opens.
4. Visitor completes the registration form.
5. Email OTP verification is completed.
6. Visitor Reference Number is generated as `VIS-YYYY-NNNN`.
7. Submission appears in the Visitor Management queue with a visitor pass QR token.
8. Visitor presents the Visitor Reference Number or Visitor Pass QR at reception.
9. Reception or security verifies identity.
10. Visitor is checked in.
11. A badge may be issued when needed.
12. Visitor completes the visit.
13. Visitor is checked out and any issued badge is returned.

The public landing page, email OTP verification, and Phase 1 visitor pass QR confirmation now cover steps 3 through 8 for local/public pre-registration. Scanner, camera, kiosk, and QR-driven check-in/check-out actions remain later phases.

## Admin Portal Scope

- View the submitted visitor queue.
- Search, sort, and filter visitor records through the enterprise table controls.
- Review, approve, reject, cancel, check in, and check out visitor visits according to permission.
- Verify identity during check-in.
- Optionally issue and return reusable visitor badges.
- Preserve visit history, activity events, and audit log records for administrative actions.

The Admin Portal should not encourage staff to create ordinary visitor registrations. Visitors, including applicants, should normally register themselves through `pages/visitor-registration.html`.

## Manual Registration

Manual Registration is retained only as a contingency workflow for exceptional cases, such as:

- visitor has no phone
- QR registration is unavailable
- internet outage
- kiosk unavailable
- reception must encode on behalf of a visitor due to an operational exception

Manual Registration uses the existing authenticated create endpoint and permissions, but it is intentionally presented as a secondary action rather than the primary CTA.

## Data Model

The module reuses the existing `visitor`, `visit`, and `visitor_pass` legacy area and extends it with the migration in `database/migrations/2026_visitor_management.sql`.

Primary tables:

- `visitor`: visitor profile and contact/identity summary.
- `visit`: visit lifecycle, destination, host, applicant metadata, approval status, scheduled fields, actual check-in/out, registration source, and QR token metadata.
- `visitor_badge`: reusable badge/pass inventory for issue and return.
- `visitor_visit_history`: status and action history per visit.
- `visitor_sequence`: annual reference number sequence for `VIS-YYYY-NNNN` values.
- `visitor_registration_challenge`: public registration OTP challenges. Stores hashed OTP values and temporary validated payloads only.

Scheduled fields are optional for public submissions. The main operations table still emphasizes `Time In` and `Time Out`.

## API Surface

Admin endpoints require authentication and return the standard JSON response shape.

- `GET /api/visitors/index.php`: queue list, summary, search, filters, sorting, pagination.
- `GET /api/visitors/show.php?id=<id>`: visitor visit details and history.
- `GET /api/visitors/options.php`: statuses, types, departments, hosts, spaces, identity document types, badges, and permissions.
- `POST /api/visitors/create-walkin.php`: contingency Manual Registration only.
- `POST /api/visitors/review.php?id=<id>`: approve, reject, or cancel.
- `POST /api/visitors/check-in.php?id=<id>`: verify identity, optionally issue badge, and check in.
- `POST /api/visitors/check-out.php?id=<id>`: check out and return issued badge.
- `POST /api/visitors/update.php?id=<id>`: update editable visit fields before checkout.
- `GET /api/visitors/history.php?id=<id>`: history-only endpoint.
- `GET /api/visitors/badges.php`: available/issued badge inventory.

Public endpoints do not require an admin session:

- `GET /api/public/visitors/options.php`: public-safe visitor types, departments, and spaces.
- `POST /api/public/visitors/start.php`: validate a draft and send an email OTP.
- `POST /api/public/visitors/verify-otp.php`: verify the OTP.
- `POST /api/public/visitors/resend-otp.php`: resend with cooldown and limits.
- `POST /api/public/visitors/submit.php`: create the pending-review visit after verification.
- `GET /api/public/visitors/qr-lookup.php?token=<opaque-token>`: return minimal public-safe QR lookup data: reference number, status, and validity.
- `GET /api/public/visitors/qr-svg.php?token=<opaque-token>`: render the public-safe lookup URL as the confirmation QR image.

Authenticated scanner endpoints require `visitors.view`:

- `GET /api/visitors/scan-lookup.php?token=<opaque-token>`: hash the raw QR token, verify token validity, and return the admin-safe scanner summary.
- `GET /api/visitors/manual-lookup.php?query=<reference-or-token>`: exact Visitor Reference Number lookup or exact raw QR token lookup after hashing.

## Statuses

The UI supports these operational statuses without relying only on color:

- Pending Verification
- Approved
- Rejected
- Checked In
- Checked Out
- Cancelled
- No Show
- Expired

Legacy or backend statuses such as `PRE_REGISTERED`, `PENDING_REVIEW`, and `ARRIVED` are displayed as Pending Verification where appropriate.

## Duplicate Visit Rule

Visitor registration enforces one active visit at a time by normalized email address. Active statuses are `PENDING_REVIEW`, `APPROVED`, `ARRIVED`, and `CHECKED_IN`.

The system must not block a visitor simply because another visit exists on the same calendar day. A visitor may register again immediately after the previous visit reaches `CHECKED_OUT`, `CANCELLED`, `REJECTED`, `NO_SHOW`, or `EXPIRED`.

This rule is enforced server-side for public OTP-verified submissions and manual contingency registration.

## Public Registration URL

`http://localhost/FacilitiesAdministrativeManagement/pages/visitor-registration.html`

This can be encoded into a static QR code for reception signage.

## Visitor Pass QR Lifecycle

Phase 1 generates a visitor pass QR only after successful OTP verification and submission. The raw token is returned once to the browser for QR rendering, while the visit row stores only a `SHA-256` token hash plus created, expiry, and revocation timestamps.

The QR payload is an opaque public lookup URL and does not encode personal information, database IDs, OTP values, verification tokens, or the visitor reference number directly. The token remains resolvable through check-in/check-out status changes until expiry unless revoked. Rejected, cancelled, no-show, expired, deleted, or revoked visits are not valid for visitor pass use.

The authenticated Visitor Scanner lives at `pages/visitor-scanner.html` inside the Admin Portal shell. It reads the token, calls the protected lookup service, and shows backend-computed allowed actions from the current visit status and the operator permissions. Scanning never checks visitors in or out automatically; review, check-in, badge issuance, check-out, and badge return continue through the existing protected endpoints with CSRF enforcement.

The same QR is reused throughout the visit lifecycle. Before check-in it locates pending or approved visits. During an active visit it locates the same `CHECKED_IN` record for checkout. After checkout it shows the completed state with no further visit actions.

## Future Phases

- Visitor self-service / kiosk flow
- Dedicated Reception/Security role navigation

## Permissions

- `visitors.view`: view the module and records.
- `visitors.create_walkin`: use contingency Manual Registration.
- `visitors.review`: review visitor records.
- `visitors.approve`: approve or reject visits.
- `visitors.checkin`: check visitors in and issue badges.
- `visitors.checkout`: check visitors out and return badges.
- `visitors.manage_badges`: manage badge/pass inventory.
- `visitors.export`: export visitor lists.
- `visitors.manage`: administrative override for visitor actions.

`SYSTEM_ADMIN` and `FAM_ADMIN` receive all visitor permissions. `FACILITY_MANAGER`, `RESERVATION_OFFICER`, and `AUDITOR` receive scoped permissions seeded by the migration.
