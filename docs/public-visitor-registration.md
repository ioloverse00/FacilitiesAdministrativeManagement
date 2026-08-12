# Public Visitor Registration

Public Visitor Registration is available at:

`http://localhost/FacilitiesAdministrativeManagement/pages/visitor-registration.html`

This URL can be encoded into a static QR code for reception signage. Use any approved QR generator, encode the URL exactly, and test it on the local network before posting it. Visitor pass QR generation after submission is included in Phase 1. Scanner, camera, check-in, and check-out actions are not part of this phase.

## Flow

1. Visitor opens the public page.
2. Visitor enters visit details and accepts the visitor registration consent notice.
3. The API stores a temporary `visitor_registration_challenge` with a hashed OTP.
4. `MAIL_MODE=log` writes the OTP to `storage/logs/mail.log` for local development.
5. Visitor verifies the OTP.
6. Submission creates a `visitor` profile when needed and a `visit` row with `PUBLIC_PRE_REGISTRATION`, `PENDING_REVIEW`, no badge, no check-in timestamp, and a Phase 1 visitor pass QR token.
7. The confirmation screen shows the Visitor Reference Number and Visitor Pass QR.
8. Reception or security reviews the visitor in the Visitor Management queue.

Public submission checks for an existing active visit using the normalized email address. Active means `PENDING_REVIEW`, `APPROVED`, `ARRIVED`, or `CHECKED_IN`. Same-day repeat visits are allowed after the earlier visit becomes `CHECKED_OUT`, `CANCELLED`, `REJECTED`, `NO_SHOW`, or `EXPIRED`.

## Visitor Pass QR

After successful OTP-verified submission, the API returns a one-time raw QR token for browser QR rendering. The database stores only `SHA-256` token hash metadata on the `visit` row: `qr_token_hash`, `qr_token_created_at`, `qr_token_expires_at`, and `qr_token_revoked_at`.

The QR code contains only a public lookup URL with an opaque token:

`/api/public/visitors/qr-lookup.php?token=<opaque-token>`

It must not contain personal information, database IDs, OTP values, verification tokens, or the visitor reference number as the primary encoded value.

The token remains usable through check-in and check-out workflows until its expiry unless it is revoked. Expiry is 24 hours after the scheduled end time, or 24 hours after submission when no scheduled end time is provided. Rejected, cancelled, no-show, expired, deleted, or revoked visits return `valid: false` or are not found.

The QR lookup endpoint returns only public-safe data:

```json
{
  "visitor_reference_number": "VIS-2026-0001",
  "status": "PENDING_REVIEW",
  "valid": true
}
```

The confirmation page and printed confirmation still show `Pending Review` and the reminder that the QR code does not guarantee entry. Reception or Security must verify the visitor registration and identification before entry.

If browser QR generation fails, the page keeps the Visitor Reference Number visible and shows: `QR code could not be generated. Please use your Visitor Reference Number at Reception.`

## Mail Modes

Use `MAIL_MODE=log` for XAMPP development. The OTP is never returned in JSON or displayed in the browser.

SMTP mode uses the `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` environment variables. Credentials must not be hardcoded.

## Migration

Run `database/migrations/2026_public_visitor_registration.sql` after the visitor management migration. It adds the OTP challenge table, privacy-consent columns, and nullable public-registration scheduling/destination fields.

Run `database/migrations/2026_visitor_qr_phase1.sql` after that migration to add Phase 1 visitor pass QR token metadata.

## Privacy Note

The public page displays a short Privacy Notice and Consent statement:

`We collect and process your personal information for visitor registration, identity verification, facility access control, safety, security, and related administrative purposes.`

It references the Data Privacy Act of 2012 (Republic Act No. 10173), states that information is retained only as long as necessary for the stated purposes, and requires explicit consent before submission.

The page also includes an expandable `Read the full Privacy Notice` placeholder. The client or Data Protection Officer must review and approve the final full privacy notice before production deployment.
