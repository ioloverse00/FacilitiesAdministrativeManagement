-- Document Template Management active lifecycle simplification.
-- Local/UAT-safe cleanup migration. Review before running; do not execute in production without a release plan.
--
-- Strategy:
-- - APPROVED template/template-version rows become ACTIVE.
-- - Existing DRAFT and UNDER_REVIEW rows are not blindly activated because they may represent incomplete work.
--   They are mapped to RETIRED so they remain auditable but are not selected as current usable templates.
-- - Templates with no current active version are marked RETIRED.
-- - document_templates.approve is deprecated for this workflow and removed from role grants.

START TRANSACTION;

UPDATE document_template_version
SET status = 'ACTIVE',
    approved_at = COALESCE(approved_at, created_at)
WHERE status = 'APPROVED';

UPDATE document_template_version
SET status = 'RETIRED',
    retired_at = COALESCE(retired_at, NOW())
WHERE status IN ('DRAFT', 'UNDER_REVIEW');

UPDATE document_template dt
JOIN document_template_version tv ON tv.template_version_id = dt.current_approved_version_id
SET dt.status = 'ACTIVE'
WHERE dt.status = 'APPROVED'
  AND tv.status = 'ACTIVE';

UPDATE document_template dt
SET dt.current_approved_version_id = (
    SELECT tv.template_version_id
    FROM document_template_version tv
    WHERE tv.template_id = dt.template_id
      AND tv.status = 'ACTIVE'
    ORDER BY tv.version_number DESC
    LIMIT 1
)
WHERE dt.deleted_at IS NULL
  AND EXISTS (
    SELECT 1
    FROM document_template_version active_tv
    WHERE active_tv.template_id = dt.template_id
      AND active_tv.status = 'ACTIVE'
  );

UPDATE document_template dt
SET dt.status = 'ACTIVE'
WHERE dt.deleted_at IS NULL
  AND dt.current_approved_version_id IS NOT NULL
  AND EXISTS (
    SELECT 1
    FROM document_template_version tv
    WHERE tv.template_version_id = dt.current_approved_version_id
      AND tv.status = 'ACTIVE'
  );

UPDATE document_template dt
SET dt.status = 'RETIRED',
    dt.current_approved_version_id = NULL
WHERE dt.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1
    FROM document_template_version tv
    WHERE tv.template_id = dt.template_id
      AND tv.status = 'ACTIVE'
  );

UPDATE permission
SET description = CASE permission_code
    WHEN 'document_templates.create' THEN 'Create active document templates.'
    WHEN 'document_templates.edit' THEN 'Create new active document template versions.'
    WHEN 'document_templates.retire' THEN 'Retire active document templates.'
    ELSE description
  END
WHERE permission_code IN ('document_templates.create','document_templates.edit','document_templates.retire');

DELETE rp
FROM role_permission rp
JOIN permission p ON p.permission_id = rp.permission_id
WHERE p.permission_code = 'document_templates.approve';

UPDATE permission
SET description = 'Deprecated; template approval was removed from Document Template Management active lifecycle.'
WHERE permission_code = 'document_templates.approve';

COMMIT;
