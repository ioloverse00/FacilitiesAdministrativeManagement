ALTER TABLE document
  ADD COLUMN IF NOT EXISTS agreement_reference VARCHAR(100) NULL AFTER expiration_date,
  ADD COLUMN IF NOT EXISTS agreement_status VARCHAR(30) NULL AFTER agreement_reference,
  ADD COLUMN IF NOT EXISTS contract_metadata_status VARCHAR(30) NOT NULL DEFAULT 'NOT_ANALYZED' AFTER agreement_status,
  ADD COLUMN IF NOT EXISTS contract_metadata_candidate_json JSON NULL AFTER contract_metadata_status,
  ADD COLUMN IF NOT EXISTS contract_metadata_source VARCHAR(40) NULL AFTER contract_metadata_candidate_json,
  ADD COLUMN IF NOT EXISTS contract_metadata_confirmed_by_user_id BIGINT UNSIGNED NULL AFTER contract_metadata_source,
  ADD COLUMN IF NOT EXISTS contract_metadata_confirmed_at DATETIME NULL AFTER contract_metadata_confirmed_by_user_id;

CREATE INDEX IF NOT EXISTS idx_document_contract_metadata_status
  ON document (contract_metadata_status);
