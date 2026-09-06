-- DEVELOPMENT/UAT ONLY
-- Completes Contract Management client/counterparty requirement controls.
-- Review before running in production.

START TRANSACTION;

ALTER TABLE contract_client_requirement
  ADD COLUMN IF NOT EXISTS applicability_status VARCHAR(30) NOT NULL DEFAULT 'APPLICABLE' AFTER requirement_classification,
  ADD COLUMN IF NOT EXISTS rejected_by_user_id BIGINT UNSIGNED NULL AFTER verified_by_user_id,
  ADD COLUMN IF NOT EXISTS rejected_at DATETIME NULL AFTER verified_at,
  ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL AFTER rejected_at;

UPDATE contract_client_requirement
SET applicability_status = CASE
  WHEN requirement_classification = 'CONDITIONAL' THEN 'PENDING'
  WHEN requirement_status = 'NOT_APPLICABLE' THEN 'NOT_APPLICABLE'
  ELSE 'APPLICABLE'
END
WHERE applicability_status IS NULL
   OR applicability_status = ''
   OR applicability_status = 'APPLICABLE';

CREATE INDEX IF NOT EXISTS idx_contract_client_requirement_applicability
  ON contract_client_requirement (requirement_classification, applicability_status);

DELIMITER $$
CREATE PROCEDURE add_contract_requirement_rejected_by_fk_if_missing()
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contract_client_requirement'
      AND CONSTRAINT_NAME = 'fk_contract_client_requirement_rejected_by'
  ) THEN
    ALTER TABLE contract_client_requirement
      ADD CONSTRAINT fk_contract_client_requirement_rejected_by
      FOREIGN KEY (rejected_by_user_id) REFERENCES user_account (user_account_id)
      ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;

CALL add_contract_requirement_rejected_by_fk_if_missing();
DROP PROCEDURE add_contract_requirement_rejected_by_fk_if_missing;

COMMIT;
