-- Phase 7: Records Retention & Compliance lifecycle controls.
-- Focused extension over existing record / retention_schedule foundations.

ALTER TABLE record
    ADD COLUMN IF NOT EXISTS legal_hold_status varchar(30) NOT NULL DEFAULT 'NONE' AFTER record_status,
    ADD COLUMN IF NOT EXISTS legal_hold_reason text NULL AFTER legal_hold_status,
    ADD COLUMN IF NOT EXISTS legal_hold_placed_by_user_id bigint(20) unsigned NULL AFTER legal_hold_reason,
    ADD COLUMN IF NOT EXISTS legal_hold_placed_at datetime NULL AFTER legal_hold_placed_by_user_id,
    ADD COLUMN IF NOT EXISTS legal_hold_released_by_user_id bigint(20) unsigned NULL AFTER legal_hold_placed_at,
    ADD COLUMN IF NOT EXISTS legal_hold_released_at datetime NULL AFTER legal_hold_released_by_user_id,
    ADD COLUMN IF NOT EXISTS legal_hold_release_reason text NULL AFTER legal_hold_released_at,
    ADD COLUMN IF NOT EXISTS last_reviewed_at datetime NULL AFTER scheduled_disposition_date,
    ADD COLUMN IF NOT EXISTS disposition_reason text NULL AFTER last_reviewed_at,
    ADD COLUMN IF NOT EXISTS dispositioned_by_user_id bigint(20) unsigned NULL AFTER disposition_reason,
    ADD COLUMN IF NOT EXISTS dispositioned_at datetime NULL AFTER dispositioned_by_user_id;

INSERT INTO permission (permission_code, permission_name, module_code, description) VALUES
('retention.view','View Records Retention','retention','View retention records, schedules, and compliance queues.'),
('retention.manage_schedules','Manage Retention Schedules','retention','Create and update retention schedules.'),
('retention.assign','Assign Retention','retention','Assign retention schedules and retention start dates to records.'),
('retention.review','Review Retention Records','retention','Review records due for retention action.'),
('retention.extend','Extend Retention','retention','Extend retention review/disposition dates.'),
('retention.archive','Archive Retention Records','retention','Logically archive records under retention control.'),
('retention.dispose','Dispose Retention Records','retention','Mark records disposed through controlled logical disposition.'),
('retention.legal_hold','Manage Legal Hold','retention','Place and release retention legal holds.')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name), module_code=VALUES(module_code), description=VALUES(description);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code LIKE 'retention.%'
WHERE r.role_code IN ('SYSTEM_ADMIN','FAM_ADMIN','RECORDS_OFFICER');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code = 'retention.view'
WHERE r.role_code IN ('AUDITOR','FACILITY_MANAGER');
