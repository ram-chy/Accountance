# Fixed Asset Management (Phase 12) Implementation Report

Application: Laravel 13 accounting backend (`accounting-app/backend`)
Phase brief: `prompt/PHASE 12 — FIXED ASSET MANAGEMENT.md`

> Note on location: §43 of the brief asks for `docs/report/PHASE_12_REPORT.md`
> and to follow the repository's actual convention. That path is written here,
> matching the eleven sibling reports already in the repository.

---

## 1. Phase Objectives

Deliver a fixed-asset accounting layer on top of the existing engine:

- asset **categories** as templates (accounts + useful life) from which assets
  copy their configuration;
- asset **registration** as a draft, then **capitalisation** into the ledger;
- **straight-line depreciation** calculated exactly, posted period by period
  through the existing journal engine;
- **disposal** that values the asset, splits the result into gain and loss, and
  balances the entry;
- an **asset register**, a **depreciation schedule** and a **depreciation
  report**, all read-only;
- the same company isolation, authorization, audit, exact-money and
  fiscal-period conventions as every other phase.

Explicitly **not** in scope (§1, §37): inventory behaviour, tax-compliance
functionality, asset revaluation, impairment, component accounting, physical
asset tracking or barcodes, and any second ledger or journal engine.

## 2. Current Architecture Reviewed

The seams were found by reading the existing code, not by adding alongside it.
The Phase 11 report was read first, as §2 requires.

| Existing piece | What it decided for Phase 12 |
|---|---|
| `App\Support\Money` | Exact decimal arithmetic reused for every charge, sum and residual; no float money was introduced. |
| `JournalService` / `JournalPostingService` | The single journal path. Capitalisation, depreciation and disposal each build a draft and post it, exactly as Phase 5 does. |
| `LedgerService` | Posted-only, company-scoped balances. P&L and Balance Sheet needed **no change** (§26). |
| `AccountingPeriodService` | Posting already refuses a date with no open period, so no fiscal rule was written for assets (§14). |
| `DocumentNumberSequence` | One `FA-` counter for the register. |
| `TransactionAccountResolver` | Extended with the fixed-asset, accumulated-depreciation, depreciation-expense and disposal-result roles. |
| `CompanyContext` + scoped route bindings | The isolation mechanism, reused unchanged. |
| `ConflictException` / `ValidationException` | The 409/422 distinction, reused unchanged. |
| `DocumentNumberType`, `JournalSource`, `PermissionName` | Extended, not duplicated. |

## 3. Database Changes

Four additive migrations. No Phase 1–11 table was altered.

| Migration | Contents |
|---|---|
| `2026_10_07_090000_create_fixed_asset_categories_table.php` | `fixed_asset_categories`, five named account FKs, two CHECKs. |
| `2026_10_07_090100_create_fixed_assets_table.php` | `fixed_assets`, seven CHECKs, four indexes. |
| `2026_10_07_090200_create_fixed_asset_depreciations_table.php` | `fixed_asset_depreciations`, three CHECKs, two unique keys. |
| `2026_10_07_090300_create_fixed_asset_disposals_table.php` | `fixed_asset_disposals`, four CHECKs, one unique key. |

**No stored accumulated-depreciation or carrying-value column.** Both are
computable from `fixed_asset_depreciations`, and a row there exists if and only
if its charge posted — the row and the journal are written in one transaction.
Storing either would be a second source of truth for a figure the schema can
already answer, and a second source is a second chance for the register and the
ledger to disagree with nothing noticing.

**The account columns are a snapshot, not a live mapping.** The five accounts are
copied from the category onto the asset at creation and never read from the
category again. The capitalisation entry, every depreciation entry and the
disposal entry must post cost to the *same* asset account; an asset whose account
came from a live join could have half its cost in one account and the rest in
another with no record that it happened.

**`gain_on_disposal_account_id` and `loss_on_disposal_account_id` are separate
nullable columns.** They sit on opposite sides of the P&L and mean opposite
things to a reader; one `disposal_result_account` column would let a category
book every disposal to whichever side flattered the number. The services refuse a
category that has neither (but allow either one alone).

### CHECK constraints

| Table | Constraint | Rule |
|---|---|---|
| categories | `fixed_asset_categories_life_check` | `useful_life_months > 0` |
| categories | `fixed_asset_categories_method_check` | `depreciation_method = 'STRAIGHT_LINE'` |
| assets | `fixed_assets_cost_check` | `original_cost > 0` |
| assets | `fixed_assets_salvage_check` | `salvage_value >= 0 and salvage_value <= original_cost` |
| assets | `fixed_assets_life_check` | `useful_life_months > 0` |
| assets | `fixed_assets_method_check` | `depreciation_method = 'STRAIGHT_LINE'` |
| assets | `fixed_assets_acquisition_check` | `acquisition_method in ('CASH','SUPPLIER_CREDIT')` |
| assets | `fixed_assets_status_check` | `status in ('DRAFT','ACTIVE','FULLY_DEPRECIATED','DISPOSED')` |
| assets | `fixed_assets_disposed_requires_date_check` | `DISPOSED` implies `disposed_at` is set |
| assets | `fixed_assets_capitalised_requires_date_check` | non-`DRAFT` implies `capitalised_at` is set |
| depreciations | `fixed_asset_deprecations_amount_check` | `amount > 0` |
| depreciations | `fixed_asset_deprecations_number_check` | `period_number > 0` |
| depreciations | `fixed_asset_deprecations_dates_check` | `period_end_date >= period_start_date` |
| disposals | `fixed_asset_disposals_signs_check` | `gain >= 0 and loss >= 0` |
| disposals | `fixed_asset_disposals_exclusive_check` | `not (gain > 0 and loss > 0)` |
| disposals | `fixed_asset_disposals_proceeds_check` | `proceeds >= 0` |
| disposals | `fixed_asset_disposals_carrying_check` | `carrying_value_at_disposal >= 0` |

The natural fourth invariant — "a non-draft asset has a `journal_id`" — is
deliberately absent. `journal_id` carries `ON DELETE SET NULL`, and MySQL error
3823 forbids a `SET NULL` column from appearing in a CHECK, because dropping the
referencing row would change the value being tested. The same split Phase 11
reached on `credit_debit_notes`: the schema proves the audit half, the service
writes all of it in one statement. This was found by running the migration.

Indexes: `fixed_assets` on `(company_id,status)`, `(company_id,depreciation_start_date)`,
`(company_id,fixed_asset_category_id)`, `(company_id,acquisition_date)`;
`fixed_asset_depreciations` on `(company_id,period_start_date)` and
`journal_id`, with unique keys on `(fixed_asset_id,period_number)` and
`(fixed_asset_id,period_start_date)`; `fixed_asset_disposals` unique on
`fixed_asset_id` and indexed on `(company_id,disposal_date)`.

Uniqueness: `(company_id,asset_number)` and `(company_id,code)` / `(company_id,name)`
for categories — per company, so two companies may each start at `FA-000001`.

## 4. Models

| Model | Notes |
|---|---|
| `FixedAssetCategory` | Account relations, `assets()`, `is_active`; five account ids are fillable, `is_active` is server-owned. |
| `FixedAsset` | Status/date/enum casts; relations to category, five accounts, journal, `depreciations()`, `disposal()`; derived accessors `accumulatedDepreciationAmount()`, `carryingAmount()`, `remainingPeriodCount()`, `periodCount()`, `monthlyChargeAmount()`; `scopeOnRegister()`. |
| `FixedAssetDepreciation` | `FixedAssetDepreciationResource` shape; `isFinalPeriod()`; `inPeriod()` scope; no factory (rows are only produced by posting). |
| `FixedAssetDisposal` | `netResultAmount()`; one row per asset. |

`FixedAsset`'s derived accessors are **aggregate-attribute aware**: when the
controller eager-loads `depreciations`, the accumulated figure is reduced from
the loaded rows with `Money`; when the query uses `withSum('depreciations','amount')`
and `withMax('depreciations','period_number')`, the accessors read those
attributes; otherwise they fall back to an exact `SUM` query. All three paths
produce the same decimal.

## 5. Enums

- `FixedAssetStatus`: `DRAFT`, `ACTIVE`, `FULLY_DEPRECIATED`, `DISPOSED`, with
  `isDraft()`, `isCapitalised()`, `isOnRegister()`, `isDepreciable()`,
  `isDisposable()`, `isDisposed()`. Not `TransactionStatus`: an asset stays in
  the ledger for years after capitalisation, so there is no `POSTED` state.
- `DepreciationMethod`: `STRAIGHT_LINE`.
- `FixedAssetAcquisitionMethod`: `CASH`, `SUPPLIER_CREDIT`.
- `JournalSource`: added `FixedAsset`, `FixedAssetDepreciation`,
  `FixedAssetDisposal`.
- `DocumentNumberType`: added `FixedAsset`, prefix `FA-`.
- `PermissionName`: added the seven `accounting.fixed_asset.*` values.

## 6. Services

| Service | Responsibility |
|---|---|
| `FixedAssetCategoryService` | Category create/update/delete/activate/deactivate; re-resolves account roles on every write; refuses deactivation/deletion while an asset refers to it. |
| `FixedAssetService` | Draft create, update, delete, and capitalise. Copies the category snapshot onto the asset, allocates `FA-`, builds and posts the capitalisation journal. Draft-only guards under a row lock. |
| `FixedAssetDepreciationService` | `depreciate()` charges only the earliest unposted period, under the asset lock; `schedule()` returns the full projection. |
| `FixedAssetDisposalService` | Values the asset at the disposal date, computes gain/loss against carrying value, builds and posts the disposal journal. |
| `FixedAssetDepreciationCalculator` | Pure straight-line maths; no queries, no side effects. Makes the rounding promise testable in isolation. |
| `DepreciationPeriod` / `FixedAssetDepreciationSummary` | Value objects for one projected period and one asset's posted/depreciable totals. |

`TransactionAccountResolver` gained `fixedAsset()`, `accumulatedDepreciation()`
(ASSET **and** CREDIT-normal), `depreciationExpense()`, `gainOnDisposal()`,
`lossOnDisposal()`, `acquisitionAccount()` (cash/bank or payable depending on the
route's method) and `proceedsAccount()` (ASSET).

## 7. Accounting Flow

```
register (DRAFT, no journal)
   └─ capitalise() ──────────────►  Dr Fixed Asset / Cr Cash|Payable
                                        status → ACTIVE
   └─ depreciate() × N ──────────►  Dr Depreciation Expense / Cr Accumulated Depreciation
                                        status → FULLY_DEPRECIATED on the last period
   └─ dispose() ─────────────────►  Dr Accumulated Depreciation
                                    Dr Loss on Disposal        (only if proceeds < carrying)
                                    Dr Proceeds account        (only if proceeds > 0)
                                       Cr Fixed Asset
                                       Cr Gain on Disposal      (only if proceeds > carrying)
                                        status → DISPOSED
```

Every leg is written through `JournalService` → `JournalPostingService`; the
asset never writes to the ledger directly and there is no second journal writer.

## 8. Capitalisation

`POST /cash` and `POST /supplier-credit` are **separate endpoints**, not one with
a method field (§5 of the route docblock). Which side of the entry is the money
side depends entirely on the method, and the eligibility rule that follows ("a
cash purchase must credit a cash/bank account, a supplier-credit purchase a
payable") must be validated against the method. A method carried in the body
would mean validating that rule against a value the client chose — the same
reason cash/bank transactions get a route per type. So the method is an argument
the controller supplies from the route it was reached through.

At capitalisation the category's useful life, method and five accounts are copied
onto the asset; the acquisition account is the per-asset fact already on the row.
The asset row cannot be edited or deleted once capitalised — the service re-checks
status under a lock and throws `ConflictException` (409).

## 9. Depreciation

Straight-line over the asset's own cycle: period N runs from
`depreciation_start_date` to the same day N−1 months later. An asset capitalised
on the 15th has a period 1 that is not a calendar month, and that is correct —
half a month of ownership is half a month of depreciation.

- Only the **earliest unposted period** may be charged; a later one is refused
  (`ConflictException`) so a gap cannot be created by the application.
- A period **cannot be charged before it has ended** (`ValidationException`).
- The **final period absorbs the rounding remainder**, so the periods sum exactly
  to the depreciable base. 1000 / 3 is `333.3333`, `333.3333`, `333.3334` — not
  three times `333.3333` with `0.0001` stranded forever.
- The charge posts on the period's **end date**, so the entry falls into the
  period it covers and the room for it is opened on that date.

## 10. Disposal, Gain and Loss

The disposal entry debits accumulated depreciation for the **total written down**
(not the carrying value — the two differ by the whole accumulated amount) and
credits the fixed-asset account for **original cost**; the difference between
proceeds and carrying value lands in gain or loss.

- `gain = max(0, proceeds − carrying)` and `loss = max(0, carrying − proceeds)`,
  computed by `Money`, never by the client.
- The gain and loss legs are **conditional**: a zero-both-sides line is rejected
  by `JournalService`, so the accumulated, loss, proceeds and gain legs are each
  emitted only when non-zero. Selling a fully depreciated asset for nothing
  produces a balanced two-line entry.
- A disposal **cannot be backdated** before the last posted period end
  (`ValidationException`); the same day is allowed (inclusive, asserted).
- An asset can be disposed **at most once** — enforced by the unique
  `fixed_asset_id` and the status check.

## 11. Derived Figures, Not Stored Balances

The asset register has no accumulated-depreciation or carrying-value column.
Those figures are computed from posted depreciation rows, which are the register's
only record of what has been written off. This is the §35/§36 constraint and the
reason the module cannot drift from the ledger. The register and schedule
endpoints eager-load or aggregate the rows so the computation costs no per-row
query.

## 12. API Endpoints

| Method | Path | Permission |
|---|---|---|
| GET | `/api/accounting/fixed-assets` | view |
| GET | `/api/accounting/fixed-assets/register` | view |
| GET | `/api/accounting/fixed-assets/depreciation-report` | view |
| POST | `/api/accounting/fixed-assets/cash` | create |
| POST | `/api/accounting/fixed-assets/supplier-credit` | create |
| GET | `/api/accounting/fixed-assets/{fixedAsset}` | view |
| PUT | `/api/accounting/fixed-assets/{fixedAsset}` | update |
| DELETE | `/api/accounting/fixed-assets/{fixedAsset}` | delete |
| POST | `/api/accounting/fixed-assets/{fixedAsset}/capitalize` | capitalize |
| GET | `/api/accounting/fixed-assets/{fixedAsset}/depreciation-schedule` | view |
| POST | `/api/accounting/fixed-assets/{fixedAsset}/depreciation` | depreciate |
| POST | `/api/accounting/fixed-assets/{fixedAsset}/dispose` | dispose |
| GET | `/api/accounting/fixed-asset-categories` | view |
| POST | `/api/accounting/fixed-asset-categories` | create |
| GET | `/api/accounting/fixed-asset-categories/{fixedAssetCategory}` | view |
| PUT | `/api/accounting/fixed-asset-categories/{fixedAssetCategory}` | update |
| DELETE | `/api/accounting/fixed-asset-categories/{fixedAssetCategory}` | delete |
| POST | `/api/accounting/fixed-asset-categories/{fixedAssetCategory}/activate` | update |
| POST | `/api/accounting/fixed-asset-categories/{fixedAssetCategory}/deactivate` | update |

The routes live under the existing `/api/accounting` prefix, following the
Phase 10 tax-module precedent rather than the brief's illustrative
`/api/fixed-assets`. `register` and `depreciation-report` are declared **before**
`{fixedAsset}` so the static paths win the match and are never read as an id.

## 13. Permissions

`accounting.fixed_asset.view` / `.create` / `.update` / `.delete` / `.capitalize` /
`.depreciate` / `.dispose`. Admin and Accountant hold all seven; Manager holds
`view` only; Staff holds none. `activate`/`deactivate` on a category reuse the
category `update` permission, as the taxes module does. The seven new values
bring the permission configuration to 85 entries, matching the enum count.

## 14. Authorization

`FixedAssetPolicy` and `FixedAssetCategoryPolicy` are registered explicitly in
`AppServiceProvider`. Because a Form Request can forget to authorize and a
controller can read the wrong policy, the matrix is tested through real HTTP
requests against every door, including the staff-forbidden and cross-company
cases.

## 15. Company Isolation

No client-supplied `company_id` reaches any query; `CompanyContext` decides.
`fixedAsset` and `fixedAssetCategory` are bound to the active company in
`AppServiceProvider`, so an id from another tenant **404s** before any method
runs rather than 403ing and confirming the id exists. Service calls receive the
company resolved from the context, never from the request.

## 16. Fiscal-Period Handling

Reuses `AccountingPeriodService` unchanged. Capitalisation posts into the period
covering `depreciation_start_date`; each depreciation posts on its period's end
date; a disposal posts on its own `disposal_date`. A draft needs no period;
capitalising, depreciating and disposing do. A charge is refused if its period
has not ended, and a disposal is refused before the last posted period end, so
the depreciation a disposal's valuation depends on is always in the ledger first.

## 17. Reports

| Report | Change |
|---|---|
| Asset register | New read-only endpoint, `ACTIVE` + `FULLY_DEPRECIATED` only, with derived values. |
| Depreciation schedule | New read-only endpoint: posted periods and the remaining projection. |
| Depreciation report | New read-only endpoint, grouped by asset, windowed by each charge's own period dates. |
| P&L / Balance Sheet / Trial Balance | **No change needed** — the journals are ordinary posted entries and the reports are ledger-derived. |
| Tax reports | Untouched; a fixed asset is not a taxable document in this phase. |

The brief's §27 permission to derive the depreciation report from posted
journals is taken literally: `depreciationReport()` reads
`fixed_asset_depreciations` directly, and the P&L/balance-sheet answer is reached
by reading the existing ledger path rather than by adding a fixed-asset branch to
a report.

## 18. Security Verification

- No endpoint writes on a report path.
- Server-owned fields (`asset_number`, `status`, all five category accounts,
  `useful_life_months`, `depreciation_method`, `journal_id`, every date/user
  column, and all computed totals) are absent from the validated payload, so a
  client supplying them is ignored, not obeyed.
- `acquisition_method` is absent from the create payload — it is the route.
- `gain`, `loss`, `carrying_value_at_disposal` are absent from the disposal
  payload; the service computes them.
- The category's account ids are validated for company membership in the request
  and for their role in the service, so a foreign or wrongly-typed account is
  refused.
- The accumulated-depreciation account must be ASSET **and** CREDIT-normal, checked
  by `TransactionAccountResolver` — the one configuration mistake that would
  inflate the balance sheet while still footing.
- RESTRICT on account and category FKs: an account or category in use cannot be
  deleted out from under an asset.
- Policies registered, so no endpoint relies on a missing-policy fall-through.

## 19. Concurrency Handling

Every irreversible operation locks the asset row (`lockForUpdate`) before reading
its state: capitalise refuses a second capitalisation, depreciate refuses an
out-of-order or duplicate period, dispose refuses a second disposal. The
depreciation and disposal unique keys are the last line — a check made from a
snapshot is a check made before the other transaction committed. Category
deactivate/delete likewise lock the category row.

## 20. Tests

56 Phase 12 tests, 210 assertions, all passing.

| File | Tests | Covers |
|---|---|---|
| `FixedAssetLifecycleTest` | 22 | Capitalisation (cash and credit), the two journal directions, depreciation order and gap refusal, exact rounding, fully-depreciated state, gain/loss disposal shapes, zero-proceeds write-off, backdating, tenancy |
| `FixedAssetFactoryTest` | 4 | The category and asset factories produce service-valid rows |
| `FixedAssetCategoryServiceTest` | 6 | Account-role validation, the at-least-one-disposal-account rule, deactivate/delete guards |
| `FixedAssetApiTest` | 15 | Create through both routes, method/account disagreement, draft edit/delete, capitalised 409, register, schedule, depreciation, disposal, report, backdating, cross-company 404, staff 403 |
| `FixedAssetCategoryApiTest` | 9 | Category CRUD, activate/deactivate, in-use guards, cross-company 404, staff 403 |

The defects the tests found, kept because they are the useful part:

- **A bogus import** in a service factory-method path, caught by lint before the
  first run.
- **An unconditional accumulated-depreciation disposal leg**, which produced an
  unbalanced (both-sides-zero) line when an asset was sold for nothing. Fixed by
  emitting the leg only when the written-down amount is non-zero.
- **Merged-account validation on category update**: clearing the gain account
  while a loss account remained was refused, and clearing both was accepted if
  the keys happened to be omitted. Fixed by validating the *effective* merged
  configuration.
- **Factories that created accounts in their own company**, so a category pointed
  at five accounts across five other companies and every asset test failed on a
  company-isolation error while appearing to test something else. Fixed by
  anchoring every factory account to the category's company.
- **Depreciation/disposal factories removed**: a depreciation or disposal row is
  only ever produced by posting, so a factory would have made it possible to
  write a row whose journal did not exist.

## 21. Migration Verification

`DatabaseTest::test_migrations_can_be_rolled_back_and_re_run` rolls every
migration back and re-runs them, and passes — so the four Phase 12 migrations,
including their named foreign keys and CHECK constraints, drop and recreate
cleanly. `migrate:fresh --force` against the local MySQL 8.4.3 database also
passes.

## 22. Commands Executed

```text
php artisan migrate:fresh --force
php artisan app:sync-roles
php artisan route:list --path=fixed-asset
php artisan test tests/Feature/Accounting/FixedAssets   # 56 tests
php artisan test                                        # full suite
./vendor/bin/pint --dirty
php -l <each new/changed file>
```

Full suite result: **947 tests, 4622 assertions, 0 failures, 0 errors.**

Before Phase 12 the suite was 891 tests / 4404 assertions, so the phase added 56
tests and 218 assertions — 56 tests and 210 assertions in the five new files, and
the remaining eight in pre-existing configuration tests that the new permissions
and migration counts extend. Nothing regressed. Pint: clean.

## 23. Known Limitations

- A non-draft asset's `journal_id` presence is guaranteed by the service, not by
  a CHECK, because MySQL forbids a `SET NULL` column in a CHECK expression (§3).
- Straight-line is the only method. The schema and the calculator are shaped for
  one method; reducing-balance would be a new schedule rule, not a new column.
- There is no revaluation, impairment or component accounting. Salvage is the
  floor the model clamps the carrying value to.
- The depreciation report groups in PHP after one query; a company with a very
  large number of assets in one window would want server-side grouping. The
  correctness benefit (exact `Money` sums) was chosen over the query cost.
- A disposal records gain and loss as separate stored amounts; a signed
  `net_result` is exposed on the resource but not stored, matching how the
  journal consumes them.

## 24. Deferred Features

Per §37 and §45: inventory effects, tax-compliance/country-specific reporting,
asset revaluation and impairment, component/sub-asset accounting, physical asset
tracking, barcodes and depreciation-method configuration beyond straight line,
and fixed-asset-specific P&L or balance-sheet sub-reports (the ledger already
carries the journals).

## 25. Requirements Traceability

| Brief | Where |
|---|---|
| §4–§8 Categories, accounts, useful life | §3, §4, §6; `FixedAssetCategoryService` |
| §9–§13 Asset registration and fields | §3, §4, §8; `StoreFixedAssetRequest` |
| §14–§18 Capitalisation and accounts | §8; `FixedAssetService::capitalise` |
| §19–§25 Depreciation calculation and posting | §9; calculator + `FixedAssetDepreciationService` |
| §24–§25 Disposal, book value, gain/loss | §10; `FixedAssetDisposalService` |
| §26 Accounting report integration | §17; ledger-derived, no change |
| §27–§28 Register and depreciation report | §12, §17 |
| §29–§30 API design and resources | §12; four resources |
| §31–§33 Authorization and roles | §13, §14 |
| §34 Company isolation | §15; scoped bindings |
| §35–§36 No second engine, no stored balance | §11, §7 |
| §38 Immutability of posted records | §8, §19 |
| §39 Concurrency | §19 |
| §40 Money precision | §9; `Money` throughout |
| §41 Fiscal periods | §16 |
| §42 Route verification | §12; `route:list` |
| §43 Documentation | This file |
| §44 Hard stops | None triggered (below) |
| §45 Code quality | No unrelated module touched; existing services reused |
| §46 Acceptance criteria | §26 below |

### Hard stop conditions (§44)

None occurred. The two that could have, and why they did not:

- **"Correct depreciation would require storing a second accounting balance
  source."** It did not: the depreciation rows *are* the posted charges, so the
  register's totals are a sum over the same rows the journals came from — one
  source, not two.
- **"Existing purchase/acquisition architecture creates unavoidable duplicate
  accounting."** It did not: a fixed-asset acquisition is a standalone
  capitalisation entry and is deliberately **not** coupled to the purchase-bill
  module, so a supplier-credit acquisition raises a payable and nothing else. The
  brief permits this (§9) and coupling it to bills would have been the duplicate.

## 26. Final Status

**PASS.**

- 56 focused Phase 12 tests, 210 assertions, all passing.
- Full suite: **947 tests, 4622 assertions, 0 failures, 0 errors.**
- Pint clean; `php -l` clean; migrations roll back and re-run.

Acceptance criteria (§46) verified:

- assets created safely, capitalisation balanced, depreciation exact and posted
  through the existing journal, disposal balanced with correct gain/loss;
- posted records immutable; fiscal periods respected; company isolation and
  authorization enforced; concurrent irreversible operations prevented;
- register and depreciation schedule work; existing reports reflect the journals
  without change;
- no second accounting engine; no stored accounting balance;
- migrations pass; focused and full suites pass; Pint passes;
- no seeders created or executed; no frontend created; no unrelated module
  changed; `docs/report/PHASE_12_REPORT.md` exists.
