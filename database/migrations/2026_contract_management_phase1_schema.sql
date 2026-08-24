-- Contract Management Phase 1: core schema foundation and reference normalization.
-- Additive/backward-compatible migration. Does not build services, APIs, UI, or lifecycle hooks.

-- 1. Canonical contract type reference rows.
INSERT INTO contract_type (type_code, type_name, description, status)
VALUES
('SERVICE', 'Service Contract', 'Contract for professional or operational services.', 'ACTIVE'),
('SUPPLY', 'Supply Contract', 'Contract for goods, supplies, or materials.', 'ACTIVE'),
('LEASE', 'Lease Agreement', 'Facility, space, or equipment lease.', 'ACTIVE'),
('MAINTENANCE', 'Maintenance Contract', 'Contract for preventive or corrective maintenance.', 'ACTIVE')
ON DUPLICATE KEY UPDATE
  type_name = VALUES(type_name),
  description = VALUES(description),
  status = 'ACTIVE';

-- Preserve contract rows while moving aliases onto canonical type rows.
UPDATE contract c
INNER JOIN contract_type old_type ON old_type.contract_type_id = c.contract_type_id
INNER JOIN contract_type new_type ON new_type.type_code = CASE old_type.type_code
  WHEN 'SVC' THEN 'SERVICE'
  WHEN 'SUP' THEN 'SUPPLY'
  WHEN 'MNT' THEN 'MAINTENANCE'
  ELSE old_type.type_code
END
SET c.contract_type_id = new_type.contract_type_id
WHERE old_type.type_code IN ('SVC', 'SUP', 'MNT')
  AND old_type.contract_type_id <> new_type.contract_type_id;

UPDATE contract_type
SET status = 'INACTIVE',
    description = COALESCE(description, 'Legacy alias retained for historical compatibility.')
WHERE type_code IN ('SVC', 'SUP', 'MNT');

-- EXPIRING_SOON is a derived state, not a stored lifecycle status.
UPDATE contract
SET contract_status = CASE
  WHEN end_date < CURRENT_DATE() THEN 'EXPIRED'
  ELSE 'ACTIVE'
END
WHERE contract_status = 'EXPIRING_SOON';

-- 2. Extend core contract table.
ALTER TABLE contract
  ADD COLUMN IF NOT EXISTS owning_department_reference_id BIGINT UNSIGNED NULL AFTER contract_owner_employee_reference_id,
  ADD COLUMN IF NOT EXISTS fam_handler_employee_reference_id BIGINT UNSIGNED NULL AFTER owning_department_reference_id,
  ADD COLUMN IF NOT EXISTS procurement_request_id BIGINT UNSIGNED NULL AFTER budget_reference_id,
  ADD COLUMN IF NOT EXISTS purchase_order_reference_id BIGINT UNSIGNED NULL AFTER procurement_request_id,
  ADD COLUMN IF NOT EXISTS executed_date DATE NULL AFTER start_date,
  ADD COLUMN IF NOT EXISTS effective_date DATE NULL AFTER executed_date,
  ADD COLUMN IF NOT EXISTS termination_date DATE NULL AFTER end_date,
  ADD COLUMN IF NOT EXISTS termination_reason TEXT NULL AFTER termination_date,
  ADD COLUMN IF NOT EXISTS renewal_type VARCHAR(20) NOT NULL DEFAULT 'NONE' AFTER notice_period_days,
  ADD COLUMN IF NOT EXISTS renewal_decision_date DATE NULL AFTER renewal_type,
  ADD COLUMN IF NOT EXISTS renewed_from_contract_id BIGINT UNSIGNED NULL AFTER renewal_decision_date,
  ADD COLUMN IF NOT EXISTS risk_level VARCHAR(20) NULL AFTER renewed_from_contract_id,
  ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT UNSIGNED NULL AFTER risk_level,
  ADD COLUMN IF NOT EXISTS updated_by_user_id BIGINT UNSIGNED NULL AFTER created_by_user_id;

UPDATE contract c
INNER JOIN employee_reference e ON e.employee_reference_id = c.contract_owner_employee_reference_id
SET c.owning_department_reference_id = e.department_reference_id
WHERE c.owning_department_reference_id IS NULL
  AND e.department_reference_id IS NOT NULL;

UPDATE contract
SET renewal_type = 'NONE'
WHERE renewal_type IS NULL OR renewal_type NOT IN ('NONE', 'MANUAL', 'AUTO');

UPDATE contract
SET risk_level = NULL
WHERE risk_level IS NOT NULL AND risk_level NOT IN ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL');

CREATE INDEX IF NOT EXISTS idx_contract_department ON contract (owning_department_reference_id);
CREATE INDEX IF NOT EXISTS idx_contract_handler ON contract (fam_handler_employee_reference_id);
CREATE INDEX IF NOT EXISTS idx_contract_procurement_request ON contract (procurement_request_id);
CREATE INDEX IF NOT EXISTS idx_contract_purchase_order ON contract (purchase_order_reference_id);
CREATE INDEX IF NOT EXISTS idx_contract_renewed_from ON contract (renewed_from_contract_id);

DELIMITER $$
CREATE PROCEDURE add_contract_management_fk_if_missing(
  IN p_table_name VARCHAR(64),
  IN p_constraint_name VARCHAR(64),
  IN p_sql TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table_name
      AND CONSTRAINT_NAME = p_constraint_name
  ) THEN
    SET @contract_management_fk_sql = p_sql;
    PREPARE contract_management_fk_stmt FROM @contract_management_fk_sql;
    EXECUTE contract_management_fk_stmt;
    DEALLOCATE PREPARE contract_management_fk_stmt;
  END IF;
END$$
DELIMITER ;

CALL add_contract_management_fk_if_missing('contract', 'fk_contract_department', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_department FOREIGN KEY (owning_department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL');
CALL add_contract_management_fk_if_missing('contract', 'fk_contract_handler', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_handler FOREIGN KEY (fam_handler_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL');
CALL add_contract_management_fk_if_missing('contract', 'fk_contract_procurement_request', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_procurement_request FOREIGN KEY (procurement_request_id) REFERENCES procurement_request(procurement_request_id) ON DELETE SET NULL');
CALL add_contract_management_fk_if_missing('contract', 'fk_contract_purchase_order', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_purchase_order FOREIGN KEY (purchase_order_reference_id) REFERENCES purchase_order_reference(purchase_order_reference_id) ON DELETE SET NULL');
CALL add_contract_management_fk_if_missing('contract', 'fk_contract_renewed_from', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_renewed_from FOREIGN KEY (renewed_from_contract_id) REFERENCES contract(contract_id) ON DELETE SET NULL');
CALL add_contract_management_fk_if_missing('contract', 'fk_contract_created_by', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL');
CALL add_contract_management_fk_if_missing('contract', 'fk_contract_updated_by', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL');

-- 3. First-class Contract Management entities.
CREATE TABLE IF NOT EXISTS contract_party (
  contract_party_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id BIGINT UNSIGNED NOT NULL,
  party_role VARCHAR(40) NOT NULL,
  supplier_reference_id BIGINT UNSIGNED NULL,
  department_reference_id BIGINT UNSIGNED NULL,
  employee_reference_id BIGINT UNSIGNED NULL,
  external_party_name VARCHAR(255) NULL,
  external_organization_name VARCHAR(255) NULL,
  contact_information VARCHAR(255) NULL,
  is_primary BOOLEAN NOT NULL DEFAULT FALSE,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  CONSTRAINT fk_contract_party_contract FOREIGN KEY (contract_id) REFERENCES contract(contract_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_party_supplier FOREIGN KEY (supplier_reference_id) REFERENCES supplier_reference(supplier_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_party_department FOREIGN KEY (department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_party_employee FOREIGN KEY (employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  INDEX idx_contract_party_contract (contract_id),
  INDEX idx_contract_party_role (party_role),
  INDEX idx_contract_party_supplier (supplier_reference_id),
  INDEX idx_contract_party_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contract_obligation (
  contract_obligation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id BIGINT UNSIGNED NOT NULL,
  obligation_type VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  responsible_party_type VARCHAR(30) NOT NULL DEFAULT 'OTHER',
  responsible_employee_reference_id BIGINT UNSIGNED NULL,
  responsible_department_reference_id BIGINT UNSIGNED NULL,
  responsible_supplier_reference_id BIGINT UNSIGNED NULL,
  source_clause_reference VARCHAR(100) NULL,
  due_date DATE NULL,
  recurrence_rule VARCHAR(255) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  completed_at DATETIME NULL,
  completion_document_id BIGINT UNSIGNED NULL,
  waiver_reason TEXT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  CONSTRAINT fk_contract_obligation_contract FOREIGN KEY (contract_id) REFERENCES contract(contract_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_obligation_employee FOREIGN KEY (responsible_employee_reference_id) REFERENCES employee_reference(employee_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_obligation_department FOREIGN KEY (responsible_department_reference_id) REFERENCES department_reference(department_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_obligation_supplier FOREIGN KEY (responsible_supplier_reference_id) REFERENCES supplier_reference(supplier_reference_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_obligation_document FOREIGN KEY (completion_document_id) REFERENCES document(document_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_obligation_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_obligation_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_contract_obligation_contract (contract_id),
  INDEX idx_contract_obligation_due (due_date),
  INDEX idx_contract_obligation_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contract_amendment (
  contract_amendment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id BIGINT UNSIGNED NOT NULL,
  amendment_number VARCHAR(80) NOT NULL UNIQUE,
  amendment_type VARCHAR(40) NOT NULL,
  description TEXT NULL,
  reason TEXT NULL,
  proposed_date DATE NOT NULL,
  approved_date DATE NULL,
  effective_date DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  value_delta DECIMAL(18,2) NULL,
  resulting_current_amount DECIMAL(18,2) NULL,
  end_date_change DATE NULL,
  document_id BIGINT UNSIGNED NULL,
  approval_request_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  CONSTRAINT fk_contract_amendment_contract FOREIGN KEY (contract_id) REFERENCES contract(contract_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_amendment_document FOREIGN KEY (document_id) REFERENCES document(document_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_amendment_approval FOREIGN KEY (approval_request_id) REFERENCES approval_request(approval_request_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_amendment_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_amendment_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_contract_amendment_contract (contract_id),
  INDEX idx_contract_amendment_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contract_renewal_event (
  contract_renewal_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id BIGINT UNSIGNED NOT NULL,
  renewal_decision VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  decision_date DATE NULL,
  new_end_date DATE NULL,
  amendment_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_contract_renewal_contract FOREIGN KEY (contract_id) REFERENCES contract(contract_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_renewal_amendment FOREIGN KEY (amendment_id) REFERENCES contract_amendment(contract_amendment_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_renewal_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_renewal_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_contract_renewal_contract (contract_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contract_history (
  contract_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  event_description TEXT NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contract_history_contract FOREIGN KEY (contract_id) REFERENCES contract(contract_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_history_actor FOREIGN KEY (actor_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  INDEX idx_contract_history_contract (contract_id),
  INDEX idx_contract_history_event_at (event_at)
) ENGINE=InnoDB;

-- 4. Legal authoritative contract linkage.
ALTER TABLE legal_matter
  ADD COLUMN IF NOT EXISTS contract_id BIGINT UNSIGNED NULL AFTER matter_type;

CREATE INDEX IF NOT EXISTS idx_legal_matter_contract ON legal_matter (contract_id);

CALL add_contract_management_fk_if_missing('legal_matter', 'fk_legal_matter_contract', 'ALTER TABLE legal_matter ADD CONSTRAINT fk_legal_matter_contract FOREIGN KEY (contract_id) REFERENCES contract(contract_id) ON DELETE SET NULL');

DROP PROCEDURE add_contract_management_fk_if_missing;
