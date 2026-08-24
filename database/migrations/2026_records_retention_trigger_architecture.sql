-- Records Retention trigger architecture correction.
-- Keeps the legacy scheduled_disposition_date as the effective operational review date.

ALTER TABLE retention_schedule
    ADD COLUMN IF NOT EXISTS retention_trigger_basis varchar(50) NULL AFTER retention_trigger;

ALTER TABLE record
    ADD COLUMN IF NOT EXISTS retention_trigger_basis varchar(50) NULL AFTER retention_start_date,
    ADD COLUMN IF NOT EXISTS retention_trigger_date date NULL AFTER retention_trigger_basis,
    ADD COLUMN IF NOT EXISTS policy_eligibility_date date NULL AFTER retention_trigger_date,
    ADD COLUMN IF NOT EXISTS administrative_review_date_override date NULL AFTER policy_eligibility_date,
    ADD COLUMN IF NOT EXISTS retention_trigger_state varchar(30) NOT NULL DEFAULT 'WAITING_FOR_TRIGGER' AFTER administrative_review_date_override;

UPDATE retention_schedule
SET retention_trigger_basis = CASE schedule_code
    WHEN 'RET-ADM-005' THEN 'RECORD_CLOSURE'
    WHEN 'RET-MNT-007' THEN 'WORK_COMPLETION'
    WHEN 'RET-AST-010' THEN 'ASSET_DISPOSAL'
    WHEN 'RET-PR-007' THEN 'FINAL_PAYMENT'
    WHEN 'RET-CON-010' THEN 'CONTRACT_EXPIRATION'
    ELSE CASE UPPER(REPLACE(TRIM(retention_trigger), ' ', '_'))
        WHEN 'DOCUMENT_DATE' THEN 'DOCUMENT_DATE'
        WHEN 'CREATION_DATE' THEN 'CREATION_DATE'
        WHEN 'MANUAL_TRIGGER' THEN 'MANUAL_TRIGGER'
        ELSE 'MANUAL_TRIGGER'
    END
END
WHERE retention_trigger_basis IS NULL OR retention_trigger_basis = '';

UPDATE record r
JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id
SET r.retention_trigger_basis = COALESCE(NULLIF(rs.retention_trigger_basis, ''), r.retention_trigger_basis),
    r.retention_trigger_date = CASE
        WHEN COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') = 'DOCUMENT_DATE' THEN COALESCE(r.retention_start_date, r.record_date)
        WHEN COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') = 'CREATION_DATE' THEN DATE(r.created_at)
        WHEN COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') = 'MANUAL_TRIGGER' THEN r.retention_start_date
        ELSE NULL
    END,
    r.policy_eligibility_date = CASE
        WHEN UPPER(COALESCE(rs.retention_period_unit, '')) = 'PERMANENT' OR UPPER(COALESCE(rs.disposition_action, '')) = 'PERMANENT' THEN NULL
        WHEN COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') IN ('DOCUMENT_DATE', 'MANUAL_TRIGGER') THEN
            CASE UPPER(COALESCE(rs.retention_period_unit, 'YEAR'))
                WHEN 'DAYS' THEN DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value DAY)
                WHEN 'DAY' THEN DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value DAY)
                WHEN 'MONTHS' THEN DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value MONTH)
                WHEN 'MONTH' THEN DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value MONTH)
                ELSE DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value YEAR)
            END
        WHEN COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') = 'CREATION_DATE' THEN
            CASE UPPER(COALESCE(rs.retention_period_unit, 'YEAR'))
                WHEN 'DAYS' THEN DATE_ADD(DATE(r.created_at), INTERVAL rs.retention_period_value DAY)
                WHEN 'DAY' THEN DATE_ADD(DATE(r.created_at), INTERVAL rs.retention_period_value DAY)
                WHEN 'MONTHS' THEN DATE_ADD(DATE(r.created_at), INTERVAL rs.retention_period_value MONTH)
                WHEN 'MONTH' THEN DATE_ADD(DATE(r.created_at), INTERVAL rs.retention_period_value MONTH)
                ELSE DATE_ADD(DATE(r.created_at), INTERVAL rs.retention_period_value YEAR)
            END
        ELSE NULL
    END,
    r.administrative_review_date_override = CASE
        WHEN r.scheduled_disposition_date IS NOT NULL
             AND COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') IN ('DOCUMENT_DATE', 'CREATION_DATE', 'MANUAL_TRIGGER')
             AND r.scheduled_disposition_date <> CASE
                WHEN UPPER(COALESCE(rs.retention_period_unit, 'YEAR')) IN ('DAYS', 'DAY') THEN DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value DAY)
                WHEN UPPER(COALESCE(rs.retention_period_unit, 'YEAR')) IN ('MONTHS', 'MONTH') THEN DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value MONTH)
                ELSE DATE_ADD(COALESCE(r.retention_start_date, r.record_date), INTERVAL rs.retention_period_value YEAR)
             END
        THEN r.scheduled_disposition_date
        ELSE r.administrative_review_date_override
    END,
    r.retention_trigger_state = CASE
        WHEN UPPER(COALESCE(rs.retention_period_unit, '')) = 'PERMANENT' OR UPPER(COALESCE(rs.disposition_action, '')) = 'PERMANENT' THEN 'RESOLVED'
        WHEN COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') IN ('DOCUMENT_DATE', 'CREATION_DATE', 'MANUAL_TRIGGER') THEN 'RESOLVED'
        ELSE 'WAITING_FOR_TRIGGER'
    END,
    r.scheduled_disposition_date = CASE
        WHEN COALESCE(NULLIF(rs.retention_trigger_basis, ''), '') IN ('DOCUMENT_DATE', 'CREATION_DATE', 'MANUAL_TRIGGER') THEN COALESCE(r.administrative_review_date_override, r.policy_eligibility_date)
        ELSE NULL
    END
WHERE r.deleted_at IS NULL;

UPDATE record r
JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id
JOIN contract c ON (r.source_entity_id = c.contract_id OR r.source_entity_type = c.contract_number) AND c.deleted_at IS NULL
SET r.retention_trigger_date = c.end_date,
    r.policy_eligibility_date = CASE UPPER(COALESCE(rs.retention_period_unit, 'YEAR'))
        WHEN 'DAYS' THEN DATE_ADD(c.end_date, INTERVAL rs.retention_period_value DAY)
        WHEN 'DAY' THEN DATE_ADD(c.end_date, INTERVAL rs.retention_period_value DAY)
        WHEN 'MONTHS' THEN DATE_ADD(c.end_date, INTERVAL rs.retention_period_value MONTH)
        WHEN 'MONTH' THEN DATE_ADD(c.end_date, INTERVAL rs.retention_period_value MONTH)
        ELSE DATE_ADD(c.end_date, INTERVAL rs.retention_period_value YEAR)
    END,
    r.retention_trigger_state = 'RESOLVED'
WHERE r.deleted_at IS NULL AND r.retention_trigger_basis = 'CONTRACT_EXPIRATION' AND c.end_date IS NOT NULL;

UPDATE record r
JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id
JOIN record_document rd ON rd.record_id = r.record_id
JOIN document d ON d.document_id = rd.document_id AND d.deleted_at IS NULL
SET r.retention_trigger_date = d.expiration_date,
    r.policy_eligibility_date = CASE UPPER(COALESCE(rs.retention_period_unit, 'YEAR'))
        WHEN 'DAYS' THEN DATE_ADD(d.expiration_date, INTERVAL rs.retention_period_value DAY)
        WHEN 'DAY' THEN DATE_ADD(d.expiration_date, INTERVAL rs.retention_period_value DAY)
        WHEN 'MONTHS' THEN DATE_ADD(d.expiration_date, INTERVAL rs.retention_period_value MONTH)
        WHEN 'MONTH' THEN DATE_ADD(d.expiration_date, INTERVAL rs.retention_period_value MONTH)
        ELSE DATE_ADD(d.expiration_date, INTERVAL rs.retention_period_value YEAR)
    END,
    r.retention_trigger_state = 'RESOLVED'
WHERE r.deleted_at IS NULL AND r.retention_trigger_basis = 'CONTRACT_EXPIRATION' AND r.retention_trigger_date IS NULL AND d.expiration_date IS NOT NULL;

UPDATE record r
JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id
JOIN maintenance_work_order m ON (r.source_entity_id = m.maintenance_work_order_id OR r.source_entity_type = m.work_order_number) AND m.deleted_at IS NULL
SET r.retention_trigger_date = DATE(COALESCE(m.actual_end_at, m.verified_at)),
    r.policy_eligibility_date = CASE UPPER(COALESCE(rs.retention_period_unit, 'YEAR'))
        WHEN 'DAYS' THEN DATE_ADD(DATE(COALESCE(m.actual_end_at, m.verified_at)), INTERVAL rs.retention_period_value DAY)
        WHEN 'DAY' THEN DATE_ADD(DATE(COALESCE(m.actual_end_at, m.verified_at)), INTERVAL rs.retention_period_value DAY)
        WHEN 'MONTHS' THEN DATE_ADD(DATE(COALESCE(m.actual_end_at, m.verified_at)), INTERVAL rs.retention_period_value MONTH)
        WHEN 'MONTH' THEN DATE_ADD(DATE(COALESCE(m.actual_end_at, m.verified_at)), INTERVAL rs.retention_period_value MONTH)
        ELSE DATE_ADD(DATE(COALESCE(m.actual_end_at, m.verified_at)), INTERVAL rs.retention_period_value YEAR)
    END,
    r.retention_trigger_state = 'RESOLVED'
WHERE r.deleted_at IS NULL AND r.retention_trigger_basis = 'WORK_COMPLETION' AND COALESCE(m.actual_end_at, m.verified_at) IS NOT NULL;

UPDATE record r
JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id
JOIN asset a ON (r.source_entity_id = a.asset_id OR r.source_entity_type = a.asset_code) AND a.deleted_at IS NULL
SET r.retention_trigger_date = a.retirement_date,
    r.policy_eligibility_date = CASE UPPER(COALESCE(rs.retention_period_unit, 'YEAR'))
        WHEN 'DAYS' THEN DATE_ADD(a.retirement_date, INTERVAL rs.retention_period_value DAY)
        WHEN 'DAY' THEN DATE_ADD(a.retirement_date, INTERVAL rs.retention_period_value DAY)
        WHEN 'MONTHS' THEN DATE_ADD(a.retirement_date, INTERVAL rs.retention_period_value MONTH)
        WHEN 'MONTH' THEN DATE_ADD(a.retirement_date, INTERVAL rs.retention_period_value MONTH)
        ELSE DATE_ADD(a.retirement_date, INTERVAL rs.retention_period_value YEAR)
    END,
    r.retention_trigger_state = 'RESOLVED'
WHERE r.deleted_at IS NULL AND r.retention_trigger_basis = 'ASSET_DISPOSAL' AND a.retirement_date IS NOT NULL;

UPDATE record r
JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id
JOIN procurement_request p ON (r.source_entity_id = p.procurement_request_id OR r.source_entity_type = p.request_number) AND p.deleted_at IS NULL
SET r.retention_trigger_date = DATE(p.completed_at),
    r.policy_eligibility_date = CASE UPPER(COALESCE(rs.retention_period_unit, 'YEAR'))
        WHEN 'DAYS' THEN DATE_ADD(DATE(p.completed_at), INTERVAL rs.retention_period_value DAY)
        WHEN 'DAY' THEN DATE_ADD(DATE(p.completed_at), INTERVAL rs.retention_period_value DAY)
        WHEN 'MONTHS' THEN DATE_ADD(DATE(p.completed_at), INTERVAL rs.retention_period_value MONTH)
        WHEN 'MONTH' THEN DATE_ADD(DATE(p.completed_at), INTERVAL rs.retention_period_value MONTH)
        ELSE DATE_ADD(DATE(p.completed_at), INTERVAL rs.retention_period_value YEAR)
    END,
    r.retention_trigger_state = 'RESOLVED'
WHERE r.deleted_at IS NULL AND r.retention_trigger_basis = 'FINAL_PAYMENT' AND p.completed_at IS NOT NULL;

UPDATE record
SET scheduled_disposition_date = COALESCE(administrative_review_date_override, policy_eligibility_date),
    retention_start_date = COALESCE(retention_trigger_date, retention_start_date)
WHERE deleted_at IS NULL AND retention_trigger_state = 'RESOLVED';
