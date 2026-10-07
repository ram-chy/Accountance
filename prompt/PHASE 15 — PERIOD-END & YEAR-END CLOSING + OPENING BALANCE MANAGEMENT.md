# PHASE 15 — PERIOD-END & YEAR-END CLOSING + OPENING BALANCE MANAGEMENT

## 1. ROLE

You are working on an existing production-oriented Accounting Web Application.

This is **Phase 15**.

Do NOT restart the project.

Do NOT rebuild the accounting system.

Do NOT replace existing accounting architecture.

Do NOT create a second accounting engine, ledger, journal engine, balance
engine, audit system, FX system, tax engine, or reporting engine.

You must first inspect the existing repository and all relevant completed Phase
1–14 implementation before changing code.

Work carefully and incrementally.

Do not hurry.

---

# 2. CURRENT PROJECT STATE

The project is an international-ready accounting web application using:

- Laravel 13
- PHP 8.4+
- MySQL 8.4
- InnoDB
- REST API
- JWT authentication
- Company-scoped accounting
- Accrual accounting
- Double-entry accounting
- DECIMAL-based financial arithmetic
- Existing fiscal periods
- Existing journal/posting engine
- Existing chart of accounts
- Existing accounting reports
- Existing cash/bank module
- Existing bank reconciliation
- Existing tax engine
- Existing credit/debit notes
- Existing fixed assets
- Existing audit and accounting controls
- Existing multi-currency and realized FX accounting

The existing accounting ledger remains the single source of truth.

---

# 3. PHASE 14 BASELINE — IMPORTANT

Phase 14 is COMPLETE.

Do not reopen or redesign Phase 14.

The latest Phase 14 implementation report establishes:

- Multi-currency support
- Exchange-rate history
- Company functional currency
- Journal FX handling
- Sales/purchase document FX integration
- Tax base amounts
- Settlement FX
- Realized FX
- Cash/bank FX integration
- Reporting integration
- Audit integration
- Accounting controls
- Authorization
- Company isolation
- Concurrency protection

Phase 14 final regression:

- 1,166 tests
- 5,190 assertions
- 0 failures
- 0 errors

Phase 14 status:

**PASS WITH NOTES**

Deferred Phase 14 items must remain deferred unless this phase discovers a
strict dependency requiring them.

Do NOT silently implement deferred Phase 14 features.

Examples of explicitly deferred Phase 14 functionality include:

- Unrealized FX
- Cross-currency cash/bank transfers
- Foreign bank reconciliation
- Fixed-asset FX depreciation
- Reverse-rate synthesis

Treat these as future scope, not Phase 15 scope.

---

# 4. PHASE 15 OBJECTIVE

Implement a controlled and auditable accounting period-end and year-end
workflow.

The objective is to make the accounting system capable of safely moving from:

OPEN ACCOUNTING PERIOD

to

PERIOD-END REVIEW

to

CLOSED PERIOD

and, where appropriate,

YEAR-END FINALIZATION

with controlled opening balances for the following accounting year.

The system must prevent accidental modification of finalized accounting history.

The phase must reuse the existing:

- AccountingPeriod model and lifecycle
- JournalService
- JournalPostingService
- LedgerService
- AccountingControlService
- Report services
- AuditService
- CompanyContext
- Authorization system
- Existing accounting permissions
- Existing multi-currency architecture
- Existing fiscal-date rules
- Existing immutable-posted-document rules

Do not create replacement versions of these systems.

---

# 5. FIRST TASK — INSPECT BEFORE CODING

Before implementing anything, inspect the repository thoroughly.

At minimum inspect:

## Existing accounting-period implementation

Find and review:

- AccountingPeriod model
- accounting_periods migration
- AccountingPeriodService
- period validation
- period close logic
- period permissions
- period policies
- period routes
- period resources
- period tests

Determine exactly:

- how periods are created
- how periods are opened
- how periods are closed
- whether periods have start/end dates
- whether overlapping periods are prevented
- whether a period belongs to a fiscal year
- whether closed periods can be reopened
- whether a locked state already exists
- how journals validate their financial date against periods

Do not duplicate existing behavior.

## Existing reports

Inspect:

- Trial Balance
- General Ledger
- Profit & Loss
- Balance Sheet
- Customer Statement
- Supplier Statement
- Receivables
- Payables
- Aging reports
- Cash/Bank activity
- FX-aware reporting

Determine exactly how:

- financial dates
- company
- fiscal periods
- base currency
- posted journals
- retained earnings
- opening balances

are currently handled.

## Existing controls

Inspect:

- AccountingControlService
- ControlStatus
- ControlFinding
- journal integrity checks
- period integrity checks
- reference integrity checks
- FX integrity checks
- control APIs

Extend existing controls instead of creating a competing control system.

## Existing audit

Inspect:

- AuditService
- AuditLog
- AuditAction
- audit policies
- audit routes
- audit permissions
- correlation/request IDs
- sensitive-data redaction

Reuse the existing audit infrastructure.

## Existing authorization

Inspect the actual permission enum/configuration/role matrix.

Do not assume the current permission names.

Reuse the project's existing authorization architecture.

---

# 6. HARD STOP CONDITIONS BEFORE IMPLEMENTATION

Stop and report a BLOCKED status if inspection reveals that:

1. Existing accounting periods cannot safely support the required workflow.
2. Posted journals can still be modified or deleted.
3. The ledger has multiple competing sources of truth.
4. Existing reports calculate financial balances independently from posted
   journals.
5. Existing period logic contradicts the intended accounting lifecycle.
6. Opening balances would require rewriting historical posted journals.
7. Year-end processing would require destructive modification of historical
   transactions.
8. Existing retained-earnings behavior conflicts with the proposed design in a
   way that cannot be resolved safely.
9. Multi-company isolation cannot be guaranteed.
10. A second journal/ledger/balance engine would be required.
11. Existing FX/base-currency behavior would be silently changed.
12. A safe migration would require rewriting existing financial history.

Do not work around a hard stop by silently changing architecture.

---

# 7. CORE DESIGN PRINCIPLE

Historical accounting data must remain immutable.

Never:

- rewrite posted journals
- alter posted journal lines
- change historical financial dates
- change historical exchange rates
- recalculate historical documents from current configuration
- silently change historical account mappings
- delete posted financial records
- mutate prior-period balances to make a closing operation succeed

If a correction is needed, use the application's existing correction/reversal
mechanisms where supported.

Do not invent a second correction engine.

---

# 8. PERIOD-END REVIEW

Introduce a controlled period-end review process.

The system must be able to determine whether a period is eligible for closure.

At minimum, the review should verify existing accounting controls relevant to
the period.

Potential checks include:

### A. Journal integrity

Verify:

- all posted journals are balanced
- no invalid journal lines exist
- no orphan journal lines exist
- all referenced accounts exist
- journal/company relationships are valid

### B. Period integrity

Verify:

- no posted journal exists outside an appropriate period
- no financial transaction exists in an invalid period state
- no posted transaction is dated inside a closed/locked period incorrectly

### C. Reference integrity

Verify:

- account references
- document references
- journal references
- company references

### D. Receivables/payables review

Where supported by existing modules:

- outstanding receivables
- outstanding payables
- unapplied allocations
- invalid allocations
- settlement integrity

Do not invent new settlement calculations.

### E. Bank reconciliation review

Where applicable:

- unresolved bank reconciliations
- incomplete reconciliation state
- period/account reconciliation status

Do not automatically reconcile anything.

### F. Tax review

Use the existing Tax Engine and reports.

Do not implement tax filing/compliance.

### G. Fixed assets review

Use existing fixed asset/depreciation functionality.

Do not recalculate historical depreciation merely to close a period.

### H. FX review

Use existing FX accounting/control infrastructure.

Do not implement unrealized FX in this phase.

---

# 9. PERIOD CLOSING

Implement a safe period closing workflow using the existing accounting-period
architecture.

The exact state transitions MUST be based on the repository's existing
implementation.

Do not create contradictory states.

At minimum, a period must not be closable when critical accounting integrity
controls fail.

A close operation must be:

- authenticated
- authorized
- company scoped
- audited
- transactionally safe
- concurrency safe
- idempotency aware

Closing must never modify posted historical transactions.

---

# 10. CLOSING VALIDATION

Before a close succeeds:

1. Resolve the company context.
2. Resolve the target accounting period.
3. Verify the period belongs to the current company.
4. Verify the actor has the required permission.
5. Verify the period is in an eligible state.
6. Run required accounting controls.
7. Reject closure if a critical FAIL finding exists.
8. Record the close action atomically.
9. Write the appropriate audit event.
10. Ensure concurrent close attempts cannot create an invalid state.

The system must return a meaningful validation response explaining why closing
was rejected.

Do not expose internal stack traces.

---

# 11. YEAR-END FINALIZATION

Implement year-end finalization only if the existing fiscal-period model can
support it safely.

A year-end operation must distinguish between:

- ordinary period close
- final period of a fiscal year
- finalized year

Do not treat every period close as a year-end close.

The implementation must be based on actual existing fiscal-period structure.

If the repository currently has no explicit fiscal-year entity, do NOT
automatically introduce a large fiscal-year subsystem unless inspection proves
it is necessary.

Prefer the smallest correct architecture.

---

# 12. PROFIT & LOSS YEAR-END HANDLING

This area requires particular care.

Inspect the current Balance Sheet implementation first.

The existing reports currently derive accounting results from posted journals
and existing retained-earnings logic.

Do NOT blindly introduce a "closing journal" that debits all revenue/expense
accounts and credits retained earnings.

Before implementing such a journal, determine whether it would:

- double count profit
- distort existing P&L
- change Balance Sheet behavior
- affect comparative reporting
- affect journal-based reports
- affect customer/supplier statements
- affect tax reporting
- affect audit/control logic

If a closing journal is architecturally incompatible with the existing report
model, do NOT implement it.

Prefer preserving the current accounting source of truth.

If an explicit year-end closing entry is required, implement it only after
proving the reporting/accounting treatment is correct across the entire system.

---

# 13. OPENING BALANCES

Implement controlled opening-balance management for a new accounting period/year
only if required by the existing accounting model.

Opening balances must never be used to rewrite the prior period.

Determine whether the current architecture already derives:

- balance sheet carry-forward
- retained earnings
- account balances
- receivable/payable balances
- cash/bank balances

from existing posted journals.

If those balances already carry forward correctly through the ledger, do not
create redundant stored balances.

The ledger remains authoritative.

---

# 14. OPENING BALANCE PRINCIPLES

If opening balance records are required:

- they must be company scoped
- they must be auditable
- they must be immutable after posting/finalization
- they must use DECIMAL arithmetic
- they must respect account type
- total debits must equal total credits
- they must respect the company's base currency
- FX handling must reuse Phase 14 architecture
- they must not overwrite historical transactions
- they must not create a second balance source of truth

Never store running balances merely to make opening-balance management easier.

---

# 15. RETAINED EARNINGS

Inspect the existing retained-earnings implementation before making changes.

The system must maintain correct treatment of:

- current-year profit/loss
- prior-year retained earnings
- balance-sheet continuity
- year-end reporting
- opening balance reporting

Do not hard-code an account ID.

If a retained earnings account is required, determine the appropriate
configuration mechanism from the existing Chart of Accounts architecture.

Do not introduce country-specific accounting rules.

---

# 16. PERIOD LOCKING

If the existing architecture distinguishes:

- OPEN
- CLOSED
- LOCKED

then Phase 15 may strengthen the transition rules.

Do not add an arbitrary new lifecycle.

A locked period must prevent financial mutation through every relevant
application path, not merely through the frontend.

Check all relevant services and endpoints.

At minimum test:

- journal creation
- journal update
- journal posting
- invoice posting
- bill posting
- payment posting
- cash/bank posting
- tax-related financial posting
- credit/debit note posting
- fixed asset posting
- depreciation posting
- FX settlement posting

where applicable to existing implementation.

Do not bypass period validation from individual modules.

---

# 17. POSTING DATE VALIDATION

Ensure every financial posting uses the existing period validation mechanism.

A transaction must not be posted into:

- a closed period
- a locked period
- an invalid company period

unless the existing architecture explicitly supports a controlled administrative
correction mechanism.

Do not create a hidden bypass.

---

# 18. ADMINISTRATIVE CORRECTIONS

Do NOT introduce a general "reopen period" capability merely for convenience.

If the existing system already supports reopening, inspect and preserve its
existing rules.

If it does not, do not create one unless there is a clear accounting/security
justification.

Any exceptional reopening, if already supported, must be:

- highly permissioned
- audited
- company scoped
- state constrained
- concurrency safe

Never silently reopen a period.

---

# 19. CLOSING CHECKLIST API

Provide a read-only period closing review endpoint if the architecture supports
it cleanly.

For example:

GET

`/api/accounting/periods/{period}/closing-check`

or an equivalent route following the repository's conventions.

The endpoint should return structured results such as:

- period information
- current state
- eligible/not eligible
- control findings
- blocking findings
- warnings
- summary
- required action

Do not duplicate the existing AccountingControlService response format.

Reuse it.

---

# 20. PERIOD CLOSE API

Use the repository's existing route conventions.

Potential shape:

POST

`/api/accounting/periods/{period}/close`

but inspect the current route before adding anything.

If this route already exists, extend/fix it rather than creating another
endpoint.

The operation must:

- authorize
- validate
- run controls
- close atomically
- audit
- return the updated period

---

# 21. YEAR-END FINALIZATION API

Only add a dedicated year-end endpoint if the existing architecture genuinely
requires a distinct operation.

Do not create redundant endpoints.

If required, follow existing conventions, for example:

POST

`/api/accounting/periods/{period}/finalize-year`

or another repository-consistent name.

Do not assume this exact URI.

---

# 22. AUDIT REQUIREMENTS

Use the existing AuditService.

Audit at minimum:

- period closing
- year-end finalization
- opening-balance creation/posting, if implemented
- exceptional period state changes
- rejected privileged period operations where the existing security-audit design
  supports it

Audit must contain enough information to answer:

- WHO
- WHAT
- WHEN
- COMPANY
- PERIOD
- ACTION
- RESULT

Do not log:

- passwords
- OTPs
- JWTs
- authorization headers
- cookies
- CSRF tokens
- secrets
- sensitive credentials

Use existing secret-scrubbing behavior.

---

# 23. ACCOUNTING CONTROLS

Extend the existing `AccountingControlService` only where necessary.

Potential new control codes may cover:

- PERIOD_NOT_READY
- POSTED_TRANSACTION_IN_CLOSED_PERIOD
- UNBALANCED_POSTED_JOURNAL
- INVALID_OPENING_BALANCE
- YEAR_END_NOT_FINALIZABLE
- OPENING_BALANCE_MISMATCH
- INVALID_PERIOD_SEQUENCE

Use the project's existing naming conventions instead of blindly adopting these
names.

Controls must remain:

- read-only
- diagnostic
- non-destructive

Never auto-repair accounting history.

---

# 24. REPORTING INTEGRATION

Review all affected reports.

At minimum verify:

- Trial Balance
- General Ledger
- Profit & Loss
- Balance Sheet
- Customer Statement
- Supplier Statement
- Receivables
- Payables
- Aging reports
- Cash/Bank activity
- Tax reports
- Fixed asset reports
- FX-aware reports

Period closing must not change the accounting source of truth.

Reports must continue to derive financial values from posted accounting data.

Do not introduce stored report balances.

---

# 25. MULTI-CURRENCY COMPATIBILITY

Phase 15 must be compatible with Phase 14.

Do not:

- rewrite historical exchange rates
- recalculate historical base amounts
- change historical currency snapshots
- implement unrealized FX
- implement reverse-rate synthesis
- implement cross-currency banking unless already supported

If opening balances involve foreign-currency accounts, reuse the Phase 14
currency/rate architecture.

Do not invent another FX calculation path.

---

# 26. COMPANY ISOLATION

Every operation must be company scoped.

Test:

- period IDs from another company
- opening-balance IDs from another company
- audit records from another company
- control findings from another company
- cross-company route model binding
- tampered company IDs
- unauthorized period close attempts

Cross-company resources should not leak through API responses.

Follow the existing 404/authorization conventions.

---

# 27. AUTHORIZATION

Inspect the existing permission architecture first.

Do not invent duplicate role logic.

Possible permissions may include:

- accounting.periods.view
- accounting.periods.create
- accounting.periods.update
- accounting.periods.close
- accounting.periods.finalize
- accounting.opening_balances.view
- accounting.opening_balances.create
- accounting.opening_balances.update
- accounting.opening_balances.post

These are examples only.

Use the actual project naming convention and introduce only permissions that are
genuinely required.

Apply authorization at the server side.

Never rely on frontend restrictions.

---

# 28. CONCURRENCY

Protect irreversible accounting operations from race conditions.

Test concurrent attempts to:

- close the same period
- finalize the same year
- create/post the same opening balance
- perform conflicting period state transitions

Database constraints and transactional locking should be used where appropriate.

Do not rely solely on application-level `if` checks.

A race must produce a controlled application response, not duplicate accounting
state.

---

# 29. DATABASE REQUIREMENTS

Before adding migrations:

- inspect existing schema
- avoid duplicate columns
- avoid duplicate tables
- avoid redundant balance storage
- preserve existing data
- use proper foreign keys where appropriate
- use appropriate indexes
- use DECIMAL for monetary values
- avoid FLOAT/DOUBLE
- enforce uniqueness where business rules require it

All migrations must be:

- additive where possible
- reversible
- safe for existing data
- verified on MySQL 8.4

Do not modify historical financial records during migration.

---

# 30. MODEL / SERVICE DESIGN

Prefer focused services.

Do not create a giant `PeriodService` that owns unrelated accounting behavior.

Potential services, only if inspection shows they are needed:

- PeriodClosingCheckService
- PeriodCloseService
- YearEndFinalizationService
- OpeningBalanceService

Reuse existing:

- AccountingPeriodService
- AccountingControlService
- JournalService
- JournalPostingService
- LedgerService
- AuditService
- CompanyContext
- existing authorization/policy infrastructure

Do not create duplicate services when an existing service already owns the
responsibility.

---

# 31. API DESIGN

All APIs must follow existing project conventions for:

- response envelopes
- validation
- resources
- pagination
- errors
- authentication
- authorization
- company context
- route model binding

Do not introduce a new API response format.

Do not expose internal model structures unnecessarily.

Use explicit API Resources.

---

# 32. VALIDATION

Validate all server-owned fields.

Never trust client input for:

- company_id
- period state
- close state
- finalized state
- journal IDs
- account IDs
- balances
- total amounts
- audit fields
- actor/user IDs
- posting timestamps

Do not make server-owned accounting fields mass assignable.

---

# 33. SECURITY REQUIREMENTS

Perform security review for:

- IDOR
- cross-company access
- authorization bypass
- mass assignment
- tampered financial totals
- period-state tampering
- opening-balance tampering
- duplicate close requests
- concurrent close requests
- invalid route bindings
- privilege escalation
- sensitive audit-data leakage

Test both authenticated and unauthorized access.

---

# 34. TESTING REQUIREMENTS

Create comprehensive tests.

At minimum:

## Period closing

Test:

- successful period close
- invalid state
- already closed period
- blocked close due to accounting control failure
- blocked close due to invalid posted journal
- blocked close due to invalid period data
- authorization
- company isolation
- audit event
- concurrent close

## Year-end

If implemented:

- valid finalization
- invalid non-year-end period
- incomplete prior periods
- blocked finalization
- authorization
- company isolation
- audit
- concurrency

## Opening balances

If implemented:

- balanced opening entry
- unbalanced entry rejected
- invalid account rejected
- company isolation
- currency validation
- base-currency handling
- authorization
- immutability after posting/finalization
- duplicate prevention
- concurrency

## Regression

Verify existing:

- journals
- invoices
- bills
- payments
- expenses
- cash/bank
- bank reconciliation
- tax
- credit/debit notes
- fixed assets
- FX
- audit
- controls
- reports

continue to work.

---

# 35. ACCOUNTING INTEGRITY TESTS

Explicitly test:

`SUM(debits) = SUM(credits)`

using exact decimal arithmetic.

Test:

- zero-value invalid cases
- negative-value invalid cases
- rounding
- currency precision
- base currency
- foreign currency
- period boundaries
- date boundaries
- closed period rejection
- locked period rejection

Do not use floating-point arithmetic for financial assertions.

---

# 36. PERIOD BOUNDARY TESTS

Test dates exactly at:

- period start
- period end
- one day before
- one day after

Also test:

- fiscal year boundary
- leap year dates where relevant
- timezone-safe date handling
- company timezone behavior if already supported

Financial dates must remain explicit and deterministic.

---

# 37. NO FRONTEND

Do NOT implement:

- Next.js pages
- dashboards
- frontend forms
- charts
- frontend state
- Tailwind UI
- frontend API clients

This phase is backend/accounting focused.

---

# 38. NO SEEDERS

Do NOT create or run seeders.

Do NOT create fake accounting data.

Do NOT insert demo users.

Do NOT insert demo accounts.

Do NOT insert demo currencies.

Use factories/tests only where appropriate.

---

# 39. NO UNRELATED REFACTORING

Do not:

- rename unrelated classes
- reorganize unrelated folders
- rewrite working services
- replace existing authentication
- replace JWT
- replace authorization
- replace the accounting engine
- replace reporting
- replace audit
- replace FX
- change API conventions

If an existing defect is discovered that directly blocks Phase 15, fix the
smallest possible defect and document it.

Do not turn Phase 15 into a general cleanup phase.

---

# 40. MIGRATION SAFETY

Before migration:

1. Inspect current schema.
2. Determine whether required structures already exist.
3. Avoid duplicate migrations.
4. Use safe foreign keys.
5. Use correct indexes.
6. Verify MySQL 8.4 compatibility.

Then verify:

- migrate
- rollback
- migrate again

Do not destroy existing financial data.

---

# 41. REQUIRED VERIFICATION COMMANDS

Run the project's appropriate test suite.

At minimum:

```bash
php artisan test
```

or the repository's established PHPUnit command.

Also run:

```bash
./vendor/bin/pint --test
```

If frontend exists but is intentionally out of scope, do not modify it.

Verify relevant routes.

Verify migration status.

Verify no unexpected migration was left pending.

---

# 42. REGRESSION BASELINE

Phase 14 baseline:

**1,166 tests / 5,190 assertions / 0 failures / 0 errors**

The final Phase 15 report must state:

- baseline test count
- final test count
- baseline assertion count
- final assertion count
- failures
- errors
- skipped tests, if any
- new Phase 15 tests
- regression results

Do not simply state "tests pass."

Provide exact numbers.

---

# 43. DOCUMENTATION

Create:

`docs/reports/PHASE_15_REPORT.md`

However, first inspect the repository and follow the actual established
report-directory convention if it differs.

The report must contain:

## 1. Executive Summary

## 2. Objectives

## 3. Existing Architecture Reviewed

## 4. Scope Delivered

## 5. Database Changes

## 6. Models / Enums

## 7. Services

## 8. APIs / Routes

## 9. Authorization

## 10. Company Isolation

## 11. Period Closing Rules

## 12. Year-End Rules

## 13. Opening Balance Rules

## 14. Accounting Treatment

## 15. Audit Integration

## 16. Accounting Controls

## 17. Security Verification

## 18. Concurrency Verification

## 19. Tests

## 20. Full Regression

## 21. Migration Verification

## 22. Known Limitations

## 23. Deferred Features

## 24. Files Changed

## 25. Final Status

Use:

- PASS
- PASS WITH NOTES
- BLOCKED

Do not mark PASS if required acceptance criteria are unverified.

---

# 44. DEFERRED FEATURES

Do NOT automatically include these in Phase 15 unless inspection proves they are
mandatory dependencies:

- Unrealized FX
- Cross-currency cash/bank transfers
- Foreign bank reconciliation
- Fixed-asset FX depreciation
- Reverse-rate synthesis
- Tax filing/compliance
- GST/VAT country-specific compliance
- E-invoicing
- Payroll
- Inventory
- Manufacturing
- CRM
- frontend dashboard
- budgeting
- forecasting
- external accounting integrations
- external banking APIs

Keep Phase 15 focused.

---

# 45. HARD ACCOUNTING RULES

These rules are non-negotiable:

1. Posted financial history is immutable.
2. Closed/locked periods cannot receive unauthorized financial postings.
3. No second ledger.
4. No second balance engine.
5. No stored running balances merely for convenience.
6. No floating-point financial arithmetic.
7. Every journal remains balanced.
8. Company isolation is mandatory.
9. Authorization is server-side.
10. Audit records remain immutable.
11. Historical FX snapshots remain immutable.
12. Historical tax snapshots remain immutable.
13. No automatic repair of accounting history.
14. No silent historical modification.
15. No frontend-only accounting controls.
16. No seeders.
17. No unrelated refactoring.

---

# 46. ACCEPTANCE CRITERIA

Phase 15 is accepted only if:

- Existing Phase 1–14 functionality remains intact.
- Existing accounting engine remains the source of truth.
- Period closing is properly authorized.
- Period closing is company scoped.
- Period closing is audited.
- Period closing is concurrency safe.
- Critical accounting-control failures block closure.
- Posted historical transactions remain immutable.
- Closed/locked periods cannot be mutated through financial APIs.
- Year-end behavior is correct if implemented.
- Opening balances are correct if implemented.
- Opening balances remain balanced.
- Existing reports remain correct.
- Existing FX behavior remains correct.
- Existing tax behavior remains correct.
- Existing fixed-asset behavior remains correct.
- Existing bank reconciliation behavior remains correct.
- Cross-company access is rejected.
- Tampered financial fields are rejected.
- No duplicate accounting source of truth exists.
- Migrations are verified.
- Pint passes.
- Full test suite passes.
- Exact final test/assertion counts are reported.
- Phase 15 report is complete.

---

# 47. FINAL INSTRUCTION TO OPENCODE

Do not start by writing code.

First inspect the repository.

Read the existing implementation and completed phase reports.

Understand the current accounting-period lifecycle.

Understand the current reporting and retained-earnings behavior.

Understand the existing AccountingControlService.

Understand the existing AuditService.

Understand the Phase 14 multi-currency architecture.

Then design the smallest correct implementation that fits the existing
architecture.

If the repository already implements part of this scope, reuse it.

If a requested feature conflicts with existing accounting behavior, STOP and
report the conflict instead of silently redesigning the system.

Do not restart the project.

Do not rewrite working accounting modules.

Do not create a second accounting engine.

Do not create a second ledger.

Do not create a second balance system.

Do not implement deferred Phase 14 features merely because they appear related.

Do not run seeders.

Do not create demo data.

Do not implement frontend.

Do not hurry.

Implement carefully.

Test thoroughly.

Run the complete regression suite.

Verify migrations.

Verify authorization.

Verify company isolation.

Verify accounting integrity.

Verify concurrency.

Verify audit behavior.

Then create the Phase 15 implementation report.

Final status must be exactly one of:

**PASS**

**PASS WITH NOTES**

**BLOCKED**
