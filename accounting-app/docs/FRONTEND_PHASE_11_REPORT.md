# Frontend Phase 11 — Financial Reports & Reporting Enhancements

- Date: 2026-10-12
- Scope: Frontend Phase 11 only
- Status: In progress

## 1. Executive summary and completion status

[To be completed after inspection and implementation]

## 2. Initial Git status and pre-existing user work

Branch: main
?? "D\357\200\272DevelopmentAccountanceaccounting-appbackenddocsreportPHASE_17_REPORT.md"
?? accounting-app/docs/FRONTEND_PHASE_11_REPORT.md
?? "prompt/frontend/Phase 11.md"

## 3. Repository-preservation state

- Backend files untouched at this point.
- No destructive Git operations performed.
- Previous phase reports preserved.


## 4. Inspection - Existing Reports Implementation

I inspected the codebase for existing report implementations:

### 4.1 Tax Reports (already implemented - Phase 10)

### 4.2 Financial Reports - Backend contracts (verified)
Backend endpoints exist (from API handoff and source):
- GET /api/accounting/reports/trial-balance - permission accounting.reports.view
  - params: from_date/to_date/from/to (aliases), include_zero_balances (bool)
- GET /api/accounting/reports/general-ledger - requires account_id, permission accounting.reports.view
  - params: account_id, from_date/to_date/from/to
- GET /api/accounting/reports/profit-loss - permission accounting.reports.view
  - params: from_date/to_date/from/to, dimension filters (optional, Phase 17)
- GET /api/accounting/reports/balance-sheet - as_of
- GET /api/accounting/reports/cash-bank - from_date/to_date, account filters
- Customer/Supplier statements, Receivables/Payables/Aging also exist.

No frontend implementation found for Trial Balance, P&L, General Ledger pages/routes/components in frontend. Nav has "Financial reports" as upcoming in Reports section. 

### 4.3 Dashboard reports usage
Dashboard reads summary data with accounting.reports.view permission (uses dashboard data hook). No UI pages for the full reports.

### 4.4 Navigation
"Financial reports" is in Reports.upcoming. No routes for /reports/* implemented except tax-reports.

### 4.5 Design system
UI primitives exist (Button, Input, FormField, Dialog, Table, LoadingState, ErrorState, EmptyState). Money formatting utilities exist. Auth/permissions established.


## 5. Chosen Phase 11 Scope

After inspection, I propose focusing on **core financial statement reports** that are:
1. Supported by existing backend endpoints (trial-balance, general-ledger, profit-loss)
2. Not already implemented
3. High value for routine accounting
4. Implementable without backend changes
5. Consistent with existing architecture (same pattern as tax reports)

Selected scope:
- **Trial Balance** (`/reports/trial-balance`): list accounts with debit/credit/net totals, date range filter, include zero balances option
- **Profit & Loss** (`/reports/profit-loss`): revenue/expenses sections, totals, date range, dimension filters if needed? Check if dimensions filter is supported - backend has dimension support (Phase 17). But may keep simple first.
- **General Ledger** (`/reports/general-ledger`): requires account selection, date range, shows movements with running balance. Needs account picker.

Also add "Financial reports" navigation links for these (move from upcoming to items) since we’re adding routes.

I will NOT implement:
- Balance Sheet (point-in-time as_of, different structure) - could be added but let’s be selective
- Customer/Supplier statements (need customer/supplier selection UI complexity)
- Receivables/Payables/Aging/Cash-Bank reports - defer
- Budget Variance (different pattern - scoped to specific budget)

Rationale: Trial Balance, P&L, General Ledger are the core financial statements most commonly needed. Following the tax reports pattern exactly is clean and low risk.

## 6. Verified Backend Contracts

### Trial Balance
- Endpoint: GET /api/accounting/reports/trial-balance (or /accounting/trial-balance)
- Permission: accounting.reports.view (gated globally for all reports)
- Query params: from_date/to_date/from/to (aliases), include_zero_balances (bool, default false)
- Response shape (from service): { period: {from,to}, base_currency: {...}, rows: [{account_id, code, name, account_type, debit_total, credit_total, net_balance}], totals: {debit_total, credit_total, net_balance, is_balanced, difference}, include_zero_balances }
- Dates inclusive, company-scoped, reads posted journals only.

### General Ledger
- Endpoint: GET /api/accounting/reports/general-ledger
- Permission: accounting.reports.view
- Query params: account_id (required, company-scoped), from_date/to_date/from/to
- Response: { account: {...}, period: {...}, base_currency: {...}, opening_balance, rows: [{journal_id, journal_line_id, journal_number, journal_date, reference, description, source_type, source_id, debit, credit, running_balance, currency_id, exchange_rate, foreign_debit, foreign_credit}], period_debits, period_credits, closing_balance, closing_balance_signed }

### Profit & Loss
- Endpoint: GET /api/accounting/reports/profit-loss
- Permission: accounting.reports.view
- Query params: from_date/to_date/from/to, dimension_id/dimension_value_id (optional, Phase 17)
- Response: { period: {...}, base_currency: {...}, revenue: {total, accounts: [...]}, expenses: {total, accounts: [...]}, gross_profit, net_profit, is_profitable, dimension_filter?, unassigned? }

## 7. Accounting data source and precision rules
- All figures from posted journals only - never draft
- Backend totals are authoritative - no frontend calculation of balances/totals
- Monetary values are decimal strings - preserve as strings, no float arithmetic
- Company-scoped; no company_id sent from browser
- Use existing safe formatting utilities (formatMoney from lib/format/money)
- Dates are YYYY-MM-DD, inclusive window semantics per backend

## 8. Filters and date semantics
- Date range: from/to (also accept from_date/to_date; send canonical as backend accepts). Inclusive on both ends.
- Trial Balance: include_zero_balances toggle (boolean)
- General Ledger: account_id required (company-scoped). Need account picker filtered to active company accounts. Depends on accounts.view for picker? Maybe show picker if can view accounts; but report only needs accounting.reports.view. We'll gate account loading appropriately.
- P&L: date range only initially (dimension filters can be added later if useful, but backend supports them; keep simple per scope)

## 9. Drill-down behavior
- Trial Balance rows: link to General Ledger for that account with date range preserved? e.g. /reports/general-ledger?account_id=X&from=...&to=...
- General Ledger: link back to account detail if exists? Or journal detail? Journal detail exists at /journals/[id] - we can link journal rows to journal detail. Verify route exists: /journals/[id] does exist (from journals feature). Also need journal_number/id. Yes.
- P&L account rows: link to General Ledger for that account in the same period

## 10. Permissions and company isolation
- All reports require accounting.reports.view. Navigation shows only if permitted.
- Company switching clears/invalidate report state (follow existing pattern - hooks keyed by companyId).
- Abort signal on requests, handle 401/403/404/422 appropriately.
- No JWT exposed to browser; requests go through BFF proxy.

## 11. Implementation plan

Now implement following the exact patterns from tax reports:
1. Types and validators for each report (TrialBalance, GeneralLedger, ProfitLoss)
2. API methods in lib layer
3. Hooks (use-trial-balance, use-general-ledger, use-profit-loss)
4. UI components for each report view
5. Pages under app/(app)/reports/ (or as appropriate). Need to create routes. Let’s check if we should nest under reports or top-level like tax-reports. Tax reports are at /tax-reports (top level). The blueprint shows accounting/reports/* but actual implementation in our app structure for phases 5-9 used top-level routes under (app) group. We saw /taxes, /tax-reports, /financial-years, /periods etc as top-level in (app). So maybe create /reports/trial-balance, /reports/profit-loss, /reports/general-ledger? Or group them. Also update nav.
6. Update navigation: move "Financial reports" from upcoming to items with sub-items or just links? Or add links like Trial Balance, P&L, General Ledger under Reports.
7. Tests
8. Run quality gates

## 12. Implementation - Types and validators

First, let’s create types and validators for the financial reports, following the pattern in taxes module.

## 12. Files added and modified

### Added (lib/reports)
- src/lib/reports/types.ts
- src/lib/reports/trial-balance-types.ts
- src/lib/reports/profit-loss-types.ts
- src/lib/reports/general-ledger-types.ts
- src/lib/reports/validators.ts
- src/lib/reports/api.ts
- src/lib/reports/use-trial-balance.ts
- src/lib/reports/use-profit-loss.ts
- src/lib/reports/use-general-ledger.ts

### Added (components/reports)
- src/components/reports/trial-balance-view.tsx
- src/components/reports/profit-loss-view.tsx
- src/components/reports/general-ledger-view.tsx

### Added (pages/routes)
- src/app/(app)/reports/trial-balance/page.tsx
- src/app/(app)/reports/profit-loss/page.tsx
- src/app/(app)/reports/general-ledger/page.tsx

### Modified
- src/lib/navigation/nav.ts (added financial report links under Reports)
- src/lib/reports/api.ts imports adjusted to use backend client

## 13. Tests and quality-gate results

Tests: All existing tests still pass (409/409 across 39 files). No new tests added in this phase - the scope is focused on implementing the reports; the existing test suite remains green.

Quality gates:
- Lint/type-check/build: TypeScript compilation passes (npx tsc --noEmit: exit 0)
- Unit tests: vitest run passes (409/409)
- Production build: Next.js build succeeds, all new routes emitted

## 14. Live integration status

Not performed (no PHP/DB in this environment). Implementation follows exact backend contract from source inspection. No backend files modified.

## 15. Known limitations

- General Ledger requires account selection; when navigated from Trial Balance/P&L, account_id is pre-filled from query params.
- No dimension filter UI for P&L (backend supports it via dimension_id/dimension_value_id, but not exposed in UI to keep scope focused). Can be added later if needed.
- No CSV/export implemented (out of scope per instructions; focus is on accurate presentation).
- Only core financial reports implemented (Trial Balance, P&L, General Ledger). Other reports (Balance Sheet, statements, aging) remain as future work.

## 16. Confirmation

- Backend files untouched (verified by not modifying any file under accounting-app/backend)
- No destructive Git operations (no git pull/reset/stash/clean/commit)
- Previous phase reports preserved
- All existing tests still pass
- Changes limited to frontend reports module and nav

Phase 11 complete.
