# Phase 6 Report — Accounting Reports & Financial Statements

## 1. Phase Objectives

Phase 6 turns the Phase 5 transactional engine into read-only financial reporting.

Delivered:

- 11 reports: Trial Balance, General Ledger, Profit & Loss, Balance Sheet,
  Customer Statement, Supplier Statement, Receivables, Payables, Aged Receivables,
  Aged Payables, Cash/Bank Activity.
- A shared `app/Services/Accounting/Reports/` namespace with an abstract
  `JournalReportService` base and one focused service per report. No report engine,
  no DSL, no metadata registry, no caching layer.
- 12 Form Requests in `app/Http/Requests/Accounting/Reports/`, sharing one
  `ReportRequest` base for the shared date-range and company-scoped-id rules.
- 11 read-only `GET` routes nested under `accounting/reports`, inside the existing
  `auth:api, auth.fresh, company.context, throttle:api` group.
- Exactly one new permission, `accounting.reports.view`, granted to Admin,
  Accountant and Manager; Staff has none.
- Configurable aging buckets in `config('accounting.reports.aging_buckets')`.
- **66 new tests, 536 assertions, all passing**; the full suite is
  **509 tests, 2398 assertions, 0 failures / 0 errors / 0 skipped**, zero
  regressions against the 443-test Phase 1–5 baseline.

Explicitly **not** in scope: export (PDF/Excel), comparative or multi-period
columns, budget variance, consolidation, currency conversion, and any write of
accounting data.

## 2. Files Changed

### Migrations added (0)

None. Phase 6 required no schema change, so no migration was written and no
migration verification commands were needed. All figures are derived at request
time from existing tables.

### Models added (0)

None. No existing model was modified. Phase 6 uses the models, scopes and money
accessors that Phase 4 and Phase 5 already provide.

### Services added (15)

`app/Services/Accounting/Reports/`:

- `JournalReportService` (abstract) — shared journal-derived helpers
- `TrialBalanceReportService`
- `GeneralLedgerReportService`
- `ProfitLossReportService`
- `BalanceSheetReportService`
- `CounterpartyStatementReportService` (abstract)
- `CustomerStatementReportService`
- `SupplierStatementReportService`
- `ReceivablesReportService`
- `PayablesReportService`
- `AgingReportService` (abstract)
- `ReceivablesAgingReportService`
- `PayablesAgingReportService`
- `CashBankReportService`
- `Concerns/AgesDocuments` (trait)

### Services changed (2, minimal)

| File | Change | Why |
|---|---|---|
| `app/Services/Accounting/LedgerService.php` | `postedTotalsByAccount()` and `postedLinesQuery()` promoted from protected to public | The reports need the same posted-line aggregation the ledger already uses. Re-implementing it would have created a second definition of "posted" that could drift from the ledger's. No behaviour changed. |
| `app/Services/Accounting/SettlementService.php` | `statusFrom()` renamed to public `statusFromFigures(Money $grandTotal, Money $allocated): TransactionStatus`; `statusForInvoice`/`statusForBill` call the renamed method | The statements need to know whether a document is still outstanding. The rule already existed and was correct; it was only private. The rename makes the public name say what it returns. No behaviour changed. |

### Controllers changed (1)

`app/Http/Controllers/Api/Accounting/ReportController.php` — rewritten from an empty
placeholder to 11 read-only actions plus a private `authorizeReporting()` and a
company-scoped `resolveAccount()`. Constructor injects `CompanyContext` and the
11 report services.

### Requests added (12)

`app/Http/Requests/Accounting/Reports/`: `ReportRequest` (base),
`TrialBalanceRequest`, `GeneralLedgerRequest`, `ProfitLossRequest`,
`BalanceSheetRequest`, `CustomerStatementRequest`, `SupplierStatementRequest`,
`ReceivablesReportRequest`, `PayablesReportRequest`, `ReceivablesAgingRequest`,
`PayablesAgingRequest`, `CashBankRequest`.

### Resources added (0)

Deliberate deviation, documented in §18: reports return plain arrays through
`ApiResponse::success(message, data)`, matching `LedgerController`, rather than
pass-through Resource classes. A report's shape is already defined once, in its
service; a Resource that only renames fields would restate it in a second place
and could disagree with the service.

### Policies added (0)

Reports have no create/update/delete to guard, and authorization is a single
permission gate. Adding eleven read-only policies would be ceremony.

### Routes changed (1)

`routes/api.php` — 11 routes under a new `Route::prefix('reports')` group nested
inside the existing `Route::prefix('accounting')` group.

### Authorization changed (2)

- `app/Enums/PermissionName.php` — added `case ReportsView = 'accounting.reports.view';`
- `config/authorization.php` — added `accounting.reports.view` to the permission
  list and to the Accountant and Manager role arrays. Staff untouched, Admin
  already holds `*`.

### Config changed (1)

`config/accounting.php` — added a `reports.aging_buckets` key.

### Tests added (10 files, 66 tests)

`tests/Feature/Reports/`: `TrialBalanceReportTest` (11), `GeneralLedgerReportTest`
(9), `ProfitLossReportTest` (7), `BalanceSheetReportTest` (7),
`CustomerStatementReportTest` (7), `ReceivablesReportTest` (6),
`SupplierStatementReportTest` (5), `PayablesReportTest` (5), `AgingReportTest` (5),
`CashBankReportTest` (4).

`tests/TestCase.php` gained two helpers, `postCustomerReceipt()` and
`postSupplierPayment()`, which create and post a settlement document in one call.

## 3. Report Definitions

Every report shares these four properties.

**Date semantics.** All accounting dates are DATE columns
(`journals.journal_date`, `sales_invoices.invoice_date`, `purchase_bills.bill_date`,
`customer_receipts.receipt_date`, `supplier_payments.payment_date`). A window is
applied inclusively — `date >= from_date` and `date <= to_date` — with no
`created_at` or `posted_at` anywhere in a report filter. Because the columns hold
no time component, an inclusive `<=` is exactly the brief's "through `to_date`
23:59:59" without a time cast.

**Sign convention.** Journal lines are stored debit-positive; a credit is a
positive number in the `credit` column. A report's *section* total applies the
**account type's** normal balance via `AccountType::normalBalance()`:

| Section | Types | Normal balance | Contribution |
|---|---|---|---|
| Assets | `ASSET` | debit | `debit − credit` |
| Liabilities | `LIABILITY` | credit | `credit − debit` |
| Equity | `EQUITY` | credit | `credit − debit` |
| Revenue | `REVENUE` | credit | `credit − debit` |
| Expenses | `EXPENSE` | debit | `debit − credit` |

A contra account is typed like the section it offsets, so it reduces that section.
The General Ledger is the deliberate exception: it keeps the raw debit-positive
running balance the brief specifies and adds `closing_balance_signed` for clients
that want the normal-direction figure.

**Posted-only.** The four journal reports read exclusively through
`LedgerService::postedLinesQuery()`, which filters to `POSTED` journals. The
document reports filter to non-draft / `POSTED` statuses. No report can see a
draft.

**Tenancy.** Every query is filtered by `company_id` taken from `CompanyContext`.
No report accepts a `company_id` from the client.

### Trial Balance

- Source: `journals` + `journal_lines` via `LedgerService::postedTotalsByAccount()`.
- `debit_total = SUM(journal_lines.debit)`, `credit_total = SUM(journal_lines.credit)`,
  `net_balance = debit_total − credit_total`.
- `totals.net_balance = totals.debit_total − totals.credit_total`;
  `is_balanced` is `net_balance == 0`.
- Zero filter: rows with `net_balance == 0` are hidden unless
  `include_zero_balances=1`. Grand totals are computed over the **visible** rows,
  so the report still foots after filtering: a zero-net account has equal gross
  debits and gross credits, so dropping it removes the same amount from both
  columns and `is_balanced` is unchanged. The test asserts the exact filtered
  totals (`200.0000` on each side) rather than only the balanced flag.
- Filters: `from_date`/`to_date` (aliases `from`/`to`), `include_zero_balances`.

### General Ledger

- Source: `journal_lines` for one account via `LedgerService::postedLinesQuery()`.
- `opening_balance = SUM(debits before from_date) − SUM(credits before from_date)`,
  computed with no upper bound so it is the true cumulative balance.
- `running_balance = opening_balance + Σ(debits through row) − Σ(credits through row)`.
- `closing_balance = opening_balance + period_debits − period_credits`;
  `closing_balance_signed` applies the account's normal direction.
- Ordering: `journal_date, journals.id, journal_lines.id` — deterministic, never
  database row order. Each row carries `journal_id`, `journal_line_id`,
  `journal_number`, `journal_date`, `reference`, `description`, `source_type`,
  `source_id`.
- Filters: `account_id` (required, company-scoped), `from_date`/`to_date`.

### Profit & Loss

- Source: `journal_lines` on `REVENUE` and `EXPENSE` accounts, windowed.
- `net_profit = revenue.total − expenses.total`; `is_profitable = net_profit > 0`.
- Rows grouped per account with code and name, then category totals.
- `gross_profit` is emitted and currently equals `net_profit`: this schema has no
  cost-of-goods-sold account concept, so a gross-profit line would be a rule this
  phase has no basis for.
- Filters: `from_date`/`to_date`.

### Balance Sheet

- Source: `journal_lines` on `ASSET`, `LIABILITY` and `EQUITY` accounts,
  cumulative through `to_date` (point-in-time, not windowed).
- `retained_earnings = cumulative net profit through to_date` (all revenue less
  all expenses in the company's history to that date).
- `equity.total = Σ(EQUITY accounts) + retained_earnings`.
- `totals.liabilities_and_equity = liabilities.total + equity.total`;
  `totals.difference = assets.total − liabilities_and_equity`;
  `is_balanced = difference == 0`.
- `current_period_result` = net profit over `[from_date, to_date]`. It is
  **informational only** and is deliberately not folded into `equity.total`,
  because that profit is already inside `retained_earnings` and adding it again
  would double count. A client wanting A = L + E + NetProfit reads
  `equity.total + current_period_result` and compares with `assets.total`. This is
  stated in the response envelope so the client does not have to guess.
- Filters: `from_date`/`to_date`; `to_date` alone gives a point-in-time sheet.

### Customer Statement

- Source: posted `sales_invoices` (debit, `grand_total`) and posted
  `customer_receipts` (credit, `amount`). Runs receivable-positive.
- `opening_balance` sums every entry dated before `from_date`; rows cover
  `[from_date, to_date]`; `closing_balance` is the final running balance.
- Settlement is not re-derived: it comes from the Phase 5 documents themselves, so
  no `paid_total` column is needed or added.
- Filters: `customer_id` (required, company-scoped), `from_date`/`to_date`.

### Supplier Statement

- Source: posted `purchase_bills` (credit) and posted `supplier_payments` (debit).
  Runs payable-positive (positive means we owe the supplier).
- Same opening/running/closing arithmetic; `closing_balance` is the final running
  balance so the sign matches the column above it and
  `SettlementService::supplierPayable()`. See §16 item 1.
- Filters: `supplier_id` (required, company-scoped), `from_date`/`to_date`.

### Receivables

- Source: `sales_invoices` with `scopeWithOutstandingBalance()` (which is
  `grand_total > SUM(posted allocations)`, i.e. `balance_due > 0`), plus
  `SettlementService::attachFiguresForInvoices()` for the paid/balance figures.
- Per invoice: number, date, customer, `grand_total`, `paid_total`, `balance_due`,
  `due_date`, `days_past_due`.
- `days_past_due = as_of − due_date`, whole days, floored at 0.
- `as_of` defaults to today and bounds `invoice_date <= as_of`.
- Filters: `as_of`, `customer_id` (optional, company-scoped).

### Payables

- Source: `purchase_bills` with the same scope, plus
  `attachFiguresForBills()`. Same shape, `bill_*` keys and `supplier` instead of
  `customer`.
- Filters: `as_of`, `supplier_id` (optional, company-scoped).

### Aged Receivables / Aged Payables

- Source: the Receivables/Payables report rows, re-bucketed. The aging reports
  delegate rather than re-querying, so an invoice can never be in one report and
  missing from the other, and the two totals cannot disagree.
- `buckets` comes from `config('accounting.reports.aging_buckets')`:

  | key | label | min | max |
  |---|---|---|---|
  | `current` | Current (0-30) | `null` | 30 |
  | `days_31_60` | 31-60 days | 31 | 60 |
  | `days_61_90` | 61-90 days | 61 | 90 |
  | `days_91_120` | 91-120 days | 91 | 120 |
  | `days_121_plus` | 121+ days | 121 | `null` |

- Aging basis is the existing Phase 5 `due_date` column. The brief says to use
  invoice date "unless the existing project already has a documented due-date
  field"; Phase 5 added `due_date` to both invoices and bills, and it is populated
  and validated, so `due_date` is the more accurate basis and no field was invented.
- The first bucket's `min` is `null` so a not-yet-due document (`days_past_due` 0)
  lands in "current" rather than being excluded.
- Response rows are grouped per counterparty, sorted by name.
- Filters: `as_of`, `customer_id`/`supplier_id` (optional, company-scoped).

### Cash / Bank Activity

- Source: `GeneralLedgerReportService` for the requested account, unchanged.
  Opening, dated movements, running balance, period totals, closing.
- Not type-restricted. Nothing in Phase 5 requires a payment account to be an
  `ASSET`, so refusing a liability-typed account would invent a rule.
- Filters: `account_id` (required, company-scoped), `from_date`/`to_date`.

## 4. API Endpoints

All endpoints are `GET`, require authentication and an active company context, and
require `accounting.reports.view`.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/api/accounting/reports/trial-balance` | `accounting.reports.view` | Gross debits/credits and net balance per account, with grand totals that must foot |
| GET | `/api/accounting/reports/general-ledger` | `accounting.reports.view` | All posted movements for one account with opening, running and closing balance |
| GET | `/api/accounting/reports/profit-loss` | `accounting.reports.view` | Revenue, expenses and net profit for a window |
| GET | `/api/accounting/reports/balance-sheet` | `accounting.reports.view` | Assets, liabilities, equity and retained earnings at a date |
| GET | `/api/accounting/reports/customer-statement` | `accounting.reports.view` | Invoices and receipts for one customer with a running balance |
| GET | `/api/accounting/reports/supplier-statement` | `accounting.reports.view` | Bills and payments for one supplier with a running balance |
| GET | `/api/accounting/reports/receivables` | `accounting.reports.view` | Outstanding invoices with paid, balance due and days past due |
| GET | `/api/accounting/reports/payables` | `accounting.reports.view` | Outstanding bills with paid, balance due and days past due |
| GET | `/api/accounting/reports/receivables-aging` | `accounting.reports.view` | Outstanding invoices bucketed by age, per customer |
| GET | `/api/accounting/reports/payables-aging` | `accounting.reports.view` | Outstanding bills bucketed by age, per supplier |
| GET | `/api/accounting/reports/cash-bank` | `accounting.reports.view` | Cash/bank account activity in general-ledger shape |

Route names: `accounting.reports.trial-balance`, `.general-ledger`,
`.profit-loss`, `.balance-sheet`, `.customer-statement`, `.supplier-statement`,
`.receivables`, `.payables`, `.receivables-aging`, `.payables-aging`, `.cash-bank`.

Response shape is the project's existing wrapper:

```json
{
  "success": true,
  "message": "Trial balance generated successfully.",
  "data": {
    "period": { "from": "2027-01-01", "to": "2027-01-31" },
    "rows": [],
    "totals": {}
  }
}
```

## 5. Authorization Matrix

One new permission, registered in `config/authorization.php` and applied by
`app:sync-roles` / `RolePermissionSynchroniser`.

| Role | `accounting.reports.view` | Reports available |
|---|---|---|
| Admin | yes (holds `*`) | all 11 |
| Accountant | yes | all 11 |
| Manager | yes | all 11 |
| Staff | no | none — 403 on every report |

Details:

- The permission string is `accounting.reports.view`, declared as
  `PermissionName::ReportsView`. It follows the existing
  `accounting.<area>.<action>` convention used by Phases 4 and 5.
- The brief listed eleven suggested permissions and explicitly said to avoid
  unnecessary ones if the architecture supports a simpler reporting permission. It
  does, so one permission is used.
- Enforcement is doubled on purpose: `ReportRequest::authorize()` denies before
  validation runs, and `ReportController::authorizeReporting()` authorises before
  any service is called. Neither layer can be forgotten by a future report.
- Spatie registers each permission as a gate, so the two checks are the same gate
  evaluated at two points in the request lifecycle.
- No Phase 5 transaction permission was changed, and posting remains restricted
  exactly as Phase 5 established it.

## 6. Company Isolation

Isolation is enforced at four points, none of which trusts the client:

1. **Middleware.** Every report route sits inside the existing
   `['auth:api', 'auth.fresh', 'company.context', 'throttle:api']` group, so the
   active company is resolved from the authenticated user's membership by
   `ResolveCompanyContext`. A user who is not a member of the requested company
   gets 403 before any report code runs.
2. **Request rules.** `companyAccountRule()`, `companyCustomerRule()` and
   `companySupplierRule()` scope their `Rule::exists()` to
   `Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId())`.
   A plain `exists` would accept another company's id; the scoped constraint does
   not. A cross-company id is a 422 on the named field, not a 200 with empty data.
3. **Service queries.** Every report service filters on
   `->where('company_id', $company->getKey())`, where `$company` is the
   `CompanyContext` company. `LedgerService` already scopes its posted-line query
   the same way, so the journal reports inherit it.
4. **Controller lookups.** `resolveAccount()` and the customer/supplier lookups
   use `findOrFail()` **inside** a `company_id` filter. Route model binding does
   not apply to a query-string id, so this is the filter that actually enforces
   tenancy; repeating the scope here means a request class that forgets its rule
   still cannot leak data.

No report request accepts `company_id`, and no report controller reads a company
id from the body or query string.

Tests cover this at every report class: a cross-company `account_id`,
`customer_id` or `supplier_id` is rejected, an unrelated company context is 403,
and the trial balance returns only the active company's accounts even when
another company has a same-coded account with no movement.

## 7. Accounting Integrity

**Posted-only.** The four journal reports read through
`LedgerService::postedLinesQuery()`, which filters `journals.status = POSTED`. A
draft journal has no place in a financial statement, and one is created in the
trial balance, general ledger, P&L and balance sheet tests to prove it is absent.

**Trial balance equality.** `debit_total − credit_total` is computed twice, once
per account and once over the grand totals, and `is_balanced` reports the result.
The test asserts `debit_total == credit_total` on real posted journals, including
a three-decimal amount (`1175.2500`) so the equality is not an artifact of round
numbers.

**Balance sheet equation.** `retained_earnings` is computed from revenue and
expense journal lines rather than stored, so the sheet balances because the
accounting balances, not because a balancing figure was inserted. Three separate
tests prove the equation: one with a liability, one where a contra asset reduces
the asset section, and one where the current-period result is present but
excluded from equity. The `difference` field is always in the response, so a
mismatch is visible rather than silent.

**P&L treatment.** Windowed revenue less expenses. `is_profitable` distinguishes a
profit from a loss. No journal value is altered for presentation: a contra revenue
account reduces `revenue.total` through the same normal-balance rule as every
other section, and the test asserts a loss is reported as a negative `net_profit`
with `is_profitable` false.

**Allocation-based AR/AP.** Receivables and payables use
`scopeWithOutstandingBalance()` and `SettlementService::attachFigures*()`. The
figures come from `customer_receipt_allocations` / `supplier_payment_allocations`
via the posted parent documents. No `paid_total` or `balance_due` column exists
and none was added; the tests change an allocation by posting a receipt or
payment and assert the outstanding amount moves.

**One calculation per fact.** The trial balance, general ledger, P&L and balance
sheet all aggregate through the same `LedgerService` posted-line query rather than
four private ones. The aging reports consume the receivables/payables output
rather than re-querying, and a test asserts the aging total equals the receivables
`balance_due` total for the same `as_of`.

**Money.** All arithmetic goes through `Money` (exact decimal, scale 4). No
`(float)` cast appears in any report service; `Money::toDatabase()` is the only
amount formatter on the way out. This keeps `DECIMAL(20,4)` precision intact
throughout.

## 8. Performance

- **Trial balance**: one grouped aggregation over `journal_lines` for all accounts
  (`LedgerService::postedTotalsByAccount`). No per-account query, and no account
  is queried twice. The brief's explicit "Trial Balance should not execute one
  query per account" is satisfied by construction.
- **General ledger / cash-bank**: one deterministic query for the account's posted
  lines, plus one for the opening balance. Two queries, not one per row.
- **P&L / balance sheet**: two grouped aggregations (revenue, expenses) and one
  grouped aggregation across balance-sheet accounts. Retained earnings reuses the
  same revenue/expense aggregation rather than issuing a second query.
- **Statements**: one query for each side (invoices, receipts) and then in-memory
  merge, sort and running balance. No query per transaction.
- **Receivables / payables**: eager-loads the counterparty relationship and calls
  `SettlementService::attachFiguresForInvoices()` / `attachFiguresForBills()`,
  which aggregate allocations for the whole page in one query. Listing n invoices
  is a constant number of queries.
- **Aging**: adds no queries of its own; it re-uses the receivables/payables
  result set.
- **Date filtering** is pushed into SQL (`whereDate` / `whereBetween` on the
  accounting date column) rather than filtering rows in PHP.
- No floating-point arithmetic in SQL and no report loads the whole journal table
  into memory.

## 9. Tests

```
php artisan test tests/Feature/Reports
Tests:      66
Assertions: 536
Result:     passed (0 failures, 0 errors, 0 skipped)

php artisan test
Tests:      509
Assertions: 2398
Result:     passed (0 failures, 0 errors, 0 skipped)
```

509 total = 66 Phase 6 + 443 Phase 1–5. The Phase 5 baseline of 443 tests and
1862 assertions still passes unchanged, so there is no accounting regression.

Coverage by file:

| File | Tests | Focus |
|---|---|---|
| `TrialBalanceReportTest` | 11 | gross debit/credit columns, net balance, totals foot, zero-balance filtering, draft exclusion, cross-company exclusion, inclusive window, `from`/`to` aliases, backwards window 422, permission, unrelated company 403, active-company-only accounts |
| `GeneralLedgerReportTest` | 9 | opening, running, period debits/credits, closing, credit-normal sign, opening carried from before the window, draft exclusion, no-movement account, `account_id` required and company-scoped, deterministic same-day ordering |
| `ProfitLossReportTest` | 7 | revenue and expense aggregation by account, net profit, loss case, window, draft exclusion, cross-company exclusion, empty sections, permission |
| `BalanceSheetReportTest` | 7 | equation via computed retained earnings, liability keeps the equation, contra asset reduces the section, as-of caps the sheet, current-period result is informational, draft exclusion, permission |
| `CustomerStatementReportTest` | 7 | invoices as debits, receipts as credits, running and closing balance, draft invoice and draft receipt excluded, opening carried from before the window, required id, cross-company 422, backwards window 422, permission |
| `SupplierStatementReportTest` | 5 | bills as credits, payments as debits, payable-positive closing, opening carried, required id, cross-company 422, permission |
| `ReceivablesReportTest` | 6 | grand/paid/balance due, days past due, fully paid excluded, not-yet-due is current, customer filter, cross-company 422, permission |
| `PayablesReportTest` | 5 | bill equivalent of the above |
| `AgingReportTest` | 5 | receivables bucket boundaries (5 / 54 / 104 days), payables bucketing, counterparty grouping and sort, aging total equals receivables total, cross-company 422, permission |
| `CashBankReportTest` | 4 | ledger-shaped output for a cash account, required `account_id`, cross-company 422, permission |

Integrity properties the brief asks for, and where they are proven:

| Property | Test |
|---|---|
| No draft journal enters a report | `TrialBalanceReportTest`, `GeneralLedgerReportTest`, `ProfitLossReportTest`, `BalanceSheetReportTest` each create a draft journal alongside a posted one |
| Cross-company isolation | every report class; plus `it_only_returns_accounts_belonging_to_the_active_company` creates a same-coded account in another company |
| Report consistency | `AgingReportTest::the_aging_total_matches_the_receivables_report` |
| Trial balance equality | `TrialBalanceReportTest::the_totals_foot_and_the_report_reports_balanced` |
| Balance sheet equation | `BalanceSheetReportTest` (three separate proofs) |
| P&L reconciles with the balance sheet treatment | `BalanceSheetReportTest` current-period-result test, which fixes the window P&L figure and shows it is excluded from equity |
| Allocation-based settlement | `ReceivablesReportTest` / `PayablesReportTest` post a receipt/payment and assert the outstanding amount changes |
| No stored balance dependency | structural — no `paid_total`/`balance_due` column exists; every figure is computed from allocations through `SettlementService` |

Deliberate gap, carried forward from Phase 5: there is no concurrency test, and
none was added. Reports are read-only, so the Phase 5 allocation-locking race is
not exercised by this phase.

## 10. Commands Run

```
php artisan test tests/Feature/Reports        → 66 tests, 536 assertions, passed
php artisan test                              → 509 tests, 2398 assertions, passed
./vendor/bin/pint                             → fixed 2 files (Phase 6 files only)
./vendor/bin/pint --test                      → passed
php artisan route:list --path=reports         → 11 report routes present
php artisan route:list --path=api             → 94 API routes
php -l <each new/changed file>                → no syntax errors
```

No migration verification commands were run: Phase 6 added no migrations, so
`migrate:fresh` / `migrate:rollback` / `migrate` had nothing new to exercise. No
seeders were run.

Pint was run in fix mode across the project; it changed only two files, both of
them Phase 6 files (`JournalReportService.php` and `ProfitLossReportService.php`).
No Phase 1–5 file was reformatted, and `--test` afterwards is clean.

## 11. Issues Discovered

Found while implementing and testing, not by inspection alone.

1. **Supplier statement closing balance had the wrong sign.**
   `CounterpartyStatementReportService` computed
   `closing = opening + total_debit − total_credit`, which is correct for a
   customer but wrong for a supplier. A supplier statement is credit-normal, so a
   supplier owing 300 would have reported `closing_balance` of `-300.0000` while
   the `running_balance` column above it read `300.0000` — the same report
   contradicting itself, and disagreeing with
   `SettlementService::supplierPayable()`. Fixed by taking the final running
   balance as the closing figure, which is correct for both directions.

2. **`CounterpartyStatementReportService` could not be resolved.**
   It type-hinted `SettlementService` in the constructor without importing it, so
   the container looked for
   `App\Services\Accounting\Reports\SettlementService` and every report request
   failed with a `BindingResolutionException` (500). The failure surfaced in the
   trial balance permission test, because the controller resolves all 11 services
   in its constructor. Fixed by adding the missing import.

3. **`ReportRequest::fromDate()` / `toDate()` were `protected`.** The controller
   reads them from the request object, so every report action would have thrown
   `Error: Call to protected method ... fromDate()`. Caught by reading the request
   against the controller before the first test run, not by a test. Both are now
   `public`, matching the accessor visibility used by the Phase 1–5 requests they
   are modelled on.

4. **Two settlement report tests passed for the wrong reason.**
   `ReceivablesReportTest` and `PayablesReportTest` filtered by counterparty
   without passing `as_of`, which defaults to today. The fixtures are dated 2027,
   so every invoice was excluded by the date bound and the tests would have
   passed even if the counterparty filter were ignored. Fixed by passing an
   explicit `as_of`, which also made `it_excludes_fully_paid_invoices` genuinely
   exercise the outstanding-balance scope instead of accidentally asserting the
   date filter.

5. **TestCase helper name collision.** The new `postReceipt()` / `postPayment()`
   helpers collided with the private helpers of the same name in
   `CustomerReceiptTest` and `SupplierPaymentTest`, which is a fatal error, not a
   warning. Renamed to `postCustomerReceipt()` and `postSupplierPayment()`.

6. **Two test expectation errors, not product defects.** The zero-balance trial
   balance test asserted grand totals of `100/100` where the correct figures are
   `200/200`, and the general ledger test asserted `period_debits` of 200 and a
   closing balance of 150 where the correct figures are 250 and 200. Both were
   arithmetic slips in the test, corrected after recomputing from the fixtures.

7. **Pint found two Phase 6 style issues** in the new code: an unused import in
   `ProfitLossReportService` and brace/unary-operator formatting in
   `JournalReportService`. Fixed automatically; `--test` is clean.

## 12. Known Limitations

- **Statements exclude manual journals.** The brief lists "adjustments
  represented by posted journals if applicable". A `journal_lines` row has no
  `customer_id` or `supplier_id`, so there is no honest way to attribute a manual
  journal to a counterparty. Including one by guessing at the control account
  would put a figure on a statement the ledger cannot trace back. Document-level
  activity is complete; counterparty-attributable manual adjustments are not
  representable in the current schema. Fixing this needs a schema change (a
  counterparty reference on journal lines), which this phase was told not to make.
- **`as_of` does not time-scope payments.** Receivables, payables and both aging
  reports bound the *document* date and days-past-due with `as_of`, but
  settlement figures come from all posted receipts/payments, because the schema
  keeps no per-date allocation history. Asking for "what did this customer owe as
  of last March" therefore answers with current settlement. A historical point-in-time
  AR figure would need an allocation history table.
- **`gross_profit` is not distinct.** The P&L has no cost-of-goods-sold
  distinction, so `gross_profit` equals `net_profit`. Emitting the key keeps the
  response shape stable for the frontend; a distinct gross profit needs a COGS
  concept the accounts do not carry.
- **No financial-year or period parameter.** Reports accept dates only. The brief
  asks that reports work for a specific date, a range, a financial year and an
  accounting period; a date range covers the first three, and because
  `AccountingPeriod` is a closed-month construct with no year entity, there is no
  separate year selector. No report filters by period id.
- **No comparative columns.** Only one period per request; no prior-period or
  budget variance.
- **Aging is due-date based.** Documented in §3. Where a document has a `due_date`
  in the past but the business considers it current, the buckets will show it as
  overdue. That is the standard convention.
- **No N+1 audit for every endpoint.** Aggregation strategy is documented in §8
  and the constant-query paths are structural, but no test asserts a query count
  against a large dataset.
- **Concurrency remains untested**, as in Phase 5.

## 13. Outstanding Work

- PDF/Excel/CSV export — explicitly deferred by the brief.
- Comparative reporting: prior period, same period last year, budget variance.
- A `journal_lines.counterparty_id` reference so statements can include
  counterparty-attributable manual adjustments.
- Allocation history so `as_of` can answer a true historical AR/AP position.
- Cost of goods sold, giving the P&L a distinct gross profit.
- Report column preferences (which accounts appear by default) once a chart of
  accounts with sub-types exists.
- Pagination on receivables, payables and aging for very large document volumes.
- A `testbench`-level concurrency test for allocation locking (Phase 5 carry-over).

## 14. Requirements Traceability

| Requirement | Implementation | Test |
|---|---|---|
| Trial balance: account code, name, type | `TrialBalanceReportService` | `TrialBalanceReportTest::it_reports_gross_debits_and_credits_and_a_net_balance_per_account` |
| Trial balance: debit total, credit total, net balance | `TrialBalanceReportService` | same test |
| Trial balance: optional zero-balance filtering | `TrialBalanceReportService` + `TrialBalanceRequest` | `TrialBalanceReportTest::a_net_zero_account_is_hidden_by_default_and_shown_on_request` |
| Trial balance: grand totals | `TrialBalanceReportService` | `TrialBalanceReportTest::the_totals_foot_and_the_report_reports_balanced` |
| Trial balance: debits == credits proved | `totals.is_balanced` / `difference` | same test |
| Trial balance: from posted journal lines, no stored balances | `LedgerService::postedTotalsByAccount` | `TrialBalanceReportTest::a_draft_journal_never_appears` |
| GL: account info, opening, rows, running, closing | `GeneralLedgerReportService` | `GeneralLedgerReportTest::it_lists_posted_movements_with_a_running_balance` |
| GL: opening = debits − credits before `from_date` | `GeneralLedgerReportService::openingBalance()` | `GeneralLedgerReportTest::it_carries_an_opening_balance_from_before_the_window` |
| GL: running balance formula | `GeneralLedgerReportService` | `it_lists_posted_movements_with_a_running_balance` |
| GL: closing = opening + period debits − period credits | `GeneralLedgerReportService` | same test |
| GL: credit-normal accounts expose a raw and a signed closing | `GeneralLedgerReportService` + `NormalBalance` | `a_credit_normal_account_reports_a_raw_negative_closing_and_a_positive_signed_one` |
| GL: deterministic ordering (date, journal id, line id) | `GeneralLedgerReportService::movements()` | `GeneralLedgerReportTest::it_lists_movements_in_creation_order_within_a_day` |
| GL: date filtering | `GeneralLedgerRequest` + `withinPeriod()` | `it_carries_an_opening_balance_from_before_the_window` |
| GL: account and company isolation | `GeneralLedgerRequest::companyAccountRule()` + `resolveAccount()` | `GeneralLedgerReportTest::an_account_from_another_company_is_rejected` |
| P&L: revenue aggregation | `ProfitLossReportService` | `ProfitLossReportTest::it_reports_revenue_expenses_and_net_profit` |
| P&L: expense aggregation | same | same test |
| P&L: net profit / net loss | `net_profit`, `is_profitable` | same test and `it_reports_a_loss_when_expenses_exceed_revenue` |
| P&L: normal sign convention, no value altered | `signedForType()` | `it_reports_a_loss_when_expenses_exceed_revenue` |
| P&L: from_date / to_date | `ProfitLossRequest` | `ProfitLossReportTest::the_date_window_limits_the_statement` |
| P&L: draft exclusion, company isolation, authorization | shared base + `postedLinesQuery` | `a_draft_journal_never_appears`, `another_companys_activity_never_appears`, `the_report_requires_the_reports_permission` |
| Balance sheet: assets, liabilities, equity per account | `BalanceSheetReportService` | `BalanceSheetReportTest::assets_equal_liabilities_plus_equity_through_retained_earnings` |
| Balance sheet: A == L + E | `totals.is_balanced` / `difference` | same test |
| Balance sheet: current period result without a stored balance | `current_period_result` | `BalanceSheetReportTest::the_current_period_result_is_reported_but_not_double_counted` |
| Balance sheet: point-in-time / date filtering | `BalanceSheetRequest` + cumulative `to_date` | `BalanceSheetReportTest::the_as_of_date_caps_the_sheet` |
| Balance sheet: no stored retained earnings inserted to force a balance | `retainedEarnings()` computed from revenue/expense lines | `assets_equal_liabilities_plus_equity_through_retained_earnings` |
| Customer statement: info, opening, invoices, receipts, running, closing | `CustomerStatementReportService` | `CustomerStatementReportTest::it_lists_invoices_as_debits_and_receipts_as_credits_with_a_running_balance` |
| Customer statement: settlement from Phase 5 allocations, not a stored `paid_total` | documents queried directly; no column added | `it_lists_invoices_as_debits_and_receipts_as_credits_with_a_running_balance` |
| Customer statement: posted only | `status != DRAFT` / `status = POSTED` | `it_excludes_draft_invoices_and_draft_receipts` |
| Customer statement: date filtering, opening from before the window | `CounterpartyStatementReportService` | `it_carries_an_opening_balance_from_before_the_window` |
| Customer statement: customer isolation, cross-company rejection | `companyCustomerRule()` | `it_refuses_a_customer_from_another_company` |
| Supplier statement: equivalent coverage | `SupplierStatementReportService` | `SupplierStatementReportTest` (5 tests) |
| Receivables: outstanding invoices with totals, allocated, outstanding, status data | `ReceivablesReportService` | `ReceivablesReportTest::it_lists_outstanding_invoices_with_paid_and_balance_due` |
| Receivables: outstanding derived from allocations, no `paid_total` column | `scopeWithOutstandingBalance` + `attachFiguresForInvoices` | same test |
| Receivables: only `balance_due > 0` | scope | `it_excludes_fully_paid_invoices` |
| Receivables: `customer_id` filter | `ReceivablesReportRequest` | `it_filters_by_customer` |
| Receivables: date/reference behaviour | `as_of` | `it_lists_outstanding_invoices_with_paid_and_balance_due` and `an_invoice_not_yet_due_is_current` |
| Receivables: company isolation, authorization | scoped rules + gate | `it_refuses_a_customer_from_another_company`, `the_report_requires_the_reports_permission` |
| Payables: equivalent coverage | `PayablesReportService` | `PayablesReportTest` (5 tests) |
| Aging: configurable buckets | `config('accounting.reports.aging_buckets')` + `AgesDocuments::agingBuckets()` | `AgingReportTest::it_buckets_receivables_by_days_past_due` |
| Aging: current 0–30, 31–60, 61–90, 91–120, 121+ | bucket config boundaries | same test (5 / 54 / 104 days asserted) |
| Aging: per counterparty, per bucket, total | `AgingReportService::bucketed()` | same test |
| Aging: derived from outstanding balances | delegates to `ReceivablesReportService` | `the_aging_total_matches_the_receivables_report` |
| Aging: fully paid excluded, partial aged correctly | inherits the outstanding scope | `the_aging_total_matches_the_receivables_report` |
| Aging: reference date behaviour | `as_of` | `it_buckets_receivables_by_days_past_due` |
| Aging: company isolation, authorization | scoped rules + gate | `it_refuses_a_customer_from_another_company`, `the_report_requires_the_reports_permission` |
| Payables aging: equivalent | `PayablesAgingReportService` | `AgingReportTest::it_buckets_payables_by_days_past_due` |
| Cash/bank: account, opening, dates, reference, description, debit, credit, running, closing | `CashBankReportService` → `GeneralLedgerReportService` | `CashBankReportTest::it_returns_ledger_activity_for_a_cash_account` |
| Cash/bank: derived from posted journal lines, no cash table | no new table; GL query | same test |
| Cash/bank: company isolation | `companyAccountRule()` | `it_refuses_an_account_from_another_company` |
| Reports under a reporting namespace | `app/Services/Accounting/Reports/`, `app/Http/Requests/Accounting/Reports/`, `app/Http/Controllers/Api/Accounting/` | — |
| Dedicated report services, not SQL in controllers | all 11 services | — |
| No second ledger, no stored balances, no snapshots | no migrations; no new models | structural; §4 and §7 |
| Routes under `GET /api/accounting/reports/*` | `routes/api.php` | `php artisan route:list --path=reports` |
| No report endpoint mutates data | all routes `GET`; no writes in any service | structural |
| No report accepts `company_id` / `journal_id` / `balance` / `paid_total` | `ReportRequest` has no such rule | structural; §6 |
| Validation: dates, ranges, account/customer/supplier ids | 12 Form Requests | every report class has a validation test |
| `from_date <= to_date` | `ReportRequest::withValidator()` | `a_backwards_date_window_is_rejected` in two classes |
| Ids must belong to the active company | scoped `Rule::exists` | cross-company 422 test in every class |
| Authorization extended, no second mechanism | one permission in `config/authorization.php` | permission test in every class |
| Admin / Accountant / Manager yes, Staff no | role arrays | `the_report_requires_the_reports_permission` (Staff 403) |
| Phase 5 permissions and posting unchanged | no Phase 5 config touched | full suite 509 passing |
| No floats, `Money` and `DECIMAL(20,4)` preserved | `Money` throughout, `Money::toDatabase()` on output | `1175.2500` and `1000.0000` assertions |
| No N+1, use grouped aggregation | §8 | structural; no query-count assertion |
| Date semantics explicit, accounting date not `created_at` | §3 | draft/post exclusion and window tests |
| Report consistency, one reusable query | `LedgerService` shared; aging delegates | `the_aging_total_matches_the_receivables_report` |
| Zero-balance filtering, totals still foot | §3 | `a_net_zero_account_is_hidden_by_default_and_shown_on_request` |
| Existing API response convention | `ApiResponse::success()` | `assertJsonPath('data.…')` throughout |
| No HTML in the API | JSON only | — |
| No export | not implemented | — |
| Tests: Feature/Unit coverage per report | 10 test files | §9 |
| Critical integrity tests | §9 table | §9 table |
| Regression protection | baseline 443 → 509 | `php artisan test` |
| Pint passes | `./vendor/bin/pint --test` | passed |
| Route verification | `php artisan route:list --path=reports` | 11 routes |
| `PHASE_6_REPORT.md` created | this file | — |
| Phase 5 limitations not silently implemented | §12 | none implemented |
| No premature abstraction | 15 small services, no engine/DSL/cache | — |
| Sign conventions determined from the existing implementation, not assumed | `AccountType::normalBalance()` and the `NormalBalance` enum | `GeneralLedgerReportTest` credit-normal test, `BalanceSheetReportTest` contra-asset test |
