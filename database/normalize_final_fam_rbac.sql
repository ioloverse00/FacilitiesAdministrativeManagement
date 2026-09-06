-- DEVELOPMENT/UAT ONLY
-- DO NOT RUN AGAINST PRODUCTION.
-- Final FAM five-role RBAC/persona normalization for a local XAMPP/UAT database.
-- Review this script before running. It does not create passwords or modify password hashes.

START TRANSACTION;

-- 1. Fail early if required RBAC/auth tables are missing.
SET @missing_required_tables := (
  SELECT COUNT(*)
  FROM (
    SELECT 'role' table_name UNION ALL
    SELECT 'permission' UNION ALL
    SELECT 'role_permission' UNION ALL
    SELECT 'user_account' UNION ALL
    SELECT 'user_role' UNION ALL
    SELECT 'employee_reference' UNION ALL
    SELECT 'department_reference'
  ) required
  LEFT JOIN information_schema.tables t
    ON BINARY t.table_schema = BINARY DATABASE()
   AND BINARY t.table_name = BINARY required.table_name
  WHERE t.table_name IS NULL
);

SET @abort_message := IF(@missing_required_tables = 0, 'SELECT 1', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Missing required FAM RBAC/auth tables''');
PREPARE abort_statement FROM @abort_message;
EXECUTE abort_statement;
DEALLOCATE PREPARE abort_statement;

-- 2. Canonical final roles.
INSERT INTO role (role_code, role_name, description, status)
VALUES
('FAM_SUPER_ADMIN','FAM Super Administrator','Highest FAM application authority.','ACTIVE'),
('FAM_ADMIN','FAM Department Head','Department head of the FAM department with broad operational oversight.','ACTIVE'),
('FAM_STAFF','FAM Staff','Ordinary FAM operational employee.','ACTIVE'),
('DEPARTMENT_HEAD','Department Head','Head of a non-FAM department with department-scoped workflow access.','ACTIVE'),
('EMPLOYEE','Employee','Ordinary employee self-service access.','ACTIVE')
ON DUPLICATE KEY UPDATE
  role_name = VALUES(role_name),
  description = VALUES(description),
  status = 'ACTIVE';

-- 3. Permissions currently required by backend or active employee portal behavior.
INSERT INTO permission (permission_code, permission_name, module_code, description)
VALUES
('employee_portal.view','View Employee Portal','employee_portal','Access the employee self-service portal.'),
('facility_requests.view_own','View Own Facility Requests','facility_requests','View facility requests owned by the authenticated employee.'),
('reservations.view_own','View Own Reservations','reservations','View room reservations owned by the authenticated employee.'),
('notifications.view_own','View Own Notifications','notifications','View notifications for the authenticated user.'),
('profile.view_own','View Own Profile','profile','View the authenticated employee profile.'),
('document_templates.view','View Document Templates','document_templates','View the governed document template library.'),
('document_templates.create','Create Document Templates','document_templates','Create active document templates.'),
('document_templates.edit','Edit Document Templates','document_templates','Create new active document template versions.'),
('document_templates.retire','Retire Document Templates','document_templates','Retire active document templates.')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  module_code = VALUES(module_code),
  description = VALUES(description);

CREATE TEMPORARY TABLE final_role_permission (
  -- Explicit collation avoids #1267 when local/UAT schemas contain mixed utf8mb4 collations.
  role_code VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  permission_code VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (role_code, permission_code)
) ENGINE=Memory;

INSERT IGNORE INTO final_role_permission (role_code, permission_code)
SELECT 'FAM_SUPER_ADMIN', p.permission_code
FROM permission p
WHERE p.permission_code IN (
    'employee_portal.view',
    'facility_requests.view_own',
    'reservations.view_own',
    'notifications.view_own',
    'profile.view_own'
  )
  OR p.module_code IN ('dashboard','facility_requests','maintenance','assets','reservations','procurement','records','reports','administration')
  OR p.permission_code LIKE 'contract.%'
  OR p.permission_code IN ('document_templates.view','document_templates.create','document_templates.edit','document_templates.retire')
  OR p.permission_code LIKE 'legal.%'
  OR p.permission_code LIKE 'retention.%'
  OR p.permission_code LIKE 'visitors.%';

INSERT IGNORE INTO final_role_permission (role_code, permission_code)
SELECT 'FAM_ADMIN', p.permission_code
FROM permission p
WHERE (
    p.permission_code IN ('dashboard.view','reports.view','reports.export')
    OR (p.module_code IN ('facility_requests','maintenance','assets','reservations','procurement','records')
      AND SUBSTRING_INDEX(p.permission_code,'.',-1) IN ('view','create','edit','assign','approve','complete','verify','export','manage'))
    OR p.permission_code LIKE 'contract.%'
    OR p.permission_code IN ('document_templates.view','document_templates.create','document_templates.edit','document_templates.retire')
    OR p.permission_code LIKE 'legal.%'
    OR p.permission_code LIKE 'retention.%'
    OR p.permission_code LIKE 'visitors.%'
  )
  AND p.module_code <> 'administration'
  AND p.permission_code NOT LIKE 'administration.%';

INSERT IGNORE INTO final_role_permission (role_code, permission_code)
VALUES
('FAM_STAFF','dashboard.view'),
('FAM_STAFF','reports.view'),
('FAM_STAFF','facility_requests.view'),
('FAM_STAFF','facility_requests.create'),
('FAM_STAFF','facility_requests.edit'),
('FAM_STAFF','facility_requests.assign'),
('FAM_STAFF','facility_requests.complete'),
('FAM_STAFF','facility_requests.verify'),
('FAM_STAFF','maintenance.view'),
('FAM_STAFF','maintenance.edit'),
('FAM_STAFF','maintenance.complete'),
('FAM_STAFF','assets.view'),
('FAM_STAFF','assets.create'),
('FAM_STAFF','assets.edit'),
('FAM_STAFF','reservations.view'),
('FAM_STAFF','reservations.create'),
('FAM_STAFF','reservations.edit'),
('FAM_STAFF','visitors.view'),
('FAM_STAFF','visitors.review'),
('FAM_STAFF','visitors.create_walkin'),
('FAM_STAFF','visitors.checkin'),
('FAM_STAFF','visitors.checkout'),
('FAM_STAFF','procurement.view'),
('FAM_STAFF','procurement.create'),
('FAM_STAFF','procurement.edit'),
('FAM_STAFF','records.view'),
('FAM_STAFF','records.create'),
('FAM_STAFF','records.edit'),
('FAM_STAFF','contract.view'),
('FAM_STAFF','contract.create'),
('FAM_STAFF','contract.edit'),
('FAM_STAFF','contract.review'),
('FAM_STAFF','retention.view'),
('FAM_STAFF','retention.review'),
('DEPARTMENT_HEAD','employee_portal.view'),
('DEPARTMENT_HEAD','facility_requests.create'),
('DEPARTMENT_HEAD','facility_requests.view_own'),
('DEPARTMENT_HEAD','reservations.create'),
('DEPARTMENT_HEAD','reservations.view_own'),
('DEPARTMENT_HEAD','notifications.view_own'),
('DEPARTMENT_HEAD','profile.view_own'),
('EMPLOYEE','employee_portal.view'),
('EMPLOYEE','facility_requests.create'),
('EMPLOYEE','facility_requests.view_own'),
('EMPLOYEE','reservations.create'),
('EMPLOYEE','reservations.view_own'),
('EMPLOYEE','notifications.view_own'),
('EMPLOYEE','profile.view_own');

-- 4. Replace final-role permissions with the approved explicit matrix.
DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
WHERE r.role_code IN ('FAM_SUPER_ADMIN','FAM_ADMIN','FAM_STAFF','DEPARTMENT_HEAD','EMPLOYEE');

INSERT INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM final_role_permission frp
JOIN role r ON BINARY r.role_code = BINARY frp.role_code
JOIN permission p ON BINARY p.permission_code = BINARY frp.permission_code;

-- Narrow cleanup for existing local/UAT databases that previously granted
-- System Configuration authority to FAM_ADMIN.
DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE BINARY r.role_code = BINARY 'FAM_ADMIN'
  AND (p.module_code = 'administration' OR p.permission_code LIKE 'administration.%');

-- Legal Management is limited to FAM_SUPER_ADMIN and FAM_ADMIN.
DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE BINARY r.role_code IN (BINARY 'FAM_STAFF', BINARY 'DEPARTMENT_HEAD', BINARY 'EMPLOYEE')
  AND p.permission_code LIKE 'legal.%';

DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE BINARY r.role_code IN (BINARY 'FAM_STAFF', BINARY 'DEPARTMENT_HEAD', BINARY 'EMPLOYEE')
  AND p.permission_code LIKE 'document_templates.%';

-- 5. Normalize known development/UAT demo user-role mappings without changing passwords.
CREATE TEMPORARY TABLE demo_user_role_normalization (
  -- Match the comparison collation used against existing user/role text columns.
  username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  role_code VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (username, role_code)
) ENGINE=Memory;

INSERT IGNORE INTO demo_user_role_normalization (username, role_code)
VALUES
('gsms-super-admin','FAM_SUPER_ADMIN'),
('gsms-fam-admin','FAM_ADMIN'),
('facility.manager','FAM_STAFF'),
('maintenance.supervisor','FAM_STAFF'),
('technician.one','FAM_STAFF'),
('asset.custodian','FAM_STAFF'),
('reservation.officer','FAM_STAFF'),
('records.officer','FAM_STAFF'),
('gsms-scm-head','DEPARTMENT_HEAD'),
('gsms-fin-head','DEPARTMENT_HEAD'),
('gsms-hr-head','DEPARTMENT_HEAD'),
('requestor.user','EMPLOYEE');

DELETE ur
FROM user_role ur
JOIN user_account u ON u.user_account_id = ur.user_account_id
JOIN demo_user_role_normalization durn ON BINARY durn.username = BINARY u.username;

INSERT INTO user_role (user_account_id, role_id, assigned_by_user_id)
SELECT u.user_account_id, r.role_id, admin.user_account_id
FROM demo_user_role_normalization durn
JOIN user_account u ON BINARY u.username = BINARY durn.username
JOIN role r ON BINARY r.role_code = BINARY durn.role_code
LEFT JOIN user_account admin ON BINARY admin.username = BINARY 'gsms-super-admin';

-- 6. Ensure known department-head demo accounts are connected to department-head FKs where the current reference data supports it.
UPDATE department_reference d
JOIN employee_reference e ON e.employee_reference_id = d.department_head_employee_reference_id
JOIN user_account u ON u.employee_reference_id = e.employee_reference_id
JOIN demo_user_role_normalization durn
  ON BINARY durn.username = BINARY u.username
 AND BINARY durn.role_code = BINARY 'DEPARTMENT_HEAD'
SET d.status = d.status
WHERE d.status = 'ACTIVE';

-- 7. Retire obsolete role grants and roles after demo mappings are normalized.
DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
WHERE r.role_code IN (
  'SYSTEM_ADMIN',
  'FACILITY_MANAGER',
  'MAINTENANCE_SUPERVISOR',
  'TECHNICIAN',
  'ASSET_CUSTODIAN',
  'RESERVATION_OFFICER',
  'PROCUREMENT_OFFICER',
  'FINANCE_APPROVER',
  'RECORDS_OFFICER',
  'REQUESTOR',
  'APPROVER',
  'AUDITOR',
  'DEPARTMENT_HEAD_EMPLOYEE'
);

DELETE ur
FROM user_role ur
JOIN role r ON r.role_id = ur.role_id
WHERE r.role_code IN (
  'SYSTEM_ADMIN',
  'FACILITY_MANAGER',
  'MAINTENANCE_SUPERVISOR',
  'TECHNICIAN',
  'ASSET_CUSTODIAN',
  'RESERVATION_OFFICER',
  'PROCUREMENT_OFFICER',
  'FINANCE_APPROVER',
  'RECORDS_OFFICER',
  'REQUESTOR',
  'APPROVER',
  'AUDITOR',
  'DEPARTMENT_HEAD_EMPLOYEE'
);

DELETE FROM role
WHERE role_code IN (
  'SYSTEM_ADMIN',
  'FACILITY_MANAGER',
  'MAINTENANCE_SUPERVISOR',
  'TECHNICIAN',
  'ASSET_CUSTODIAN',
  'RESERVATION_OFFICER',
  'PROCUREMENT_OFFICER',
  'FINANCE_APPROVER',
  'RECORDS_OFFICER',
  'REQUESTOR',
  'APPROVER',
  'AUDITOR',
  'DEPARTMENT_HEAD_EMPLOYEE'
);

-- 8. Local production-candidate cleanup of disposable history/state.
-- Use DELETE instead of TRUNCATE so this remains transactional and FK-aware.
DELETE FROM notification;
DELETE FROM workflow_task;
DELETE FROM approval_step;
DELETE FROM approval_request;
DELETE FROM integration_outbox;
DELETE FROM activity_event;
DELETE FROM audit_log;

COMMIT;

-- Post-run verification queries.
SELECT role_code, role_name, status
FROM role
ORDER BY FIELD(role_code, 'FAM_SUPER_ADMIN','FAM_ADMIN','FAM_STAFF','DEPARTMENT_HEAD','EMPLOYEE'), role_code;

SELECT r.role_code, COUNT(rp.permission_id) AS permission_count
FROM role r
LEFT JOIN role_permission rp ON rp.role_id = r.role_id
WHERE r.role_code IN ('FAM_SUPER_ADMIN','FAM_ADMIN','FAM_STAFF','DEPARTMENT_HEAD','EMPLOYEE')
GROUP BY r.role_code
ORDER BY FIELD(r.role_code, 'FAM_SUPER_ADMIN','FAM_ADMIN','FAM_STAFF','DEPARTMENT_HEAD','EMPLOYEE');

SELECT u.username, r.role_code
FROM user_account u
JOIN user_role ur ON ur.user_account_id = u.user_account_id
JOIN role r ON r.role_id = ur.role_id
WHERE u.username IN (
  'gsms-super-admin',
  'gsms-fam-admin',
  'facility.manager',
  'maintenance.supervisor',
  'technician.one',
  'asset.custodian',
  'reservation.officer',
  'records.officer',
  'gsms-scm-head',
  'gsms-fin-head',
  'gsms-hr-head',
  'requestor.user'
)
ORDER BY u.username, r.role_code;

SELECT r.role_code AS remaining_obsolete_role
FROM role r
WHERE r.role_code IN (
  'SYSTEM_ADMIN',
  'FACILITY_MANAGER',
  'MAINTENANCE_SUPERVISOR',
  'TECHNICIAN',
  'ASSET_CUSTODIAN',
  'RESERVATION_OFFICER',
  'PROCUREMENT_OFFICER',
  'FINANCE_APPROVER',
  'RECORDS_OFFICER',
  'REQUESTOR',
  'APPROVER',
  'AUDITOR',
  'DEPARTMENT_HEAD_EMPLOYEE'
);
