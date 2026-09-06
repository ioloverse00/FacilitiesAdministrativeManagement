-- DEVELOPMENT / UAT ONLY
-- FULL FAM TRANSACTIONAL + USER CONTENT RESET
-- DO NOT RUN IN PRODUCTION
--
-- Purpose:
--   Return the Facilities Administrative Management UAT database to a fresh
--   operational baseline by removing user-generated/runtime transactional data
--   while preserving users, RBAC, system configuration, reusable reference data,
--   external/shared master data, and integration configuration.
--
-- Safety policy:
--   - DO NOT run this in production.
--   - Review all preview SELECT statements before executing the DELETE section.
--   - This script does not disable FOREIGN_KEY_CHECKS.
--   - This script does not blindly TRUNCATE shared tables.
--   - This script does not delete audit_log by default.
--   - This script does not delete physical uploaded files from disk.
--
-- Preserved categories:
--   SECURITY: user_account, role, permission, role_permission, user_role,
--     notification_preference.
--   ORGANIZATION/REFERENCE MASTER: department_reference, employee_reference,
--     building, facility_space, request_category, sla_policy, asset_category,
--     document_category, contract_type, retention_schedule,
--     document_template_merge_field, supplier_reference, budget_reference,
--     inventory_item_reference, vehicle_reference.
--   INTEGRATION CONFIG/MASTER: external_system, google_account_connection,
--     system_setting.
--
-- Supersedes for clean-slate UAT reset:
--   - database/migrations/2026_contract_management_uat_reset.sql
--   - database/migrations/2026_uat_orphan_reference_cleanup.sql

-- ==================================================
-- 1. PREFLIGHT COUNTS
-- ==================================================
-- Review these counts before running the reset.

SELECT 'activity_event' AS table_name, COUNT(*) AS before_count, 0 AS expected_after FROM activity_event
UNION ALL SELECT 'ai_recommendation', COUNT(*), 0 FROM ai_recommendation
UNION ALL SELECT 'approval_request', COUNT(*), 0 FROM approval_request
UNION ALL SELECT 'approval_step', COUNT(*), 0 FROM approval_step
UNION ALL SELECT 'asset', COUNT(*), 0 FROM asset
UNION ALL SELECT 'asset_history', COUNT(*), 0 FROM asset_history
UNION ALL SELECT 'contract', COUNT(*), 0 FROM contract
UNION ALL SELECT 'contract_amendment', COUNT(*), 0 FROM contract_amendment
UNION ALL SELECT 'contract_client_requirement', COUNT(*), 0 FROM contract_client_requirement
UNION ALL SELECT 'contract_google_document', COUNT(*), 0 FROM contract_google_document
UNION ALL SELECT 'contract_history', COUNT(*), 0 FROM contract_history
UNION ALL SELECT 'contract_obligation', COUNT(*), 0 FROM contract_obligation
UNION ALL SELECT 'contract_party', COUNT(*), 0 FROM contract_party
UNION ALL SELECT 'contract_renewal_event', COUNT(*), 0 FROM contract_renewal_event
UNION ALL SELECT 'contract_template_value', COUNT(*), 0 FROM contract_template_value
UNION ALL SELECT 'document', COUNT(*), 0 FROM document
UNION ALL SELECT 'document_template', COUNT(*), 0 FROM document_template
UNION ALL SELECT 'document_template_version', COUNT(*), 0 FROM document_template_version
UNION ALL SELECT 'document_template_version_field', COUNT(*), 0 FROM document_template_version_field
UNION ALL SELECT 'document_version', COUNT(*), 0 FROM document_version
UNION ALL SELECT 'external_entity_mapping', COUNT(*), 0 FROM external_entity_mapping
UNION ALL SELECT 'facility_request', COUNT(*), 0 FROM facility_request
UNION ALL SELECT 'facility_request_history', COUNT(*), 0 FROM facility_request_history
UNION ALL SELECT 'facility_reservation', COUNT(*), 0 FROM facility_reservation
UNION ALL SELECT 'integration_outbox', COUNT(*), 0 FROM integration_outbox
UNION ALL SELECT 'integration_sync_log', COUNT(*), 0 FROM integration_sync_log
UNION ALL SELECT 'legal_case_retired', COUNT(*), 0 FROM legal_case_retired
UNION ALL SELECT 'legal_matter', COUNT(*), 0 FROM legal_matter
UNION ALL SELECT 'legal_matter_action', COUNT(*), 0 FROM legal_matter_action
UNION ALL SELECT 'legal_matter_action_suggestion', COUNT(*), 0 FROM legal_matter_action_suggestion
UNION ALL SELECT 'legal_matter_history', COUNT(*), 0 FROM legal_matter_history
UNION ALL SELECT 'legal_matter_party', COUNT(*), 0 FROM legal_matter_party
UNION ALL SELECT 'legal_matter_party_suggestion', COUNT(*), 0 FROM legal_matter_party_suggestion
UNION ALL SELECT 'maintenance_history', COUNT(*), 0 FROM maintenance_history
UNION ALL SELECT 'maintenance_material', COUNT(*), 0 FROM maintenance_material
UNION ALL SELECT 'maintenance_work_order', COUNT(*), 0 FROM maintenance_work_order
UNION ALL SELECT 'notification', COUNT(*), 0 FROM notification
UNION ALL SELECT 'preventive_maintenance_plan', COUNT(*), 0 FROM preventive_maintenance_plan
UNION ALL SELECT 'procurement_history', COUNT(*), 0 FROM procurement_history
UNION ALL SELECT 'procurement_request', COUNT(*), 0 FROM procurement_request
UNION ALL SELECT 'procurement_request_item', COUNT(*), 0 FROM procurement_request_item
UNION ALL SELECT 'purchase_order_reference', COUNT(*), 0 FROM purchase_order_reference
UNION ALL SELECT 'record', COUNT(*), 0 FROM record
UNION ALL SELECT 'record_disposition_recommendation', COUNT(*), 0 FROM record_disposition_recommendation
UNION ALL SELECT 'record_document', COUNT(*), 0 FROM record_document
UNION ALL SELECT 'reservation_history', COUNT(*), 0 FROM reservation_history
UNION ALL SELECT 'reservation_participant', COUNT(*), 0 FROM reservation_participant
UNION ALL SELECT 'reservation_request_letter', COUNT(*), 0 FROM reservation_request_letter
UNION ALL SELECT 'sla_tracking', COUNT(*), 0 FROM sla_tracking
UNION ALL SELECT 'visit', COUNT(*), 0 FROM visit
UNION ALL SELECT 'visitor', COUNT(*), 0 FROM visitor
UNION ALL SELECT 'visitor_pass', COUNT(*), 0 FROM visitor_pass
UNION ALL SELECT 'visitor_registration_challenge', COUNT(*), 0 FROM visitor_registration_challenge
UNION ALL SELECT 'visitor_sequence', COUNT(*), 0 FROM visitor_sequence
UNION ALL SELECT 'visitor_visit_history', COUNT(*), 0 FROM visitor_visit_history
UNION ALL SELECT 'workflow_task', COUNT(*), 0 FROM workflow_task
ORDER BY table_name;

-- Preserved baseline checks.
SELECT 'document_template_merge_field' AS table_name, COUNT(*) AS preserved_count FROM document_template_merge_field
UNION ALL SELECT 'retention_schedule', COUNT(*) FROM retention_schedule
UNION ALL SELECT 'contract_type', COUNT(*) FROM contract_type
UNION ALL SELECT 'document_category', COUNT(*) FROM document_category
UNION ALL SELECT 'supplier_reference', COUNT(*) FROM supplier_reference
UNION ALL SELECT 'budget_reference', COUNT(*) FROM budget_reference
UNION ALL SELECT 'user_account', COUNT(*) FROM user_account
UNION ALL SELECT 'role', COUNT(*) FROM role
UNION ALL SELECT 'permission', COUNT(*) FROM permission
UNION ALL SELECT 'role_permission', COUNT(*) FROM role_permission
UNION ALL SELECT 'user_role', COUNT(*) FROM user_role
UNION ALL SELECT 'google_account_connection', COUNT(*) FROM google_account_connection
ORDER BY table_name;

-- ==================================================
-- 2. PHYSICAL FILE PREFLIGHT
-- ==================================================
-- Export these storage_path values before running the reset.
-- SQL removes DB rows only. After a successful reset, delete only these files
-- from the configured document storage root if they still exist on disk.

SELECT d.document_id,
       dv.document_version_id,
       CASE
         WHEN dtv.template_version_id IS NOT NULL THEN 'template_backing_document'
         WHEN rd.record_document_id IS NOT NULL THEN 'record_or_operational_document'
         WHEN ccr.contract_client_requirement_id IS NOT NULL THEN 'contract_requirement_evidence'
         WHEN cgd.contract_google_document_id IS NOT NULL THEN 'contract_google_synced_document'
         WHEN ca.contract_amendment_id IS NOT NULL THEN 'contract_amendment_document'
         WHEN co.contract_obligation_id IS NOT NULL THEN 'contract_obligation_document'
         ELSE 'user_uploaded_document'
       END AS owner_classification,
       dv.storage_path
FROM document d
JOIN document_version dv ON dv.document_id = d.document_id
LEFT JOIN document_template_version dtv ON dtv.document_id = d.document_id OR dtv.document_version_id = dv.document_version_id
LEFT JOIN record_document rd ON rd.document_id = d.document_id
LEFT JOIN contract_client_requirement ccr ON ccr.uploaded_document_id = d.document_id
LEFT JOIN contract_google_document cgd ON cgd.synced_document_id = d.document_id OR cgd.synced_document_version_id = dv.document_version_id
LEFT JOIN contract_amendment ca ON ca.document_id = d.document_id
LEFT JOIN contract_obligation co ON co.completion_document_id = d.document_id
WHERE dv.storage_path IS NOT NULL
  AND dv.storage_path <> ''
ORDER BY d.document_id, dv.document_version_id;

-- Reservation request letters are stored outside document_version.
SELECT rrl.reservation_request_letter_id,
       rrl.facility_reservation_id,
       rrl.original_file_name,
       rrl.storage_path
FROM reservation_request_letter rrl
WHERE rrl.storage_path IS NOT NULL
  AND rrl.storage_path <> ''
ORDER BY rrl.facility_reservation_id, rrl.reservation_request_letter_id;

-- ==================================================
-- 3. TRANSACTIONAL RESET
-- ==================================================

START TRANSACTION;

-- Generic runtime/application instance tables.
DELETE FROM notification;
DELETE FROM workflow_task;
DELETE FROM approval_step;
DELETE FROM approval_request;
DELETE FROM ai_recommendation;
DELETE FROM integration_outbox;
DELETE FROM integration_sync_log;
DELETE FROM external_entity_mapping;
DELETE FROM activity_event;

-- Legal Management transactional data.
DELETE FROM legal_matter_action_suggestion;
DELETE FROM legal_matter_action;
DELETE FROM legal_matter_party_suggestion;
DELETE FROM legal_matter_party;
DELETE FROM legal_matter_history;
DELETE FROM legal_matter;
DELETE FROM legal_case_retired;

-- Contract Management transactional data.
DELETE FROM contract_renewal_event;
DELETE FROM contract_amendment;
DELETE FROM contract_obligation;
DELETE FROM contract_party;
DELETE FROM contract_template_value;
DELETE FROM contract_client_requirement;
DELETE FROM contract_google_document;
DELETE FROM contract_history;
UPDATE contract
SET renewed_from_contract_id = NULL
WHERE renewed_from_contract_id IS NOT NULL;
DELETE FROM contract;

-- Records/Retention transactional data and user document links.
DELETE FROM record_disposition_recommendation;
DELETE FROM record_document;
DELETE FROM record;

-- Document Template user content. Preserve document_template_merge_field.
UPDATE document_template
SET current_approved_version_id = NULL
WHERE current_approved_version_id IS NOT NULL;
DELETE FROM document_template_version_field;
DELETE FROM document_template_version;
DELETE FROM document_template;

-- Document Management user content and physical-file DB metadata.
DELETE FROM document_version;
DELETE FROM document;

-- Procurement transactions. Preserve supplier_reference, budget_reference, and inventory_item_reference.
DELETE FROM purchase_order_reference;
DELETE FROM procurement_history;
DELETE FROM procurement_request_item;
DELETE FROM procurement_request;

-- Maintenance and asset runtime data. Preserve asset_category and inventory_item_reference.
DELETE FROM maintenance_material;
DELETE FROM maintenance_history;
DELETE FROM maintenance_work_order;
DELETE FROM preventive_maintenance_plan;
DELETE FROM asset_history;
DELETE FROM asset;

-- Facility request transactions. Preserve request_category and sla_policy.
DELETE FROM sla_tracking;
DELETE FROM facility_request_history;
DELETE FROM facility_request;

-- Visitor Management transactions.
-- visitor_badge rows are reusable badge inventory/reference data, so preserve
-- the badge rows and reset only runtime assignment/return state.
UPDATE visitor_badge
SET issued_to_visit_id = NULL,
    issued_by_user_id = NULL,
    issued_at = NULL,
    returned_to_user_id = NULL,
    returned_at = NULL,
    badge_status = 'AVAILABLE',
    remarks = NULL
WHERE issued_to_visit_id IS NOT NULL
   OR issued_by_user_id IS NOT NULL
   OR issued_at IS NOT NULL
   OR returned_to_user_id IS NOT NULL
   OR returned_at IS NOT NULL
   OR badge_status <> 'AVAILABLE'
   OR remarks IS NOT NULL;
DELETE FROM visitor_pass;
DELETE FROM visitor_visit_history;
DELETE FROM visitor_registration_challenge;
DELETE FROM visit;
DELETE FROM visitor;
DELETE FROM visitor_sequence;

-- Facility reservation transactions. Preserve building and facility_space.
DELETE FROM reservation_participant;
DELETE FROM reservation_history;
DELETE FROM reservation_request_letter;
DELETE FROM facility_reservation;

COMMIT;

-- ==================================================
-- 4. AUTO_INCREMENT CLEANUP
-- ==================================================
-- These ALTER TABLE statements implicitly commit in MySQL, so they run after
-- the transactional reset. They are only applied to tables intended to be empty.

SET @sql := IF((SELECT COUNT(*) FROM activity_event) = 0, 'ALTER TABLE activity_event AUTO_INCREMENT = 1', 'SELECT ''activity_event not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM ai_recommendation) = 0, 'ALTER TABLE ai_recommendation AUTO_INCREMENT = 1', 'SELECT ''ai_recommendation not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM approval_request) = 0, 'ALTER TABLE approval_request AUTO_INCREMENT = 1', 'SELECT ''approval_request not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM approval_step) = 0, 'ALTER TABLE approval_step AUTO_INCREMENT = 1', 'SELECT ''approval_step not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM asset) = 0, 'ALTER TABLE asset AUTO_INCREMENT = 1', 'SELECT ''asset not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM asset_history) = 0, 'ALTER TABLE asset_history AUTO_INCREMENT = 1', 'SELECT ''asset_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract) = 0, 'ALTER TABLE contract AUTO_INCREMENT = 1', 'SELECT ''contract not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_amendment) = 0, 'ALTER TABLE contract_amendment AUTO_INCREMENT = 1', 'SELECT ''contract_amendment not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_client_requirement) = 0, 'ALTER TABLE contract_client_requirement AUTO_INCREMENT = 1', 'SELECT ''contract_client_requirement not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_google_document) = 0, 'ALTER TABLE contract_google_document AUTO_INCREMENT = 1', 'SELECT ''contract_google_document not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_history) = 0, 'ALTER TABLE contract_history AUTO_INCREMENT = 1', 'SELECT ''contract_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_obligation) = 0, 'ALTER TABLE contract_obligation AUTO_INCREMENT = 1', 'SELECT ''contract_obligation not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_party) = 0, 'ALTER TABLE contract_party AUTO_INCREMENT = 1', 'SELECT ''contract_party not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_renewal_event) = 0, 'ALTER TABLE contract_renewal_event AUTO_INCREMENT = 1', 'SELECT ''contract_renewal_event not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM contract_template_value) = 0, 'ALTER TABLE contract_template_value AUTO_INCREMENT = 1', 'SELECT ''contract_template_value not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM document) = 0, 'ALTER TABLE document AUTO_INCREMENT = 1', 'SELECT ''document not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM document_template) = 0, 'ALTER TABLE document_template AUTO_INCREMENT = 1', 'SELECT ''document_template not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM document_template_version) = 0, 'ALTER TABLE document_template_version AUTO_INCREMENT = 1', 'SELECT ''document_template_version not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM document_version) = 0, 'ALTER TABLE document_version AUTO_INCREMENT = 1', 'SELECT ''document_version not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM external_entity_mapping) = 0, 'ALTER TABLE external_entity_mapping AUTO_INCREMENT = 1', 'SELECT ''external_entity_mapping not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM facility_request) = 0, 'ALTER TABLE facility_request AUTO_INCREMENT = 1', 'SELECT ''facility_request not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM facility_request_history) = 0, 'ALTER TABLE facility_request_history AUTO_INCREMENT = 1', 'SELECT ''facility_request_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM facility_reservation) = 0, 'ALTER TABLE facility_reservation AUTO_INCREMENT = 1', 'SELECT ''facility_reservation not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM integration_outbox) = 0, 'ALTER TABLE integration_outbox AUTO_INCREMENT = 1', 'SELECT ''integration_outbox not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM integration_sync_log) = 0, 'ALTER TABLE integration_sync_log AUTO_INCREMENT = 1', 'SELECT ''integration_sync_log not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM legal_case_retired) = 0, 'ALTER TABLE legal_case_retired AUTO_INCREMENT = 1', 'SELECT ''legal_case_retired not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM legal_matter) = 0, 'ALTER TABLE legal_matter AUTO_INCREMENT = 1', 'SELECT ''legal_matter not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM legal_matter_action) = 0, 'ALTER TABLE legal_matter_action AUTO_INCREMENT = 1', 'SELECT ''legal_matter_action not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM legal_matter_action_suggestion) = 0, 'ALTER TABLE legal_matter_action_suggestion AUTO_INCREMENT = 1', 'SELECT ''legal_matter_action_suggestion not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM legal_matter_history) = 0, 'ALTER TABLE legal_matter_history AUTO_INCREMENT = 1', 'SELECT ''legal_matter_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM legal_matter_party) = 0, 'ALTER TABLE legal_matter_party AUTO_INCREMENT = 1', 'SELECT ''legal_matter_party not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM legal_matter_party_suggestion) = 0, 'ALTER TABLE legal_matter_party_suggestion AUTO_INCREMENT = 1', 'SELECT ''legal_matter_party_suggestion not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM maintenance_history) = 0, 'ALTER TABLE maintenance_history AUTO_INCREMENT = 1', 'SELECT ''maintenance_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM maintenance_material) = 0, 'ALTER TABLE maintenance_material AUTO_INCREMENT = 1', 'SELECT ''maintenance_material not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM maintenance_work_order) = 0, 'ALTER TABLE maintenance_work_order AUTO_INCREMENT = 1', 'SELECT ''maintenance_work_order not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM notification) = 0, 'ALTER TABLE notification AUTO_INCREMENT = 1', 'SELECT ''notification not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM preventive_maintenance_plan) = 0, 'ALTER TABLE preventive_maintenance_plan AUTO_INCREMENT = 1', 'SELECT ''preventive_maintenance_plan not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM procurement_history) = 0, 'ALTER TABLE procurement_history AUTO_INCREMENT = 1', 'SELECT ''procurement_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM procurement_request) = 0, 'ALTER TABLE procurement_request AUTO_INCREMENT = 1', 'SELECT ''procurement_request not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM procurement_request_item) = 0, 'ALTER TABLE procurement_request_item AUTO_INCREMENT = 1', 'SELECT ''procurement_request_item not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM purchase_order_reference) = 0, 'ALTER TABLE purchase_order_reference AUTO_INCREMENT = 1', 'SELECT ''purchase_order_reference not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM record) = 0, 'ALTER TABLE record AUTO_INCREMENT = 1', 'SELECT ''record not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM record_disposition_recommendation) = 0, 'ALTER TABLE record_disposition_recommendation AUTO_INCREMENT = 1', 'SELECT ''record_disposition_recommendation not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM record_document) = 0, 'ALTER TABLE record_document AUTO_INCREMENT = 1', 'SELECT ''record_document not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM reservation_history) = 0, 'ALTER TABLE reservation_history AUTO_INCREMENT = 1', 'SELECT ''reservation_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM reservation_participant) = 0, 'ALTER TABLE reservation_participant AUTO_INCREMENT = 1', 'SELECT ''reservation_participant not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM sla_tracking) = 0, 'ALTER TABLE sla_tracking AUTO_INCREMENT = 1', 'SELECT ''sla_tracking not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM visit) = 0, 'ALTER TABLE visit AUTO_INCREMENT = 1', 'SELECT ''visit not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM visitor) = 0, 'ALTER TABLE visitor AUTO_INCREMENT = 1', 'SELECT ''visitor not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM visitor_pass) = 0, 'ALTER TABLE visitor_pass AUTO_INCREMENT = 1', 'SELECT ''visitor_pass not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM reservation_request_letter) = 0, 'ALTER TABLE reservation_request_letter AUTO_INCREMENT = 1', 'SELECT ''reservation_request_letter not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM visitor_registration_challenge) = 0, 'ALTER TABLE visitor_registration_challenge AUTO_INCREMENT = 1', 'SELECT ''visitor_registration_challenge not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM visitor_visit_history) = 0, 'ALTER TABLE visitor_visit_history AUTO_INCREMENT = 1', 'SELECT ''visitor_visit_history not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM workflow_task) = 0, 'ALTER TABLE workflow_task AUTO_INCREMENT = 1', 'SELECT ''workflow_task not empty; AUTO_INCREMENT not reset'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ==================================================
-- 5. POST-RESET VERIFICATION
-- ==================================================

SELECT 'contract' AS table_name, COUNT(*) AS remaining_rows FROM contract
UNION ALL SELECT 'contract_history', COUNT(*) FROM contract_history
UNION ALL SELECT 'contract_client_requirement', COUNT(*) FROM contract_client_requirement
UNION ALL SELECT 'contract_google_document', COUNT(*) FROM contract_google_document
UNION ALL SELECT 'document', COUNT(*) FROM document
UNION ALL SELECT 'document_version', COUNT(*) FROM document_version
UNION ALL SELECT 'document_template', COUNT(*) FROM document_template
UNION ALL SELECT 'document_template_version', COUNT(*) FROM document_template_version
UNION ALL SELECT 'document_template_version_field', COUNT(*) FROM document_template_version_field
UNION ALL SELECT 'record', COUNT(*) FROM record
UNION ALL SELECT 'record_document', COUNT(*) FROM record_document
UNION ALL SELECT 'record_disposition_recommendation', COUNT(*) FROM record_disposition_recommendation
UNION ALL SELECT 'facility_request', COUNT(*) FROM facility_request
UNION ALL SELECT 'facility_reservation', COUNT(*) FROM facility_reservation
UNION ALL SELECT 'visit', COUNT(*) FROM visit
UNION ALL SELECT 'visitor', COUNT(*) FROM visitor
UNION ALL SELECT 'visitor_sequence', COUNT(*) FROM visitor_sequence
UNION ALL SELECT 'reservation_request_letter', COUNT(*) FROM reservation_request_letter
UNION ALL SELECT 'legal_matter', COUNT(*) FROM legal_matter
UNION ALL SELECT 'maintenance_work_order', COUNT(*) FROM maintenance_work_order
UNION ALL SELECT 'asset', COUNT(*) FROM asset
UNION ALL SELECT 'procurement_request', COUNT(*) FROM procurement_request
UNION ALL SELECT 'purchase_order_reference', COUNT(*) FROM purchase_order_reference
UNION ALL SELECT 'activity_event', COUNT(*) FROM activity_event
UNION ALL SELECT 'notification', COUNT(*) FROM notification
UNION ALL SELECT 'workflow_task', COUNT(*) FROM workflow_task
UNION ALL SELECT 'approval_request', COUNT(*) FROM approval_request
UNION ALL SELECT 'approval_step', COUNT(*) FROM approval_step
UNION ALL SELECT 'ai_recommendation', COUNT(*) FROM ai_recommendation
UNION ALL SELECT 'integration_outbox', COUNT(*) FROM integration_outbox;

-- Orphan checks.
SELECT 'document_version_missing_document' AS check_name, COUNT(*) AS orphan_count
FROM document_version dv
LEFT JOIN document d ON d.document_id = dv.document_id
WHERE d.document_id IS NULL
UNION ALL
SELECT 'record_document_missing_record', COUNT(*)
FROM record_document rd
LEFT JOIN record r ON r.record_id = rd.record_id
WHERE r.record_id IS NULL
UNION ALL
SELECT 'record_document_missing_document', COUNT(*)
FROM record_document rd
LEFT JOIN document d ON d.document_id = rd.document_id
WHERE d.document_id IS NULL
UNION ALL
SELECT 'template_version_missing_document', COUNT(*)
FROM document_template_version dtv
LEFT JOIN document d ON d.document_id = dtv.document_id
WHERE d.document_id IS NULL;

-- Preservation verification.
SELECT 'user_account' AS table_name, COUNT(*) AS preserved_rows FROM user_account
UNION ALL SELECT 'role', COUNT(*) FROM role
UNION ALL SELECT 'permission', COUNT(*) FROM permission
UNION ALL SELECT 'role_permission', COUNT(*) FROM role_permission
UNION ALL SELECT 'user_role', COUNT(*) FROM user_role
UNION ALL SELECT 'department_reference', COUNT(*) FROM department_reference
UNION ALL SELECT 'employee_reference', COUNT(*) FROM employee_reference
UNION ALL SELECT 'contract_type', COUNT(*) FROM contract_type
UNION ALL SELECT 'document_category', COUNT(*) FROM document_category
UNION ALL SELECT 'document_template_merge_field', COUNT(*) FROM document_template_merge_field
UNION ALL SELECT 'retention_schedule', COUNT(*) FROM retention_schedule
UNION ALL SELECT 'supplier_reference', COUNT(*) FROM supplier_reference
UNION ALL SELECT 'budget_reference', COUNT(*) FROM budget_reference
UNION ALL SELECT 'external_system', COUNT(*) FROM external_system
UNION ALL SELECT 'google_account_connection', COUNT(*) FROM google_account_connection
UNION ALL SELECT 'system_setting', COUNT(*) FROM system_setting
ORDER BY table_name;
