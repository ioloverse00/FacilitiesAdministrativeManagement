-- ==================================================
-- FAM DEVELOPMENT / UAT ONLY TRANSACTION RESET
-- ==================================================
--
-- Project: FacilitiesAdministrativeManagement
-- Database: ismers_fam
--
-- PURPOSE
-- Clear local transactional/UAT data while preserving reference,
-- configuration, RBAC, facilities, employee, and retention policy data.
--
-- DO NOT RUN AGAINST PRODUCTION.
--
-- Recommended backup before execution:
--   mysqldump -u root ismers_fam > backups/ismers_fam_before_uat_reset.sql
--
-- Preserved examples:
--   user_account, user_role, role, permission, role_permission
--   employee_reference, department_reference
--   building, facility_space
--   request_category, sla_policy
--   document_category, retention_schedule
--   asset_category, contract_type
--   external/reference tables and system_setting
--
-- Uploaded document files:
--   This SQL resets database rows only. Before running, capture the
--   document_version.storage_path values below and delete only those files
--   under the verified local storage/documents directory after review.
--
--   SELECT storage_path
--   FROM document_version
--   WHERE storage_path IS NOT NULL
--     AND storage_path <> ''
--     AND storage_path NOT LIKE '/demo-storage/%'
--   ORDER BY storage_path;
--
-- ==================================================

START TRANSACTION;

-- --------------------------------------------------
-- Module-scoped activity, audit, notifications, tasks
-- --------------------------------------------------
DELETE FROM notification
WHERE module_code IN (
  'facility_requests',
  'maintenance',
  'assets',
  'reservations',
  'visitors',
  'procurement',
  'documents',
  'records',
  'retention',
  'legal',
  'legal_management'
);

DELETE FROM workflow_task
WHERE module_code IN (
  'facility_requests',
  'maintenance',
  'assets',
  'reservations',
  'visitors',
  'procurement',
  'documents',
  'records',
  'retention',
  'legal',
  'legal_management'
);

DELETE FROM activity_event
WHERE module_code IN (
  'facility_requests',
  'maintenance',
  'assets',
  'reservations',
  'visitors',
  'procurement',
  'documents',
  'records',
  'retention',
  'legal',
  'legal_management'
);

DELETE FROM audit_log
WHERE module_code IN (
  'facility_requests',
  'maintenance',
  'assets',
  'reservations',
  'visitors',
  'procurement',
  'documents',
  'records',
  'retention',
  'legal',
  'legal_management'
);

DELETE FROM integration_outbox
WHERE source_module IN (
  'facility_requests',
  'maintenance',
  'assets',
  'reservations',
  'visitors',
  'procurement',
  'documents',
  'records',
  'retention',
  'legal',
  'legal_management'
);

DELETE FROM integration_sync_log;
DELETE FROM external_entity_mapping;

-- --------------------------------------------------
-- AI/recommendation and approval transactions
-- --------------------------------------------------
DELETE FROM ai_recommendation;
DELETE FROM approval_step;
DELETE FROM approval_request;

-- --------------------------------------------------
-- Legal Management transactions
-- Child tables are listed explicitly for readability.
-- ON DELETE CASCADE also protects these when deleting legal_matter.
-- --------------------------------------------------
DELETE FROM legal_matter_action_suggestion;
DELETE FROM legal_matter_action;
DELETE FROM legal_matter_party_suggestion;
DELETE FROM legal_matter_party;
DELETE FROM legal_matter_history;
DELETE FROM legal_matter;
DELETE FROM legal_case_retired;

-- --------------------------------------------------
-- Records Retention and Document transactions
-- Preserve retention_schedule and document_category.
-- --------------------------------------------------
DELETE FROM record_disposition_recommendation;
DELETE FROM record_document;
DELETE FROM document_version;
DELETE FROM record;
DELETE FROM document;

-- --------------------------------------------------
-- Procurement transactions
-- --------------------------------------------------
DELETE FROM purchase_order_reference;
DELETE FROM procurement_history;
DELETE FROM procurement_request_item;
DELETE FROM procurement_request;

-- --------------------------------------------------
-- Maintenance and asset transactions
-- Preserve asset_category, inventory_item_reference, suppliers,
-- buildings, and spaces.
-- --------------------------------------------------
DELETE FROM maintenance_material;
DELETE FROM maintenance_history;
DELETE FROM maintenance_work_order;
DELETE FROM preventive_maintenance_plan;
DELETE FROM asset_history;
DELETE FROM asset;

-- --------------------------------------------------
-- Facility request transactions
-- Preserve request_category and sla_policy.
-- --------------------------------------------------
DELETE FROM sla_tracking;
DELETE FROM facility_request_history;
DELETE FROM facility_request;

-- --------------------------------------------------
-- Room reservation transactions
-- Preserve building/facility_space room reference data.
-- --------------------------------------------------
DELETE FROM reservation_participant;
DELETE FROM reservation_history;
DELETE FROM reservation_request_letter;
DELETE FROM facility_reservation;

-- --------------------------------------------------
-- Visitor Management transactions
-- Preserve visitor_badge inventory rows, but return them to
-- available/unissued state for the next clean UAT cycle.
-- --------------------------------------------------
DELETE FROM visitor_pass;
DELETE FROM visitor_visit_history;
DELETE FROM visitor_registration_challenge;
UPDATE visitor_badge
SET badge_status = 'AVAILABLE',
    issued_to_visit_id = NULL,
    issued_at = NULL,
    returned_at = NULL,
    issued_by_user_id = NULL,
    returned_to_user_id = NULL,
    updated_at = NOW();
DELETE FROM visit;
DELETE FROM visitor;

-- Reset local visitor numbering for clean UAT only.
UPDATE visitor_sequence
SET last_number = 0,
    updated_at = NOW();

-- --------------------------------------------------
-- Contracts are currently local transaction data.
-- Preserve contract_type reference rows.
-- --------------------------------------------------
DELETE FROM contract;

COMMIT;

-- --------------------------------------------------
-- Reset AUTO_INCREMENT counters for fully-cleared
-- transactional tables. Module-scoped audit/activity/log tables are
-- intentionally not reset because non-module/auth/system rows may remain.
-- --------------------------------------------------
ALTER TABLE workflow_task AUTO_INCREMENT = 1;
ALTER TABLE integration_outbox AUTO_INCREMENT = 1;
ALTER TABLE integration_sync_log AUTO_INCREMENT = 1;
ALTER TABLE external_entity_mapping AUTO_INCREMENT = 1;

ALTER TABLE ai_recommendation AUTO_INCREMENT = 1;
ALTER TABLE approval_step AUTO_INCREMENT = 1;
ALTER TABLE approval_request AUTO_INCREMENT = 1;

ALTER TABLE legal_matter_action_suggestion AUTO_INCREMENT = 1;
ALTER TABLE legal_matter_action AUTO_INCREMENT = 1;
ALTER TABLE legal_matter_party_suggestion AUTO_INCREMENT = 1;
ALTER TABLE legal_matter_party AUTO_INCREMENT = 1;
ALTER TABLE legal_matter_history AUTO_INCREMENT = 1;
ALTER TABLE legal_matter AUTO_INCREMENT = 1;
ALTER TABLE legal_case_retired AUTO_INCREMENT = 1;

ALTER TABLE record_disposition_recommendation AUTO_INCREMENT = 1;
ALTER TABLE record_document AUTO_INCREMENT = 1;
ALTER TABLE document_version AUTO_INCREMENT = 1;
ALTER TABLE record AUTO_INCREMENT = 1;
ALTER TABLE document AUTO_INCREMENT = 1;

ALTER TABLE purchase_order_reference AUTO_INCREMENT = 1;
ALTER TABLE procurement_history AUTO_INCREMENT = 1;
ALTER TABLE procurement_request_item AUTO_INCREMENT = 1;
ALTER TABLE procurement_request AUTO_INCREMENT = 1;

ALTER TABLE maintenance_material AUTO_INCREMENT = 1;
ALTER TABLE maintenance_history AUTO_INCREMENT = 1;
ALTER TABLE maintenance_work_order AUTO_INCREMENT = 1;
ALTER TABLE preventive_maintenance_plan AUTO_INCREMENT = 1;
ALTER TABLE asset_history AUTO_INCREMENT = 1;
ALTER TABLE asset AUTO_INCREMENT = 1;

ALTER TABLE sla_tracking AUTO_INCREMENT = 1;
ALTER TABLE facility_request_history AUTO_INCREMENT = 1;
ALTER TABLE facility_request AUTO_INCREMENT = 1;

ALTER TABLE reservation_participant AUTO_INCREMENT = 1;
ALTER TABLE reservation_history AUTO_INCREMENT = 1;
ALTER TABLE reservation_request_letter AUTO_INCREMENT = 1;
ALTER TABLE facility_reservation AUTO_INCREMENT = 1;

ALTER TABLE visitor_pass AUTO_INCREMENT = 1;
ALTER TABLE visitor_visit_history AUTO_INCREMENT = 1;
ALTER TABLE visitor_registration_challenge AUTO_INCREMENT = 1;
ALTER TABLE visit AUTO_INCREMENT = 1;
ALTER TABLE visitor AUTO_INCREMENT = 1;

ALTER TABLE contract AUTO_INCREMENT = 1;

-- --------------------------------------------------
-- Post-reset verification queries.
-- These should return preserved reference counts and zero
-- transactional counts after execution.
-- --------------------------------------------------
SELECT 'PRESERVED role' AS check_name, COUNT(*) AS row_count FROM role
UNION ALL SELECT 'PRESERVED permission', COUNT(*) FROM permission
UNION ALL SELECT 'PRESERVED user_account', COUNT(*) FROM user_account
UNION ALL SELECT 'PRESERVED employee_reference', COUNT(*) FROM employee_reference
UNION ALL SELECT 'PRESERVED department_reference', COUNT(*) FROM department_reference
UNION ALL SELECT 'PRESERVED building', COUNT(*) FROM building
UNION ALL SELECT 'PRESERVED facility_space', COUNT(*) FROM facility_space
UNION ALL SELECT 'PRESERVED document_category', COUNT(*) FROM document_category
UNION ALL SELECT 'PRESERVED retention_schedule', COUNT(*) FROM retention_schedule;

SELECT 'RESET legal_matter' AS check_name, COUNT(*) AS row_count FROM legal_matter
UNION ALL SELECT 'RESET document', COUNT(*) FROM document
UNION ALL SELECT 'RESET document_version', COUNT(*) FROM document_version
UNION ALL SELECT 'RESET record', COUNT(*) FROM record
UNION ALL SELECT 'RESET facility_request', COUNT(*) FROM facility_request
UNION ALL SELECT 'RESET facility_reservation', COUNT(*) FROM facility_reservation
UNION ALL SELECT 'RESET visitor', COUNT(*) FROM visitor
UNION ALL SELECT 'RESET visit', COUNT(*) FROM visit
UNION ALL SELECT 'RESET procurement_request', COUNT(*) FROM procurement_request
UNION ALL SELECT 'RESET maintenance_work_order', COUNT(*) FROM maintenance_work_order
UNION ALL SELECT 'RESET asset', COUNT(*) FROM asset
UNION ALL SELECT 'RESET contract', COUNT(*) FROM contract;
