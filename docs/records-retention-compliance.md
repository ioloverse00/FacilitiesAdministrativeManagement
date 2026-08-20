# Records Retention & Compliance

Phase 7 implements the official retention lifecycle layer for FAM records. It reuses the existing Document Management foundation instead of creating a second repository.

## Scope

- Retention schedules are stored in `retention_schedule`.
- Retention-controlled records are stored in `record`.
- Documents remain in `document` and `document_version`, linked through `record_document`.
- Lifecycle actions are logged in `activity_event`.

## Workflow

1. A record is created by an upstream module or Document Management.
2. If Document Management creates the linked record with a valid active non-permanent schedule and a safe retention start date, the server calculates `scheduled_disposition_date` immediately.
3. Records Retention may assign or update an active retention schedule.
4. The server calculates `scheduled_disposition_date` from `retention_start_date` and the selected schedule.
5. Compliance users review due or overdue records.
6. Authorized users may extend review dates, archive records, place or release legal holds, or mark records disposed.

## Due State Rules

The retention service is the authoritative source for due-state semantics.

- `DUE_FOR_REVIEW`: active record with a scheduled disposition/review date from today through the next 30 days.
- `OVERDUE`: active record with a scheduled disposition/review date before today.
- `ON_HOLD`: active legal hold or persisted `ON_HOLD` record status.
- `PERMANENT`: schedule period unit or disposition action is permanent.
- `ARCHIVED` and `DISPOSED`: terminal retention states and not counted as due.

## Controls

- Disposal is logical only. No files or document versions are physically deleted.
- Document repository lifecycle and retention record lifecycle are intentionally separate. A retention record becoming archived or disposed does not physically delete or automatically archive the document/file.
- Active legal holds block archive and disposition.
- Releasing a hold restores the persisted record status to `ACTIVE`; due, overdue, or permanent state is then represented dynamically from the schedule and dates.
- Permanent schedules do not calculate a disposition date and cannot be extended or disposed.
- Legal hold and disposition actions require a reason.

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
