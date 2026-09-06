-- DEVELOPMENT/UAT ONLY
-- Backfill Google-synchronized contract artifact titles from implementation wording
-- to contract business identity.
--
-- This is intentionally narrow:
-- - only documents linked by contract_google_document.synced_document_id;
-- - only records still carrying the legacy generated title;
-- - no template backing documents are moved or deleted.
--
-- Review before running in production.

START TRANSACTION;

UPDATE document d
INNER JOIN contract_google_document cgd
  ON cgd.synced_document_id = d.document_id
INNER JOIN contract c
  ON c.contract_id = cgd.contract_id
SET
  d.document_title = CASE
    WHEN TRIM(COALESCE(c.contract_title, '')) = ''
      OR TRIM(c.contract_title) = TRIM(c.contract_number)
      THEN CONCAT(TRIM(c.contract_number), ' - Contract')
    ELSE CONCAT(TRIM(c.contract_number), ' - ', TRIM(c.contract_title))
  END,
  d.document_description = CONCAT('Draft contract artifact for ', TRIM(COALESCE(c.contract_title, 'Contract')), '. Authoring provider: Google Docs.'),
  d.updated_at = NOW()
WHERE d.deleted_at IS NULL
  AND d.document_title = CONCAT(TRIM(c.contract_number), ' Google Working Copy');

COMMIT;
