-- DEVELOPMENT/UAT ONLY
-- Client/counterparty requirement tracking for Contract Management.
-- Do not run against production without review.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS contract_client_requirement (
  contract_client_requirement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id BIGINT UNSIGNED NOT NULL,
  requirement_code VARCHAR(80) NOT NULL,
  requirement_name VARCHAR(180) NOT NULL,
  requirement_description TEXT NULL,
  requirement_classification VARCHAR(30) NOT NULL DEFAULT 'REQUIRED',
  requirement_status VARCHAR(30) NOT NULL DEFAULT 'MISSING',
  verification_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  uploaded_document_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  uploaded_by_user_id BIGINT UNSIGNED NULL,
  verified_by_user_id BIGINT UNSIGNED NULL,
  uploaded_at DATETIME NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (contract_client_requirement_id),
  UNIQUE KEY uq_contract_client_requirement_code (contract_id, requirement_code),
  KEY idx_contract_client_requirement_contract (contract_id),
  KEY idx_contract_client_requirement_classification (requirement_classification),
  KEY idx_contract_client_requirement_status (requirement_status, verification_status),
  KEY idx_contract_client_requirement_document (uploaded_document_id),
  CONSTRAINT fk_contract_client_requirement_contract
    FOREIGN KEY (contract_id) REFERENCES contract (contract_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_client_requirement_document
    FOREIGN KEY (uploaded_document_id) REFERENCES document (document_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_client_requirement_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES user_account (user_account_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_client_requirement_uploaded_by
    FOREIGN KEY (uploaded_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_client_requirement_verified_by
    FOREIGN KEY (verified_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL
);

ALTER TABLE contract_client_requirement
  ADD COLUMN IF NOT EXISTS requirement_code VARCHAR(80) NOT NULL DEFAULT 'CUSTOM',
  ADD COLUMN IF NOT EXISTS requirement_classification VARCHAR(30) NOT NULL DEFAULT 'REQUIRED';

UPDATE contract_client_requirement
SET requirement_code = CONCAT('CUSTOM_', contract_client_requirement_id)
WHERE requirement_code = 'CUSTOM';

COMMIT;
