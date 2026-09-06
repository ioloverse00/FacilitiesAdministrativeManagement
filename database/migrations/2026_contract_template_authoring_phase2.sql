-- Contract Template Authoring Phase 2.
-- Additive migration only. Review and run in local/UAT before UAT; do not run against production without a release plan.

CREATE TABLE IF NOT EXISTS contract_template_value (
  contract_template_value_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id BIGINT UNSIGNED NOT NULL,
  template_id BIGINT UNSIGNED NOT NULL,
  template_version_id BIGINT UNSIGNED NOT NULL,
  merge_field_id BIGINT UNSIGNED NOT NULL,
  field_code VARCHAR(120) NOT NULL,
  value_text TEXT NULL,
  source_type VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contract_template_value_field (contract_id, template_version_id, merge_field_id),
  INDEX idx_contract_template_value_contract (contract_id),
  INDEX idx_contract_template_value_template (template_id, template_version_id),
  INDEX idx_contract_template_value_field_code (field_code),
  CONSTRAINT fk_contract_template_value_contract FOREIGN KEY (contract_id) REFERENCES contract(contract_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_template_value_template FOREIGN KEY (template_id) REFERENCES document_template(template_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_template_value_template_version FOREIGN KEY (template_version_id) REFERENCES document_template_version(template_version_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_template_value_merge_field FOREIGN KEY (merge_field_id) REFERENCES document_template_merge_field(merge_field_id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_template_value_created_by FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_template_value_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO document_template_merge_field
  (field_code, namespace, field_name, display_name, description, data_type, source_type, source_field, status)
VALUES
('client.representative_name','client','representative_name','Client Representative Name','Manual contract-specific client representative name.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('client.id_number','client','id_number','Client ID Number','Manual contract-specific client identification number.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('client.id_issue_date','client','id_issue_date','Client ID Issue Date','Manual contract-specific client ID issue date.','DATE','MANUAL','contract_template_value.value_text','ACTIVE'),
('client.id_issue_place','client','id_issue_place','Client ID Issue Place','Manual contract-specific client ID issue place.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('provider.representative_name','provider','representative_name','Provider Representative Name','Manual contract-specific provider representative name.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('provider.id_number','provider','id_number','Provider ID Number','Manual contract-specific provider identification number.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('provider.id_issue_date','provider','id_issue_date','Provider ID Issue Date','Manual contract-specific provider ID issue date.','DATE','MANUAL','contract_template_value.value_text','ACTIVE'),
('provider.id_issue_place','provider','id_issue_place','Provider ID Issue Place','Manual contract-specific provider ID issue place.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('contract.signing_place','contract','signing_place','Place','Manual contract signing place.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('contract.jurisdiction_place','contract','jurisdiction_place','City / Municipality / Province','Manual city, municipality, or province for contract wording.','STRING','MANUAL','contract_template_value.value_text','ACTIVE'),
('contract.signing_date','contract','signing_date','Date','Manual contract signing date for template wording.','DATE','MANUAL','contract_template_value.value_text','ACTIVE'),
('contract.number_of_pages','contract','number_of_pages','Number Of Pages','Manual page count placeholder until final rendering can calculate this reliably.','STRING','MANUAL','contract_template_value.value_text','ACTIVE')
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  description = VALUES(description),
  data_type = VALUES(data_type),
  source_type = VALUES(source_type),
  source_field = VALUES(source_field),
  status = VALUES(status);
