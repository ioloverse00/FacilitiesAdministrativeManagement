# Records Retention & Compliance

Phase 7 implements the official retention lifecycle layer for FAM records. It reuses the existing Document Management foundation instead of creating a second repository.

## Scope

- Retention schedules are stored in `retention_schedule`.
- Retention schedules are predefined reference/policy configuration. The normal Records Retention transaction page does not expose schedule creation.
- Retention-controlled records are stored in `record`.
- Documents remain in `document` and `document_version`, linked through `record_document`.
- Lifecycle actions are logged in `activity_event`.

## Workflow

1. A record is created by an upstream module or Document Management.
2. The assigned schedule supplies a controlled `retention_trigger_basis`.
3. Records Retention may assign or update an active retention schedule.
4. The server resolves the authoritative trigger date, then calculates `policy_eligibility_date = retention_trigger_date + retention period`.
5. `scheduled_disposition_date` remains the effective operational review date for compatibility. It mirrors policy eligibility unless an administrative review override exists.
6. Compliance users review due or overdue records.
7. Authorized users may extend review dates, archive records, place or release legal holds, or mark records disposed.

Final disposition is governed by the retention schedule's `disposition_action`. The schedule is the authoritative policy boundary: `ARCHIVE` permits Archive only, `DISPOSE` permits Dispose only, `REVIEW_THEN_DISPOSE` permits Dispose only after an official review marker exists, and permanent/unsupported rules do not expose destructive final actions. The server enforces this boundary independently of the UI, AI recommendation, or human recommendation review decision.

## Trigger Basis

Retention schedules use a controlled trigger basis instead of arbitrary free text:

- `RECORD_CLOSURE`
- `WORK_COMPLETION`
- `ASSET_DISPOSAL`
- `FINAL_PAYMENT`
- `CONTRACT_EXPIRATION`
- `DOCUMENT_DATE`
- `CREATION_DATE`
- `MANUAL_TRIGGER`

The configured demo schedules map as follows: General Administrative Records to record closure, Maintenance Records to work completion, Asset Records to asset disposal, Procurement Records to final payment, and Contracts and Agreements to contract expiration.

If the configured trigger date cannot be resolved from the linked source record, the record enters `WAITING_FOR_TRIGGER`. The system does not substitute document date, upload date, or today unless the schedule explicitly uses `DOCUMENT_DATE` or `CREATION_DATE`.

For `CONTRACT_EXPIRATION`, the resolver uses authoritative structured sources only: a linked contract `end_date`, or a linked document `expiration_date`. AI summaries are not parsed for trigger dates. Legal contract evidence must first go through structured metadata extraction and human confirmation in the Legal/Document workflow before `document.expiration_date` can drive deterministic trigger resolution.

Legal Management is treated as a source module, not as a retention category. Legal-originated records use the authoritative Legal Matter type to choose the schedule:

- `PROPERTY_DAMAGE`, `FACILITY_INCIDENT`, `VISITOR_INCIDENT`, `COMPLAINT`, `CLAIM`, `COMPLIANCE`, and `OTHER` use General Administrative Records (`RET-ADM-005`) with `RECORD_CLOSURE`.
- `CONTRACT_RELATED` uses Contracts and Agreements (`RET-CON-010`) with `CONTRACT_EXPIRATION`.

`COMPLIANCE` currently uses the General Administrative Records schedule because no compliance-specific active retention schedule exists in the current configuration. This mapping is deterministic backend policy and is not AI-classified.

Manual Trigger Date is only valid for schedules whose trigger basis is `MANUAL_TRIGGER`. Business-event schedules such as contract expiration, record closure, work completion, asset disposal, and final payment must be resolved by the backend from authoritative source data. A user-supplied manual date for those schedules is rejected.

`policy_eligibility_date` is the immutable policy date calculated from the resolved trigger. `administrative_review_date_override` is used only for authorized review postponement. The effective review date is the override when present, otherwise policy eligibility.

The details UI displays the deterministic policy state in the Retention Schedule section. Unresolved records show Trigger Date as not available, Policy Eligibility Date as not calculated, and Policy Status as waiting for retention trigger.

## Due State Rules

The retention service is the authoritative source for due-state semantics.

- `DUE_FOR_REVIEW`: active record with a scheduled disposition/review date from today through the next 30 days.
- `OVERDUE`: active record with a scheduled disposition/review date before today.
- `WAITING_FOR_TRIGGER`: active record whose configured business-event trigger date is not yet available.
- `ON_HOLD`: active legal hold or persisted `ON_HOLD` record status.
- `PERMANENT`: schedule period unit or disposition action is permanent.
- `ARCHIVED` and `DISPOSED`: terminal retention states and not counted as due.

## Controls

- Disposal is logical only. No files or document versions are physically deleted.
- Document repository lifecycle and retention record lifecycle are intentionally separate. A retention record becoming archived or disposed does not physically delete or automatically archive the document/file.
- Active legal holds block archive and disposition.
- Missing trigger dates block archive, disposition, and AI disposition analysis.
- Legal Hold remains available even when the trigger date is missing because preservation may be required before the retention countdown is established.
- Releasing a hold restores the persisted record status to `ACTIVE`; due, overdue, or permanent state is then represented dynamically from the schedule and dates.
- Permanent schedules do not calculate a disposition date and cannot be extended or disposed.
- Legal hold and disposition actions require a reason.
- Final Archive/Dispose actions are blocked until the effective review date has been reached. The effective review date is `administrative_review_date_override` when present, otherwise the original `policy_eligibility_date`.
- Schedule correction remains available for non-terminal records without an active hold, but changing a schedule after trigger resolution or review activity requires a correction reason and marks pending AI recommendations stale.
- `ARCHIVED` and `DISPOSED` are terminal retention states. Historical policy, trigger, disposition, and AI recommendation data remain readable for audit, but new AI disposition analysis, recommendation review, schedule correction, review extension, legal hold mutation, and final disposition mutation are blocked unless a future recovery workflow is explicitly designed.

## AI-Assisted Disposition Recommendations

Records Retention includes an advisory disposition recommendation layer for records administrators. Recommendations are staged in `record_disposition_recommendation` and never replace the deterministic retention engine or the human review workflow.

- Supported recommendation actions are `RETAIN`, `ARCHIVE`, `REVIEW`, and `DISPOSE`.
- AI analysis is explicit only. Viewing the queue or details modal does not call Gemini.
- The request is metadata-first: record title, category, status, trigger basis/date, policy eligibility date, effective review date, retention schedule, and hold state. Full document contents are not sent for Phase 1 recommendation generation.
- The deterministic retention engine remains authoritative for trigger resolution, policy eligibility dates, effective review dates, permanent retention, legal hold blocks, and terminal states.
- AI analysis is unavailable while a record is `WAITING_FOR_TRIGGER`.
- Active legal holds block archive/dispose approval regardless of any recommendation.
- Disposition remains logical only. Approving `DISPOSE` marks the retention record disposed through the existing workflow and does not physically delete files or document versions.
- Gemini outages, quota errors, invalid responses, or missing configuration do not block Records Retention. The UI reports AI Recommendation Unavailable and does not persist or display a fake `REVIEW` recommendation.
- Changing the retention schedule, trigger date, policy eligibility date, effective review date, hold state, or official lifecycle state marks pending recommendations stale.

Administrators may approve the recommended action, modify it to another valid action with a reason, or reject it with a reason. The recommendation is decision support only; the reviewed decision is recorded separately from the original recommendation.

Recommendation review cannot expand policy authority. If a schedule permits Archive only, an AI recommendation or reviewer modification cannot authorize Dispose. When an approved recommendation triggers an official Archive or Dispose, the execution path revalidates RBAC, terminal state, legal hold, effective review date, permanent retention, and the schedule's disposition rule.

## Permissions

- `retention.view`
- `retention.manage_schedules`
- `retention.assign`
- `retention.review`
- `retention.extend`
- `retention.archive`
- `retention.dispose`
- `retention.legal_hold`

The Phase 7 migration grants full retention permissions to system/FAM records roles and view access to auditor/facility-manager style roles when those roles exist.
