# FAM Persona and RBAC Normalization

The current department/business-domain structure used by FAM is derived from the ISMERS system decomposition and serves as the provisional organizational reference until an authoritative company organizational chart is provided.

This model intentionally creates FAM accounts only for people who operate FAM modules or have a concrete FAM-facing workflow. A FAM module name does not automatically imply that a separate company department account must exist.

## Personas

`SUPER_ADMIN` maps to the existing `SYSTEM_ADMIN` role for compatibility. The canonical UAT username is `gsms-super-admin`, and the portal is FAM/Admin.

`FAM_ADMIN` maps to the existing `FAM_ADMIN` role. The canonical UAT username is `gsms-fam-admin`, and the portal is FAM/Admin only.

`FAM_OPERATIONAL` covers FAM operators with module-specific work such as facility management, reservations operations, records operations, assets, maintenance, and technical work. These users use the FAM/Admin portal and remain governed by granular module permissions.

`DEPARTMENT_HEAD_EMPLOYEE` covers authorized external FAM-facing department heads. Initial canonical users are `gsms-fin-head`, `gsms-hr-head`, and `gsms-scm-head`. They use the Employee Portal and can use only the capabilities granted to their roles and explicit workflow assignments.

`UNAUTHORIZED_OR_UNRESOLVED` accounts are not assigned a portal by default.

## Username Convention

Canonical UAT usernames use the `gsms-*` prefix for business personas:

- `gsms-super-admin`
- `gsms-fam-admin`
- `gsms-fin-head`
- `gsms-hr-head`
- `gsms-scm-head`

Usernames are login identities only. Authorization must not be granted by username checks.

## Authority Sources

Persona determines the broad workspace.

Permissions determine business capability.

`department_reference.department_head_employee_reference_id` is the source of truth for department-head mapping.

`workflow_task` and `approval_step` assignment determine actionable Assigned Tasks.

Legacy roles such as `SYSTEM_ADMIN`, `FINANCE_APPROVER`, `REQUESTOR`, `MAINTENANCE_SUPERVISOR`, `PROCUREMENT_OFFICER`, and generic `APPROVER` remain compatibility roles until runtime dependencies are removed.

## Employee Portal Policy

Employee Portal is restricted to authorized external FAM-facing department heads. It is not a generic employee portal.

FAM Admin, Super Admin, FAM operational users, ordinary employees, and legacy requestor-only accounts are rejected by the server-side Employee Portal bootstrap.

Authorized external heads may initially create and view their own Facility Requests and Reservations, plus action Assigned Tasks explicitly assigned to their user or employee seat. Module-specific authority, such as `budget.approve`, remains separate.

## Future Remapping

When an authoritative company organizational chart becomes available, department codes, department-head mappings, and external FAM-facing personas can be remapped without rewriting historical workflow, approval, audit, or transaction ownership records.
