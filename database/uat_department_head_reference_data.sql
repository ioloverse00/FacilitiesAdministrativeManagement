-- LOCAL UAT ONLY: approved mock Department Head references for FAM cross-department workflows.
-- These values support local Manual UAT and are not production HRIS truth.
-- This script updates organizational reference data only. It does not create transactional records.

USE ismers_fam;
START TRANSACTION;

-- Validate that approved local UAT heads still match their departments before applying.
DROP TEMPORARY TABLE IF EXISTS uat_department_head_mapping;
CREATE TEMPORARY TABLE uat_department_head_mapping (
  department_code VARCHAR(50) NOT NULL PRIMARY KEY,
  employee_reference_id BIGINT UNSIGNED NOT NULL,
  employee_number VARCHAR(50) NOT NULL,
  full_name VARCHAR(200) NOT NULL
);

INSERT INTO uat_department_head_mapping (department_code, employee_reference_id, employee_number, full_name)
VALUES
('DEP-ADM', 24, 'EMP-2026-0009', 'Bianca Cruz'),
('DEP-FAC', 18, 'EMP-2026-0003', 'Leah Navarro'),
('DEP-FIN', 28, 'EMP-2026-0013', 'Selene Dizon'),
('DEP-IT', 16, 'EMP-2026-0001', 'Mara Ibarra'),
('DEP-MNT', 19, 'EMP-2026-0004', 'Tomas Rivas'),
('DEP-REC', 26, 'EMP-2026-0011', 'Elena Aquino'),
('DEP-SCM', 25, 'EMP-2026-0010', 'Carlo Reyes');

UPDATE department_reference d
JOIN uat_department_head_mapping m
  ON m.department_code = d.department_code
  AND d.source_system = 'HRIS'
JOIN employee_reference e
  ON e.employee_reference_id = m.employee_reference_id
  AND e.employee_number = m.employee_number
  AND e.full_name = m.full_name
  AND e.department_reference_id = d.department_reference_id
  AND e.employment_status = 'ACTIVE'
  AND e.deleted_at IS NULL
SET d.department_head_employee_reference_id = e.employee_reference_id;

UPDATE department_reference
SET department_head_employee_reference_id = NULL
WHERE source_system = 'HRIS'
  AND department_code = 'DEP-HR';

COMMIT;
