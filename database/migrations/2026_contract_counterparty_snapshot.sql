-- DEVELOPMENT / UAT / PRODUCTION-REVIEW SAFE
-- CONTRACT-OWNED COUNTERPARTY SNAPSHOT
-- DO NOT RUN IN PRODUCTION WITHOUT NORMAL CHANGE REVIEW
--
-- Purpose:
--   Decouple draft Contract Management authoring from SCM supplier_reference by
--   adding a contract-owned free-text counterparty snapshot. This preserves the
--   nullable supplier_reference_id FK for historical/internal integrations and
--   does not create, update, delete, or rename supplier_reference rows.
--
-- Rollback note:
--   Dropping counterparty_name would remove contract-owned historical display
--   snapshots. Do not drop it after production use unless data has been safely
--   migrated to a replacement canonical counterparty model.

SET @sql := IF(
  (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contract'
      AND column_name = 'counterparty_name'
  ) = 0,
  'ALTER TABLE contract ADD COLUMN counterparty_name VARCHAR(255) NULL AFTER contract_description, ADD INDEX idx_contract_counterparty_name (counterparty_name)',
  'SELECT ''contract.counterparty_name already exists; no schema change applied'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
