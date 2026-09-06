-- DEVELOPMENT / UAT ONLY
-- ORPHAN ACTIVITY CLEANUP AFTER CONTRACTS + TEMPLATES + RECORDS/RETENTION RESET
-- DO NOT RUN IN PRODUCTION
--
-- Supersession note:
--   For a full clean-slate FAM UAT transactional reset, prefer
--   database/migrations/2026_fam_full_uat_transaction_reset.sql.
--   This script remains available only as a narrow cleanup for known orphan
--   dashboard/document activity rows from earlier UAT reset runs.
--
-- Purpose:
--   Remove dashboard/application activity rows that point to UAT transactional
--   document rows already removed by the reset process.
--
-- Policy:
--   - Uses structured entity_type/entity_id checks only.
--   - Does not disable foreign keys.
--   - Does not truncate shared tables.
--   - Does not delete reusable reference/configuration data.
--   - Does not delete audit_log rows.
--   - Safe to re-run.

START TRANSACTION;

CREATE TEMPORARY TABLE tmp_uat_orphan_activity_event_ids (
  activity_event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT INTO tmp_uat_orphan_activity_event_ids (activity_event_id)
SELECT ae.activity_event_id
FROM activity_event ae
LEFT JOIN document d
  ON d.document_id = ae.entity_id
 AND d.deleted_at IS NULL
WHERE ae.module_code = 'documents'
  AND ae.entity_type = 'document'
  AND ae.entity_id IS NOT NULL
  AND d.document_id IS NULL
  AND ae.activity_event_id IN (748, 756, 758, 760, 762, 767, 768, 774, 775);

-- Preview before running DELETE:
-- SELECT ae.activity_event_id,
--        ae.module_code,
--        ae.entity_type,
--        ae.entity_id,
--        ae.event_type,
--        ae.event_title,
--        ae.actor_user_id,
--        ae.occurred_at
-- FROM activity_event ae
-- JOIN tmp_uat_orphan_activity_event_ids ids
--   ON ids.activity_event_id = ae.activity_event_id
-- ORDER BY ae.activity_event_id;

DELETE ae
FROM activity_event ae
JOIN tmp_uat_orphan_activity_event_ids ids
  ON ids.activity_event_id = ae.activity_event_id;

COMMIT;

-- Post-cleanup verification queries:
--
-- Recent Activity: no dashboard activity pointing to deleted Contract/Document/Template/Record entities.
-- SELECT ae.module_code, ae.entity_type, ae.event_type, COUNT(*) AS orphan_count
-- FROM activity_event ae
-- LEFT JOIN contract c ON ae.entity_type = 'contract' AND c.contract_id = ae.entity_id AND c.deleted_at IS NULL
-- LEFT JOIN document d ON ae.entity_type = 'document' AND d.document_id = ae.entity_id AND d.deleted_at IS NULL
-- LEFT JOIN document_template dt ON ae.entity_type = 'document_template' AND dt.template_id = ae.entity_id AND dt.deleted_at IS NULL
-- LEFT JOIN record r ON ae.entity_type = 'record' AND r.record_id = ae.entity_id AND r.deleted_at IS NULL
-- WHERE ae.module_code IN ('documents', 'retention', 'contract_management')
--   AND (
--        (ae.entity_type = 'contract' AND c.contract_id IS NULL)
--     OR (ae.entity_type = 'document' AND d.document_id IS NULL)
--     OR (ae.entity_type = 'document_template' AND dt.template_id IS NULL)
--     OR (ae.entity_type = 'record' AND r.record_id IS NULL)
--   )
-- GROUP BY ae.module_code, ae.entity_type, ae.event_type;
--
-- Documents: no orphan document_version or record_document rows.
-- SELECT 'document_version_missing_document' AS check_name, COUNT(*) AS orphan_count
-- FROM document_version dv
-- LEFT JOIN document d ON d.document_id = dv.document_id
-- WHERE d.document_id IS NULL
-- UNION ALL
-- SELECT 'record_document_missing_document', COUNT(*)
-- FROM record_document rd
-- LEFT JOIN document d ON d.document_id = rd.document_id
-- WHERE d.document_id IS NULL;
--
-- Templates: merge-field config remains, and template backing documents are valid.
-- SELECT 'document_template_merge_field_count' AS check_name, COUNT(*) AS row_count
-- FROM document_template_merge_field
-- UNION ALL
-- SELECT 'template_version_missing_document', COUNT(*)
-- FROM document_template_version dtv
-- LEFT JOIN document d ON d.document_id = dtv.document_id
-- WHERE d.document_id IS NULL
-- UNION ALL
-- SELECT 'template_version_missing_document_version', COUNT(*)
-- FROM document_template_version dtv
-- LEFT JOIN document_version dv ON dv.document_version_id = dtv.document_version_id
-- WHERE dtv.document_version_id IS NOT NULL AND dv.document_version_id IS NULL;
--
-- Records/Retention: links are valid and retention configuration remains.
-- SELECT 'record_document_missing_record' AS check_name, COUNT(*) AS orphan_count
-- FROM record_document rd
-- LEFT JOIN record r ON r.record_id = rd.record_id
-- WHERE r.record_id IS NULL
-- UNION ALL
-- SELECT 'record_disposition_missing_record', COUNT(*)
-- FROM record_disposition_recommendation rdr
-- LEFT JOIN record r ON r.record_id = rdr.record_id
-- WHERE r.record_id IS NULL
-- UNION ALL
-- SELECT 'retention_schedule_count', COUNT(*)
-- FROM retention_schedule;
--
-- Supplier/Counterparty: referenced rows remain; current unreferenced seed rows are review-only.
-- SELECT s.supplier_reference_id,
--        s.supplier_code,
--        s.supplier_name,
--        s.supplier_status,
--        s.source_system,
--        s.sync_status,
--        (SELECT COUNT(*) FROM contract c WHERE c.supplier_reference_id = s.supplier_reference_id AND c.deleted_at IS NULL) AS contract_refs,
--        (SELECT COUNT(*) FROM purchase_order_reference po WHERE po.supplier_reference_id = s.supplier_reference_id) AS purchase_order_refs,
--        (SELECT COUNT(*) FROM asset a WHERE a.supplier_reference_id = s.supplier_reference_id) AS asset_refs
-- FROM supplier_reference s
-- ORDER BY s.supplier_reference_id;
--
-- Security/config preservation.
-- SELECT 'user_account' AS table_name, COUNT(*) AS row_count FROM user_account
-- UNION ALL SELECT 'role', COUNT(*) FROM role
-- UNION ALL SELECT 'permission', COUNT(*) FROM permission
-- UNION ALL SELECT 'google_account_connection', COUNT(*) FROM google_account_connection;
