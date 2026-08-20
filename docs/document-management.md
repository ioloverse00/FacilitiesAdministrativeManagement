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

## Future Retention Integration

Phase 6 does not implement disposition approval, disposal, legal hold, retention review queues, compliance calendars, or retention enforcement. It only keeps document-record links integration-ready.

## Legal Management Links

Legal supporting documents are ordinary Document Management records. Legal Management automatically assigns the canonical Legal category, applies the Legal category's restricted confidentiality default, sets `related_module` to `LEGAL_MANAGEMENT`, and sets `related_reference` to the legal matter number.

Users do not choose document category, related module, related record, or confidentiality when attaching evidence from Legal Management.

Legal-linked documents have an additional server-side authorization boundary. The normal `records.view`, `records.create`, and `records.edit` permissions continue to govern ordinary Document Management documents, but legal-linked evidence also requires administrator-level Legal access through `legal.manage` for metadata, view, download, version upload, and archival actions.

Document Management remains the source of truth for file storage, metadata, versions, and archive lifecycle.

Legal AI Matter Summary is not stored as a document, file, or generated PDF. It is derived Legal Matter metadata. Document Management only supplies authorized current source files to the Legal summarization workflow when they are linked to that matter.

The Legal AI workflow may send only the current readable versions of documents linked to the specific Legal Matter being summarized. Raw Gemini responses, prompts, source file contents, and permanent storage paths are not exposed to the frontend.
