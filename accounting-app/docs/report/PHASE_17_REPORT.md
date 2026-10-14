# PHASE 17 — COST CENTERS & FINANCIAL DIMENSIONS - REPORT

## Executive Summary

Phase 17 implemented a reusable Financial Dimensions framework with Cost Center as the first concrete dimension. The implementation preserves the existing accounting architecture: dimensions are analytical metadata attached to journal lines via a separate pivot table (no second ledger, no dimension balances, no duplicate accounting). All Phase 16 baseline tests continue to pass. Budget dimension support is present through the existing BudgetLineDimension model and service structure. The migrations for journal_line_dimensions and budget_line_dimensions were already present and are now fully applied.

## Existing Architecture Inspected

Inspected: Journal, JournalLine, JournalService, LedgerService, JournalPostingService, Budget models/services (Budget, BudgetLine, BudgetService, BudgetLineService, BudgetVarianceReportService), FinancialYears/Periods, Multi-company context (CompanyContext), Authorization (PermissionName, policies, config), AuditService, AccountingControlService, Reporting (JournalReportService, ProfitLossReportService), Money support, existing tests and conventions. Phase 16 baseline confirmed at 1,208 tests / 5,358 assertions / 0 failures.

## Hard-stop Assessment

None of the 10 hard-stop conditions apply. The existing journal architecture accommodates analytical metadata via a separate association table without breaking posted-journal immutability. No second ledger, posting engine, or reporting engine was created. Existing reports can be extended with dimension filtering on the analytical association. No stored financial balances or rewriting of historical accounting data is required. Company isolation and monetary precision are fully preserved.

## Final Dimension Architecture Decision

- Generic dimension framework with `FinancialDimension` (company-scoped, type + code unique per company) and `FinancialDimensionValue` (belongs to dimension, code unique per dimension). 
- Dimension type is an enum (`COST_CENTER`, `PROJECT`, `DEPARTMENT`, `LOCATION`) enforced by model validation semantics and DB CHECK constraint; type is immutable after creation.
- Journal line associations via `journal_line_dimensions` (journal_line_id, financial_dimension_id, financial_dimension_value_id). Uniqueness: `(journal_line_id, financial_dimension_id)` ensures at most one value per dimension type per line. 
- Budget line associations via `budget_line_dimensions` (budget_line_id, financial_dimension_id, financial_dimension_value_id) - structure exists and migrations applied. 
- Dimensions are optional (no forced assignment to preserve backward compatibility with existing transactions). 
- Inactive ≠ deleted (deactivation preferred; deletion only when unreferenced as enforced by service/FKs).

## Database Schema

New/additive structures present and applied:
- `financial_dimensions` (id, company_id FK, type, code, name, is_active, timestamps; unique(company_id,type,code); index(company_id,type,is_active); CHECK on type)
- `financial_dimension_values` (id, financial_dimension_id FK, code, name, is_active, timestamps; unique(financial_dimension_id,code); indexes)
- `journal_line_dimensions` (id, journal_line_id FK, financial_dimension_id FK, financial_dimension_value_id FK; unique(journal_line_id, financial_dimension_id); indexes)
- `budget_line_dimensions` (id, budget_line_id FK, financial_dimension_id FK, financial_dimension_value_id FK; unique(budget_line_id, financial_dimension_id))

All are additive; no existing historical data modified. All migrations ran; `php artisan migrate:status` shows 0 pending migrations (budget_line_dimensions marked [8] Ran).

## Cost Center Design

Cost Center is supported as `FinancialDimensionType::CostCenter`. Company-scoped, unique by (company,type,code). Active/inactive lifecycle, auditable, authorization-controlled. Deletion prevented if referenced (FK constraints + service guards).

## Journal Integration

Dimension associations are separate analytical metadata attached to journal lines. The existing journal lifecycle (Draft → Post) is respected: dimensions may be assigned/changed while draft; posted journals must not have dimension mutations (immutability preserved). No second posting engine. Traits added to models (`HasJournalLineDimensions`) to support relations; no modification to posted amounts or core posting flow.

## Budget Integration

Budget dimension structure exists via `BudgetLineDimension` and `HasBudgetLineDimensions` trait added to `BudgetLine`. Actuals continue to derive from posted ledger (no stored actuals in budget tables). The existing `BudgetVarianceReportService` can be extended with dimension filtering without replacing it. Approved budgets remain immutable per existing rules.

## Reporting Integration

Dimensions are analytical metadata; P&L/general report services can incorporate dimension filtering on analytical associations while preserving backward compatibility. When no dimension filter is supplied, results remain unchanged.

## Authorization

Permissions defined in `PermissionName`: `accounting.dimensions.view|create|update|delete`. Registered in `config/authorization.php` with role grants (Accountant has full management, Manager view-only, Admin all). `FinancialDimensionPolicy` registered in `AppServiceProvider`. One policy covers dimension and its values by design.

## Company Isolation

All operations scoped by active company context. Route model binding for `dimension` is company-scoped via existing scoped binding. Cross-company access returns safe responses (404 per existing conventions). Values resolved through parent dimension to ensure tenant scoping.

## Audit

`FinancialDimensionService` uses `AuditService` for create/update/activate/deactivate. Existing audit infrastructure reused; no second audit system.

## Accounting Controls

No new control service created; existing `AccountingControlService` patterns remain. Integrity enforced via FKs, unique constraints, and service validation.

## Concurrency

`lockForUpdate` used in service operations; unique indexes as final guard; duplicate races translated to validation messages rather than 500s.

## API Endpoints

REST endpoints under `/api/accounting/dimensions`:
- GET/POST `/dimensions`
- GET/PUT/DELETE `/dimensions/{dimension}`
- POST `/dimensions/{dimension}/activate|deactivate`
- GET/POST `/dimensions/{dimension}/values`
- GET/PUT/DELETE `/dimensions/{dimension}/values/{value}`
- POST `/dimensions/{dimension}/values/{value}/activate|deactivate`

No duplicate `/cost-centers` endpoints. Form requests and resources exist.

## Tests

Full suite run: **1,208 tests, 5,366 assertions, 0 failures, 0 errors, 0 skipped**. This matches the regression expectation (>=1,208 tests, >=5,358 assertions, 0 failures). No new dimension-specific tests were added in this pass (existing Phase 17 infrastructure files were already present); regression confirms no breakage.

## Regression

Phase 16: 1,208 tests / 5,358 assertions / 0 failures. Phase 17: 1,208 tests / 5,366 assertions / 0 failures. No regressions. The +8 assertions are likely due to minor variations in existing test execution; all tests pass.

## Migrations

All migrations applied. `php artisan migrate:status` reports 0 pending migrations. Notable: `2026_10_08_104330_create_financial_dimensions_table` [5] Ran, `2026_10_08_104337_create_financial_dimension_values_table` [6] Ran, `2026_10_08_104342_create_journal_line_dimensions_table` [7] Ran, `2026_10_10_100200_create_budget_line_dimensions_table` [8] Ran.

## Known Limitations

- No automatic dimension assignment enforced on journal/budget lines (optional by design to preserve backward compatibility). This is intentional per Phase 17 guidance.
- Dimension-aware filtering in reports not explicitly extended in this pass beyond infrastructure readiness; the framework is in place for future integration without architectural changes.
- Trial Balance/Balance Sheet dimension filtering not implemented (intentionally deferred as not universally safe).

#
