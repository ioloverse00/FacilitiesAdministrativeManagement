-- Visitor Management live admin module foundation.

ALTER TABLE visitor
  ADD COLUMN IF NOT EXISTS visitor_uuid CHAR(36) NULL AFTER visitor_id,
  ADD COLUMN IF NOT EXISTS identification_last4 VARCHAR(16) NULL AFTER id_number_encrypted,
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER identification_last4,
  ADD UNIQUE KEY IF NOT EXISTS uq_visitor_uuid (visitor_uuid);

UPDATE visitor SET visitor_uuid = UUID() WHERE visitor_uuid IS NULL;

ALTER TABLE visit
  ADD COLUMN IF NOT EXISTS visitor_type VARCHAR(30) NULL AFTER visitor_id,
  ADD COLUMN IF NOT EXISTS visit_description TEXT NULL AFTER purpose,
  ADD COLUMN IF NOT EXISTS destination_department_reference_id BIGINT UNSIGNED NULL AFTER destination_space_id,
  ADD COLUMN IF NOT EXISTS approval_status VARCHAR(30) NOT NULL DEFAULT 'PENDING' AFTER visit_status,
  ADD COLUMN IF NOT EXISTS registration_source VARCHAR(40) NOT NULL DEFAULT 'WALK_IN' AFTER approval_status,
  ADD COLUMN IF NOT EXISTS applicant_reference VARCHAR(120) NULL AFTER registration_source,
  ADD COLUMN IF NOT EXISTS company_or_school VARCHAR(200) NULL AFTER applicant_reference,
  ADD COLUMN IF NOT EXISTS identity_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER company_or_school,
  ADD COLUMN IF NOT EXISTS identity_verified_at DATETIME NULL AFTER identity_verified,
  ADD COLUMN IF NOT EXISTS identity_verified_by_user_id BIGINT UNSIGNED NULL AFTER identity_verified_at,
  ADD COLUMN IF NOT EXISTS visitor_badge_id BIGINT UNSIGNED NULL AFTER identity_verified_by_user_id,
  ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT UNSIGNED NULL AFTER remarks,
  ADD COLUMN IF NOT EXISTS updated_by_user_id BIGINT UNSIGNED NULL AFTER created_by_user_id,
  ADD INDEX IF NOT EXISTS idx_visit_department (destination_department_reference_id),
  ADD INDEX IF NOT EXISTS idx_visit_approval (approval_status),
  ADD INDEX IF NOT EXISTS idx_visit_source (registration_source),
  ADD INDEX IF NOT EXISTS idx_visit_actual_time_in (actual_time_in),
  ADD CONSTRAINT fk_visit_destination_department FOREIGN KEY (destination_department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS visitor_sequence (
  sequence_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitor_badge (
  visitor_badge_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  badge_number VARCHAR(60) NOT NULL UNIQUE,
  badge_status VARCHAR(30) NOT NULL DEFAULT 'AVAILABLE',
  issued_to_visit_id BIGINT UNSIGNED NULL,
  issued_at DATETIME NULL,
  returned_at DATETIME NULL,
  issued_by_user_id BIGINT UNSIGNED NULL,
  returned_to_user_id BIGINT UNSIGNED NULL,
  remarks TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_visitor_badge_visit FOREIGN KEY (issued_to_visit_id) REFERENCES visit(visit_id) ON DELETE SET NULL,
  CONSTRAINT fk_visitor_badge_issued_by FOREIGN KEY (issued_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_visitor_badge_returned_to FOREIGN KEY (returned_to_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_visitor_badge_status (badge_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitor_visit_history (
  visitor_visit_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  event_type VARCHAR(100) NOT NULL,
  remarks TEXT NULL,
  changed_by_user_id BIGINT UNSIGNED NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_visitor_history_visit FOREIGN KEY (visit_id) REFERENCES visit(visit_id) ON DELETE CASCADE,
  CONSTRAINT fk_visitor_history_user FOREIGN KEY (changed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_visitor_history_visit (visit_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO visitor_badge (badge_number, badge_status)
SELECT CONCAT('BADGE-', LPAD(n.n, 3, '0')), 'AVAILABLE'
FROM (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) n
WHERE NOT EXISTS (SELECT 1 FROM visitor_badge b WHERE b.badge_number = CONCAT('BADGE-', LPAD(n.n, 3, '0')));

INSERT INTO permission (permission_code, permission_name, module_code, description) VALUES
('visitors.view','View Visitors','VISITORS','View Visitor Management records.'),
('visitors.create_walkin','Register Walk-in Visitors','VISITORS','Register walk-in visitor records.'),
('visitors.review','Review Visitors','VISITORS','Review visitor visits and outcomes.'),
('visitors.approve','Approve Visitors','VISITORS','Approve or reject visitor visits.'),
('visitors.checkin','Check In Visitors','VISITORS','Check visitors in and issue badges.'),
('visitors.checkout','Check Out Visitors','VISITORS','Check visitors out and return badges.'),
('visitors.manage_badges','Manage Visitor Badges','VISITORS','Manage reusable visitor badges.'),
('visitors.export','Export Visitors','VISITORS','Export visitor records.'),
('visitors.manage','Manage Visitors','VISITORS','Administer Visitor Management records.')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name), module_code=VALUES(module_code), description=VALUES(description);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p ON p.permission_code LIKE 'visitors.%'
WHERE r.role_code IN ('SYSTEM_ADMIN','FAM_ADMIN');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p ON p.permission_code IN ('visitors.view','visitors.review','visitors.approve','visitors.export')
WHERE r.role_code='FACILITY_MANAGER';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p ON p.permission_code IN ('visitors.view','visitors.create_walkin','visitors.checkin','visitors.checkout')
WHERE r.role_code='RESERVATION_OFFICER';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM role r JOIN permission p ON p.permission_code IN ('visitors.view','visitors.export')
WHERE r.role_code='AUDITOR';
