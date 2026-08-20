-- Phase 2 update: Legal supporting evidence plus AI-powered matter summarization.
-- The AI summary is derived Legal Matter metadata. Source files remain owned by
-- Document Management.

ALTER TABLE legal_matter
  ADD COLUMN IF NOT EXISTS initial_note text NULL AFTER summary,
  ADD COLUMN IF NOT EXISTS ai_summary text NULL AFTER initial_note,
  ADD COLUMN IF NOT EXISTS ai_summary_status varchar(30) NOT NULL DEFAULT 'NOT_REQUESTED' AFTER ai_summary,
  ADD COLUMN IF NOT EXISTS ai_summary_generated_at datetime NULL AFTER ai_summary_status,
  ADD COLUMN IF NOT EXISTS ai_summary_provider varchar(60) NULL AFTER ai_summary_generated_at,
  ADD COLUMN IF NOT EXISTS ai_summary_model varchar(120) NULL AFTER ai_summary_provider,
  ADD COLUMN IF NOT EXISTS ai_summary_source_fingerprint varchar(64) NULL AFTER ai_summary_model,
  ADD KEY IF NOT EXISTS idx_legal_matter_ai_summary_status (ai_summary_status);
