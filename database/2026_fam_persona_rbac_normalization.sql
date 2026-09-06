START TRANSACTION;

-- Historical persona/RBAC normalization migration, updated to the final
-- five-role model. For existing local UAT databases, prefer reviewing and
-- running database/normalize_final_fam_rbac.sql.

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

INSERT INTO permission (permission_code, permission_name, module_code, description)
VALUES
('employee_portal.view','View Employee Portal','employee_portal','Access the employee self-service portal.'),
('facility_requests.view_own','View Own Facility Requests','facility_requests','View facility requests owned by the authenticated employee.'),
('reservations.view_own','View Own Reservations','reservations','View room reservations owned by the authenticated employee.'),
('notifications.view_own','View Own Notifications','notifications','View notifications for the authenticated user.'),
('profile.view_own','View Own Profile','profile','View the authenticated employee profile.')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  module_code = VALUES(module_code),
  description = VALUES(description);

INSERT INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN (
  'employee_portal.view',
  'facility_requests.create',
  'facility_requests.view_own',
  'reservations.create',
  'reservations.view_own',
  'notifications.view_own',
  'profile.view_own'
)
WHERE r.role_code = 'EMPLOYEE'
  AND NOT EXISTS (
    SELECT 1 FROM role_permission existing
    WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN (
  'employee_portal.view',
  'facility_requests.create',
  'facility_requests.view_own',
  'reservations.create',
  'reservations.view_own',
  'notifications.view_own',
  'profile.view_own'
)
WHERE r.role_code = 'DEPARTMENT_HEAD'
  AND NOT EXISTS (
    SELECT 1 FROM role_permission existing
    WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

COMMIT;
