-- DEVELOPMENT / UAT ONLY
-- DESTRUCTIVE FAM TRANSACTIONAL RESET
-- CONTRACTS + TEMPLATES + RECORDS/RETENTION TRANSACTIONS
-- DO NOT RUN IN PRODUCTION
--
-- Supersession note:
--   For a full clean-slate FAM UAT transactional reset, prefer
--   database/migrations/2026_fam_full_uat_transaction_reset.sql.
--   This script remains available only for the narrower Contract Management,
--   Document Template Management, and Records/Retention reset scope.
--
-- Purpose:
--   Reset Contract Management, Document Template Management, and Records
--   Management / Retention transactional/UAT data. This removes contract-owned
--   documents, template backing documents, and record-owned documents, while
--   preserving unrelated shared module data and reusable retention configuration.
--
-- Physical files:
--   SQL can remove database rows only. Before running this script, export the
--   storage_path values selected by the preflight query near the end of this file.
--   After a successful commit, delete only those files from the configured
--   document storage location. Unrelated shared module files must remain.

START TRANSACTION;

CREATE TEMPORARY TABLE tmp_uat_contract_ids (
  contract_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_contract_numbers (
  contract_number VARCHAR(60) NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_contract_record_ids (
  record_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_reset_record_ids (
  record_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_contract_approval_request_ids (
  approval_request_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_contract_document_ids (
  document_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_contract_document_version_ids (
  document_version_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_record_owned_document_ids (
  document_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_template_ids (
  template_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_template_version_ids (
  template_version_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_reset_document_ids (
  document_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

CREATE TEMPORARY TABLE tmp_uat_reset_document_version_ids (
  document_version_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT INTO tmp_uat_contract_ids (contract_id)
SELECT c.contract_id
FROM contract c
WHERE c.deleted_at IS NULL OR c.deleted_at IS NOT NULL;

INSERT INTO tmp_uat_contract_numbers (contract_number)
SELECT c.contract_number
FROM contract c
JOIN tmp_uat_contract_ids t ON t.contract_id = c.contract_id;

INSERT INTO tmp_uat_contract_record_ids (record_id)
SELECT DISTINCT r.record_id
FROM record r
LEFT JOIN tmp_uat_contract_ids tc ON tc.contract_id = r.source_entity_id
LEFT JOIN tmp_uat_contract_numbers tn ON tn.contract_number = r.source_entity_type
WHERE r.source_module = 'contract_management'
  AND (tc.contract_id IS NOT NULL OR tn.contract_number IS NOT NULL);

INSERT IGNORE INTO tmp_uat_contract_document_ids (document_id)
SELECT DISTINCT rd.document_id
FROM record_document rd
JOIN tmp_uat_contract_record_ids tr ON tr.record_id = rd.record_id;

INSERT IGNORE INTO tmp_uat_contract_document_ids (document_id)
SELECT DISTINCT ccr.uploaded_document_id
FROM contract_client_requirement ccr
JOIN tmp_uat_contract_ids tc ON tc.contract_id = ccr.contract_id
WHERE ccr.uploaded_document_id IS NOT NULL;

INSERT IGNORE INTO tmp_uat_contract_document_ids (document_id)
SELECT DISTINCT cgd.synced_document_id
FROM contract_google_document cgd
JOIN tmp_uat_contract_ids tc ON tc.contract_id = cgd.contract_id
WHERE cgd.synced_document_id IS NOT NULL;

INSERT IGNORE INTO tmp_uat_contract_document_ids (document_id)
SELECT DISTINCT ca.document_id
FROM contract_amendment ca
JOIN tmp_uat_contract_ids tc ON tc.contract_id = ca.contract_id
WHERE ca.document_id IS NOT NULL;

INSERT IGNORE INTO tmp_uat_contract_document_ids (document_id)
SELECT DISTINCT co.completion_document_id
FROM contract_obligation co
JOIN tmp_uat_contract_ids tc ON tc.contract_id = co.contract_id
WHERE co.completion_document_id IS NOT NULL;

INSERT INTO tmp_uat_reset_record_ids (record_id)
SELECT r.record_id
FROM record r
WHERE r.deleted_at IS NULL OR r.deleted_at IS NOT NULL;

INSERT INTO tmp_uat_contract_document_version_ids (document_version_id)
SELECT DISTINCT dv.document_version_id
FROM document_version dv
JOIN tmp_uat_contract_document_ids td ON td.document_id = dv.document_id;

INSERT INTO tmp_uat_template_ids (template_id)
SELECT dt.template_id
FROM document_template dt
WHERE dt.deleted_at IS NULL OR dt.deleted_at IS NOT NULL;

INSERT INTO tmp_uat_template_version_ids (template_version_id)
SELECT dtv.template_version_id
FROM document_template_version dtv
JOIN tmp_uat_template_ids tt ON tt.template_id = dtv.template_id;

INSERT IGNORE INTO tmp_uat_reset_document_ids (document_id)
SELECT document_id
FROM tmp_uat_contract_document_ids;

INSERT IGNORE INTO tmp_uat_reset_document_ids (document_id)
SELECT DISTINCT dtv.document_id
FROM document_template_version dtv
JOIN tmp_uat_template_version_ids tv ON tv.template_version_id = dtv.template_version_id;

INSERT IGNORE INTO tmp_uat_record_owned_document_ids (document_id)
SELECT DISTINCT rd.document_id
FROM record_document rd
JOIN record r ON r.record_id = rd.record_id
JOIN tmp_uat_reset_record_ids tr ON tr.record_id = r.record_id
WHERE COALESCE(r.source_module, '') NOT IN (
  'asset',
  'contract_management',
  'facility_request',
  'facility_requests',
  'facility_reservation',
  'legal_management',
  'maintenance_work_order',
  'procurement_request',
  'room_reservations'
);

INSERT IGNORE INTO tmp_uat_reset_document_ids (document_id)
SELECT document_id
FROM tmp_uat_record_owned_document_ids;

INSERT IGNORE INTO tmp_uat_reset_document_version_ids (document_version_id)
SELECT document_version_id
FROM tmp_uat_contract_document_version_ids;

INSERT IGNORE INTO tmp_uat_reset_document_version_ids (document_version_id)
SELECT DISTINCT dv.document_version_id
FROM document_version dv
JOIN tmp_uat_reset_document_ids td ON td.document_id = dv.document_id;

INSERT IGNORE INTO tmp_uat_reset_document_version_ids (document_version_id)
SELECT DISTINCT dtv.document_version_id
FROM document_template_version dtv
JOIN tmp_uat_template_version_ids tv ON tv.template_version_id = dtv.template_version_id;

INSERT IGNORE INTO tmp_uat_contract_record_ids (record_id)
SELECT DISTINCT rd.record_id
FROM record_document rd
JOIN tmp_uat_reset_document_ids td ON td.document_id = rd.document_id;

INSERT IGNORE INTO tmp_uat_reset_record_ids (record_id)
SELECT record_id
FROM tmp_uat_contract_record_ids;

INSERT IGNORE INTO tmp_uat_contract_approval_request_ids (approval_request_id)
SELECT DISTINCT ar.approval_request_id
FROM approval_request ar
JOIN tmp_uat_contract_ids tc ON tc.contract_id = ar.entity_id
WHERE ar.module_code = 'contract_management';

INSERT IGNORE INTO tmp_uat_contract_approval_request_ids (approval_request_id)
SELECT DISTINCT cgd.approval_request_id
FROM contract_amendment cgd
JOIN tmp_uat_contract_ids tc ON tc.contract_id = cgd.contract_id
WHERE cgd.approval_request_id IS NOT NULL;

-- Generic activity/workflow rows scoped to reset contracts, templates, and records.
DELETE FROM notification
WHERE module_code = 'contract_management'
   OR module_code = 'document_templates'
   OR module_code IN ('records', 'records_management', 'retention')
   OR (related_entity_type = 'contract' AND related_entity_id IN (SELECT contract_id FROM tmp_uat_contract_ids))
   OR (related_entity_type = 'document_template' AND related_entity_id IN (SELECT template_id FROM tmp_uat_template_ids))
   OR (related_entity_type = 'record' AND related_entity_id IN (SELECT record_id FROM tmp_uat_reset_record_ids));

DELETE FROM integration_outbox
WHERE source_module = 'contract_management'
   OR source_module = 'document_templates'
   OR source_module IN ('records', 'records_management', 'retention')
   OR (entity_type = 'contract' AND entity_id IN (SELECT contract_id FROM tmp_uat_contract_ids))
   OR (entity_type = 'document_template' AND entity_id IN (SELECT template_id FROM tmp_uat_template_ids))
   OR (entity_type = 'record' AND entity_id IN (SELECT record_id FROM tmp_uat_reset_record_ids));

DELETE FROM ai_recommendation
WHERE module_code = 'contract_management'
   OR module_code = 'document_templates'
   OR module_code IN ('records', 'records_management', 'retention')
   OR (entity_type = 'contract' AND entity_id IN (SELECT contract_id FROM tmp_uat_contract_ids))
   OR (entity_type = 'document_template' AND entity_id IN (SELECT template_id FROM tmp_uat_template_ids))
   OR (entity_type = 'record' AND entity_id IN (SELECT record_id FROM tmp_uat_reset_record_ids));

DELETE FROM activity_event
WHERE module_code = 'contract_management'
   OR module_code IN ('records', 'records_management', 'retention')
   OR (module_code = 'documents' AND entity_type = 'document_template' AND entity_id IN (SELECT template_id FROM tmp_uat_template_ids))
   OR (module_code = 'documents' AND entity_type = 'document' AND entity_id IN (SELECT document_id FROM tmp_uat_reset_document_ids))
   OR (module_code = 'documents' AND entity_type = 'document_version' AND entity_id IN (SELECT document_version_id FROM tmp_uat_reset_document_version_ids))
   OR (entity_type = 'contract' AND entity_id IN (SELECT contract_id FROM tmp_uat_contract_ids))
   OR (entity_type = 'document_template' AND entity_id IN (SELECT template_id FROM tmp_uat_template_ids))
   OR (entity_type = 'document' AND entity_id IN (SELECT document_id FROM tmp_uat_reset_document_ids))
   OR (entity_type = 'document_version' AND entity_id IN (SELECT document_version_id FROM tmp_uat_reset_document_version_ids))
   OR (entity_type = 'record' AND entity_id IN (SELECT record_id FROM tmp_uat_reset_record_ids));

DELETE FROM audit_log
WHERE module_code = 'contract_management'
   OR module_code = 'document_templates'
   OR module_code IN ('records', 'records_management', 'retention')
   OR (entity_type = 'contract' AND entity_id IN (SELECT contract_id FROM tmp_uat_contract_ids))
   OR (entity_type = 'document_template' AND entity_id IN (SELECT template_id FROM tmp_uat_template_ids))
   OR (entity_type = 'record' AND entity_id IN (SELECT record_id FROM tmp_uat_reset_record_ids));

DELETE FROM workflow_task
WHERE module_code = 'contract_management'
   OR module_code IN ('records', 'records_management', 'retention')
   OR (entity_type = 'contract' AND entity_id IN (SELECT contract_id FROM tmp_uat_contract_ids))
   OR (entity_type = 'record' AND entity_id IN (SELECT record_id FROM tmp_uat_reset_record_ids))
   OR approval_request_id IN (SELECT approval_request_id FROM tmp_uat_contract_approval_request_ids)
   OR approval_step_id IN (
      SELECT approval_step_id
      FROM approval_step
      WHERE approval_request_id IN (SELECT approval_request_id FROM tmp_uat_contract_approval_request_ids)
   );

DELETE FROM approval_step
WHERE approval_request_id IN (SELECT approval_request_id FROM tmp_uat_contract_approval_request_ids);

DELETE FROM approval_request
WHERE approval_request_id IN (SELECT approval_request_id FROM tmp_uat_contract_approval_request_ids);

-- Remove references from preserved non-reset rows before document deletes.
UPDATE legal_matter
SET contract_id = NULL
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

UPDATE legal_matter_action
SET source_document_id = NULL,
    source_document_version_id = NULL
WHERE source_document_id IN (SELECT document_id FROM tmp_uat_reset_document_ids)
   OR source_document_version_id IN (SELECT document_version_id FROM tmp_uat_reset_document_version_ids);

UPDATE legal_matter_action_suggestion
SET source_document_id = NULL,
    source_document_version_id = NULL
WHERE source_document_id IN (SELECT document_id FROM tmp_uat_reset_document_ids)
   OR source_document_version_id IN (SELECT document_version_id FROM tmp_uat_reset_document_version_ids);

UPDATE legal_matter_party
SET source_document_id = NULL
WHERE source_document_id IN (SELECT document_id FROM tmp_uat_reset_document_ids);

UPDATE legal_matter_party_suggestion
SET source_document_id = NULL
WHERE source_document_id IN (SELECT document_id FROM tmp_uat_reset_document_ids);

DELETE FROM record_disposition_recommendation
WHERE record_id IN (SELECT record_id FROM tmp_uat_reset_record_ids);

DELETE FROM record_document
WHERE record_id IN (SELECT record_id FROM tmp_uat_reset_record_ids)
   OR document_id IN (SELECT document_id FROM tmp_uat_reset_document_ids);

DELETE FROM contract_renewal_event
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids)
   OR amendment_id IN (
      SELECT contract_amendment_id
      FROM contract_amendment
      WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids)
   );

DELETE FROM contract_amendment
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

DELETE FROM contract_obligation
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

DELETE FROM contract_party
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

DELETE FROM contract_template_value
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

DELETE FROM contract_client_requirement
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

DELETE FROM contract_google_document
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

UPDATE document_template
SET current_approved_version_id = NULL
WHERE template_id IN (SELECT template_id FROM tmp_uat_template_ids);

DELETE FROM contract_history
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

UPDATE contract
SET renewed_from_contract_id = NULL
WHERE renewed_from_contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

DELETE FROM contract
WHERE contract_id IN (SELECT contract_id FROM tmp_uat_contract_ids);

DELETE FROM document_template_version_field
WHERE template_version_id IN (SELECT template_version_id FROM tmp_uat_template_version_ids);

DELETE FROM document_template_version
WHERE template_version_id IN (SELECT template_version_id FROM tmp_uat_template_version_ids);

DELETE FROM document_template
WHERE template_id IN (SELECT template_id FROM tmp_uat_template_ids);

DELETE FROM record
WHERE record_id IN (SELECT record_id FROM tmp_uat_reset_record_ids);

DELETE FROM document_version
WHERE document_version_id IN (SELECT document_version_id FROM tmp_uat_reset_document_version_ids);

DELETE FROM document
WHERE document_id IN (SELECT document_id FROM tmp_uat_reset_document_ids);

COMMIT;

-- AUTO_INCREMENT cleanup. These ALTER TABLE statements implicitly commit in MySQL,
-- so they intentionally run after the transactional delete has completed.
SET @sql := IF((SELECT COUNT(*) FROM contract) = 0, 'ALTER TABLE contract AUTO_INCREMENT = 1', 'SELECT ''contract not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_history) = 0, 'ALTER TABLE contract_history AUTO_INCREMENT = 1', 'SELECT ''contract_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_party) = 0, 'ALTER TABLE contract_party AUTO_INCREMENT = 1', 'SELECT ''contract_party not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_obligation) = 0, 'ALTER TABLE contract_obligation AUTO_INCREMENT = 1', 'SELECT ''contract_obligation not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_amendment) = 0, 'ALTER TABLE contract_amendment AUTO_INCREMENT = 1', 'SELECT ''contract_amendment not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_renewal_event) = 0, 'ALTER TABLE contract_renewal_event AUTO_INCREMENT = 1', 'SELECT ''contract_renewal_event not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_client_requirement) = 0, 'ALTER TABLE contract_client_requirement AUTO_INCREMENT = 1', 'SELECT ''contract_client_requirement not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_template_value) = 0, 'ALTER TABLE contract_template_value AUTO_INCREMENT = 1', 'SELECT ''contract_template_value not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM contract_google_document) = 0, 'ALTER TABLE contract_google_document AUTO_INCREMENT = 1', 'SELECT ''contract_google_document not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM document_template) = 0, 'ALTER TABLE document_template AUTO_INCREMENT = 1', 'SELECT ''document_template not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM document_template_version) = 0, 'ALTER TABLE document_template_version AUTO_INCREMENT = 1', 'SELECT ''document_template_version not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM document_template_version_field) = 0, 'ALTER TABLE document_template_version_field AUTO_INCREMENT = 1', 'SELECT ''document_template_version_field not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Reset shared/generic tables only when fully empty after this reset.
SET @sql := IF((SELECT COUNT(*) FROM approval_request) = 0, 'ALTER TABLE approval_request AUTO_INCREMENT = 1', 'SELECT ''approval_request not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM approval_step) = 0, 'ALTER TABLE approval_step AUTO_INCREMENT = 1', 'SELECT ''approval_step not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM workflow_task) = 0, 'ALTER TABLE workflow_task AUTO_INCREMENT = 1', 'SELECT ''workflow_task not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM record) = 0, 'ALTER TABLE record AUTO_INCREMENT = 1', 'SELECT ''record not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM record_document) = 0, 'ALTER TABLE record_document AUTO_INCREMENT = 1', 'SELECT ''record_document not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM record_disposition_recommendation) = 0, 'ALTER TABLE record_disposition_recommendation AUTO_INCREMENT = 1', 'SELECT ''record_disposition_recommendation not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM document) = 0, 'ALTER TABLE document AUTO_INCREMENT = 1', 'SELECT ''document not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM document_version) = 0, 'ALTER TABLE document_version AUTO_INCREMENT = 1', 'SELECT ''document_version not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM activity_event) = 0, 'ALTER TABLE activity_event AUTO_INCREMENT = 1', 'SELECT ''activity_event not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM audit_log) = 0, 'ALTER TABLE audit_log AUTO_INCREMENT = 1', 'SELECT ''audit_log not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM notification) = 0, 'ALTER TABLE notification AUTO_INCREMENT = 1', 'SELECT ''notification not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM integration_outbox) = 0, 'ALTER TABLE integration_outbox AUTO_INCREMENT = 1', 'SELECT ''integration_outbox not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM ai_recommendation) = 0, 'ALTER TABLE ai_recommendation AUTO_INCREMENT = 1', 'SELECT ''ai_recommendation not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional preflight query to run before this reset:
-- FILES SAFE TO DELETE AFTER RESET
-- These rows cover contract-generated, template backing, and record-owned files.
-- The ownership_scope column explains why each file is included.
-- Any unrelated/shared module file not returned by this query must remain.
-- WITH contract_ids AS (
--   SELECT contract_id, contract_number FROM contract
-- ),
-- template_version_ids AS (
--   SELECT template_version_id, document_id, document_version_id
--   FROM document_template_version
-- ),
-- reset_record_ids AS (
--   SELECT record_id
--   FROM record
-- ),
-- contract_record_ids AS (
--   SELECT DISTINCT r.record_id
--   FROM record r
--   LEFT JOIN contract_ids c_id ON c_id.contract_id = r.source_entity_id
--   LEFT JOIN contract_ids c_no ON c_no.contract_number = r.source_entity_type
--   WHERE r.source_module = 'contract_management'
--     AND (c_id.contract_id IS NOT NULL OR c_no.contract_id IS NOT NULL)
-- ),
-- contract_document_ids AS (
--   SELECT DISTINCT rd.document_id
--   FROM record_document rd
--   JOIN contract_record_ids cr ON cr.record_id = rd.record_id
--   UNION
--   SELECT DISTINCT ccr.uploaded_document_id
--   FROM contract_client_requirement ccr
--   JOIN contract_ids c ON c.contract_id = ccr.contract_id
--   WHERE ccr.uploaded_document_id IS NOT NULL
--   UNION
--   SELECT DISTINCT cgd.synced_document_id
--   FROM contract_google_document cgd
--   JOIN contract_ids c ON c.contract_id = cgd.contract_id
--   WHERE cgd.synced_document_id IS NOT NULL
--   UNION
--   SELECT DISTINCT ca.document_id
--   FROM contract_amendment ca
--   JOIN contract_ids c ON c.contract_id = ca.contract_id
--   WHERE ca.document_id IS NOT NULL
--   UNION
--   SELECT DISTINCT co.completion_document_id
--   FROM contract_obligation co
--   JOIN contract_ids c ON c.contract_id = co.contract_id
--   WHERE co.completion_document_id IS NOT NULL
-- ),
-- template_document_ids AS (
--   SELECT DISTINCT tv.document_id
--   FROM template_version_ids tv
-- ),
-- record_document_ids AS (
--   SELECT DISTINCT rd.document_id
--   FROM record_document rd
--   JOIN record r ON r.record_id = rd.record_id
--   JOIN reset_record_ids rr ON rr.record_id = r.record_id
--   WHERE COALESCE(r.source_module, '') NOT IN (
--     'asset',
--     'contract_management',
--     'facility_request',
--     'facility_requests',
--     'facility_reservation',
--     'legal_management',
--     'maintenance_work_order',
--     'procurement_request',
--     'room_reservations'
--   )
-- ),
-- scoped_documents AS (
--   SELECT document_id, 'contract' AS ownership_scope FROM contract_document_ids
--   UNION
--   SELECT document_id, 'template' AS ownership_scope FROM template_document_ids
--   UNION
--   SELECT document_id, 'record' AS ownership_scope FROM record_document_ids
-- )
-- SELECT sd.ownership_scope, dv.document_id, dv.document_version_id, dv.storage_path
-- FROM document_version dv
-- JOIN scoped_documents sd ON sd.document_id = dv.document_id
-- UNION
-- SELECT 'template_version' AS ownership_scope, dv.document_id, dv.document_version_id, dv.storage_path
-- FROM document_version dv
-- JOIN template_version_ids tv ON tv.document_version_id = dv.document_version_id
-- ORDER BY ownership_scope, document_id, document_version_id;

-- Post-run smoke checks:
-- SELECT 'contract' AS table_name, COUNT(*) AS remaining_rows FROM contract
-- UNION ALL SELECT 'contract_history', COUNT(*) FROM contract_history
-- UNION ALL SELECT 'contract_client_requirement', COUNT(*) FROM contract_client_requirement
-- UNION ALL SELECT 'contract_google_document', COUNT(*) FROM contract_google_document
-- UNION ALL SELECT 'contract_template_value', COUNT(*) FROM contract_template_value
-- UNION ALL SELECT 'record', COUNT(*) FROM record
-- UNION ALL SELECT 'record_document', COUNT(*) FROM record_document
-- UNION ALL SELECT 'record_disposition_recommendation', COUNT(*) FROM record_disposition_recommendation
-- UNION ALL SELECT 'record_retention_transactions', COUNT(*) FROM record WHERE retention_trigger_state IS NOT NULL OR scheduled_disposition_date IS NOT NULL OR legal_hold_status <> 'NONE'
-- UNION ALL SELECT 'retention_schedule_preserved', COUNT(*) FROM retention_schedule
-- UNION ALL SELECT 'document_template', COUNT(*) FROM document_template
-- UNION ALL SELECT 'document_template_version', COUNT(*) FROM document_template_version
-- UNION ALL SELECT 'document_template_merge_field_preserved', COUNT(*) FROM document_template_merge_field
-- UNION ALL SELECT 'document_template_version_field', COUNT(*) FROM document_template_version_field
-- UNION ALL SELECT 'users_preserved', COUNT(*) FROM user_account
-- UNION ALL SELECT 'roles_preserved', COUNT(*) FROM role
-- UNION ALL SELECT 'permissions_preserved', COUNT(*) FROM permission
-- UNION ALL SELECT 'google_account_connection_preserved', COUNT(*) FROM google_account_connection
-- UNION ALL SELECT 'unrelated_documents_remaining', COUNT(*) FROM document;
