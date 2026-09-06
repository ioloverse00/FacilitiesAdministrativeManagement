-- Contract Management to Document Template Management integration Phase 1.
-- Additive only. Do not run against production without review/backups.
-- Stores the exact template version selected when a contract draft is created or edited.

ALTER TABLE contract
  ADD COLUMN IF NOT EXISTS template_id BIGINT UNSIGNED NULL AFTER contract_type_id,
  ADD COLUMN IF NOT EXISTS template_version_id BIGINT UNSIGNED NULL AFTER template_id;

CREATE INDEX IF NOT EXISTS idx_contract_template ON contract (template_id);
CREATE INDEX IF NOT EXISTS idx_contract_template_version ON contract (template_version_id);
CREATE INDEX IF NOT EXISTS idx_template_version_template_pair ON document_template_version (template_id, template_version_id);

DELIMITER $$
CREATE PROCEDURE add_contract_template_fk_if_missing(
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
    SET @contract_template_fk_sql = p_sql;
    PREPARE contract_template_fk_stmt FROM @contract_template_fk_sql;
    EXECUTE contract_template_fk_stmt;
    DEALLOCATE PREPARE contract_template_fk_stmt;
  END IF;
END$$
DELIMITER ;

CALL add_contract_template_fk_if_missing('contract', 'fk_contract_template', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_template FOREIGN KEY (template_id) REFERENCES document_template(template_id) ON DELETE RESTRICT');
CALL add_contract_template_fk_if_missing('contract', 'fk_contract_template_version', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_template_version FOREIGN KEY (template_version_id) REFERENCES document_template_version(template_version_id) ON DELETE RESTRICT');
CALL add_contract_template_fk_if_missing('contract', 'fk_contract_template_version_pair', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_template_version_pair FOREIGN KEY (template_id, template_version_id) REFERENCES document_template_version(template_id, template_version_id) ON DELETE RESTRICT');

DROP PROCEDURE add_contract_template_fk_if_missing;
