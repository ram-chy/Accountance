# Phase 8 — Financial Year, Accounting Periods & Period Closing

## 1. Phase Objective

Implement controlled **Financial Year and Accounting Period management** for the
existing ERP accounting system.

Phase 8 must introduce accounting-period controls without creating a second
accounting engine.

The existing architecture from Phases 1–7 remains the source of truth:

- Existing Chart of Accounts
- Existing Journal / JournalLine system
- Existing LedgerService
- Existing JournalService
- Existing JournalPostingService
- Existing accounting document services
- Existing settlement/payment infrastructure
- Existing Cash & Banking infrastructure
- Existing Phase 6 financial reports
- Existing company isolation
- Existing authorization system
- Existing `Money` implementation
- Existing audit conventions

The purpose of this phase is to answer:

> **When is an accounting date allowed to receive or modify accounting data?**

A transaction may be valid according to its own business rules but must also be
rejected when its accounting date belongs to a closed accounting period or
closed financial year.

---

# 2. Critical Implementation Rule

## INSPECT FIRST — DO NOT ASSUME

Before writing any migration, model, service, controller, request, route, or
test:

Inspect the existing implementation for:

- accounting dates
- journal dates
- document dates
- posting dates
- journal status
- transaction status
- existing period-related fields
- existing financial-year concepts
- existing `AccountingPeriod`
- existing permissions
- existing policies
- existing company scopes
- existing authorization synchronisation
- existing posting services
- existing document posting flows
- existing Cash & Banking posting flow
- existing report date handling
- existing audit fields
- existing factories
- existing test helpers
- existing database constraints

Search the entire codebase before deciding that something does not exist.

If an equivalent mechanism already exists, **reuse it rather than introducing a
duplicate concept**.

Do not create a second period engine.

---

# 3. Scope

Phase 8 includes:

1. Financial Year management
2. Accounting Period management
3. Monthly period generation
4. Open / Closed period state
5. Financial-year state
6. Period validation
7. Posting protection for closed periods
8. Mutation protection for accounting data belonging to closed periods
9. Period closing
10. Period reopening
11. Financial-year closing
12. Financial-year reopening only if existing architecture and requirements
    justify it
13. Authorization
14. Company isolation
15. Auditability
16. Integration with existing posting services
17. Integration with Cash & Banking
18. Integration with existing reports
19. Comprehensive tests

---

# 4. Explicitly Out of Scope

Do NOT implement:

- a second ledger
- stored account balances
- stored period balances
- cached financial statements
- journal snapshots
- automatic retained-earnings journal generation unless the existing accounting
  design demonstrably requires it
- bank reconciliation
- bank statement imports
- CSV/PDF/Excel exports
- budget management
- budget variance
- comparative reporting
- consolidation
- multi-currency
- exchange-rate management
- tax-period management
- payroll periods
- inventory-period snapshots
- redesign of the accounting engine
- replacement of the existing journal system
- duplicate posting infrastructure
- a generic workflow/state-machine framework
- unnecessary repository/DSL/metadata abstractions

Phase 8 is specifically about **controlling accounting dates and period state**.

---

# 5. Core Accounting Model

The preferred architecture is:

```text
Financial Year
    └── Accounting Period
            └── accounting dates
                    └── Journals / Accounting Documents
```

A Financial Year contains one or more Accounting Periods.

For the current ERP, the normal implementation should be:

```text
Financial Year
2027-2028
    ├── Apr 2027
    ├── May 2027
    ├── Jun 2027
    ├── ...
    └── Mar 2028
```

However, do not hard-code April/March if the existing application has a
configured financial-year start month.

Inspect the existing configuration first.

If the project already defines a financial-year convention, use it.

If no convention exists, implement the smallest configurable mechanism required
by the existing requirements rather than introducing a complex fiscal-calendar
engine.

---

# 6. Financial Year

## 6.1 Required Concept

A financial year is company-scoped.

Minimum conceptual fields:

```text
company_id
name / label
start_date
end_date
status
created_by
closed_by
closed_at
timestamps
```

Do not add fields unless the existing architecture or requirements require them.

Possible statuses should be determined after inspecting existing enums.

If no existing equivalent exists, use a small explicit enum such as:

```text
OPEN
CLOSED
```

Do not introduce unnecessary states such as:

```text
DRAFT
ACTIVE
LOCKED
ARCHIVED
FINALIZED
REOPENED
```

unless the actual requirements justify them.

A reopened year should return to the appropriate existing open state rather than
creating a separate `REOPENED` state.

---

# 7. Financial Year Rules

A financial year:

- belongs to exactly one company
- has a start date
- has an end date
- must satisfy `start_date <= end_date`
- must not overlap another financial year for the same company
- may overlap financial years belonging to another company
- must never use another company's records
- must not be controlled by a client-supplied `company_id`

Recommended database-level/index strategy should be determined from the
project's database and existing migration conventions.

Do not rely exclusively on application validation for overlap prevention.

At minimum, the service must perform a transactional overlap check.

---

# 8. Accounting Period

An Accounting Period belongs to:

```text
company
financial year
```

Minimum conceptual fields:

```text
company_id
financial_year_id
name / label
start_date
end_date
status
closed_by
closed_at
timestamps
```

If an existing `AccountingPeriod` model/table already exists, extend and reuse
it instead of creating another one.

---

# 9. Period Boundaries

Period dates are inclusive.

Example:

```text
April 2027
start_date = 2027-04-01
end_date   = 2027-04-30
```

May starts:

```text
2027-05-01
```

No time component is required.

The existing project uses accounting DATE columns, so do not introduce
unnecessary timestamps into accounting-period boundaries.

---

# 10. Period Generation

Provide a service capable of generating the periods belonging to a financial
year.

For a monthly accounting system:

```text
Financial Year
2027-04-01 → 2028-03-31
```

generates:

```text
Apr 2027
May 2027
Jun 2027
Jul 2027
Aug 2027
Sep 2027
Oct 2027
Nov 2027
Dec 2027
Jan 2028
Feb 2028
Mar 2028
```

The generator must:

- respect the financial-year boundaries
- not create periods outside the year
- not duplicate existing periods
- be company-scoped
- be safe to call more than once
- use a transaction where appropriate

Do not create duplicate periods when generation is repeated.

---

# 11. Period Resolution

Create a focused service for resolving the accounting period for a date.

Conceptually:

```php
resolveForDate(
    Company $company,
    CarbonInterface $date
): AccountingPeriod
```

It should:

1. resolve the company-scoped financial year
2. resolve the company-scoped period
3. ensure the period exists
4. return its state

Do not allow callers to manually reproduce period lookup logic.

The purpose is to establish **one definition of which accounting period owns a
date**.

---

# 12. Period Status Validation

Create a focused service responsible for answering:

```text
Is this accounting date open for posting?
```

The service should be reusable by:

- Journal posting
- Sales posting
- Purchase posting
- Payment posting
- Cash & Bank posting
- other accounting document posting services already present

Do not duplicate:

```php
if ($period->status === ...)
```

across ten different services.

The period service should own the rule.

---

# 13. Closed Period Rule

Once a period is closed:

### Must be rejected

- new journal posting
- posting a draft journal
- posting a sales document that creates accounting entries
- posting a purchase document that creates accounting entries
- posting customer receipts
- posting supplier payments
- posting Cash & Bank transactions
- any other existing accounting operation that creates or posts journal entries

### Must also be reviewed

Existing accounting-document update/delete operations.

If an operation can alter accounting data or produce/reverse accounting entries
for a closed accounting date, it must be rejected.

Do not bypass the period service merely because an operation is an update rather
than a posting.

---

# 14. Drafts and Closed Periods

A critical distinction:

A draft is not yet accounting data.

Therefore:

- creating a draft should not automatically be rejected merely because its date
  belongs to a closed period **unless the existing business rules require date
  validation at draft creation**
- posting the draft into a closed period must always be rejected
- changing a draft's accounting date to an open period should remain subject to
  existing document rules
- changing an already posted document must follow existing immutability rules

Do not introduce unnecessary restrictions on draft creation.

Inspect the existing document lifecycle before deciding.

---

# 15. Posting Integration

This is the most important part of Phase 8.

The period rule must be enforced at the **actual accounting posting boundary**.

Do not rely only on controllers.

Do not rely only on Form Requests.

Do not rely only on frontend validation.

The service that actually creates/posts the accounting journal must verify:

```text
accounting date
        ↓
company
        ↓
financial year
        ↓
accounting period
        ↓
period status
        ↓
allowed / rejected
```

This must occur inside the same transactional posting flow where practical.

---

# 16. Existing Posting Services

Inspect and integrate with:

- `JournalService`
- `JournalPostingService`
- Sales posting
- Purchase posting
- Customer receipt posting
- Supplier payment posting
- Cash & Bank posting
- any other existing accounting posting service

Do not duplicate posting logic.

The period check should be inserted at the narrowest safe accounting boundary.

If `JournalPostingService` is the universal posting boundary and can reliably
determine:

- company
- journal date

then period validation may belong there.

If some existing flows create journals and require business-specific validation
before reaching the universal boundary, keep the common period check at the
journal posting boundary and add only the minimum additional validation required
upstream.

---

# 17. Cash & Banking Integration

Phase 7 introduced:

```text
CashBankPostingService
```

Phase 8 must ensure Cash & Bank posting respects accounting periods.

For example:

```text
POST /cash-bank-transactions/{id}/post
```

must reject a transaction whose `transaction_date` belongs to a closed period.

Do not add a separate cash/bank period mechanism.

Cash & Bank must use the same accounting-period service as every other
accounting transaction.

---

# 18. Journal Date Integrity

The accounting period is determined from the **accounting/journal date**, not:

- `created_at`
- `updated_at`
- `posted_at`

Use the same accounting-date semantics already established by Phase 6.

For existing reports:

```text
journal_date
invoice_date
bill_date
receipt_date
payment_date
```

remain the relevant accounting dates.

Do not change Phase 6 report semantics.

---

# 19. Closed Period Mutation

The implementation must inspect all existing accounting mutations.

Examples:

- journal update
- journal deletion
- document cancellation
- document reversal
- payment update
- payment deletion
- cash/bank transaction update
- cash/bank transaction deletion

The rule should be:

> An accounting mutation that changes accounting history cannot silently modify
> a closed period.

Where the existing architecture already makes posted accounting documents
immutable, preserve that rule.

Do not weaken existing immutability simply to implement period closing.

---

# 20. Period Closing

Provide a service such as:

```text
AccountingPeriodService::close()
```

or an equivalent focused service after inspecting existing naming conventions.

Closing must be transactional.

Before closing, validate the period.

Minimum validation:

- period belongs to active company
- period is currently open
- period dates are valid
- period belongs to an open financial year
- no invalid overlapping period state exists

Do not invent unnecessary accounting prerequisites.

For example, do not automatically require:

- zero receivables
- zero payables
- zero cash
- balanced inventory
- zero drafts

unless an existing requirement explicitly demands it.

A period close should not silently invent business rules.

---

# 21. Financial Year Closing

A financial year may only be closed when its accounting periods satisfy the
required closing condition.

The simplest acceptable rule is:

```text
All periods belonging to the financial year must be closed.
```

If the existing requirements specify a different rule, follow those
requirements.

Closing a financial year must be:

- company-scoped
- authorized
- transactional
- auditable

After financial-year closure, posting into any date belonging to that year must
be rejected.

---

# 22. Reopening

Reopening is a privileged operation.

It must not happen implicitly.

Provide an explicit operation such as:

```text
POST /api/accounting/periods/{period}/reopen
```

only if the project's existing API conventions support this action style.

Alternatively use the project's established state-change route convention.

Reopening should:

- verify company ownership
- verify authorization
- verify current state
- change the state explicitly
- record who reopened it
- record when it was reopened
- preserve existing accounting history

Do not delete or rewrite journal data when reopening.

---

# 23. Financial-Year Reopening

Do not automatically implement financial-year reopening.

First inspect:

- existing requirements
- accounting conventions
- current authorization model
- whether reopening a period is sufficient

If the project requires it, implement it as a separately authorized explicit
operation.

Otherwise, keep the financial year permanently closed once closed.

Document the decision in the Phase 8 implementation report.

---

# 24. Auditability

Closing/reopening is an accounting-control operation.

At minimum preserve:

```text
closed_by
closed_at
```

for periods.

If the project already has a general audit/event system, reuse it.

Do not build a second audit framework.

For reopening, if the schema needs additional fields, determine the smallest
existing-compatible design.

Do not add an unlimited audit-history system unless the project already uses
one.

---

# 25. Authorization

Inspect the existing permission naming convention first.

Phase 7 established:

```text
accounting.cash_bank.view
accounting.cash_bank.create
accounting.cash_bank.update
accounting.cash_bank.post
accounting.cash_bank.delete
```

Phase 6 established:

```text
accounting.reports.view
```

Phase 8 should use the smallest permission set that provides proper separation.

A likely structure is:

```text
accounting.periods.view
accounting.periods.manage
accounting.periods.close
accounting.periods.reopen
```

However:

**Do not blindly create these four permissions.**

Inspect whether the existing authorization architecture can safely represent:

- viewing
- creating/updating periods
- closing
- reopening

with fewer permissions.

Use the smallest defensible set.

Recommended role intent:

| Role       | View |                                     Manage |                         Close | Reopen |
| ---------- | ---: | -----------------------------------------: | ----------------------------: | -----: |
| Admin      |  yes |                                        yes |                           yes |    yes |
| Accountant |  yes |                                        yes |                           yes |    yes |
| Manager    |  yes | appropriate only if existing policy allows | no unless explicitly required |     no |
| Staff      |   no |                                         no |                            no |     no |

Do not alter existing Phase 5–7 permissions.

The final matrix must reflect the project's actual authorization design after
inspection.

---

# 26. Company Isolation

All financial years and periods are company-scoped.

Never accept:

```text
company_id
```

from the client.

Company comes from:

```text
CompanyContext
```

and the existing:

```text
company.context
```

middleware.

Every lookup must be scoped by active company.

A period from Company A must not be visible or mutable while Company B is
active.

Tests must cover:

- foreign financial year
- foreign accounting period
- foreign period route binding
- foreign IDs in requests
- unrelated company context

---

# 27. Route Model Binding

If route model binding is used, verify that the binding cannot resolve another
company's period.

Do not assume model binding automatically provides tenancy.

Use the existing company-aware binding strategy where available.

If explicit controller resolution is required, use:

```php
where('company_id', $company->getKey())
```

before resolving the record.

---

# 28. API Endpoints

The exact routes must follow the existing project's conventions.

A likely structure is:

```text
GET    /api/accounting/financial-years
POST   /api/accounting/financial-years
GET    /api/accounting/financial-years/{financialYear}
PUT    /api/accounting/financial-years/{financialYear}

GET    /api/accounting/periods
POST   /api/accounting/periods
GET    /api/accounting/periods/{period}
PUT    /api/accounting/periods/{period}

POST   /api/accounting/periods/{period}/close
POST   /api/accounting/periods/{period}/reopen
```

But these are **candidate routes**, not instructions to blindly implement them.

Inspect existing route conventions first.

If financial years are created together with their periods, avoid exposing
redundant CRUD endpoints.

Keep the API simple.

---

# 29. Financial Year Creation

A financial year creation request should minimally validate:

- start date
- end date
- valid date range
- no overlapping company financial year

If the application has a configured financial-year start month, respect it.

Do not allow a financial year to be created with periods outside its boundaries.

---

# 30. Period Creation

If manual period creation is allowed, validate:

- company
- financial year
- period dates
- period is entirely inside financial year
- no overlap
- no duplicate period

If the phase establishes automatic monthly generation as the official approach,
avoid unnecessary individual-period creation APIs.

The final implementation should have one clear source of truth for period
creation.

---

# 31. Period List

The period list should support useful filters only.

Potential filters:

```text
financial_year_id
status
from_date
to_date
```

Do not implement a large generic search/filter DSL.

The list should be company-scoped.

Ordering should be deterministic:

```text
start_date ASC
```

or another project-consistent ordering.

---

# 32. Period Close Response

Use the existing API response wrapper:

```json
{
  "success": true,
  "message": "Accounting period closed successfully.",
  "data": {
    ...
  }
}
```

Do not introduce a new response envelope.

---

# 33. Error Behaviour

Use the project's existing HTTP error conventions.

Examples:

### Closed period

```text
409 Conflict
```

or the project's established business-rule status.

Message should clearly explain:

> The accounting period is closed and this transaction cannot be posted.

Do not return a generic 500.

### Foreign company resource

Use the existing company-isolation behavior, typically:

```text
404
```

where route binding hides another company's resource.

### Unauthorized close/reopen

```text
403
```

### Invalid date range

```text
422
```

---

# 34. No Frontend Assumptions

Phase 8 is primarily a backend/accounting-control phase.

Do not redesign the frontend.

If this project has a separate frontend phase workflow, create only the backend
contract required for the later frontend implementation.

API responses must be stable and explicit.

---

# 35. Model Requirements

Potential models:

```text
FinancialYear
AccountingPeriod
```

Only create them if equivalent models do not already exist.

Models should include:

- company relationship
- appropriate casts
- status enum
- audit relationships if existing conventions support them
- date handling
- guarded/fillable conventions matching existing models

Do not add business logic to models that belongs in services.

---

# 36. Service Structure

Prefer focused services such as:

```text
Accounting/
    FinancialYearService
    AccountingPeriodService
    AccountingPeriodResolver
```

Names must match existing project conventions.

Do not create:

```text
AccountingEngine
PeriodEngine
FiscalCalendarEngine
WorkflowEngine
AccountingRulesEngine
```

No generic framework is required.

---

# 37. Period Validation Integration

The preferred dependency flow is:

```text
Posting Service
      ↓
Accounting Period Resolver
      ↓
Accounting Period
      ↓
Open/Closed validation
      ↓
Journal Posting
```

For universal journal posting:

```text
JournalPostingService
      ↓
resolve period from journal.company + journal.journal_date
      ↓
assert period open
      ↓
post journal
```

This is preferred because it prevents a caller from accidentally bypassing the
rule.

However, inspect the existing posting architecture before modifying it.

---

# 38. Existing Reports Must Remain Read-Only

Phase 6 reports must continue to work after Phase 8.

Closing a period must not:

- delete journals
- modify journal lines
- alter report calculations
- create fake balances
- create report snapshots

Reports should simply continue reading historical posted data.

A closed period means:

> historical accounting data cannot be newly posted or modified through
> accounting operations.

It does **not** mean:

> historical accounting data disappears.

---

# 39. Phase 6 Compatibility

Verify that these reports remain functional:

- Trial Balance
- General Ledger
- Profit & Loss
- Balance Sheet
- Customer Statement
- Supplier Statement
- Receivables
- Payables
- Aged Receivables
- Aged Payables
- Cash/Bank Activity

Historical closed periods must remain reportable.

A report for a closed period must return the same accounting data that existed
before closing.

---

# 40. Phase 7 Compatibility

Verify all Phase 7 functionality remains intact:

- Cash/bank account classification
- Bank metadata
- Deposit
- Withdrawal
- Transfer
- Draft lifecycle
- Posting
- Double-post protection
- Company isolation
- Permissions
- Ledger integration

A Cash & Bank transaction dated inside a closed period must not post.

A Cash & Bank transaction dated inside an open period must continue to post
exactly as before.

---

# 41. Existing Accounting Documents

Inspect all existing accounting documents from Phases 1–7.

At minimum investigate:

- Sales invoices
- Purchase bills
- Customer receipts
- Supplier payments
- Journals
- Cash/bank transactions

For every posting path determine:

```text
What is its accounting date?
What service actually creates the journal?
What service actually posts the journal?
Can it modify an already posted journal?
```

Integrate the period rule at the correct boundary.

Do not assume the list above is exhaustive.

---

# 42. Date Boundary Tests

Mandatory tests:

### First day

A transaction dated exactly:

```text
period.start_date
```

is allowed while open.

### Last day

A transaction dated exactly:

```text
period.end_date
```

is allowed while open.

### Day before

A transaction dated immediately before the period belongs to the previous
period.

### Day after

A transaction dated immediately after the period belongs to the next period.

### Closed

Any transaction dated anywhere inside a closed period is rejected.

---

# 43. Financial Year Boundary Tests

Test:

```text
financial_year.start_date
financial_year.end_date
```

and the dates immediately outside them.

A date outside the financial year must not resolve to that year.

Two adjacent years must not accidentally overlap.

Example:

```text
FY 2026-27:
2026-04-01 → 2027-03-31

FY 2027-28:
2027-04-01 → 2028-03-31
```

The shared boundary must not produce an overlap because:

```text
2027-03-31
```

belongs only to the first year and:

```text
2027-04-01
```

belongs only to the second.

---

# 44. Closing Validation Tests

Test:

- open period can close
- already closed period cannot close again
- foreign-company period cannot close
- unauthorized user cannot close
- authorized user can close
- closing records actor/time
- closed period rejects posting
- closed period remains reportable

---

# 45. Reopening Tests

If reopening is implemented:

- authorized user can reopen
- unauthorized user receives 403
- foreign-company period cannot be reopened
- open period cannot be reopened unnecessarily
- reopened period accepts valid posting again
- existing journal history remains unchanged
- reopen does not delete data

If reopening is deliberately not implemented, document that decision clearly in
the final phase report.

---

# 46. Financial Year Closing Tests

If financial-year closing is implemented:

- all periods open → year cannot close
- all periods closed → year can close
- closed year rejects posting
- year close is company-scoped
- unauthorized user cannot close
- closing is idempotency-safe
- historical reports remain available

---

# 47. Authorization Tests

Test every applicable role.

At minimum:

```text
Admin
Accountant
Manager
Staff
```

Verify the final permission matrix rather than assuming the matrix in this
document is automatically correct.

Critical distinction:

```text
view
manage
close
reopen
```

must not accidentally collapse into one permission if the project's
accounting-control requirements require separation.

Also verify:

- Staff cannot bypass using direct API calls
- Manager cannot close/reopen unless explicitly granted
- create/update permission does not automatically grant close/reopen
- close permission does not automatically grant unrelated accounting permissions

---

# 48. Company Isolation Tests

Mandatory:

1. Company A cannot see Company B financial years.
2. Company A cannot see Company B periods.
3. Company A cannot close Company B period.
4. Company A cannot reopen Company B period.
5. Company A cannot post into Company B period.
6. Query-string IDs cannot bypass company scoping.
7. Route model binding cannot expose foreign records.

---

# 49. Transactional Integrity

Closing must be transactional.

If any part of a close operation fails:

```text
no partial close
```

Similarly, financial-year close must not leave half the intended state applied.

Do not implement:

```text
update status
then perform validation
```

in a way that leaves inconsistent state.

Validate first, then mutate within a transaction.

---

# 50. Concurrency

Inspect existing locking conventions.

At minimum protect against:

```text
Request A closes period
Request B posts transaction
```

racing with one another.

The implementation must not simply perform an unlocked:

```text
if open
then post
```

check when a concurrent close could invalidate the decision.

Use the existing transaction/row-lock conventions where appropriate.

Do not build a custom concurrency framework.

---

# 51. Money

Phase 8 itself should not perform financial calculations unless required.

When it does interact with accounting amounts:

- use existing `Money`
- never cast accounting amounts to float
- preserve `DECIMAL(20,4)`
- do not introduce balance fields

---

# 52. Database Constraints

Use database constraints where they provide genuine integrity.

Potential examples:

- financial year belongs to company
- period belongs to company
- foreign keys
- unique period identity within company/year
- appropriate indexes for date/status/company queries

Do not over-engineer constraints that MySQL cannot express cleanly.

Application-level transactional validation remains required for date-range
overlap.

---

# 53. Indexing

Inspect the existing indexes first.

Likely useful access patterns:

```text
financial_years:
(company_id, start_date, end_date)
(company_id, status)

accounting_periods:
(company_id, financial_year_id, start_date)
(company_id, status)
```

Do not add indexes blindly.

Only add indexes justified by actual query patterns.

---

# 54. API Resources

Use the project's existing Resource convention if appropriate.

If existing accounting endpoints return Resources, follow that convention.

Do not create Resources that simply duplicate service output without adding
value.

Keep response shapes consistent with the rest of the API.

---

# 55. Factories

Add factories only where the project already uses factories for the
corresponding domain models.

Factories should make tests readable.

Do not add seeders automatically.

**Do not run seeders.**

---

# 56. No Seeder Execution

OpenCode must not execute:

```bash
php artisan db:seed
php artisan migrate:fresh --seed
```

or any equivalent seeding command unless explicitly instructed.

Migration testing may be performed without seeding.

---

# 57. Testing Requirements

Add focused Feature/Unit tests for:

### Financial Year

- create
- validation
- overlap
- company isolation
- permissions
- boundaries
- status

### Accounting Period

- creation/generation
- correct boundaries
- duplicate prevention
- overlap prevention
- company isolation
- list/filter
- permissions

### Period Resolution

- correct financial year
- correct period
- boundary dates
- missing period
- foreign company protection

### Period Closing

- close
- duplicate close
- authorization
- audit fields
- closed-state enforcement

### Reopening

if implemented:

- authorization
- state transition
- auditability
- posting after reopen

### Posting Integration

Test every existing accounting posting path affected by Phase 8.

At minimum:

- journal
- sales
- purchase
- customer receipt
- supplier payment
- cash/bank

A closed period must reject posting.

An open period must continue to work.

---

# 58. Regression Testing

The entire existing suite must continue passing.

The final report must explicitly state:

```text
Phase 1–7 baseline
+
Phase 8 tests
=
full suite
```

Do not delete or weaken existing tests to make Phase 8 pass.

Do not modify old test expectations unless Phase 8 exposes a genuine
pre-existing defect.

If an existing test fails because Phase 8 changes an intentionally documented
business rule, explain the change explicitly.

---

# 59. Required Verification

Run the appropriate focused tests.

Example:

```bash
php artisan test tests/Feature/Accounting
```

or the actual relevant test directories discovered from the project.

Then:

```bash
php artisan test
```

Run:

```bash
./vendor/bin/pint
./vendor/bin/pint --test
```

Verify routes:

```bash
php artisan route:list --path=accounting
```

Verify migrations using the project's established migration verification
approach.

Run syntax checks where appropriate.

Do not claim verification that was not actually performed.

---

# 60. Final Phase Report

After implementation, create:

```text
PHASE_8_REPORT.md
```

The report must include:

1. Phase objectives
2. Files changed
3. Migrations
4. Models
5. Enums
6. Services
7. Requests
8. Resources
9. Controllers
10. Routes
11. Authorization
12. Company isolation
13. Period rules
14. Posting integration
15. Financial-year rules
16. Closing/reopening behavior
17. Auditability
18. Security considerations
19. Performance
20. Tests
21. Commands run
22. Issues discovered
23. Known limitations
24. Outstanding work
25. Requirements traceability
26. Regression result

Do not merely state "implemented".

Explain how the implementation works.

---

# 61. Requirements Traceability

The final report must contain a table similar to:

| Requirement                                    | Implementation | Test |
| ---------------------------------------------- | -------------- | ---- |
| Company-scoped financial year                  | ...            | ...  |
| No overlapping financial years                 | ...            | ...  |
| Monthly accounting periods                     | ...            | ...  |
| Period belongs to financial year               | ...            | ...  |
| Open/closed state                              | ...            | ...  |
| Date resolves to one period                    | ...            | ...  |
| Closed period rejects journal posting          | ...            | ...  |
| Closed period rejects sales posting            | ...            | ...  |
| Closed period rejects purchase posting         | ...            | ...  |
| Closed period rejects receipt posting          | ...            | ...  |
| Closed period rejects supplier payment posting | ...            | ...  |
| Closed period rejects cash/bank posting        | ...            | ...  |
| Historical reports remain available            | ...            | ...  |
| Company isolation                              | ...            | ...  |
| Authorization                                  | ...            | ...  |
| Close auditability                             | ...            | ...  |
| Reopen behavior                                | ...            | ...  |
| No duplicate accounting engine                 | ...            | ...  |
| No stored balances                             | ...            | ...  |
| Phase 6 compatibility                          | ...            | ...  |
| Phase 7 compatibility                          | ...            | ...  |
| Full regression suite                          | ...            | ...  |

---

# 62. Hard Architectural Rules

OpenCode MUST NOT:

- create a second ledger
- create stored account balances
- create period balance columns
- duplicate journal posting logic
- duplicate company isolation logic
- trust `company_id` from the client
- use floating-point arithmetic for accounting amounts
- bypass `JournalPostingService`
- create a second Cash & Bank posting mechanism
- alter Phase 6 report calculation semantics
- weaken Phase 5/6/7 security
- add unnecessary abstractions
- create a generic workflow engine
- introduce a generic repository layer without a concrete need
- create a DSL
- add caching for period state
- modify posted accounting history simply because a period is reopened
- automatically create closing journals without a documented accounting
  requirement
- run seeders
- silently change unrelated modules

---

# 63. Hard Stop Conditions

Stop implementation and report the finding if:

1. An existing financial-year/period implementation already exists but its
   intended behavior is unclear.
2. A proposed change would require replacing the existing journal engine.
3. A proposed period rule conflicts with an existing documented accounting rule.
4. The universal posting boundary cannot safely determine company/date.
5. Existing accounting documents use inconsistent accounting dates that require
   a business decision.
6. Financial-year closing requires retained-earnings accounting that the
   existing chart-of-accounts design cannot support honestly.
7. A migration would destroy existing accounting data.
8. A requested rule cannot be implemented without changing an existing Phase 1–7
   contract.
9. A concurrency-safe close/post interaction cannot be guaranteed with the
   current architecture.
10. Tests reveal a genuine accounting integrity problem that Phase 8 would
    otherwise hide.

Do not work around a hard stop by inventing a new abstraction.

Document it.

---

# 64. Implementation Order

Use this order:

## Step 1 — Inspect

Inspect:

- schema
- migrations
- models
- enums
- accounting services
- posting services
- permissions
- policies
- routes
- tests
- Phase 6 reports
- Phase 7 Cash & Bank

Do not modify anything.

---

## Step 2 — Architecture Report

Before implementation, document internally:

```text
Existing period/fiscal concepts:
...

Universal posting boundary:
...

Accounting date sources:
...

Existing authorization:
...

Existing company isolation:
...

Required integration points:
...
```

If the existing architecture differs from this blueprint, follow the actual
project architecture.

---

## Step 3 — Database

Only after inspection:

- create/modify financial-year storage if required
- create/modify accounting-period storage if required
- add required indexes/constraints

No unnecessary migration.

---

## Step 4 — Domain Layer

Implement:

- enums
- models
- relationships
- focused services
- period resolver
- period validation

---

## Step 5 — Posting Integration

Integrate period validation into the actual accounting posting boundary.

Then integrate any necessary upstream document-specific paths.

---

## Step 6 — Management API

Implement:

- financial-year endpoints
- period endpoints
- close
- reopen if required

Use existing API conventions.

---

## Step 7 — Tests

Write focused tests before considering the phase complete.

Test both:

```text
open period → posting succeeds
closed period → posting fails
```

for every relevant accounting flow.

---

## Step 8 — Regression

Run:

```bash
php artisan test
```

and all required quality checks.

---

## Step 9 — Report

Create:

```text
PHASE_8_REPORT.md
```

with complete traceability and limitations.

---

# 65. Definition of Done

Phase 8 is complete only when all of the following are true:

- Financial years are company-scoped.
- Financial years cannot overlap within a company.
- Accounting periods are company-scoped.
- Accounting periods belong to a financial year.
- Period boundaries are deterministic.
- A date resolves to the correct period.
- Period state is explicit.
- Closing is transactional.
- Closed periods reject new accounting postings.
- The restriction occurs at the real accounting posting boundary.
- Sales respects period state.
- Purchases respect period state.
- Customer receipts respect period state.
- Supplier payments respect period state.
- Cash & Bank respects period state.
- Existing journal posting remains the source of truth.
- Posted accounting history is not rewritten.
- Historical reports remain available.
- Company isolation is enforced.
- Authorization is enforced.
- Close/reopen operations are auditable where implemented.
- No stored balances are introduced.
- No second ledger is introduced.
- No second posting engine is introduced.
- No unnecessary abstraction is introduced.
- No seeders are run.
- Phase 6 remains compatible.
- Phase 7 remains compatible.
- Focused Phase 8 tests pass.
- Full regression suite passes.
- Pint passes.
- Route verification passes.
- `PHASE_8_REPORT.md` is created.

---

# 66. Final Instruction to OpenCode

Implement Phase 8 carefully and incrementally.

**Do not rush.**

The existing accounting system is already functioning through Phase 7. Treat
that implementation as production accounting infrastructure.

Your first responsibility is to understand it.

Your second responsibility is to introduce period control with the smallest safe
change.

Your third responsibility is to prove that existing accounting behavior remains
correct.

Do not rewrite working accounting code merely to make Phase 8 look cleaner.

Do not invent business rules that are not required.

Do not create abstractions without a concrete need.

When a requirement conflicts with the existing implementation, stop, document
the conflict, and identify the smallest safe resolution.

At completion, provide:

```text
PHASE_8_REPORT.md
```

and the exact test/verification results.

The final report must state the exact:

```text
tests
assertions
failures
errors
skipped
```

and must not claim success unless the commands were actually executed.
