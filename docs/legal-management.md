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

## Matter Responsibility Flow

A FAM Facilitator or reporting user submits the Legal Matter with the required supporting evidence. New matters always begin as `OPEN` and `Unassigned`; the creator does not choose the responsible handler during matter creation.

FAM Admin/Head users review the matter, AI-supported summary, parties, supporting documents, and action recommendations before explicitly assigning the responsible handler through the Assign Matter workflow. Assignment changes remain separate from matter editing so permissions and audit history stay clear.

Matter Assignment and Action Assignment are intentionally separate:

- Matter Assignment identifies the person responsible for handling the overall Legal Matter.
- Action Assignment identifies the person responsible for completing one specific approved legal action.

Existing assigned matters preserve their current assignment history. The unassigned-on-create rule applies only to newly created matters going forward.

## Queue and Review Workflow

The Matter Queue exposes contextual actions based on the current lifecycle status:

- `OPEN`: `Review`, `Edit`, and `Cancel Matter`
- `UNDER_REVIEW`: `Continue Review`, and `Cancel Matter` when permitted
- `IN_PROGRESS`: `Continue Review`, and `Cancel Matter` when permitted
- `RESOLVED`: `View Details`, and `Reopen Matter` when permitted
- `CLOSED`: `View Details`, and `Reopen Matter` when permitted
- `CANCELLED`: `View Details`

`Review` is the formal review-start action. It transitions a matter from `OPEN` to `UNDER_REVIEW`, records the lifecycle history event, refreshes the queue, and opens the Legal Matter details modal for review.

General matter editing is a pre-review correction workflow only. Once a matter is no longer `OPEN`, the update endpoint rejects generic edits with:

`This legal matter is already under formal review. General matter details can no longer be edited through the pre-review correction workflow.`

Assignment is handled inside the Legal Matter details modal, not from the queue action menu. The Assignment accordion shows the current handler and offers `Assign Matter` or `Reassign` to users with assignment permission.

Lifecycle controls after review are also inside the details modal. `UNDER_REVIEW` matters can move to `IN_PROGRESS` through `Begin Processing`, but the server blocks that transition until a responsible handler is assigned:

`Assign a responsible handler before beginning processing.`

`IN_PROGRESS` matters can be resolved only when existing action guards pass. `RESOLVED` matters can be closed or reopened according to the existing lifecycle permissions and transition rules.

`CLOSED` matters are immutable historical records. They may be viewed for audit, read-only review, supporting document viewing/downloading, AI summary inspection, parties, actions, assignment history, resolution data, and activity history. Ordinary mutation workflows are not allowed after closure, including matter edits, party changes, action changes, assignment changes, evidence additions, AI summary regeneration, party re-analysis, or action re-analysis.

The only controlled exception is the existing authorized `Reopen Matter` lifecycle transition where current RBAC and lifecycle rules permit it. After a successful reopen, controls return according to the resulting matter status.

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

## Phase 4: Actions, Deadlines, and AI Decision Support

Legal Management stores official matter actions in `legal_matter_action`. AI-detected or AI-recommended action candidates are stored separately in `legal_matter_action_suggestion` until an authorized human reviews them.

Official action types use the controlled taxonomy:

- `REVIEW`
- `FOLLOW_UP`
- `DOCUMENT_SUBMISSION`
- `DOCUMENT_REQUEST`
- `MEETING`
- `INSPECTION`
- `COMPLIANCE`
- `RESPONSE`
- `OTHER`

Stored action statuses are:

- `PENDING`
- `IN_PROGRESS`
- `COMPLETED`
- `CANCELLED`

`OVERDUE` and `DUE_SOON` are derived display states only. They are not stored as canonical action statuses. An incomplete action is due soon when its due date falls within the next three calendar days.

AI action suggestions identify the recommendation basis:

- `SOURCE_DERIVED`: the supporting evidence explicitly states the action, obligation, response date, submission requirement, or deadline.
- `AI_RECOMMENDED`: the evidence and matter metadata support a conservative internal follow-up action or target date, but the source does not create an explicit deadline.
- `NO_DEADLINE`: no due date or useful target is supported.

AI-recommended target dates use the FAM priority policy:

- `CRITICAL`: 1 business day
- `HIGH`: 2-3 business days
- `MEDIUM`: 5 business days
- `LOW`: 7-10 business days

Gemini may recommend an urgency inside the applicable priority window, but backend logic computes the actual date deterministically using weekday-only business days. The current implementation does not include a holiday calendar.

Authorized Legal/Admin users can:

- manually add an action or deadline
- edit an official action
- start, complete, or cancel an action
- re-analyze linked supporting evidence for action suggestions
- accept an AI suggestion into an official action
- dismiss an AI suggestion

AI action analysis reads only current readable supporting documents linked to the selected Legal Matter, plus matter metadata such as type, priority, and existing actions/suggestions for duplicate avoidance. It creates pending suggestions, not official actions. The prompt must not invent legal obligations, statutory deadlines, court dates, hearings, sanctions, penalties, responsibility, liability, or conclusions.

Accepted suggestions become official `legal_matter_action` records with `source = AI` only after Admin/Head review. The reviewer may edit the title, type, description, assignee, and due date before creating the official action. Dismissed suggestions remain non-authoritative and suppress the same unchanged suggestion from immediately returning during re-analysis.

AI suggestions alone do not block resolution or closure. Only official `PENDING` and `IN_PROGRESS` actions are tracked for due soon/overdue behavior and resolution guards.

Legal matters cannot be resolved or closed while official actions remain `PENDING` or `IN_PROGRESS`. The server blocks the transition with:

`Complete or cancel all open legal actions before resolving this matter.`

The Legal Matter details modal shows `Actions & Deadlines` as a collapsed accordion below `Parties Involved` and above assignment/supporting document sections. The AI Matter Summary remains always visible, and Matter Information remains the only expanded accordion by default.

Safe history events include `LEGAL_ACTION_ADDED`, `LEGAL_ACTION_UPDATED`, `LEGAL_ACTION_STARTED`, `LEGAL_ACTION_COMPLETED`, `LEGAL_ACTION_CANCELLED`, `LEGAL_AI_ACTIONS_ANALYZED`, `LEGAL_AI_ACTION_SUGGESTED`, `LEGAL_AI_ACTION_ACCEPTED`, and `LEGAL_AI_ACTION_DISMISSED`. These events log workflow context without storing supporting document contents.
