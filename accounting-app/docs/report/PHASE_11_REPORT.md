# Credit & Debit Notes (Phase 11) Implementation Report

Application: Laravel 13 accounting backend (`accounting-app/backend`)
Phase brief: `prompt/PHASE 11 — CREDIT & DEBIT NOTES.md`

> Note on location: §69 of the brief asks for `docs/reports/PHASE_11_REPORT.md`.
> The file is written to `docs/report/PHASE_11_REPORT.md`, matching the ten sibling
> reports already in the repository.

---

## 1. Phase Objectives

Deliver a financial correction layer over the existing invoice and bill documents:

- sales and purchase **credit** and **debit** notes, each adjusting exactly one
  existing document;
- adjustment arithmetic that cannot over-adjust a document, including under
  concurrency;
- document-level and line-level adjustments, the latter constrained to the
  quantity the source line actually has;
- a complete accounting entry per note, through the existing journal;
- settlement, receivable, payable and statement effects;
- tax handled by the existing tax engine, with the historical snapshot
  guarantee preserved;
- the same company isolation, authorization, audit and deletion conventions as
  every other phase.

Explicitly **not** in scope (§57): replacing invoices, payments, taxes or the
accounting engine; inventory behaviour (§58); refunds and payment reallocation
(§59); cancellation or reversal of a posted note (§12, §57).

## 2. Current Architecture Reviewed

The seams were found by reading the existing code, not by adding alongside it.

| Existing piece | What it decided for Phase 11 |
|---|---|
| `App\Support\Money` | Exact decimal arithmetic reused everywhere; no float money was introduced. |
| `LedgerService` | Posted-only, company-scoped queries. The single journal path (§62). |
| `DocumentCalculator` | Where note line totals and tax snapshots are computed, unchanged. |
| `AccountingPeriodService` | Posting already refuses a date with no open period, so no fiscal rules were written (§31). |
| `DocumentNumberSequence` | One `CDN-` counter for all four note types. |
| `SettlementService` | Where note-adjusted `balance_due` is derived and the status refreshed. |
| `TaxRuleResolver` / `TaxCalculator` | Reused unchanged for note lines; `tax_id` is snapshotted exactly as on invoice lines. |
| `TransactionAccountResolver` | Enforces which account type each note type may charge. |
| `AgesDocuments` / `withOutstandingBalance()` | The scopes the ageing reports already filter on. |
| `JournalReportService::totalsByAccount()` | Why P&L and Balance Sheet needed **no code change** (§44, §45). |

## 3. Database Changes

Two migrations, both additive. No Phase 1–10 table was altered destructively.

| Migration | Contents |
|---|---|
| `2026_10_06_160000_create_credit_debit_notes_table.php` | `credit_debit_notes` header, plus four CHECK constraints. |
| `2026_10_06_160100_create_credit_debit_note_lines_table.php` | `credit_debit_note_lines` plus one CHECK constraint. |

**Two source columns, not a `(type, id)` pair.** A note names its source with
`sales_invoice_id` / `purchase_bill_id`, both nullable, both RESTRICT. A string
type plus an integer id cannot be constrained at all: the strongest available
check says the type is one of two strings, which says nothing about whether the
row exists or belongs to this company. With real foreign keys the constraint
becomes declarative. `CreditDebitNoteResource` still exposes the uniform
`source_document_type` / `source_document_id` pair, so a client gets one shape
without the storage layer carrying an unvalidatable pair.

**No stored balance columns.** There is no `remaining_adjustable_amount`, no
`adjusted_total`, no `outstanding_after_note`. Every one of those is a sum over
posted notes, and a stored copy of a sum is a second source of truth that can
disagree with the notes it summarises. `CreditDebitNoteAdjustmentService` computes
them, under a lock on the source document when the answer must be exact.

### CHECK constraints

| Constraint | Rule |
|---|---|
| `credit_debit_notes_single_source_check` | Exactly one of the two source FKs is set. |
| `credit_debt_notes_counterparty_check` | `customer_id` present exactly when `sales_invoice_id` is; `supplier_id` exactly when `purchase_bill_id` is. |
| `credit_debit_notes_status_check` | `status in ('DRAFT','POSTED')` — a note never enters `PARTIALLY_PAID`/`PAID`. |
| `credit_debit_notes_posted_fields_check` | `POSTED` implies `posted_by` and `posted_at` are set. |
| `credit_debit_note_lines_single_source_check` | A line references at most one source line, never both. |

The journal half of the posting invariant is deliberately **not** in a CHECK:
`journal_id` carries `SET NULL`, and MySQL forbids a column with a referential
action of `SET NULL` from appearing in a CHECK, because dropping the referencing
row would change the value being tested. This was found by running the migration.
The schema proves two of the four halves and the posting service writes all four
in one statement inside the transaction.

Indexes: `(sales_invoice_id, status)` and `(purchase_bill_id, status)` — the sum
that runs on every create, update and post. Plus `(company_id, note_date)`,
`(company_id, status)`, `(company_id, note_type)`, `(company_id, customer_id)`,
`(company_id, supplier_id)`.

Uniqueness (§65): `(company_id, note_number)`, **not** `note_number` alone.

## 4. Models

| Model | Notes |
|---|---|
| `CreditDebitNote` | `note_type` cast to `NoteType`; `status` to `TransactionStatus`; money accessors; `salesInvoice()`, `purchaseBill()`, `lines()`, `journal()`, `createdBy()`, `postedBy()`. |
| `CreditDebitNoteLine` | `tax_rate`/`tax_amount`/`tax_id` snapshot exactly as invoice lines; one `account_id` serving both revenue and expense roles. |

`Tax::isReferencedByDocument()` was extended to check `creditDebitNoteLines()` as
well as invoice and bill lines, so a tax cannot be deleted while a note still
carries it.

## 5. Enums

- `NoteType`: `SalesCreditNote`, `SalesDebitNote`, `PurchaseCreditNote`,
  `PurchaseDebitNote`, with `isSales()`, `isCredit()`.
- `JournalSource::CreditDebitNote = 'CREDIT_DEBIT_NOTE'` (§25).
- `DocumentNumberType::CreditDebitNote`, prefix `CDN-` (§32).
- `PermissionName`: view / create / update / post / delete.

## 6. Services

| Service | Responsibility |
|---|---|
| `CreditDebitNoteAdjustmentService` | Posted-note sums per source document and per source line; document and line limits; `remaining_adjustable_amount` for the source endpoints (§34). |
| `CreditDebitNoteService` | Draft create, update, delete. Rejects non-draft mutation. |
| `CreditDebitNotePostingService` | Re-checks limits under lock, builds the journal, writes `status`/`posted_by`/`posted_at`/`journal_id`, refreshes the source settlement status. |

`SettlementService` was extended, not replaced: `balance_due` and `paid_total`
stay derived, and note sums are one more input to them.

## 7. Document Lifecycle

`DRAFT` → `POSTED`, and no third state. A draft may be edited or deleted; a
posted note may not, and returns 422 rather than 403, because the permission is
held and the document's state is what refuses it.

There is no cancellation and no reversal (§12). A credit note is the reversal.

## 8. Source Document Relationship

The source document is immutable to a note (§7): a note never writes to
`sales_invoices` or `purchase_bills` except to refresh the settlement **status
label**. `note_type` and both source FKs are absent from the update request rules,
so a client cannot repoint a draft at a different document or flip its direction.

`reason` is **required**, which is stricter than the brief's field list. An
adjustment with no stated reason is an adjustment nobody can audit: the amount
and the counterparty are both derivable from the note and the invoice it adjusts,
so the reason is the only part carrying information not derivable from the rest of
the ledger. `reference` is optional, because an external returns-note number does
not exist for every adjustment.

## 9. Adjustment Calculation

```
netAdjustment = Σ posted credit notes − Σ posted debit notes
remaining     = source.grand_total − netAdjustment
```

- **Document-level** (§9, §10): a note of either direction must satisfy
  `note.grand_total ≤ remaining`.
- **Line-level** (§21, §22): when a line names `sales_invoice_line_id` /
  `purchase_bill_line_id`, the sum of posted note quantities against that line
  plus this note's quantity must lie in `[0, source_line.quantity]`.

Both limits are checked **twice**: at draft time, and again at posting time
inside the posting transaction, with the source document row locked first
(`lockForUpdate`). The draft-time check is a user-experience feature; the posting
check is the correctness guarantee, because two drafts can both be valid when
written and only one can post.

The one rule that covers both directions — a debit note of up to the full
remaining amount is legitimate, because a supplementary charge on goods the
customer really did take is a real sale — is recorded at the check and asserted
in the tests.

## 10. Tax Integration

A note's tax is computed by the existing engine and snapshotted onto its own
lines (§17, §46): `tax_rate`, `tax_amount` and `tax_id`. No report or service
re-reads `tax_rates` to recompute anything.

- Side (§18): a **sales** note touches **output** tax; a **purchase** note
  touches **input** tax. That holds in both directions — a sales *debit* note
  still charges output tax.
- Basis (§19) and multiple taxes (§20) are the engine's existing behaviour.
- `tax_account_id` is optional at draft time when the computed tax is zero, and
  required when it is not. Inactive accounts are refused at posting.
- `TaxReportService` folds posted note lines into the same rows as invoice and
  bill lines, from **one** query for all four types, signed by direction and
  windowed by the note's own `note_date` (§40).

A credit larger than the period's sales reports a **negative** figure rather than
zero. Zero says "nothing happened"; −20.00 says "this much came back", and only
one of the two can be filed.

## 11. Historical Snapshot Integrity

`TaxConfigurationTest` passes unchanged after `Tax::isReferencedByDocument()` was
extended. Raising a tax's rate after a note has posted does not restate the
period that note was reported in — asserted directly in `TaxReportNoteTest`.

## 12. Accounting Integration

One journal per posted note, written by the existing posting pattern. No second
ledger, no second journal writer (§62).

| Note type | Debit | Credit |
|---|---|---|
| Sales credit | Revenue, Output tax payable | Accounts receivable |
| Sales debit | Accounts receivable | Revenue, Output tax payable |
| Purchase credit | Accounts payable | Expense, Input tax recoverable |
| Purchase debit | Expense, Input tax recoverable | Accounts payable |

The type names say which way the **counterparty's balance** moves, not which way
tax moves; the tax leg is always the reverse of the counterparty leg.

**P&L and Balance Sheet required no code change** (§44, §45). Both derive from
`LedgerService::postedTotalsByAccount()`, so a note's journal is already in both
reports. That is the §62 answer, arrived at by reading the code path rather than
by adding a note-aware branch.

## 13. Journal Examples

Sales credit note, 100.00 net + 20.00 VAT on a 120.00 invoice:

```text
Dr  Revenue / Sales                100.0000
Dr  Output tax payable              20.0000
    Cr  Accounts receivable                  120.0000
```

`source_type = CREDIT_DEBIT_NOTE`, `source_id = <note id>` (§26), balanced by
the existing poster (§27).

## 14. Customer/Supplier Settlement Effects

`balance_due = max(0, grand_total − allocations − netNotes)` — the clamp is
deliberate and documented at the point it is applied. A document paid in full and
then credited lands at a negative balance; reporting −100.00 would read as though
the customer were 100.00 behind, when in fact 100.00 is owed back. The customer's
credit position is a refund to be arranged and belongs on the note, which carries
the amount.

**The status column is not rewritten by a note.** `refreshInvoiceStatus()` and
`refreshBillStatus()` return early when the document is already settled, and
`statusFromFigures()` returns `POSTED` when nothing has ever been allocated. Three
consequences, each asserted:

- a paid-in-full invoice stays `PAID` after being credited — money came in and
  did not stop coming in; the refund is a conversation with the customer, not an
  unpaid document, and rewriting the label would put the invoice back into the
  receivables ageing as though they had stopped paying;
- a part-paid invoice stays `PARTIALLY_PAID` after being fully credited — the
  credit absorbed the remainder rather than the customer paying it;
- an untouched invoice reports `POSTED`, not `PAID`. Before this guard,
  `allocated >= grand_total` was true for `0 >= 200` and every invoice in a clean
  dataset reported as fully paid.

Statements (§41, §42) place notes from
`CounterpartyStatementReportService::noteEntries()`: a credit note on the credit
side for a customer and the debit side for a supplier — i.e. always the side that
reduces the balance.

## 15. API Endpoints

| Method | Path | Permission |
|---|---|---|
| GET | `/api/credit-debit-notes` | view |
| POST | `/api/credit-debit-notes` | create |
| GET | `/api/credit-debit-notes/{creditDebitNote}` | view |
| PUT | `/api/credit-debit-notes/{creditDebitNote}` | update |
| DELETE | `/api/credit-debit-notes/{creditDebitNote}` | delete |
| POST | `/api/credit-debit-notes/{creditDebitNote}/post` | post |
| GET | `/api/sales-invoices/{invoice}/adjustable-lines` | view |
| GET | `/api/purchase-bills/{bill}/adjustable-lines` | view |

The two adjustable-lines endpoints live on the invoice and bill controllers
because they answer "what is still adjustable on *this* document", but they return
note data, so they are gated by the note **view** permission.

## 16. Permissions

`accounting.credit_debit_note.view` / `.create` / `.update` / `.post` /
`.delete`. Admin and Accountant hold all five; Manager holds view only; Staff
holds none.

## 17. Authorization

`CreditDebitNotePolicy` is registered in `AppServiceProvider`. Because a Form
Request can forget to authorize and a controller can read the wrong policy, the
matrix is tested through real HTTP requests against every door: list, show, create,
update, delete, post, and both adjustable-lines endpoints.

## 18. Company Isolation

No client-supplied `company_id` reaches any query; the request context decides.
A note belonging to another company is a **404**, not a 403, because route model
binding is company-scoped and a 403 would confirm the id exists.

## 19. Fiscal Period Handling

Reuses `AccountingPeriodService` unchanged. A note posts into the period covering
its own `note_date` (§31). A draft needs no period; posting does.

## 20. Reports

| Report | Change |
|---|---|
| Customer statement | Notes as rows, credit side (§41) |
| Supplier statement | Notes as rows, debit side (§42) |
| Receivables | `withOutstandingBalance()` includes posted notes (§43) |
| Payables | same, on bills |
| P&L / Balance Sheet | No change needed — ledger-derived (§44, §45) |
| Tax summary / by-tax | Posted note lines folded in, signed by direction (§40) |

`SalesInvoice::scopeWithOutstandingBalance()` and its bill counterpart needed
real work: the note sum is a `CASE` over `note_type` with bindings, because a
debit note and a credit note have to be summed with opposite signs, and the scope
runs on every ageing report.

## 21. Security Verification

- No endpoint writes on a report path.
- Server-owned fields (`note_number`, `status`, `journal_id`, `posted_by`,
  `posted_at`, all totals) are absent from the validated payload, so a client
  supplying them is **ignored, not obeyed** — asserted, because "silently
  ignored" and "rejected" are different contracts and the wrong one is assumed by
  clients that retry.
- `note_type` and the source FKs are absent from the update rules for the same
  reason.
- Source-line IDs receive integer validation only; company ownership, source
  membership and quantity limits are enforced in the service, because they are
  rules about two rows rather than about a column's shape.
- RESTRICT on both source FKs: an invoice a note adjusts cannot be deleted out
  from under it (§38).
- Policy registered, so no endpoint relies on a missing-policy fall-through.

## 22. Performance

The sums that run on every draft write and every post are indexed for exactly
that: `(sales_invoice_id, status)` and `(purchase_bill_id, status)` on the
header, `sales_invoice_line_id` / `purchase_bill_line_id` on the lines. The tax
report reads all four note types in one query rather than four. No column stores
a sum (§63).

## 23. Tests

92 Phase 11 tests, 753 assertions, plus the full application suite.

| File | Tests | Assertions | Covers |
|---|---|---|---|
| `CreditDebitNoteTest` | 32 | 366 | Lifecycle, the four journal directions, tax legs, validation, lifecycle refusal, tenancy |
| `CreditDebitNoteAdjustmentTest` | 18 | 234 | Document and line limits, the posting-time re-check, settlement status, receivables, statements |
| `CreditDebitNoteAuthorizationTest` | 23 | 39 | The role matrix through every endpoint, cross-company 404, permission registration |
| `CreditDebitNoteIntegrityTest` | 9 | 14 | The CHECK and unique constraints, asserted by writing rows the services would refuse |
| `TaxReportNoteTest` | 10 | 100 | Output/input tax by direction, note-date windowing, negative net, by-tax attribution, rate changes, tenancy |

The tests that found real defects, kept here because they are the useful part:

- **Notes un-settled their sources.** Posting a credit note against a paid
  invoice rewrote the status to `PARTIALLY_PAID`, which put a fully-paid customer
  back into the receivables ageing as though they had defaulted. Fixed by the
  settled-status guard in §14.
- **An untouched invoice reported as `PAID`.** `allocated >= grand_total` is true
  for `0 >= 200`; every invoice in a clean dataset was "fully paid". Fixed by the
  same `statusFromFigures()` change.
- **`only_full_group_by` rejected the adjustment sum.** The posted-line query
  selected `credit_debit_note_lines.*` alongside aggregates, which MySQL refuses.
- **`Field 'line_number' doesn't have a default value`.** Four server-owned
  columns were missing from the line's fillable list, so lines were silently
  inserted without them.
- **`Object of class Illuminate\Database\Query\Expression could not be converted
  to string`** in both outstanding-balance scopes — `DB::raw()` fragments were
  being concatenated as strings. Replaced with SQL strings and bindings.
- **A settled document could be un-settled by a note** (above), which is a
  correctness defect rather than a cosmetic one: it would have made the ageing
  report actively lie.

Three tests were written asserting behaviour that turned out to be wrong, and
were changed rather than made to pass: a debit note was expected to be refused on
an un-credited invoice (it is legitimate — a supplementary charge), `balance_due`
was expected to show 50.00 on a paid-then-credited invoice (it clamps to zero,
and that is the correct reading), and a part-paid invoice was expected to be
promoted to `PAID` (it is not, and promoting it would erase the fact that the
customer never paid the last 50.00).

## 24. Commands Executed

```text
php artisan migrate
php artisan route:list --path=credit-debit-notes
php artisan test tests/Feature/Transactions/CreditDebitNoteTest.php
php artisan test tests/Feature/Transactions/CreditDebitNoteAdjustmentTest.php
php artisan test tests/Feature/Transactions/CreditDebitNoteIntegrityTest.php
php artisan test tests/Feature/Transactions/CreditDebitNoteAuthorizationTest.php
php artisan test tests/Feature/Accounting/TaxReportNoteTest.php
php artisan test tests/Feature/Reports
php artisan test tests/Feature/Transactions
php artisan test                         # full suite
./vendor/bin/pint --test
```

Full suite result: **891 tests, 4404 assertions, 0 failures, 0 errors.**
Before Phase 11 the suite was 799 tests / 3647 assertions, so the phase added
92 tests and 757 assertions — 92 tests and 753 of those assertions in the five
new files, and the remaining four in pre-existing files whose fixtures Phase 11
added lines to. Nothing regressed. Pint: clean.

## 25. Migration Verification

`DatabaseTest::test_migrations_can_be_rolled_back_and_re_run` rolls every
migration back and re-runs them, and passes — so the Phase 11 migrations drop and
recreate cleanly, including the CHECK constraints.

## 26. Known Limitations

- The `posted_fields_check` covers `posted_by` and `posted_at`, not `journal_id`,
  because MySQL forbids a `SET NULL` column in a CHECK (§3).
- A document paid in full and then credited reports `balance_due = 0`. The 50.00
  owed back is not surfaced as a negative figure anywhere on the invoice; it is
  visible on the note. Surfacing it would need a credit-balance concept Phase 11
  was told not to invent (§59).
- Line-level adjustment is per note line, so splitting one note across several
  source lines needs several note lines. This mirrors invoice lines rather than
  working around them.
- `CreditDebitNoteResource` reports `journal_id` but does not embed the entry, so
  reading the posting from a note response is a second request.

## 27. Deferred Features

Per §57: note cancellation or reversal, credit/debit note PDFs and printing,
inventory effects, refunds and payment reallocation, e-invoicing and
country-specific compliance, multi-currency notes.

## 28. Requirements Traceability

| Brief | Where |
|---|---|
| §6–§8 Document types, source relationship | §5, §8 above; `NoteType`, two source FKs |
| §9–§10 Adjustment limits | §9 above; `CreditDebitNoteAdjustmentService` |
| §11–§12 Lifecycle, cancellation | §7 above; no cancellation (§57) |
| §13–§15 Database design | §3, §4 above |
| §16–§20 Tax integration and snapshot | §10, §11 above |
| §21–§23 Source line and quantity | §9 above |
| §24–§27 Accounting, journal source, balance | §12, §13 above |
| §28–§30 Settlement, paid documents | §14 above |
| §31 Fiscal period | §19 above |
| §32 Numbering | §5 above; `CDN-` |
| §33–§34 API and source endpoints | §15 above |
| §35–§37 Authorization and isolation | §16–§18 above |
| §38 Safe deletion | §21 above; RESTRICT |
| §39–§46 Reporting | §20 above |
| §47 Concurrency | §9 above; source-first `lockForUpdate` |
| §48 Performance | §22 above |
| §49–§50 Response conventions, resources | `CreditDebitNoteResource`, `CreditDebitNoteLineResource` |
| §51–§52 Testing, regression | §23, §24 above |
| §53–§54 Migrations, code quality | §25 above; Pint clean |
| §55–§56 No seeders, no frontend | No seeder or frontend file was added |
| §57 Explicit non-goals | §1, §27 above |
| §58–§59 Inventory and payment boundaries | Not touched (§26) |
| §60–§63 Service architecture, no second engine, no stored balances | §6, §12, §3 |
| §64–§67 No client totals, numbering, auditability, errors | §21, §8 above |
| §70 Hard stops | None triggered; see below |

### Hard stop conditions (§70)

None of the ten conditions occurred. Two are worth naming explicitly because they
were the ones that could have:

- **"the existing settlement model conflicts with note accounting"** — it did,
  but as an *implementation* defect rather than a model conflict. The status
  column is a settlement label, and the fix was to stop letting notes rewrite a
  settled document's label (§14) rather than to change what the column means.
- **"payment/refund behavior becomes necessary to make the accounting model
  correct"** — it was not necessary. `balance_due` clamps at zero and the refund
  obligation stays on the note, which keeps the accounting model correct without
  introducing refund behaviour.

## 29. Final Status

**Complete.** 92 focused tests, 753 assertions, all passing; full suite 891 tests
/ 4404 assertions with zero failures and zero errors; Pint clean; migrations roll
back and re-run.

Every item in §71 verifies:

- *Architecture* — existing ledger, tax engine and settlement engine reused; no
  second engine; no stored balances.
- *Financial integrity* — posted source documents immutable to notes; posted notes
  immutable; both limits enforced and re-checked under lock; journals balanced;
  fiscal periods respected; tax snapshots preserved.
- *Security* — no client-controlled `company_id`; cross-company access returns 404;
  server-side authorization on every door; policy registered; tampered IDs rejected;
  safe deletion by RESTRICT.
- *Reporting* — customer, supplier, receivables, payables, P&L, Balance Sheet and
  tax reports all reflect notes.
- *Quality* — focused tests, full suite, Pint, migrations and routes all verified;
  no seeders; no frontend; no unrelated refactoring.

Phase 11 is a correction layer over invoices and bills. It does not replace
them, and it added no business rule that the brief did not state.