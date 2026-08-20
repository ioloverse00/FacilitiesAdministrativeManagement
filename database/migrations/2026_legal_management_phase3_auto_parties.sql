-- Phase 3 refinement: AI-extracted parties become active parties with provenance and review state.

ALTER TABLE legal_matter_party
  ADD COLUMN IF NOT EXISTS party_source VARCHAR(20) NOT NULL DEFAULT 'MANUAL' AFTER ai_suggested,
  ADD COLUMN IF NOT EXISTS review_status VARCHAR(30) NOT NULL DEFAULT 'REVIEWED' AFTER party_source,
  ADD COLUMN IF NOT EXISTS dismissal_reason VARCHAR(255) NULL AFTER review_status;

UPDATE legal_matter_party
SET party_source = CASE WHEN ai_suggested = 1 THEN 'AI' ELSE 'MANUAL' END,
    review_status = CASE WHEN ai_suggested = 1 AND confirmed_by_user_id IS NULL THEN 'PENDING_REVIEW' ELSE 'REVIEWED' END
WHERE party_source IS NULL OR party_source = '';

INSERT INTO legal_matter_party (
  legal_matter_id,
  party_role,
  party_type,
  external_name,
  organization_name,
  notes,
  source_document_id,
  ai_suggested,
  party_source,
  review_status,
  created_at,
  updated_at
)
SELECT
  s.legal_matter_id,
  s.suggested_party_role,
  s.suggested_party_type,
  s.suggested_name,
  s.suggested_organization,
  s.context,
  s.source_document_id,
  1,
  'AI',
  'PENDING_REVIEW',
  NOW(),
  NOW()
FROM legal_matter_party_suggestion s
WHERE s.status = 'PENDING'
  AND NOT EXISTS (
    SELECT 1
    FROM legal_matter_party p
    WHERE p.legal_matter_id = s.legal_matter_id
      AND p.party_role = s.suggested_party_role
      AND p.party_type = s.suggested_party_type
      AND LOWER(REPLACE(COALESCE(p.external_name, p.organization_name, ''), ' ', '')) = LOWER(REPLACE(s.suggested_name, ' ', ''))
  );

UPDATE legal_matter_party_suggestion
SET status = 'ACCEPTED', updated_at = NOW()
WHERE status = 'PENDING';
