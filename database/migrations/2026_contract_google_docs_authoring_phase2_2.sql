-- DEVELOPMENT/UAT ONLY
-- Phase 2.2 Google Docs contract authoring integration.
-- Do not run against production without review and configured OAuth/token encryption.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS google_account_connection (
  google_account_connection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_account_id BIGINT UNSIGNED NOT NULL,
  google_subject_id VARCHAR(120) NOT NULL,
  google_email VARCHAR(255) NOT NULL,
  encrypted_refresh_token TEXT NOT NULL,
  scopes TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  PRIMARY KEY (google_account_connection_id),
  UNIQUE KEY uq_google_account_connection_user (user_account_id),
  KEY idx_google_account_connection_status (status),
  CONSTRAINT fk_google_account_connection_user
    FOREIGN KEY (user_account_id) REFERENCES user_account (user_account_id)
);

CREATE TABLE IF NOT EXISTS contract_google_document (
  contract_google_document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id BIGINT UNSIGNED NOT NULL,
  template_id BIGINT UNSIGNED NOT NULL,
  template_version_id BIGINT UNSIGNED NOT NULL,
  google_account_connection_id BIGINT UNSIGNED NULL,
  google_file_id VARCHAR(255) NOT NULL,
  google_document_id VARCHAR(255) NOT NULL,
  google_web_view_url VARCHAR(1000) NULL,
  working_document_status VARCHAR(30) NOT NULL DEFAULT 'WORKING',
  synced_document_id BIGINT UNSIGNED NULL,
  synced_document_version_id BIGINT UNSIGNED NULL,
  last_synced_at DATETIME NULL,
  finalized_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (contract_google_document_id),
  UNIQUE KEY uq_contract_google_document_contract (contract_id),
  KEY idx_contract_google_document_template (template_id, template_version_id),
  KEY idx_contract_google_document_status (working_document_status),
  KEY idx_contract_google_document_synced_document (synced_document_id),
  CONSTRAINT fk_contract_google_document_contract
    FOREIGN KEY (contract_id) REFERENCES contract (contract_id),
  CONSTRAINT fk_contract_google_document_template
    FOREIGN KEY (template_id) REFERENCES document_template (template_id),
  CONSTRAINT fk_contract_google_document_template_version
    FOREIGN KEY (template_version_id) REFERENCES document_template_version (template_version_id),
  CONSTRAINT fk_contract_google_document_connection
    FOREIGN KEY (google_account_connection_id) REFERENCES google_account_connection (google_account_connection_id),
  CONSTRAINT fk_contract_google_document_synced_document
    FOREIGN KEY (synced_document_id) REFERENCES document (document_id),
  CONSTRAINT fk_contract_google_document_synced_version
    FOREIGN KEY (synced_document_version_id) REFERENCES document_version (document_version_id),
  CONSTRAINT fk_contract_google_document_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES user_account (user_account_id)
);

COMMIT;
