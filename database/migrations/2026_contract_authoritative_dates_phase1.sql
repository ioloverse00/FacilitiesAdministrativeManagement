-- Contract Management Phase 1: authoritative date provenance.
-- Additive only. Do not change start_date/end_date nullability in this phase.

ALTER TABLE contract
  ADD COLUMN IF NOT EXISTS contract_dates_source_document_id BIGINT UNSIGNED NULL AFTER effective_date,
  ADD COLUMN IF NOT EXISTS contract_dates_source_document_version_id BIGINT UNSIGNED NULL AFTER contract_dates_source_document_id,
  ADD COLUMN IF NOT EXISTS contract_dates_confirmed_by_user_id BIGINT UNSIGNED NULL AFTER contract_dates_source_document_version_id,
  ADD COLUMN IF NOT EXISTS contract_dates_confirmed_at DATETIME NULL AFTER contract_dates_confirmed_by_user_id;

CREATE INDEX IF NOT EXISTS idx_contract_dates_source_document
  ON contract (contract_dates_source_document_id);

CREATE INDEX IF NOT EXISTS idx_contract_dates_source_version
  ON contract (contract_dates_source_document_version_id);

DELIMITER $$
CREATE PROCEDURE add_contract_dates_fk_if_missing(
  IN p_constraint_name VARCHAR(64),
  IN p_sql TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contract'
      AND CONSTRAINT_NAME = p_constraint_name
  ) THEN
    SET @contract_dates_fk_sql = p_sql;
    PREPARE contract_dates_fk_stmt FROM @contract_dates_fk_sql;
    EXECUTE contract_dates_fk_stmt;
    DEALLOCATE PREPARE contract_dates_fk_stmt;
  END IF;
END$$
DELIMITER ;

CALL add_contract_dates_fk_if_missing('fk_contract_dates_source_document', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_dates_source_document FOREIGN KEY (contract_dates_source_document_id) REFERENCES document(document_id) ON DELETE SET NULL');
CALL add_contract_dates_fk_if_missing('fk_contract_dates_source_version', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_dates_source_version FOREIGN KEY (contract_dates_source_document_version_id) REFERENCES document_version(document_version_id) ON DELETE SET NULL');
CALL add_contract_dates_fk_if_missing('fk_contract_dates_confirmed_by', 'ALTER TABLE contract ADD CONSTRAINT fk_contract_dates_confirmed_by FOREIGN KEY (contract_dates_confirmed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL');

DROP PROCEDURE add_contract_dates_fk_if_missing;
