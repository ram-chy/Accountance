# Multi-Currency & Foreign Exchange Accounting (Phase 14) Implementation Report

Application: Laravel 13 accounting backend (`accounting-app/backend`)
Phase brief: `prompt/PHASE 14 — MULTI-CURRENCY & FOREIGN EXCHANGE ACCOUNTING.md`

> Note on location: §44 of the brief asks for `docs/reports/PHASE_14_REPORT.md`
> and to follow the repository's actual convention. This path is written here,
> matching the eleven sibling reports already in the repository.

> **This phase is complete.** All fourteen implementation steps are done and
> verified. The schema, the currency and rate models, the **journal**, the
> **document** layer (invoice / bill / credit-debit note), **tax base amounts**,
> the **settlement layer including realised FX**, **cash/bank movements**,
> **reporting integration**, **base-currency change safety**, **accounting
> controls**, **authorization**, the **HTTP API surface**, **audit expansion and
> secret scrubbing**, and **concurrency verification** are all built and tested.
> Final suite: **1166 tests / 5190 assertions, 0 failures**. The status is
> **PASS WITH NOTES** (§25), the notes being the deliberately deferred items in
> §24 rather than any unmet acceptance criterion.

> **Progress log.** §26 records what each implementation step changed, so the
> work can be reviewed step by step rather than as one diff.

---

## 1. Phase Objectives

Deliver multi-currency accounting on the existing engine: a currency master,
a company base currency, dated exchange rates with deterministic resolution,
foreign-currency documents that snapshot their rate, settlement in the
document currency, realized FX, and the same company-isolation, authorization,
audit, exact-money and fiscal-period conventions as the previous thirteen
phases.

Explicitly **not** in scope or deferred (§19, §22, §23): unrealized FX
revaluation, cross-currency cash/bank transfers, foreign bank reconciliation,
and fixed-asset FX depreciation.

## 2. Existing Currency Infrastructure Reviewed

Per §3, inspection preceded implementation. What already existed:

| Existing piece | What it decided for Phase 14 |
|---|---|
| `App\Support\Money` | Exact BCMath decimal arithmetic at scale 4. Reused for every converted amount — **no float arithmetic was introduced**. |
| `TaxRateService` + `TaxRate` | The effective-dated reference pattern. `ExchangeRate` and `ExchangeRateService` are built as its sibling: `effective_from` + `latest-effective-on-or-before` resolution. |
| `Tax::rateOn()` | The resolution rule copied, deliberately, so a rate in this phase and a tax rate in Phase 8 resolve identically. |
| `JournalService` / `JournalPostingService` | The single journal path. `journal_lines.debit`/`.credit` remain the **only** base-currency ledger amounts. |
| `AccountingPeriodService` | Posting already refuses a date with no open period; no new fiscal rule was needed. |
| `SchemaCheck` | The named-CHECK helper, extended once (see §4) rather than replaced. |
| `Account::acceptsCurrency()` | The permissive account-currency rule, added to the model in this phase. |
| `CompanyContext` + scoped route bindings | The isolation mechanism, unchanged. |

## 3. Architecture Decisions

**The base ledger is untouched.** `journal_lines.debit` and `.credit` are still
the only authoritative amounts, and they are still in the company's base
currency. The Phase 14 columns (`foreign_debit`, `foreign_credit`,
`exchange_rate`, `currency_id`) are *provenance* on a ledger line, not a second
ledger. This is the decision the whole phase rests on: there is no parallel
foreign-currency balance system to reconcile against, and no report can prefer
one amount over the other because there is only one.

**Rates are dated history, never a mutable current value.** A rate stays in force
until a later effective date replaces it. Consequence: no window exists in which
a date has no rate, and no period-closing of rate ranges is needed — the cost is
a longer rate table. The alternative (editing a current rate) would let two
different base amounts exist for the same posted document on two different days,
with the difference indistinguishable from fraud.

**Conversion is always a multiply, never a divide.** `rate` is stored as units of
`to_currency` per one unit of `from_currency`, and no code path divides by a
rate. Storing a pair the other way round would make a 1,000 USD invoice post as
0.06 IDR — wrong by a factor of 272 million while looking entirely plausible.

**No automatic rate inversion.** Given USD/INR the system does not synthesise
INR/USD. The reciprocal is a derived figure, and a derived figure that behaves
identically to a stored one cannot be told apart from a stored one when they
disagree — and they will disagree, because real markets have spreads. There is
no canonical base pair; pairs are stored the way they would be quoted.

**No seeders, and no default currency.** A currency is created through the
same validated path as everything else, so nothing writes to a financial table
by a route an operator cannot see. A fresh install therefore has no currencies,
and `companies.currency_id` is nullable: a company that has not chosen a base
currency books single-currency documents at an implicit rate of 1. This is a
real trade — it is recorded here rather than hidden (§23).

**Unrealized FX is deferred**, matching the brief's own "only if safely
supported" framing. It requires period-end revaluation mechanics this phase
does not otherwise have, and a revaluation posting would be the first thing in
this codebase to change the carrying value of an already-posted document —
which §30 forbids. Deferring is the safe reading, not the convenient one.

## 4. Database Changes

Ten migrations, `2026_10_09_170000` … `170900`, all verified through a full
rollback/re-run cycle:

| Migration | Contents |
|---|---|
| `170000_create_currencies` | Global master. `code CHAR(3)` unique, precision CHECK `0..4`. |
| `170100_create_exchange_rates` | Company-scoped, dated, `DECIMAL(20,10)`, unique per (company, pair, day), positive-rate CHECK. |
| `170200_create_company_fx_settings` | 1:1 with company. Gain/loss account pair, both-or-neither CHECK. |
| `170300_add_currency_fk_to_companies` | Nullable functional-currency FK. |
| `170400_add_currency_to_accounts` | Nullable restriction, by account type. |
| `170500_add_currency_to_journal_lines` | `currency_id`, `foreign_debit`, `foreign_credit`, `exchange_rate` + FX-consistency CHECK. |
| `170600_add_currency_to_documents` | Invoice/bill/note: `currency_id`, `exchange_rate`, `base_subtotal`, `base_tax`, `base_grand_total`. |
| `170700_add_base_tax_to_document_lines` | Line-level `base_tax_amount`. |
| `170800_add_currency_to_payments` | Receipt/payment FX snapshot + allocation `base_amount`. |
| `170900_add_currency_to_cash_bank_transactions` | FX snapshot + `base_amount`. |

`SchemaCheck` gained one method, `drop()`, because a named CHECK cannot be
dropped with the column it constrains in MySQL. It was needed by all ten
`down()` methods.

### Three schema bugs found and fixed

1. **The journal FX CHECK silently accepted invalid rows.** Written with the
   same one-sidedness idiom the existing `debit`/`credit` check uses —
   `((foreign_debit > 0) <> (foreign_credit > 0))`. For a foreign debit line
   `foreign_credit` is NULL, so `foreign_credit > 0` is NULL, so `(TRUE) <> NULL`
   is NULL, and a CHECK rejects a row only when its expression is **FALSE**, never
   when NULL. The entire right-hand branch collapsed to NULL and NULL is a pass.
   It therefore accepted a base amount contradicting its foreign amount and a rate
   on a line carrying no foreign amount. Rewritten NULL-safely and locked down by
   `JournalLineFxConstraintTest`, which asserts both the accepted and the refused
   shapes — a test that only checked good rows would have passed against the
   broken constraint.
2. **`down()` ordering.** Named CHECKs must be dropped before the columns they
   reference, and FK columns before `dropConstrainedForeignId()`.
3. **Redundant index.** An explicit `companies.currency_id` index duplicates the
   one MySQL already builds for the FK.

## 5. Currency Model

`App\Models\Currency` — global reference data, not company-scoped, because a
currency is a fact about the world rather than about a tenant, and duplicating
rows per company would let two tenants disagree about what USD is.

`CurrencyService` is the strictest-authorized service in the phase, because its
writes are the only ones that affect every tenant.

**There is no `delete()`, only `deactivate()`** — asserted structurally by
test. A currency referenced by a posted document defines what the numbers on
that document *mean*; deleting the row would remove the ability to find out what
it meant without removing the amounts. Deactivation stops a currency being chosen
and leaves it readable, so historical reports survive someone tidying the list.

Code is normalised (trimmed, uppercased, `[A-Z]{3}`). Precision is bounded
0–4: zero is allowed because JPY genuinely has no minor unit, and 4 is the
ceiling because a currency needing more decimal places than the ledger's
`DECIMAL(20,4)` could not express itself and would produce unroundable documents.

## 6. Exchange-Rate Model

`App\Models\ExchangeRate` — deliberately built as `TaxRate`'s sibling. No
`'decimal:10'` cast, for the reason `TaxRate` documents: Laravel passes the raw
driver string through, and `rate()` returns a `Rate` value object so no caller
can do arithmetic on a bare string.

`Rate` (new, `app/Support/Rate.php`) enforces positive values, rejects more
than the configured 10 decimal places except trailing zeros, and exposes
`applyTo()`, `reciprocal()`, `isOne()`. `Money::product()` was added to
`App\Support\Money` for the single-rounding multiply.

`ExchangeRateService` is the single rate authority (brief §34):

- `findRate()` / `rateOrFail()` — one pair, one date.
- `resolveFor()` — a foreign currency against the company's base.
- `resolveMany()` — batched; a fifty-line document resolves in one query, and
  the answers cannot disagree because there is only one query.
- Missing rates are **absent from the map**, never defaulted to 1. Defaulting
  would post a foreign invoice at par — a specific and wrong number that looks
  like a deliberate decision.

**A rate that has priced a document cannot be edited.** The document's own
snapshot is what its journal and totals were computed from; editing the rate row
afterwards would leave the rate table claiming one rate while the documents say
another, with no way to tell which is wrong. Corrections are recorded on a new
effective date. The check matches on currency **and** the exact rate string, so
a pair can still be edited while rows that snapshotted a *different* rate exist —
otherwise the documented correction workflow would be unreachable, because the
erroneous row itself could never be tidied.

## 7. Base-Currency Behavior

`companies.currency_id` is the functional currency.
`company_settings.default_currency_id` is deliberately left unused: two columns
meaning "the company's currency" would eventually disagree, and the loser would
be the one reports read.

`DocumentCurrencyService` requires an explicit `$documentDate` on every
resolution and has **no overload defaulting to today**. A default that is right
99% of the time is exactly the kind of default that silently misstates
backdated documents.

A document explicitly naming the company's *own* base currency is normalised to
base with a NULL rate, not treated as foreign at rate 1 — the two are stored
differently, and a rate of 1 on a journal line claims someone quoted a rate when
nobody did. The stored shape therefore depends on what is *true*, not on which
field the client filled in.

`TransactionCurrency` carries both the transaction currency and the company's
base currency, exposing `effectiveCurrency()`. This distinction was found by a
test failing: comparing the raw transaction currency meant a base-currency
document passed `null` into `Account::acceptsCurrency()`, where `null` means
*"no restriction"*, so the check compared a real currency id against null and
refused every base-currency posting to a currency-restricted account.

## 8. Journal Integration

**IMPLEMENTED (§12–13).**

`JournalService` derives every base amount from a foreign amount and the rate of
the journal's date, refuses a line that claims both a base and a foreign amount,
requires a foreign line to state its foreign side, accepts a supplied rate only
if it matches the rate of record, and normalises an explicitly-named base
currency to a base line with every FX column `NULL`. `JournalPostingService`
revalidates persisted FX against the line's **stored** rate snapshot, so editing
rate history cannot invalidate a correctly drafted entry. A foreign-currency
transaction therefore integrates with the journal engine: `journal_lines.debit`
and `.credit` remain the only base amounts, and the FX columns are provenance.

## 9. Document Integrations

**IMPLEMENTED (§14–16).**

`SalesInvoice`, `PurchaseBill`, `CreditDebitNote` and their line models carry the
FX and base-total columns, casts and relations, and the create/update/posting
services populate them. Each document snapshots `currency_id`/`exchange_rate` on
draft, re-resolves the rate at posting, and writes `base_grand_total` /
`base_tax_total` **read back out of the journal** rather than converted a second
time. A credit/debit note inherits its source document's currency; there is no
request field for it, because a note against a USD invoice *is* in USD.

## 10. Tax Integration

**IMPLEMENTED.**

`base_tax_amount` exists on document lines so the existing Tax engine is reused
rather than duplicated. The posting path distributes it so the per-line parts sum
exactly to the journal's tax leg, and tax reporting reads it, so a foreign tax
figure reconciles to the ledger rather than to a re-conversion.

## 11. Settlement Integration

**IMPLEMENTED (§17–19).**

`CustomerReceipt`, `SupplierPayment` and both allocation models carry the FX
snapshot columns. `PaymentAllocationService` enforces **same-currency
allocation** at draft and again at posting, and each allocation row stores the
**carrying base** it was measured against. Both settlement posting services
compute base amounts and post realised FX as an explicit third line — credited on
receipt, debited on payment, with the sign correctly inverted between the two
directions.

## 12. FX Gain/Loss Implementation

**IMPLEMENTED — the computation is called by both settlement posting paths.**

`RealizedFxService` + `RealizedFxResult` implement the rule:

```
realised gain = base value received  -  base value carried
```

with `gain = settlement base − carrying base`, the balance cleared at its
**carrying** value and FX absorbing the gap:

```
Debit  Bank          1,700,000   (cash, at the receipt's rate)
Credit Receivable   1,650,000   (cleared at the invoice's carrying value)
Credit FX Gain         50,000
```

Clearing the balance at the settlement amount instead would leave a residual
receivable equal to the FX result — an account balance with no invoice behind
it, indistinguishable from a customer who underpaid.

`resolveAccount()` **throws** when `company_fx_settings` has no configured pair.
The alternatives were all worse: skipping the FX line unbalances the journal or
silently absorbs the difference into the receivable; a hardcoded account does
not exist in most charts; posting to the receivable is the residual-balance bug
above.

Signs are tested by asserting the **reconstructed entries**, not the difference,
because a mirrored gain/loss produces entries that pass every structural check
in the system — balanced, non-negative, correct columns — and are completely
wrong.

FX accounts are ordinary `Account` rows (revenue for gain, expense for loss),
not a parallel structure.

## 13. Unrealized FX Status

**DEFERRED**, per §19 and the phase brief's "only if safely supported" framing.

It requires period-end revaluation mechanics this phase does not otherwise
have, and a revaluation posting would be the first thing in this codebase to
change the carrying value of an already-posted document — which §30 forbids.
The realized/posted split means the ledger currently overstates or understates
*unsettled* foreign balances between rate changes. That is a known, accepted
limitation (§23), not an oversight.

## 14. Reporting Integration

**IMPLEMENTED (§21.1).**

Every report whose output contains accounting balances now discloses the base
currency, so a base figure is never presented as if it were unit-free:

- A `base_currency` block (code, name, id — or an explicit `null` when the
  company has not set one) is attached to the Tax, Receivables, Payables, Aging,
  Balance Sheet, Profit & Loss, Counterparty Statement and Cash/Bank reports.
- `JournalReportService`, `GeneralLedgerReportService` and the account-statement
  reports carry the FX provenance on each line — `currency_id`, `currency_code`,
  `exchange_rate`, `foreign_debit`, `foreign_credit` — via the extended
  `JournalLineResource`. `JournalController` eager-loads `lines.currency` so the
  resource does not fire a query per line.
- The customer and supplier statement reports were corrected to read
  `base_amount` for settlement movement instead of the transaction-currency
  `amount`, which was **wrong** for a foreign settlement once `base_amount`
  existed.
- Tax reporting consumes the document-line `base_tax_amount` so a foreign tax
  figure reconciles to the ledger rather than to a re-conversion.

The rule applied throughout: base amounts stay authoritative and foreign columns
appear as provenance only, never as a second balance to reconcile against.

## 15. Audit Integration

**IMPLEMENTED (§30–31).**

`CurrencyService` and `ExchangeRateService` write audit rows: create, update,
activate, deactivate, each via `AuditService` so the existing conventions hold
(no update row when nothing changed; `created_by`/`updated_by` taken from the
authenticated user, never the payload, because an audit row naming whoever the
client said quoted a rate is worthless as evidence).

Realised FX is audited at the point it is posted.
`CustomerReceiptPostingService` and `SupplierPaymentPostingService` now capture
the `RealizedFxResult` from the settlement computation and, **only when the
result is non-trivial** (`! isNone()`), call `AuditService::journalPosted()` for
the settlement journal inside the posting transaction. A settlement at the
carrying rate changes no value and writes no FX audit row — the row marks a
financial event, not every posting.

Secret scrubbing is global, not FX-only (§31). `AuditService` now redacts
cookie and session material (`cookie`, `cookies`, `set_cookie`, `session`,
`session_id`, `csrf_token`, `xsrf_token`, `x_csrf_token`, `x_xsrf_token`) in
addition to credentials and OTP fields, and `scrub()` normalises every key
(lower-casing and folding `-` to `_`) so a header name like `X-CSRF-Token` is
matched by the `x_csrf_token` rule rather than sailing through. Covered by
`AuditSecretScrubbingTest`.

## 16. Control Integration

**IMPLEMENTED (§24).**

`AccountingControlService` is a read-only integrity scanner. `run(Company)`
returns a list of `ControlFinding` (status `Pass` / `Warning` / `Fail`, plus a
code, message and context), currently covering:

- `BASE_CURRENCY` — a company transacting in a foreign currency with no base
  currency configured.
- `BASE_CURRENCY_CHANGE_SAFETY` — posted accounting present, so the base
  currency can no longer be changed safely.
- `FOREIGN_TRANSACTION_RATE` — a foreign document or line whose rate cannot be
  re-derived.
- `INVALID_HISTORICAL_FX_STATE` — a foreign journal line whose stored base and
  foreign amounts fail the FX consistency rule.
- `FOREIGN_SETTLEMENT_ACCOUNTS` — foreign activity with no FX gain/loss accounts
  configured (the `RealizedFxService::canPostForeignSettlement()` seed, now with
  a caller).
- `ACCOUNT_DOCUMENT_CURRENCY` — an account that cannot accept a currency it has
  been asked to hold.

Controls **detect, never repair** (§24): nothing in this service writes. It is
exposed read-only at `GET accounting/controls` behind `accounting.controls.view`,
returning a summary of Pass/Warning/Fail counts alongside the findings.

## 17. Authorization

**IMPLEMENTED (§26–27).**

`PermissionName` gained `accounting.currency.*`,
`accounting.exchange_rates.view/create/update`, `accounting.fx.update` and
`accounting.controls.view`. `config/authorization.php` assigns them by role:
Admin expands to all via `RolePermissionSynchroniser`; Accountant holds the
currency/rate/fx/controls view-and-manage set but **not** currency master
create/update; Manager holds read-only currency/rate/controls plus
`companies.settings.update`; Staff holds none.

Policies are registered explicitly in `AppServiceProvider::configurePolicies()`
(this project never relies on convention discovery):
`CurrencyPolicy`, `ExchangeRatePolicy`, `CompanyFxSettingPolicy`, plus
`CompanyPolicy::viewControls`. The base-currency change is governed by
`updateSettings` on the active company.

The API surface (§28–29) is live: `CurrencyController`,
`ExchangeRateController`, `CompanyFxSettingController`,
`AccountingControlController`, and `CompanySettingsController::changeBaseCurrency`,
with form requests (`app/Http/Requests/Accounting/Currency/`) and API resources
(`CurrencyResource`, `ExchangeRateResource`, `CompanyFxSettingResource`). Routes
sit under the `accounting` prefix plus `PUT company/settings/base-currency`; all
accounting model bindings are scoped to the active company via
`bindAccountingModelsToActiveCompany()`, so a cross-company id resolves to 404
rather than leaking another tenant's row.

## 18. Company Isolation

**IMPLEMENTED.**

Enforced in the service layer, which is this project's convention (no global
scopes). `ExchangeRateService` scopes every resolution by `company_id`, and
`RealizedFxService` reads `company_fx_settings` by company. Tests confirm one
company's rates cannot price another's invoice and one company's FX accounts are
not used by another.

Base-currency changes (§25) are guarded by `CompanyCurrencyService`: a change is
refused when posted accounting exists, when posted foreign lines exist, or when
the target currency is inactive; a first-time assignment from null is allowed;
an unchanged value is a no-op with no audit row. Historical journal lines are
never rewritten.

## 19. Security Review

**PASS WITH NOTES.**

- Currency/rate/FX-setting creation is service-guarded and permission-gated at
  the HTTP layer.
- ID tampering: cross-company currency/rate ids resolve to 404 through the scoped
  route bindings, asserted by `CurrencyApiTest`.
- Posted-record mutation: unaffected; no posted-record behaviour was changed.
- Sensitive data in audit logs: cookie/session/credential payloads are now
  scrubbed globally (§15).
- Rates and amounts never round-trip through a float in any arithmetic path.

## 20. Concurrency Review

**VERIFIED.**

The one-row-per-(company, pair, day) rule is enforced by a **unique index**
(`exchange_rates_pair_day_unique`) rather than a service-level existence check,
because two users saving the same pair on the same day both pass an
application-level check and both insert — after which "the rate on that day" has
two answers. The service translates the unique violation into a field-level
validation message rather than a 500.

`ExchangeRateConcurrencyTest` now pins both halves of that guarantee: a duplicate
insert that reaches the table is rejected by the database with a
`unique`/`23000` violation and leaves exactly one authoritative row, and a
service call that loses the race reaches the caller as a `ValidationException`
on `effective_date` (a 422, not a 500). True wall-clock contention is simulated
by committing the winner directly and racing the loser against it, since the
application holds no lock between the read and the write.

## 21. Tests

**Full suite: 1166 tests / 5190 assertions, 0 failures, 0 errors** (baseline
before this phase's later steps: 1125 / 5050). All Phase 14 work is covered by
feature tests through the service and HTTP layers.

| File | Focus |
|---|---|
| `CurrencyServiceTest` | Normalisation, uniqueness, precision bounds, deactivation-is-terminal, formatting |
| `ExchangeRateServiceTest` | Direction, resolution at/on/before a date, history immutability, batch resolution |
| `DocumentCurrencyServiceTest` | Base normalisation, backdating, account compatibility |
| `RealizedFxServiceTest` | Gain/loss direction, clearing value, account resolution |
| `JournalLineFxConstraintTest` | The NULL-logic CHECK regression (data-provided) |
| `ForeignSettlementTest` | Realised FX sign in both directions, same-currency allocation, and audit rows for FX postings |
| `CompanyCurrencyServiceTest` | Base-currency change safety (posted accounting, foreign lines, inactive target) |
| `AccountingControlServiceTest` | Each integrity check's Pass/Warning/Fail outcome |
| `CurrencyAuthorizationTest` | Role → permission enforcement |
| `CurrencyApiTest` | Currency/rate/FX-setting/controls endpoints, cross-company scoping, 422 validation |
| `ExchangeRateConcurrencyTest` | DB unique index is the authority; lost race is a validation error |
| `AuditSecretScrubbingTest` | Cookie/session/CSRF redaction and key normalisation |

Coverage is honest about its limits: the rate-resolution tests pin the three
failure modes that are invisible in casual use — taking the *oldest* matching
row, taking the row effective *exactly* on the date rather than at-or-before,
and resolving against today instead of the document's date.

## 22. Migration Verification

- `migrate` → all ten applied.
- `migrate:rollback --step=10` → all ten reversed cleanly, in reverse order.
- `migrate` again → all ten re-applied.

Rollback was verified twice during development, once after fixing the CHECK/FK
ordering bugs and once after Pint reformatted the anonymous-class migrations.
The local development database currently has Phase 14 applied.

Pint reformatted all ten migration files to
`return new class extends Migration\n{`, matching the eleven existing
migrations; this was cosmetic only and re-verified by a full cycle.

## 23. Known Limitations

1. **Unrealized FX is not recognised**, so *unsettled* foreign balances are
   carried at their original rate. This overstates or understates them between
   rate changes. This is the deliberate deferral of §19, revisited in §24.
2. **`company_settings.default_currency_id` is unused** and, if it is already
   populated in an existing database, disagrees with
   `companies.currency_id`. Nothing reconciles the two.
3. **No currencies exist on a fresh install** and a company with no base currency
   books at an implicit rate of 1. Intended (§41), but it means "multi-currency
   enabled" is not the same as "correct".
4. **A company may have `currency_id IS NULL` and a base-currency document may be
   posted with no base currency configured** — the Phase 13 behaviour, retained
   deliberately so no existing document changes meaning.
5. `Currency::formatAmount()` casts to `float` for `number_format`. This is
   presentation only and never arithmetic, but it is a float in a codebase whose
   rule is "never calculate with floats".

## 24. Deferred Functionality

| Item | Brief | Reason |
|---|---|---|
| Unrealized FX revaluation | §19 | Needs period-end mechanics; would mutate a posted document's carrying value (§30) |
| Cross-currency cash/bank transfers | §21 | Needs a paired-transfer model the journal engine does not have |
| Foreign bank reconciliation | §22 | Deferred by the brief |
| Fixed-asset FX depreciation | §23 | Deferred by the brief; asset cost basis is single-currency |
| Second direction rate synthesis | §9 | Design decision (§3), not a deferral |

Everything else the brief asks for is implemented; the rows removed from this
table since the earlier draft (form requests/API resources, audit secret
scrubbing, reporting, controls, authorization, base-currency safety,
concurrency) are now delivered and covered by tests.

## 25. Final Status

**PASS WITH NOTES.**

All fourteen implementation steps are complete and verified against a green
suite of **1166 tests / 5190 assertions**. The notes attached to the PASS are
the deliberately deferred items in §24 (unrealized FX, cross-currency transfers,
foreign bank reconciliation, fixed-asset FX depreciation) and the design
decisions in §3 — not unmet acceptance criteria. Nothing is blocked.

**Delivered and verified:**

- 10 migrations, schema + CHECK constraints, verified up/down/up.
- Global currency master with ISO validation, precision bounds, and
  deactivate-as-terminal lifecycle.
- Dated exchange rates with deterministic resolution, exact decimal arithmetic,
  and immutability once a rate has priced a document.
- Base-currency behaviour with explicit-date resolution and base/foreign
  normalisation.
- Realized FX computation with the correct sign and clearing value, and a hard
  refusal to post without configured FX accounts.
- **Journal FX (§12–13).** `JournalService` derives every base amount from a
  foreign amount and the rate of the journal's date; refuses a line that claims
  both a base and a foreign amount; requires a foreign line to state its foreign
  side; accepts a supplied rate only if it matches the rate of record;
  normalises an explicitly-named base currency to a base line with every FX
  column `NULL`. `JournalPostingService` revalidates persisted FX against the
  line's **stored** rate snapshot, so editing rate history cannot invalidate a
  correctly drafted entry.
- **Document FX (§14–16).** Invoice, purchase bill and credit/debit note each
  snapshot `currency_id`/`exchange_rate` on draft, re-resolve at posting, and
  write `base_grand_total`/`base_tax_total` **read back out of the journal**
  rather than converted a second time. Per-line `base_tax_amount` is distributed
  so the parts sum exactly to the journal's tax leg.
- **Settlement FX (§14–19).** Receipts and payments snapshot their currency and
  rate, refuse a payment account that cannot hold the currency, and enforce
  **same-currency allocation** at draft and again at posting. Each allocation row
  stores the **carrying base** it was measured against. Realised FX posts as an
  explicit third line — credited on receipt, debited on payment, with the sign
  correctly inverted between the two directions.
- **Cash/bank FX (§20).** Deposits, withdrawals and transfers snapshot a single
  currency and rate for the whole movement, re-resolve that rate at posting, and
  book both legs in the transaction currency with the journal deriving the base side.
  An account that declares a currency is refused if it does not match, on the cash
  side and the offset side alike. A cross-currency transfer is refused outright.
- **Reporting integration (§21).** Base-currency disclosure on every
  balance-bearing report; FX provenance columns in the journal, GL and statement
  reports; `base_amount` used for foreign settlement movement; `base_tax_amount`
  consumed by tax reporting.
- **Base-currency change safety (§25).** `CompanyCurrencyService` refuses an
  unsafe change and never rewrites history; a first-time assignment is allowed
  and an unchanged value is a silent no-op.
- **Controls (§24).** `AccountingControlService` scans for six currency/FX
  integrity faults and reports them read-only.
- **Authorization and API (§26–29).** Permissions, roles, policies, form
  requests, resources, controllers and company-scoped routes; cross-company ids
  resolve to 404.
- **Audit and secret scrubbing (§30–31).** Realised FX postings audited inside
  the posting transaction; cookie/session/credential payloads scrubbed globally
  with key normalisation.
- **Concurrency (§32).** The unique index is proven to be the enforcement point,
  and a lost race is proven to surface as a validation error, not a 500.
- Full suite **1166 tests / 5190 assertions** green; Pint clean repo-wide; no
  seeders; no frontend; no second journal engine.

**Not outstanding:**

- ~~Reporting (§21–23)~~ — **Done** (§14).
- ~~Controls (§24)~~ — **Done** (§16).
- ~~Authorization and API surface (§26–28)~~ — **Done** (§17).
- ~~Base-currency change safety (§25)~~ — **Done** (§18).
- ~~Concurrency verification (§32)~~ — **Done** (§20).
- ~~Audit expansion and secret scrubbing (§30–31)~~ — **Done** (§15).

**Recommendation:** the phase can be treated as delivered. The remaining items
are the deliberately deferred ones in §24 and should be confirmed against a
future phase brief rather than assumed. Because the schema is additive and
reversible and no existing base-currency document changes meaning — asserted by
regression tests, not merely intended — deploying the FX schema to an
environment holding real data is safe.

## 26. Progress Log

Ordered by implementation step, so the work can be reviewed step by step.

| Step | Scope | State | Key files |
|---|---|---|---|
| 1 | Repository audit, baseline suite | Done | — |
| 2 | Journal FX (§12–13) | Done | `JournalService`, `JournalPostingService`, `Currency/DocumentCurrencyService` |
| 3 | Document + tax snapshot FX (§14–16) | Done | `SalesInvoiceService`, `SalesInvoicePostingService`, `PurchaseBill*`, `Notes/CreditDebitNote*` |
| 4 | Tax base amounts | Done (folded into step 3) | — |
| 5 | Settlement + realised FX (§17–18) | Done | `PaymentAllocationService`, `CustomerReceipt*`, `SupplierPayment*`, `Currency/RealizedFxService` |
| 6 | Cash/bank FX (§20) | Done | `Accounting/CashBank/*`, `TransactionAccountResolver` |
| 7 | Reporting (§21–23) | Done | `Accounting/Reports/*`, `JournalLineResource`, `JournalController` |
| 8 | Base-currency change safety (§25) | Done | `Accounting/Currency/CompanyCurrencyService` |
| 9 | Controls (§24) | Done | `Accounting/Controls/AccountingControlService`, `ControlFinding` |
| 10 | Authorization (§26–27) | Done | `Enums/PermissionName`, `config/authorization.php`, `Policies/*` |
| 11 | Form requests, resources, routes (§33) | Done | `Http/Requests/Accounting/Currency/*`, `Http/Resources/*`, `Http/Controllers/Api/Accounting/*`, `routes/api.php` |
| 12 | Audit expansion + secret scrubbing (§30–31) | Done | `Services/Audit/AuditService`, `Sales/CustomerReceiptPostingService`, `Purchasing/SupplierPaymentPostingService` |
| 13 | Concurrency verification (§32) | Done | `tests/Feature/Currency/ExchangeRateConcurrencyTest` |
| 14 | Final regression + Pint | Done — full suite 1166/5190 green, Pint clean | — |
| 15 | This report | Done | — |

### Design decisions taken during steps 2–5

Recorded here because each one is a fork the brief does not settle, and each was
a deliberate choice rather than an accident.

1. **Documents convert foreign → base only; the server derives base amounts.**
   Clients supply foreign amounts to `JournalService`, which derives the base
   side. A payload carrying both is rejected rather than reconciled.

2. **Rounding:** aggregate foreign by account before conversion. If independently
   converted legs differ by a minor unit and no longer foot, posting is **refused**
   rather than absorbing the difference. `base_tax_amount` cannot be converted per
   line (a `CHECK` enforces `base = foreign × rate`), so all but the last taxable
   line convert at the document rate and the last receives the remainder.

3. **A credit/debit note always inherits its source document's currency.**
   There is no request field for it. A note against a USD invoice *is* in USD;
   allowing a free choice would credit a USD receivable with a base-currency
   document and split one customer balance across two currencies.

4. **A note is priced at its own date's rate, not its source's.** Pricing it at
   the invoice's rate would balance and quietly destroy the difference before
   realised FX could measure it at settlement.

5. **Settlement must be in the same currency as what it settles.** A USD receipt
   does not discharge an IDR invoice; the amounts compare as bare numbers, so the
   outstanding check would pass and both sub-ledgers would end up wrong.
   Converting instead would have to invent a rate the parties never agreed.

6. **The receivable/payable leg of a foreign settlement is a BASE amount, at the
   carrying rate.** The clearing leg cannot be a foreign line: the rate it needs is
   the *document's*, and the only rate a journal can carry is the one resolved for
   its own date. This required one deliberate change to an existing guard — see
   below.

7. **Base-currency journal lines are accepted on currency-restricted accounts.**
   `DocumentCurrencyService::assertAccountAccepts` now returns early for a
   base-currency context. The restriction governs how an account may be
   *denominated*, and a base line makes no claim about denomination.
   `JournalService::assertPersistedFxValid` already behaved this way, so the draft
   path was the inconsistent one; foreign settlement is what made the difference
   load-bearing rather than tidy.

8. **Realised FX sign inverts between directions.** `RealizedFxService` is defined
   for money coming *in* (gain = base received − base carried). A payment is base
   *paid*, so `SupplierPaymentPostingService` passes the arguments the other way
   round rather than negating the result — which lets both services keep the single
   rule "gain credits, loss debits". Both directions have dedicated tests, because
   the entry balances either way and only the sign reveals the error.

9. **Documents read their base totals back out of the journal.** A second
   conversion would agree today and diverge the first time a rounding adjustment
   was added to one of the two paths.

10. **A company must name its FX gain/loss accounts before it can post a foreign
    settlement.** The refusal is whole: booking the cash and the receivable while
    failing to book the difference would leave the journal short by an amount
    nobody could name.

11. **A cash/bank movement is the one foreign document with no FX line.** Both its
    legs are the same money on the same day, so it converts once and lands
    identically on both sides. There is no difference to book and therefore no gain
    to post. A third line on such an entry would be an FX result the company did
    not have, which is the same error the settlement posting avoids in the opposite
    direction - there, a missing line loses real money; here, an extra line invents
    it.

12. **The cross-currency transfer is refused with instructions, not silently.**
    `TransactionAccountResolver::assertSameDenomination()` lives in the resolver
    rather than in either service, because both the draft service and the posting
    service must apply it and a posting service's re-validation is only meaningful
    if there is one rule to re-validate against. It is checked *before* the
    per-account currency check so the user is told the real problem - these two
    accounts cannot move money between each other - rather than a message about one
    side of it.

13. **The currency restriction is re-checked on every update, unconditionally.**
    Gating it on `currency_id` or `transaction_date` having changed is the bug that
    matters here, because the partial edit that produces an invalid pair is
    precisely one where neither was touched: swapping a USD bank account for a EUR
    one leaves the currency alone. The extra query on an unchanged update buys one
    code path instead of two, and "which fields matter" is exactly where the next
    missed case would live.

### Design decisions taken during steps 7–13

14. **Reporting discloses the base currency rather than labelling amounts.** A
    base figure with no currency context is the specific failure §21.1 guards
    against, so every balance-bearing report carries a `base_currency` block
    (explicitly `null` when the company has none) instead of assuming the reader
    knows it.

15. **The customer/supplier statements were fixed, not patched.** They read
    `Money::of($receipt->amount)` — the transaction-currency figure — which was
    correct only while every settlement was base. With `base_amount` present the
    correct source is `base_amount`, so the report reads it rather than converting.

16. **A base-currency change is refused, never re-written.** `CompanyCurrencyService`
    refuses the change when posted accounting or posted foreign lines exist, and
    only allows the first-time assignment from `NULL`. Re-expressing historical
    journals at a new base would change the meaning of every posted document
    (§30), so the safe failure is the correct one. An unchanged value is a no-op
    that writes no audit row.

17. **Controls detect, they never repair.** `AccountingControlService` returns
    findings and writes nothing, so a scan can be run at any time without
    side-effects. Auto-repair would be a silent mutation of financial history on
    the word of a heuristic.

18. **The realised-FX audit row marks a financial event, not every posting.** The
    two settlement services call `journalPosted()` only when the `RealizedFxResult`
    is non-trivial. A settlement at the carrying rate realises nothing and would
    otherwise fill the audit trail with rows that record no event.

19. **Secret scrubbing normalises keys.** Matching redaction rules against raw keys
    missed header-style names (`X-CSRF-Token`); `scrub()` now lower-cases and folds
    `-` to `_` before matching, so cookie/session/CSRF material is caught however
    it is spelled.

20. **The unique index, not the service, owns rate uniqueness.** `ExchangeRateConcurrencyTest`
    races a loser against a committed winner to prove the database rejects the
    duplicate and that the resulting `QueryException` is translated to a
    field-level `ValidationException` — a 422 a person can act on rather than a
    500.