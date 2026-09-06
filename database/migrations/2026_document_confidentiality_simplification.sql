-- Local/UAT migration: normalize document confidentiality to the final
-- three-level model and remove Legal Management grants from non-legal roles.
-- Review before running. Do not run against production without a release plan.

START TRANSACTION;

UPDATE document
SET confidentiality_level = 'CONFIDENTIAL',
    updated_at = NOW()
WHERE confidentiality_level = 'RESTRICTED';

UPDATE record
SET confidentiality_level = 'CONFIDENTIAL',
    updated_at = NOW()
WHERE confidentiality_level = 'RESTRICTED';

UPDATE document_category
SET default_confidentiality_level = 'CONFIDENTIAL'
WHERE default_confidentiality_level = 'RESTRICTED';

DELETE rp
FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code IN ('FAM_STAFF', 'DEPARTMENT_HEAD', 'EMPLOYEE')
  AND p.permission_code LIKE 'legal.%';

COMMIT;

-- Post-run verification queries.
SELECT confidentiality_level, COUNT(*) AS document_count
FROM document
GROUP BY confidentiality_level
ORDER BY confidentiality_level;

SELECT default_confidentiality_level, COUNT(*) AS category_count
FROM document_category
GROUP BY default_confidentiality_level
ORDER BY default_confidentiality_level;

SELECT r.role_code, p.permission_code
FROM role r
JOIN role_permission rp ON rp.role_id = r.role_id
JOIN permission p ON p.permission_id = rp.permission_id
WHERE r.role_code IN ('FAM_STAFF', 'DEPARTMENT_HEAD', 'EMPLOYEE')
  AND p.permission_code LIKE 'legal.%'
ORDER BY r.role_code, p.permission_code;
