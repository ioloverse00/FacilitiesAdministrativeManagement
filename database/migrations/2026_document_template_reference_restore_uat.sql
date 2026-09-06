-- DEVELOPMENT / UAT ONLY
-- Non-destructive reference/configuration restoration for Document Template Management.
-- Safe to run after a UAT transactional reset if the template merge-field registry
-- or canonical contract/template type rows were removed or deactivated.
-- Does not recreate templates, template versions, documents, records, or files.

START TRANSACTION;

INSERT INTO contract_type (type_code, type_name, description, status)
VALUES
('CLIENT_CONTRACT','Client Contract','Client-facing contract or agreement.','ACTIVE'),
('EMPLOYEE_CONTRACT','Employee Contract','Employee contract or employment-related agreement.','ACTIVE'),
('NDA','NDA / Confidentiality Agreement','Non-disclosure or confidentiality agreement.','ACTIVE'),
('CONTRACT_AMENDMENT','Contract Amendment','Amendment to an existing contract.','ACTIVE'),
('OTHER','Other','Other contract or agreement type.','ACTIVE')
ON DUPLICATE KEY UPDATE
  type_name = VALUES(type_name),
  description = VALUES(description),
  status = VALUES(status);

INSERT INTO document_category (category_code, category_name, description, default_confidentiality_level, status)
VALUES
('DOC-CON','Contracts','Contracts and agreements.','CONFIDENTIAL','ACTIVE')
ON DUPLICATE KEY UPDATE
  category_name = VALUES(category_name),
  description = VALUES(description),
  default_confidentiality_level = VALUES(default_confidentiality_level),
  status = VALUES(status);

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
('agency.name','agency','name','Agency Name','Future agency/legal entity name.','STRING','FUTURE','FUTURE','ACTIVE'),
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

COMMIT;

-- Verification:
-- SELECT type_code, type_name, status
-- FROM contract_type
-- WHERE type_code IN ('CLIENT_CONTRACT','EMPLOYEE_CONTRACT','NDA','CONTRACT_AMENDMENT','OTHER')
-- ORDER BY FIELD(type_code,'CLIENT_CONTRACT','EMPLOYEE_CONTRACT','NDA','CONTRACT_AMENDMENT','OTHER');
--
-- SELECT category_code, default_confidentiality_level, status
-- FROM document_category
-- WHERE category_code = 'DOC-CON';
--
-- SELECT field_code, namespace, status
-- FROM document_template_merge_field
-- ORDER BY namespace, field_name;
