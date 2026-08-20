# Legal Management

Legal Management tracks legal matters, assignments, lifecycle actions, supporting evidence, AI-powered administrative summaries, and matter history for the FAM system.

## Phase 2: Supporting Documents and AI Matter Summary

Supporting documents and evidence are stored by Document Management. Legal Management links those documents to a matter by creating normal document records with:

- `related_module = LEGAL_MANAGEMENT`
- `related_reference = LM-YYYY-NNNN`

Legal matters do not store file paths, blobs, or duplicate document versions. Viewing, downloading, versioning, and archiving remain governed by Document Management and its records permissions.

Full Legal Matter details, AI summaries, sensitive history, regeneration, and supporting evidence metadata/view/download require administrator-level Legal access through `legal.manage`. Generic records permissions alone do not authorize access to legal-linked evidence.

## Attachment Flow

New legal matters require at least one supporting document or evidence file. The Create Matter form does not ask the user to choose a document category or confidentiality level.

Legal evidence uploads are limited to PDF, PNG, JPG, and JPEG files up to 10 MB each. All selected files are validated with the Document Management upload conventions, including size, extension, and MIME type checks, before the matter is created. If any selected file is invalid, creation is blocked and no partial legal matter or orphan document record is left behind.

Legal Management automatically stores each supporting file as a Document Management record using the canonical Legal document category (`DOC-LEGAL`) and the category's restricted legal confidentiality default. The document is linked to the generated matter number using:

- `related_module = LEGAL_MANAGEMENT`
- `related_reference = LM-YYYY-NNNN`

Each successful attachment logs `LEGAL_DOCUMENT_ATTACHED` in legal matter history and the system activity log.

## AI-Powered Matter Summarization

When a new matter is submitted with readable supporting evidence, Legal Management performs one controlled Gemini request after the matter and document records are saved. The result is stored on the legal matter as derived metadata:

- `ai_summary`
- `ai_summary_status`
- `ai_summary_generated_at`
- `ai_summary_provider`
- `ai_summary_model`
- `ai_summary_source_fingerprint`

The AI summary is not a Document Management file and does not create a separate retention record. It is an operational synthesis only.

Supported readable sources are the current linked versions of PDF, PNG, JPG, and JPEG evidence documents. Office documents are not accepted as Legal evidence in the current Phase 2 scope.

AI states:

- `NOT_REQUESTED`: no summary request has been made
- `PENDING`: analysis request is in progress
- `READY`: summary is available
- `FAILED`: analysis failed safely
- `NO_READABLE_SOURCE`: no supported readable source was available
- `STALE`: supporting documents changed after the previous result

Adding a supporting document or uploading a new current document version marks existing AI summary output stale. Authorized users may explicitly regenerate the AI Matter Summary. Viewing matter details never triggers Gemini calls.

## Safety and Human Authority

The Legal AI prompt restricts output to factual, neutral administrative summarization grounded in the linked supporting documents. It must not decide fault, guilt, legal liability, punishment, resolution, or legal advice.

Humans remain authoritative for matter classification, priority, assignment, status changes, legal conclusions, resolution, closure, legal hold decisions, and final interpretation of source documents.

## Privacy Boundary

AI-enabled summarization may transmit the content of linked supporting documents to the configured Gemini service. The request is minimized to the current Legal Matter's readable supporting documents only. It does not include unrelated repository files, other legal matters, user profile data, session data, or source documents from other modules.

Raw Gemini responses, prompts, chain-of-thought, complete extracted document text, and source file contents are not stored in Legal Management.

## Failure and Quota Behavior

Legal Matter and Document Management creation are authoritative. Gemini timeout, quota, missing configuration, malformed output, unsupported source files, or service failure will not roll back a valid matter or uploaded documents. The matter remains available and the officer can inspect source documents directly.

## Phase 3: Parties Involved and AI-Assisted Party Extraction

Legal Management stores confirmed involved parties in `legal_matter_party`. AI-detected candidates are stored separately in `legal_matter_party_suggestion` until an authorized human reviews them.

Confirmed party roles use the controlled taxonomy:

- `REPORTING_PARTY`
- `COMPLAINANT`
- `RESPONDENT`
- `WITNESS`
- `PERSON_INVOLVED`
- `REPRESENTATIVE`
- `INSPECTOR`
- `OTHER`

Party types use:

- `EMPLOYEE`
- `VISITOR`
- `SUPPLIER_VENDOR`
- `CONTRACTOR`
- `EXTERNAL_PERSON`
- `ORGANIZATION`
- `GOVERNMENT_AGENCY`
- `OTHER`

When a party maps to an existing employee or visitor, Legal Management links the existing reference (`employee_reference_id` or `visitor_id`) instead of duplicating profile data. External parties store only minimal matter-specific identity/context fields such as name, organization, contact information, and notes.

AI party extraction reads only current readable supporting documents linked to the selected Legal Matter. It creates pending suggestions, not official parties. The prompt prohibits unsupported inference of guilt, fault, liability, responsibility, wrongdoing, complainant status, or respondent status. If a role is unclear, AI must prefer `PERSON_INVOLVED` or `OTHER`. `RESPONDENT` requires explicit source support or later human correction.

Authorized Legal/Admin users can:

- manually add a party
- re-analyze supporting evidence for party suggestions
- accept an AI suggestion
- edit and accept an AI suggestion
- dismiss an AI suggestion

Accepted suggestions become confirmed `legal_matter_party` records with `ai_suggested = true`, `confirmed_by_user_id`, and `confirmed_at`. Dismissed suggestions remain non-authoritative and prevent the same unchanged suggestion from immediately returning. Confirmed human parties are preserved when evidence is re-analyzed.

The Legal Matter details modal shows `Parties Involved` as a collapsed accordion below the always-visible AI Matter Summary and the expanded Matter Information section. Confirmed parties are shown separately from `AI Suggested Parties` so unreviewed AI output is never mistaken for an official matter party.

Safe history events include `LEGAL_AI_PARTIES_ANALYZED`, `LEGAL_PARTY_ADDED`, `LEGAL_AI_PARTY_ACCEPTED`, `LEGAL_AI_PARTY_EDITED_ACCEPTED`, and `LEGAL_AI_PARTY_DISMISSED`. These events log workflow context without storing supporting document contents.
