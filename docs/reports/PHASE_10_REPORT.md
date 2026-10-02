# Phase 10 — Tax Engine Report

Application: Laravel 13 accounting backend (`accounting-app/backend`)
Phase brief: `prompt/PHASE 10 — TAX ENGINE.md`

---

## 1. Phase Objectives

Deliver a tax engine that fits the existing accounting application rather than
sitting beside it:

- configurable taxes with an effective-dated rate history;
- exact, server-side tax arithmetic with a single canonical implementation;
- tax named on sales invoices and purchase bills, snapshotted so posted history
  never moves;
- account mappings for where tax money posts;
- read-only tax reports sourced from the accounting record;
- the same company isolation, authorization and audit conventions as every other
  phase.

Explicitly **not** in scope (§40): filing, GST/VAT returns, tax authority
integration, e-invoicing, e-way bills, country-specific compliance reports, and
tax payment or refund workflows.

## 2. Existing Architecture Reviewed

Before writing anything, the following were read to find the seams rather than
invent new ones:

| Existing piece | What it decided for Phase 10 |
|---|---|
| `App\Support\Money` | Exact decimal arithmetic already existed; the tax engine reused it instead of adding float math. Two methods were added for tax specifically (`percentageOf`, `inclusivePercentageOf`). |
| `App\Services\Accounting\LedgerService` | Posted-only, company-scoped queries. Confirmed the "posted journal" definition the reports and the deletion guard depend on. |
| `App\Services\Accounting\Reports\*` | Report shape and conventions (`JournalReportService`, inclusive date windows, `ReportRequest`). |
| `App\Services\Accounting\DocumentCalculator` | Where line totals are computed; already wrote the `tax_rate`/`tax_amount` snapshot. |
| `SalesInvoiceService` / `PurchaseBillService` | Where documents are persisted and posted. |
| `TransactionAccountResolver` | Existing account-type validation for `tax_account_id`. Reused rather than duplicated. |
| `Phase 8` periods | Posting already refuses a date with no open period, so no fiscal-rule logic was written. |
| `BankReconciliationMovementService` | Had a company/date scoping defect; corrected in this phase (see §22). |

## 3. Database Changes

Four migrations, all additive. Nothing was dropped or rewritten, and Phases 1–9
tables keep their existing definitions.

| Migration | Contents |
|---|---|
| `2026_10_05_150000_create_taxes_table.php` | `taxes`: company-scoped master data. `tax_type`, `calculation_basis`, `is_active`, audit columns. |
| `2026_10_05_150100_create_tax_rates_table.php` | `tax_rates`: effective-dated rate periods (`effective_from`, `effective_to`, `rate`, `is_active`). |
| `2026_10_05_150200_create_tax_account_mappings_table.php` | `tax_account_mappings`: output and input account per tax. |
| `2026_10_05_150300_add_tax_id_to_document_lines_table.php` | Nullable `tax_id` on `sales_invoice_lines` and `purchase_bill_lines`. |

Uniqueness (§33): `taxes` is unique on `(**company_id**, code)`, not on `code`
alone. A global unique code would force a second company to invent a different
code for the same tax, which is a data-quality defect dressed as integrity.

Indexes: `tax_id` is indexed on both line tables by the foreign key itself. An
explicit duplicate index was added during development and then removed — InnoDB
already creates one for a foreign key, and the duplicate also broke the
`migrate:rollback` test because MySQL will not drop an index a constraint still
needs.

`tax_id` is nullable and that is load-bearing, not a shortcut:

- rows predating the tax engine have no configured tax behind them;
- a line may still carry a hand-entered rate with no `tax_id`;
- a line charging several taxes cannot name them all in one column.

## 4. Models

- `Tax` — master record; `rateOn($date, $rates)` resolves the effective rate;
  `appliesToSales()` / `appliesToPurchases()` delegate to the enum.
- `TaxRate` — one effective period of one tax. `is_active` and audit columns
  deliberately **not** fillable, so lifecycle cannot be flipped through a mass
  assignment.
- `TaxAccountMapping` — output/input account pair.

`tax_id` was added to the fillable attributes of `SalesInvoiceLine` and
`PurchaseBillLine` because the document services persist it explicitly. This is
the one place Phase 10 writes to Phase 5 tables, and it writes only a nullable
identifier that no existing code reads.

## 5. Enums

- `TaxType`: `OUTPUT`, `INPUT`, `BOTH`, with `appliesToSales()` /
  `appliesToPurchases()`. The predicates live on the enum, not in SQL `whereIn`
  clauses, so there is one encoding of "which side does this tax touch".
- `TaxCalculationBasis`: `EXCLUSIVE`, `INCLUSIVE`.
- `PermissionName`: six new cases — `accounting.tax.view`, `.create`,
  `.update`, `.delete`, `.calculate`, `.report.view`.

## 6. Services

| Service | Responsibility |
|---|---|
| `TaxCalculationService` | The only implementation of the arithmetic (§7). No database write on any path. |
| `TaxRuleResolver` | Company- and side-scoped resolution of named tax ids to effective rates. Batch-loads rates to avoid N+1. |
| `TaxService` | Tax CRUD and lifecycle. |
| `TaxRateService` | Rate history: transactional creation, predecessor closing, overlap rejection, deletion guard. |
| `TaxAccountMappingService` | Account-role validation and replacement. |
| `Reports\TaxReportService` | Read-only summary and by-tax reports (§11). |
| `DocumentTaxContext` | Value object carrying company, date and side from a document into the calculator. |

`DocumentCalculator` gained optional `TaxCalculationService`, `TaxRuleResolver`
and context parameters. Every existing caller is unchanged, so untaxed documents
behave exactly as in Phase 5.

## 7. Tax Calculation Rules

Exclusive:

```text
tax = net x rate / 100
```

Inclusive:

```text
tax = gross x rate / (100 + rate)
```

Multiple taxes are **parallel, never cascading** (§11). Each component is computed
from the same base; no component is computed from a figure another component
already inflated.

Both bases are supported by `POST /api/accounting/tax/calculate`.

**Documents support `EXCLUSIVE` only**, and this is a deliberate restriction, not
an omission. Phase 5 defines a document line as
`line_total = net + tax`, revenue posting as `line_total - tax_amount`, and
document totals as `subtotal - discount + tax_total`. An inclusive tax is the
tax already *inside* the figure, so supporting it would mean changing the meaning
of `line_total` on every existing invoice, bill and journal. Rather than risk the
Phase 5 ledger to offer a basis no current document uses, naming an inclusive tax
on a document is **refused with a 422 naming `INCLUSIVE`**. The calculation
endpoint still serves inclusive quotes, where extracting the tax from a gross is
well defined and touches no ledger.

Naming a deactivated tax, or a tax with no rate in force on the document's date,
is likewise **refused rather than silently charged at zero**. An under-charged
document that looks deliberate is worse than one that fails to save.

## 8. Rounding Rules

All money is `decimal(18,4)`; rates are `decimal(7,4)`. Arithmetic is `bcmath`
string math at scale 4, never float.

- Two operands at scale 4 multiplied give scale 8 as the working scale.
- Division runs at that working scale, so the truncation error sits far below the
  last stored place.
- Exactly one **half-up** rounding to scale 4 then produces the stored value.
- `percentageOf()` and `inclusivePercentageOf()` share the working scale and the
  rounding deliberately: an inclusive and an exclusive tax on the same sale must
  not differ in the last decimal for reasons unrelated to the tax.

Rates are validated, not clamped: a rate at or above 100 is rejected with a
message naming the field.

## 9. Historical Tax Integrity

This was the phase's central risk and it is structural rather than procedural.

- Every line stores `tax_rate` and `tax_amount` as written at calculation time.
  Nothing anywhere re-reads `tax_rates` to recompute a document.
- `tax_id` is an additional identifier for reporting. It is never an input to
  arithmetic, so it cannot change a figure.
- `restrictOnDelete()` on the `tax_id` foreign keys is the backstop for a raw
  DELETE; `TaxService` refuses the deletion in the application first with a
  message that points at deactivation.
- `TaxRateService::delete()` refuses to delete a rate that **any** posted document
  used, regardless of the rate's period.
- Reactivating a tax or a rate resumes the rate already in force. Neither rewrites
  history.

Asserted by tests: a rate raised to 25% in April does not move a January report;
deactivating a tax leaves a posted invoice's 10% snapshot intact; a rate can be
deactivated and reactivated without touching any document.

## 10. Accounting Integration

The tax engine does not post anything. It produces `tax_rate` and `tax_amount`,
and Phase 5's existing posting services build the journal exactly as they did
before:

- the tax posts to the document's existing `tax_account_id`;
- revenue/expense still post the **net**, not the gross;
- the journal still balances, because the document totals the posting service
  reads are unchanged.

`TransactionAccountResolver` already validated `tax_account_id` against the right
account type, and that validation now also fires when tax comes from the
configuration rather than a typed rate. A taxed document with no tax account is
refused with a 422 on `tax_account_id`.

Verified end to end in `TaxDocumentIntegrationTest`: a posted invoice of 200.00
net at 10% produces a journal of Dr Receivable 220.00 / Cr Revenue 200.00 /
Cr Tax Payable 20.00.

## 11. API Endpoints

All under `/api/accounting`, all behind `auth:api`, `auth.fresh`,
`company.context`, `throttle:api`.

```text
GET    /taxes                                   index          (tax.view)
POST   /taxes                                   store          (tax.create)
GET    /taxes/{tax}                             show           (tax.view)
PUT    /taxes/{tax}                             update         (tax.update)
DELETE /taxes/{tax}                             destroy        (tax.delete)
POST   /taxes/{tax}/activate                    activate       (tax.update)
POST   /taxes/{tax}/deactivate                  deactivate     (tax.update)

GET    /taxes/{tax}/rates                       rates          (tax.view)
POST   /taxes/{tax}/rates                       storeRate      (tax.create)
PUT    /taxes/{tax}/rates/{rate}                updateRate     (tax.update)
DELETE /taxes/{tax}/rates/{rate}                destroyRate    (tax.delete)
POST   /taxes/{tax}/rates/{rate}/activate       activateRate   (tax.update)
POST   /taxes/{tax}/rates/{rate}/deactivate     deactivateRate (tax.update)

GET    /taxes/{tax}/account-mapping             show           (tax.view)
PUT    /taxes/{tax}/account-mapping             update         (tax.update)

POST   /tax/calculate                           calculate      (tax.calculate)

GET    /tax-reports/summary                     summary        (tax.report.view)
GET    /tax-reports/by-tax                      byTax          (tax.report.view)
```

`POST /tax/calculate` is POST because the amount, the taxes and the date are
inputs that determine the answer and there is no resource to address afterwards.
It writes nothing. It is not under the `reports` prefix because it is not a
report.

### Reports

Both reports are derived from **posted documents' own line snapshots**
(§27) — not from tax configuration, and not by recomputing anything. A tax raised
after the fact cannot alter a closed period.

`summary` returns taxable base, tax collected, tax recovered, the difference, and
the unattributed total. `by-tax` returns the same figures one row per tax.

Two properties are worth stating because they are the ones that make a tax report
trustworthy:

- **Unattributed amounts are reported, not dropped.** A line with a
  hand-entered rate, or a line charging several taxes, has no single `tax_id`.
  Omitting those amounts would make every named tax's row look right while the
  report no longer footed to the ledger. They are reported in their own row with
  a null `tax_id` and are included in the totals.
- **The reports are not filings.** They report what the ledger says was charged
  and recovered. They reconcile to no authority's return and apply no
  jurisdiction's rules, because no jurisdiction concept is modelled anywhere in
  this application. Inventing one would produce a document that looks like a
  return and is not one.

A separate `TaxReportController` was used rather than adding two actions to the
Phase 6 `ReportController`. That class documents that every action it holds is
gated on `accounting.reports.view`; adding actions with a different permission
would make the statement false.

## 12. Permissions

Six new permissions in `PermissionName` and `config/authorization.php`:

| Role | Granted |
|---|---|
| Accountant | all six |
| Manager | `tax.view`, `tax.calculate`, `tax.report.view` |
| Staff | none |

Manager stays read-only, consistent with every other accounting capability in the
application. Staff gets nothing, including `calculate`: quoting a tax discloses
the company's effective rate, which is exactly the disclosure Staff may not see.

Activation and deactivation are gated on **`tax.update`**, not `tax.delete`. They
are the brief's safe alternative to destroying a tax (§21), not a privileged act;
gating them on `delete` would mean a role that may stop charging a tax could not
undo its own mistake.

## 13. Authorization

Authorization is server-side on every endpoint, in three layers:

1. **Form requests** validate input and own the request-level permission check.
2. **`TaxPolicy`** authorizes each action. Registered for **both** `Tax::class`
   and `TaxRate::class` — an unregistered model falls through to "allow", which
   would have made every rate endpoint public.
3. **Controllers** call `$this->authorize(...)` explicitly.

Rate and mapping abilities inherit the configuration capability of the tax they
belong to, on the reasoning recorded in `TaxPolicy`: a bare `10%` discloses
nothing, so granting rate access separately would create a way to learn a
company's effective rate while being unable to see which tax it belongs to.

`is_active` is not an updatable field on either model: a client cannot flip a tax
off by including it in a `PUT`. Lifecycle has its own routes and authorization.

## 14. Company Isolation

- No endpoint accepts a `company_id`. The company comes from the request context.
- `Tax` and `TaxRate` are bound to the active company in `AppServiceProvider`, so
  another tenant's id **404s before any method runs**.
- `taxes` is unique per company, not globally (§3).
- `TaxReportService` filters both line tables by their parent document's company.
- Report requests cannot name a company: `TaxReportRequest` inherits the Phase 6
  base, whose rules include no company field.

Naming a tax id from another company is refused with "does not belong to the
active company", which confirms nothing about whether that id exists elsewhere.

## 15. Tests

122 Phase 10 tests, 472 assertions, plus the full application suite.

| File | Tests | Covers |
|---|---|---|
| `TaxCalculationTest` | 20 | Both bases, multiple components, boundaries, precision, no-persistence |
| `TaxConfigurationTest` | 15 | CRUD, duplicate codes, lifecycle flags, listing/filtering, safe deletion |
| `TaxRateServiceTest` | 15 | Effective dating, precision, overlap handling, posted-document deletion guard |
| `TaxRuleResolutionTest` | 15 | Side scoping, date resolution, empty-list semantics, mappings |
| `TaxAuthorizationTest` | 14 | Capability per role, tenant 404s |
| `TaxDocumentIntegrationTest` | 16 | The document seam: arithmetic, snapshots, posting, journal balance |
| `TaxReportTest` | 14 | Report figures, drafts, windows, unattributed amounts, permissions |
| `TaxLifecycleTest` | 13 | Activate/deactivate for taxes and rates, history preservation |

The tests that found real defects, kept here because they are the useful part:

- an inactive tax was reported as "does not apply to sales transactions", which
  invites the reader to change the tax's type to `BOTH` — corrupting every
  document the tax was ever used on. The two causes are now distinguished, with
  the reasoning recorded at the branch. The original code deliberately conflated
  them to avoid leaking configuration; that reasoning did not survive contact
  with the consequence.
- the line-`tax_id` migration dropped its index before the foreign key, so
  `migrate:rollback` failed. Found by the migration rollback test, not by a
  feature test.
- `TaxService::update()` filtered out nulls, so `description: null` was
  indistinguishable from an absent field and a description could be set once and
  never cleared.
- `TaxRuleResolver` treated an empty collection as falsey, so `tax_ids: []`
  returned *every* applicable tax — a caller asking for no tax would be charged
  all of them.

## 16. Commands Executed

```text
php artisan migrate
php artisan migrate:rollback            # via the migration rollback test
php artisan test --filter=TaxCalculationTest
php artisan test --filter=TaxConfigurationTest
php artisan test --filter=TaxRateServiceTest
php artisan test --filter=TaxRuleResolutionTest
php artisan test --filter=TaxAuthorizationTest
php artisan test --filter=TaxDocumentIntegrationTest
php artisan test --filter=TaxReportTest
php artisan test --filter=TaxLifecycleTest
php artisan test                        # full suite
./vendor/bin/pint --dirty
php artisan route:list
```

Full suite result: **799 tests, 3647 assertions, 0 failures, 0 errors.**
Pint: clean.

## 17. Security Verification

- No endpoint writes on a `calculate` or report path; verified by inspection of
  every service on those paths and by asserting documents are unchanged.
- No user-supplied `company_id` reaches any query; all scoping is the request
  context.
- Policy registered for both tax models, so no endpoint relies on a missing-policy
  fall-through.
- Mass assignment cannot reach `is_active`, `created_by` or `updated_by`.
- Cross-tenant ids are refused without disclosing existence.
- SQL is parameterised throughout; no raw string interpolation into queries.
- Secrets are not logged; no configuration values added.

## 18. Performance Considerations

- Rate resolution loads all candidate rates in **one** query and resolves in
  memory. The obvious implementation issues one query per line per tax; a ten
  line invoice naming two taxes would cost twenty lookups.
- The reports issue exactly **two** queries regardless of how many taxes or
  documents the period contains — one per line table — rather than one per tax.
- `tax_id` is indexed via its foreign key in both line tables.
- All money arithmetic is `bcmath`, which is slower than float and correct, which
  is the intended trade for an accounting system.

## 19. Known Limitations

1. **Inclusive tax is refused on documents.** Explained fully in §7. Supported by
   the calculation endpoint only.
2. **A multi-tax line stores `tax_id = null`.** One column cannot name two taxes,
   so `tax_rate` holds the sum of the components as a presentation aggregate. The
   report shows these amounts as unattributed rather than attributing them
   proportionally.
3. **Attribution is incomplete by design.** Hand-entered rates and multi-tax
   lines are reported as unattributed. This is a fidelity limit, not a bug: no
   schema change can attribute a percentage someone typed into a form.
4. **No jurisdiction model.** No country, region, registration number or filing
   period exists. Reports are aggregates over documents.
5. **The reports do not reconcile to any return.** By design (§11).
6. **No seeders were created**, per the brief's explicit instruction.

## 20. Deferred Features

All of §40's non-goals, none of them started: filing, GST/VAT returns, tax
authority integration, e-invoicing, e-way bills, country-specific compliance
reports, tax payment workflows, tax refund workflows. Also deferred: a
line-level or multi-currency tax model (the engine holds no currency assumption
and introduces none, so §31's future support is not blocked), and full
multi-currency support.

## 21. Requirements Traceability

| § | Requirement | Status |
|---|---|---|
| 7 | Scope of Phase 10 | Met |
| 8 | Database design | Met — 4 additive migrations, company-scoped uniqueness (§3) |
| 9 | Tax type | Met — `TaxType` with side predicates |
| 10 | Calculation method | Met — both bases, one implementation (§7) |
| 11 | Multiple tax components | Met — parallel, never cascading |
| 12 | Rounding | Met — half-up at scale 4, one rounding (§8) |
| 13 | Calculation result | Met — immutable result objects |
| 14 | Calculation service | Met |
| 15 | Tax rule resolution | Met — company/side/date scoped, batched |
| 16 | Tax account mapping | Met |
| 17 | Accounting integration | Met — §10 |
| 18 | Existing transaction integration | Met — lines accept `tax_ids`, Phase 5 flows unchanged |
| 19 | Snapshot / historical integrity | Met — §9 |
| 20 | Posted transaction immutability | Met — snapshots only, deletion guarded |
| 21 | Configuration lifecycle | Met — activate/deactivate routes, §12 |
| 22 | API design | Met — §11 |
| 23 | API security | Met — §17 |
| 24 | Permissions | Met — six permissions, §12 |
| 25 | Authorization | Met — three layers, §13 |
| 26 | Tax reports | Met — summary and by-tax |
| 27 | Report source of truth | Met — posted snapshots, §11 |
| 28 | Existing reporting integration | Partially by design — see §21 note below |
| 29 | Journal integrity | Met — asserted balance and sides in `TaxDocumentIntegrationTest` |
| 30 | Fiscal period rules | Met by reuse — Phase 8 already refuses posting outside a period |
| 31 | Multi-currency readiness | Met — no currency assumption introduced; full support deferred (§20) |
| 32 | Auditability | Met — `created_by`/`updated_by` on all three tables |
| 33 | Database integrity | Met — FKs, indexes, per-company uniqueness (§3) |
| 34 | Performance | Met — §18 |
| 35 | Testing requirements | Met — 122 tests (§15) |
| 36 | Regression testing | Met — 799/799 pass |
| 37 | Code quality | Met — Pint clean |
| 38 | Migration verification | Met — rollback and re-run test passes |
| 39 | Documentation | Met — this report |
| 40 | Explicit non-goals | Met — none attempted (§20) |
| 42 | No unnecessary refactoring | Met — existing callers of `DocumentCalculator` unchanged |
| 43 | Expected service structure | Met |
| 47 | Final report | This document |

**Note on §28.** The brief asks that tax reports reuse existing report
infrastructure rather than create a duplicate framework. They do reuse it:
`TaxReportRequest` extends the Phase 6 `ReportRequest` and inherits its inclusive
date window and ordering check; the route group, response envelope
(`ApiResponse::success`), permission gating and report conventions all match.
`TaxReportService` does not extend `JournalReportService`, because it reads
document lines rather than journal lines — inheriting a class whose protected
helpers query journals it never uses would have been reuse in name only. It also
lives in a separate controller for the reason given in §11.

## 22. Final Status

```text
PASS WITH NOTES
```

**Verification performed.** Migrations applied and rolled back and re-applied;
122 Phase 10 tests pass; the full suite passes at 799/799 with 3647 assertions;
Pint is clean.

The baseline recorded at the start of this phase was 677 tests with one failure,
in bank reconciliation. `BankReconciliationMovementService` filtered
`journal_lines` on two columns that table does not have: a `company_id` of its
own, and a `date`. `journal_lines` deliberately has neither — ownership lives on
`journals.company_id` and the period lives on `journals.journal_date`, so that
both facts have one definition instead of two that could disagree. Filtering on
the missing columns was an SQL error, not an empty result. Both predicates now go
through the journal, matching what `LedgerService::postedLinesQuery` already did.
The failure no longer reproduces.

**Notes qualifying the PASS:**

1. Inclusive tax is refused on documents by design and is supported only by the
   calculation endpoint (§7, §19.1).
2. A line charging multiple taxes cannot attribute itself to a single tax, so
   those amounts appear as unattributed in reports (§19.2).
3. Phase 10 work was not committed on its own; it is delivered as one commit with
   this report, the Phase 10 prompt, and the Phase 9 reconciliation fix.