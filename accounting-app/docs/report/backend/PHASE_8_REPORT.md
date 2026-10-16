# Phase 8 - Financial Year, Accounting Periods & Period Closing

## 1. Phase Objectives

Phase 8 inserts a fiscal-year layer above accounting periods and turns the period
lifecycle into a real accounting control rather than a one-way door.

Delivered:

- `FinancialYear` as a dated, company-scoped container above `AccountingPeriod`,
  with its own `OPEN`/`CLOSED` state, overlap prevention and audited close.
- Monthly period generation from a financial year: idempotent, non-destructive,
  clipped to the year's own boundaries, company-scoped.
- Period resolution for any accounting date, and universal enforcement of that
  resolution at the posting boundary.
- Reopening, which Phase 4 lacked. `POST .../periods/{period}/reopen` with its own
  permission, its own audit pair, and no effect on posted history.
- Close and reopen audit columns on `accounting_periods`, close audit on
  `financial_years`.
- Draft-level protection across all six transactional flows: a draft may be
  created, edited and deleted while its month is closed, but it may not be
  **re-dated** into one.
- **73 new tests, 290 assertions, all passing**; the full suite is
  **673 tests, 3160 assertions, 0 failures / 0 errors / 0 skipped**, with zero
  regressions against the 600-test Phase 1-7 baseline.

No second ledger, no stored balances, no period balance columns, no cached period
state, no duplicate posting path and no closing journal were introduced.

## 2. Files Changed

### Migrations added (2)

| File | Effect |
| --- | --- |
| `2026_10_03_130000_create_financial_years_table.php` | `financial_years` table, indexes, two CHECK constraints, and the backfill attaching pre-existing periods to the year containing them |
| `2026_10_03_130100_add_financial_year_to_accounting_periods_table.php` | `accounting_periods.financial_year_id`, close audit pair, reopen audit pair, one composite index |

### Models added (1)

`FinancialYear`.

### Models modified (1)

`AccountingPeriod` - `financialYear()` and `reopener()` relations, `reopened_at`
cast.

### Enums added (1)

`FinancialYearStatus`.

### Services added (2)

`FinancialYearService`, `AccountingPeriodResolver`.

### Services modified (12)

`AccountingPeriodService`, `JournalPostingService`, `JournalService`,
`SalesInvoiceService`, `SalesInvoicePostingService`, `PurchaseBillService`,
`PurchaseBillPostingService`, `CustomerReceiptService`,
`CustomerReceiptPostingService`, `SupplierPaymentService`,
`SupplierPaymentPostingService`, `CashBankTransactionService`.

The five `*PostingService` changes are one argument each; see section 14.

### HTTP layer added (4)

`FinancialYearController`, `StoreFinancialYearRequest`,
`UpdateFinancialYearRequest`, `FinancialYearResource`.

### HTTP layer modified (6)

`AccountingPeriodController` (reopen action, year/date-range filters),
`StorePeriodRequest` (optional `financial_year_id`), `UpdatePeriodRequest`,
`AccountingPeriodResource`, `AccountingPeriodPolicy`,
`AppServiceProvider` (year binding and policy), `routes/api.php`.

### Other modified (5)

`config/accounting.php` (`fiscal_year.start_month`), `config/authorization.php`
(one new permission), `PermissionName`, `PeriodStatus` (docblock only),
`.env.example` (documents `FISCAL_YEAR_START_MONTH`), plus
`AccountingPeriodFactory` (year-aware states).

### Tests added (6)

`FinancialYearTest`, `FinancialYearPeriodGenerationTest`,
`FinancialYearClosingTest`, `PeriodResolutionTest`, `PeriodReopenTest`,
`ClosedPeriodPostingTest`.

### Tests modified (1)

`AccountingPeriodTest` - one method renamed and re-documented; see section 22.

### Reports modified (2)

`PHASE_7_REPORT.md` (corrected test count, completed verification table),
`PHASE_4_REPORT.md` (three "Superseded by Phase 8" notes; no substantive claim
changed).

The working tree also contains uncommitted Phase 7 files (`Account`,
`AccountService`, `CashBankTransaction`, `CashBankKind`, the cash/bank migrations
and services and their tests). Those are prior work, not part of this phase, and
are excluded from the counts above.

## 3. Migrations

### `financial_years`

`company_id` (FK, cascade), `name`, `start_date`, `end_date`, `status` (default
`OPEN`), `created_by`, `closed_by`, `closed_at`, timestamps.

Indexes, each justified by a query this phase actually issues:

| Index | Serves |
| --- | --- |
| `unique (company_id, name)` | The duplicate-name race |
| `(company_id, start_date, end_date)` | Date-range containment in `findFinancialYear()` and `findForDate()` |
| `(company_id, status)` | The year list filtered by status |

CHECK constraints are added through the existing `App\Support\Database\SchemaCheck`
helper so they stay MySQL-version-aware: `end_date >= start_date`, and
`status in ('OPEN','CLOSED')`. Both were verified present by querying
`information_schema.TABLE_CONSTRAINTS` against the test database.

`financial_year_id` on `accounting_periods` is `nullable` with
`ON DELETE SET NULL`. The nullability is required by the upgrade path - periods
already exist when this column arrives - not as a statement that a period may
legitimately have no year. `AccountingPeriodService::create()` always sets one and
the backfill assigns the rest.

### Backfill of existing periods

`attachExistingPeriodsToFinancialYears()` runs inside the first migration and
gives every pre-existing period the financial year containing its own
`start_date`, creating that year if the company has none.

Leaving them orphaned would be worse than adding a column: an orphaned period
belongs to no year, so a year could be closed while periods it does not know about
were still open. The year of a date follows from `start_month`, so this is derived
from configuration rather than invented. No period is moved, re-dated or deleted;
nothing is inserted except the missing `financial_years` rows.

### The constraint MySQL refused

A CHECK tying `status = 'CLOSED'` to `closed_by`/`closed_at` being set was
attempted and **rejected by MySQL with error 3823**: a CHECK constraint may not
reference a column that also carries a foreign key with a referential action.
`closed_by` is `ON DELETE SET NULL` so that deleting a user does not cascade away
the year or period that user closed.

The pairing is enforced in `FinancialYearService::close()` and
`AccountingPeriodService::close()`, which are also the only writers of `status` -
the models keep `status` out of `$fillable`. Documented rather than worked
around, because the workaround was dropping `ON DELETE SET NULL`, which would make
deleting a user destroy accounting-control history.

## 4. Models

`FinancialYear` holds no amounts, no balances and no cached report data.
`#[Fillable]` covers `name`, `start_date`, `end_date` only; `company_id`,
`status`, `created_by`, `closed_by` and `closed_at` are absent because each is a
scope or lifecycle value that must come from a service. Casts: dates as `date`,
`status` as `FinancialYearStatus`, `closed_at` as `datetime`.

`AccountingPeriod` gains `financialYear()`, `reopener()` and the `reopened_at`
cast. Its fillable set gains `financial_year_id` but **not** `status`, `closed_by`,
`closed_at`, `reopened_by` or `reopened_at` - same reason as above.

No business logic was added to either model. `isOpen()`/`isClosed()` delegate to
the enum; everything else is a relation or a cast.

## 5. Enums

`FinancialYearStatus`: `OPEN`, `CLOSED`, with `isOpen()`, `isClosed()` and
`values()`.

There is deliberately no `REOPENED`, and no third state on `PeriodStatus`. A
reopened period is `OPEN`; a separate state would mean every reader had to know it
meant "open, and previously closed". That a reopen happened is audit data on the
row, not a status.

`PeriodStatus` itself is unchanged in behaviour. Its docblock was rewritten
because the Phase 4 text described the absence of reopen as a deliberate permanent
design rather than the gap it actually was.

## 6. Services

### `FinancialYearService`

| Method | Behaviour |
| --- | --- |
| `create()` | Rejects overlapping company ranges inside the transaction; `assertNoOverlap()` plus the unique `(company_id, name)` index together settle the race |
| `generatePeriods()` | Walks the year's own `start_date` to `end_date` month by month, clipping first and last months to the year, keyed by `Y-m` so a repeat call recognises its own work |
| `close()` | Row-locked; rejects an already-closed year, a year with zero periods, and a year with any open period; stamps `closed_by`/`closed_at`; writes no journal |
| `update()` | Refuses a closed year; refuses a range overlapping another year; refuses to shrink so that a period would fall outside |
| `resolveOrCreateForDate()` | The mutating lookup: derives and creates the year for a date from `start_month` |
| `findForDate()` | The read-only lookup |
| `fiscalRangeFor()` | `start_month` to `[first of that month, last day of the same month next year]` |

`generatePeriods()` writes through the same overlap-checked path a hand-created
period takes, so a generated period and a typed one are the same kind of row. It
never overwrites an existing period: an operator's hand-made January is left
alone. The response reports what the year holds afterwards rather than what this
call added, because on a repeat call those differ and reporting the delta would
suggest nothing happened when in fact the year is fully set up.

Generation lives on the year rather than on `AccountingPeriodService` because the
year owns the set - the generator's whole job is to walk the year's own dates.
Every period rule still lives in `AccountingPeriodService`.

### `AccountingPeriodResolver`

Split from `AccountingPeriodService` by responsibility:

- `AccountingPeriodService` - lifecycle: create, update, close, reopen, and the
  rules governing those.
- `AccountingPeriodResolver` - lookup: which period, and which year, owns a date.

A posting flow needs the second and not the first. A resolver that could also
close periods is a resolver someone will eventually call to close one.

Methods: `findPeriod()`, `findFinancialYear()`, `resolve()`, `isClosed()`,
`acceptsPostings()`. `findFinancialYear()` never writes - a posting path asking
"which year is this" must not create one as a side effect of answering, which is
why it is separate from `resolveOrCreateForDate()`.

`resolve()` returns the period and the year **together** even though callers
usually want one, because the two can disagree, and a period sitting outside its
own year must be visible to whoever is asking rather than silently resolved in the
period's favour.

### `AccountingPeriodService`

`create()` requires a financial year, named or derived, and asserts the period
lies wholly inside it. `update()` re-checks overlap. `close()` and `reopen()` are
row-locked and audited. `assertPostableDate()` and `assertDateNotClosed()` are the
two enforcement points described in sections 13 and 14.

## 7. Requests

`StoreFinancialYearRequest` validates shape only: `name` required string,
`start_date` and `end_date` required `Y-m-d`, `end_date`
`after_or_equal:start_date`. `UpdateFinancialYearRequest` uses `sometimes` for each
and adds a `withValidator` pass for the ordering when both dates are present.

Neither request accepts `company_id`. A comment in each says why: accepting the
column would let a request create a year inside another tenant, and no amount of
validation makes that safe. It is a scope question, and the scope comes from
`CompanyContext`. Overlap, name uniqueness and closed-state are service rules for
the same reason - a Form Request has no company to scope a lookup by, and a
tenancy or lifecycle decision duplicated into validation is two implementations of
one rule that will eventually disagree.

`StorePeriodRequest` gains an **optional** `financial_year_id`, scoped by
`Rule::exists(...)->where('company_id', ...)`. `UpdatePeriodRequest` gains none:
exposing it there would invite a client to move a period into a year its dates do
not fall in, which the service would refuse with a message the client could not
have predicted. Changing a period's year is a job for moving its dates.

## 8. Resources

`FinancialYearResource` exposes `can_close` and `accepts_postings` rather than
leaving a client to reimplement "all periods closed" and end up with a close
button that 422s. Period and open-period counts are derived per request via
`loadCount`, not stored, so a year can never disagree with its own periods.

`AccountingPeriodResource` gains a nested `financial_year` (`id`, `name`,
`status`), the `financial_year_id`, the close audit pair and the reopen audit
pair. Its `accepts_postings` is computed from the period's status **and** its
year's, mirroring `assertPostableDate()`. Reporting only the period status would
tell a client a date is postable when the posting boundary will reject it.

## 9. Controllers

`FinancialYearController`: `index`, `store`, `show`, `update`, `generatePeriods`,
`close`. `AccountingPeriodController` gains `reopen` plus `financial_year_id`,
`from_date` and `to_date` filters.

Both hold a `CompanyContext` and never read a company id from the request. All
responses use the existing `ApiResponse` envelope; no new envelope was
introduced.

The `from_date`/`to_date` filters select on the period **range** rather than on a
single date column - `end_date >= from_date AND start_date <= to_date`. Matching
`start_date` alone would drop the period that began before `from_date` but still
covers it, which is the opposite of what a caller asking "which periods cover
this range" wants. Two bounds only; no generic filter DSL, which the brief rules
out explicitly.

## 10. Routes

Six financial-year routes under `accounting/financial-years`, six period routes
under `accounting/periods`, all inside the existing
`auth:api, auth.fresh, company.context, throttle:api` group.

| Method | URI | Name |
| --- | --- | --- |
| GET | `accounting/financial-years` | `accounting.financial_years.index` |
| POST | `accounting/financial-years` | `accounting.financial_years.store` |
| GET | `accounting/financial-years/{financialYear}` | `accounting.financial_years.show` |
| PUT | `accounting/financial-years/{financialYear}` | `accounting.financial_years.update` |
| POST | `accounting/financial-years/{financialYear}/periods/generate` | `accounting.financial_years.periods.generate` |
| POST | `accounting/financial-years/{financialYear}/close` | `accounting.financial_years.close` |
| GET | `accounting/periods` | `accounting.periods.index` |
| POST | `accounting/periods` | `accounting.periods.store` |
| GET | `accounting/periods/{period}` | `accounting.periods.show` |
| PUT | `accounting/periods/{period}` | `accounting.periods.update` |
| POST | `accounting/periods/{period}/close` | `accounting.periods.close` |
| POST | `accounting/periods/{period}/reopen` | `accounting.periods.reopen` |

Verified with `php artisan route:list --path=accounting`: 25 routes, of which 12
are Phase 8.

There is no `financial-years/{financialYear}/reopen`. See section 16.

## 11. Authorization

Exactly one new permission: `accounting.periods.reopen`.

| Role | view | create/update | close | reopen |
| --- | :-: | :-: | :-: | :-: |
| Admin | yes | yes | yes | yes |
| Accountant | yes | yes | no | no |
| Manager | yes | no | no | no |
| Staff | no | no | no | no |

Reopen is deliberately not granted to Accountant, and this is a deviation from the
brief's illustrative table, which showed Accountant with reopen. That table is
labelled as a guide, and the brief's own instruction is to use the smallest
defensible set and to let the matrix reflect the project's actual design after
inspection. Two reasons for this project's answer:

- Phase 4 already withheld `accounting.periods.close` from Accountant, with a
  comment in `config/authorization.php` explaining that closing belongs to
  whoever owns the period rather than to day-to-day bookkeeping. Reopening undoes
  that decision. Granting an Accountant reopen without close would let the role
  reverse a control it cannot apply, which is incoherent.
- A role that may close but not reopen can always ask an Admin; the reverse is not
  true. The asymmetry is the point.

Financial-year routes reuse the period permissions rather than adding a
`accounting.financial_years.*` namespace. A year is viewed, maintained and closed
by exactly the same roles as periods, and a second hierarchy would be a second set
of grants to keep in step for no additional separation.

`AccountingPeriodPolicy` gained the year abilities (`viewAnyYear`, `viewYear`,
`createYear`, `updateYear`, `generatePeriods`, `closeYear`) rather than a second
`FinancialYearPolicy`. The two models are one hierarchy governed by one permission
set; two policy classes would answer the same questions and drift.

`generatePeriods()` rides on the **create** permission, not update. It writes rows,
and a role that may rename a period need not be able to add twelve of them.

## 12. Company Isolation

`financialYear` is bound through the same scoped-binding closure as every other
accounting model in `AppServiceProvider`, so a financial year reaching a
controller is already known to belong to the active company. A foreign id 404s
before the controller runs, so it is not even reachable for a policy to refuse.

No request accepts `company_id`. No service accepts a company from a caller it did
not resolve from `CompanyContext`. `AccountingPeriodResolver` takes
`Company|int` so a service holding only an id can resolve without re-loading the
model, but every call site passes a model it already has.

Verified by: `a_financial_year_from_another_company_is_not_found`,
`two_companies_may_define_the_same_financial_year`,
`resolution_is_scoped_to_one_company`, `generation_is_company_scoped`,
`a_financial_year_of_another_company_cannot_be_named`,
`a_period_in_another_company_cannot_be_reopened`,
`a_company_sees_only_its_own_financial_years`,
`a_period_of_one_company_being_closed_does_not_block_another`.

## 13. Period Rules

Boundaries are inclusive at both ends on periods and years alike. `start_date` and
`end_date` belong to the range. This is what lets January (ending 01-31) and
February (starting 02-01) sit adjacent without being reported as overlapping.

Overlap is rejected inside the same transaction that writes the row, and the
unique `(company_id, name)` index is the real guard against two concurrent
requests: the application check cannot see another transaction's uncommitted row,
so the index closes the race the check cannot. Only *that* index is translated
into a friendly message - a blanket catch would answer "name already in use" for
an unrelated failure and send the user to fix a name that was never the problem.

A period must lie wholly inside its year. `create()` refuses a period extending
past either edge, and `update()` on a year refuses to shrink the year so a period
would fall outside.

A date must resolve to **exactly one** period. Both endpoints being inclusive plus
overlap prevention is what makes that true rather than merely intended.

An uncovered date is not an error at draft time - it is an error at posting time,
where the message says to create the period.

## 14. Posting Integration

`JournalPostingService` remains the single path from `DRAFT` to `POSTED`. Step 7 of
its existing fixed sequence is now:

```php
$this->periods->assertPostableDate(
    $fresh->company,
    Carbon::parse($fresh->journal_date),
    $dateField,
);
```

Because every flow posts through a generated journal, enforcing the rule here means
no flow can bypass it. There is no second posting path, no per-flow period check
that could disagree, and no way for a future module to post without passing this
line.

`assertPostableDate()` distinguishes three failures rather than one, because each
needs a different action from the user:

| Failure | Message | Meaning |
| --- | --- | --- |
| No period covers the date | "No accounting period covers this date. Create the period before posting." | The calendar is not set up |
| Period is closed | "The accounting period [X] is closed and cannot accept new entries." | The entry is late |
| Year is closed | "The financial year [Y] is closed and cannot accept new entries." | The whole span is finished |

Collapsing them into "invalid period" would leave the user guessing.

### The date-field argument

The failure is reported against `$dateField` rather than always `journal_date`.
Every flow reaches this method through a generated journal, but the user is
editing an invoice or a bill, and an error attached to a field that does not exist
on their form is an error they cannot act on. The five posting services pass their
own field; a manual journal takes the default.

| Flow | Field passed |
| --- | --- |
| Manual journal | `journal_date` (default) |
| Sales invoice | `invoice_date` |
| Purchase bill | `bill_date` |
| Customer receipt | `receipt_date` |
| Supplier payment | `payment_date` |
| Cash/bank transaction | `transaction_date` |

### Draft re-dating

`assertDateNotClosed()` is the counterpart for operations that are not postings.
It is called from the `update`/`updateDraft` path of all six document services, and
**only when the date field is present in the payload**.

A draft created while its month was open, and never re-dated, stays editable for
its description, notes and lines. A draft is not yet accounting data, and locking
it entirely the day its month closes would make a typo in the description
unfixable except by delete-and-recreate. What is refused is moving accounting data
into a closed date.

`assertDateNotClosed()` deliberately does not call `assertPostableDate()`:
requiring an *open* period at edit time would make a draft undeletable the moment
its month closed, which is a much harsher rule than the accounting one. It also
accepts a date with no period, because an uncovered date is not a closed date, and
refusing it would block the ordinary correction of a draft dated before the
calendar was set up.

Deletion and non-date edits are untouched. No new guard was added to
`deleteDraft()`.

## 15. Financial-Year Rules

A year is a dated container and nothing more. Its usability is decided by whether
its periods are closed, not by a lifecycle of its own - which is why `FinancialYearStatus`
has two states and not three, and why a year is never a draft.

Overlap: a company may not hold two years whose ranges intersect. Adjacent years
(2027-04-01..2028-03-31 and 2028-04-01..2029-03-31) are not overlapping, because
endpoints are inclusive and those ranges share no date.

The fiscal calendar is `config('accounting.fiscal_year.start_month')`, defaulting to
`4` from `FISCAL_YEAR_START_MONTH`. A year runs from the first of that month to the
last day of the same month a year later, so `4` puts 2027-03-31 in the year
beginning 2026-04-01 and 2027-04-01 in the next one. The boundary always lands on
the first of a month, never inside one.

This is a single integer, not a fiscal-calendar engine. Every company in this
deployment shares one calendar, so a per-company calendar would be storage for a
distinction nobody has asked for. Changing the value affects generation and
derivation only; it never re-dates an existing year or period, because those are
dated facts with journal history attached.

A year may be renamed and re-ranged while open, subject to the overlap check and
the containment check in section 13. A closed year is immutable, for the same
reason a closed period is: moving the dates of a year declared finished would
change which dates the close covers without anybody closing them.

## 16. Closing / Reopening Behaviour

### Period close

Transactional and row-locked. Rejects an already-closed period with `422` rather
than silently succeeding, so a client that retries a close learns it has nothing
left to do. Stamps `closed_by` and `closed_at`.

**No prerequisite beyond state.** No zero-receivables check, no zero-payables, no
zero-drafts. The brief is explicit that a period close must not invent business
rules, and nothing in Phases 1-7 demands them.

### Period reopen

Transactional and row-locked. Refuses an already-open period. Refuses a period
inside a **closed** financial year, and says so - see below.

Reopening preserves accounting history absolutely. No journal, line, allocation or
balance is read, rewritten or deleted. The entries posted before the close were
legitimate then and remain legitimate now; what changes is only whether *new*
postings are accepted for that range. `reopening_does_not_alter_journals_already_posted_in_the_period`
asserts the journal row is byte-identical across the transition.

### Audit

Two independent nullable pairs on `accounting_periods`:

| Pair | Written by | Cleared by |
| --- | --- | --- |
| `closed_by` / `closed_at` | `close()` | `reopen()` |
| `reopened_by` / `reopened_at` | `reopen()` | `close()` |

Each pair describes the last transition of its own kind, and the two are never both
populated. Reopening is the more consequential of the two acts, so it is the one
that must leave an attributable trace; the brief asks reopening to record who did
it and when, and that record has to survive the transition. Leaving `closed_by` in
place instead would make one pair describe two different states.

This is two nullable columns, not an audit-history table. A history of every close
attempt belongs in an audit system this project does not have, and inventing one
was out of scope.

### Financial-year close

Permitted only once **every** period of the year is closed, and only when the year
has at least one period. Closing the periods is the substantive act; the year close
records that the whole span is finished. A year with an open month inside it would
otherwise leave a date the year calls finished and the period calls live.

**No closing journal is written.** This is the single most important accounting
decision in the phase, and it is not a shortcut:

`JournalReportService::retainedEarnings()` already derives retained earnings as
cumulative revenue less cumulative expenses from posted lines through a date, and
`BalanceSheetReportService` adds that derived figure to equity. A year-end closing
entry moving net profit into an equity account would be counted a second time on
the balance sheet, because the equity account's own balance and the derived figure
would both include it. The chart-of-accounts design supports retained earnings
honestly through derivation, so posting would not improve it - it would corrupt
it.

Closing a year is therefore purely a statement that the year is finished. The only
thing that moves money in this system remains `JournalPostingService`.
`closing_a_year_writes_no_journal` asserts the journal count is unchanged.

### Financial-year reopen: deliberately not implemented

The brief says not to implement this automatically, and to decide after inspection
and document the decision. Decision: **not implemented.** A closed financial year
is permanent in Phase 8.

The reasoning: reopening a year is the more dangerous of the two operations,
because it silently makes twelve months of dates postable again. It would need its
own permission, its own audit pair and its own validation of what it is
reopening - none of which the brief asks for, and all of which would be new
authority created without a requirement behind it.

The practical consequence is stated rather than hidden: a period inside a closed
year **cannot** be reopened, and `reopen()` says so explicitly ("Reopen the
financial year before reopening one of its periods"). That instruction is
currently unactionable, because there is no such route. This is a dead end by
design, and saying so is more useful to an administrator than reopening the
month and leaving the year and the month disagreeing. If year reopen is later
required, it is a small additive change: a route, a permission, a service method
and the audit pair.

## 17. Security Considerations

- **No client-supplied `company_id`.** Not accepted by any request, not read by
  any controller. Scope comes from `CompanyContext`, and every accounting model is
  bound through the scoped closure in `AppServiceProvider`.
- **State is never client-writable.** `status`, `closed_by`, `closed_at`,
  `reopened_by`, `reopened_at` and `created_by` are absent from every model's
  fillable set on both models. `posted_by`/`posted_at` on journals were already
  server-assigned and remain so.
- **Cross-tenant ids 404 rather than 403.** The binding hides another company's
  resource, so the response does not disclose that the id exists.
- **Reopen is separately authorized** from close, and `reopening_an_already_open_period_is_rejected`
  plus the three role tests confirm an Accountant and a Manager are refused with
  `403`.
- **Mutation of a closed period is refused** through `update()` with `422`, so
  renaming a closed period is not a quieter route to changing its state.
- **No weakening of Phase 5-7 immutability.** Posted journals remain immutable;
  `deleteDraft()` still requires `DRAFT` under a row lock; cash/bank
  double-posting still returns `409`.
- **Error messages name the period and year.** This is deliberate: a user who
  cannot see which month is closed cannot act. It reveals nothing a member of that
  company could not already read from the period list.

## 18. Performance

- Period resolution is two indexed range scans on
  `(company_id, start_date, end_date)` - the index that already existed - and at
  most one more on `(company_id, financial_year_id, start_date)`. No table scan,
  no cached state.
- `generatePeriods()` loads the year's existing periods once and keys them by
  `Y-m`, so the walk is O(months) in memory plus one insert per missing month. It
  does not re-query per month.
- The overlap check runs once per write, inside the transaction, against the same
  indexed range.
- Resource period counts are `loadCount` sub-selects, not separate round trips,
  and only on the two endpoints that need them.
- No cache was introduced for period or year state. A cached period status would
  be a correctness liability for exactly the reason the row lock exists.
- Financial-year close issues two counts on an indexed relation rather than loading
  the period collection.

## 19. Tests

**73 new tests, 290 assertions.**

| File | Tests | Assertions | Covers |
| --- | ---: | ---: | --- |
| `FinancialYearTest` | 12 | 36 | Create, overlap and adjacency, cross-tenant create, name uniqueness, invalid range, closed-year immutability, containment on shrink, listing filter, role gating |
| `FinancialYearPeriodGenerationTest` | 7 | 26 | One period per month, clipping to a misaligned year, idempotency, year attachment, non-overwrite of an operator's period, company scoping, permission |
| `FinancialYearClosingTest` | 8 | 25 | Open period blocks, zero periods blocks, admin closes, audit stamps, accountant refused, double close refused, **no journal written**, closed year rejects posting even with an open period |
| `PeriodResolutionTest` | 17 | 54 | Inclusive boundaries both ends, company scoping, uncovered date, period+year together, closed year, `isClosed()` vs `acceptsPostings()`, derived year, containment, foreign year, legacy null-year row, three filters, cross-tenant list |
| `PeriodReopenTest` | 11 | 47 | Admin reopens, accountant and manager refused, double reopen refused, closed-year refusal, posting works again afterwards, history untouched, update route still refused, no journal, both audit pairs, cross-tenant |
| `ClosedPeriodPostingTest` | 18 | 102 | All six posting flows rejected, all six draft re-datings rejected, draft still creatable, non-date edit still allowed, delete still allowed, posted journal undisturbed, cross-company close does not block, resource reports `accepts_postings: false` |

Per-file counts are measured, not derived from `#[Test]` attributes - see section
22 for why that distinction mattered.

### Boundary coverage

First day, last day, the day before and the day after are each exercised at both
the period and the year level, on the posting path and the resolution path. The
two are separately tested because the failure messages differ.

## 20. Commands Run

All from `accounting-app/backend`.

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Accounting` | **263 tests, 1222 assertions, 0 failures** |
| `php artisan test` | **673 tests, 3160 assertions, 0 failures** |
| `./vendor/bin/pint` | 10 files fixed (line endings, brace position, import order, one unused import) |
| `./vendor/bin/pint --test` | passed |
| `php artisan route:list --path=accounting` | 25 routes; 12 Phase 8 |
| `php artisan route:list --path=accounting/periods` | 6 routes |
| `php artisan route:list --path=accounting/financial-years` | 6 routes |
| `php -l` on all 15 Phase 8 PHP files | clean |
| `DB_DATABASE=accounting_test php artisan migrate --database=mysql` | both migrations applied |
| `DB_DATABASE=accounting_test php artisan migrate:rollback --step=2` | both rolled back, twice, cleanly |
| `DB_DATABASE=accounting_test php artisan migrate:status` | 0 pending |
| `information_schema` index and CHECK-constraint queries | all expected indexes and constraints present |

The migration rollback was run twice, in both directions, to confirm the pair is
symmetric rather than only succeeding on first application.

**The development database was never migrated.** Every migration command was
prefixed with `DB_DATABASE=accounting_test`, because this project has no
`.env.testing` and `php artisan migrate --env=testing` would resolve to the
development database. `DB_DATABASE=accounting` was verified afterwards:
`financial_years` absent, `financial_year_id` column absent, zero rows in
`migrations` matching `2026_10_03`.

## 21. Issues Discovered

Seven real defects were found and fixed during this phase. Each is listed because
a report that only describes the finished state hides what the work actually cost.

1. **`generatePeriods()` was not selecting `start_date`.** It keyed its
   already-seen map on `$period->start_date` while selecting only `id` and `name`.
   Every call therefore generated duplicates and the idempotency requirement was
   silently violated. Found by the generation test, fixed by selecting the column.

2. **`FinancialYearService::close()` accepted a year with zero periods.**
   `openCount` is zero when there are no periods, so the guard passed and a year
   could be closed having never contained a month. Now rejected with a message
   pointing at generation.

3. **Four services were missing `use` statements.** `AccountingPeriodService` was
   referenced without an import in `SalesInvoiceService`, `PurchaseBillService`,
   `CustomerReceiptService` and `SupplierPaymentService`. `php -l` passes on
   unimported same-namespace-looking references, so this was invisible until the
   tests ran.

4. **Posting failures were reported against the wrong field.** Without the
   `$dateField` argument, an invoice rejected for a closed month returned an error
   on `journal_date` - a field that does not exist on the invoice form. Fixed by
   threading the field from each posting service.

5. **Reopening recorded no reopener.** Found while checking section 22 against the
   brief: `reopen()` cleared `closed_by`/`closed_at` and recorded nothing, but the
   brief requires reopening to record who did it and when. Added
   `reopened_by`/`reopened_at`, with `close()` clearing them so the two pairs
   cannot both be populated.

6. **`reopen()`'s closure did not capture `$actor`.** The parameter existed and was
   documented but was not in the `use (...)` list, so stamping it raised
   `ErrorException: Undefined variable $actor` and returned `500`. Caught by
   `an_admin_can_reopen_a_closed_period` once the audit assertion was added.

7. **`AccountingPeriodTest` carried a Phase 4 assumption into Phase 8.** Its
   `a_closed_period_cannot_be_reopened` method asserted that no route exists to
   reopen a period - true in Phase 4, false now, and the comment claimed it was a
   permanent design decision. Renamed to
   `a_closed_period_cannot_be_edited_through_the_update_route` and re-documented.
   The assertion itself (a `PUT` on a closed period returns `422`) is unchanged and
   still correct.

Two inaccuracies in prior reports were also corrected rather than left:

8. **`PHASE_7_REPORT.md` claimed 89 new tests; the real figure is 91.** The 89 was
   evidently counted from `#[Test]` attributes, which undercounts methods that a
   data provider expands into several cases. Measured per file:
   `CashBankMovementTest` has 18 attributes and runs 22; `CashBankAuthorizationTest`
   8 and 11; `CashBankRulesTest` 6 and 10. The correction is documented in the
   Phase 7 report with the full table. Its full-suite test count of 600 was also
   correct, but its assertion figure of 2868 was two short: running every
   non-Phase-8 file as a set during Phase 8 measured 600 tests / 2870 assertions,
   and the Phase 7 report now records 2870.

9. **`PHASE_4_REPORT.md` described reopen as permanently absent** in three places,
   including "No period reopening" under Outstanding Work. Each now carries a
   "Superseded by Phase 8" note. No substantive Phase 4 claim was rewritten, and
   the note also records that period-end **auto-close** remains deliberately
   unimplemented - nothing in this system advances a calendar on its own, so
   closing stays an explicit human act.

## 22. Known Limitations

- **A closed financial year is permanent.** Documented in section 16. This is a
  deliberate decision with a stated consequence (its periods cannot be reopened
  either), not an oversight.
- **The closed-state CHECK constraint is not in the database.** MySQL error 3823
  forbids it while `closed_by` carries `ON DELETE SET NULL`. Service-level
  enforcement only, in the two methods that are the sole writers of `status`.
  A direct SQL write could produce a `CLOSED` year with no `closed_by`.
- **`reopened_by`/`reopened_at` record only the latest reopen.** A period cycled
  five times shows the fifth. An attempt log was explicitly out of scope.
- **`financial_year_id` is nullable.** Only the migration path and hand-edited rows
  rely on that. A legacy row with no year is handled rather than rejected, because
  refusing it would block posting into a date the company has always been able to
  post into.
- **Concurrency is verified by design and by sequential tests, not by a two-process
  test.** The locking is real and the close/post interleaving is reasoned about in
  `assertPostableDate()`, but no test opens two connections and races them. This is
  the same limitation the Phase 4 report recorded for posting, and it is unchanged.
- **The fiscal calendar is global, not per-company.** Documented in section 15.
- **`from_date`/`to_date` filters are not validated against each other.** A
  `from_date` later than `to_date` returns an empty list rather than a 422. The
  brief asked for useful filters, not a filter DSL, and an inverted range is
  answerable.

## 23. Outstanding Work

Deliberately not in this phase:

- Financial-year reopening (section 16).
- Period-end automatic closing and lock dates. Closing stays an explicit human act.
- Year-to-year comparative reporting columns.
- Budgets, forecasts and consolidation.
- Export, and any read-side change to the Phase 6 report contracts.

## 24. Requirements Traceability

| Requirement | Implementation | Test |
| --- | --- | --- |
| Company-scoped financial year | `FinancialYearService`, scoped `financialYear` binding | `a_company_sees_only_its_own_financial_years`, `a_financial_year_from_another_company_is_not_found` |
| No overlapping financial years | `assertNoOverlap()` + unique index | `an_overlapping_financial_year_is_rejected`, `adjacent_financial_years_do_not_overlap` |
| Monthly accounting periods | `generatePeriods()` | `generating_periods_creates_one_per_month_within_the_year` |
| Generation idempotent, non-destructive | `Y-m` keyed map, `assertPeriodRangeIsFree()` | `generation_is_idempotent`, `generation_does_not_overwrite_a_period_an_operator_already_created` |
| Generation clips to the year | month clip in `generatePeriods()` | `generation_clips_periods_to_a_year_that_does_not_start_on_a_month_boundary` |
| Period belongs to financial year | `financial_year_id` + `assertWithinYear()` | `generated_periods_belong_to_the_year_that_created_them`, `a_period_may_not_sit_outside_the_year_it_is_attached_to` |
| Financial year derived when unnamed | `resolveOrCreateForDate()` | `a_period_created_without_a_named_year_is_attached_to_the_derived_year` |
| Legacy periods backfilled | `attachExistingPeriodsToFinancialYears()` | `a_legacy_period_with_no_year_still_resolves_and_posts` |
| Open/closed state | `FinancialYearStatus`, `PeriodStatus` | `closing_records_who_closed_it_and_when`, `a_closed_period_reports_that_it_does_not_accept_postings` |
| Date resolves to one period | `AccountingPeriodResolver::findPeriod()` | `a_date_resolves_to_the_period_that_contains_it`, `period_boundaries_are_inclusive_on_both_ends` |
| Uncovered date refused at posting | `assertPostableDate()` | `posting_a_date_no_period_covers_is_refused_and_says_so` |
| Closed period rejects journal posting | `assertPostableDate()` in `JournalPostingService` | `a_journal_cannot_post_into_a_closed_period` |
| Closed period rejects sales posting | same, via `SalesInvoicePostingService` | `a_sales_invoice_cannot_post_into_a_closed_period` |
| Closed period rejects purchase posting | same, via `PurchaseBillPostingService` | `a_purchase_bill_cannot_post_into_a_closed_period` |
| Closed period rejects receipt posting | same, via `CustomerReceiptPostingService` | `a_customer_receipt_cannot_post_into_a_closed_period` |
| Closed period rejects supplier payment posting | same, via `SupplierPaymentPostingService` | `a_supplier_payment_cannot_post_into_a_closed_period` |
| Closed period rejects cash/bank posting | same, via `CashBankPostingService` | `a_cash_bank_transaction_cannot_post_into_a_closed_period` |
| Errors blamed on the flow's own field | `$dateField` parameter | all six posting tests assert the field key |
| Draft re-dating refused, other edits allowed | `assertDateNotClosed()` | 6 re-dating tests + `a_draft_can_still_be_created_in_a_closed_period`, `a_draft_in_a_closed_period_can_still_have_its_non_date_fields_edited`, `a_draft_in_a_closed_period_can_still_be_deleted` |
| Period close | `AccountingPeriodService::close()` | `an_admin_can_close_a_period`, `closing_an_already_closed_period_is_rejected` |
| Period reopen, separately authorized | `reopen()` + `accounting.periods.reopen` | `an_admin_can_reopen_a_closed_period`, `an_accountant_cannot_reopen_a_period`, `a_manager_cannot_reopen_a_period` |
| Reopen preserves history | `reopen()` touches no journal table | `reopening_does_not_alter_journals_already_posted_in_the_period`, `reopening_a_period_can_produce_no_journal` |
| Reopen audited | `reopened_by` / `reopened_at` | `an_admin_can_reopen_a_closed_period`, `closing_a_reopened_period_clears_the_reopen_attribution` |
| Closed year rejects posting | year branch in `assertPostableDate()` | `posting_into_a_closed_year_is_refused_and_names_the_year`, `a_closed_year_rejects_posting_even_if_a_period_is_left_open` |
| Year close requires all periods closed | `FinancialYearService::close()` | `a_year_with_an_open_period_cannot_be_closed`, `an_admin_can_close_a_year_once_every_period_is_closed` |
| Year close writes no journal | derived retained earnings | `closing_a_year_writes_no_journal` |
| Year reopen not implemented | by design | `a_period_in_a_closed_financial_year_cannot_be_reopened` |
| Closed period not editable | `update()` guard | `a_reopened_period_still_cannot_be_edited_through_the_update_route` |
| Company isolation on periods and years | scoped bindings | 8 tests, listed in section 12 |
| Authorization matrix | `AccountingPeriodPolicy` + config | role tests in `FinancialYearTest`, `PeriodReopenTest`, `a_manager_cannot_generate_periods` |
| Database constraints and indexes | migrations + `SchemaCheck` | verified via `information_schema` (section 20) |
| Migration rollback clean | `down()` in both migrations | `migrate:rollback --step=2` run twice (section 20) |
| Historical reports remain available | no report service modified | `tests/Feature/Reports/` 66 tests, 536 assertions, all passing |
| No duplicate accounting engine | single `JournalPostingService::post()` | grep confirms 5 call sites, all delegating |
| No stored balances | no balance column added | schema verified in `information_schema` |
| Phase 6 compatibility | untouched report calculations | full suite green |
| Phase 7 compatibility | cash/bank posting routes through the same guard | `a_cash_bank_transaction_cannot_post_into_a_closed_period` |
| Fiscal start month configurable | `config('accounting.fiscal_year.start_month')` | `fiscal()` factory state, `.env.example` |
| Period list filters | year, status, from/to | three filter tests in `PeriodResolutionTest` |
| Deterministic ordering | `orderBy('start_date')->orderBy('end_date')` | asserted in filter tests |

## 25. Regression Result

```text
Phase 1-7 baseline:  600 tests, 2870 assertions, 0 failures
+
Phase 8 tests:        73 tests,  290 assertions, 0 failures
=
Full suite:          673 tests, 3160 assertions, 0 failures
```

Verified by exclusion, not by subtraction alone: every test file except the six
Phase 8 files was run as a set and reported **600 tests, 2870 assertions, 0
failures**, confirming the baseline is intact rather than merely arithmetically
consistent.

- `./vendor/bin/pint --test` passes.
- All 15 Phase 8 PHP files pass `php -l`.
- `tests/Feature/Reports/` re-run at 66 tests, 536 assertions, all passing,
  confirming Phase 6 report semantics are unchanged.
- No existing test was deleted or weakened. One method was renamed
  (`a_closed_period_cannot_be_reopened` to
  `a_closed_period_cannot_be_edited_through_the_update_route`) and its comment
  rewritten; the assertion and its expected status are identical.
- No documented Phase 1-7 business rule was changed. The Phase 4 rule that a
  closed period cannot be edited still holds; the phase only added the route
  that the Phase 4 report itself pointed at as a gap.

## 26. Architectural Compliance

| Prohibited | Status |
| --- | --- |
| A second ledger | Not created. `JournalPostingService` remains the sole `DRAFT` to `POSTED` path |
| Stored account balances | None added |
| Period balance columns | None added |
| Duplicated journal posting logic | None. One call site per flow, all delegating |
| Duplicated company isolation logic | None. The existing scoped binding was reused |
| Trusting `company_id` from the client | No request accepts it |
| Floating-point money | Phase 8 performs no financial calculation at all |
| Bypassing `JournalPostingService` | No new posting path exists |
| A second cash/bank posting mechanism | None; cash/bank posts through the same service |
| Altered Phase 6 report semantics | No report calculation touched |
| Weakened Phase 5-7 security | No permission removed, no guard relaxed |
| Unnecessary abstractions | Three focused services, no engine, no repository layer, no DSL |
| Caching for period state | None, deliberately |
| Modifying posted history on reopen | None |
| Automatic closing journals | None, and documented why |
| Running seeders | None run |
| Silently changing unrelated modules | Only the report corrections in section 21, each explicit |
