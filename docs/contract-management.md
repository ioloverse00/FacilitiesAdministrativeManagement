# Contract Management

## Scope Through Phase 4

Contract Management now provides:

- `ContractService`
- lifecycle validation and transitions
- RBAC checks
- contract numbering
- derived operational states
- backend `allowedActions`
- `contract_history`
- basic list/show/create/update/transition/options APIs
- Contract Management browser UI and DOC-CON document linking
- Phase 4 approval orchestration through `approval_request`, `approval_step`, and `workflow_task`

It does not implement retention hooks, legal matter creation, obligations CRUD, amendments, renewals, reminders, AI review, milestones, authoring/redlining, or e-signature.

## Lifecycle

Stored lifecycle states are:

- `DRAFT`
- `FOR_REVIEW`
- `FOR_APPROVAL`
- `APPROVED`
- `ACTIVE`
- `EXPIRED`
- `TERMINATED`
- `ARCHIVED`
- `REJECTED`
- `CANCELLED`

Derived states such as `EXPIRING_SOON`, `PENDING_EFFECTIVE`, `RENEWAL_DUE`, and overdue obligations are not stored in `contract.contract_status`.

Lifecycle transitions:

- `DRAFT -> FOR_REVIEW`
- `DRAFT -> CANCELLED`
- `FOR_REVIEW -> DRAFT`
- `FOR_REVIEW -> FOR_APPROVAL`
- `FOR_APPROVAL -> REJECTED`
- `FOR_APPROVAL -> DRAFT`
- `APPROVED -> ACTIVE`
- `APPROVED -> DRAFT`
- `ACTIVE -> TERMINATED`
- `ACTIVE -> EXPIRED`
- `EXPIRED -> ARCHIVED`
- `TERMINATED -> ARCHIVED`

`FOR_APPROVAL -> APPROVED` is not a direct client lifecycle transition. A contract becomes `APPROVED` only after the final required approval step is approved through the approval workflow API.

## Permissions

Phase 2 permissions:

- `contract.view`
- `contract.create`
- `contract.edit`
- `contract.review`
- `contract.approve`
- `contract.activate`
- `contract.terminate`
- `contract.archive`
- `contract.manage`

`contract.manage` is treated as an override by `ContractPolicy`.

## APIs

- `GET /api/contracts/index.php`
- `GET /api/contracts/show.php?id=<id>`
- `POST /api/contracts/create.php`
- `POST /api/contracts/update.php?id=<id>`
- `POST /api/contracts/transition.php?id=<id>`
- `POST /api/contracts/approval-action.php?id=<id>`
- `GET /api/contracts/options.php`

POST endpoints require JSON and CSRF protection through the shared API bootstrap.

## Validation

Creation always produces `DRAFT`. Direct client creation as `ACTIVE`, `APPROVED`, or another lifecycle state is ignored/rejected by service rules.

Core validations include active canonical contract type, active department, active owner, eligible FAM handler, active supplier/budget references, valid procurement/PO references, nonnegative amounts, valid date order, supported renewal type, supported risk level, and nonnegative notice period.

Direct metadata update is limited to `DRAFT`. Executed baseline terms on active contracts are protected for later amendment workflows.

## Phase 3 UI And Documents

Phase 3 adds the browser-facing Contract Management shell:

- `pages/contract-management.html`
- `assets/js/contract-management.js`
- Contract register with search, status, type, department, handler, and expiry filters
- summary cards from the backend list summary
- create draft contract flow
- draft-only edit flow
- details modal with Overview, Approvals, Documents, and History
- lifecycle buttons rendered from backend `allowedActions`
- contract document upload/listing through Document Management

Contract document upload uses the existing Document Management pipeline. Files are stored in `document` and `document_version`, linked through `record` and `record_document`, and categorized with `DOC-CON`. The contract upload endpoint sets:

- `related_module = CONTRACT_MANAGEMENT`
- `related_reference = contract.contract_number`
- `record.source_module = contract_management`
- `record.source_entity_id = contract.contract_id`
- `record.source_entity_type = contract.contract_number`

Primary contract documents use the existing `record_document.is_primary_document` flag. Supporting documents use the same linkage without becoming primary.

## Phase 4 Approval Orchestration

Submitting a `FOR_REVIEW` contract for approval creates a new `approval_request` and ordered `approval_step` rows in the same transaction that moves the contract to `FOR_APPROVAL`. If the approval route cannot be resolved, the transaction fails and the contract remains `FOR_REVIEW`.

The implemented route is sequential:

- Owning Department Head approval, resolved from `department_reference.department_head_employee_reference_id` to an active employee with an active user account.
- Procurement approval when `procurement_request_id` or `purchase_order_reference_id` is present, resolved to the first active user with a role granting `procurement.approve`.
- Finance/Budget approval when `budget_reference_id` is present, resolved to the first active user with a role granting `budget.approve`.
- Legal approval when `risk_level` is `HIGH` or `CRITICAL`, resolved to the first active user with a role granting `legal.manage`.
- Final FAM Contract approval, resolved to the first active user with a role granting `contract.approve`.

If any required approver cannot be resolved, submission is blocked with a configuration message. No employee ids are hardcoded or invented.

`approval_request.approval_status` uses `PENDING`, `APPROVED`, `REJECTED`, and `RETURNED`. The request stores `current_step_number` and `total_steps`. `approval_step.step_status` and `approval_step.decision` track each required step with `PENDING`, `APPROVED`, `REJECTED`, `RETURNED`, or `CANCELLED` semantics. Only the current request step can be acted on.

Approval actions are handled by `POST /api/contracts/approval-action.php?id=<id>` with `action=APPROVE`, `REJECT`, or `RETURN`. Reject and return require a reason. Approve comments are optional. The service validates authentication, CSRF, `contract.approve`, contract status, active approval request, current step, step authority, and stale/double submission state.

When a non-final step is approved, the current step is completed, its workflow task is closed, the request advances to the next step, a task is created for that step, and the contract remains `FOR_APPROVAL`.

When the final step is approved, the final step and request are completed and the contract moves to `APPROVED` in the same transaction. Direct crafted `FOR_APPROVAL -> APPROVED` calls to the lifecycle transition API are rejected.

Reject marks the current step/request rejected, cancels remaining open workflow tasks, moves the contract to `REJECTED`, and preserves approval evidence. Return marks the request returned, closes/cancels tasks, moves the contract to `DRAFT`, and preserves approval evidence. A later resubmission creates a new approval request and new steps; old requests remain visible as previous cycles.

Contract Details includes an approval summary payload with current request id, status, current step, total/completed counts, user-specific approval capabilities, ordered steps, comments, action dates, and previous approval cycles. The UI renders Approve, Reject, and Return for Changes only when the backend says the current user can act.

Workflow tasks are generated for the active approval step and closed after action. Notifications are inserted for the next/current approver when possible; notification insertion is non-critical and does not override approval state. Contract history records submitted-for-approval, step approval, rejection, return, and final approval events. Audit rows are attempted for approval actions.

## Deferred Integrations

Future phases will add Legal Matter creation/linking, Retention hooks, obligation CRUD/task generation, amendment/renewal behavior, expiration reminders, AI review, milestones, authoring/redlining, and e-signature.
