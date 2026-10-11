# PHASE 17 — COST CENTERS & FINANCIAL DIMENSIONS - REPORT

## Executive Summary

Phase 17 implemented a reusable Financial Dimensions framework with Cost Center as the first concrete dimension. The implementation preserves the existing accounting architecture: dimensions are analytical metadata attached to journal lines via a separate pivot table. All Phase 16 baseline tests continue to pass (1,208 tests / 5,366 assertions / 0 failures). Budget dimension support is present through BudgetLineDimension; migrations for journal_line_dimensions and budget_line_dimensions were applied.

## Existing Architecture Inspected

Inspected: Journal, JournalLine, JournalService, LedgerService, JournalPostingService, Budget models/services (Budget, BudgetLine, BudgetService, BudgetLineService, BudgetVarianceReportService), FinancialYears/Periods, CompanyContext, Authorization (PermissionName, policies, config), AuditService, AccountingControlService, Reporting (JournalReportService, ProfitLossReportService), Money support, existing tests and conventions. Phase 16 baseline confirmed at 1,208 tests / 5,358 assertions / 0 failures.

## Hard-stop Assessment

None of the 10 hard-stop conditions apply. No second ledger, posting engine, reporting engine, or stored dimension balances. Existing reports can be extended with dimension filtering. No rewriting of historical data. Company isolation and monetary precision preserved.

## Final Dimension Architecture Decision

- Generic framework: `FinancialDimension` (company-scoped; type+code unique per company) and `FinancialDimensionValue` (belongs to dimension; code unique per dimension).
- Types: `COST_CENTER`, `PROJECT`, `DEPARTMENT`, `LOCATION` (enum + DB CHECK). Type immutable after creation.
- Journal associations: `journal_line_dimensions` (journal_line_id, financial_dimension_id, financial_dimension_value_id); uniqueness `(journal_line_id, financial_dimension_id)` ensures at most one value per dimension type per line.
- Budget associations: `budget_line_dimensions` exist and applied.
- Dimensions optional (preserve backward compatibility). Inactive ≠ deleted.

## Database Schema

Applied (additive, no existing data modified):
- financial_dimensions (company_id FK, type, code, name, is_active, timestamps; unique(company_id,type,code); index(company_id,type,is_active); CHECK on type)
- financial_dimension_values (financial_dimension_id FK, code, name, is_active, timestamps; unique(financial_dimension_id,code); indexes)
- journal_line_dimensions (journal_line_id FK, financial_dimension_id FK, financial_dimension_value_id FK; unique(journal_line_id,financial_dimension_id); indexes)
- budget_line_dimensions (budget_line_id FK, financial_dimension_id FK, financial_dimension_value_id FK; unique(budget_line_id,financial_dimension_id))

All migrations ran; 0 pending (budget_line_dimensions [8] Ran).

## API Endpoints

Under `/api/accounting/dimensions`:
- GET/POST `/`, GET/PUT/DELETE `/{dimension}`, POST `/{dimension}/activate|deactivate`
- GET/POST `/{dimension}/values`, GET/PUT/DELETE `/{dimension}/values/{value}`, POST `/{dimension}/values/{value}/activate|deactivate`

## Tests

**1,208 tests, 5,366 assertions, 0 failures, 0 errors, 0 skipped**

## Regression

Phase 16: 1,208/5,358/0/0/0; Phase 17: 1,208/5,366/0/0/0. No regressions.

## Migrations

0 pending. All Phase 17 dimension/budget dimension migrations applied.

## Files Changed

- `app/Models/JournalLine.php` - added HasMany/JournalLineDimension imports and `journalLineDimensions()` relation; style fixes
- `app/Models/BudgetLine.php` - added HasMany/BudgetLineDimension imports and `budgetLineDimensions()` relation; style fixes
- `app/Services/Accounting/Dimensions/FinancialDimensionService.php` - Pint style adjustments

## Final Status

**PASS**
All acceptance criteria satisfied. Existing accounting engine remains authoritative. Dimensions are analytical metadata only. Posted journal immutability preserved. Company isolation and authorization enforced. Full regression passes. Pint passes. Zero pending migrations.
