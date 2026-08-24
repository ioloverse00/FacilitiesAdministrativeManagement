START TRANSACTION;

INSERT INTO role (role_code, role_name, description, status)
VALUES ('DEPARTMENT_HEAD_EMPLOYEE', 'Department Head Employee Portal', 'External FAM-facing department head persona for Employee Portal self-service and assigned workflow tasks.', 'ACTIVE')
ON DUPLICATE KEY UPDATE
  role_name = VALUES(role_name),
  description = VALUES(description),
  status = 'ACTIVE';

INSERT INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN (
  'dashboard.view',
  'facility_requests.view',
  'facility_requests.create',
  'reservations.view',
  'reservations.create'
)
WHERE r.role_code = 'DEPARTMENT_HEAD_EMPLOYEE'
  AND NOT EXISTS (
    SELECT 1
    FROM role_permission existing
    WHERE existing.role_id = r.role_id
      AND existing.permission_id = p.permission_id
  );

UPDATE user_account SET username = 'gsms-super-admin' WHERE username = 'admin';
UPDATE user_account SET username = 'gsms-fam-admin' WHERE username = 'fam.admin';
UPDATE user_account SET username = 'gsms-fin-head' WHERE username = 'finance.approver';
UPDATE user_account SET username = 'gsms-scm-head' WHERE username = 'procurement.officer';

INSERT INTO employee_reference (
  external_employee_id,
  employee_number,
  full_name,
  department_reference_id,
  position_title,
  email_address,
  contact_number,
  employment_status,
  source_system,
  sync_status,
  last_synced_at,
  external_updated_at
)
SELECT
  'EXT-EMP-HR-HEAD',
  'EMP-2026-HR01',
  'HR Department Head',
  d.department_reference_id,
  'Human Resources Head',
  'hr.head@example-agency.test',
  NULL,
  'ACTIVE',
  'UAT',
  'SYNCED',
  NOW(),
  NOW()
FROM department_reference d
WHERE d.department_code = 'DEP-HR'
  AND NOT EXISTS (
    SELECT 1 FROM employee_reference e WHERE e.employee_number = 'EMP-2026-HR01'
  );

INSERT INTO user_account (
  employee_reference_id,
  username,
  password_hash,
  account_status,
  failed_login_count,
  locked_until,
  created_at,
  updated_at
)
SELECT
  e.employee_reference_id,
  'gsms-hr-head',
  source.password_hash,
  'ACTIVE',
  0,
  NULL,
  NOW(),
  NOW()
FROM employee_reference e
JOIN user_account source ON source.username = 'gsms-fin-head'
WHERE e.employee_number = 'EMP-2026-HR01'
  AND NOT EXISTS (
    SELECT 1 FROM user_account u WHERE u.username = 'gsms-hr-head'
  );

UPDATE department_reference d
JOIN employee_reference e ON e.employee_number = 'EMP-2026-HR01'
SET d.department_head_employee_reference_id = e.employee_reference_id
WHERE d.department_code = 'DEP-HR'
  AND d.department_head_employee_reference_id IS NULL;

INSERT INTO user_role (user_account_id, role_id, assigned_by_user_id)
SELECT u.user_account_id, r.role_id, admin.user_account_id
FROM user_account u
JOIN role r ON r.role_code = 'DEPARTMENT_HEAD_EMPLOYEE'
LEFT JOIN user_account admin ON admin.username = 'gsms-super-admin'
WHERE u.username IN ('gsms-fin-head', 'gsms-hr-head', 'gsms-scm-head')
  AND NOT EXISTS (
    SELECT 1
    FROM user_role existing
    WHERE existing.user_account_id = u.user_account_id
      AND existing.role_id = r.role_id
      AND (existing.expires_at IS NULL OR existing.expires_at > NOW())
  );

COMMIT;
