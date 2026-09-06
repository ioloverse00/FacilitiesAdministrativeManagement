-- Confidential document Email OTP step-up protection.
-- Run after 2026_document_management_foundation.sql.

CREATE TABLE IF NOT EXISTS document_otp_challenge (
    challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenge_uuid CHAR(36) NOT NULL,
    user_account_id BIGINT UNSIGNED NOT NULL,
    session_hash CHAR(64) NOT NULL,
    purpose VARCHAR(80) NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    last_sent_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (challenge_id),
    UNIQUE KEY uq_document_otp_challenge_uuid (challenge_uuid),
    KEY idx_document_otp_active (user_account_id, session_hash, purpose, document_id, consumed_at, expires_at),
    KEY idx_document_otp_document (document_id),
    CONSTRAINT fk_document_otp_user FOREIGN KEY (user_account_id) REFERENCES user_account (user_account_id),
    CONSTRAINT fk_document_otp_document FOREIGN KEY (document_id) REFERENCES document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
