-- Secure account password setup token schema.
-- Stores only a one-way digest of the raw one-time setup token.

CREATE TABLE IF NOT EXISTS account_setup_token (
  account_setup_token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_account_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  request_ip VARCHAR(45) NULL,
  consumed_ip VARCHAR(45) NULL,
  request_user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (account_setup_token_id),
  UNIQUE KEY uq_account_setup_token_hash (token_hash),
  KEY idx_account_setup_token_user (user_account_id, consumed_at, expires_at),
  KEY idx_account_setup_token_requester (requested_by_user_id),
  CONSTRAINT fk_account_setup_token_user FOREIGN KEY (user_account_id) REFERENCES user_account (user_account_id) ON DELETE CASCADE,
  CONSTRAINT fk_account_setup_token_requester FOREIGN KEY (requested_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
