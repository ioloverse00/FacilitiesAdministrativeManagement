-- LOCAL UAT ONLY: controlled Records Retention disposition lifecycle fixtures.
-- Safe to rerun: stable UAT identifiers are inserted only when absent.
-- This script intentionally does not reset terminal ARCHIVED/DISPOSED outcomes after Manual UAT.

START TRANSACTION;

SET @uat_user_id := (
  SELECT user_account_id
  FROM user_account
  WHERE username = 'records.officer'
    AND account_status = 'ACTIVE'
    AND deleted_at IS NULL
  LIMIT 1
);

SET @uat_employee_id := (
  SELECT employee_reference_id
  FROM user_account
  WHERE username = 'records.officer'
    AND account_status = 'ACTIVE'
    AND deleted_at IS NULL
  LIMIT 1
);

SET @uat_department_id := (
  SELECT department_reference_id
  FROM department_reference
  WHERE department_code = 'DEP-REC'
    AND status = 'ACTIVE'
  LIMIT 1
);

SET @admin_category_id := (
  SELECT document_category_id
  FROM document_category
  WHERE category_code = 'DOC-ADM'
    AND status = 'ACTIVE'
  LIMIT 1
);

SET @archive_schedule_id := (
  SELECT retention_schedule_id
  FROM retention_schedule
  WHERE schedule_code = 'RET-MNT-007'
    AND status = 'ACTIVE'
  LIMIT 1
);

SET @dispose_schedule_id := (
  SELECT retention_schedule_id
  FROM retention_schedule
  WHERE schedule_code = 'RET-ADM-005'
    AND status = 'ACTIVE'
  LIMIT 1
);

INSERT INTO document (
  document_number,
  document_category_id,
  document_title,
  document_description,
  document_status,
  confidentiality_level,
  current_version_number,
  document_date,
  uploaded_by_user_id,
  owner_employee_reference_id,
  created_at,
  updated_at
)
SELECT
  'DOC-UAT-RET-ARCHIVE',
  @admin_category_id,
  'Records Retention Archive Lifecycle - UAT',
  'Controlled UAT fixture for validating Records Retention archive disposition lifecycle. Not an operational business record.',
  'ACTIVE',
  'INTERNAL',
  1,
  '2019-08-24',
  @uat_user_id,
  @uat_employee_id,
  NOW(),
  NOW()
WHERE @uat_user_id IS NOT NULL
  AND @uat_employee_id IS NOT NULL
  AND @admin_category_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM document WHERE document_number = 'DOC-UAT-RET-ARCHIVE');

INSERT INTO document (
  document_number,
  document_category_id,
  document_title,
  document_description,
  document_status,
  confidentiality_level,
  current_version_number,
  document_date,
  uploaded_by_user_id,
  owner_employee_reference_id,
  created_at,
  updated_at
)
SELECT
  'DOC-UAT-RET-DISPOSE',
  @admin_category_id,
  'Records Retention Dispose Lifecycle - UAT',
  'Controlled UAT fixture for validating Records Retention review-then-dispose lifecycle. Not an operational business record.',
  'ACTIVE',
  'INTERNAL',
  1,
  '2021-08-24',
  @uat_user_id,
  @uat_employee_id,
  NOW(),
  NOW()
WHERE @uat_user_id IS NOT NULL
  AND @uat_employee_id IS NOT NULL
  AND @admin_category_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM document WHERE document_number = 'DOC-UAT-RET-DISPOSE');

SET @archive_document_id := (SELECT document_id FROM document WHERE document_number = 'DOC-UAT-RET-ARCHIVE' LIMIT 1);
SET @dispose_document_id := (SELECT document_id FROM document WHERE document_number = 'DOC-UAT-RET-DISPOSE' LIMIT 1);

INSERT INTO document_version (
  document_id,
  version_number,
  file_name,
  file_extension,
  mime_type,
  file_size,
  storage_path,
  file_hash,
  change_summary,
  uploaded_by_user_id,
  uploaded_at,
  is_current
)
SELECT
  @archive_document_id,
  1,
  'retention-archive-lifecycle-uat.pdf',
  'pdf',
  'application/pdf',
  635,
  'documents/uat-retention-archive/v1/retention-archive-lifecycle-uat.pdf',
  SHA2('DOC-UAT-RET-ARCHIVE-v1', 256),
  'Initial controlled UAT fixture upload.',
  @uat_user_id,
  NOW(),
  TRUE
WHERE @archive_document_id IS NOT NULL
  AND @uat_user_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM document_version
    WHERE document_id = @archive_document_id
      AND version_number = 1
      AND deleted_at IS NULL
  );

INSERT INTO document_version (
  document_id,
  version_number,
  file_name,
  file_extension,
  mime_type,
  file_size,
  storage_path,
  file_hash,
  change_summary,
  uploaded_by_user_id,
  uploaded_at,
  is_current
)
SELECT
  @dispose_document_id,
  1,
  'retention-dispose-lifecycle-uat.pdf',
  'pdf',
  'application/pdf',
  635,
  'documents/uat-retention-dispose/v1/retention-dispose-lifecycle-uat.pdf',
  SHA2('DOC-UAT-RET-DISPOSE-v1', 256),
  'Initial controlled UAT fixture upload.',
  @uat_user_id,
  NOW(),
  TRUE
WHERE @dispose_document_id IS NOT NULL
  AND @uat_user_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM document_version
    WHERE document_id = @dispose_document_id
      AND version_number = 1
      AND deleted_at IS NULL
  );

INSERT INTO record (
  record_number,
  record_title,
  record_description,
  record_type,
  retention_schedule_id,
  originating_department_reference_id,
  record_owner_employee_reference_id,
  source_module,
  source_entity_type,
  source_entity_id,
  record_date,
  retention_start_date,
  retention_trigger_basis,
  retention_trigger_date,
  policy_eligibility_date,
  administrative_review_date_override,
  scheduled_disposition_date,
  retention_trigger_state,
  record_status,
  legal_hold_status,
  confidentiality_level,
  created_by_user_id,
  updated_by_user_id,
  created_at,
  updated_at
)
SELECT
  'REC-UAT-RET-ARCHIVE',
  'Records Retention Archive Lifecycle - UAT',
  'Controlled UAT fixture for validating Records Retention archive disposition lifecycle. Not an operational business record.',
  'Maintenance',
  @archive_schedule_id,
  @uat_department_id,
  @uat_employee_id,
  'uat_retention_fixture',
  'ARCHIVE_LIFECYCLE',
  @archive_document_id,
  '2019-08-24',
  '2019-08-24',
  'WORK_COMPLETION',
  '2019-08-24',
  DATE_ADD('2019-08-24', INTERVAL 7 YEAR),
  NULL,
  DATE_ADD('2019-08-24', INTERVAL 7 YEAR),
  'RESOLVED',
  'ACTIVE',
  'NONE',
  'INTERNAL',
  @uat_user_id,
  @uat_user_id,
  NOW(),
  NOW()
WHERE @archive_schedule_id IS NOT NULL
  AND @uat_department_id IS NOT NULL
  AND @uat_employee_id IS NOT NULL
  AND @archive_document_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM record WHERE record_number = 'REC-UAT-RET-ARCHIVE');

INSERT INTO record (
  record_number,
  record_title,
  record_description,
  record_type,
  retention_schedule_id,
  originating_department_reference_id,
  record_owner_employee_reference_id,
  source_module,
  source_entity_type,
  source_entity_id,
  record_date,
  retention_start_date,
  retention_trigger_basis,
  retention_trigger_date,
  policy_eligibility_date,
  administrative_review_date_override,
  scheduled_disposition_date,
  retention_trigger_state,
  record_status,
  legal_hold_status,
  confidentiality_level,
  created_by_user_id,
  updated_by_user_id,
  created_at,
  updated_at
)
SELECT
  'REC-UAT-RET-DISPOSE',
  'Records Retention Dispose Lifecycle - UAT',
  'Controlled UAT fixture for validating Records Retention review-then-dispose lifecycle. Not an operational business record.',
  'Administrative',
  @dispose_schedule_id,
  @uat_department_id,
  @uat_employee_id,
  'uat_retention_fixture',
  'DISPOSE_LIFECYCLE',
  @dispose_document_id,
  '2021-08-24',
  '2021-08-24',
  'RECORD_CLOSURE',
  '2021-08-24',
  DATE_ADD('2021-08-24', INTERVAL 5 YEAR),
  NULL,
  DATE_ADD('2021-08-24', INTERVAL 5 YEAR),
  'RESOLVED',
  'ACTIVE',
  'NONE',
  'INTERNAL',
  @uat_user_id,
  @uat_user_id,
  NOW(),
  NOW()
WHERE @dispose_schedule_id IS NOT NULL
  AND @uat_department_id IS NOT NULL
  AND @uat_employee_id IS NOT NULL
  AND @dispose_document_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM record WHERE record_number = 'REC-UAT-RET-DISPOSE');

SET @archive_record_id := (SELECT record_id FROM record WHERE record_number = 'REC-UAT-RET-ARCHIVE' LIMIT 1);
SET @dispose_record_id := (SELECT record_id FROM record WHERE record_number = 'REC-UAT-RET-DISPOSE' LIMIT 1);

INSERT INTO record_document (record_id, document_id, is_primary_document, added_by_user_id)
SELECT @archive_record_id, @archive_document_id, TRUE, @uat_user_id
WHERE @archive_record_id IS NOT NULL
  AND @archive_document_id IS NOT NULL
  AND @uat_user_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM record_document
    WHERE record_id = @archive_record_id
      AND document_id = @archive_document_id
  );

INSERT INTO record_document (record_id, document_id, is_primary_document, added_by_user_id)
SELECT @dispose_record_id, @dispose_document_id, TRUE, @uat_user_id
WHERE @dispose_record_id IS NOT NULL
  AND @dispose_document_id IS NOT NULL
  AND @uat_user_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM record_document
    WHERE record_id = @dispose_record_id
      AND document_id = @dispose_document_id
  );

COMMIT;

-- Optional targeted cleanup after UAT, if needed:
-- START TRANSACTION;
-- DELETE rdr FROM record_disposition_recommendation rdr
-- JOIN record r ON r.record_id = rdr.record_id
-- WHERE r.record_number IN ('REC-UAT-RET-ARCHIVE','REC-UAT-RET-DISPOSE');
-- DELETE ae FROM activity_event ae
-- WHERE ae.module_code = 'retention'
--   AND ae.entity_type = 'record'
--   AND ae.entity_id IN (
--     SELECT record_id FROM record
--     WHERE record_number IN ('REC-UAT-RET-ARCHIVE','REC-UAT-RET-DISPOSE')
--   );
-- DELETE FROM record_document
-- WHERE record_id IN (
--   SELECT record_id FROM record
--   WHERE record_number IN ('REC-UAT-RET-ARCHIVE','REC-UAT-RET-DISPOSE')
-- );
-- DELETE FROM record WHERE record_number IN ('REC-UAT-RET-ARCHIVE','REC-UAT-RET-DISPOSE');
-- DELETE FROM document_version
-- WHERE document_id IN (
--   SELECT document_id FROM document
--   WHERE document_number IN ('DOC-UAT-RET-ARCHIVE','DOC-UAT-RET-DISPOSE')
-- );
-- DELETE FROM document WHERE document_number IN ('DOC-UAT-RET-ARCHIVE','DOC-UAT-RET-DISPOSE');
-- COMMIT;
