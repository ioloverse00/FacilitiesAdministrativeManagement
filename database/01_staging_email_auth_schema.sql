-- STAGING ONLY: schema step for canonical account email.
-- DO NOT RUN AGAINST PRODUCTION.
--
-- This file is DDL-only and is executed separately before the transactional
-- account-data migration. ALTER TABLE performs implicit commits in MariaDB/MySQL,
-- so this file intentionally does not use START TRANSACTION, COMMIT, or ROLLBACK.
--
-- Manual HostForge workflow:
-- 1) Run this whole file against the selected staging database.
-- 2) Review the post-DDL verification results.
-- 3) Only if correct, proceed to database/02_staging_email_auth_accounts.sql.
--
-- No account emails are populated here.

SELECT
    'pre_schema_selected_database' AS verification_name,
    DATABASE() AS selected_database;

SELECT
    'pre_schema_user_account_table' AS verification_name,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END AS table_status
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_account';

SELECT
    'pre_schema_user_account_email_column' AS verification_name,
    CASE WHEN COUNT(*) = 1 THEN 'EXISTS' ELSE 'MISSING' END AS email_column_status
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_account'
  AND COLUMN_NAME = 'email';

SELECT
    'pre_schema_user_account_email_index' AS verification_name,
    CASE WHEN COUNT(*) = 1 THEN 'EXISTS' ELSE 'MISSING' END AS email_index_status
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_account'
  AND INDEX_NAME = 'uq_user_account_email';

ALTER TABLE user_account
    ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL AFTER username,
    ADD UNIQUE INDEX IF NOT EXISTS uq_user_account_email (email);

SELECT
    'post_schema_user_account_email_column' AS verification_name,
    c.COLUMN_NAME,
    c.COLUMN_TYPE,
    c.IS_NULLABLE,
    c.COLLATION_NAME
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME = 'user_account'
  AND c.COLUMN_NAME = 'email';

SELECT
    'post_schema_user_account_email_index' AS verification_name,
    s.INDEX_NAME,
    s.NON_UNIQUE,
    s.SEQ_IN_INDEX,
    s.COLUMN_NAME,
    s.COLLATION
FROM INFORMATION_SCHEMA.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.TABLE_NAME = 'user_account'
  AND s.INDEX_NAME = 'uq_user_account_email'
ORDER BY s.SEQ_IN_INDEX;

SELECT
    'post_schema_email_value_counts' AS verification_name,
    COUNT(*) AS total_user_accounts,
    SUM(CASE WHEN email IS NULL THEN 1 ELSE 0 END) AS null_email_count,
    SUM(CASE WHEN email IS NOT NULL AND email = '' THEN 1 ELSE 0 END) AS blank_email_count,
    SUM(CASE WHEN email IS NOT NULL AND email <> '' THEN 1 ELSE 0 END) AS nonblank_email_count
FROM user_account;

SELECT
    'post_schema_duplicate_nonblank_emails' AS verification_name,
    LOWER(email) AS normalized_email,
    COUNT(*) AS owner_count,
    GROUP_CONCAT(username ORDER BY username SEPARATOR ', ') AS owners
FROM user_account
WHERE email IS NOT NULL
  AND email <> ''
GROUP BY LOWER(email)
HAVING COUNT(*) > 1
ORDER BY LOWER(email);
