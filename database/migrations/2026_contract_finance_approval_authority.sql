INSERT INTO permission (permission_code, permission_name, module_code, description)
VALUES
('budget.approve', 'Approve Budgets', 'budget', 'Approve budget-linked Contract Management approval steps.')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  module_code = VALUES(module_code),
  description = VALUES(description);

INSERT INTO role (role_code, role_name, description, status)
VALUES
('FINANCE_APPROVER', 'Finance Approver', 'Reviews and approves budget-linked contract approval steps.', 'ACTIVE')
ON DUPLICATE KEY UPDATE
  role_name = VALUES(role_name),
  description = VALUES(description),
  status = VALUES(status);

INSERT INTO user_account (employee_reference_id, username, password_hash, account_status, last_login_at)
SELECT e.employee_reference_id, 'gsms-fin-head', '$2y$10$abcdefghijklmnopqrstuuM6Dks4xK8vRNhV0N2mP1xG1Jv6bqF9y', 'ACTIVE', NULL
FROM employee_reference e
WHERE e.employee_number = 'EMP-2026-0013'
  AND e.employment_status = 'ACTIVE'
  AND e.deleted_at IS NULL
ON DUPLICATE KEY UPDATE
  employee_reference_id = VALUES(employee_reference_id),
  account_status = VALUES(account_status);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code = 'budget.approve'
WHERE r.role_code = 'FINANCE_APPROVER';

INSERT IGNORE INTO user_role (user_account_id, role_id, assigned_by_user_id)
SELECT u.user_account_id, r.role_id, admin.user_account_id
FROM user_account u
JOIN employee_reference e ON e.employee_reference_id = u.employee_reference_id
JOIN role r ON r.role_code = 'FINANCE_APPROVER'
JOIN user_account admin ON admin.username = 'gsms-super-admin'
WHERE e.employee_number = 'EMP-2026-0013'
  AND u.username = 'gsms-fin-head';
