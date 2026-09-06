# FAM Persona and RBAC Normalization

The canonical application role model has five roles:

- `FAM_SUPER_ADMIN` = FAM system super administrator
- `FAM_ADMIN` = FAM Department Head
- `FAM_STAFF` = ordinary FAM operational employee
- `DEPARTMENT_HEAD` = head of a non-FAM department
- `EMPLOYEE` = ordinary employee

Organizational position and application authorization are separate concepts. Department membership is stored through `employee_reference.department_reference_id`. Department-head identity is stored through `department_reference.department_head_employee_reference_id`. Application authority comes from role and permission mappings.

## Persona Routing

`FAM_SUPER_ADMIN`, `FAM_ADMIN`, and `FAM_STAFF` route to the FAM administrative portal.

`DEPARTMENT_HEAD` and `EMPLOYEE` route to the employee self-service portal.

`UNAUTHORIZED_OR_UNRESOLVED` accounts are not assigned a portal by default.

## Department Head Semantics

A FAM Department Head must be represented as:

- department = FAM
- role = `FAM_ADMIN`
- `department_reference.department_head_employee_reference_id` points to that employee where applicable

Do not also assign `DEPARTMENT_HEAD` to the FAM Department Head.

A non-FAM Department Head must be represented as:

- department != FAM
- role = `DEPARTMENT_HEAD`
- `department_reference.department_head_employee_reference_id` points to that employee

The system must not rely on a fixed short list of department codes to decide whether someone is a department head.

## Authority Sources

Persona determines the broad workspace. Permissions determine business capability. Row-level checks remain authoritative for ownership, department scope, assigned employees, approval steps, workflow tasks, and workflow state.

Frontend visibility is not authorization. Backend routes must continue to enforce permissions and row-level ownership/scope.

## Development Normalization

For existing local UAT databases, review and run `database/normalize_final_fam_rbac.sql` manually. It is development/UAT only and must not be run against production. It normalizes demo role mappings to the final five roles, removes obsolete role definitions after dependencies are handled, and clears disposable local history tables used by auth/activity/workflow demos.
