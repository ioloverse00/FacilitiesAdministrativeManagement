ALTER TABLE legal_matter_action_suggestion
  ADD COLUMN IF NOT EXISTS deadline_basis varchar(30) NULL AFTER suggested_due_at,
  ADD COLUMN IF NOT EXISTS recommendation_reason varchar(500) NULL AFTER source_context,
  ADD COLUMN IF NOT EXISTS recommended_business_days int(10) unsigned NULL AFTER recommendation_reason;

UPDATE legal_matter_action_suggestion
SET deadline_basis = COALESCE(deadline_basis, NULLIF(date_basis, ''))
WHERE deadline_basis IS NULL;

ALTER TABLE legal_matter_action
  ADD COLUMN IF NOT EXISTS suggestion_id bigint(20) unsigned NULL AFTER source,
  ADD COLUMN IF NOT EXISTS deadline_basis varchar(30) NULL AFTER source_document_version_id,
  ADD COLUMN IF NOT EXISTS recommendation_reason varchar(500) NULL AFTER deadline_basis;
