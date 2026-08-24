-- FAM employee/assignee scope cleanup support.
-- Department heads are authoritative HRIS/reference data, not inferred from names or job titles.

ALTER TABLE department_reference
  ADD COLUMN IF NOT EXISTS department_head_employee_reference_id BIGINT UNSIGNED NULL AFTER department_name,
  ADD KEY IF NOT EXISTS idx_department_head_employee (department_head_employee_reference_id);

ALTER TABLE department_reference
  ADD CONSTRAINT fk_department_head_employee
  FOREIGN KEY (department_head_employee_reference_id)
  REFERENCES employee_reference (employee_reference_id)
  ON UPDATE CASCADE
  ON DELETE SET NULL;
