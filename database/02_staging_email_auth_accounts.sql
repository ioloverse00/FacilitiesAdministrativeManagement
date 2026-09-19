-- STAGING ONLY: transactional account/email data migration.
-- DO NOT RUN AGAINST PRODUCTION.
--
-- Prerequisite:
-- Run and verify database/01_staging_email_auth_schema.sql first.
--
-- Manual HostForge workflow for this file:
-- 1) Run PHASE A only. It is read-only preflight.
-- 2) Stop at the STOP HERE marker and manually inspect every result.
-- 3) Only if every result matches expectations, prepare PHASE B as one HostForge SQL submission.
-- 4) PHASE B must include START TRANSACTION, the migration DML, the post-migration SELECTs, and COMMIT.
-- 5) Execute the complete PHASE B block in one submission so START TRANSACTION and COMMIT use the same database connection.
-- 6) After execution, open a fresh HostForge query/session and run the commented persistence verification at the end of this file.

-- ===========================================================================
-- PHASE A - PREFLIGHT ONLY. READ-ONLY. NO MUTATIONS.
-- ===========================================================================

-- Expected: selected database is the intended HostForge staging database.
SELECT 'preflight_selected_database' AS check_name, DATABASE() AS selected_database;

-- Expected: user_account.email exists from 01_staging_email_auth_schema.sql.
SELECT 'preflight_user_account_email_column' AS check_name, COUNT(*) AS actual_count, 1 AS expected_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_account'
  AND COLUMN_NAME = 'email';

-- Expected: uq_user_account_email exists from 01_staging_email_auth_schema.sql.
SELECT 'preflight_user_account_email_index' AS check_name, COUNT(*) AS actual_count, 1 AS expected_count
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_account'
  AND INDEX_NAME = 'uq_user_account_email';

-- Expected: all 10 target usernames exist exactly once with these staging IDs.
SELECT
    'preflight_target_accounts_expected_ids' AS check_name,
    expected.username,
    expected.expected_user_account_id,
    expected.expected_employee_reference_id,
    COUNT(ua.user_account_id) AS actual_username_count,
    MAX(ua.user_account_id) AS actual_user_account_id,
    MAX(ua.employee_reference_id) AS actual_employee_reference_id,
    CASE
        WHEN COUNT(ua.user_account_id) = 1
         AND MAX(ua.user_account_id) = expected.expected_user_account_id
         AND MAX(ua.employee_reference_id) = expected.expected_employee_reference_id
        THEN 'OK'
        ELSE 'STOP_DO_NOT_RUN_PHASE_B'
    END AS result
FROM (
    SELECT 'gsms-super-admin' username, 16 expected_user_account_id, 16 expected_employee_reference_id UNION ALL
    SELECT 'gsms-fam-admin', 17, 17 UNION ALL
    SELECT 'reservation.officer', 22, 24 UNION ALL
    SELECT 'records.officer', 24, 26 UNION ALL
    SELECT 'gsms-hr-head', 27, 30 UNION ALL
    SELECT 'gsms-it-head', 29, 32 UNION ALL
    SELECT 'gsms-fin-head', 26, 28 UNION ALL
    SELECT 'gsms-maint-head', 30, 21 UNION ALL
    SELECT 'gsms-records-head', 31, 29 UNION ALL
    SELECT 'gsms-scm-head', 23, 25
) expected
LEFT JOIN user_account ua ON ua.username = expected.username
GROUP BY expected.username, expected.expected_user_account_id, expected.expected_employee_reference_id
ORDER BY expected.expected_user_account_id;

-- Expected: all 10 target accounts show current identity, department, and roles.
SELECT
    'preflight_target_account_inventory' AS check_name,
    ua.user_account_id,
    ua.username,
    ua.account_status,
    ua.email AS current_account_email,
    e.employee_reference_id,
    e.full_name,
    e.position_title,
    e.email_address AS current_employee_email,
    d.department_code,
    d.department_name,
    GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS current_roles
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id
LEFT JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
LEFT JOIN role r ON r.role_id = ur.role_id
WHERE ua.username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
GROUP BY ua.user_account_id
ORDER BY FIELD(ua.username,'gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head');

-- Expected: each target account has exactly one linked employee_reference.
SELECT
    'preflight_target_employee_links' AS check_name,
    ua.username,
    COUNT(e.employee_reference_id) AS linked_employee_count,
    1 AS expected_count
FROM user_account ua
LEFT JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
WHERE ua.username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
GROUP BY ua.user_account_id, ua.username
ORDER BY ua.username;

-- Expected: required role definitions exist exactly once.
SELECT
    'preflight_required_roles' AS check_name,
    expected.role_code,
    COUNT(r.role_id) AS actual_count,
    1 AS expected_count
FROM (
    SELECT 'FAM_SUPER_ADMIN' role_code UNION ALL
    SELECT 'FAM_ADMIN' UNION ALL
    SELECT 'FAM_STAFF' UNION ALL
    SELECT 'EMPLOYEE'
) expected
LEFT JOIN role r ON r.role_code = expected.role_code AND r.status = 'ACTIVE'
GROUP BY expected.role_code
ORDER BY expected.role_code;

-- Expected: no rows. Any row means a proposed target email is owned by another account.
SELECT
    'preflight_target_email_conflicts' AS check_name,
    target.username AS target_username,
    target.target_email,
    owner.user_account_id AS conflicting_user_account_id,
    owner.username AS conflicting_username
FROM (
    SELECT 'gsms-super-admin' username, 'fam_superadmin@greatsolomonmpservices.com' target_email UNION ALL
    SELECT 'gsms-fam-admin', 'fam_admin@greatsolomonmpservices.com' UNION ALL
    SELECT 'reservation.officer', 'fam_reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 'records.officer', 'fam_recordsofficer@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-hr-head', 'fam_hr@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-it-head', 'fam_it@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-fin-head', 'fam_fin@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-maint-head', 'fam_maintenance@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-records-head', 'fam_records@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-scm-head', 'fam_supplychain@greatsolomonmpservices.com'
) target
LEFT JOIN user_account owner
    ON LOWER(owner.email) = LOWER(target.target_email)
   AND owner.username <> target.username
WHERE owner.user_account_id IS NOT NULL
ORDER BY target.username;

-- Expected: no rows. Any row means duplicate non-null account emails already exist.
SELECT
    'preflight_duplicate_nonblank_account_emails' AS check_name,
    LOWER(email) AS normalized_email,
    COUNT(*) AS owner_count,
    GROUP_CONCAT(username ORDER BY username SEPARATOR ', ') AS owners
FROM user_account
WHERE email IS NOT NULL AND email <> ''
GROUP BY LOWER(email)
HAVING COUNT(*) > 1
ORDER BY LOWER(email);

-- Expected: DEP-FAC=18, DEP-MNT=19, DEP-FIN=28, DEP-HR=30, DEP-SCM=25 before Phase B.
SELECT
    'preflight_department_head_mappings' AS check_name,
    d.department_code,
    d.department_name,
    d.department_head_employee_reference_id,
    e.employee_number,
    e.full_name,
    ua.username
FROM department_reference d
LEFT JOIN employee_reference e ON e.employee_reference_id = d.department_head_employee_reference_id
LEFT JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id
WHERE d.source_system = 'HRIS'
ORDER BY d.department_code;

-- Expected: requestor.user exists as user_account_id 25 / employee_reference_id 27.
SELECT 'preflight_requestor_user' AS check_name, ua.user_account_id, ua.username, ua.account_status, ua.employee_reference_id, e.employee_number, e.full_name
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
WHERE ua.username = 'requestor.user';

-- Expected: all four obsolete operational accounts exist and will be deactivated, not deleted.
SELECT 'preflight_obsolete_operational_accounts' AS check_name, ua.user_account_id, ua.username, ua.account_status, ua.employee_reference_id, e.employee_number, e.full_name
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
WHERE ua.username IN ('facility.manager','maintenance.supervisor','technician.one','asset.custodian')
ORDER BY ua.username;

-- Expected: exactly 10 rows for RR-2026-0001 through RR-2026-0010.
SELECT 'preflight_r01_r10_exist' AS check_name, COUNT(*) AS actual_count, 10 AS expected_count
FROM facility_reservation
WHERE reservation_number IN ('RR-2026-0001','RR-2026-0002','RR-2026-0003','RR-2026-0004','RR-2026-0005','RR-2026-0006','RR-2026-0007','RR-2026-0008','RR-2026-0009','RR-2026-0010');

-- Expected: requester/creator IDs match the staging snapshot.
SELECT
    'preflight_r01_r10_attribution' AS check_name,
    fr.reservation_number,
    fr.requested_by_employee_reference_id,
    expected.expected_requested_by_employee_reference_id,
    fr.created_by_user_id,
    expected.expected_created_by_user_id,
    CASE WHEN fr.requested_by_employee_reference_id = expected.expected_requested_by_employee_reference_id AND fr.created_by_user_id = expected.expected_created_by_user_id THEN 'OK' ELSE 'STOP_DO_NOT_RUN_PHASE_B' END AS result
FROM facility_reservation fr
INNER JOIN (
    SELECT 'RR-2026-0001' reservation_number, 27 expected_requested_by_employee_reference_id, 25 expected_created_by_user_id UNION ALL
    SELECT 'RR-2026-0002', 30, 27 UNION ALL
    SELECT 'RR-2026-0003', 32, 29 UNION ALL
    SELECT 'RR-2026-0004', 28, 26 UNION ALL
    SELECT 'RR-2026-0005', 27, 25 UNION ALL
    SELECT 'RR-2026-0006', 29, 31 UNION ALL
    SELECT 'RR-2026-0007', 25, 23 UNION ALL
    SELECT 'RR-2026-0008', 21, 30 UNION ALL
    SELECT 'RR-2026-0009', 27, 25 UNION ALL
    SELECT 'RR-2026-0010', 30, 27
) expected ON expected.reservation_number = fr.reservation_number
ORDER BY fr.reservation_number;

-- Expected: exactly 10 request-letter rows; uploaded_by_user_id values preserve staging attribution.
SELECT 'preflight_request_letter_attribution' AS check_name, fr.reservation_number, rrl.reservation_request_letter_id, rrl.uploaded_by_user_id
FROM reservation_request_letter rrl
INNER JOIN facility_reservation fr ON fr.facility_reservation_id = rrl.facility_reservation_id
WHERE fr.reservation_number IN ('RR-2026-0001','RR-2026-0002','RR-2026-0003','RR-2026-0004','RR-2026-0005','RR-2026-0006','RR-2026-0007','RR-2026-0008','RR-2026-0009','RR-2026-0010')
ORDER BY fr.reservation_number;

-- Expected: visitor rows/history exist; actor IDs remain informational and are not mutated by Phase B.
SELECT
    'preflight_visitor_uat_counts' AS check_name,
    (SELECT COUNT(*) FROM visit) AS visit_count,
    (SELECT COUNT(*) FROM visitor_visit_history) AS visitor_visit_history_count,
    (SELECT GROUP_CONCAT(DISTINCT changed_by_user_id ORDER BY changed_by_user_id SEPARATOR ', ') FROM visitor_visit_history) AS visitor_history_actor_user_ids;

-- Expected: all 10 target accounts have non-null password_hash.
SELECT 'preflight_target_password_hash_presence' AS check_name, username, user_account_id, CASE WHEN password_hash IS NULL OR password_hash = '' THEN 'STOP_DO_NOT_RUN_PHASE_B' ELSE 'OK' END AS result
FROM user_account
WHERE username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
ORDER BY user_account_id;

-- ============================================================
-- STOP HERE.
-- DO NOT EXECUTE PHASE B unless ALL preflight results above
-- match the documented expected values.
-- ============================================================

-- ===========================================================================
-- PHASE B - TRANSACTIONAL MUTATION. RUN ONLY AFTER PHASE A IS REVIEWED.
-- ===========================================================================

START TRANSACTION;

UPDATE user_account ua
INNER JOIN (
    SELECT 16 user_account_id, 'gsms-super-admin' username, 'fam_superadmin@greatsolomonmpservices.com' email UNION ALL
    SELECT 17, 'gsms-fam-admin', 'fam_admin@greatsolomonmpservices.com' UNION ALL
    SELECT 22, 'reservation.officer', 'fam_reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'records.officer', 'fam_recordsofficer@greatsolomonmpservices.com' UNION ALL
    SELECT 27, 'gsms-hr-head', 'fam_hr@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'gsms-it-head', 'fam_it@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'gsms-fin-head', 'fam_fin@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'gsms-maint-head', 'fam_maintenance@greatsolomonmpservices.com' UNION ALL
    SELECT 31, 'gsms-records-head', 'fam_records@greatsolomonmpservices.com' UNION ALL
    SELECT 23, 'gsms-scm-head', 'fam_supplychain@greatsolomonmpservices.com'
) target ON target.user_account_id = ua.user_account_id AND target.username = ua.username
SET ua.email = target.email, ua.account_status = 'ACTIVE', ua.deleted_at = NULL;

UPDATE employee_reference e
INNER JOIN (
    SELECT 16 employee_reference_id, 'FAM Super Admin' full_name, 'FAM Super Admin' position_title, 'DEP-IT' department_code, 'fam_superadmin@greatsolomonmpservices.com' contact_email UNION ALL
    SELECT 17, 'FAM Admin', 'FAM Admin', 'DEP-FAC', 'fam_admin@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'Reservation Officer', 'Reservation Officer', 'DEP-ADM', 'fam_reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'Records Officer', 'Records Officer', 'DEP-REC', 'fam_recordsofficer@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'HR Head', 'HR Head', 'DEP-HR', 'fam_hr@greatsolomonmpservices.com' UNION ALL
    SELECT 32, 'IT Head', 'IT Head', 'DEP-IT', 'fam_it@greatsolomonmpservices.com' UNION ALL
    SELECT 28, 'Finance Head', 'Finance Head', 'DEP-FIN', 'fam_fin@greatsolomonmpservices.com' UNION ALL
    SELECT 21, 'Maintenance Head', 'Maintenance Head', 'DEP-MNT', 'fam_maintenance@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'Records Head', 'Records Head', 'DEP-REC', 'fam_records@greatsolomonmpservices.com' UNION ALL
    SELECT 25, 'Supply Chain Head', 'Supply Chain Head', 'DEP-SCM', 'fam_supplychain@greatsolomonmpservices.com'
) target ON target.employee_reference_id = e.employee_reference_id
INNER JOIN department_reference d ON d.department_code = target.department_code AND d.source_system = 'HRIS'
SET
    e.full_name = target.full_name,
    e.position_title = target.position_title,
    e.department_reference_id = d.department_reference_id,
    e.email_address = target.contact_email,
    e.employment_status = 'ACTIVE',
    e.deleted_at = NULL,
    e.updated_at = NOW();

DELETE ur
FROM user_role ur
INNER JOIN user_account ua ON ua.user_account_id = ur.user_account_id
INNER JOIN role r ON r.role_id = ur.role_id
INNER JOIN (
    SELECT 'gsms-super-admin' username, 'FAM_SUPER_ADMIN' target_role UNION ALL
    SELECT 'gsms-fam-admin', 'FAM_ADMIN' UNION ALL
    SELECT 'reservation.officer', 'FAM_STAFF' UNION ALL
    SELECT 'records.officer', 'FAM_STAFF' UNION ALL
    SELECT 'gsms-hr-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-it-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-fin-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-maint-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-records-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-scm-head', 'EMPLOYEE'
) target ON target.username = ua.username
WHERE r.role_code <> target.target_role;

INSERT IGNORE INTO user_role (user_account_id, role_id, assigned_by_user_id, assigned_at, expires_at)
SELECT ua.user_account_id, r.role_id, 16, NOW(), NULL
FROM (
    SELECT 'gsms-super-admin' username, 'FAM_SUPER_ADMIN' target_role UNION ALL
    SELECT 'gsms-fam-admin', 'FAM_ADMIN' UNION ALL
    SELECT 'reservation.officer', 'FAM_STAFF' UNION ALL
    SELECT 'records.officer', 'FAM_STAFF' UNION ALL
    SELECT 'gsms-hr-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-it-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-fin-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-maint-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-records-head', 'EMPLOYEE' UNION ALL
    SELECT 'gsms-scm-head', 'EMPLOYEE'
) target
INNER JOIN user_account ua ON ua.username = target.username
INNER JOIN role r ON r.role_code = target.target_role AND r.status = 'ACTIVE';

UPDATE department_reference d
INNER JOIN employee_reference e ON e.employee_reference_id = d.department_head_employee_reference_id
LEFT JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id
SET d.department_head_employee_reference_id = NULL
WHERE d.source_system = 'HRIS'
  AND (
      (d.department_code = 'DEP-FAC' AND e.employee_reference_id = 18 AND ua.username = 'facility.manager')
      OR (d.department_code = 'DEP-MNT' AND e.employee_reference_id = 19 AND ua.username = 'maintenance.supervisor')
      OR (d.department_code = 'DEP-FIN' AND e.employee_reference_id = 28 AND ua.username = 'gsms-fin-head')
      OR (d.department_code = 'DEP-HR' AND e.employee_reference_id = 30 AND ua.username = 'gsms-hr-head')
      OR (d.department_code = 'DEP-SCM' AND e.employee_reference_id = 25 AND ua.username = 'gsms-scm-head')
  );

UPDATE user_account ua
SET ua.account_status = 'INACTIVE', ua.updated_at = NOW()
WHERE (
    (ua.username = 'requestor.user' AND ua.user_account_id = 25 AND ua.employee_reference_id = 27)
    OR (ua.username = 'facility.manager' AND ua.user_account_id = 18 AND ua.employee_reference_id = 18)
    OR (ua.username = 'maintenance.supervisor' AND ua.user_account_id = 19 AND ua.employee_reference_id = 19)
    OR (ua.username = 'technician.one' AND ua.user_account_id = 20 AND ua.employee_reference_id = 20)
    OR (ua.username = 'asset.custodian' AND ua.user_account_id = 21 AND ua.employee_reference_id = 23)
);

-- POST-MIGRATION VERIFICATION. These result sets are emitted before the COMMIT
-- below, within the same HostForge SQL submission.

SELECT
    'post_final_active_personas' AS verification_name,
    ua.user_account_id,
    ua.username,
    ua.email,
    ua.account_status,
    e.employee_reference_id,
    e.full_name,
    e.position_title,
    d.department_name,
    GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS roles
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id
LEFT JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
LEFT JOIN role r ON r.role_id = ur.role_id
WHERE ua.username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
GROUP BY ua.user_account_id
ORDER BY FIELD(ua.username,'gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head');

SELECT 'post_final_active_target_count' AS verification_name, COUNT(*) AS actual_count, 10 AS expected_count
FROM user_account
WHERE username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
  AND account_status = 'ACTIVE'
  AND deleted_at IS NULL
  AND email IS NOT NULL
  AND email <> '';

SELECT 'post_duplicate_nonblank_account_emails' AS verification_name, LOWER(email) AS normalized_email, COUNT(*) AS owner_count, GROUP_CONCAT(username ORDER BY username SEPARATOR ', ') AS owners
FROM user_account
WHERE email IS NOT NULL AND email <> ''
GROUP BY LOWER(email)
HAVING COUNT(*) > 1
ORDER BY LOWER(email);

SELECT 'post_employee_requesters_employee_only' AS verification_name, ua.username, GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS roles
FROM user_account ua
LEFT JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
LEFT JOIN role r ON r.role_id = ur.role_id
WHERE ua.username IN ('gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
GROUP BY ua.user_account_id
ORDER BY ua.username;

SELECT 'post_six_requesters_department_head_role_count' AS verification_name, ua.username, COUNT(r.role_id) AS department_head_role_count
FROM user_account ua
LEFT JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
LEFT JOIN role r ON r.role_id = ur.role_id AND r.role_code = 'DEPARTMENT_HEAD'
WHERE ua.username IN ('gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
GROUP BY ua.user_account_id
ORDER BY ua.username;

SELECT 'post_department_head_mappings' AS verification_name, d.department_code, d.department_name, d.department_head_employee_reference_id, e.full_name, ua.username
FROM department_reference d
LEFT JOIN employee_reference e ON e.employee_reference_id = d.department_head_employee_reference_id
LEFT JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id
WHERE d.source_system = 'HRIS'
ORDER BY d.department_code;

SELECT 'post_requestor_user_preserved_inactive' AS verification_name, ua.user_account_id, ua.username, ua.account_status, ua.employee_reference_id, e.employee_number, e.full_name
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
WHERE ua.username = 'requestor.user';

SELECT 'post_legacy_accounts_preserved_inactive' AS verification_name, ua.user_account_id, ua.username, ua.account_status, ua.employee_reference_id, e.employee_number, e.full_name
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
WHERE ua.username IN ('facility.manager','maintenance.supervisor','technician.one','asset.custodian')
ORDER BY ua.username;

SELECT 'post_r01_r10_exist' AS verification_name, COUNT(*) AS actual_count, 10 AS expected_count
FROM facility_reservation
WHERE reservation_number IN ('RR-2026-0001','RR-2026-0002','RR-2026-0003','RR-2026-0004','RR-2026-0005','RR-2026-0006','RR-2026-0007','RR-2026-0008','RR-2026-0009','RR-2026-0010');

SELECT
    'post_r01_r10_attribution_unchanged' AS verification_name,
    fr.reservation_number,
    fr.requested_by_employee_reference_id,
    expected.expected_requested_by_employee_reference_id,
    fr.created_by_user_id,
    expected.expected_created_by_user_id,
    CASE WHEN fr.requested_by_employee_reference_id = expected.expected_requested_by_employee_reference_id AND fr.created_by_user_id = expected.expected_created_by_user_id THEN 'OK' ELSE 'CHECK_REQUIRED' END AS result
FROM facility_reservation fr
INNER JOIN (
    SELECT 'RR-2026-0001' reservation_number, 27 expected_requested_by_employee_reference_id, 25 expected_created_by_user_id UNION ALL
    SELECT 'RR-2026-0002', 30, 27 UNION ALL
    SELECT 'RR-2026-0003', 32, 29 UNION ALL
    SELECT 'RR-2026-0004', 28, 26 UNION ALL
    SELECT 'RR-2026-0005', 27, 25 UNION ALL
    SELECT 'RR-2026-0006', 29, 31 UNION ALL
    SELECT 'RR-2026-0007', 25, 23 UNION ALL
    SELECT 'RR-2026-0008', 21, 30 UNION ALL
    SELECT 'RR-2026-0009', 27, 25 UNION ALL
    SELECT 'RR-2026-0010', 30, 27
) expected ON expected.reservation_number = fr.reservation_number
ORDER BY fr.reservation_number;

SELECT 'post_request_letters_preserved' AS verification_name, fr.reservation_number, rrl.reservation_request_letter_id, rrl.uploaded_by_user_id
FROM reservation_request_letter rrl
INNER JOIN facility_reservation fr ON fr.facility_reservation_id = rrl.facility_reservation_id
WHERE fr.reservation_number IN ('RR-2026-0001','RR-2026-0002','RR-2026-0003','RR-2026-0004','RR-2026-0005','RR-2026-0006','RR-2026-0007','RR-2026-0008','RR-2026-0009','RR-2026-0010')
ORDER BY fr.reservation_number;

SELECT 'post_visitor_uat_counts' AS verification_name, (SELECT COUNT(*) FROM visit) AS visit_count, (SELECT COUNT(*) FROM visitor_visit_history) AS visitor_visit_history_count;

SELECT 'post_password_hash_presence' AS verification_name, username, user_account_id, CASE WHEN password_hash IS NULL OR password_hash = '' THEN 'CHECK_REQUIRED' ELSE 'PRESENT' END AS password_hash_status
FROM user_account
WHERE username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
ORDER BY user_account_id;

-- HostForge execution note:
-- Execute PHASE B as one SQL submission. The post-migration SELECTs above verify
-- the state within this transaction. COMMIT is intentionally part of this same
-- submission because HostForge may use a different database connection for a
-- later query. After this submission finishes, verify persistence from a fresh
-- HostForge query/session using the commented query below.

COMMIT;

-- POST-COMMIT FRESH-SESSION PERSISTENCE VERIFICATION.
-- Run the following read-only query separately in a NEW HostForge query/session:
--
-- SELECT
--     'fresh_session_persistence_check' AS verification_name,
--     SUM(CASE
--         WHEN username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
--          AND account_status = 'ACTIVE'
--          AND deleted_at IS NULL
--         THEN 1 ELSE 0 END) AS active_target_count,
--     SUM(CASE
--         WHEN username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head')
--          AND account_status = 'ACTIVE'
--          AND deleted_at IS NULL
--          AND email IS NOT NULL
--          AND email <> ''
--         THEN 1 ELSE 0 END) AS targets_with_email,
--     SUM(CASE
--         WHEN username IN ('requestor.user','facility.manager','maintenance.supervisor','technician.one','asset.custodian')
--          AND account_status = 'INACTIVE'
--         THEN 1 ELSE 0 END) AS inactive_legacy_count
-- FROM user_account
-- WHERE username IN (
--     'gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer','gsms-hr-head','gsms-it-head','gsms-fin-head','gsms-maint-head','gsms-records-head','gsms-scm-head',
--     'requestor.user','facility.manager','maintenance.supervisor','technician.one','asset.custodian'
-- );
