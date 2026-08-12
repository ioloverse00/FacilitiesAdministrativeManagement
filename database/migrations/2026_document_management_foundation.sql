-- Phase 6: Document Management official working categories.
-- Run after the aligned schema and reference seed data.

INSERT INTO document_category
    (category_code, category_name, description, default_confidentiality_level, status)
VALUES
    ('DOC-RES', 'Facility & Reservation', 'Reservation approvals, authorization documents, and event attachments.', 'INTERNAL', 'ACTIVE'),
    ('DOC-VIS', 'Visitor', 'Visitor/access authorization documents and supporting visitor files.', 'CONFIDENTIAL', 'ACTIVE'),
    ('DOC-ADM', 'Administrative', 'Memoranda, office orders, policies, reports, and official correspondence.', 'INTERNAL', 'ACTIVE'),
    ('DOC-LEGAL', 'Legal', 'Legal correspondence, opinions, notices, and legal case attachments.', 'CONFIDENTIAL', 'ACTIVE'),
    ('DOC-CON', 'Contract', 'Signed contracts, agreements, amendments, renewals, and termination documents.', 'CONFIDENTIAL', 'ACTIVE')
ON DUPLICATE KEY UPDATE
    category_name = VALUES(category_name),
    description = VALUES(description),
    default_confidentiality_level = VALUES(default_confidentiality_level),
    status = VALUES(status);
