# Bank Reconciliation (Phase 9) Implementation Report

## Summary
Implemented Bank Reconciliation & Reconciliation Management end-to-end: models, migrations, enums, services, requests, resources, policy, controller, routes, permissions, AppServiceProvider registration, config updates, and feature tests. Enforced immutability for RECONCILED and no direct ledger writes.

## Domain & Data
- Models:
  - `app/Models/BankReconciliation.php`
  - `app/Models/BankReconciliationItem.php` (extends BaseModel)
- Enums: `app/Enums/BankReconciliationStatus.php` (Draft/IN_PROGRESS/RECONCILED)
- Migrations (no stored ledger balances/differences):
  - `database/migrations/2026_10_04_140000_create_bank_reconciliations_table.php`
  - `database/migrations/2026_10_04_140100_create_bank_reconciliation_items_table.php`

## Services (Calculator + Movements + Lifecycle)
- `app/Services/Accounting/Reconciliation/BankReconciliationCalculator.php` – computes summary (statement/ledger openings/closings, period debits/credits, cleared/uncleared totals/differences)
- `app/Services/Accounting/Reconciliation/BankReconciliationMovementService.php` – eligible/period movements, add/remove cleared items, guards immutability and eligibility
- `app/Services/Accounting/Reconciliation/BankReconciliationService.php` – create/update validations (period/account immutable once items exist, active bank account, dates, uniqueness per bank account active period)
- `app/Services/Accounting/Reconciliation/BankReconciliationCompletionService.php` – completes only when difference == 0; marks RECONCILED
- `app/Services/Accounting/Reconciliation/BankReconciliationReopenService.php` – reopens RECONCILED -> IN_PROGRESS (never back to DRAFT); stamps reopened_at, cleared_by/reopened_by

## HTTP Layer
- Requests (6): 
  - `app/Http/Requests/Accounting/Reconciliation/CreateBankReconciliationRequest.php`
  - `app/Http/Requests/Accounting/Reconciliation/UpdateBankReconciliationRequest.php`
  - `app/Http/Requests/Accounting/Reconciliation/BankReconciliationFilterRequest.php`
  - `app/Http/Requests/Accounting/Reconciliation/AddBankReconciliationItemRequest.php`
  - `app/Http/Requests/Accounting/Reconciliation/CompleteBankReconciliationRequest.php`
  - `app/Http/Requests/Accounting/Reconciliation/ReopenBankReconciliationRequest.php`
- Resources (2):
  - `app/Http/Resources/BankReconciliationResource.php` (includes computed summary)
  - `app/Http/Resources/BankReconciliationMovementResource.php`
- Controller: `app/Http/Controllers/Api/Accounting/BankReconciliationController.php`
  - CRUD (index/show/store/update/destroy)
  - `GET /{reconciliation}/movements`
  - `GET /{reconciliation}/items`
  - `POST /{reconciliation}/items`
  - `DELETE /{reconciliation}/items/{item}`
  - `POST /{reconciliation}/complete`
  - `POST /{reconciliation}/reopen`

## Authorization & Routes
- Policy: `app/Policies/BankReconciliationPolicy.php`
- Permissions enum: `app/Enums/PermissionName.php` added `accounting.bank_reconciliation.view/create/update/complete/reopen`
- Config: `config/authorization.php` added corresponding permissions
- Role sync: `app/Services/Authorization/RolePermissionSynchroniser.php` synced
- AppServiceProvider: Gate policy registration + route model bindings (`reconciliation`, `item`)
- Routes: registered under `auth:api`, `auth.fresh`, `company.context`, `throttle:api` with prefix `bank-reconciliations` in `routes/api.php`

## Constraints Enforced
- Reconciled is immutable (service/reopen logic)
- Reopening returns to IN_PROGRESS only (no DRAFT)
- Period/account cannot change once items exist
- Completion requires difference == 0
- No writes to accounting ledger/journals (separate reconciliation entities)
- Active bank account required

## Testing
- Feature tests: `tests/Feature/Accounting/Reconciliation/BankReconciliationTest.php` (4 tests passing on SQLite)
  - creates draft reconciliation
  - prevents creating duplicate active reconciliation for same bank/account/period
  - lists reconciliations
  - updates draft reconciliation (from_date/to_date)
- Style: `vendor/bin/pint --test` passes

## Fixes Applied During Testing
- BankReconciliationService: check `BankAccount->is_active` (correct field)
- BankReconciliationMovementService: fixed whereHas/date/orderBy references for SQLite compatibility
- BankReconciliationResource: use `Money` `__toString()` (not `toString()`)
- Controller: create returns 201 status

## Artifacts
- All code under `app/` as listed
- Routes in `routes/api.php`
- Permissions/config in `config/authorization.php`, `app/Enums/PermissionName.php`
- Tests in `tests/Feature/Accounting/Reconciliation/BankReconciliationTest.php`
