-- FAM staff responsibility and permission-isolation RBAC data migration.
-- Run after 2026_fam_staff_permission_isolation_schema.sql.
-- This migration does not create accounts or credentials. Provision legal.manager
-- through the approved application/admin flow first, then run this script.
-- Contains only guarded RBAC and persona data changes.

SET @required_table_count := (
  SELECT COUNT(*)
  FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name IN ('role','permission','role_permission','user_role','user_account','employee_reference','user_permission')
);
SET @auth_email_column_count := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'user_account'
    AND column_name = 'email'
);
SET @abort_message := CASE
  WHEN @required_table_count <> 7 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Required RBAC/account tables are missing'''
  WHEN @auth_email_column_count <> 1 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''user_account.email prerequisite is missing'''
  ELSE 'SELECT 1'
END;
PREPARE abort_statement FROM @abort_message;
EXECUTE abort_statement;
DEALLOCATE PREPARE abort_statement;

SET @missing_permission_count := (
  SELECT COUNT(*)
  FROM (
    SELECT 'dashboard.view' permission_code UNION ALL
    SELECT 'reservations.view' UNION ALL SELECT 'reservations.create' UNION ALL SELECT 'reservations.edit' UNION ALL SELECT 'reservations.approve' UNION ALL
    SELECT 'visitors.view' UNION ALL SELECT 'visitors.review' UNION ALL SELECT 'visitors.approve' UNION ALL SELECT 'visitors.create_walkin' UNION ALL SELECT 'visitors.checkin' UNION ALL SELECT 'visitors.checkout' UNION ALL
    SELECT 'contract.view' UNION ALL SELECT 'contract.create' UNION ALL SELECT 'contract.edit' UNION ALL SELECT 'contract.review' UNION ALL SELECT 'contract.approve' UNION ALL SELECT 'contract.activate' UNION ALL SELECT 'contract.terminate' UNION ALL SELECT 'contract.archive' UNION ALL
    SELECT 'legal.view' UNION ALL SELECT 'legal.create' UNION ALL SELECT 'legal.edit' UNION ALL SELECT 'legal.assign' UNION ALL SELECT 'legal.resolve' UNION ALL SELECT 'legal.close' UNION ALL SELECT 'legal.manage' UNION ALL
    SELECT 'records.view' UNION ALL SELECT 'records.create' UNION ALL SELECT 'records.edit' UNION ALL
    SELECT 'retention.view' UNION ALL SELECT 'retention.manage_schedules' UNION ALL SELECT 'retention.assign' UNION ALL SELECT 'retention.review' UNION ALL SELECT 'retention.extend' UNION ALL SELECT 'retention.archive' UNION ALL SELECT 'retention.dispose' UNION ALL SELECT 'retention.legal_hold' UNION ALL
    SELECT 'document_templates.view' UNION ALL SELECT 'document_templates.create' UNION ALL SELECT 'document_templates.edit' UNION ALL SELECT 'document_templates.retire'
  ) required_permission
  LEFT JOIN permission p ON p.permission_code = required_permission.permission_code
  WHERE p.permission_id IS NULL
);
SET @role_precondition_count := (
  SELECT COUNT(*)
  FROM (
    SELECT expected.role_code, COUNT(r.role_id) actual_count
    FROM (SELECT 'FAM_SUPER_ADMIN' role_code UNION ALL SELECT 'FAM_ADMIN' UNION ALL SELECT 'FAM_STAFF') expected
    LEFT JOIN role r ON r.role_code = expected.role_code
    GROUP BY expected.role_code
  ) role_counts
  WHERE actual_count <> 1
);
SET @expected_account_precondition_count := (
  SELECT COUNT(*)
  FROM (
    SELECT expected.username, COUNT(ua.user_account_id) actual_count
    FROM (
      SELECT 'gsms-super-admin' username UNION ALL SELECT 'gsms-fam-admin' UNION ALL
      SELECT 'reservation.officer' UNION ALL SELECT 'records.officer' UNION ALL
      SELECT 'facility.manager' UNION ALL SELECT 'maintenance.supervisor' UNION ALL
      SELECT 'technician.one' UNION ALL SELECT 'asset.custodian' UNION ALL
      SELECT 'legal.manager'
    ) expected
    LEFT JOIN user_account ua ON ua.username = expected.username AND ua.deleted_at IS NULL
    GROUP BY expected.username
  ) account_counts
  WHERE actual_count <> 1
);
SET @legal_manager_prereq_count := (
  SELECT COUNT(*)
  FROM user_account ua
  INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
  WHERE ua.username = 'legal.manager'
    AND ua.email = 'legal.manager@greatsolomonmpservices.com'
    AND ua.password_hash IS NOT NULL
    AND ua.password_hash <> ''
    AND ua.account_status = 'ACTIVE'
    AND ua.deleted_at IS NULL
    AND e.employee_number = 'EMP-FAM-LEGAL-MANAGER'
    AND e.full_name = 'Legal Manager'
    AND e.position_title = 'Legal Manager'
    AND e.email_address = 'legal.manager@greatsolomonmpservices.com'
    AND e.employment_status = 'ACTIVE'
    AND e.deleted_at IS NULL
);
SET @legal_manager_collision_count := (
  SELECT COUNT(*)
  FROM user_account ua
  LEFT JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
  WHERE (ua.username <> 'legal.manager' AND LOWER(ua.email) = 'legal.manager@greatsolomonmpservices.com')
     OR (e.employee_number <> 'EMP-FAM-LEGAL-MANAGER' AND LOWER(e.email_address) = 'legal.manager@greatsolomonmpservices.com')
     OR (ua.username = 'legal.manager' AND e.employee_number <> 'EMP-FAM-LEGAL-MANAGER')
);
SET @direct_grant_target_count := (
  SELECT COUNT(DISTINCT ua.username)
  FROM user_account ua
  WHERE ua.username IN ('reservation.officer','legal.manager','records.officer')
    AND ua.account_status = 'ACTIVE'
    AND ua.deleted_at IS NULL
);
SET @duplicate_direct_grant_count := (
  SELECT COUNT(*)
  FROM (
    SELECT up.user_account_id, up.permission_id
    FROM user_permission up
    GROUP BY up.user_account_id, up.permission_id
    HAVING COUNT(*) > 1
  ) duplicate_grants
);

SET @abort_message := CASE
  WHEN @missing_permission_count <> 0 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Missing required permission codes for FAM staff isolation'''
  WHEN @role_precondition_count <> 0 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Required FAM role codes are missing or non-unique'''
  WHEN @expected_account_precondition_count <> 0 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Expected persona accounts are missing or non-unique'''
  WHEN @legal_manager_prereq_count <> 1 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''legal.manager must be pre-provisioned as an active Legal Manager account with email and credential'''
  WHEN @legal_manager_collision_count <> 0 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Legal Manager identity collides with another account or employee'''
  WHEN @direct_grant_target_count <> 3 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Direct-grant target accounts are not exactly active and unique'''
  WHEN @duplicate_direct_grant_count <> 0 THEN 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Duplicate direct user_permission grants must be resolved before migration'''
  ELSE 'SELECT 1'
END;
PREPARE abort_statement FROM @abort_message;
EXECUTE abort_statement;
DEALLOCATE PREPARE abort_statement;

START TRANSACTION;

UPDATE role
SET role_name = CASE role_code WHEN 'FAM_SUPER_ADMIN' THEN 'Super Admin' WHEN 'FAM_ADMIN' THEN 'Admin' ELSE role_name END,
  description = CASE role_code WHEN 'FAM_SUPER_ADMIN' THEN 'Highest FAM application authority.' WHEN 'FAM_ADMIN' THEN 'Cross-module operational administrator with broad oversight.' ELSE description END,
  status = 'ACTIVE'
WHERE role_code IN ('FAM_SUPER_ADMIN','FAM_ADMIN')
  AND (
    status <> 'ACTIVE'
    OR (role_code = 'FAM_SUPER_ADMIN' AND (role_name <> 'Super Admin' OR description <> 'Highest FAM application authority.'))
    OR (role_code = 'FAM_ADMIN' AND (role_name <> 'Admin' OR description <> 'Cross-module operational administrator with broad oversight.'))
  );

UPDATE employee_reference e
INNER JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id
SET e.full_name = CASE ua.username
    WHEN 'gsms-super-admin' THEN 'Super Admin'
    WHEN 'gsms-fam-admin' THEN 'Admin'
    WHEN 'reservation.officer' THEN 'Facilities Operations Officer'
    WHEN 'records.officer' THEN 'Records Officer'
    ELSE e.full_name
  END,
  e.position_title = CASE ua.username
    WHEN 'gsms-super-admin' THEN 'Super Admin'
    WHEN 'gsms-fam-admin' THEN 'Admin'
    WHEN 'reservation.officer' THEN 'Facilities Operations Officer'
    WHEN 'records.officer' THEN 'Records Officer'
    ELSE e.position_title
  END,
  e.employment_status = 'ACTIVE',
  e.deleted_at = NULL,
  e.updated_at = NOW()
WHERE ua.username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer')
  AND ua.deleted_at IS NULL
  AND (
    e.deleted_at IS NOT NULL
    OR e.employment_status <> 'ACTIVE'
    OR (ua.username = 'gsms-super-admin' AND (e.full_name <> 'Super Admin' OR e.position_title <> 'Super Admin'))
    OR (ua.username = 'gsms-fam-admin' AND (e.full_name <> 'Admin' OR e.position_title <> 'Admin'))
    OR (ua.username = 'reservation.officer' AND (e.full_name <> 'Facilities Operations Officer' OR e.position_title <> 'Facilities Operations Officer'))
    OR (ua.username = 'records.officer' AND (e.full_name <> 'Records Officer' OR e.position_title <> 'Records Officer'))
  );

UPDATE user_account
SET account_status = 'ACTIVE', deleted_at = NULL, updated_at = NOW()
WHERE username IN ('gsms-super-admin','gsms-fam-admin','reservation.officer','records.officer')
  AND deleted_at IS NULL
  AND account_status <> 'ACTIVE';

INSERT IGNORE INTO user_role (user_account_id, role_id, assigned_by_user_id, assigned_at, expires_at)
SELECT ua.user_account_id, r.role_id, admin.user_account_id, NOW(), NULL
FROM user_account ua
INNER JOIN role r ON r.role_code = 'FAM_STAFF' AND r.status = 'ACTIVE'
LEFT JOIN user_account admin ON admin.username = 'gsms-super-admin'
WHERE ua.username IN ('reservation.officer','legal.manager','records.officer')
  AND ua.account_status = 'ACTIVE'
  AND ua.deleted_at IS NULL;

DELETE rp
FROM role_permission rp
INNER JOIN role r ON r.role_id = rp.role_id
INNER JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'FAM_STAFF'
  AND p.permission_code <> 'dashboard.view';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
INNER JOIN permission p ON p.permission_code = 'dashboard.view'
WHERE r.role_code = 'FAM_STAFF'
  AND r.status = 'ACTIVE';

DELETE rp
FROM role_permission rp
INNER JOIN role r ON r.role_id = rp.role_id
INNER JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code <> 'FAM_SUPER_ADMIN'
  AND p.permission_code LIKE 'document_templates.%';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
INNER JOIN permission p ON p.permission_code IN ('document_templates.view','document_templates.create','document_templates.edit','document_templates.retire')
WHERE r.role_code = 'FAM_SUPER_ADMIN'
  AND r.status = 'ACTIVE';

DELETE rp
FROM role_permission rp
INNER JOIN role r ON r.role_id = rp.role_id
INNER JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'FAM_ADMIN'
  AND (p.module_code = 'administration' OR p.permission_code LIKE 'administration.%');

DELETE up
FROM user_permission up
INNER JOIN user_account ua ON ua.user_account_id = up.user_account_id
INNER JOIN permission p ON p.permission_id = up.permission_id
LEFT JOIN (
  SELECT 'reservation.officer' username, 'reservations.view' permission_code UNION ALL SELECT 'reservation.officer','reservations.create' UNION ALL SELECT 'reservation.officer','reservations.edit' UNION ALL SELECT 'reservation.officer','reservations.approve' UNION ALL
  SELECT 'reservation.officer','visitors.view' UNION ALL SELECT 'reservation.officer','visitors.review' UNION ALL SELECT 'reservation.officer','visitors.approve' UNION ALL SELECT 'reservation.officer','visitors.create_walkin' UNION ALL SELECT 'reservation.officer','visitors.checkin' UNION ALL SELECT 'reservation.officer','visitors.checkout' UNION ALL
  SELECT 'legal.manager','contract.view' UNION ALL SELECT 'legal.manager','contract.create' UNION ALL SELECT 'legal.manager','contract.edit' UNION ALL SELECT 'legal.manager','contract.review' UNION ALL SELECT 'legal.manager','contract.approve' UNION ALL SELECT 'legal.manager','contract.activate' UNION ALL SELECT 'legal.manager','contract.terminate' UNION ALL SELECT 'legal.manager','contract.archive' UNION ALL
  SELECT 'legal.manager','legal.view' UNION ALL SELECT 'legal.manager','legal.create' UNION ALL SELECT 'legal.manager','legal.edit' UNION ALL SELECT 'legal.manager','legal.assign' UNION ALL SELECT 'legal.manager','legal.resolve' UNION ALL SELECT 'legal.manager','legal.close' UNION ALL SELECT 'legal.manager','legal.manage' UNION ALL
  SELECT 'records.officer','records.view' UNION ALL SELECT 'records.officer','records.create' UNION ALL SELECT 'records.officer','records.edit' UNION ALL
  SELECT 'records.officer','retention.view' UNION ALL SELECT 'records.officer','retention.manage_schedules' UNION ALL SELECT 'records.officer','retention.assign' UNION ALL SELECT 'records.officer','retention.review' UNION ALL SELECT 'records.officer','retention.extend' UNION ALL SELECT 'records.officer','retention.archive' UNION ALL SELECT 'records.officer','retention.dispose' UNION ALL SELECT 'records.officer','retention.legal_hold'
) allowed_grant ON allowed_grant.username = ua.username AND allowed_grant.permission_code = p.permission_code
WHERE ua.username IN ('reservation.officer','legal.manager','records.officer','facility.manager','maintenance.supervisor','technician.one','asset.custodian')
  AND allowed_grant.permission_code IS NULL;

INSERT IGNORE INTO user_permission (user_account_id, permission_id, granted_by_user_id)
SELECT ua.user_account_id, p.permission_id, admin.user_account_id
FROM user_account ua
INNER JOIN permission p ON p.permission_code IN ('reservations.view','reservations.create','reservations.edit','reservations.approve','visitors.view','visitors.review','visitors.approve','visitors.create_walkin','visitors.checkin','visitors.checkout')
LEFT JOIN user_account admin ON admin.username = 'gsms-super-admin'
WHERE ua.username = 'reservation.officer' AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL;

INSERT IGNORE INTO user_permission (user_account_id, permission_id, granted_by_user_id)
SELECT ua.user_account_id, p.permission_id, admin.user_account_id
FROM user_account ua
INNER JOIN permission p ON p.permission_code IN ('contract.view','contract.create','contract.edit','contract.review','contract.approve','contract.activate','contract.terminate','contract.archive','legal.view','legal.create','legal.edit','legal.assign','legal.resolve','legal.close','legal.manage')
LEFT JOIN user_account admin ON admin.username = 'gsms-super-admin'
WHERE ua.username = 'legal.manager' AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL;

INSERT IGNORE INTO user_permission (user_account_id, permission_id, granted_by_user_id)
SELECT ua.user_account_id, p.permission_id, admin.user_account_id
FROM user_account ua
INNER JOIN permission p ON p.permission_code IN ('records.view','records.create','records.edit','retention.view','retention.manage_schedules','retention.assign','retention.review','retention.extend','retention.archive','retention.dispose','retention.legal_hold')
LEFT JOIN user_account admin ON admin.username = 'gsms-super-admin'
WHERE ua.username = 'records.officer' AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL;

UPDATE user_account ua
SET ua.account_status = 'INACTIVE', ua.updated_at = NOW()
WHERE ua.username IN ('facility.manager','maintenance.supervisor','technician.one','asset.custodian')
  AND ua.deleted_at IS NULL
  AND ua.account_status <> 'INACTIVE';

UPDATE employee_reference e
INNER JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id
SET e.employment_status = 'INACTIVE', e.updated_at = NOW()
WHERE ua.username IN ('facility.manager','maintenance.supervisor','technician.one','asset.custodian')
  AND e.deleted_at IS NULL
  AND e.employment_status <> 'INACTIVE';

SELECT 'fam_staff_role_baseline' AS verification_name, r.role_code,
  GROUP_CONCAT(p.permission_code ORDER BY p.permission_code SEPARATOR ',') AS permissions
FROM role r
LEFT JOIN role_permission rp ON rp.role_id = r.role_id
LEFT JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'FAM_STAFF'
GROUP BY r.role_code;

SELECT 'staff_user_permission_matrix' AS verification_name, ua.username, ua.account_status,
  e.employee_reference_id, e.full_name, e.position_title,
  GROUP_CONCAT(p.permission_code ORDER BY p.permission_code SEPARATOR ',') AS permissions
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
LEFT JOIN user_permission up ON up.user_account_id = ua.user_account_id
LEFT JOIN permission p ON p.permission_id = up.permission_id
WHERE ua.username IN ('reservation.officer','legal.manager','records.officer','facility.manager','maintenance.supervisor','technician.one','asset.custodian')
GROUP BY ua.user_account_id, ua.username, ua.account_status, e.employee_reference_id, e.full_name, e.position_title
ORDER BY FIELD(ua.username,'reservation.officer','legal.manager','records.officer','facility.manager','maintenance.supervisor','technician.one','asset.custodian');

COMMIT;
