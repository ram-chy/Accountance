# PHASE 18 — BACKEND COMPLETION AUDIT, INTEGRATION VERIFICATION & PRODUCTION HARDENING

**Report date:** 2026-10-11
**Auditor role:** Senior Laravel architect / accounting-system engineer / application security reviewer / QA engineer
**Repository:** `D:\Development\Accountance` (backend at `accounting-app/backend`)
**Working tree state:** `main`, uncommitted. HEAD = `c57d561 Phase 16: Budgeting & Budget Variance Analysis`. Phase 17 implementation and the Phase 18 completion work are in the working tree, not yet committed.

---

## 1. Executive summary

The backend is complete for the documented scope (Phases 1–17) and is ready for
the planned Next.js frontend, with notes. The audit confirmed that the
accounting source of truth remains the posted journal entry, that posted
records are immutable, that company isolation and server-side authorization are
enforced by construction, and that money uses DECIMAL arithmetic throughout.

One genuine, in-scope gap was identified and fixed: **Phase 17 §7.7 dimension
filtering for the Profit & Loss and Budget Variance reports was not implemented.**
Per the brief ("If the functionality is absent and is part of the intended Phase
17 scope, implement the smallest compatible fix and add regression tests"), it
was implemented additively — the reports now accept `dimension_id` /
`dimension_value_id` filters, echo a `dimension_filter` block, and (for P&L)
return an `unassigned` reconciliation block. No existing response key was
changed and no accounting engine was modified. 21 new regression tests cover
journal-line and budget-line dimension assignment plus both filtered reports.

One medium API defect was discovered and **documented but not fixed**, because
fixing it requires a cross-cutting, backward-incompatible response-contract
change that the brief reserves for approval: paginated list endpoints drop the
paginator's `links` / `meta`, so clients receive only the current page's items
(see §12, finding P2-1).

All results below are actual command output from this audit.

| Check | Result |
|---|---|
| Full test suite | **1229 passed, 0 failed, 0 errors, 0 skipped — 5493 assertions** |
| Focused dimension regression suite | **21 passed / 127 assertions** |
| Pint (`vendor/bin/pint --test`) | **Passed** |
| Migrations | **57 migrations, all Ran, 0 pending** |
| API routes | **218** |
| **Final status** | **PASS WITH NOTES** |

---

## 2. Repository and architecture inspected

### 2.1 Runtime and dependencies (verified from the installed project)

- **PHP:** 8.4.12
- **Laravel Framework:** 13.34.0
- **Database:** MySQL 8.4 LTS, InnoDB, utf8mb4 (`mysql` / `accounting` local; tests use `accounting_test` via `phpunit.xml`)
- **API:** REST, single JWT guard
- **Auth:** `php-open-source-saver/jwt-auth` `^2.9` (HS256, `ttl` default 60 min, blacklist enabled)
- **Authorization:** `spatie/laravel-permission` `^8.3`, server-side policies + `config/authorization.php` matrix
- **Money:** DECIMAL columns; `bcmath`-based exact arithmetic in the money value object; no FLOAT/DOUBLE in financial paths

### 2.2 Layer inventory inspected

- **Models & relations & casts & scopes:** accounts, journals/lines, ledger, periods/financial years, customers/suppliers, invoices/bills, receipts/payments, credit/debit notes, cash/bank, bank reconciliations, taxes/rates, currencies/exchange rates, fixed assets/categories, budgets/lines, financial dimensions/values and the two junction tables.
- **Controllers / requests / resources / middleware / exceptions** under `app/Http/**`.
- **Services:** `JournalService`, `JournalPostingService`, `LedgerService`, reporting services, `AccountingControlService`, tax engine, FX services, cash/bank, reconciliation, fixed-asset, budgeting and dimension services.
- **Route model binding** scoped to the active company in `AppServiceProvider` (foreign-company ids 404 before controller code runs).
- **`routes/api.php`** (909 lines, fully read) — 218 routes.
- **`config/authorization.php`** (437 lines, fully read) — Admin / Accountant / Manager / Staff matrix.
- **`config/jwt.php`**, `.env` / `phpunit.xml` DB configuration.
- **Factories and the full test suite** under `tests/` and `database/factories/`.
- **Completed phase reports** `docs/report/PHASE_1_REPORT.md` … `PHASE_17_REPORT.md`.

### 2.3 Completed phase baseline (as reported by Phase 17)

1208 tests / 5366 assertions / 0 failures / 0 errors / 0 skipped / 0 pending
migrations / Pint passed. This was treated as a *reported* baseline and
re-verified rather than trusted (see §3).

### 2.4 Git status at audit start

48 working-tree entries: 23 modified tracked files, 25 untracked (dimension
models, migrations, requests, resources, services, policies, tests, factories,
the Phase 17 prompt and the real `docs/report/PHASE_17_REPORT.md`). One stray
artifact file was found at the repository root (see P3-1). No unrelated user
changes were reset, discarded or reformatted.

---

## 3. Baseline test and migration results

Commands were run from `accounting-app/backend` using the repository's own
tooling.

| Command | Result |
|---|---|
| `php artisan migrate:status` | 57 migrations, **all Ran**, 0 pending (batches 1–8) |
| `php artisan test` (full suite) | **1229 passed / 5493 assertions / 0 failed / 0 errors / 0 skipped** (~942 s) |
| `php artisan test --filter=Dimension` | **21 passed / 127 assertions** |
| `vendor/bin/pint --test` | **Passed** (project-wide) |
| `php artisan route:list` | 218 routes |

Delta vs. the Phase 17 reported baseline: **+21 tests / +127 assertions**, all
attributable to the dimension regression tests added by this phase. The
baseline was green before this phase's changes; no pre-existing failures had to
be distinguished from regressions.

No `migrate:fresh`, reset, destructive migration, seeder or destructive DB
command was run.

---

## 4. Audit scope and methodology

**Scope:** the entire backend as it exists in the repository, against the locked
architecture (§1 of the brief).

**Method:**

1. **Inspect first** — read source, migrations, routes, config, policies,
   factories and reports before editing anything (`git status` reviewed first).
2. **Re-run the authoritative checks** — full suite, focused suite, Pint,
   migration status — and record actual output (not the reported baseline).
3. **Trace each locked invariant from code to test** — double-entry, immutability,
   company isolation, DECIMAL money, single ledger source of truth, authorization.
4. **Target the brief's explicit suspicion** — §7.7 asked specifically whether
   Phase 17 completed P&L / Budget Variance dimension filtering. It was verified
   absent, then implemented with regression tests.
5. **Classify findings** P0–P3; fix only justified, minimal, evidence-backed
   defects; document the rest honestly.
6. **Re-verify** — focused + full suite, Pint, migration status, final diff review.

**Limitations of the method:** verification that happens only at runtime in a
real browser/network is out of scope; the backend is headless and no frontend
exists. Field-level security-advisory scanning was done against the installed
dependency set, not a live CVE feed. "Cannot be verified from the repository"
items are called out explicitly rather than assumed healthy.

---

## 5. Accounting integrity findings

**Result: verified, no defects.**

- **Double-entry / posting** — `JournalPostingService` validates balanced
  debits = credits, required account/company/period, and posts atomically inside
  a transaction; failed posting leaves no partial records. Covered by the
  accounting-integrity and concurrency tests in the suite.
- **Draft vs. posted** — draft entries cannot affect posted-ledger reports; the
  reports read posted lines only through `LedgerService`.
- **Immutability** — a posted journal cannot be edited or deleted through any
  ordinary route; the lifecycle uses separate service paths (`JournalService`
  for drafts, `JournalPostingService` for `/post`). The dimension tests re-assert
  this: posting is refused/immutable with a 422 and dimension labels are
  unchanged.
- **Lifecycle transitions** — only valid transitions accepted; the status is a
  lifecycle the client reads, never sets.
- **Corrections** — follow the existing reversal/replacement design; posted
  amounts are never mutated to repair history.
- **Precision** — financial columns are DECIMAL; arithmetic uses the existing
  exact (bcmath) money object; rounding is explicit and currency-aware. No
  monetary path depends on PHP floats.
- **Periods / year-end** — closed periods reject prohibited postings via
  `AccountingControlService`; no LOCKED state was introduced; no duplicate
  profit/retained-earnings effect; no stored balance introduced (all balances
  derive from posted journal lines).

No second ledger, posting engine, balance store or monetary scheme exists.

---

## 6. Cross-module integration findings

**Result: verified, no defects; one in-scope gap completed (dimensions).**

- **§7.1 Sales / purchases / notes** — documents use correct company and
  counterparty relations; posting reuses the journal + tax integration; notes
  cannot exceed adjustable limits (`/adjustable-lines`); source documents remain
  immutable; settlements use authoritative posted amounts.
- **§7.2 Tax Engine** — effective-dated rates and per-document tax snapshots are
  consistent; calculations never mutate historical posted amounts; account
  mappings and FX-consistent bases are respected; reports and postings read the
  same authoritative values. Country-specific filing/e-invoicing remain
  deferred.
- **§7.3 Multi-currency / FX** — company base currency respected; rates are
  dated and company-scoped; FX fields are provenance/calculation data, not a
  second ledger; posting revalidates FX per existing rules. Unrealized
  revaluation remains deferred.
- **§7.4 Cash / bank / reconciliation** — transactions use the posting engine;
  reconciliation is company/account/date scoped and agrees with ledger
  movements; completion/reopen follow the established lifecycle; no hidden
  balance store.
- **§7.5 Fixed assets** — capitalization, depreciation and disposal post through
  the journal engine; schedules reconcile to posted entries; closed-period,
  isolation and immutability rules hold.
- **§7.6 Budgeting** — budgets are plans, not ledger records; actuals derive
  from posted journals at request time; approved budgets are immutable through
  the API (PUT can only edit drafts); variance signs and direction-aware
  favourability follow the Phase 16 convention; zero budgets do not produce a
  misleading percentage.
- **§7.7 Financial dimensions & cost centers** — dimensions/values are
  company-scoped; parent/child and active-state are validated; journal-line
  associations persist; posted journal dimensions cannot be changed; inactive
  values cannot be assigned to new transactions; historical associations are
  retained; budget-line dimensions flow through create/update/revision.
  **The gap:** filtered **P&L** and **Budget Variance** reporting was absent.
  It was implemented (see §13).

---

## 7. Reporting and API findings

**Result: report families complete; one medium API defect documented.**

Every report listed in brief §8 is present as a read-only, posted-entry-derived
endpoint: Trial Balance, General Ledger, Profit & Loss, Balance Sheet, Customer
& Supplier statements, Receivables & Payables, Receivables/Payables aging,
Cash/Bank activity, bank reconciliation movements, tax summary/by-tax, fixed-asset
register & depreciation report, credit/debit note reporting, multi-currency
reporting (currencies/rates/FX settings + accounting control report), budget
variance, and dimension-aware P&L / variance (now implemented).

Verified for each: `accounting.reports.view` (or tax-specific) authorization,
company isolation via context + scoped binding, date/period filters, posted-entry
authority, and consistency with the ledger. Reports are not paginated (they are
bounded statements); index/collection endpoints use `paginate(...)`.

**API review:**

- **Validation** — FormRequests; server-owned fields (company id, status, journal
  number, computed totals, base amounts) are *absent from the rules entirely*, so
  they cannot be set.
- **Response consistency** — `{success, message, data}` on success and
  `{success, message, errors?}` on failure (`app/Support/ApiResponse.php`).
- **HTTP codes** — 200/201 success, 401 auth, 403 policy/permission, 404
  cross-company/not-found (404 rather than 403 to avoid id disclosure), 422
  validation, 503 health dependency failure.
- **Validation structure** — `422` with `errors` keyed by field; nested line
  errors under `lines.<n>....`.
- **Route-model binding** — scoped to the active company in `AppServiceProvider`;
  a foreign id 404s before controllers run.
- **Naming** — consistent, documented (e.g. lifecycle-as-separate-path; one
  resource for all four note types).

**Finding P2-1 (documented, not fixed): pagination metadata is dropped.** See §12.

---

## 8. Security and company-isolation findings

**Result: verified, no defects.**

- **Every company-owned resource is scoped** — binding in `AppServiceProvider`
  plus `company.context`, so a user cannot reach another company by editing an
  id; cross-company access returns 404.
- **No payload can override server-owned fields** — company id, status, journal
  number, base amounts and computed totals are not request inputs.
- **Policies + permissions enforced on every relevant route** — `authorize()`
  runs in FormRequests *and* policy checks in controllers; a hidden UI control is
  never the only protection.
- **Auth design** — indistinguishable login failures (no account enumeration);
  `auth.fresh` re-checks `is_active` and the token password-version claim on every
  request, so deactivation/password change takes effect immediately.
- **Secrets** — `UserResource` lists fields explicitly; tokens/credentials are
  not logged; `HealthController` exposes no paths, env or infrastructure.
- **Mass assignment / IDOR** — guarded by explicit `$fillable`/rules and scoped
  binding; covered by existing security/isolation tests.
- **Concurrency** — numbering/posting are transaction-safe; the suite includes
  concurrency coverage.

**Authoritative permission matrix (from `config/authorization.php`, verified):**

- **Admin** — `*` (all permissions).
- **Accountant** — full CRUD on accounts, journals, customers/suppliers,
  invoices/bills, receipts/payments, notes, cash/bank, reconciliations, fixed
  assets; **budgets view/create/update/delete but NOT approve**; **dimensions
  view/create/update/delete**; **no** currency create/update/activate (global
  reference data); reports/ledger/periods/tax view; controls view.
- **Manager** — read-only across modules (view on accounts, journals, ledger,
  periods, customers/suppliers, invoices/bills, receipts/payments, reports,
  cash/bank, bank reconciliation, tax, fixed assets, currencies, rates,
  controls, **dimensions view only**), plus `bank_reconciliation.create/update/complete`,
  **`budgets.view` + `budgets.approve`**, and `companies.update` /
  `companies.settings.*`. It holds no `.create/.post/.update` on any
  transactional document.
- **Staff** — `companies.view` only.

The implemented matrix matches the repository's authoritative configuration;
no authorization was weakened.

---

## 9. Audit and controls findings

**Result: verified, no defects.**

- Financially significant actions (posting, closing/reopening periods,
  base-currency change, tax deactivation, budget approval, FX setting changes,
  dimension lifecycle) use the existing audit infrastructure.
- `AccountingControlService` + `GET /api/accounting/controls` detect supported
  integrity problems **without mutating** financial data (read-only).
- Audit/control endpoints require their existing permissions
  (`accounting.controls.view`).
- No second audit or control subsystem exists.
- **Note:** there is no public "read audit logs" API route. Audit records are
  written internally; exposing them to clients was not part of the documented
  scope. Documented as a limitation (§15), not a defect.

---

## 10. Performance and reliability findings

**Result: acceptable for the documented scope; no changes made.**

- Index endpoints use `paginate()` with bounded `per_page`; hot reads
  (`AccountController::index`) pre-compute `has_journal_history` via
  `withExists` instead of per-row queries.
- Report queries filter on indexed company/date/account columns and derive from
  posted lines only.
- The new dimension filter joins `journal_line_dimensions` / uses in-memory
  `matches()` on already-loaded budget lines; junction tables carry indexes on
  their foreign keys (verified in the migration).
- Numbering/posting/approval/settlement/reconciliation run in transactions
  (no partial records).
- No speculative caching, queues or new indexes were introduced. No demonstrated
  N+1 or unbounded load was found in the audited paths.

---

## 11. Production configuration findings

**Result: configuration is production-shaped; notes below.**

- **Env/config** — `.env` + `.env.example` drives DB, JWT (`JWT_TTL`,
  `JWT_ALGO`, blacklist on), mail and app key. No secrets are committed.
- **Debug** — production must set `APP_DEBUG=false` and `APP_ENV=production`
  (recorded as an operational checklist item; not changeable from the repo).
- **JWT** — HS256, 60-minute TTL, blacklist enabled, `refresh_ttl` 2 weeks.
- **Logging** — standard channel; no tokens/passwords written by application code.
- **Queues** — no queue required for any implemented workflow.
- **Health** — `GET /api/health` returns `{status, database}` and 503 if the DB is
  unreachable; exposes nothing sensitive.
- **Migration safety** — migrations are additive and non-destructive; none were
  run destructively.
- **Dependency advisories** — `composer.lock` is within the project's declared
  ranges (`php ^8.4`, `laravel/framework ^13.17`, jwt-auth `^2.9`,
  spatie/laravel-permission `^8.3`). No incompatible constraint was found. A live
  advisory feed was not available in the audit environment; this is noted as a
  residual check rather than a claim.
- Deployment-specific readiness (the actual host) was **not** tested and is not
  claimed.

---

## 12. Issues discovered, prioritized by severity

- **P0 — Critical:** none.
- **P1 — High:** none.
- **P2 — Medium:**
  - **P2-1 — Paginated list endpoints drop pagination metadata.** List
    endpoints build `paginate(...)` then wrap the `ResourceCollection` inside
    `ApiResponse::success(data: ...)`. Because the collection is embedded in a
    plain array, it is serialized via `jsonSerialize()`, which returns only the
    items — the paginator's `links` and `meta` are **not** emitted. Verified
    empirically: `{"success":true,"message":"ok","data":[{"id":1},{"id":2}]}`.
    The affected controllers' own comments state the intent to expose `meta`, so
    this is unintended. **Not fixed** — the smallest fix changes the response
    envelope of many endpoints (e.g. `data` becoming `{data, links, meta}`),
    which is a backward-incompatible contract change reserved for approval
    (brief §13/§19). Recommended fix: return the resource collection as the
    top-level response, or adopt an `ApiResponse::paginated()` envelope — one
    deliberate, platform-wide decision.
- **P3 — Low / cleanup:**
  - **P3-1 — Stray artifact file at repo root.** A file named
    `D:DevelopmentAccountanceaccounting-appbackenddocsreportPHASE_17_REPORT.md`
    (a literal path mangled by a shell redirect) sits untracked at the
    repository root and duplicates the real `docs/report/PHASE_17_REPORT.md`.
    Not deleted without approval (it is an untracked artifact, not user source).
  - **P3-2 — Unused traits.** `app/Models/Traits/HasJournalLineDimensions.php`
    and `HasBudgetLineDimensions.php` exist but are not used by the models
    (relations are declared on `JournalLine` / `BudgetLine` directly). Harmless
    dead code; left in place to avoid unrelated churn.

No P0/P1 issues were found.

---

## 13. Fixes implemented and regression tests added

### 13.1 Implemented fix — Phase 17 §7.7 dimension filtering (P1-scope gap → closed)

- **Observed problem:** P&L and Budget Variance reports ignored the Phase 17
  dimension associations; there was no way to cut them by
  `dimension_id` / `dimension_value_id`, and no reconciliation of unfiltered
  totals to filtered + unassigned.
- **Root cause:** the Phase 17 model/junction/migrations were applied, but the
  report services and their requests were never wired to the dimensions
  (investigated per brief §7.7 rather than assumed present).
- **Smallest safe fix (additive, no engine change):**
  - New `DimensionAssignmentValidator` (shared write-path validation) and
    `DimensionFilter` (read-path value object) under
    `app/Services/Accounting/Dimensions/`.
  - New `ValidatesDimensionFilter` request trait; new `BudgetVarianceRequest`.
  - `ProfitLossRequest`, `StoreBudgetLineRequest`, `UpdateBudgetLineRequest`
    accept the `dimensions: [{dimension_id, value_id}]` shape;
    `ValidatesJournalLines` does the same for journal lines.
  - `JournalService`, `BudgetLineService` persist associations; `BudgetService::revise()`
    copies line dimensions to the new version.
  - `LedgerService` / `JournalReportService` / `ProfitLossReportService` /
    `BudgetVarianceReportService` accept an optional `DimensionFilter`.
  - P&L response gains `dimension_filter` (echo) and `unassigned`
    `{revenue, expenses, net}` (only when a filter is active), preserving
    `unfiltered = filtered + unassigned`.
  - Variance response gains a `dimension_filter` echo only when active.
  - `JournalLineResource` / `BudgetLineResource` expose `dimensions` via
    `whenLoaded`; controllers eager-load the junction relations.
- **Regression tests:** `tests/Feature/Accounting/Dimensions/` —
  `JournalLineDimensionAssignmentTest`, `BudgetLineDimensionAssignmentTest`,
  `ProfitLossDimensionFilterTest`, `BudgetVarianceDimensionFilterTest`
  (**21 tests / 127 assertions**), plus the `postJournalWithDimensions` helper
  in `tests/TestCase.php`.
- **Verification:** focused suite 21/21 green; full suite green; Pint clean;
  existing P&L and variance tests unchanged and still passing (backward
  compatible — no key removed or renamed).

### 13.2 No other production changes were made in this phase.

---

## 14. Issues not fixed and the reason for each

| Issue | Severity | Reason not fixed |
|---|---|---|
| P2-1 pagination metadata dropped | Medium | Requires a platform-wide, backward-incompatible response-envelope decision; reserved for approval by brief §13/§19. Fix documented and recommended. |
| P3-1 stray mangled artifact file | Low | Untracked artifact; deleting untracked files without approval is out of policy. Documented. |
| P3-2 unused dimension traits | Low | Cosmetic dead code; removing them is unrelated churn. Documented. |
| No public audit-log read API | Note | Not part of documented scope; exposing audit records would be a new feature. |
| Live dependency-advisory feed | Note | Not available in the audit environment; recorded as a residual operational check. |

---

## 15. Explicitly deferred features that remain out of scope

- Dimension filtering of **Trial Balance, General Ledger and Balance Sheet**
  (Phase 17 §24–26) — deliberately deferred; only P&L and Budget Variance were
  intended for §7.7. P&L is the dimension-aware statement in this scope.
- Unrealized FX revaluation and other explicitly deferred FX functionality.
- Country-specific tax filing, e-invoicing and other deferred compliance.
- Consolidation, comparative-period reporting and cash-flow statements.
- Public audit-log read endpoints.
- All frontend implementation (Next.js/React/TypeScript/Tailwind) — implemented
  separately after backend sign-off.

These are inherited deferrals, not defects.

---

## 16. Final test, assertion, failure, error, skipped-test, Pint, and migration results

| Metric | Actual result |
|---|---|
| Tests | **1229** |
| Assertions | **5493** |
| Failures | **0** |
| Errors | **0** |
| Skipped | **0** |
| Focused dimension suite | **21 passed / 127 assertions** |
| Pint (`vendor/bin/pint --test`) | **Passed** |
| Migrations | **57 total, all Ran, 0 pending** |
| Routes | **218** |

These are Phase 18 results. The Phase 17 reported figure (1208 / 5366) is
**not** repeated as the Phase 18 result; the delta is +21 tests / +127 assertions
from the dimension regression tests.

---

## 17. Files changed and why

Working tree relative to HEAD (`c57d561`). Phase 17 implementation and the
Phase 18 completion are both uncommitted.

**New — dimension domain (Phase 17):** `app/Enums/FinancialDimensionType.php`;
`app/Models/FinancialDimension.php`, `FinancialDimensionValue.php`,
`JournalLineDimension.php`, `BudgetLineDimension.php`, `app/Models/Traits/*`;
`app/Policies/FinancialDimensionPolicy.php`;
`app/Http/Controllers/Api/Accounting/FinancialDimensionController.php`;
`app/Http/Resources/FinancialDimensionResource.php`,
`FinancialDimensionValueResource.php`; `app/Http/Requests/Accounting/Dimensions/`;
`database/factories/FinancialDimensionFactory.php`,
`FinancialDimensionValueFactory.php`; four migrations
(`create_financial_dimensions_table`, `create_financial_dimension_values_table`,
`create_journal_line_dimensions_table`, `create_budget_line_dimensions_table`).

**New — audit completion (Phase 18):**
`app/Services/Accounting/Dimensions/DimensionAssignmentValidator.php`,
`app/Services/Accounting/Dimensions/DimensionFilter.php`,
`app/Http/Requests/Accounting/ValidatesDimensionFilter.php`,
`app/Http/Requests/Accounting/Budgets/BudgetVarianceRequest.php`;
`tests/Feature/Accounting/Dimensions/*` (4 files, 21 tests).

**Modified:** `app/Enums/PermissionName.php`;
`app/Providers/AppServiceProvider.php` (scoped binding + policy);
`app/Models/JournalLine.php`, `BudgetLine.php` (relations);
`app/Http/Controllers/Api/Accounting/{JournalController,BudgetController,ReportController}.php`;
`app/Http/Requests/Accounting/ValidatesJournalLines.php`,
`.../Reports/ProfitLossRequest.php`,
`.../Budgets/StoreBudgetLineRequest.php`, `.../UpdateBudgetLineRequest.php`;
`app/Http/Resources/JournalLineResource.php`, `BudgetLineResource.php`;
`app/Services/Accounting/{JournalService,LedgerService}.php`,
`.../Reports/JournalReportService.php`, `.../Reports/ProfitLossReportService.php`,
`.../Budgets/BudgetLineService.php`, `.../Budgets/BudgetService.php`,
`.../Budgets/BudgetVarianceReportService.php`;
`config/authorization.php` (dimension permissions);
`routes/api.php` (dimension routes);
`tests/TestCase.php` (`postJournalWithDimensions` helper).

**Docs:** new `docs/report/PHASE_18_REPORT.md`, new
`docs/FRONTEND_API_HANDOFF.md`, and the real `docs/report/PHASE_17_REPORT.md`.

---

## 18. Database changes, if any

Phase 18 introduced **no schema changes**. The four migrations present in the
working tree belong to Phase 17 (`financial_dimensions`,
`financial_dimension_values`, `journal_line_dimensions`,
`budget_line_dimensions`) and are all applied (`Ran`). They are additive.
Junction tables carry no `company_id` by design; company scoping is enforced
through the parent dimension/value and the owning journal/budget line.

---

## 19. Backward-compatibility assessment

- **Fully backward compatible.** The dimension filter is additive: P&L only adds
  `dimension_filter` and `unassigned` when a filter is supplied; variance adds
  `dimension_filter` only when a filter is supplied. No existing key was renamed
  or removed, and unfiltered output is unchanged (asserted by the "unfiltered
  unchanged" tests).
- Dimension write fields are optional; existing payloads without `dimensions`
  behave exactly as before.
- No route was renamed or removed; the 218-route surface gains only the
  dimension endpoints.
- The one known incompatibility is not introduced by this phase: the pagination
  metadata omission (P2-1) is a pre-existing property of the response envelope,
  documented for a future deliberate decision.

---

## 20. Frontend handoff readiness

**READY WITH NOTES.** The backend exposes a complete, authorized, company-scoped
REST surface sufficient to build the frontend: auth + company context, accounts,
journals/ledger, reports, sales/purchases/notes, cash/bank, reconciliation, tax,
fixed assets, budgets + variance, and dimensions. Full details — routes by
module, permissions, request/response examples, validation/error conventions,
report filters and date semantics, currency handling, and budget/dimension
filtering — are in **`docs/FRONTEND_API_HANDOFF.md`**.

Notes the frontend team must design around:

1. **Pagination** — list endpoints accept `per_page` but return only the current
   page's `data` array with **no** `links`/`meta` (P2-1). Until the contract
   decision is made, the frontend should treat `per_page` as a page size and
   detect end-of-data by a short page.
2. **Active company** — every company-scoped request must send the
   `X-Company-Id` header; switching updates the caller's default and echoes the
   header to use.
3. **Immutability** — drafts are editable; posted documents are not. Lifecycles
   are separate paths (`PUT` edits, `POST /post` posts).
4. **Company isolation** — foreign-company ids return 404 by design.

---

## 21. Final status

**PASS WITH NOTES.**

The existing accounting source of truth (posted journal entries) was preserved;
no duplicate engine, balance store or monetary scheme was introduced; posted
immutability holds; company isolation and server-side authorization are enforced
and tested; financial precision is intact; the sole in-scope gap (Phase 17 §7.7
dimension filtering for P&L and Budget Variance) was implemented with the
smallest compatible change and covered by 21 regression tests; the full suite,
Pint and migration checks are green.

Notes preventing an unqualified PASS: the medium API defect **P2-1**
(pagination metadata) is documented but not fixed pending an approved
response-envelope decision; and two low-priority cleanup items (P3-1, P3-2)
remain. None blocks frontend development.

**Recommendation:** proceed to frontend development against
`docs/FRONTEND_API_HANDOFF.md`, and schedule a single deliberate decision on the
pagination envelope before list-heavy screens are finalized.


# Frontend API Handoff

**Backend:** Laravel 13 / PHP 8.4 REST API (JWT)
**Audience:** the Next.js / React / TypeScript frontend team
**Source of truth:** the actual routes in `routes/api.php`, requests, resources and
`config/authorization.php` at the time of Phase 18. Derived from the
implementation, not from a specification.

> Phase 18 audit status: **PASS WITH NOTES**. See
> `docs/report/PHASE_18_REPORT.md`. The single material frontend-affecting note is
> the pagination behaviour in §7 (pagination metadata is not returned).

---

## 1. Base URL and conventions

- All routes are under the `/api` prefix, e.g. `GET /api/accounts`.
- Requests and responses are JSON. Send `Accept: application/json`.
- Dates are `YYYY-MM-DD`. Money is returned as **strings** with up to 4 decimal
  places (never JSON numbers) so no precision is lost in transit.
- Every response carries an `X-Request-Id` header (a UUID, or an echo of a valid
  `X-Request-Id` you send). Quote it in bug reports; it correlates to audit rows.

---

## 2. Authentication flow and token handling

Public endpoints (no token), under `throttle:auth`:

| Method | Path | Purpose | Rate limit |
|---|---|---|---|
| POST | `/api/auth/register` | Register a user | `throttle:register` |
| POST | `/api/auth/login` | Obtain a JWT | `throttle:login` |
| POST | `/api/auth/forgot-password` | Email a reset link | `throttle:forgot-password` |
| POST | `/api/auth/reset-password` | Reset with a token | `throttle:reset-password` |
| GET | `/api/auth/reset-password/{token}` | Check whether a reset token is still valid | `throttle:reset-password` |
| GET | `/api/auth/email/verify/{id}/{hash}` | Email verification link (signed, opened from mail) | `throttle:verify-email` |
| GET | `/api/health` | Health / DB availability | — |

Authenticated endpoints, under `auth:api` + `auth.fresh`:

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/auth/me` | Current user |
| POST | `/api/auth/logout` | Revoke the current token |
| PUT | `/api/auth/password` | Change password |
| POST | `/api/auth/email/verification-notification` | Resend verification email |

**Login request:**

```json
{ "email": "user@example.com", "password": "secret" }
```

**Login response (200):**

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token": "<jwt>",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": {
      "id": 1,
      "first_name": "Ada",
      "last_name": "Lovelace",
      "full_name": "Ada Lovelace",
      "email": "user@example.com",
      "mobile_no": null,
      "email_verified": true,
      "is_active": true,
      "roles": [ { "name": "Accountant" } ],
      "last_login_at": "2026-10-11T09:00:00+00:00",
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-10-11T09:00:00+00:00"
    }
  }
}
```

**Token handling rules:**

- Send `Authorization: Bearer <token>` on every authenticated request.
- `expires_in` is seconds (`jwt.ttl` default **3600 s**, signalled HS256). The
  token is invalid after expiry.
- There is **no refresh-token endpoint**. When a token expires the API returns
  `401` with `{"success":false,"message":"Your token has expired. Please sign in again."}`;
  send the user back to login.
- `auth.fresh` re-checks account status and password version on **every** request.
  Deactivating a user or changing a password invalidates existing tokens
  immediately (the next request returns 401). Design for a sudden 401 on any call.
- Failed logins are deliberately indistinguishable (unknown email, wrong password
  and deactivated account all return the same 401), so do not build UI that
  tries to explain *why* login failed.
- Any 401 should clear stored credentials and route to login.

---

## 3. User and company context selection

A user belongs to one or more companies (memberships). Most business endpoints
operate on an **active company** resolved from a header, not from the URL or body.

- Send `X-Company-Id: <companyId>` on every company-scoped request.
- If omitted, the backend falls back to the caller's default company; if the
  header names a company the caller is not a member of, or an inactive company,
  the request is rejected (never silently ignored).
- A foreign-company resource id in the path returns **404** (not 403), by design,
  to avoid confirming that another company's id exists.

Company and context routes:

| Method | Path | Purpose | Capability |
|---|---|---|---|
| GET | `/api/companies` | Caller's own companies | membership |
| POST | `/api/companies` | Create a company | `companies.create` |
| GET | `/api/companies/{company}` | Read one | membership + `companies.view` |
| PUT | `/api/companies/{company}` | Update | `companies.update` |
| POST | `/api/companies/{company}/activate` | Activate | `companies.update` |
| POST | `/api/companies/{company}/deactivate` | Deactivate (replaces delete) | `companies.update` |
| POST | `/api/companies/{company}/switch` | Set caller's default company | membership |
| POST | `/api/companies/{company}/members` | Add member | `companies.update` |
| DELETE | `/api/companies/{company}/members/{user}` | Remove member | `companies.update` |
| GET | `/api/company` | The active company | membership |
| GET | `/api/company/settings` | Read settings | `companies.settings.view` |
| PUT | `/api/company/settings` | Update settings | `companies.settings.update` |
| PUT | `/api/company/settings/base-currency` | Change base currency (audited; may be refused) | `companies.settings.update` |

**Recommended client flow:** after login, `GET /api/companies`; if exactly one,
call `POST /api/companies/{id}/switch` (or simply send that id as `X-Company-Id`).
Store the chosen id and attach it to every subsequent request. `POST .../switch`
returns the company and **echoes the `X-Company-Id` to use**:

```json
// response headers include: X-Company-Id: 7
{ "success": true, "message": "Active company switched successfully.", "data": { "...": "..." } }
```

---

## 4. Response and error conventions

Documented once in `app/Support/ApiResponse.php` and `bootstrap/app.php`.

**Success envelope:**

```json
{ "success": true, "message": "Accounts retrieved successfully.", "data": { } }
```

`data` is omitted when there is nothing to return (e.g. logout).

**Error envelope:**

```json
{ "success": false, "message": "The given data was invalid.", "errors": { "email": ["The email field is required."] } }
```

**Status codes:**

| Status | Meaning | Body |
|---|---|---|
| 200 / 201 | Success | success envelope |
| 401 | Unauthenticated / token expired, invalid or blacklisted | `{success:false, message}` |
| 403 | Authenticated but not permitted | `{success:false, message}` |
| 404 | Not found, **or cross-company id** | `{success:false, message:"Resource not found."}` |
| 405 | Wrong HTTP method | `{success:false, message}` |
| 422 | Validation failed | `{success:false, message, errors}` |
| 429 | Rate limited | `{success:false, message}` + `Retry-After` |
| 500 | Unexpected (production) | `{success:false, message:"An unexpected error occurred."}` |
| 503 | Health: DB unavailable | `{success:false, message, errors}` |

Validation errors are keyed by field; nested fields use dot/bracket-style keys,
e.g. `lines.1.dimensions.0.dimension_id` and `lines.1.quantity`.

---

## 5. Validation conventions (important for forms)

- **Server-owned fields are not inputs.** Company id, document status, journal
  number, invoice/bill numbers, computed totals, `journal_id`, `created_by`,
  `posted_by`, `posted_at` are **not present** in the request rules — sending them
  is ignored, not honoured. Never build a form that posts them.
- Money inputs must be **decimal strings** (e.g. `"1234.5600"`, or a number with
  at most `accounting.rounding.max_input_decimals` decimals). Values with extra
  decimals are rounded half-up rather than rejected; a value that rounds to zero
  on a required side is rejected.
- Journal/invoice line amounts are one-sided: a line has a debit **or** a credit,
  never both, never negative.
- Journal debits must equal credits; the server sums the lines itself and ignores
  any client total.
- Past dates (backdating) are allowed on journals and documents; whether a date is
  *postable* is decided by the accounting period at posting time.
- `exists`-style checks are company-scoped; an id from another company is reported
  as not belonging to the active company (same shape as "does not exist").

---

## 6. Request/response examples (from the implementation)

### 6.1 Create a draft journal — `POST /api/journals`

Capability: `journals.create`. Headers: `Authorization`, `X-Company-Id`.

```json
{
  "journal_date": "2026-10-11",
  "description": "Monthly accrual",
  "reference": "REF-001",
  "lines": [
    { "account_id": 10, "description": "Rent", "debit": "1000.0000", "credit": "0.0000",
      "dimensions": [ { "dimension_id": 3, "value_id": 21 } ] },
    { "account_id": 20, "description": "Payable", "debit": "0.0000", "credit": "1000.0000" }
  ]
}
```

Journal line dimensions (Phase 17) are optional. Each entry is
`{ "dimension_id": <int>, "value_id": <int> }`; at most one value per dimension
type per line. The response (via `JournalLineResource`) echoes
`"dimensions": [ { "dimension_id": 3, "value_id": 21 } ]`. A posted journal's
dimensions cannot be changed.

### 6.2 Create a draft sales invoice — `POST /api/sales-invoices`

Capability: `sales.invoices.create`.

```json
{
  "customer_id": 5,
  "invoice_date": "2026-10-11",
  "due_date": "2026-11-10",
  "notes": "Thanks",
  "tax_account_id": 33,
  "lines": [
    { "description": "Widget", "quantity": "2.0000", "unit_price": "50.0000",
      "discount": "0.0000", "tax_rate": "15.0000", "revenue_account_id": 40 }
  ]
}
```

Response money fields (`subtotal`, `discount_total`, `tax_total`, `grand_total`)
are strings. `paid_total` / `balance_due` / `is_overdue` / `days_overdue` are
present only when the controller attached settlement figures (list/show), and are
**absent** on create.

### 6.3 Post a document — `POST /api/{documents}/{id}/post`

Capability: the module's `.post` permission (`sales.invoices.post`, etc.).
**No request body.** Posting is irreversible; a posted document is immutable.

### 6.4 Tax calculation — `POST /api/accounting/tax/calculate`

Capability: `accounting.tax.calculate`. Writes nothing.

```json
{ "amount": "100.00", "date": "2026-10-11", "tax_ids": [1, 2], "basis": "EXCLUSIVE" }
```

`tax_ids` optional (empty = no tax); `basis` optional (`EXCLUSIVE` | `INCLUSIVE`).

### 6.5 Create a company dimension and value — `POST /api/accounting/dimensions`

Capability: `accounting.dimensions.create`.

```json
{ "type": "COST_CENTER", "code": "CC", "name": "Cost Center" }
```

Then `POST /api/accounting/dimensions/{dimension}/values`:

```json
{ "code": "CC-100", "name": "Engineering" }
```

`type` is a controlled vocabulary (`FinancialDimensionType`). Values are always
addressed under their dimension; there is no `/dimensions/values/{id}` route.

---

## 7. Pagination, sorting, and filtering

- **List endpoints** accept `per_page` (validated `1..100` where a filter request
  exists; some default to 50). Query parameters you already sent are preserved.
- **Pagination metadata is NOT returned (Phase 18 note P2-1).** The response is:

  ```json
  { "success": true, "message": "Accounts retrieved successfully.", "data": [ { "id": 1 }, { "id": 2 } ] }
  ```

  `data` is a flat array of the current page **only** — there are no `links`,
  `meta`, `total`, `last_page` or `current_page` keys. Until the backend response
  envelope is revised (a pending product decision), treat `per_page` as a page
  size and detect the last page by receiving fewer than `per_page` items. Do not
  build a page-number control that depends on `last_page`.
- **Sorting** is fixed per endpoint (e.g. accounts are ordered by `code`); there
  is no generic `sort` parameter.
- **Common filters** (exact names vary by endpoint; representative):
  - Accounts: `search`, `account_type`, `is_active`.
  - Dimensions: `type`, `is_active`, `search`, `per_page`.
  - Budgets: `financial_year_id`, `status`, `search`, `per_page`.
  - Cash/bank transactions: `transaction_type`, `status`, `account_id`, `from`,
    `to`, `reference`, `per_page`.
- `is_active` is three-state where offered: absent = all, `true` = active,
  `false` = inactive.

---

## 8. Report filters and date semantics

All Phase 6 reports use `accounting.reports.view`. They are read-only and derive
from **posted** journal entries only.

**Date window (inclusive, date-only):** `from_date` / `to_date`
(aliases `from` / `to` are accepted). `from_date` must be `<= to_date`.

**Point-in-time reports:** `as_of` (defaults to today) is used by
receivables/payables and aging, distinct from `to_date`.

**Company-scoped ids:** `account_id`, `customer_id`, `supplier_id` are validated
against the active company.

| Report | Path | Key params |
|---|---|---|
| Trial Balance | `GET /api/accounting/reports/trial-balance` | `from_date`, `to_date` |
| General Ledger | `GET /api/accounting/reports/general-ledger` | `account_id` (required), `from_date`, `to_date` |
| Profit & Loss | `GET /api/accounting/reports/profit-loss` | `from_date`, `to_date`, `dimension_id`, `dimension_value_id` |
| Balance Sheet | `GET /api/accounting/reports/balance-sheet` | `as_of` |
| Customer Statement | `GET /api/accounting/reports/customer-statement` | `customer_id` (required), `from_date`, `to_date` |
| Supplier Statement | `GET /api/accounting/reports/supplier-statement` | `supplier_id` (required), `from_date`, `to_date` |
| Receivables | `GET /api/accounting/reports/receivables` | `as_of` |
| Payables | `GET /api/accounting/reports/payables` | `as_of` |
| Receivables Aging | `GET /api/accounting/reports/receivables-aging` | `as_of` |
| Payables Aging | `GET /api/accounting/reports/payables-aging` | `as_of` |
| Cash/Bank activity | `GET /api/accounting/reports/cash-bank` | `from_date`, `to_date`, account filters |

`GET /api/accounting/trial-balance` and
`GET /api/accounting/accounts/{account}/balance` are the Phase 4 ledger reads
(`accounting.ledger.view`) and use `from` / `to` / `as_of`.

Tax reports (`accounting.tax.report.view`): `GET /api/accounting/tax-reports/summary`
and `GET /api/accounting/tax-reports/by-tax`.

---

## 9. Currency and base-currency presentation

- Each company has a **base currency** (multi-currency Phase 14).
- Documents and report totals are in the company **base currency**. Journal
  `debit`/`credit` are always base currency.
- Journal lines may carry foreign-currency *provenance*:
  `currency_id`, `currency_code`, `exchange_rate`, `foreign_debit`,
  `foreign_credit`. A `null` `currency_id` means the line is base-currency and the
  foreign fields are `null`.
- Multi-currency configuration routes:
  - `GET /api/accounting/currencies` and `POST/GET/PUT` + `activate`/`deactivate`
    (`accounting.currency.*`; creation is a system-level capability, not granted
    to the Accountant).
  - `GET /api/accounting/exchange-rates` and `POST/GET/PUT` + `activate`/`deactivate`
    (`accounting.exchange_rate.*`); rates are company-scoped and dated.
  - `GET` / `PUT /api/accounting/fx-settings` (`accounting.fx.update` for writes).
  - `GET /api/accounting/controls` (`accounting.controls.view`) — read-only FX /
    currency integrity report.
- Changing the company base currency is a separate, audited endpoint
  (`PUT /api/company/settings/base-currency`) and may be refused; it reinterprets
  every stored base amount.
- Present amounts as returned (strings, up to 4 dp). Do not reformat via floats.

---

## 10. Budget and dimension-filtering support

### Budgets (Phase 16)

| Method | Path | Capability |
|---|---|---|
| GET | `/api/accounting/budgets` | `accounting.budgets.view` |
| POST | `/api/accounting/budgets` | `accounting.budgets.create` |
| GET | `/api/accounting/budgets/{budget}` | `accounting.budgets.view` |
| PUT | `/api/accounting/budgets/{budget}` | `accounting.budgets.update` |
| DELETE | `/api/accounting/budgets/{budget}` | `accounting.budgets.delete` |
| POST | `/api/accounting/budgets/{budget}/approve` | `accounting.budgets.approve` |
| POST | `/api/accounting/budgets/{budget}/revise` | `accounting.budgets.create` |
| GET | `/api/accounting/budgets/{budget}/variance` | `accounting.budgets.view` |
| POST | `/api/accounting/budgets/{budget}/lines` | `accounting.budgets.update` |
| PUT | `/api/accounting/budgets/{budget}/lines/{line}` | `accounting.budgets.update` |
| DELETE | `/api/accounting/budgets/{budget}/lines/{line}` | `accounting.budgets.update` |

- A budget is created as a `DRAFT`; `status` is **not** a client field.
- `approve` finalizes and makes it immutable; a change is a new version via
  `revise` (which copies line dimensions to the new draft).
- Budget line create/update accepts optional `dimensions` in the same shape as
  journal lines. `BudgetLineResource` echoes
  `"dimensions": [ { "dimension_id": 3, "value_id": 21 } ]`.

### Budget variance — `GET /api/accounting/budgets/{budget}/variance`

Optional dimension filter: `dimension_id`, `dimension_value_id` (validated
together — `dimension_value_id` must belong to `dimension_id`). Response shape:

```json
{
  "budget": { "...": "..." },
  "period": { "...": "..." },
  "summary": {
    "revenue":  { "budget": "…", "actual": "…", "variance": "…" },
    "expenses": { "budget": "…", "actual": "…", "variance": "…" },
    "net":      { "budget": "…", "actual": "…", "variance": "…" },
    "counts":   { "favourable": 3, "...": "..." }
  },
  "lines": [ { "...": "..." } ],
  "dimension_filter": { "dimension_id": 3, "dimension_value_id": 21 }
}
```

`dimension_filter` is present **only** when a filter was supplied. Variance =
actual − budget; favourability is direction-aware by account normal side (revenue
over-plan is favourable, expense over-plan is unfavourable).

### Profit & Loss dimension filter — `GET /api/accounting/reports/profit-loss`

Accepts `dimension_id` and/or `dimension_value_id`. When active, the response adds:

```json
{
  "...": "existing P&L keys unchanged...",
  "dimension_filter": { "dimension_id": 3, "dimension_value_id": 21 },
  "unassigned": { "revenue": "…", "expenses": "…", "net": "…" }
}
```

Reconciliation contract: `unfiltered = filtered + unassigned`. `dimension_id`
alone means "all values of that dimension". Unfiltered responses are unchanged
(no new keys), so existing screens keep working.

### Dimensions (Phase 17)

| Method | Path | Capability |
|---|---|---|
| GET | `/api/accounting/dimensions` | `accounting.dimensions.view` |
| POST | `/api/accounting/dimensions` | `accounting.dimensions.create` |
| GET | `/api/accounting/dimensions/{dimension}` | `accounting.dimensions.view` |
| PUT | `/api/accounting/dimensions/{dimension}` | `accounting.dimensions.update` |
| DELETE | `/api/accounting/dimensions/{dimension}` | `accounting.dimensions.delete` |
| POST | `/api/accounting/dimensions/{dimension}/activate` | `accounting.dimensions.update` |
| POST | `/api/accounting/dimensions/{dimension}/deactivate` | `accounting.dimensions.update` |
| GET | `/api/accounting/dimensions/{dimension}/values` | `accounting.dimensions.view` |
| POST | `/api/accounting/dimensions/{dimension}/values` | `accounting.dimensions.create` |
| GET | `/api/accounting/dimensions/{dimension}/values/{value}` | `accounting.dimensions.view` |
| PUT | `/api/accounting/dimensions/{dimension}/values/{value}` | `accounting.dimensions.update` |
| DELETE | `/api/accounting/dimensions/{dimension}/values/{value}` | `accounting.dimensions.delete` |
| POST | `/api/accounting/dimensions/{dimension}/values/{value}/activate` | `accounting.dimensions.update` |
| POST | `/api/accounting/dimensions/{dimension}/values/{value}/deactivate` | `accounting.dimensions.update` |

---

## 11. Route inventory by module

Capabilities are the values in `app/Enums/PermissionName.php`. "Membership" means
company membership via `CompanyPolicy`. All routes except the public auth/health
group require `Authorization: Bearer`; all company-scoped routes require
`X-Company-Id`.

### Users — `users.*`

| Method | Path | Capability |
|---|---|---|
| GET | `/api/users` | `users.view` |
| POST | `/api/users` | `users.create` |
| GET | `/api/users/{user}` | `users.view` |
| PUT | `/api/users/{user}` | `users.update` |
| DELETE | `/api/users/{user}` | `users.delete` |

### Chart of accounts — `accounts.*`

| Method | Path | Capability |
|---|---|---|
| GET | `/api/accounts/types` | `accounts.view` |
| GET | `/api/accounts` | `accounts.view` |
| POST | `/api/accounts` | `accounts.create` |
| GET | `/api/accounts/{account}` | `accounts.view` |
| PUT | `/api/accounts/{account}` | `accounts.update` |
| DELETE | `/api/accounts/{account}` | `accounts.delete` |
| POST | `/api/accounts/{account}/activate` | `accounts.activate` |
| POST | `/api/accounts/{account}/deactivate` | `accounts.deactivate` |

### Periods & financial years

| Method | Path | Capability |
|---|---|---|
| GET/POST | `/api/accounting/periods` | `accounting.periods.view` / `.create` |
| GET/PUT | `/api/accounting/periods/{period}` | `accounting.periods.view` / `.update` |
| GET | `/api/accounting/periods/{period}/closing-check` | `accounting.periods.view` |
| POST | `/api/accounting/periods/{period}/close` | `accounting.periods.close` |
| POST | `/api/accounting/periods/{period}/reopen` | `accounting.periods.reopen` |
| GET/POST | `/api/accounting/financial-years` | `accounting.periods.view` / `.create` |
| GET/PUT | `/api/accounting/financial-years/{financialYear}` | `accounting.periods.view` / `.update` |
| POST | `/api/accounting/financial-years/{financialYear}/periods/generate` | `accounting.periods.create` |
| POST | `/api/accounting/financial-years/{financialYear}/close` | `accounting.periods.close` |

### Journals & ledger

| Method | Path | Capability |
|---|---|---|
| GET | `/api/journals` | `journals.view` |
| POST | `/api/journals` | `journals.create` |
| GET | `/api/journals/{journal}` | `journals.view` |
| PUT | `/api/journals/{journal}` | `journals.update` |
| DELETE | `/api/journals/{journal}` | `journals.delete` |
| POST | `/api/journals/{journal}/post` | `journals.post` |
| GET | `/api/accounting/trial-balance` | `accounting.ledger.view` |
| GET | `/api/accounting/accounts/{account}/balance` | `accounting.ledger.view` |

### Reports — `accounting.reports.view`

All `GET` under `/api/accounting/reports/*`: `trial-balance`, `general-ledger`,
`profit-loss`, `balance-sheet`, `customer-statement`, `supplier-statement`,
`receivables`, `payables`, `receivables-aging`, `payables-aging`, `cash-bank`.

### Customers & suppliers

| Method | Path | Capability |
|---|---|---|
| GET/POST | `/api/customers` | `customers.view` / `.create` |
| GET/PUT | `/api/customers/{customer}` | `customers.view` / `.update` |
| POST | `/api/customers/{customer}/deactivate` | `customers.deactivate` |
| GET/POST | `/api/suppliers` | `suppliers.view` / `.create` |
| GET/PUT | `/api/suppliers/{supplier}` | `suppliers.view` / `.update` |
| POST | `/api/suppliers/{supplier}/deactivate` | `suppliers.deactivate` |

(No customer/supplier DELETE — lifecycle ends at deactivation.)

### Sales invoices / purchase bills

Pattern for both `sales-invoices` and `purchase-bills`:
`GET`/`POST` (`.view`/`.create`), `GET`/`PUT`/`DELETE` by id
(`.view`/`.update`/`.delete`), `POST /{id}/post` (`.post`), and
`GET /{id}/adjustable-lines` (`.view`) which reports how much is still adjustable.

Capabilities: `sales.invoices.*` and `purchases.bills.*` (view/create/update/post/delete).

### Credit & debit notes — `credit_debit_notes.*`

`GET`/`POST` `/api/credit-debit-notes`; `GET`/`PUT`/`DELETE` `/{creditDebitNote}`;
`POST /{creditDebitNote}/post`. One resource for all four adjustment types;
filter by `note_type` on index. Capabilities:
`accounting.credit_debit_note.view/create/update/delete/post`.

### Customer receipts / supplier payments

Same shape for `customer-receipts` and `supplier-payments`: `GET`/`POST`,
`GET`/`PUT`/`DELETE` by id, `POST /{id}/post`. Capabilities
`customer.receipts.*` and `supplier.payments.*`.

### Cash & banking

| Method | Path | Capability |
|---|---|---|
| GET | `/api/cash-bank-accounts` | `accounting.cash_bank.view` |
| PUT | `/api/cash-bank-accounts/{account}` | `accounting.cash_bank.update` |
| DELETE | `/api/cash-bank-accounts/{account}/bank-details` | `accounting.cash_bank.update` |
| POST | `/api/cash-bank-accounts/{account}/activate` | `accounting.cash_bank.update` |
| POST | `/api/cash-bank-accounts/{account}/deactivate` | `accounting.cash_bank.update` |
| GET | `/api/cash-bank-transactions` | `accounting.cash_bank.view` |
| POST | `/api/cash-bank-transactions/deposits` | `accounting.cash_bank.create` |
| POST | `/api/cash-bank-transactions/withdrawals` | `accounting.cash_bank.create` |
| POST | `/api/cash-bank-transactions/transfers` | `accounting.cash_bank.create` |
| GET/PUT/DELETE | `/api/cash-bank-transactions/{transaction}` | `...view`/`.update`/`.delete` |
| POST | `/api/cash-bank-transactions/{transaction}/post` | `accounting.cash_bank.post` |

A cash/bank account is an account plus a classification; the account itself is
created via `/api/accounts`.

### Bank reconciliation — `accounting.bank_reconciliation.*`

`GET`/`POST` `/api/bank-reconciliations`; `GET`/`PUT`/`DELETE` `/{reconciliation}`;
`GET /{reconciliation}/movements`; `POST /{reconciliation}/items`;
`DELETE /{reconciliation}/items/{item}`; `POST /{reconciliation}/complete`;
`POST /{reconciliation}/reopen`.

### Tax — `accounting.tax.*` and `accounting.tax.report.view`

`/api/accounting/taxes` (view/create/update/delete, `activate`/`deactivate`),
`/{tax}/rates` (view/create/update/delete + activate/deactivate),
`/{tax}/account-mapping` (view/update), `POST /api/accounting/tax/calculate`
(`accounting.tax.calculate`), and `GET /api/accounting/tax-reports/summary|by-tax`
(`accounting.tax.report.view`).

### Fixed assets — `accounting.fixed_asset.*`

`/api/accounting/fixed-asset-categories` (view/create/update/delete,
activate/deactivate); `/api/accounting/fixed-assets`:
`GET /register` and `GET /depreciation-report` (static, before `/{id}`),
`GET`/`POST /cash`/`POST /supplier-credit`, `GET`/`PUT`/`DELETE` `/{fixedAsset}`,
`POST /{fixedAsset}/capitalize`, `GET /{fixedAsset}/depreciation-schedule`,
`POST /{fixedAsset}/depreciate`, `POST /{fixedAsset}/dispose`.

### Multi-currency — `accounting.currency.*`, `accounting.exchange_rate.*`

See §9.

### Budgets & dimensions

See §10.

---

## 12. Role summary (authoritative matrix)

From `config/authorization.php`:

- **Admin** — `*` (everything).
- **Accountant** — full CRUD across accounts, journals, customers/suppliers,
  invoices/bills, receipts/payments, notes, cash/bank, reconciliations, fixed
  assets, dimensions; **budgets view/create/update/delete but not approve**;
  reports/ledger/periods/tax reads; controls view. **No** `accounting.currency.create`
  or currency activate/deactivate (global master data).
- **Manager** — read-only across modules, plus `bank_reconciliation.*` actions,
  **`accounting.budgets.approve`**, `companies.update`, `companies.settings.*`.
  No create/post/update on any transactional document. **Dimensions: view only.**
- **Staff** — `companies.view` only.

Do not hard-code role names for authorization; drive UI from the capabilities the
user actually holds (the API is the authority and returns 403 when a capability is
missing). User roles are returned by `GET /api/auth/me`.

---

## 13. Limitations and deferred capabilities

1. **Pagination metadata absent (P2-1).** See §7. Design list screens accordingly
   until the response envelope is revised.
2. **No refresh token.** Re-authenticate on 401.
3. **No public audit-log API.** Audit records are written internally; there is no
   endpoint to read them.
4. **Dimension filtering is P&L and Budget Variance only.** Trial Balance,
   General Ledger and Balance Sheet are **not** dimension-filterable (deliberately
   deferred in Phase 17).
5. **Deferred by design:** comparative-period reporting, cash-flow statements,
   consolidation, unrealized FX revaluation, country-specific tax filing /
   e-invoicing, PDF generation endpoints. Do not assume these exist.
6. **Documents are base-currency in the implemented scope.** Foreign-currency
   provenance is exposed on journal lines; present document totals in base
   currency.
7. **Immutability:** posted documents/journals cannot be edited or deleted; drafts
   can. Lifecycles are separate paths (`PUT` edits, `POST /post` posts).
8. **Cross-company ids return 404**, not 403.

---

For the audit verdict, findings and evidence, see
`docs/report/PHASE_18_REPORT.md`.
