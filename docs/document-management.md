# Document Management

Document Management stores, classifies, versions, retrieves, and logically archives official FAM documents.

## Scope

Supported working categories:

- Facility & Reservation
- Visitor
- Administrative
- Legal
- Contract

The module excludes HR employee files, accounting files, inventory files, arbitrary personal files, and procurement files unless a later official FAM workflow explicitly links them.

## Data Model

The implementation reuses `document_category`, `document`, `document_version`, `record`, `record_document`, and `retention_schedule`.

`document` stores document metadata and the current version number. `document_version` stores immutable file-version metadata. `record` and `record_document` preserve compatibility with future Records Retention & Compliance phases.

## Numbering

Document numbers are generated server-side in `DOC-YYYY-NNNN` format. The frontend does not create document reference numbers.

## File Storage

Uploaded files are stored under `storage/documents/{document_id}/v{version}/`. Stored filenames are random. Original filenames are retained as metadata only. Files are served through authorized API endpoints instead of direct storage URLs.

## Versioning

Creating a document creates `v1`. Uploading a new version creates a new `document_version` row, marks older versions as not current, updates `document.current_version_number`, and preserves old files.

## Archive Behavior

Archiving sets `document.document_status = ARCHIVED`. It does not delete documents, versions, or files. Retention disposition remains a later Records Retention & Compliance workflow.

## Permissions

The module reuses existing records permissions:

- `records.view`: list, view, and download
- `records.create`: create/upload
- `records.edit`: upload new versions and archive
- `records.export`: reserved for future export

Write endpoints require CSRF protection.

## Security

Upload validation checks extension, MIME type, size, upload errors, and sanitized filenames. Initial allowed file types are PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, and JPEG.

Legal Management evidence uploads use a stricter workflow-specific allowlist: PDF, PNG, JPG, and JPEG only, up to 10 MB each. This does not change general Document Management upload support for non-legal document records.

## Retention Integration

When Document Management creates a linked retention record, it may store the document date as record metadata, but Records Retention resolves the authoritative retention trigger separately from the schedule's controlled trigger basis.

Document date is used as the retention trigger only when the assigned schedule explicitly uses `DOCUMENT_DATE`. Schedules such as Contracts and Agreements require their configured business event, such as contract expiration. If that source date is unavailable, the linked retention record remains `WAITING_FOR_TRIGGER` and no policy eligibility date is fabricated from upload date or document date.

For contract-related Legal supporting documents, Document Management stores reviewable contract metadata candidates and the confirmed authoritative metadata. Gemini extraction may propose `agreement_reference`, `effective_date`, `expiration_date`, and `agreement_status`, but only confirmed values update canonical document fields such as `document.effective_date` and `document.expiration_date`.

Document Management remains the source of truth for file storage, metadata, versioning, retrieval, and logical archive behavior. Records Retention owns legal hold, compliance review, policy eligibility, administrative review overrides, archive decisions, and logical disposition.

## Legal Management Links

Legal supporting documents are ordinary Document Management records. Legal Management automatically assigns the canonical Legal category, applies the `CONFIDENTIAL` classification, sets `related_module` to `LEGAL_MANAGEMENT`, and sets `related_reference` to the legal matter number.

Users do not choose document category, related module, related record, or confidentiality when attaching evidence from Legal Management.

When a Legal Management document creates a linked retention record, Document Management resolves the retention schedule from the referenced Legal Matter's authoritative `matter_type`. It does not map every `LEGAL_MANAGEMENT` source to Contracts. Property damage, facility incident, visitor incident, complaint, claim, compliance, and other matters map to General Administrative Records unless a more specific active schedule exists; contract-related matters map to Contracts and Agreements.

Legal-linked documents have an additional server-side authorization boundary. The normal `records.view`, `records.create`, and `records.edit` permissions continue to govern ordinary Document Management documents, but legal-linked evidence also requires administrator-level Legal access through `legal.manage` for metadata, view, download, version upload, and archival actions.

Document Management remains the source of truth for file storage, metadata, versions, and archive lifecycle.

Legal AI Matter Summary is not stored as a document, file, or generated PDF. It is derived Legal Matter metadata. Document Management only supplies authorized current source files to the Legal summarization workflow when they are linked to that matter.

The Legal AI workflow may send only the current readable versions of documents linked to the specific Legal Matter being summarized. Raw Gemini responses, prompts, source file contents, and permanent storage paths are not exposed to the frontend.

The Legal AI summary text is never an authoritative retention trigger source. Contract expiration dates become eligible for Records Retention only after they are stored as confirmed structured Document Management metadata. Confirmation records provenance through contract metadata status/source and confirmation actor/timestamp fields.

## Template Management

Document Template Management governs reusable master templates such as client service agreements, employee contracts, NDAs, and contract amendment templates. Templates are not individual contracts and do not generate contract-specific documents in this phase.

In v1, the uploaded template file is the authoritative template content. Administrators prepare the reusable document outside the system, upload it, add business metadata, and save it as an active controlled master template. The normal admin workflow does not require typing contract text, template codes, source-module identifiers, placeholder syntax, version numbers, or a separate self-approval step.

Template governance uses dedicated `document_templates.*` permissions rather than System Configuration permissions. FAM Super Admin and FAM Admin may manage and retire templates; FAM Staff, Department Head, and Employee users receive no template-governance permissions.

Template files reuse the existing `document` and `document_version` storage model. The template tables store logical template metadata, version lifecycle status, historical approval metadata where it exists, and registered placeholder usage. Active template versions are immutable; changes require uploading a new version, which becomes the current active version immediately while prior versions remain in version history.

The placeholder registry remains future-ready backend infrastructure using strict `{{namespace.field}}` syntax. It is not a required step in the normal v1 administrative workflow. Validation detects valid, duplicate, unknown, malformed, and unsupported placeholders without executing code, SQL, JavaScript, or runtime expressions.
