# Visitor Management

Visitor Management is a Reception/Security Operations module for on-site visitor entry, badge issuance, badge return, and check-out. Phase 1 makes the authenticated Reception Console the primary workflow.

## Primary Reception Workflow

1. Visitor arrives at reception.
2. Reception staff verifies the presented ID, optionally using camera capture and AI-assisted extraction to transcribe the identity fields.
3. Reception staff records or confirms the visitor identity, purpose, destination, and required badge.
4. The system creates the visitor and visit record in one server-side transaction.
5. The selected badge is issued to that visit.
6. The visit is immediately marked `CHECKED_IN`.
7. When the visitor exits, reception enters or selects the physical badge number.
8. The system looks up only the currently checked-in visit assigned to that badge.
9. Reception confirms badge return and check-out.
10. The system returns the badge to available inventory and marks the visit `CHECKED_OUT`.

The console route remains `pages/visitor-scanner.html` for compatibility with existing navigation, but the UI is now labeled as the Visitor Reception Console.

## Camera ID AI Assistance

The Reception Console includes a `Scan ID` control inside the New Visitor Entry ID Verification section. It opens a browser camera modal, detects when an ID-like card is steady inside the frame, auto-captures one cropped frame, and sends that temporary image to the server endpoint `POST /api/visitors/analyze-id.php`.

The PHP endpoint performs the Gemini Vision request through the server-side `VisitorIdAnalysisService`. The browser never receives or exposes the Gemini API key, and it never calls Gemini directly.

The ID scan is assistive only:

- The browser analyzes live camera frames only for local stability/card-presence detection.
- One captured frame creates one server-side Vision request.
- No continuous live video is uploaded.
- The captured image is validated as JPEG, PNG, or WEBP, limited to 5 MB, and sent temporarily to Gemini as inline base64 image data through the Gemini `generateContent` API.
- The server requests a strict structured result with only `document_detected`, `full_name`, `id_type`, `id_last4`, and `needs_review`.
- The prompt explicitly forbids extracting address, birth date, sex, nationality, signature, photo biometrics, full ID number, or unrelated fields.
- The prompt and server sanitizer reject field-label names such as `LAST NAME FIRST NAME MIDDLE NAMES`.
- `NATIONAL_ID` and `PROFESSIONAL_ID` are normalized to the canonical app value `GOVERNMENT_ID`.
- The captured image is not persisted by the FAM application.
- The officer must review and may correct the suggested Visitor Name, ID Type, and optional ID Last 4 before the main form is populated.
- Visitor Type, Purpose, Destination, Host, Badge, and Notes are never inferred from the ID.
- AI extraction never approves a visitor and never performs check-in.

The officer remains responsible for physically checking the presented ID. The UI says ID details were confirmed only after the officer reviews and accepts the extracted values. It must never imply AI approval, automatic identity verification, or automatic check-in.

Manual entry remains fully available if the camera is unavailable, permission is denied, the API key is missing, the Gemini request fails, the card is unusual, or the officer chooses not to scan. Retake ID discards the temporary capture and restarts the camera.

### Gemini Configuration

Configure the server with:

- `GEMINI_API_KEY`
- `GEMINI_VISITOR_ID_MODEL`
- `GEMINI_VISITOR_ID_TIMEOUT_SECONDS`

The implementation uses the official Gemini `models.generateContent` image input pattern: request content contains text instructions and one `inline_data` image part. The selected model is configured by `GEMINI_VISITOR_ID_MODEL`.

### Local OCR Fallback

Local OCR assets remain vendored under `assets/vendor/tesseract/` as a fallback when the Gemini endpoint cannot return a result:

- `tesseract.min.js`
- `worker.min.js`
- `tesseract-core.wasm.js`
- `tesseract-core.wasm`
- `tessdata/eng.traineddata.gz`
- `LICENSE.tesseract-js.md`

The fallback also remains assistive and temporary. It does not store raw camera frames, cropped ID images, raw OCR text, or full ID numbers.

### ID Type Recognition

ID type detection is centralized in the browser helper `detectIdType(ocrText)` and maps only to canonical app values:

- `GOVERNMENT_ID`
- `SCHOOL_ID`
- `COMPANY_ID`
- `PASSPORT`
- `DRIVER_LICENSE`
- `OTHER`

Unknown or low-confidence results are not aggressively guessed. The officer can manually choose the correct ID Type.

### ID-Specific Extraction Rules

The server-side Vision prompt includes layout-aware rules for the supported canonical ID types:

- `DRIVER_LICENSE`: strings like `LAST NAME FIRST NAME MIDDLE NAMES` are field labels. The actual name must be the printed value visually near, beneath, or beside those labels.
- `GOVERNMENT_ID`: Philippine National ID, PRC, and other government/professional IDs map to `GOVERNMENT_ID`. Agency names and document headings are never visitor names.
- `PASSPORT`: the name must come from holder name values near surname/given-name fields, not the country, authority, or document title.
- `COMPANY_ID`: the name must be the employee/cardholder value, not company names, department labels, or `IDENTIFICATION CARD` text.
- `SCHOOL_ID`: the name must be the student/cardholder value, not school names, program labels, or ID card titles.
- `OTHER`: used only when the card appears to be an ID but the canonical type is uncertain.

The server post-validation applies a centralized field-label blacklist and conservative non-name checks. If the extracted `full_name` is a label, government agency heading, document title, date, ID number, or other obvious non-name, it is rejected and the response is marked for review.

### Name and ID Last 4 Handling

Name extraction is centralized in `extractNameCandidate(ocrText, detectedIdType)`. It uses conservative label-based and generic line parsing, then applies safe formatting only: whitespace cleanup and display casing. It does not spell-correct uncertain names.

If an ID-like number is detected, the browser derives only the last four characters. Full ID numbers are not stored in hidden fields, sent to the backend, logged, or persisted.

Server-side AI extraction also returns only the final four alphanumeric characters of a relevant visible ID number when confident. If uncertain, `id_last4` is `null`.

### Evaluation Fixtures

Development-only fixtures live in `tests/fixtures/visitor-ids/`. Commit only synthetic, mock, redacted, or explicitly approved images. Do not commit real visitor IDs.

Run the evaluator with:

```bash
php scripts/evaluate-visitor-id-fixtures.php
```

Repeat the same frozen fixture image up to 10 times:

```bash
php scripts/evaluate-visitor-id-fixtures.php --fixture=philippine_drivers_license_sample_01 --repeat=5
```

The evaluator reports ID type accuracy, normalized full-name match, last-four accuracy, overall pass rate, and field-label-as-name failures. It does not write raw model responses or full ID numbers to logs.

### Diagnostics Mode

Set `VISITOR_ID_DIAGNOSTICS=true` only in local development or UAT environments. Normal production behavior is unchanged when it is disabled.

Diagnostics track the ID scan pipeline as safe stages: `CAPTURE`, `PREPROCESS`, `AI_REQUEST`, `AI_RESPONSE`, `POST_VALIDATE`, `FORM_POPULATE`, and final failure/success categories such as `AI_NO_DOCUMENT`, `AI_NAME_LABEL_ERROR`, `POST_VALIDATION_REJECTED_NAME`, and `SUCCESS`.

Safe diagnostics may include image dimensions, crop dimensions, SHA-256 image fingerprint, brightness/contrast/sharpness ratings, model name, latency, document-detected flag, validation pass/fail flags, `needs_review`, and failure stage. The system must not store raw ID images, base64 image data, full OCR text, full ID numbers, addresses, birth dates, or other sensitive ID fields.

When diagnostics are enabled, the Reception Console may show a small in-memory diagnostics block after analysis. It is cleared with the modal/session and is not persisted to the database.

Use the diagnostics to separate likely causes:

- same image, inconsistent outputs: likely AI variability
- same image correct, repeated camera captures vary: likely capture quality variability
- AI output correct but field mapping wrong: likely post-processing or mapping bug
- low sharpness/contrast on failed attempts: likely capture quality
- repeated field-label names: likely prompt or visual extraction rule issue

### Privacy and Data Minimization

The FAM application does not store raw camera frames, cropped ID images, raw OCR text, or full ID numbers. Temporary frame and extraction state are cleared when scanning closes, scanning restarts, page unloads, or visitor check-in succeeds.

Scanned visitor IDs are not sent to Document Management and do not create document or Records Retention entries. Because Google Gemini is an external processor for the Vision request when enabled, production deployment should be reviewed against the organization's privacy notice, data-processing terms, and retention settings before adding `GEMINI_API_KEY`.

### Known Recognition Limitations

Extraction quality depends on camera focus, lighting, glare, ID layout, card condition, and browser performance. The scanner may fail to detect useful text for blurry, moving, glare-heavy, partially visible, or uncommon IDs. In those cases, the officer should retake the ID or use manual entry.

## Badge Lifecycle

`visitor_badge` is the reusable physical pass inventory.

- `AVAILABLE`: can be assigned to a new reception check-in.
- `ISSUED`: assigned to exactly one currently checked-in visit.
- `LOST`, `DAMAGED`, `DISABLED`: reserved exception states and must not be assignable.

Reception check-in requires an available badge. Badge return and check-out are handled together so staff can process exits by badge number without first searching the visitor list.

## Retired Duplicate Entry Points

Rapid Entry, QR-first scanning, and duplicate manual-registration entry points are not exposed in the active Reception Console. The active on-site intake path is New Visitor Entry with optional camera ID OCR assistance, badge assignment, and immediate check-in.

## Public Registration and QR Compatibility

The public visitor registration, OTP verification, visitor pass QR generation, and legacy scanner lookup endpoints remain in the system for compatibility and future phases.

They are not the primary Phase 1 reception workflow. Reception staff should use the Reception Console for normal on-site entry and badge return.

## Privacy Boundary

Reception records officer-confirmed identity verification metadata only. Camera extraction assists transcription, but the FAM application does not store raw identity documents or raw OCR text.

## Data Model

- `visitor`: visitor profile and contact/identity summary.
- `visit`: visit lifecycle, destination, host, approval, actual check-in/out, source, identity verification, and badge assignment.
- `visitor_badge`: reusable badge/pass inventory for issue and return.
- `visitor_visit_history`: lifecycle history per visit.
- `visitor_sequence`: annual reference number sequence for `VIS-YYYY-NNNN`.
- `visitor_registration_challenge`: public OTP challenge storage for legacy/future public registration.

## Admin API Surface

- `GET /api/visitors/index.php`: queue list, summary, search, filters, sorting, pagination.
- `GET /api/visitors/show.php?id=<id>`: visitor visit details and history.
- `GET /api/visitors/options.php`: statuses, departments, hosts, spaces, identity types, badge inventory, and permissions.
- `POST /api/visitors/reception-check-in.php`: create visitor, issue badge, and immediately check in.
- `GET /api/visitors/badge-lookup.php?badge_number=<badge>`: find the active checked-in visit assigned to a badge.
- `POST /api/visitors/badge-check-out.php`: return badge and check out the assigned active visit.
- `POST /api/visitors/create-walkin.php`: legacy/manual contingency registration.
- `POST /api/visitors/review.php?id=<id>`: approve, reject, or cancel.
- `POST /api/visitors/check-in.php?id=<id>`: legacy check-in endpoint for existing queued records.
- `POST /api/visitors/check-out.php?id=<id>`: legacy check-out endpoint.
- `POST /api/visitors/update.php?id=<id>`: update editable visit fields before checkout.

## Duplicate Visit Rule

Visitor registration enforces one active visit at a time by normalized email address. Active statuses are `PENDING_REVIEW`, `APPROVED`, `ARRIVED`, and `CHECKED_IN`.

The system must not block a visitor simply because another visit exists on the same calendar day. A visitor may register again immediately after the previous visit reaches `CHECKED_OUT`, `CANCELLED`, `REJECTED`, `NO_SHOW`, or `EXPIRED`.

## Permissions

- `visitors.view`: view visitor records.
- `visitors.create_walkin`: create reception/manual visitor entries.
- `visitors.review`: review visitor records.
- `visitors.approve`: approve or reject queued visits.
- `visitors.checkin`: check visitors in and issue badges.
- `visitors.checkout`: check visitors out and return badges.
- `visitors.manage_badges`: manage badge/pass inventory.
- `visitors.export`: export visitor lists.
- `visitors.manage`: administrative override for visitor actions.
