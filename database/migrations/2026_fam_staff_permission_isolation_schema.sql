-- FAM staff permission isolation schema support.
-- Forward-only schema migration.
-- Contains only the direct schema dependency required by the RBAC migration.

CREATE TABLE IF NOT EXISTS user_permission (
  user_permission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_account_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  granted_by_user_id BIGINT UNSIGNED NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (user_permission_id),
  UNIQUE KEY uq_user_permission (user_account_id, permission_id),
  KEY idx_user_permission_permission (permission_id),
  KEY fk_user_permission_granter (granted_by_user_id),
  CONSTRAINT fk_user_permission_user FOREIGN KEY (user_account_id) REFERENCES user_account (user_account_id) ON DELETE CASCADE,
  CONSTRAINT fk_user_permission_permission FOREIGN KEY (permission_id) REFERENCES permission (permission_id) ON DELETE CASCADE,
  CONSTRAINT fk_user_permission_granter FOREIGN KEY (granted_by_user_id) REFERENCES user_account (user_account_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SELECT
  'user_permission_schema' AS verification_name,
  CASE WHEN COUNT(*) = 1 THEN 'READY' ELSE 'MISSING' END AS table_status
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'user_permission';
