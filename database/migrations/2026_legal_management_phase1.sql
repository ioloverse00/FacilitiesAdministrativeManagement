-- Phase 1: Legal Management foundation and core Legal Matter workflow.
-- Canonical terminology is Legal Matter. The legacy legal_case shell is retired
-- by deployment tooling only when it is empty; this migration creates the
-- canonical tables and permissions used by the application.

CREATE TABLE IF NOT EXISTS legal_matter (
  legal_matter_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  matter_number varchar(60) NOT NULL,
  title varchar(255) NOT NULL,
  matter_type varchar(50) NOT NULL,
  summary text NOT NULL,
  priority varchar(30) NOT NULL DEFAULT 'MEDIUM',
  status varchar(30) NOT NULL DEFAULT 'OPEN',
  department_reference_id bigint(20) unsigned NULL,
  assigned_employee_reference_id bigint(20) unsigned NULL,
  reported_at date NULL,
  opened_at datetime NOT NULL DEFAULT current_timestamp(),
  resolved_at datetime NULL,
  resolved_by_user_id bigint(20) unsigned NULL,
  resolution_summary text NULL,
  closed_at datetime NULL,
  closed_by_user_id bigint(20) unsigned NULL,
  cancelled_at datetime NULL,
  cancelled_by_user_id bigint(20) unsigned NULL,
  cancellation_reason text NULL,
  created_by_user_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  deleted_at datetime NULL,
  PRIMARY KEY (legal_matter_id),
  UNIQUE KEY uq_legal_matter_number (matter_number),
  KEY idx_legal_matter_status (status),
  KEY idx_legal_matter_type (matter_type),
  KEY idx_legal_matter_priority (priority),
  KEY idx_legal_matter_updated (updated_at),
  KEY fk_legal_matter_department (department_reference_id),
  KEY fk_legal_matter_assignee (assigned_employee_reference_id),
  KEY fk_legal_matter_created_by (created_by_user_id),
  KEY fk_legal_matter_resolved_by (resolved_by_user_id),
  KEY fk_legal_matter_closed_by (closed_by_user_id),
  KEY fk_legal_matter_cancelled_by (cancelled_by_user_id),
  CONSTRAINT fk_legal_matter_department FOREIGN KEY (department_reference_id) REFERENCES department_reference (department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_assignee FOREIGN KEY (assigned_employee_reference_id) REFERENCES employee_reference (employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_resolved_by FOREIGN KEY (resolved_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_legal_matter_cancelled_by FOREIGN KEY (cancelled_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_matter_history (
  legal_matter_history_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  legal_matter_id bigint(20) unsigned NOT NULL,
  event_type varchar(100) NOT NULL,
  from_status varchar(30) NULL,
  to_status varchar(30) NULL,
  description text NOT NULL,
  metadata_json longtext NULL,
  actor_user_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (legal_matter_history_id),
  KEY idx_legal_matter_history_matter (legal_matter_id, created_at),
  KEY fk_legal_matter_history_actor (actor_user_id),
  CONSTRAINT fk_legal_matter_history_matter FOREIGN KEY (legal_matter_id) REFERENCES legal_matter (legal_matter_id) ON DELETE CASCADE,
  CONSTRAINT fk_legal_matter_history_actor FOREIGN KEY (actor_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permission (permission_code, permission_name, module_code, description) VALUES
('legal.view','View Legal Matters','legal','View Legal Management matters and details.'),
('legal.create','Create Legal Matters','legal','Create Legal Management matter records.'),
('legal.edit','Edit Legal Matters','legal','Edit Legal Management matter metadata.'),
('legal.assign','Assign Legal Matters','legal','Assign and reassign Legal Management matters.'),
('legal.resolve','Resolve Legal Matters','legal','Resolve and reopen Legal Management matters.'),
('legal.close','Close Legal Matters','legal','Close Legal Management matters.'),
('legal.manage','Manage Legal Matters','legal','Administer Legal Management records and lifecycle.')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name), module_code=VALUES(module_code), description=VALUES(description);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code LIKE 'legal.%'
WHERE r.role_code IN ('SYSTEM_ADMIN','FAM_ADMIN');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code = 'legal.view'
WHERE r.role_code IN ('AUDITOR','FACILITY_MANAGER');
