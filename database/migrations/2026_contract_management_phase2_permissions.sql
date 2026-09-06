-- Contract Management Phase 2 permissions.

INSERT INTO permission (permission_code, permission_name, module_code, description)
VALUES
('contract.view', 'View Contracts', 'contract', 'View Contract Management records.'),
('contract.create', 'Create Contracts', 'contract', 'Create draft Contract Management records.'),
('contract.edit', 'Edit Contracts', 'contract', 'Edit draft Contract Management records and submit/cancel drafts.'),
('contract.review', 'Review Contracts', 'contract', 'Review Contract Management records and route lifecycle review states.'),
('contract.approve', 'Approve Contracts', 'contract', 'Approve or reject Contract Management lifecycle approval in Phase 2.'),
('contract.activate', 'Activate Contracts', 'contract', 'Activate approved contracts after execution checks.'),
('contract.terminate', 'Terminate Contracts', 'contract', 'Terminate active contracts with required reason and date.'),
('contract.archive', 'Archive Contracts', 'contract', 'Archive expired or terminated Contract Management records.'),
('contract.manage', 'Manage Contracts', 'contract', 'Administer Contract Management records and lifecycle.')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  module_code = VALUES(module_code),
  description = VALUES(description);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code LIKE 'contract.%'
WHERE r.role_code IN ('FAM_SUPER_ADMIN', 'FAM_ADMIN');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN ('contract.view','contract.create','contract.edit','contract.review','contract.activate','contract.terminate','contract.archive')
WHERE r.role_code = 'FAM_STAFF';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code = 'contract.view'
WHERE r.role_code = 'FAM_STAFF';
