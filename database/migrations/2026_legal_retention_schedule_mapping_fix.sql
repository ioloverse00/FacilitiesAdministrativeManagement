-- Correct Legal Management retention schedule mapping by authoritative matter type.
-- LEGAL_MANAGEMENT is a source module, not a retention category.

UPDATE record_disposition_recommendation rdr
INNER JOIN record r ON r.record_id = rdr.record_id AND r.deleted_at IS NULL
INNER JOIN legal_matter lm
    ON r.source_entity_type COLLATE utf8mb4_unicode_ci = lm.matter_number COLLATE utf8mb4_unicode_ci
    AND lm.deleted_at IS NULL
INNER JOIN retention_schedule current_rs ON current_rs.retention_schedule_id = r.retention_schedule_id
INNER JOIN retention_schedule target_rs
    ON target_rs.schedule_code = CASE
        WHEN lm.matter_type = 'CONTRACT_RELATED' THEN 'RET-CON-010'
        ELSE 'RET-ADM-005'
    END
    AND target_rs.status = 'ACTIVE'
SET rdr.status = 'STALE',
    rdr.review_reason = COALESCE(rdr.review_reason, 'Legal matter retention schedule mapping corrected.'),
    rdr.updated_at = NOW()
WHERE r.source_module = 'legal_management'
  AND rdr.status = 'PENDING'
  AND current_rs.retention_schedule_id <> target_rs.retention_schedule_id;

UPDATE record r
INNER JOIN legal_matter lm
    ON r.source_entity_type COLLATE utf8mb4_unicode_ci = lm.matter_number COLLATE utf8mb4_unicode_ci
    AND lm.deleted_at IS NULL
INNER JOIN retention_schedule target_rs
    ON target_rs.schedule_code = CASE
        WHEN lm.matter_type = 'CONTRACT_RELATED' THEN 'RET-CON-010'
        ELSE 'RET-ADM-005'
    END
    AND target_rs.status = 'ACTIVE'
SET r.retention_schedule_id = target_rs.retention_schedule_id,
    r.retention_trigger_basis = target_rs.retention_trigger_basis,
    r.retention_trigger_date = CASE
        WHEN target_rs.retention_trigger_basis = 'RECORD_CLOSURE' THEN DATE(lm.closed_at)
        ELSE NULL
    END,
    r.retention_start_date = CASE
        WHEN target_rs.retention_trigger_basis = 'RECORD_CLOSURE' AND lm.closed_at IS NOT NULL THEN DATE(lm.closed_at)
        ELSE r.retention_start_date
    END,
    r.policy_eligibility_date = CASE
        WHEN target_rs.retention_trigger_basis = 'RECORD_CLOSURE' AND lm.closed_at IS NOT NULL THEN
            CASE UPPER(COALESCE(target_rs.retention_period_unit, 'YEAR'))
                WHEN 'DAYS' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value DAY)
                WHEN 'DAY' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value DAY)
                WHEN 'MONTHS' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value MONTH)
                WHEN 'MONTH' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value MONTH)
                ELSE DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value YEAR)
            END
        ELSE NULL
    END,
    r.scheduled_disposition_date = CASE
        WHEN target_rs.retention_trigger_basis = 'RECORD_CLOSURE' AND lm.closed_at IS NOT NULL THEN
            COALESCE(
                r.administrative_review_date_override,
                CASE UPPER(COALESCE(target_rs.retention_period_unit, 'YEAR'))
                    WHEN 'DAYS' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value DAY)
                    WHEN 'DAY' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value DAY)
                    WHEN 'MONTHS' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value MONTH)
                    WHEN 'MONTH' THEN DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value MONTH)
                    ELSE DATE_ADD(DATE(lm.closed_at), INTERVAL target_rs.retention_period_value YEAR)
                END
            )
        ELSE NULL
    END,
    r.retention_trigger_state = CASE
        WHEN target_rs.retention_trigger_basis = 'RECORD_CLOSURE' AND lm.closed_at IS NOT NULL THEN 'RESOLVED'
        ELSE 'WAITING_FOR_TRIGGER'
    END,
    r.updated_at = NOW()
WHERE r.deleted_at IS NULL
  AND r.source_module = 'legal_management';
