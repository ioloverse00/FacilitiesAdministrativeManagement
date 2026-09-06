-- Contract approval resubmission cycle support
--
-- Historical approval_request rows are immutable audit records. A contract can
-- return to Draft/Review after a completed approval cycle, then be submitted
-- again. The old unique entity key allowed only one approval_request row for a
-- contract forever, so it blocked legitimate new approval cycles.

DELIMITER $$

CREATE PROCEDURE drop_approval_entity_unique_if_present()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'approval_request'
      AND INDEX_NAME = 'uq_approval_entity'
      AND NON_UNIQUE = 0
  ) THEN
    ALTER TABLE approval_request DROP INDEX uq_approval_entity;
  END IF;
END$$

CREATE PROCEDURE add_approval_entity_lookup_if_missing()
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'approval_request'
      AND INDEX_NAME = 'idx_approval_entity'
  ) THEN
    CREATE INDEX idx_approval_entity
      ON approval_request (module_code, entity_type, entity_id);
  END IF;
END$$

DELIMITER ;

CALL drop_approval_entity_unique_if_present();
CALL add_approval_entity_lookup_if_missing();

DROP PROCEDURE drop_approval_entity_unique_if_present;
DROP PROCEDURE add_approval_entity_lookup_if_missing;
