-- STAGING ONLY: canonical authentication email dot-notation cleanup.
-- Do not run against production.
--
-- Purpose:
-- Rename only the 10 active canonical user_account.email values from
-- underscore-style addresses to dot-style addresses.
--
-- Scope:
-- - Mutates only user_account.email and user_account.updated_at.
-- - Does not change usernames, password_hash, employee_reference rows,
--   roles, permissions, ownership, attribution, MFA/session tables, or HR
--   contact email data.
--
-- HostForge note:
-- START TRANSACTION and COMMIT are intentionally in the same SQL submission.

-- Pre-change verification: exact target accounts and expected current emails.
SELECT
    'pre_target_accounts' AS verification_name,
    target.user_account_id AS expected_user_account_id,
    target.username AS expected_username,
    target.employee_reference_id AS expected_employee_reference_id,
    target.old_email AS expected_current_email,
    target.new_email AS target_new_email,
    ua.user_account_id AS actual_user_account_id,
    ua.username AS actual_username,
    ua.employee_reference_id AS actual_employee_reference_id,
    ua.email AS actual_current_email,
    ua.account_status,
    ua.deleted_at,
    CASE
        WHEN ua.user_account_id = target.user_account_id
         AND ua.username = target.username
         AND ua.employee_reference_id = target.employee_reference_id
         AND ua.email = target.old_email
         AND ua.account_status = 'ACTIVE'
         AND ua.deleted_at IS NULL
        THEN 'OK'
        ELSE 'STOP_REVIEW_REQUIRED'
    END AS result
FROM (
    SELECT 16 user_account_id, 'gsms-super-admin' username, 16 employee_reference_id, 'fam_superadmin@greatsolomonmpservices.com' old_email, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
    SELECT 17, 'gsms-fam-admin', 17, 'fam_admin@greatsolomonmpservices.com', 'fam.admin@greatsolomonmpservices.com' UNION ALL
    SELECT 22, 'reservation.officer', 24, 'fam_reservation@greatsolomonmpservices.com', 'fam.reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'records.officer', 26, 'fam_recordsofficer@greatsolomonmpservices.com', 'fam.records@greatsolomonmpservices.com' UNION ALL
    SELECT 27, 'gsms-hr-head', 30, 'fam_hr@greatsolomonmpservices.com', 'hr.head@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'gsms-it-head', 32, 'fam_it@greatsolomonmpservices.com', 'it.head@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'gsms-fin-head', 28, 'fam_fin@greatsolomonmpservices.com', 'finance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'gsms-maint-head', 21, 'fam_maintenance@greatsolomonmpservices.com', 'maintenance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 31, 'gsms-records-head', 29, 'fam_records@greatsolomonmpservices.com', 'records.head@greatsolomonmpservices.com' UNION ALL
    SELECT 23, 'gsms-scm-head', 25, 'fam_supplychain@greatsolomonmpservices.com', 'supplychain.head@greatsolomonmpservices.com'
) target
LEFT JOIN user_account ua
    ON ua.user_account_id = target.user_account_id
ORDER BY target.user_account_id;

-- Expected: 10 rows with result OK.
SELECT
    'pre_target_ready_count' AS verification_name,
    SUM(CASE
        WHEN ua.user_account_id = target.user_account_id
         AND ua.username = target.username
         AND ua.employee_reference_id = target.employee_reference_id
         AND ua.email = target.old_email
         AND ua.account_status = 'ACTIVE'
         AND ua.deleted_at IS NULL
        THEN 1 ELSE 0
    END) AS actual_ready_count,
    10 AS expected_ready_count
FROM (
    SELECT 16 user_account_id, 'gsms-super-admin' username, 16 employee_reference_id, 'fam_superadmin@greatsolomonmpservices.com' old_email, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
    SELECT 17, 'gsms-fam-admin', 17, 'fam_admin@greatsolomonmpservices.com', 'fam.admin@greatsolomonmpservices.com' UNION ALL
    SELECT 22, 'reservation.officer', 24, 'fam_reservation@greatsolomonmpservices.com', 'fam.reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'records.officer', 26, 'fam_recordsofficer@greatsolomonmpservices.com', 'fam.records@greatsolomonmpservices.com' UNION ALL
    SELECT 27, 'gsms-hr-head', 30, 'fam_hr@greatsolomonmpservices.com', 'hr.head@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'gsms-it-head', 32, 'fam_it@greatsolomonmpservices.com', 'it.head@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'gsms-fin-head', 28, 'fam_fin@greatsolomonmpservices.com', 'finance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'gsms-maint-head', 21, 'fam_maintenance@greatsolomonmpservices.com', 'maintenance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 31, 'gsms-records-head', 29, 'fam_records@greatsolomonmpservices.com', 'records.head@greatsolomonmpservices.com' UNION ALL
    SELECT 23, 'gsms-scm-head', 25, 'fam_supplychain@greatsolomonmpservices.com', 'supplychain.head@greatsolomonmpservices.com'
) target
LEFT JOIN user_account ua
    ON ua.user_account_id = target.user_account_id;

-- Expected: no rows. Any row means a dot-notation target email is already owned by another account.
SELECT
    'pre_target_email_conflicts' AS verification_name,
    target.username,
    target.new_email,
    owner.user_account_id AS conflicting_user_account_id,
    owner.username AS conflicting_username,
    owner.email AS conflicting_email
FROM (
    SELECT 16 user_account_id, 'gsms-super-admin' username, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
    SELECT 17, 'gsms-fam-admin', 'fam.admin@greatsolomonmpservices.com' UNION ALL
    SELECT 22, 'reservation.officer', 'fam.reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'records.officer', 'fam.records@greatsolomonmpservices.com' UNION ALL
    SELECT 27, 'gsms-hr-head', 'hr.head@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'gsms-it-head', 'it.head@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'gsms-fin-head', 'finance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'gsms-maint-head', 'maintenance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 31, 'gsms-records-head', 'records.head@greatsolomonmpservices.com' UNION ALL
    SELECT 23, 'gsms-scm-head', 'supplychain.head@greatsolomonmpservices.com'
) target
INNER JOIN user_account owner
    ON LOWER(owner.email) = LOWER(target.new_email)
   AND owner.user_account_id <> target.user_account_id
ORDER BY target.user_account_id;

-- Expected: no rows. The proposed new emails must be unique within this target list.
SELECT
    'pre_duplicate_target_emails' AS verification_name,
    LOWER(new_email) AS normalized_new_email,
    COUNT(*) AS target_count,
    GROUP_CONCAT(username ORDER BY username SEPARATOR ', ') AS target_usernames
FROM (
    SELECT 'gsms-super-admin' username, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
    SELECT 'gsms-fam-admin', 'fam.admin@greatsolomonmpservices.com' UNION ALL
    SELECT 'reservation.officer', 'fam.reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 'records.officer', 'fam.records@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-hr-head', 'hr.head@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-it-head', 'it.head@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-fin-head', 'finance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-maint-head', 'maintenance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-records-head', 'records.head@greatsolomonmpservices.com' UNION ALL
    SELECT 'gsms-scm-head', 'supplychain.head@greatsolomonmpservices.com'
) target
GROUP BY LOWER(new_email)
HAVING COUNT(*) > 1;

-- Pre-change password/hash and HR contact visibility. Review only.
SELECT
    'pre_identity_safety_snapshot' AS verification_name,
    ua.user_account_id,
    ua.username,
    ua.employee_reference_id,
    ua.email AS account_email_before,
    CASE WHEN ua.password_hash IS NULL OR ua.password_hash = '' THEN 'STOP_MISSING_PASSWORD_HASH' ELSE 'PRESENT' END AS password_hash_status,
    e.email_address AS employee_reference_email_address_unchanged_by_this_script,
    GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS roles
FROM user_account ua
INNER JOIN employee_reference e
    ON e.employee_reference_id = ua.employee_reference_id
LEFT JOIN user_role ur
    ON ur.user_account_id = ua.user_account_id
   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
LEFT JOIN role r
    ON r.role_id = ur.role_id
WHERE ua.user_account_id IN (16,17,22,23,24,26,27,29,30,31)
GROUP BY ua.user_account_id
ORDER BY ua.user_account_id;

-- Executable precondition guards. These intentionally raise a SQL error if
-- any all-or-nothing prerequisite fails, so the UPDATE cannot partially run.
SELECT
    'guard_exact_10_targets_ready' AS guard_name,
    COALESCE((
        SELECT guard_failure
        FROM (
            SELECT 'ABORT: expected exactly 10 active target accounts with expected old auth emails' AS guard_failure
            UNION ALL
            SELECT 'ABORT: target identity/email precondition failed'
        ) guard_error
        WHERE (
            SELECT SUM(CASE
                WHEN ua.user_account_id = target.user_account_id
                 AND ua.username = target.username
                 AND ua.employee_reference_id = target.employee_reference_id
                 AND ua.email = target.old_email
                 AND ua.account_status = 'ACTIVE'
                 AND ua.deleted_at IS NULL
                THEN 1 ELSE 0
            END)
            FROM (
                SELECT 16 user_account_id, 'gsms-super-admin' username, 16 employee_reference_id, 'fam_superadmin@greatsolomonmpservices.com' old_email UNION ALL
                SELECT 17, 'gsms-fam-admin', 17, 'fam_admin@greatsolomonmpservices.com' UNION ALL
                SELECT 22, 'reservation.officer', 24, 'fam_reservation@greatsolomonmpservices.com' UNION ALL
                SELECT 24, 'records.officer', 26, 'fam_recordsofficer@greatsolomonmpservices.com' UNION ALL
                SELECT 27, 'gsms-hr-head', 30, 'fam_hr@greatsolomonmpservices.com' UNION ALL
                SELECT 29, 'gsms-it-head', 32, 'fam_it@greatsolomonmpservices.com' UNION ALL
                SELECT 26, 'gsms-fin-head', 28, 'fam_fin@greatsolomonmpservices.com' UNION ALL
                SELECT 30, 'gsms-maint-head', 21, 'fam_maintenance@greatsolomonmpservices.com' UNION ALL
                SELECT 31, 'gsms-records-head', 29, 'fam_records@greatsolomonmpservices.com' UNION ALL
                SELECT 23, 'gsms-scm-head', 25, 'fam_supplychain@greatsolomonmpservices.com'
            ) target
            LEFT JOIN user_account ua
                ON ua.user_account_id = target.user_account_id
        ) <> 10
    ), 'OK') AS result;

SELECT
    'guard_no_target_email_conflicts' AS guard_name,
    COALESCE((
        SELECT guard_failure
        FROM (
            SELECT 'ABORT: one or more dot-notation target emails are owned by another account' AS guard_failure
            UNION ALL
            SELECT 'ABORT: target email collision detected'
        ) guard_error
        WHERE (
            SELECT COUNT(*)
            FROM (
                SELECT 16 user_account_id, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
                SELECT 17, 'fam.admin@greatsolomonmpservices.com' UNION ALL
                SELECT 22, 'fam.reservation@greatsolomonmpservices.com' UNION ALL
                SELECT 24, 'fam.records@greatsolomonmpservices.com' UNION ALL
                SELECT 27, 'hr.head@greatsolomonmpservices.com' UNION ALL
                SELECT 29, 'it.head@greatsolomonmpservices.com' UNION ALL
                SELECT 26, 'finance.head@greatsolomonmpservices.com' UNION ALL
                SELECT 30, 'maintenance.head@greatsolomonmpservices.com' UNION ALL
                SELECT 31, 'records.head@greatsolomonmpservices.com' UNION ALL
                SELECT 23, 'supplychain.head@greatsolomonmpservices.com'
            ) target
            INNER JOIN user_account owner
                ON LOWER(owner.email) = LOWER(target.new_email)
               AND owner.user_account_id <> target.user_account_id
        ) <> 0
    ), 'OK') AS result;

SELECT
    'guard_target_emails_unique' AS guard_name,
    COALESCE((
        SELECT guard_failure
        FROM (
            SELECT 'ABORT: proposed dot-notation target emails are not unique' AS guard_failure
            UNION ALL
            SELECT 'ABORT: duplicate target email detected'
        ) guard_error
        WHERE (
            SELECT COUNT(*)
            FROM (
                SELECT LOWER(new_email) normalized_new_email
                FROM (
                    SELECT 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
                    SELECT 'fam.admin@greatsolomonmpservices.com' UNION ALL
                    SELECT 'fam.reservation@greatsolomonmpservices.com' UNION ALL
                    SELECT 'fam.records@greatsolomonmpservices.com' UNION ALL
                    SELECT 'hr.head@greatsolomonmpservices.com' UNION ALL
                    SELECT 'it.head@greatsolomonmpservices.com' UNION ALL
                    SELECT 'finance.head@greatsolomonmpservices.com' UNION ALL
                    SELECT 'maintenance.head@greatsolomonmpservices.com' UNION ALL
                    SELECT 'records.head@greatsolomonmpservices.com' UNION ALL
                    SELECT 'supplychain.head@greatsolomonmpservices.com'
                ) target
                GROUP BY LOWER(new_email)
                HAVING COUNT(*) > 1
            ) duplicates
        ) <> 0
    ), 'OK') AS result;

SELECT
    'guard_all_password_hashes_present' AS guard_name,
    COALESCE((
        SELECT guard_failure
        FROM (
            SELECT 'ABORT: one or more target accounts has a missing password_hash' AS guard_failure
            UNION ALL
            SELECT 'ABORT: password_hash precondition failed'
        ) guard_error
        WHERE (
            SELECT COUNT(*)
            FROM user_account ua
            WHERE ua.user_account_id IN (16,17,22,23,24,26,27,29,30,31)
              AND ua.password_hash IS NOT NULL
              AND ua.password_hash <> ''
        ) <> 10
    ), 'OK') AS result;

START TRANSACTION;

UPDATE user_account ua
INNER JOIN (
    SELECT 16 user_account_id, 'gsms-super-admin' username, 16 employee_reference_id, 'fam_superadmin@greatsolomonmpservices.com' old_email, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
    SELECT 17, 'gsms-fam-admin', 17, 'fam_admin@greatsolomonmpservices.com', 'fam.admin@greatsolomonmpservices.com' UNION ALL
    SELECT 22, 'reservation.officer', 24, 'fam_reservation@greatsolomonmpservices.com', 'fam.reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'records.officer', 26, 'fam_recordsofficer@greatsolomonmpservices.com', 'fam.records@greatsolomonmpservices.com' UNION ALL
    SELECT 27, 'gsms-hr-head', 30, 'fam_hr@greatsolomonmpservices.com', 'hr.head@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'gsms-it-head', 32, 'fam_it@greatsolomonmpservices.com', 'it.head@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'gsms-fin-head', 28, 'fam_fin@greatsolomonmpservices.com', 'finance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'gsms-maint-head', 21, 'fam_maintenance@greatsolomonmpservices.com', 'maintenance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 31, 'gsms-records-head', 29, 'fam_records@greatsolomonmpservices.com', 'records.head@greatsolomonmpservices.com' UNION ALL
    SELECT 23, 'gsms-scm-head', 25, 'fam_supplychain@greatsolomonmpservices.com', 'supplychain.head@greatsolomonmpservices.com'
) target
    ON target.user_account_id = ua.user_account_id
   AND target.username = ua.username
   AND target.employee_reference_id = ua.employee_reference_id
   AND target.old_email = ua.email
LEFT JOIN user_account owner
    ON LOWER(owner.email) = LOWER(target.new_email)
   AND owner.user_account_id <> target.user_account_id
SET
    ua.email = target.new_email,
    ua.updated_at = NOW()
WHERE ua.account_status = 'ACTIVE'
  AND ua.deleted_at IS NULL
  AND owner.user_account_id IS NULL
  -- Defensive all-or-nothing predicates: even if a SQL client continues after
  -- a precondition guard error, the UPDATE can affect either all 10 rows or 0
  -- rows. It cannot update a partial subset.
  AND (
      SELECT SUM(CASE
          WHEN check_ua.user_account_id = check_target.user_account_id
           AND check_ua.username = check_target.username
           AND check_ua.employee_reference_id = check_target.employee_reference_id
           AND check_ua.email = check_target.old_email
           AND check_ua.account_status = 'ACTIVE'
           AND check_ua.deleted_at IS NULL
          THEN 1 ELSE 0
      END)
      FROM (
          SELECT 16 user_account_id, 'gsms-super-admin' username, 16 employee_reference_id, 'fam_superadmin@greatsolomonmpservices.com' old_email UNION ALL
          SELECT 17, 'gsms-fam-admin', 17, 'fam_admin@greatsolomonmpservices.com' UNION ALL
          SELECT 22, 'reservation.officer', 24, 'fam_reservation@greatsolomonmpservices.com' UNION ALL
          SELECT 24, 'records.officer', 26, 'fam_recordsofficer@greatsolomonmpservices.com' UNION ALL
          SELECT 27, 'gsms-hr-head', 30, 'fam_hr@greatsolomonmpservices.com' UNION ALL
          SELECT 29, 'gsms-it-head', 32, 'fam_it@greatsolomonmpservices.com' UNION ALL
          SELECT 26, 'gsms-fin-head', 28, 'fam_fin@greatsolomonmpservices.com' UNION ALL
          SELECT 30, 'gsms-maint-head', 21, 'fam_maintenance@greatsolomonmpservices.com' UNION ALL
          SELECT 31, 'gsms-records-head', 29, 'fam_records@greatsolomonmpservices.com' UNION ALL
          SELECT 23, 'gsms-scm-head', 25, 'fam_supplychain@greatsolomonmpservices.com'
      ) check_target
      LEFT JOIN user_account check_ua
          ON check_ua.user_account_id = check_target.user_account_id
  ) = 10
  AND (
      SELECT COUNT(*)
      FROM (
          SELECT 16 user_account_id, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
          SELECT 17, 'fam.admin@greatsolomonmpservices.com' UNION ALL
          SELECT 22, 'fam.reservation@greatsolomonmpservices.com' UNION ALL
          SELECT 24, 'fam.records@greatsolomonmpservices.com' UNION ALL
          SELECT 27, 'hr.head@greatsolomonmpservices.com' UNION ALL
          SELECT 29, 'it.head@greatsolomonmpservices.com' UNION ALL
          SELECT 26, 'finance.head@greatsolomonmpservices.com' UNION ALL
          SELECT 30, 'maintenance.head@greatsolomonmpservices.com' UNION ALL
          SELECT 31, 'records.head@greatsolomonmpservices.com' UNION ALL
          SELECT 23, 'supplychain.head@greatsolomonmpservices.com'
      ) conflict_target
      INNER JOIN user_account conflict_owner
          ON LOWER(conflict_owner.email) = LOWER(conflict_target.new_email)
         AND conflict_owner.user_account_id <> conflict_target.user_account_id
  ) = 0
  AND (
      SELECT COUNT(*)
      FROM (
          SELECT LOWER(new_email) normalized_new_email
          FROM (
              SELECT 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
              SELECT 'fam.admin@greatsolomonmpservices.com' UNION ALL
              SELECT 'fam.reservation@greatsolomonmpservices.com' UNION ALL
              SELECT 'fam.records@greatsolomonmpservices.com' UNION ALL
              SELECT 'hr.head@greatsolomonmpservices.com' UNION ALL
              SELECT 'it.head@greatsolomonmpservices.com' UNION ALL
              SELECT 'finance.head@greatsolomonmpservices.com' UNION ALL
              SELECT 'maintenance.head@greatsolomonmpservices.com' UNION ALL
              SELECT 'records.head@greatsolomonmpservices.com' UNION ALL
              SELECT 'supplychain.head@greatsolomonmpservices.com'
          ) unique_target
          GROUP BY LOWER(new_email)
          HAVING COUNT(*) > 1
      ) duplicate_target
  ) = 0
  AND (
      SELECT COUNT(*)
      FROM user_account hash_ua
      WHERE hash_ua.user_account_id IN (16,17,22,23,24,26,27,29,30,31)
        AND hash_ua.password_hash IS NOT NULL
        AND hash_ua.password_hash <> ''
  ) = 10;

-- Post-change verification: exactly 10 target rows should now hold dot-notation auth emails.
SELECT
    'post_target_accounts' AS verification_name,
    target.user_account_id AS expected_user_account_id,
    target.username AS expected_username,
    target.employee_reference_id AS expected_employee_reference_id,
    target.old_email,
    target.new_email AS expected_new_email,
    ua.email AS actual_account_email,
    ua.account_status,
    ua.deleted_at,
    CASE
        WHEN ua.user_account_id = target.user_account_id
         AND ua.username = target.username
         AND ua.employee_reference_id = target.employee_reference_id
         AND ua.email = target.new_email
         AND ua.account_status = 'ACTIVE'
         AND ua.deleted_at IS NULL
        THEN 'OK'
        ELSE 'CHECK_REQUIRED'
    END AS result
FROM (
    SELECT 16 user_account_id, 'gsms-super-admin' username, 16 employee_reference_id, 'fam_superadmin@greatsolomonmpservices.com' old_email, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
    SELECT 17, 'gsms-fam-admin', 17, 'fam_admin@greatsolomonmpservices.com', 'fam.admin@greatsolomonmpservices.com' UNION ALL
    SELECT 22, 'reservation.officer', 24, 'fam_reservation@greatsolomonmpservices.com', 'fam.reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'records.officer', 26, 'fam_recordsofficer@greatsolomonmpservices.com', 'fam.records@greatsolomonmpservices.com' UNION ALL
    SELECT 27, 'gsms-hr-head', 30, 'fam_hr@greatsolomonmpservices.com', 'hr.head@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'gsms-it-head', 32, 'fam_it@greatsolomonmpservices.com', 'it.head@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'gsms-fin-head', 28, 'fam_fin@greatsolomonmpservices.com', 'finance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'gsms-maint-head', 21, 'fam_maintenance@greatsolomonmpservices.com', 'maintenance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 31, 'gsms-records-head', 29, 'fam_records@greatsolomonmpservices.com', 'records.head@greatsolomonmpservices.com' UNION ALL
    SELECT 23, 'gsms-scm-head', 25, 'fam_supplychain@greatsolomonmpservices.com', 'supplychain.head@greatsolomonmpservices.com'
) target
LEFT JOIN user_account ua
    ON ua.user_account_id = target.user_account_id
ORDER BY target.user_account_id;

SELECT
    'post_target_updated_count' AS verification_name,
    COUNT(*) AS actual_count,
    10 AS expected_count
FROM user_account ua
INNER JOIN (
    SELECT 16 user_account_id, 'fam.superadmin@greatsolomonmpservices.com' new_email UNION ALL
    SELECT 17, 'fam.admin@greatsolomonmpservices.com' UNION ALL
    SELECT 22, 'fam.reservation@greatsolomonmpservices.com' UNION ALL
    SELECT 24, 'fam.records@greatsolomonmpservices.com' UNION ALL
    SELECT 27, 'hr.head@greatsolomonmpservices.com' UNION ALL
    SELECT 29, 'it.head@greatsolomonmpservices.com' UNION ALL
    SELECT 26, 'finance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 30, 'maintenance.head@greatsolomonmpservices.com' UNION ALL
    SELECT 31, 'records.head@greatsolomonmpservices.com' UNION ALL
    SELECT 23, 'supplychain.head@greatsolomonmpservices.com'
) target
    ON target.user_account_id = ua.user_account_id
   AND target.new_email = ua.email
WHERE ua.account_status = 'ACTIVE'
  AND ua.deleted_at IS NULL;

SELECT
    'post_duplicate_nonblank_account_emails' AS verification_name,
    LOWER(email) AS normalized_email,
    COUNT(*) AS owner_count,
    GROUP_CONCAT(username ORDER BY username SEPARATOR ', ') AS owners
FROM user_account
WHERE email IS NOT NULL
  AND email <> ''
GROUP BY LOWER(email)
HAVING COUNT(*) > 1
ORDER BY LOWER(email);

SELECT
    'post_identity_safety_snapshot' AS verification_name,
    ua.user_account_id,
    ua.username,
    ua.employee_reference_id,
    ua.email AS account_email_after,
    CASE WHEN ua.password_hash IS NULL OR ua.password_hash = '' THEN 'CHECK_REQUIRED' ELSE 'PRESENT' END AS password_hash_status,
    e.email_address AS employee_reference_email_address_unchanged_by_this_script,
    GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS roles
FROM user_account ua
INNER JOIN employee_reference e
    ON e.employee_reference_id = ua.employee_reference_id
LEFT JOIN user_role ur
    ON ur.user_account_id = ua.user_account_id
   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
LEFT JOIN role r
    ON r.role_id = ur.role_id
WHERE ua.user_account_id IN (16,17,22,23,24,26,27,29,30,31)
GROUP BY ua.user_account_id
ORDER BY ua.user_account_id;

COMMIT;

-- Optional fresh-session read-only persistence verification after HostForge execution:
-- SELECT 'fresh_dot_email_target_count' AS verification_name, COUNT(*) AS actual_count, 10 AS expected_count
-- FROM user_account
-- WHERE (user_account_id = 16 AND username = 'gsms-super-admin' AND email = 'fam.superadmin@greatsolomonmpservices.com')
--    OR (user_account_id = 17 AND username = 'gsms-fam-admin' AND email = 'fam.admin@greatsolomonmpservices.com')
--    OR (user_account_id = 22 AND username = 'reservation.officer' AND email = 'fam.reservation@greatsolomonmpservices.com')
--    OR (user_account_id = 24 AND username = 'records.officer' AND email = 'fam.records@greatsolomonmpservices.com')
--    OR (user_account_id = 27 AND username = 'gsms-hr-head' AND email = 'hr.head@greatsolomonmpservices.com')
--    OR (user_account_id = 29 AND username = 'gsms-it-head' AND email = 'it.head@greatsolomonmpservices.com')
--    OR (user_account_id = 26 AND username = 'gsms-fin-head' AND email = 'finance.head@greatsolomonmpservices.com')
--    OR (user_account_id = 30 AND username = 'gsms-maint-head' AND email = 'maintenance.head@greatsolomonmpservices.com')
--    OR (user_account_id = 31 AND username = 'gsms-records-head' AND email = 'records.head@greatsolomonmpservices.com')
--    OR (user_account_id = 23 AND username = 'gsms-scm-head' AND email = 'supplychain.head@greatsolomonmpservices.com');
