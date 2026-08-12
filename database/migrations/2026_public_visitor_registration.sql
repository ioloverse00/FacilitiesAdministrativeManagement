-- Public Visitor Registration Portal with email OTP verification.

ALTER TABLE visit
  MODIFY host_employee_reference_id BIGINT UNSIGNED NULL,
  MODIFY scheduled_arrival DATETIME NULL,
  ADD COLUMN IF NOT EXISTS privacy_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER company_or_school,
  ADD COLUMN IF NOT EXISTS consented_at DATETIME NULL AFTER privacy_consent,
  ADD COLUMN IF NOT EXISTS consent_version VARCHAR(80) NULL AFTER consented_at;

CREATE TABLE IF NOT EXISTS visitor_registration_challenge (
  visitor_registration_challenge_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  challenge_uuid CHAR(36) NOT NULL,
  email_address VARCHAR(190) NOT NULL,
  payload_json JSON NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  otp_expires_at DATETIME NOT NULL,
  otp_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  otp_max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  resend_available_at DATETIME NULL,
  verified_at DATETIME NULL,
  verification_token_hash VARCHAR(255) NULL,
  consumed_at DATETIME NULL,
  submitted_visit_id BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_visitor_registration_challenge_uuid (challenge_uuid),
  INDEX idx_visitor_registration_email_created (email_address, created_at),
  INDEX idx_visitor_registration_status (status),
  CONSTRAINT fk_visitor_registration_challenge_visit FOREIGN KEY (submitted_visit_id) REFERENCES visit(visit_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

