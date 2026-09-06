-- ISMERS FAM reference seed data
-- Target: MySQL 8.0+, database: ismers_fam
-- Import after ismers_fam_aligned_schema_mysql8.sql.
-- Demo password hashes are placeholders. Replace through the authentication setup before use.

USE ismers_fam;
START TRANSACTION;

-- 1. External Systems
INSERT INTO external_system (system_code, system_name, owner_group, integration_type, status)
VALUES
('HRIS','Human Resource Information System','Human Resources','REST API','CONNECTED'),
('FMS','Financial Management System','Finance','Batch API','ACTIVE'),
('SCM','Supply Chain Management System','Supply Chain','REST API','ACTIVE'),
('FLEET','Fleet Management System','Administration','REST API','PLANNED'),
('BI','Business Intelligence Platform','Information Technology','Data Export','ACTIVE')
ON DUPLICATE KEY UPDATE system_name=VALUES(system_name), owner_group=VALUES(owner_group), integration_type=VALUES(integration_type), status=VALUES(status);

-- 2. Departments
INSERT INTO department_reference (external_department_id, department_code, department_name, source_system, sync_status, last_synced_at, external_updated_at, status)
VALUES
('EXT-DEP-ADM','DEP-ADM','Administration','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE'),
('EXT-DEP-FAC','DEP-FAC','Facilities Management','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE'),
('EXT-DEP-MNT','DEP-MNT','Maintenance Services','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE'),
('EXT-DEP-FIN','DEP-FIN','Finance','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE'),
('EXT-DEP-HR','DEP-HR','Human Resources','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE'),
('EXT-DEP-IT','DEP-IT','Information Technology','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE'),
('EXT-DEP-SCM','DEP-SCM','Supply Chain','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE'),
('EXT-DEP-REC','DEP-REC','Records Management','HRIS','SYNCED','2026-07-26 08:00:00','2026-07-01 09:00:00','ACTIVE')
ON DUPLICATE KEY UPDATE department_name=VALUES(department_name), sync_status=VALUES(sync_status), status=VALUES(status);

-- Additional references used by demo transactions
INSERT INTO budget_reference (external_budget_id, budget_code, budget_name, fiscal_year, allocated_amount, committed_amount, available_amount, currency_code, status, source_system, sync_status, last_synced_at)
VALUES
('EXT-BUD-FAC-2026','BUD-FAC-OPS','Facilities Operations 2026',2026,2500000,740000,1760000,'PHP','ACTIVE','FMS','SYNCED','2026-07-26 08:15:00'),
('EXT-BUD-MNT-2026','BUD-MNT-PM','Maintenance Program 2026',2026,1800000,680000,1120000,'PHP','ACTIVE','FMS','SYNCED','2026-07-26 08:15:00'),
('EXT-BUD-ADM-2026','BUD-ADM-SUP','Administrative Supplies 2026',2026,900000,240000,660000,'PHP','ACTIVE','FMS','SYNCED','2026-07-26 08:15:00'),
('EXT-BUD-IT-2026','BUD-IT-EQP','IT and AV Equipment 2026',2026,1500000,390000,1110000,'PHP','ACTIVE','FMS','SYNCED','2026-07-26 08:15:00')
ON DUPLICATE KEY UPDATE budget_name=VALUES(budget_name), allocated_amount=VALUES(allocated_amount), committed_amount=VALUES(committed_amount), available_amount=VALUES(available_amount), status=VALUES(status);

INSERT INTO supplier_reference (external_supplier_id, supplier_code, supplier_name, contact_person, contact_number, email_address, supplier_status, source_system, sync_status, last_synced_at)
VALUES
('EXT-SUP-001','SUP-ALPHA','Alpha Facilities Supply Co.','Nora Vale','+63-2-555-0101','orders@alpha-facilities.example-agency.test','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00'),
('EXT-SUP-002','SUP-BRIGHT','Brightline Technical Services','Cris Talon','+63-2-555-0102','service@brightline.example-agency.test','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00'),
('EXT-SUP-003','SUP-CLEAR','Clearwater Office Traders','Iris Sol','+63-2-555-0103','sales@clearwater.example-agency.test','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00')
ON DUPLICATE KEY UPDATE supplier_name=VALUES(supplier_name), contact_person=VALUES(contact_person), supplier_status=VALUES(supplier_status);

INSERT INTO inventory_item_reference (external_item_id, item_code, item_name, unit_of_measure, inventory_status, source_system, sync_status, last_synced_at)
VALUES
('EXT-ITM-001','INV-FILTER-24X24','HVAC Filter 24x24','piece','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00'),
('EXT-ITM-002','INV-BREAKER-30A','30A Circuit Breaker','piece','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00'),
('EXT-ITM-003','INV-LED-TUBE','LED Tube Light','piece','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00'),
('EXT-ITM-004','INV-CLEAN-KIT','Sanitation Consumables Kit','set','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00'),
('EXT-ITM-005','INV-PROJ-LAMP','Projector Lamp Module','piece','ACTIVE','SCM','SYNCED','2026-07-26 08:30:00')
ON DUPLICATE KEY UPDATE item_name=VALUES(item_name), unit_of_measure=VALUES(unit_of_measure), inventory_status=VALUES(inventory_status);

INSERT INTO vehicle_reference (external_vehicle_id, vehicle_code, plate_number, vehicle_type, capacity, vehicle_status, source_system, sync_status, last_synced_at)
VALUES
('EXT-VEH-001','VEH-POOL-01','FAM-1001','Passenger Van',12,'AVAILABLE','FLEET','PENDING','2026-07-26 08:30:00'),
('EXT-VEH-002','VEH-POOL-02','FAM-1002','Utility Pickup',4,'AVAILABLE','FLEET','PENDING','2026-07-26 08:30:00')
ON DUPLICATE KEY UPDATE plate_number=VALUES(plate_number), vehicle_type=VALUES(vehicle_type), vehicle_status=VALUES(vehicle_status);

-- 3. Employees
INSERT INTO employee_reference (external_employee_id, employee_number, full_name, department_reference_id, position_title, email_address, contact_number, employment_status, source_system, sync_status, last_synced_at, external_updated_at)
SELECT v.external_employee_id, v.employee_number, v.full_name, d.department_reference_id, v.position_title, v.email_address, v.contact_number, 'ACTIVE', 'HRIS', 'SYNCED', '2026-07-26 08:00:00', '2026-07-01 09:00:00'
FROM (
SELECT 'EXT-EMP-0001' external_employee_id,'EMP-2026-0001' employee_number,'Mara Ibarra' full_name,'DEP-IT' dept,'System Administrator' position_title,'mara.ibarra@example-agency.test' email_address,'+63-917-555-0001' contact_number UNION ALL
SELECT 'EXT-EMP-0002','EMP-2026-0002','Jonas Velasco','DEP-FAC','FAM Administrator','jonas.velasco@example-agency.test','+63-917-555-0002' UNION ALL
SELECT 'EXT-EMP-0003','EMP-2026-0003','Leah Navarro','DEP-FAC','Facility Manager','leah.navarro@example-agency.test','+63-917-555-0003' UNION ALL
SELECT 'EXT-EMP-0004','EMP-2026-0004','Tomas Rivas','DEP-MNT','Maintenance Supervisor','tomas.rivas@example-agency.test','+63-917-555-0004' UNION ALL
SELECT 'EXT-EMP-0005','EMP-2026-0005','Paolo Santos','DEP-MNT','Electrical Technician','paolo.santos@example-agency.test','+63-917-555-0005' UNION ALL
SELECT 'EXT-EMP-0006','EMP-2026-0006','Rina Flores','DEP-MNT','HVAC Technician','rina.flores@example-agency.test','+63-917-555-0006' UNION ALL
SELECT 'EXT-EMP-0007','EMP-2026-0007','Miguel Ortega','DEP-MNT','General Maintenance Technician','miguel.ortega@example-agency.test','+63-917-555-0007' UNION ALL
SELECT 'EXT-EMP-0008','EMP-2026-0008','Ana Mercado','DEP-FAC','Asset Custodian','ana.mercado@example-agency.test','+63-917-555-0008' UNION ALL
SELECT 'EXT-EMP-0009','EMP-2026-0009','Bianca Cruz','DEP-ADM','Reservation Officer','bianca.cruz@example-agency.test','+63-917-555-0009' UNION ALL
SELECT 'EXT-EMP-0010','EMP-2026-0010','Carlo Reyes','DEP-SCM','Procurement Officer','carlo.reyes@example-agency.test','+63-917-555-0010' UNION ALL
SELECT 'EXT-EMP-0011','EMP-2026-0011','Elena Aquino','DEP-REC','Records Officer','elena.aquino@example-agency.test','+63-917-555-0011' UNION ALL
SELECT 'EXT-EMP-0012','EMP-2026-0012','Nico Valdez','DEP-ADM','Department Requestor','nico.valdez@example-agency.test','+63-917-555-0012' UNION ALL
SELECT 'EXT-EMP-0013','EMP-2026-0013','Selene Dizon','DEP-FIN','Budget Approver','selene.dizon@example-agency.test','+63-917-555-0013' UNION ALL
SELECT 'EXT-EMP-0014','EMP-2026-0014','Oscar Lim','DEP-REC','Internal Auditor','oscar.lim@example-agency.test','+63-917-555-0014'
) v JOIN department_reference d ON d.department_code=v.dept AND d.source_system='HRIS'
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), department_reference_id=VALUES(department_reference_id), position_title=VALUES(position_title), email_address=VALUES(email_address), employment_status=VALUES(employment_status);

-- 4. User Accounts
-- Placeholder hash is a PHP password_hash-compatible bcrypt sample for documentation only.
-- Demo login policy: usernames are seeded; set/reset passwords through the app authentication setup.
INSERT INTO user_account (employee_reference_id, username, password_hash, account_status, last_login_at)
SELECT e.employee_reference_id, v.username, '$2y$10$abcdefghijklmnopqrstuuM6Dks4xK8vRNhV0N2mP1xG1Jv6bqF9y', 'ACTIVE', v.last_login_at
FROM (
SELECT 'EMP-2026-0001' employee_number,'gsms-super-admin' username,'2026-07-26 08:45:00' last_login_at UNION ALL
SELECT 'EMP-2026-0002','gsms-fam-admin','2026-07-26 09:10:00' UNION ALL
SELECT 'EMP-2026-0003','facility.manager','2026-07-26 10:20:00' UNION ALL
SELECT 'EMP-2026-0004','maintenance.supervisor','2026-07-26 07:50:00' UNION ALL
SELECT 'EMP-2026-0005','technician.one','2026-07-26 13:05:00' UNION ALL
SELECT 'EMP-2026-0008','asset.custodian','2026-07-25 16:30:00' UNION ALL
SELECT 'EMP-2026-0009','reservation.officer','2026-07-26 11:35:00' UNION ALL
SELECT 'EMP-2026-0010','gsms-scm-head','2026-07-25 14:00:00' UNION ALL
SELECT 'EMP-2026-0011','records.officer','2026-07-26 15:10:00' UNION ALL
SELECT 'EMP-2026-0012','requestor.user','2026-07-26 12:00:00' UNION ALL
SELECT 'EMP-2026-0013','gsms-fin-head',NULL
) v JOIN employee_reference e ON e.employee_number=v.employee_number AND e.source_system='HRIS'
ON DUPLICATE KEY UPDATE employee_reference_id=VALUES(employee_reference_id), account_status=VALUES(account_status), last_login_at=VALUES(last_login_at);

-- 5. Roles
INSERT INTO role (role_code, role_name, description, status)
VALUES
('FAM_SUPER_ADMIN','FAM Super Administrator','Highest FAM application authority.','ACTIVE'),
('FAM_ADMIN','FAM Department Head','Department head of the FAM department with broad operational oversight.','ACTIVE'),
('FAM_STAFF','FAM Staff','Ordinary FAM operational employee.','ACTIVE'),
('DEPARTMENT_HEAD','Department Head','Head of a non-FAM department with department-scoped workflow access.','ACTIVE'),
('EMPLOYEE','Employee','Ordinary employee self-service access.','ACTIVE')
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), description=VALUES(description), status=VALUES(status);

-- 6. Permissions
INSERT INTO permission (
    permission_code,
    permission_name,
    module_code,
    description
)
SELECT
    CONCAT(m.module_code, '.', a.action_code),
    CONCAT(
        REPLACE(m.module_code, '_', ' '),
        ' ',
        a.action_code
    ),
    m.module_code,
    CONCAT(
        'Allows ',
        a.action_code,
        ' access for ',
        m.module_code,
        '.'
    )
FROM (
    SELECT 'dashboard' AS module_code
    UNION ALL SELECT 'facility_requests'
    UNION ALL SELECT 'maintenance'
    UNION ALL SELECT 'assets'
    UNION ALL SELECT 'reservations'
    UNION ALL SELECT 'procurement'
    UNION ALL SELECT 'records'
    UNION ALL SELECT 'reports'
    UNION ALL SELECT 'administration'
) AS m
CROSS JOIN (
    SELECT 'view' AS action_code
    UNION ALL SELECT 'create'
    UNION ALL SELECT 'edit'
    UNION ALL SELECT 'assign'
    UNION ALL SELECT 'approve'
    UNION ALL SELECT 'complete'
    UNION ALL SELECT 'verify'
    UNION ALL SELECT 'export'
    UNION ALL SELECT 'manage'
    UNION ALL SELECT 'delete'
) AS a
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module_code = VALUES(module_code),
    description = VALUES(description);

INSERT INTO permission (permission_code, permission_name, module_code, description) VALUES
('contract.view','View Contracts','contract','View Contract Management records.'),
('contract.create','Create Contracts','contract','Create draft Contract Management records.'),
('contract.edit','Edit Contracts','contract','Edit draft Contract Management records and submit/cancel drafts.'),
('contract.review','Review Contracts','contract','Review Contract Management records and route lifecycle review states.'),
('contract.approve','Approve Contracts','contract','Approve or reject Contract Management lifecycle approval in Phase 2.'),
('contract.activate','Activate Contracts','contract','Activate approved contracts after execution checks.'),
('contract.terminate','Terminate Contracts','contract','Terminate active contracts with required reason and date.'),
('contract.archive','Archive Contracts','contract','Archive expired or terminated Contract Management records.'),
('contract.manage','Manage Contracts','contract','Administer Contract Management records and lifecycle.'),
('budget.approve','Approve Budgets','budget','Approve budget-linked Contract Management approval steps.')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name), module_code=VALUES(module_code), description=VALUES(description);

INSERT INTO permission (permission_code, permission_name, module_code, description) VALUES
('employee_portal.view','View Employee Portal','employee_portal','Access the employee self-service portal.'),
('facility_requests.view_own','View Own Facility Requests','facility_requests','View facility requests owned by the authenticated employee.'),
('reservations.view_own','View Own Reservations','reservations','View room reservations owned by the authenticated employee.'),
('notifications.view_own','View Own Notifications','notifications','View notifications for the authenticated user.'),
('profile.view_own','View Own Profile','profile','View the authenticated employee profile.')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name), module_code=VALUES(module_code), description=VALUES(description);

INSERT INTO permission (permission_code, permission_name, module_code, description) VALUES
('document_templates.view','View Document Templates','document_templates','View the governed document template library.'),
('document_templates.create','Create Document Templates','document_templates','Create active document templates.'),
('document_templates.edit','Edit Document Templates','document_templates','Create new active document template versions.'),
('document_templates.retire','Retire Document Templates','document_templates','Retire active document templates.')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name), module_code=VALUES(module_code), description=VALUES(description);

-- 7. Role-Permission Assignments
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p
WHERE r.role_code='FAM_SUPER_ADMIN'
  AND (
    p.permission_code IN (
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
    OR p.permission_code LIKE 'visitors.%'
  );

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p
WHERE r.role_code='FAM_ADMIN'
  AND (
    p.permission_code IN ('dashboard.view','reports.view','reports.export')
    OR (p.module_code IN ('facility_requests','maintenance','assets','reservations','procurement','records') AND SUBSTRING_INDEX(p.permission_code,'.',-1) IN ('view','create','edit','assign','approve','complete','verify','export','manage'))
    OR p.permission_code LIKE 'contract.%'
    OR p.permission_code IN ('document_templates.view','document_templates.create','document_templates.edit','document_templates.retire')
    OR p.permission_code LIKE 'legal.%'
    OR p.permission_code LIKE 'retention.%'
    OR p.permission_code LIKE 'visitors.%'
  )
  AND p.module_code <> 'administration'
  AND p.permission_code NOT LIKE 'administration.%';

DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'FAM_ADMIN'
  AND (p.module_code = 'administration' OR p.permission_code LIKE 'administration.%');

DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code IN ('FAM_STAFF','DEPARTMENT_HEAD','EMPLOYEE')
  AND p.permission_code LIKE 'legal.%';

DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code IN ('FAM_STAFF','DEPARTMENT_HEAD','EMPLOYEE')
  AND p.permission_code LIKE 'document_templates.%';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p
WHERE r.role_code='FAM_STAFF'
  AND p.permission_code IN (
    'dashboard.view',
    'reports.view',
    'facility_requests.view',
    'facility_requests.create',
    'facility_requests.edit',
    'facility_requests.assign',
    'facility_requests.complete',
    'facility_requests.verify',
    'maintenance.view',
    'maintenance.edit',
    'maintenance.complete',
    'assets.view',
    'assets.create',
    'assets.edit',
    'reservations.view',
    'reservations.create',
    'reservations.edit',
    'visitors.view',
    'visitors.review',
    'visitors.create_walkin',
    'visitors.checkin',
    'visitors.checkout',
    'procurement.view',
    'procurement.create',
    'procurement.edit',
    'records.view',
    'records.create',
    'records.edit',
    'contract.view',
    'contract.create',
    'contract.edit',
    'contract.review',
    'retention.view',
    'retention.review'
  );

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p
WHERE r.role_code='DEPARTMENT_HEAD'
  AND p.permission_code IN (
    'employee_portal.view',
    'facility_requests.create',
    'facility_requests.view_own',
    'reservations.create',
    'reservations.view_own',
    'notifications.view_own',
    'profile.view_own'
  );

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p
WHERE r.role_code='EMPLOYEE'
  AND p.permission_code IN (
    'employee_portal.view',
    'facility_requests.create',
    'facility_requests.view_own',
    'reservations.create',
    'reservations.view_own',
    'notifications.view_own',
    'profile.view_own'
  );

-- 8. User-Role Assignments
INSERT IGNORE INTO user_role (user_account_id, role_id, assigned_by_user_id)
SELECT u.user_account_id, r.role_id, admin.user_account_id
FROM (
SELECT 'gsms-super-admin' username,'FAM_SUPER_ADMIN' role_code UNION ALL
SELECT 'gsms-fam-admin','FAM_ADMIN' UNION ALL
SELECT 'facility.manager','FAM_STAFF' UNION ALL
SELECT 'maintenance.supervisor','FAM_STAFF' UNION ALL
SELECT 'technician.one','FAM_STAFF' UNION ALL
SELECT 'asset.custodian','FAM_STAFF' UNION ALL
SELECT 'reservation.officer','FAM_STAFF' UNION ALL
SELECT 'records.officer','FAM_STAFF' UNION ALL
SELECT 'gsms-scm-head','DEPARTMENT_HEAD' UNION ALL
SELECT 'gsms-fin-head','DEPARTMENT_HEAD' UNION ALL
SELECT 'requestor.user','EMPLOYEE'
) v JOIN user_account u ON u.username=v.username JOIN role r ON r.role_code=v.role_code JOIN user_account admin ON admin.username='gsms-super-admin';

-- 9. Buildings
INSERT INTO building (building_code, building_name, address, description, status)
VALUES
('BLDG-ADM','Administration Building','Fictional Civic Campus, North Drive','Main administrative offices.','ACTIVE'),
('BLDG-OPS','Operations Building','Fictional Civic Campus, East Drive','Facilities and maintenance operations.','ACTIVE'),
('BLDG-TRN','Training Center','Fictional Civic Campus, Learning Lane','Training and assembly spaces.','ACTIVE'),
('BLDG-UTL','Utility Annex','Fictional Civic Campus, Service Road','Utility and infrastructure areas.','ACTIVE')
ON DUPLICATE KEY UPDATE building_name=VALUES(building_name), address=VALUES(address), description=VALUES(description), status=VALUES(status);

-- 10. Facility Spaces
INSERT INTO facility_space (building_id, space_code, space_name, space_type, floor_number, capacity, location_description, is_reservable, status)
SELECT b.building_id, v.space_code, v.space_name, v.space_type, v.floor_number, v.capacity, v.location_description, v.is_reservable, 'ACTIVE'
FROM (
SELECT 'BLDG-ADM' b,'SP-ADM-CONF-MAIN' space_code,'Main Conference Room' space_name,'MEETING_ROOM' space_type,'2' floor_number,40 capacity,'Administration Building second floor west wing' location_description,TRUE is_reservable UNION ALL
SELECT 'BLDG-ADM','SP-ADM-MEET-EXEC','Executive Meeting Room','MEETING_ROOM','3',16,'Administration Building third floor suite area',TRUE UNION ALL
SELECT 'BLDG-TRN','SP-TRN-A','Training Room A','TRAINING_ROOM','1',35,'Training Center ground floor',TRUE UNION ALL
SELECT 'BLDG-TRN','SP-TRN-B','Training Room B','TRAINING_ROOM','1',35,'Training Center ground floor',TRUE UNION ALL
SELECT 'BLDG-ADM','SP-REC-OFFICE','Records Office','OFFICE','1',12,'Administration Building records wing',FALSE UNION ALL
SELECT 'BLDG-ADM','SP-SCM-OFFICE','Procurement Office','OFFICE','1',10,'Administration Building supply counter area',FALSE UNION ALL
SELECT 'BLDG-OPS','SP-MNT-WORKSHOP','Maintenance Workshop','WORKSHOP','G',20,'Operations Building service bay',FALSE UNION ALL
SELECT 'BLDG-UTL','SP-UTL-ELEC','Electrical Room','UTILITY','G',4,'Utility Annex secured electrical room',FALSE UNION ALL
SELECT 'BLDG-UTL','SP-UTL-GEN','Generator Room','UTILITY','G',4,'Utility Annex generator enclosure',FALSE UNION ALL
SELECT 'BLDG-OPS','SP-IT-SERVER','Server Room','TECHNICAL','2',4,'Operations Building restricted IT area',FALSE UNION ALL
SELECT 'BLDG-ADM','SP-ADM-LOBBY','Lobby','PUBLIC_AREA','G',80,'Administration Building main lobby',FALSE UNION ALL
SELECT 'BLDG-OPS','SP-OPS-STORAGE','Storage Room','STORAGE','G',8,'Operations Building supplies storage',FALSE
) v JOIN building b ON b.building_code=v.b
ON DUPLICATE KEY UPDATE space_name=VALUES(space_name), space_type=VALUES(space_type), floor_number=VALUES(floor_number), capacity=VALUES(capacity), is_reservable=VALUES(is_reservable), status=VALUES(status);

-- 11. Facility Amenities
-- Schema limitation: there are no facility_amenity or space_amenity tables in the current schema.
-- Amenities are represented in reservation setup_requirements and document text by demo transactions.

-- 12. Request Categories
INSERT INTO request_category (category_code, category_name, description, default_priority, responsible_role_code, status)
VALUES
('GEN','General Facility Request','General facilities support request.','MEDIUM','FAM_STAFF','ACTIVE'),
('HVAC','HVAC','Heating, ventilation, and air conditioning service.','HIGH','FAM_STAFF','ACTIVE'),
('ELEC','Electrical','Electrical repair, inspection, and safety issues.','HIGH','FAM_STAFF','ACTIVE'),
('PLUMB','Plumbing','Water supply, drainage, and fixture concerns.','MEDIUM','FAM_STAFF','ACTIVE'),
('CLEAN','Cleaning and Sanitation','Janitorial and sanitation services.','LOW','FAM_STAFF','ACTIVE'),
('CARP','Carpentry','Furniture, partition, door, and woodwork support.','MEDIUM','FAM_STAFF','ACTIVE'),
('SAFE','Safety','Safety hazards, inspections, and compliance support.','HIGH','FAM_STAFF','ACTIVE'),
('EQUIP','Equipment','Facility equipment setup or troubleshooting.','MEDIUM','FAM_STAFF','ACTIVE'),
('TRANS','Transportation Service','Vehicle or transport coordination request.','MEDIUM','FAM_STAFF','ACTIVE')
ON DUPLICATE KEY UPDATE category_name=VALUES(category_name), description=VALUES(description), default_priority=VALUES(default_priority), responsible_role_code=VALUES(responsible_role_code), status=VALUES(status);

-- 13. SLA Policies
INSERT INTO sla_policy (policy_code, policy_name, request_category_id, priority, acknowledgement_minutes, assignment_minutes, resolution_minutes, escalation_minutes, status, effective_from)
SELECT v.policy_code, v.policy_name, c.request_category_id, v.priority, v.ack_mins, v.assign_mins, v.resolve_mins, v.escalate_mins, 'ACTIVE', '2026-01-01'
FROM (
SELECT 'SLA-ELEC-CRIT' policy_code,'Critical Electrical Issue' policy_name,'ELEC' cat,'CRITICAL' priority,15 ack_mins,30 assign_mins,240 resolve_mins,60 escalate_mins UNION ALL
SELECT 'SLA-PLUMB-HIGH','High Plumbing Issue','PLUMB','HIGH',30,60,480,120 UNION ALL
SELECT 'SLA-CLEAN-NORMAL','Normal Cleaning Request','CLEAN','MEDIUM',120,240,1440,480 UNION ALL
SELECT 'SLA-HVAC-HIGH','High HVAC Issue','HVAC','HIGH',30,60,480,120 UNION ALL
SELECT 'SLA-SAFE-CRIT','Critical Safety Issue','SAFE','CRITICAL',15,30,240,60 UNION ALL
SELECT 'SLA-GEN-MED','General Medium Request','GEN','MEDIUM',120,240,2880,720 UNION ALL
SELECT 'SLA-EQUIP-MED','Equipment Medium Request','EQUIP','MEDIUM',60,180,1440,360 UNION ALL
SELECT 'SLA-CARP-LOW','Low Carpentry Request','CARP','LOW',240,480,4320,1440
) v JOIN request_category c ON c.category_code=v.cat
ON DUPLICATE KEY UPDATE policy_name=VALUES(policy_name), request_category_id=VALUES(request_category_id), priority=VALUES(priority), acknowledgement_minutes=VALUES(acknowledgement_minutes), assignment_minutes=VALUES(assignment_minutes), resolution_minutes=VALUES(resolution_minutes), escalation_minutes=VALUES(escalation_minutes), status=VALUES(status);

-- 14. Asset Categories
INSERT INTO asset_category (category_code, category_name, description, default_useful_life_years, default_maintenance_interval_days, status)
VALUES
('AST-HVAC','HVAC Equipment','Air conditioning and ventilation equipment.',10,90,'ACTIVE'),
('AST-ELEC','Electrical Equipment','Panels, breakers, and power equipment.',12,180,'ACTIVE'),
('AST-FURN','Furniture','Desks, chairs, cabinets, and fixtures.',8,365,'ACTIVE'),
('AST-ITAV','IT and AV Equipment','Projectors, displays, network, and audio equipment.',5,180,'ACTIVE'),
('AST-SAFE','Safety Equipment','Fire extinguishers, alarms, and safety gear.',6,90,'ACTIVE'),
('AST-OFF','Office Equipment','Copiers, printers, and office machines.',5,180,'ACTIVE'),
('AST-UTIL','Utility Equipment','Generators, pumps, and utility systems.',12,90,'ACTIVE')
ON DUPLICATE KEY UPDATE category_name=VALUES(category_name), description=VALUES(description), default_useful_life_years=VALUES(default_useful_life_years), default_maintenance_interval_days=VALUES(default_maintenance_interval_days), status=VALUES(status);

-- 15. Document Categories
INSERT INTO document_category (category_code, category_name, description, default_confidentiality_level, status)
VALUES
('DOC-FR','Facility Request Documents','Attachments and supporting files for facility requests.','INTERNAL','ACTIVE'),
('DOC-MNT','Maintenance Documents','Maintenance reports, manuals, and service records.','INTERNAL','ACTIVE'),
('DOC-AST','Asset Documents','Asset manuals, warranties, and inspection files.','INTERNAL','ACTIVE'),
('DOC-RES','Reservation Documents','Reservation forms and event documents.','INTERNAL','ACTIVE'),
('DOC-PR','Procurement Documents','Procurement requests, quotes, and supply documents.','CONFIDENTIAL','ACTIVE'),
('DOC-ADM','Administrative Records','Administrative policies, forms, and memoranda.','INTERNAL','ACTIVE'),
('DOC-CON','Contracts','Contracts and agreements.','CONFIDENTIAL','ACTIVE'),
('DOC-PERMIT','Permits and Certificates','Permits, certificates, and compliance documents.','PUBLIC','ACTIVE'),
('DOC-LEGAL','Legal Documents','Legal records and related documents.','CONFIDENTIAL','ACTIVE')
ON DUPLICATE KEY UPDATE category_name=VALUES(category_name), description=VALUES(description), default_confidentiality_level=VALUES(default_confidentiality_level), status=VALUES(status);

-- 16. Contract Types
INSERT INTO contract_type (type_code, type_name, description, status)
VALUES
('CLIENT_CONTRACT','Client Contract','Client-facing contract or agreement.','ACTIVE'),
('EMPLOYEE_CONTRACT','Employee Contract','Employee contract or employment-related agreement.','ACTIVE'),
('NDA','NDA / Confidentiality Agreement','Non-disclosure or confidentiality agreement.','ACTIVE'),
('CONTRACT_AMENDMENT','Contract Amendment','Amendment to an existing contract.','ACTIVE'),
('OTHER','Other','Other contract or agreement type.','ACTIVE')
ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), description=VALUES(description), status=VALUES(status);

-- 17. Retention Schedules
INSERT INTO retention_schedule (schedule_code, schedule_name, record_category, retention_trigger, retention_trigger_basis, retention_period_value, retention_period_unit, disposition_action, legal_basis, description, status, effective_date)
VALUES
('RET-ADM-005','General Administrative Records','Administrative','Record closure','RECORD_CLOSURE',5,'YEAR','REVIEW_THEN_DISPOSE','Fictional agency records policy','Routine administrative records retained for five years.','ACTIVE','2026-01-01'),
('RET-MNT-007','Maintenance Records','Maintenance','Work completion','WORK_COMPLETION',7,'YEAR','ARCHIVE','Fictional maintenance retention policy','Maintenance records retained for asset lifecycle review.','ACTIVE','2026-01-01'),
('RET-AST-010','Asset Records','Assets','Asset disposal','ASSET_DISPOSAL',10,'YEAR','ARCHIVE','Fictional property accountability policy','Asset records retained after final disposition.','ACTIVE','2026-01-01'),
('RET-PR-007','Procurement Records','Procurement','Final payment','FINAL_PAYMENT',7,'YEAR','REVIEW_THEN_DISPOSE','Fictional procurement retention policy','Procurement files retained for audit readiness.','ACTIVE','2026-01-01'),
('RET-CON-010','Contracts and Agreements','Contracts','Contract expiration','CONTRACT_EXPIRATION',10,'YEAR','ARCHIVE','Fictional contract retention policy','Contracts retained after expiration.','ACTIVE','2026-01-01')
ON DUPLICATE KEY UPDATE schedule_name=VALUES(schedule_name), record_category=VALUES(record_category), retention_trigger=VALUES(retention_trigger), retention_trigger_basis=VALUES(retention_trigger_basis), retention_period_value=VALUES(retention_period_value), disposition_action=VALUES(disposition_action), status=VALUES(status);

-- 18. System Settings
INSERT INTO system_setting (setting_key, setting_value, value_type, description, is_public, updated_by_user_id)
SELECT v.setting_key, v.setting_value, v.value_type, v.description, v.is_public, u.user_account_id
FROM (
SELECT 'system.display_name' setting_key,'ISMERS FAM' setting_value,'STRING' value_type,'System display name.' description,TRUE is_public UNION ALL
SELECT 'organization.name','Great Solomon Manpower Services Inc.','STRING','Organization name for demos.',TRUE UNION ALL
SELECT 'system.timezone','Asia/Manila','STRING','Default timezone.',TRUE UNION ALL
SELECT 'system.language','en','STRING','Default language.',TRUE UNION ALL
SELECT 'format.date','YYYY-MM-DD','STRING','Default date format.',TRUE UNION ALL
SELECT 'format.time','HH:mm','STRING','Default time format.',TRUE UNION ALL
SELECT 'finance.currency','PHP','STRING','Default currency.',TRUE UNION ALL
SELECT 'finance.fiscal_year_start','01-01','STRING','Fiscal year start month and day.',FALSE UNION ALL
SELECT 'operations.business_hours','08:00-17:00 Mon-Fri','STRING','Default business hours.',TRUE UNION ALL
SELECT 'navigation.default_landing_page','/dashboard','STRING','Default landing page after login.',TRUE
) v LEFT JOIN user_account u ON u.username='gsms-super-admin'
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), value_type=VALUES(value_type), description=VALUES(description), is_public=VALUES(is_public), updated_by_user_id=VALUES(updated_by_user_id);

COMMIT;
