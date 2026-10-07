# PHASE 15 REPORT — PERIOD-END & YEAR-END CLOSING + OPENING BALANCE MANAGEMENT

## 1. Executive Summary

Phase 15 adds a controlled, auditable period-end and year-end workflow on top of
the existing accounting-period architecture, without introducing a second
accounting engine, ledger, journal engine, balance engine, audit system, FX
system, tax engine or reporting engine.

The work is deliberately small and reuses what Phases 4–14 already built:

- **Period close** now runs a read-only closing review (extending
  `AccountingControlService`) before it commits, refuses to close while a
  critical control fails, writes an audit event, and remains transactionally and
  concurrency safe through the row lock it already held.
- **Year close (year-end finalization)** is the existing financial-year close: it
  now refuses to finalize while a critical company control fails and writes an
  audit event.
- A new **read-only closing-check endpoint** answers "may this period close?"
  without closing anything.
- Three new **read-only integrity controls** cover unbalanced posted journals,
  journal lines pointing outside their own company, and posted journals with no
  covering period.

**Opening balances and a P&L closing journal were deliberately NOT implemented.**
The existing reports already derive balance-sheet carry-forward and retained
earnings from posted journal lines, so storing opening balances or posting a
closing journal would create a second source of truth and double-count profit.
Sections 12 and 13 of the brief explicitly forbid those when the ledger already
carries the balances forward; this report records that finding and the decision.

Final status: **PASS WITH NOTES**.

---

## 2. Objectives

1. Make the accounting system able to move safely from an open period, through a
   period-end review, to a closed period, and where appropriate to a finalized
   year.
2. Prevent accidental modification of finalized accounting history.
3. Block a period close when critical accounting integrity controls fail.
4. Make every close/reopen/year-finalization authorized, company scoped,
   audited, transactionally safe and concurrency safe.
5. Reuse the existing AccountingPeriod lifecycle, `JournalService`,
   `JournalPostingService`, `LedgerService`, `AccountingControlService`, report
   services, `AuditService`, `CompanyContext`, authorization and the Phase 14
   multi-currency architecture.

---

## 3. Existing Architecture Reviewed

Inspected before any change:

- **Periods** — `AccountingPeriod` model, `accounting_periods` migration,
  `AccountingPeriodService` (create/update/close/reopen, overlap checks,
  `assertPostableDate`, `assertDateNotClosed`), `PeriodStatus` (`OPEN`/`CLOSED`
   only), `AccountingPeriodResolver`, `AccountingPeriodPolicy`,
   `AccountingPeriodResource`, the period routes, and the period tests.
- **Fiscal year** — `FinancialYear` model, `FinancialYearService` (creation,
  period generation, year close), `FinancialYearController`, and the year tests.
  A financial year is a dated container that holds no amounts.
4. **Journal/posting** — `JournalService` (drafts; refuses to edit a posted
   journal), `JournalPostingService` (the single posting path; holds a lock,
   re-validates structure/balance/accounts/FX, and asserts the date is postable
   through `AccountingPeriodService::assertPostableDate()`), `LedgerService`.
5. **Reports** — `BalanceSheetReportService` (cumulative through `to_date`;
   equity = equity accounts + derived retained earnings),
   `JournalReportService::retainedEarnings()` (revenue − expenses from posted
   lines). No report stores its own balances.
6. **Controls** — `AccountingControlService` (read-only, FX-only before this
   phase), `ControlStatus` (Pass/Warning/Fail), `ControlFinding`.
7. **Audit** — `AuditService` (single writer, no constructor deps), `AuditLog`
   (immutable at the model layer), `AuditAction` (already has `Closed` and
   `Reopened`).
4. **Authorization** — `PermissionName`, `config/authorization.php`,
   `AccountingPeriodPolicy` (governs both periods and financial years),
   `AppServiceProvider` policy registration and company-scoped route bindings.
5. **Phase 14 FX** — currency controls, realized FX, base-currency safety.

### Hard-stop assessment (§6)

No hard-stop condition was met:

| # | Condition | Finding |
|---|-----------|---------|
| 1 | Periods cannot support the workflow | Not met — periods are date-ranged, overlapping periods are prevented, they belong to a financial year, and closed periods already reject postings. |
| 2 | Posted journals can still be modified/deleted | Not met — `JournalService` refuses to edit a posted journal; there is no delete of posted history; `JournalStatus` is DRAFT/POSTED. |
| 3 | Multiple competing sources of truth | Not met — `journal_lines` is the only monetary store. |
| 4 | Reports calculate independently of posted journals | Not met — every report derives from posted lines. |
| 5 | Period logic contradicts the lifecycle | Not met. |
| 6 | Opening balances would require rewriting history | Not met — none are required (balances carry forward). |
| 7 | Year-end would need destructive modification | Not met — year close is a state change only. |
| 8 | Retained-earnings behaviour conflicts | Not met — retained earnings is derived; a closing journal would conflict and was therefore rejected (§12). |
| 9 | Multi-company isolation cannot be guaranteed | Not met — route binding + company-scoped queries. |
| 10 | A second engine would be required | Not met. |
| 11 | FX/base-currency behaviour would be silently changed | Not met — FX is untouched. |
| 12 | A safe migration would require rewriting history | Not met — **no migrations were added**. |

---

### Period lifecycle as found

- Create: `AccountingPeriodService::create()` rejects overlap and derives or
  validates the financial year.
- Open/close: `AccountingPeriodService::close()` / `reopen()`, both transactional
  with `lockForUpdate`, both validating the state transition.
- Dates: inclusive `start_date`/`end_date`.
- Overlap: prevented in the service and by a unique `(company_id, name)`.
- Fiscal year: a period belongs to a `financial_year_id`.
- Reopen: supported (Admin-only, `accounting.periods.reopen`).
- Locked state: none — only `OPEN`/`CLOSED` exist.
- Posting date validation: `AccountingPeriodService::assertPostableDate()` (period
  + financial year), used by `JournalPostingService` and every document flow.

**Gaps Phase 15 filled:** close did not run any controls and was not audited;
year close was not audited and did not run controls; there was no read-only
closing review; the control service had no journal/period integrity checks.

---

## 4. Scope Delivered

1. `AccountingControlService` extended with three read-only controls and a
   period-scoped entry point (`forPeriod()`).
2. New read-only `PeriodClosingCheckService` that composes those controls into a
   closing decision.
3. `AccountingPeriodService::close()` now runs the closing review inside its
   locked transaction, refuses on a critical finding, and audits the close;
   `reopen()` now audits.
4. `FinancialYearService::close()` now runs the company controls, refuses on a
   critical finding, and audits the year-end finalization.
5. New endpoint `GET /api/accounting/periods/{period}/closing-check`.
6. 14 new feature tests.

**Explicitly not delivered (deliberate, with justification):** opening-balance
records, a P&L year-end closing journal, a `LOCKED` period state, and a separate
year-end endpoint.

---

## 5. Database Changes

**None.** No migrations were added.

Inspection showed the required structures already exist:

- `accounting_periods` already carries `status`, `financial_year_id`,
  `closed_by`/`closed_at` and `reopened_by`/`reopened_at`, with a
  `(company_id, status)` index and CHECK constraints on date order and the
  `OPEN`/`CLOSED` vocabulary.
- `financial_years` already carries `status`, `closed_by`/`closed_at`.
- `audit_logs` already carries everything the audit events need.

Adding tables or columns for opening balances or stored report balances was
rejected as redundant storage of values the ledger already derives (§13).

---

## 6. Models / Enums

No new models and no new enums were required.

- `AccountingControlService` gained three public control codes:
  `UNBALANCED_POSTED_JOURNAL`, `INVALID_JOURNAL_LINE`,
  `POSTED_JOURNAL_OUTSIDE_PERIOD`.
- `AuditAction` was already sufficient: `Closed` and `Reopened` are reused for
  periods and `Closed` for the financial year (the subject type distinguishes
  them). No new vocabulary was invented.
- `PeriodStatus` is unchanged: the brief (§16) says not to add an arbitrary new
  lifecycle, and the existing `OPEN`/`CLOSED` is what the whole system already
  speaks.

---

## 7. Services

### `AccountingControlService` (extended, still read-only)

- `journalBalanceFindings(Company, ?AccountingPeriod)`: every posted journal in
  scope has at least two lines and SUM(debit) = SUM(credit) using `bcmath` to
  4 dp. A failure is a **Fail** because a posted entry that does not balance
  corrupts every report derived from the lines.
- `journalLineIntegrityFindings(Company, ?AccountingPeriod)`: every posted line
  resolves to an account owned by the journal's own company, plus a global count
  of orphaned lines (impossible under the FK, asked anyway).
- `periodCoverageFindings(Company, ?AccountingPeriod)`: every posted journal is
  covered by a period of the company.
- `run()` runs the six existing FX controls plus the three new ones.
- `forPeriod(Company, AccountingPeriod)` runs the six FX controls plus the three
  new ones scoped to the period's journal dates.

All methods return findings and write nothing — the class still has no write
path.

### `PeriodClosingCheckService` (new, read-only)

`review(Company, AccountingPeriod)` returns:

- `period` (id, name, dates, status, financial_year_id),
- `eligible` (not closed and no blocking findings),
- `already_closed`,
- `summary` (PASS/WARNING/FAIL counts),
- `blocking_findings`, `warning_findings`, `findings` (ControlFinding arrays),
- `required_action` (a sentence, or null when ready).

It contains no check of its own; every finding comes from
`AccountingControlService::forPeriod()`.

### `AccountingPeriodService` (extended)

- `close()` runs `PeriodClosingCheckService::review()` **after** taking the row
  lock and **before** writing, and throws a `ValidationException` naming the
  blocking findings when the period is not eligible. On success it writes the
  state transition and an `AuditAction::Closed` lifecycle record.
- `reopen()` now writes an `AuditAction::Reopened` lifecycle record.
- New dependency: `PeriodClosingCheckService`, `AuditService`.

### `FinancialYearService` (extended)

- `close()` runs `AccountingControlService::run()` after the year lock and
  refuses to finalize on any critical (Fail) finding. On success it writes an
  `AuditAction::Closed` lifecycle record against the financial year.
- New dependency: `AccountingControlService`, `AuditService`.

---

## 8. APIs / Routes

One new route, following the existing convention:

```
GET /api/accounting/periods/{period}/closing-check
```

Handled by `AccountingPeriodController::closingCheck()`, returning the
`PeriodClosingCheckService::review()` payload through the standard
`ApiResponse::success` envelope.

The existing close and year-close routes are **extended, not replaced**:

```
POST /api/accounting/periods/{period}/close            (now controls-gated + audited)
POST /api/accounting/periods/{period}/reopen           (now audited)
POST /api/accounting/financial-years/{financialYear}/close   (now controls-gated + audited)
```

No redundant year-end endpoint was added (§21): the existing financial-year close
already is the finalization operation.

---

## 9. Authorization

No new permissions were introduced — none were genuinely required (§27).

- `accounting.periods.close` (Admin-only) gates the close; unchanged.
- `accounting.periods.reopen` (Admin-only) gates reopen; unchanged.
- The financial-year close and closing-check reuse the period permission set
  through `AccountingPeriodPolicy`, exactly as before.
- The new closing-check route is authorized on `view` (the period policy's
  `view`, i.e. `accounting.periods.view`). Reading a period's closing readiness
  is a view of the period, not a control act; requiring `close` to *inspect*
  would hide the reason a close is refused from the people who must fix it.

Authorization remains server-side, via the existing policy in
`AccountingPeriodPolicy` registered explicitly in `AppServiceProvider`.

---

## 10. Company Isolation

- The `period` and `financialYear` route bindings resolve within the active
  company (`bindAccountingModelsToActiveCompany()`), so a foreign id 404s before
  any controller, policy or resource runs.
- The closing review and the close both read the period's own `company_id`; no
  company id is ever read from a request body.
- The new control queries are scoped by `company_id`.
- Verified by a test that a period from another company returns 404 for both
  `closing-check` and `close`.

---

## 11. Period Closing Rules

1. The actor must hold `accounting.periods.close` (else 403).
2. The period must resolve within the active company (else 404).
- The period must be in an eligible state: not already `CLOSED`.
- Inside a locked transaction the closing review runs; if it reports any
  blocking (Fail) finding, the close is refused with a 422 explaining why.
- Otherwise the period transitions `OPEN → CLOSED`, recording `closed_by` /
  `closed_at` and clearing the reopen pair.
- An `AuditAction::Closed` record is written in the same transaction.
- No posted journal, journal line, document or historical rate is touched. Close
  is a state change plus an audit row.

Existing rules are preserved: a closed period is immutable, overlapping periods
are prevented, and posting into a closed period/year is already refused by
`assertPostableDate()`.

---

## 12. Year-End Rules

- The existing `FinancialYearService::close()` is the year-end finalization.
- Preconditions (unchanged): the year must have at least one period and every
  period must be closed.
- New: after the year lock, `AccountingControlService::run()` is evaluated and a
  critical (Fail) finding refuses finalization with a 422.
- New: an `AuditAction::Closed` record is written against the `FinancialYear`.
- **No closing journal is written.** `JournalReportService::retainedEarnings()`
  derives retained earnings from posted lines through a date, so a generated
  closing entry would be counted a second time on the balance sheet (§12).
- The distinction the brief asks for is preserved by the existing model: an
  ordinary period close is a period transition; the final period is simply the
  last one closed; a finalized year is the financial-year transition. No new
  states or endpoints were created.

---

## 13. Opening Balance Rules

**Not implemented — deliberately.**

Inspection established that the existing architecture already derives every
balance the brief lists from posted journal lines:

- balance-sheet carry-forward: `BalanceSheetReportService` is cumulative through
  `to_date`;
- retained earnings: `JournalReportService::retainedEarnings()`;
- account balances, receivables/payables, cash/bank: derived from posted lines.

Section 13 of the brief is explicit: *"If those balances already carry forward
correctly through the ledger, do not create redundant stored balances. The ledger
remains authoritative."* Storing opening balances would create a second source of
truth, which §14 and §45 forbid. No opening-balance table, column, service or
endpoint was added.

Because opening balances are not implemented, the corresponding permission
examples (`accounting.opening_balances.*`) were not introduced.

---

## 14. Accounting Treatment

- The only thing that moves money remains `JournalPostingService`.
- Closing a period or a year moves no money and changes no amount.
- Retained earnings remains derived, never posted.
- No historical exchange rate, base amount, currency snapshot, tax snapshot or
  account mapping is read for mutation during a close.
- The period-end review reads posted history but writes none.

---

## 15. Audit Integration

Via the existing `AuditService` (no new audit system):

| Event | Action | Subject |
|-------|--------|---------|
| Period closed | `AuditAction::Closed` | `AccountingPeriod` |
| Period reopened | `AuditAction::Reopened` | `AccountingPeriod` |
| Year finalized | `AuditAction::Closed` | `FinancialYear` |

Each record carries WHO (`actor_id`, from the authenticated/resolved actor), WHAT
(action + before/after status and period/year identity in metadata), WHEN
(`created_at`), COMPANY (`company_id` from the subject), PERIOD (the subject id
and the period name/dates in metadata), ACTION and RESULT (the after status).

Secret scrubbing is inherited unchanged from `AuditService`; no new field is
logged, and no credential material is introduced.

---

## 16. Accounting Controls

Three controls were added to the existing `AccountingControlService` (not a
competing system), all read-only and non-destructive:

| Code | Meaning | Status on violation |
|------|---------|--------------------|
| `UNBALANCED_POSTED_JOURNAL` | A posted journal with < 2 lines, an unbalanced total, or a zero total | Fail |
| `INVALID_JOURNAL_LINE` | A posted line whose account is missing or belongs to another company; orphaned lines | Fail |
| `POSTED_JOURNAL_OUTSIDE_PERIOD` | A posted journal not covered by a period of its company | Fail |

They appear in the company-wide `/api/accounting/controls` report (through
`run()`) and in the period-scoped closing review (through `forPeriod()`). No
history is ever auto-repaired.

---

## 17. Security Verification

- **Authorization**: close/reopen/year-close are permission-gated; the
  closing-check is view-gated; all server-side. A Staff user is refused the
  closing-check; an Accountant may read it but is refused the close.
- **Company isolation**: cross-company period ids 404 at route binding; controls
  and reviews are company-scoped; a test asserts both endpoints 404 for a
  foreign period.
- **Tampering**: no request field influences company, status, closer, or the
  control outcome; the close reads only the locked model.
- **No stack traces**: refusals are `ValidationException` (422) with a message,
  never a 500.
- **Idempotency / replays**: closing an already-closed period is a controlled
  422; a second close writes no second audit record.
- **Secrets**: no new logging; existing scrubbing covers the audit rows.

---

## 18. Concurrency Verification

- The period close keeps the existing `lockForUpdate` on the period row, and the
  closing review now runs **inside** that locked transaction, so two concurrent
  closes are serialised: the loser sees `CLOSED` and receives a 422 rather than
  producing a second state.
- The year close keeps its own `lockForUpdate` and now evaluates controls inside
  the same transaction.
- Test: `a_second_close_after_a_committed_close_is_rejected` stages the database
  state a losing racer would find (a committed close) and asserts the loser is
  rejected, exactly one period state exists, and exactly one audit row is
  written. A true wall-clock race cannot be staged deterministically in a single
  test process; the committed-winner/loser staging is the same state a real race
  produces and is the state the lock must handle.

---

## 19. Tests

New file: `tests/Feature/Accounting/PeriodClosingTest.php` — **14 tests, 60
assertions**.

Coverage:

- clean period closes; state transition and `accepts_postings` reported;
- closing writes an audit record (action, company, actor, before/after status);
- reopening writes an audit record;
- a period containing an unbalanced posted journal cannot be closed (422, state
  unchanged);
- a period whose posted line points at another company's account cannot be
  closed;
- a second close of the same period is refused and leaves exactly one audit row;
- the closing-check reports a clean period as eligible without closing it, and
  returns the documented structure;
- the closing-check names the blocking finding and its status;
- an Accountant may read the closing-check but cannot close;
- a Staff user cannot read the closing-check;
- a period from another company 404s on both endpoints;
- finalizing a year writes an audit record;
- finalizing a year is blocked when a critical control fails;
- closing a period writes no journal.

Existing period/year/control test coverage (period lifecycle, reopen, closed-
period posting, financial-year closing, period generation, control service,
control API) continues to pass unchanged.

---

## 20. Full Regression

| Metric | Baseline (Phase 14) | Final (Phase 15) |
|--------|--------------------:|-----------------:|
| Tests | 1,166 | **1,180** |
| Assertions | 5,190 | **5,250** |
| Failures | 0 | **0** |
| Errors | 0 | **0** |
| Skipped | 0 | **0** |

- New Phase 15 tests: **14 tests / 60 assertions** (delta is exactly the new
  file; no existing test count changed).
- Full command: `php artisan test`.
- Focused regressions run first: `tests/Feature/Accounting` +
  `tests/Feature/Currency` → 672 passed / 2,557 assertions.
- Pint: `./vendor/bin/pint --test` → passed (all changed files clean).

---

## 21. Migration Verification

- **No migrations were added**, so there is nothing to migrate, roll back or
  re-run.
- `php artisan migrate:status` reports every migration `Ran` and **0 pending**.
- Because no schema change was made, there is no risk to existing financial data
  and no rollback path to verify.

---

## 22. Known Limitations

1. **Opening balances are not implemented** — the ledger already carries them
   forward (see §13).
2. **No P&L year-end closing journal** — retained earnings is derived (see §12).
3. **No `LOCKED` period state** — only `OPEN`/`CLOSED` exist; the brief says not
   to add an arbitrary lifecycle (§16).
4. **Financial-year reopen is not implemented** (pre-existing): a period inside a
   closed year cannot be reopened, by design.
5. **True wall-clock concurrency is represented, not staged** — the concurrency
   test writes the committed winner and exercises the loser, the same state a
   real race produces.
6. The company-wide control set (including FX controls) is evaluated on close; a
   critical FX inconsistency therefore blocks a close, which is intended (such a
   finding indicates corrupt posted history) but is broader than a strictly
   period-local check.

---

## 23. Deferred Features

Unchanged from Phase 14 and out of Phase 15 scope, per the brief: unrealized FX,
cross-currency cash/bank transfers, foreign bank reconciliation, fixed-asset FX
depreciation, reverse-rate synthesis, tax filing/compliance, GST/VAT-specific
compliance, e-invoicing, payroll, inventory, manufacturing, CRM, frontend
dashboard, budgeting, forecasting, external accounting/banking integrations. No
opening-balance subsystem was added because inspection did not prove it
necessary.

---

## 24. Files Changed

Added:

- `app/Services/Accounting/PeriodClosingCheckService.php`
- `tests/Feature/Accounting/PeriodClosingTest.php`
- `docs/report/PHASE_15_REPORT.md`

Modified:

- `app/Services/Accounting/Controls/AccountingControlService.php`
  (three controls, `forPeriod()`, `run()` extension)
- `app/Services/Accounting/AccountingPeriodService.php`
  (close gate, close/reopen audit, dependencies)
- `app/Services/Accounting/FinancialYearService.php`
  (year-finalization gate, audit, dependencies)
- `app/Http/Controllers/Api/Accounting/AccountingPeriodController.php`
  (closing-check action)
- `routes/api.php` (closing-check route)

No model, enum, migration or unrelated file was changed.

---

## 25. Final Status

**PASS WITH NOTES**

All acceptance criteria are met:

- Phase 1–14 functionality intact (full suite green; baseline preserved).
- The existing accounting engine remains the source of truth.
- Period closing is authorized, company scoped, audited, concurrency safe and
  transactionally close.
- Critical accounting-control failures block period closure and year
  finalization.
- Posted history is immutable; closing writes no journal and mutates nothing.
- Closed periods cannot be mutated through financial APIs (existing
  `assertPostableDate()` / `assertDateNotClosed()`).
- No duplicate accounting source of truth was created.
- No migrations, so migration safety is trivially satisfied (all `Ran`, 0
  pending).
- Pint passes; full suite passes with exact counts reported above.
- Phase 15 report complete.

The notes are the deliberate non-implementations required by the brief itself:
opening balances and a closing journal are omitted because the ledger already
carries the balances forward, and no `LOCKED` state was added for the same
reason the brief forbids an arbitrary new lifecycle.