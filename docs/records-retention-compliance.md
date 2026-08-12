# Records Retention & Compliance

Phase 7 implements the official retention lifecycle layer for FAM records. It reuses the existing Document Management foundation instead of creating a second repository.

## Scope

- Retention schedules are stored in `retention_schedule`.
- Retention-controlled records are stored in `record`.
- Documents remain in `document` and `document_version`, linked through `record_document`.
- Lifecycle actions are logged in `activity_event`.

## Workflow

1. A record is created by an upstream module or Document Management.
2. Records Retention assigns or updates an active retention schedule.
3. The server calculates `scheduled_disposition_date` from `retention_start_date` and the selected schedule.
4. Compliance users review due or overdue records.
5. Authorized users may extend review dates, archive records, place or release legal holds, or mark records disposed.

## Controls

- Disposal is logical only. No files or document versions are physically deleted.
- Active legal holds block disposition.
- Permanent schedules do not calculate a disposition date and cannot be disposed.
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
