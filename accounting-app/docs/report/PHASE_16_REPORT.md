# PHASE 16 REPORT — BUDGETING & BUDGET VARIANCE ANALYSIS

## 1. Executive Summary

Phase 16 adds company-scoped budgeting and a read-only budget-versus-actual
report on top of the existing accounting architecture, without introducing a
second accounting engine, ledger, journal engine, balance engine, posting path,
audit system, FX system, tax engine or reporting engine.

The work reuses what Phases 4–15 already built:

- **Budgets are planning data, not accounting records.** A budget and its lines
  are stored in two new tables and never posted. No column in either table is a
  balance or an actual.
- **The ledger is the only source of actuals.** `BudgetVarianceReportService`
  computes every actual from `journal_lines JOIN journals WHERE status = 'POSTED'`
  through the same `LedgerService` the profit-and-loss report uses, so a budget
  report and the P&L for the same window cannot disagree.
- **Sign conventions are not restated.** Both the plan and the actual are
  expressed on the account type's normal side using the existing enumeration
  authority (`AccountType::increasesOnCredit()`), and favourability is derived
  from that same authority rather than hard-coded.
- **The lifecycle is one controlled transition.** A budget is `DRAFT` or
  `APPROVED`; an approved budget is immutable, and a change is a new draft
  **version** in the same `(company, code)` chain rather than an edit.
- Authorization, company isolation, audit, decimal precision, error handling and
  testing follow the existing conventions exactly.

**Balance-sheet budgeting, forecasting, multi-currency budgets and scenario
analysis were deliberately NOT implemented.** Budgeting a balance-sheet account
needs opening balances and cash-flow to mean anything, and the internal
architecture keeps period plans in the profit-and-loss space only; the brief's
explicit scope-out of balance-sheet and forward projection is honoured. This is
recorded in §12.

Final status: **PASS WITH NOTES**.

---

## 2. Objectives

1. Let a company record a period-by-period plan for revenue and expenses, per
   financial year, with a controlled approval step.
2. Let the company compare that plan to what actually happened, per account and
   per accounting period, with a correct and unambiguous favourability signal.
3. Never create a second source of truth: no stored actuals, no duplicated
   balances, no budget journal.
4. Be authorized, company scoped, audited, transactionally safe and concurrency
   safe.
5. Reuse the existing `financial_years`, `accounting_periods`, `accounts` and
   `journal_lines` structures, `LedgerService`, `JournalReportService`,
   `AccountingRules`, `Money`, `AuditService`, `CompanyContext`, `PermissionName`,
   policy registration and company-scoped route bindings.

---

## 3. Existing Architecture Reviewed

Inspected before any change:

- **Chart of accounts** — `Account` model and migration, `AccountType`
  (`ASSET`/`LIABILITY`/`EQUITY`/`REVENUE`/`EXPENSE`), `NormalBalance`,
  `AccountingRules` (`normalBalanceFor`, `signedBalance`, `splitForTrialBalance`)
  and `AccountType::increasesOnCredit()`.
- **Ledger** — `LedgerService` (`postedLinesQuery`, `postedTotalsByAccount`,
  `totalsFor`) is the single definition of posted-only, company-scoped balances.
- **Reports** — the abstract `JournalReportService` (`postedLines`,
  `totalsByAccount`, `signedForType`, `period`, `baseCurrency`,
  `retainedEarnings`) and its concrete subclasses, especially
  `ProfitLossReportService`, plus `ReportController` and the report requests.
- **Periods and years** — `AccountingPeriod`/`FinancialYear` models, their
  factories, `AccountingPeriodResolver`, and how their dates bound the ledger.
- **Money** — `App\Support\Money` (bcmath, immutable, fixed scale) and
  `config/accounting.php` `precision` (`DECIMAL(20,4)` throughout).
- **Audit** — `AuditService` (single writer, `created`/`updated`/`deleted`/
  `lifecycle`) and the controlled `AuditAction` vocabulary.
- **Authorization** — `PermissionName`, `config/authorization.php`,
  `RolePermissionSynchroniser`, `AppServiceProvider` policy registration and the
  `bindAccountingModelsToActiveCompany()` route bindings; `FixedAssetCategoryPolicy`
  and the transaction-account trait were read as the closest CRUD precedent.
- **Error/response conventions** — `ApiResponse`, `ValidationException` usage,
  `ModelNotFoundException` for tenancy, and the `QueryException` → validation
  translation used by `FixedAssetCategoryService`.
- **Tests** — `tests/TestCase.php` helpers and the `ProfitLossReportTest` /
  fixed-asset test style.

### Hard-stop assessment

No hard-stop condition was met:

| # | Condition | Finding |
|---|-----------|---------|
| 1 | Budgeting would require a second ledger/engine | Not met — budgets are a separate, additive plan store; the ledger is untouched. |
| 2 | Actuals cannot be derived from posted journals | Not met — `LedgerService` derives them exactly as the P&L does. |
| 3 | The chart of accounts cannot express budget lines | Not met — budget lines reference ordinary accounts, restricted to Revenue/Expense. |
| 4 | Existing periods cannot scope a plan | Not met — budgets belong to a financial year; lines belong to its periods. |
| 5 | Multi-company isolation cannot be guaranteed | Not met — company-scoped route binding plus explicit service filters. |
| 6 | A migration would rewrite history | Not met — the two migrations are purely additive. |
| 7 | FX/base currency would be silently changed | Not met — budgets are base-currency only and no FX code was touched. |
| 8 | The audit vocabulary would need to be invented | Not met — one new action (`APPROVED`) is added for the real approval act. |

**Baseline before this phase (Phase 15): 1,180 tests / 5,250 assertions / 0
failures.**

---

## 4. Scope Delivered

1. Two additive migrations: `budgets` and `budget_lines`.
2. `Budget` and `BudgetLine` models, plus `BudgetStatus` and a single new
   `AuditAction::Approved`.
3. `BudgetService` (create / update / delete / approve / revise) and
   `BudgetLineService` (line create/update/delete with eligibility and tenancy
   rules).
3. `BudgetVarianceReportService` (read-only, posts nothing).
4. Five new permissions and their role grants, a `BudgetPolicy`, an explicit
   policy registration and a company-scoped `budget` route binding.
4. `BudgetController`, seven request classes, two resources and a route group.
5. 28 new feature tests across lifecycle, lines and variance.
6. This report.

**Explicitly not delivered (deliberate, with justification):** balance-sheet
budgets, multi-currency budgets, forecasting/scenario modelling, budget
templates, per-department/cost-centre budgeting, budget alerts, and a
`SUPERSEDED` budget state. See §12.

---

## 5. Database Changes

Two new tables. Both are additive; no existing table or column was altered, so
no historical row is rewritten.

### `budgets`

| Column | Type / notes |
|--------|--------------|
| `id` | primary key |
| `company_id` | FK → `companies`, `ON DELETE CASCADE` |
| `financial_year_id` | FK → `financial_years`, `ON DELETE RESTRICT` |
| `code` | string(50) |
| `name` | string(150) |
| `version_number` | unsigned integer, default 1 |
| `parent_budget_id` | nullable FK → `budgets`, `ON DELETE SET NULL` |
| `status` | string(20), default `DRAFT` |
| `notes` | nullable text |
| `created_by`, `updated_by`, `approved_by` | nullable FK → `users`, `ON DELETE RESTRICT` |
| `approved_at` | nullable timestamp |
| `created_at`, `updated_at` | timestamps |

Constraints: `UNIQUE (company_id, code, version_number)`;
`INDEX (company_id, financial_year_id)`; `INDEX (company_id, status)`;
`CHECK status IN ('DRAFT','APPROVED')`; `CHECK version_number >= 1`.

### `budget_lines`

| Column | Type |
|--------|------|
| `id` | bigint |
| `budget_id` | FK → `budgets`, `ON DELETE CASCADE` |
| `account_id` | FK → `accounts`, `ON DELETE RESTRICT` |
| `accounting_period_id` | FK → `accounting_periods`, `ON DELETE RESTRICT` |
| `amount` | `DECIMAL(20,4)`, default 0 |
| `description` | nullable string(500) |
| `created_at`, `updated_at` | timestamps |

Constraints: `UNIQUE (budget_id, account_id, accounting_period_id)` (named
`budget_lines_natural_key_unique`); `INDEX (account_id)`;
`INDEX (accounting_period_id)`; `CHECK amount >= 0`.

CHECK constraints are added through the project's `SchemaCheck::add()` helper,
which is a no-op on database servers that do not enforce CHECK constraints, so
the migrations remain portable.

**Why a version is a row and not a second table.** The brief sketches
`Budget → BudgetVersion → BudgetLine`. A version has no life of its own: same
company, same code, same year, distinguished only by its place in the chain. A
separate table would hold a foreign key and an integer and force a join on every
listing. Storing `version_number` on `budgets` with a `parent_budget_id` pointer
makes "the same budget, revised" a filter on a unique index, and makes a new
version a new row rather than a new hierarchy level.

---

## 5b. Money and Precision

`amount` is `DECIMAL(20,4)` — the one monetary precision in the system — and all
arithmetic passes through `App\Support\Money`. No budget value is ever a PHP
float; the resource emits canonical 4-decimal strings, and the variance report
subtracts `Money` instances. This is what makes a plan exactly subtractable from
the ledger.

---

## 6. Models / Enums

### New

- **`BudgetStatus`** (`DRAFT`, `APPROVED`) with `isDraft()` / `isApproved()` /
  `values()`. Two states only; see §11 for why no further state was added.
- **`Budget`** — fillable `code`, `name`, `notes` only; relationships
  `company`, `financialYear`, `lines`, `parent`, `revisions`, `creator`,
  `updater`, `approver`; `status` cast to `BudgetStatus`.
- **`BudgetLine`** — fillable `account_id`, `accounting_period_id`, `amount`,
  `description`; relationships `budget`, `account`, `accountingPeriod`; a
  `amount(): Money` accessor. `budget_id` is server-owned and not fillable.

### Modified

- **`AuditAction`** — added `Approved = 'APPROVED'`. It was deliberately absent
  until now because there was no approval workflow; a budget approval is the
  first real one, so the vocabulary is extended rather than a free string used.

Company id, version, status, parent and the approval attribution are **not
fillable** on `Budget`; they are assigned by the service, so a client cannot
forge a company, slot itself into a version chain, or approve a budget through a
request body.

---

## 7. Services

### `BudgetService` (new)

- `create(Company, User, array)` — validates the financial year belongs to the
  company, creates a `DRAFT` version 1, audits `Created`. The
  `(company, code, version_number)` unique violation is translated into a
  validation error rather than surfaced as a 500.
- `update(Budget, Company, User, array)` — locks the row, asserts `DRAFT`,
  updates the descriptive fields, audits `Updated`.
- `delete(Budget, Company, User)` — locks, asserts `DRAFT`, audits `Deleted`,
  deletes (lines cascade).
- `approve(Budget, Company, User)` — locks, asserts `DRAFT`, refuses an empty
  budget (a plan with no lines records a decision about nothing and its variance
  report cannot distinguish "planned zero" from "not filled in"), sets
  `APPROVED` with `approved_by`/`approved_at`, audits `Approved`.
- `revise(Budget, Company, User, array)` — locks, requires the source to be
  `APPROVED`, computes the next version number, creates a new `DRAFT` with
  `parent_budget_id` pointing at the source, **copies the lines forward**, and
  audits `Created`. The approved source is never modified.

### `BudgetLineService` (new)

Enforces the three rules the database cannot express: the account belongs to the
budget's company, the account is `Revenue` or `Expense` **and active**, and the
accounting period belongs to the budget's own financial year. Also refuses a
duplicate `(account, period)` line for the budget, and refuses any write to a
non-draft budget. The unique index remains the real concurrency guard; the
insert is wrapped so a racing request receives the same validation message.

### `BudgetVarianceReportService` (new, extends `JournalReportService`)

`generate(Budget, Company)` loads the lines and, **once per accounting period**,
calls the inherited `totalsByAccount()` (i.e. `LedgerService`), then for each
line computes:

- `budget` — the stored magnitude on the account's normal side;
- `actual` — `signedForType(debit, credit, account_type)` for that account in
  that period, from posted lines only;
- `variance = actual − budget`;
- `variance_percentage` (null when the plan is zero — see §9);
- `status` — `FAVOURABLE` / `UNFAVOURABLE` / `ON_TARGET`.

It returns the budget header, the reporting period (the budget's financial
year), the base-currency disclosure block, per-line rows, and a section summary
(revenue / expenses / net) with favourable/unfavourable/on-target counts. No
actual is stored; every figure is derived at request time.

---

## 8. APIs / Routes

All under `auth:api`,`auth.fresh`,`company.context`,`throttle:api`:

```
GET    /api/accounting/budgets                     list (filter by year, status, search)
POST   /api/accounting/budgets                     create a draft (version 1)
GET    /api/accounting/budgets/{budget}            show (with lines + year)
PUT    /api/accounting/budgets/{budget}            edit a draft
DELETE /api/accounting/budgets/{budget}            delete a draft
POST   /api/accounting/budgets/{budget}/approve    finalize a draft
POST   /api/accounting/budgets/{budget}/revise     new draft version from an approved budget
GET    /api/accounting/budgets/{budget}/variance   budget-versus-actual report
POST   /api/accounting/budgets/{budget}/lines      add a line
PUT    /api/accounting/budgets/{budget}/lines/{line}   edit a line
DELETE /api/accounting/budgets/{budget}/lines/{line}   delete a line
```

Approval and revision are POST acts, never `PUT`; there is no route that accepts
a status, version number, parent id or approved-by. Lines are addressed under
their budget and resolved through `$budget->lines()`, so a line id from another
budget or company 404s.

---

## 9. Authorization

Five new permissions, following the existing document shape:

| Permission | Accountant | Manager | Admin | Staff |
|------------|:---:|:---:|:---:|:---:|
| `accounting.budgets.view` | ✅ | ✅ | ✅ | — |
| `accounting.budgets.create` | ✅ | — | ✅ | — |
| `accounting.budgets.update` | ✅ | — | ✅ | — |
| `accounting.budgets.delete` | ✅ | — | ✅ | — |
| `accounting.budgets.approve` | — | ✅ | ✅ | — |

- The **Accountant** prepares and maintains plans but cannot approve them.
- The **Manager** may read and approve plans but cannot create, edit or delete
  them, so any budget the Manager approves was necessarily prepared by someone
  else. This is the same control split the existing `bank_reconciliation.complete`
  grant uses and mirrors the Admin-only period close.
- **Admin** holds all (wildcard); **Staff** holds none.

`BudgetPolicy` maps `viewAny`/`view` → view, `create` → create, `update` →
update, `delete` → delete, `approve` → approve, and `revise` → create (a revision
is the creation of a new version). Line endpoints authorize `update` against the
parent budget. The policy is registered explicitly in `AppServiceProvider`; no
membership re-check is done because the company-scoped route binding already
guarantees ownership.

---

## 10. Company Isolation

- `Route::bind('budget', ...)` in `AppServiceProvider` resolves a `Budget` scoped
  to the active company; a foreign id 404s before the controller, policy or
  resource runs.
- Budget **lines** are deliberately not globally bound (they have no
  `company_id`); they are resolved through their budget.
- `BudgetService`, `BudgetLineService` and `BudgetFilterRequest` re-assert the
  company on every path, so a caller without a route binding in front of it
  (a future job or command) cannot cross tenants.
- The variance report derives actuals with `LedgerService`, which is already
  company-scoped.
- Verified by tests: a foreign budget 404s; a foreign account/period is rejected;
  a line belonging to another budget 404s; another company's posted activity
  never enters the variance.

---

## 11. Budget Lifecycle Rules

1. A new budget is always `DRAFT`, version 1, in the caller's active company.
2. Only a draft may be edited, deleted or have its lines changed.
3. Approval requires at least one line; on success the budget becomes
   `APPROVED`, records `approved_by`/`approved_at`, and is thereafter immutable.
4. A change to an approved budget is made by **revising** it: a new `DRAFT`
   version is created with the same code, the next version number, a parent
   pointer to the approved row, and copied lines. The approved row is untouched.
5. Only an approved budget may be revised (a draft is edited directly).

The states are exactly two. `SUPERSEDED`, `LOCKED` and `ARCHIVED` were rejected:
a version becomes historical by being superseded *by a newer row*, not by moving
to a state of its own, and the brief forbids inventing lifecycle states without a
demonstrated need.

---

## 12. Budget Line Rules

- The account must exist in the active company, be `is_active`, and be a
  **Revenue** or **Expense** account.
- The accounting period must exist in the active company and belong to the
  budget's own financial year.
- `(budget, account, period)` is unique; a duplicate is refused.
- `amount` is non-negative (`CHECK amount >= 0`): a plan is a magnitude on the
  account's normal side, and "spend less" is a smaller positive number.

**Balance-sheet budgeting was deliberately not implemented.** Budgeting an Asset,
Liability or Equity account produces a comparison against a *cumulative balance*,
which is not comparable to a period plan without modelling opening balances and
cash flow; that is a different subsystem, explicitly out of scope. The restriction
is enforced in `BudgetLineService` and documented here.

---

## 13. Variance Rules

- **Actual = posted movement, period-scoped.** For each line, the actual is the
  account's posted activity for the line's accounting period
  (`journal_date` inclusive of the period start and end), signed on the account
  type's normal side. Draft journals, other companies and dates outside the
  period do not contribute.
- **Variance = actual − budget**, both on the account type's normal side.
- **Favourability is direction-aware.** A positive variance (actual above plan)
  is `FAVOURABLE` for a credit-normal type (revenue) and `UNFAVOURABLE` for a
  debit-normal type (expense). A zero variance is `ON_TARGET`.
- **`variance_percentage`** is `variance / budget × 100`, or `null` when the
  budget is zero — a percentage of zero is undefined, and reporting it as 0 would
  hide a real overrun against a zero plan.
- Section totals (revenue, expenses, net) are only aggregated within a section;
  the net figures use revenue-minus-expenses, consistent with the P&L.

---

## 14. Audit

Every lifecycle act writes through `AuditService`:

| Act | Action | Subject |
|-----|--------|---------|
| Create a budget / a revision | `Created` | `Budget` |
| Edit a draft | `Updated` | `Budget` |
| Delete a draft | `Deleted` | `Budget` |
| Approve | `Approved` | `Budget` |

The actor is the authenticated user; the company is the subject's own company;
the approval records the `DRAFT → APPROVED` transition. No request body can name
the actor or the company.

---

## 15. Concurrency

- `update`, `delete`, `approve` and `revise` re-read the budget with
  `lockForUpdate` before deciding, so two concurrent approvals, or an approval
  racing an edit, resolve to exactly one outcome.
- `BudgetLineService` locks the budget on every line write, so a line cannot be
  added to a budget that is being approved in the same instant.
- The natural-key unique indexes (`budgets` version key, `budget_lines` account/
  period key) are the database-level guards; the services translate their
  violations into the same validation messages the pre-checks produce, so a racing
  request gets a clear 422 rather than a 500.

---

## 16. APIs — Request Validation

`ValidatesBudgetInputs` builds on the existing `ValidatesTransactionAmounts`
trait, so a planned amount is subject to the same decimal-input rule (never a
PHP float, rounding tolerance from config) as every other money value. It adds
company-scoped `financial_year_id`, `account_id` and `accounting_period_id`
rules. Rule sets are `required` on create and `sometimes` on update, so an
omitted field on a partial update is left alone rather than treated as blank.

---

## 17. Tests

Three new feature files under `tests/Feature/Accounting/Budgets/`:

- **`BudgetLifecycleTest`** — create/edit/delete drafts; approve; empty-budget
  refusal; approved immutability; revise copies lines into a new version;
  draft-cannot-be-revised; accountant cannot approve; manager can approve but not
  create; cross-company 404; staff forbidden.
- **`BudgetLineTest`** — add a line; duplicate refused; balance-sheet account
  refused; inactive account refused; period outside the budget's year refused;
  foreign account refused; zero allowed, negative refused; approved lines frozen;
  another budget's line id 404s.
- **`BudgetVarianceReportTest`** — correct variance and favourability for revenue
  and expense; expense under plan favourable; draft journal ignored; other
  company ignored; zero budget → null percentage and `ON_TARGET`; staff forbidden.

All tests go through the real HTTP endpoints rather than calling services, so the
route, request, policy and binding are exercised, not just the service.

---

## 18. Full Regression

| Metric | Baseline (Phase 15) | Final (Phase 16) |
|--------|--------------------:|-----------------:|
| Tests | 1,180 | **1,208** |
| Assertions | 5,250 | **5,358** |
| Failures | 0 | **0** |
| Errors | 0 | **0** |
| Skipped | 0 | **0** |

- New Phase 16 tests: **28 tests** (three new files).
- Full command: `php artisan test` → `1208 passed / 5358 assertions`.
- Pint: `vendor/bin/pint` → passed on all new and changed files.

---

## 19. Database / Migration

- Two migrations added, both purely additive:
  `2026_10_10_100000_create_budgets_table`,
  `2026_10_10_100100_create_budget_lines_table`.
- `php artisan migrate` ran both on MySQL (including the CHECK constraints);
  `php artisan migrate:status` reports **0 pending**.
- No existing table, column, index or constraint was altered, so the migration
  cannot corrupt or rewrite existing accounting data.

---

## 20. Deferred Features (with justification)

- **Balance-sheet budgeting** — needs opening balances and cash flow to be
  meaningful; out of scope (§12).
- **Multi-currency budgets** — budgets are base-currency only; a foreign plan
  would require a rate policy and would reintroduce the FX complications Phase 14
  exists to control. Out of scope.
- **Forecasting / scenario analysis / rolling budgets** — projection, not
  budgeting-against-actuals.
- **Budget templates, approvals workflow beyond one step, notifications.**
- **Additional lifecycle states** (`LOCKED`, `SUPERSEDED`, `ARCHIVED`) — no
  demonstrated need; versioning supersedes via new rows.

---

## 21. Files Changed

Added:

- `app/Enums/BudgetStatus.php`
- `app/Models/Budget.php`, `app/Models/BudgetLine.php`
- `app/Policies/BudgetPolicy.php`
- `app/Http/Controllers/Api/Accounting/BudgetController.php`
- `app/Http/Resources/BudgetResource.php`, `app/Http/Resources/BudgetLineResource.php`
- `app/Http/Requests/Accounting/Budgets/` — `ValidatesBudgetInputs`,
  `StoreBudgetRequest`, `UpdateBudgetRequest`, `BudgetFilterRequest`,
  `ReviseBudgetRequest`, `StoreBudgetLineRequest`, `UpdateBudgetLineRequest`
- `app/Services/Accounting/Budgets/` — `BudgetService`, `BudgetLineService`,
  `BudgetVarianceReportService`
- `database/factories/BudgetFactory.php`, `database/factories/BudgetLineFactory.php`
- `database/migrations/2026_10_10_100000_create_budgets_table.php`,
  `database/migrations/2026_10_10_100100_create_budget_lines_table.php`
- `tests/Feature/Accounting/Budgets/` — `BudgetLifecycleTest`, `BudgetLineTest`,
  `BudgetVarianceReportTest`
- `docs/report/PHASE_16_REPORT.md`

Modified:

- `app/Enums/AuditAction.php` (added `Approved`)
- `app/Enums/PermissionName.php` (five budget permissions)
- `app/Providers/AppServiceProvider.php` (policy registration + `budget` binding)
- `config/authorization.php` (permissions + role grants)
- `routes/api.php` (budget routes)

No model, enum, migration or unrelated file outside the above was changed.

---

## 22. Final Status

**PASS WITH NOTES**

All acceptance criteria are met:

- Phases 1–15 functionality intact (full suite green; baseline preserved).
- The existing accounting engine remains the source of truth; budgets never post
  and no balance is stored.
- Budgeting is authorized, company scoped, audited, transactionally safe and
  concurrency safe; approved plans are immutable through every route.
- The variance report derives actuals from posted journals only, uses the same
  sign authority as the P&L, and reports favourability direction-aware.
- No duplicate accounting source of truth was created.
- Migrations are additive, ran cleanly and are 0 pending.
- Pint passes; the full suite passes with exact counts reported above.

The notes are deliberate scope boundaries: balance-sheet and multi-currency
budgeting, and additional lifecycle states, were omitted because their value
depends on subsystems (opening balances, cash flow, rate policy) that are outside
this phase and would otherwise create a second source of truth.