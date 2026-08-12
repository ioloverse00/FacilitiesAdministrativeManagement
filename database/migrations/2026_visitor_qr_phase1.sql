-- Phase 1 visitor pass QR token support.

ALTER TABLE visit
  ADD COLUMN IF NOT EXISTS qr_token_hash CHAR(64) NULL AFTER consent_version,
  ADD COLUMN IF NOT EXISTS qr_token_created_at DATETIME NULL AFTER qr_token_hash,
  ADD COLUMN IF NOT EXISTS qr_token_expires_at DATETIME NULL AFTER qr_token_created_at,
  ADD COLUMN IF NOT EXISTS qr_token_revoked_at DATETIME NULL AFTER qr_token_expires_at,
  ADD UNIQUE KEY IF NOT EXISTS uq_visit_qr_token_hash (qr_token_hash),
  ADD INDEX IF NOT EXISTS idx_visit_qr_token_state (qr_token_expires_at, qr_token_revoked_at);

