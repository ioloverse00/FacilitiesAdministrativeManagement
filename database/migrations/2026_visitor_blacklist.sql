-- Visitor blacklist support.
-- Apply this migration before deploying blacklist application code.
-- Blacklist rows preserve blacklist/unblacklist lifecycle for existing visitor records.

CREATE TABLE visitor_blacklist (
  visitor_blacklist_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_id BIGINT UNSIGNED NOT NULL,
  reason TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  blacklisted_by_user_id BIGINT UNSIGNED NULL,
  blacklisted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_by_user_id BIGINT UNSIGNED NULL,
  removed_at DATETIME NULL,
  removal_reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (visitor_blacklist_id),
  KEY idx_visitor_blacklist_visitor_status (visitor_id, status),
  KEY idx_visitor_blacklist_status (status),
  CONSTRAINT fk_visitor_blacklist_visitor
    FOREIGN KEY (visitor_id) REFERENCES visitor(visitor_id),
  CONSTRAINT fk_visitor_blacklist_blacklisted_by
    FOREIGN KEY (blacklisted_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
  CONSTRAINT fk_visitor_blacklist_removed_by
    FOREIGN KEY (removed_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL
);
