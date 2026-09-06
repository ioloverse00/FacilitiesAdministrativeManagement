-- DEVELOPMENT / UAT SAFE REPAIR
-- Contract Management authoritative document confidentiality correction.
--
-- Purpose:
--   Correct only authoritative FAM document masters linked to Contract Management
--   contracts through Google synchronization and/or signed-copy relationships.
--   This does not touch unrelated INTERNAL documents, document versions, files,
--   client-requirement evidence, templates, records, or OTP/security logic.
--
-- Usage:
--   Review the preflight SELECT first. Execute the UPDATE only after confirming
--   the returned rows are Contract Management authoritative contract artifacts.

-- Preflight: rows that would be corrected.
SELECT DISTINCT
  d.document_id,
  d.document_number,
  d.document_title,
  d.confidentiality_level,
  c.contract_id,
  c.contract_number,
  CASE
    WHEN cgd.synced_document_id = d.document_id AND c.signed_document_id = d.document_id THEN 'google_synced_and_signed_contract_document'
    WHEN cgd.synced_document_id = d.document_id THEN 'google_synced_contract_document'
    WHEN c.signed_document_id = d.document_id THEN 'signed_contract_document'
    ELSE 'not_contract_authoritative_document'
  END AS contract_document_link
FROM document d
INNER JOIN contract c
  ON c.signed_document_id = d.document_id
LEFT JOIN contract_google_document cgd
  ON cgd.contract_id = c.contract_id
  AND cgd.synced_document_id = d.document_id
WHERE d.deleted_at IS NULL
  AND c.deleted_at IS NULL
  AND d.confidentiality_level <> 'CONFIDENTIAL'
UNION
SELECT DISTINCT
  d.document_id,
  d.document_number,
  d.document_title,
  d.confidentiality_level,
  c.contract_id,
  c.contract_number,
  CASE
    WHEN cgd.synced_document_id = d.document_id AND c.signed_document_id = d.document_id THEN 'google_synced_and_signed_contract_document'
    WHEN cgd.synced_document_id = d.document_id THEN 'google_synced_contract_document'
    WHEN c.signed_document_id = d.document_id THEN 'signed_contract_document'
    ELSE 'not_contract_authoritative_document'
  END AS contract_document_link
FROM document d
INNER JOIN contract_google_document cgd
  ON cgd.synced_document_id = d.document_id
INNER JOIN contract c
  ON c.contract_id = cgd.contract_id
WHERE d.deleted_at IS NULL
  AND c.deleted_at IS NULL
  AND d.confidentiality_level <> 'CONFIDENTIAL'
ORDER BY contract_number, document_number;

-- Repair: targeted and idempotent.
UPDATE document d
INNER JOIN (
  SELECT DISTINCT authoritative_document_id
  FROM (
    SELECT c.signed_document_id AS authoritative_document_id
    FROM contract c
    WHERE c.deleted_at IS NULL
      AND c.signed_document_id IS NOT NULL
    UNION
    SELECT cgd.synced_document_id AS authoritative_document_id
    FROM contract_google_document cgd
    INNER JOIN contract c
      ON c.contract_id = cgd.contract_id
    WHERE c.deleted_at IS NULL
      AND cgd.synced_document_id IS NOT NULL
  ) linked
  WHERE authoritative_document_id IS NOT NULL
) contract_docs
  ON contract_docs.authoritative_document_id = d.document_id
SET d.confidentiality_level = 'CONFIDENTIAL',
    d.updated_at = NOW()
WHERE d.deleted_at IS NULL
  AND d.confidentiality_level <> 'CONFIDENTIAL';
