-- DEVELOPMENT/UAT ONLY
-- Align Contract Management contract types with Document Template Management template types.
-- This migration does not rewrite existing contract rows.

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

UPDATE contract_type
SET status = 'INACTIVE',
    description = CASE
      WHEN description LIKE '%Legacy contract type retained for historical records.%' THEN description
      ELSE CONCAT(COALESCE(NULLIF(description, ''), type_name), ' Legacy contract type retained for historical records.')
    END
WHERE type_code IN ('SERVICE','SUPPLY','LEASE','MAINTENANCE');

COMMIT;
