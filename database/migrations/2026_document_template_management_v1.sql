-- Document Template Management v1.
-- Local/UAT-safe additive migration. Review before running; do not execute in production without a release plan.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS document_template (
  template_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_code VARCHAR(60) NOT NULL,
  template_name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  template_type VARCHAR(50) NOT NULL,
  source_module VARCHAR(80) NOT NULL DEFAULT 'DOCUMENT_MANAGEMENT',
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  current_approved_version_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_document_template_code (template_code),
  INDEX idx_document_template_status (status),
  INDEX idx_document_template_type (template_type),
  CONSTRAINT fk_document_template_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_template_version (
  template_version_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  document_version_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  change_summary TEXT NULL,
  effective_from DATE NULL,
  effective_until DATE NULL,
  submitted_by_user_id BIGINT UNSIGNED NULL,
  submitted_at DATETIME NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  retired_by_user_id BIGINT UNSIGNED NULL,
  retired_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_template_version (template_id, version_number),
  INDEX idx_document_template_version_status (status),
  INDEX idx_document_template_version_document (document_id, document_version_id),
  CONSTRAINT fk_template_version_template FOREIGN KEY (template_id) REFERENCES document_template(template_id) ON DELETE RESTRICT,
  CONSTRAINT fk_template_version_document FOREIGN KEY (document_id) REFERENCES document(document_id) ON DELETE RESTRICT,
  CONSTRAINT fk_template_version_document_version FOREIGN KEY (document_version_id) REFERENCES document_version(document_version_id) ON DELETE RESTRICT,
  CONSTRAINT fk_template_version_submitted_by FOREIGN KEY (submitted_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_template_version_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_template_version_retired_by FOREIGN KEY (retired_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_template_version_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_template_merge_field (
  merge_field_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_code VARCHAR(120) NOT NULL,
  namespace VARCHAR(40) NOT NULL,
  field_name VARCHAR(80) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  data_type VARCHAR(30) NOT NULL DEFAULT 'STRING',
  source_type VARCHAR(40) NOT NULL DEFAULT 'DATABASE_FIELD',
  source_field VARCHAR(160) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_template_merge_field_code (field_code),
  INDEX idx_template_merge_field_namespace (namespace, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_template_version_field (
  template_version_id BIGINT UNSIGNED NOT NULL,
  merge_field_id BIGINT UNSIGNED NOT NULL,
  required_flag BOOLEAN NOT NULL DEFAULT TRUE,
  default_value TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (template_version_id, merge_field_id),
  CONSTRAINT fk_template_version_field_version FOREIGN KEY (template_version_id) REFERENCES document_template_version(template_version_id) ON DELETE CASCADE,
  CONSTRAINT fk_template_version_field_field FOREIGN KEY (merge_field_id) REFERENCES document_template_merge_field(merge_field_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO document_template_merge_field
  (field_code, namespace, field_name, display_name, description, data_type, source_type, source_field, status)
VALUES
('contract.contract_number','contract','contract_number','Contract Number','Authoritative contract number.','STRING','DATABASE_FIELD','contract.contract_number','ACTIVE'),
('contract.title','contract','title','Contract Title','Authoritative contract title.','STRING','DATABASE_FIELD','contract.contract_title','ACTIVE'),
('contract.start_date','contract','start_date','Start Date','Contract start date.','DATE','DATABASE_FIELD','contract.start_date','ACTIVE'),
('contract.end_date','contract','end_date','End Date','Contract end date.','DATE','DATABASE_FIELD','contract.end_date','ACTIVE'),
('contract.currency','contract','currency','Currency','Contract currency code.','STRING','DATABASE_FIELD','contract.currency_code','ACTIVE'),
('contract.contract_value','contract','contract_value','Contract Value','Current contract amount.','MONEY','DATABASE_FIELD','contract.current_amount','ACTIVE'),
('client.name','client','name','Client Name','Future client/counterparty display name.','STRING','FUTURE','FUTURE','ACTIVE'),
('employee.full_name','employee','full_name','Employee Full Name','Employee full name.','STRING','DATABASE_FIELD','employee_reference.full_name','ACTIVE'),
('employee.position','employee','position','Employee Position','Employee position/title when available.','STRING','DATABASE_FIELD','employee_reference.position_title','ACTIVE'),
('agency.name','agency','name','Agency Name','Future agency/legal entity name.','STRING','FUTURE','FUTURE','ACTIVE')
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  description = VALUES(description),
  data_type = VALUES(data_type),
  source_type = VALUES(source_type),
  source_field = VALUES(source_field),
  status = VALUES(status);

INSERT INTO permission (permission_code, permission_name, module_code, description)
VALUES
('document_templates.view','View Document Templates','document_templates','View the governed document template library.'),
('document_templates.create','Create Document Templates','document_templates','Create active document templates.'),
('document_templates.edit','Edit Document Templates','document_templates','Create new active document template versions.'),
('document_templates.retire','Retire Document Templates','document_templates','Retire active document templates.')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  module_code = VALUES(module_code),
  description = VALUES(description);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN ('document_templates.view','document_templates.create','document_templates.edit','document_templates.retire')
WHERE r.role_code IN ('FAM_SUPER_ADMIN', 'FAM_ADMIN');

DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code IN ('FAM_STAFF', 'DEPARTMENT_HEAD', 'EMPLOYEE')
  AND p.permission_code LIKE 'document_templates.%';

COMMIT;
