CREATE TABLE IF NOT EXISTS reservation_request_letter (
    reservation_request_letter_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    facility_reservation_id BIGINT UNSIGNED NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    stored_file_name VARCHAR(255) NOT NULL,
    file_extension VARCHAR(20) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (reservation_request_letter_id),
    UNIQUE KEY uq_reservation_request_letter_active (facility_reservation_id, deleted_at),
    KEY idx_reservation_request_letter_reservation (facility_reservation_id),
    KEY idx_reservation_request_letter_uploaded_by (uploaded_by_user_id),
    CONSTRAINT fk_reservation_request_letter_reservation
        FOREIGN KEY (facility_reservation_id) REFERENCES facility_reservation (facility_reservation_id),
    CONSTRAINT fk_reservation_request_letter_uploaded_by
        FOREIGN KEY (uploaded_by_user_id) REFERENCES user_account (user_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE facility_reservation
    ADD COLUMN IF NOT EXISTS ai_request_summary TEXT NULL AFTER remarks,
    ADD COLUMN IF NOT EXISTS ai_request_summary_status VARCHAR(30) NOT NULL DEFAULT 'NOT_REQUESTED' AFTER ai_request_summary,
    ADD COLUMN IF NOT EXISTS ai_request_summary_generated_at DATETIME NULL AFTER ai_request_summary_status,
    ADD COLUMN IF NOT EXISTS ai_request_summary_provider VARCHAR(60) NULL AFTER ai_request_summary_generated_at,
    ADD COLUMN IF NOT EXISTS ai_request_summary_model VARCHAR(120) NULL AFTER ai_request_summary_provider,
    ADD COLUMN IF NOT EXISTS ai_request_summary_failure_reason VARCHAR(120) NULL AFTER ai_request_summary_model;
