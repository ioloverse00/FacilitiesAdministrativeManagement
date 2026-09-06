-- DEVELOPMENT / UAT SAFE ADDITIVE MIGRATION
-- Contract Management signed/executed copy provenance.
--
-- Adds the minimum lifecycle metadata needed to:
--   1. store the exact uploaded signed document/version, and
--   2. bind each approval request to the exact signed document/version reviewed.

SET @schema_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'contract' AND COLUMN_NAME = 'signed_document_id') = 0,
  'ALTER TABLE contract ADD COLUMN signed_document_id BIGINT UNSIGNED NULL AFTER contract_dates_confirmed_at',
  'SELECT ''contract.signed_document_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'contract' AND COLUMN_NAME = 'signed_document_version_id') = 0,
  'ALTER TABLE contract ADD COLUMN signed_document_version_id BIGINT UNSIGNED NULL AFTER signed_document_id',
  'SELECT ''contract.signed_document_version_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'approval_request' AND COLUMN_NAME = 'signed_document_id') = 0,
  'ALTER TABLE approval_request ADD COLUMN signed_document_id BIGINT UNSIGNED NULL AFTER remarks',
  'SELECT ''approval_request.signed_document_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'approval_request' AND COLUMN_NAME = 'signed_document_version_id') = 0,
  'ALTER TABLE approval_request ADD COLUMN signed_document_version_id BIGINT UNSIGNED NULL AFTER signed_document_id',
  'SELECT ''approval_request.signed_document_version_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'contract' AND INDEX_NAME = 'idx_contract_signed_document') = 0,
  'ALTER TABLE contract ADD INDEX idx_contract_signed_document (signed_document_id, signed_document_version_id)',
  'SELECT ''idx_contract_signed_document already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'approval_request' AND INDEX_NAME = 'idx_approval_signed_document') = 0,
  'ALTER TABLE approval_request ADD INDEX idx_approval_signed_document (signed_document_id, signed_document_version_id)',
  'SELECT ''idx_approval_signed_document already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'contract' AND CONSTRAINT_NAME = 'fk_contract_signed_document') = 0,
  'ALTER TABLE contract ADD CONSTRAINT fk_contract_signed_document FOREIGN KEY (signed_document_id) REFERENCES document(document_id) ON DELETE SET NULL',
  'SELECT ''fk_contract_signed_document already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'contract' AND CONSTRAINT_NAME = 'fk_contract_signed_document_version') = 0,
  'ALTER TABLE contract ADD CONSTRAINT fk_contract_signed_document_version FOREIGN KEY (signed_document_version_id) REFERENCES document_version(document_version_id) ON DELETE SET NULL',
  'SELECT ''fk_contract_signed_document_version already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'approval_request' AND CONSTRAINT_NAME = 'fk_approval_signed_document') = 0,
  'ALTER TABLE approval_request ADD CONSTRAINT fk_approval_signed_document FOREIGN KEY (signed_document_id) REFERENCES document(document_id) ON DELETE SET NULL',
  'SELECT ''fk_approval_signed_document already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'approval_request' AND CONSTRAINT_NAME = 'fk_approval_signed_document_version') = 0,
  'ALTER TABLE approval_request ADD CONSTRAINT fk_approval_signed_document_version FOREIGN KEY (signed_document_version_id) REFERENCES document_version(document_version_id) ON DELETE SET NULL',
  'SELECT ''fk_approval_signed_document_version already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
