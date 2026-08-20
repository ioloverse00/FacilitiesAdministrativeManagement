-- Phase 3 duplicate cleanup: collapse duplicate active AI-extracted parties.
-- Identity is same linked reference, or same normalized name + party type + role.

DROP TEMPORARY TABLE IF EXISTS legal_ai_party_dedupe;

CREATE TEMPORARY TABLE legal_ai_party_dedupe AS
SELECT
  ranked.legal_matter_party_id,
  ranked.legal_matter_id,
  ranked.identity_key,
  ranked.group_size,
  ranked.rn
FROM (
  SELECT
    p.legal_matter_party_id,
    p.legal_matter_id,
    CASE
      WHEN p.employee_reference_id IS NOT NULL THEN CONCAT('EMP:', p.employee_reference_id)
      WHEN p.visitor_id IS NOT NULL THEN CONCAT('VIS:', p.visitor_id)
      ELSE CONCAT(
        'NAME:',
        LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p.external_name, p.organization_name, '')), ' ', ''), '.', ''), ',', ''), '-', '')),
        '|TYPE:',
        p.party_type,
        '|ROLE:',
        p.party_role
      )
    END AS identity_key,
    COUNT(*) OVER (
      PARTITION BY
        p.legal_matter_id,
        CASE
          WHEN p.employee_reference_id IS NOT NULL THEN CONCAT('EMP:', p.employee_reference_id)
          WHEN p.visitor_id IS NOT NULL THEN CONCAT('VIS:', p.visitor_id)
          ELSE CONCAT(
            'NAME:',
            LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p.external_name, p.organization_name, '')), ' ', ''), '.', ''), ',', ''), '-', '')),
            '|TYPE:',
            p.party_type,
            '|ROLE:',
            p.party_role
          )
        END
    ) AS group_size,
    ROW_NUMBER() OVER (
      PARTITION BY
        p.legal_matter_id,
        CASE
          WHEN p.employee_reference_id IS NOT NULL THEN CONCAT('EMP:', p.employee_reference_id)
          WHEN p.visitor_id IS NOT NULL THEN CONCAT('VIS:', p.visitor_id)
          ELSE CONCAT(
            'NAME:',
            LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p.external_name, p.organization_name, '')), ' ', ''), '.', ''), ',', ''), '-', '')),
            '|TYPE:',
            p.party_type,
            '|ROLE:',
            p.party_role
          )
        END
      ORDER BY
        CASE WHEN p.employee_reference_id IS NOT NULL OR p.visitor_id IS NOT NULL THEN 1 ELSE 0 END DESC,
        CHAR_LENGTH(TRIM(COALESCE(p.organization_name, ''))) DESC,
        p.legal_matter_party_id ASC
    ) AS rn
  FROM legal_matter_party p
  WHERE p.deleted_at IS NULL
    AND p.party_source = 'AI'
    AND COALESCE(p.external_name, p.organization_name, '') <> ''
) ranked
WHERE ranked.group_size > 1;

INSERT INTO legal_matter_history (
  legal_matter_id,
  event_type,
  from_status,
  to_status,
  description,
  metadata_json,
  actor_user_id,
  created_at
)
SELECT
  legal_matter_id,
  'LEGAL_AI_PARTY_DUPLICATE_MERGED',
  NULL,
  NULL,
  'Duplicate AI-extracted parties were merged during deduplication cleanup.',
  JSON_OBJECT('duplicate_party_ids', GROUP_CONCAT(legal_matter_party_id ORDER BY legal_matter_party_id)),
  NULL,
  NOW()
FROM legal_ai_party_dedupe
WHERE rn > 1
GROUP BY legal_matter_id;

UPDATE legal_matter_party p
JOIN legal_ai_party_dedupe d ON d.legal_matter_party_id = p.legal_matter_party_id
SET
  p.dismissal_reason = 'Merged duplicate AI-extracted party during deduplication cleanup.',
  p.deleted_at = NOW(),
  p.updated_at = NOW()
WHERE d.rn > 1
  AND p.deleted_at IS NULL;

DROP TEMPORARY TABLE IF EXISTS legal_ai_party_dedupe;
