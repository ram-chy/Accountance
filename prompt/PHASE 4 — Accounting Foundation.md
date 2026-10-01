# PHASE 4 — ACCOUNTING FOUNDATION

## Project

Accounting Web Application

## Phase

Phase 4 — Accounting Foundation

---

# 1. Objective

Implement the core accounting foundation of the Accounting Web Application.

This phase establishes the underlying financial model required for all future
accounting modules.

The system must support a proper:

- Double-entry accounting model
- Chart of Accounts foundation
- Journal
- Journal Lines
- Debit/Credit validation
- Posting
- General Ledger foundation
- Account balances
- Accounting periods foundation
- Transaction dates
- Financial precision
- Accounting integrity
- Company-scoped accounting data

The central accounting rule is:

```text
TOTAL DEBIT = TOTAL CREDIT
```

A transaction must never be posted if this rule is violated.

---

# 2. Critical Working Rules

Before implementation:

1. Read the completed Phase 3 implementation.
2. Read `PHASE_3_REPORT.md`.
3. Inspect the actual database schema.
4. Inspect company-context implementation.
5. Inspect authentication and authorization.
6. Do not rewrite working Phase 1–3 functionality.
7. Do not implement future business modules.
8. Do not create fake accounting data.
9. Do not run seeders without explicit permission.
10. Do not silently change the approved accounting architecture.
11. If a serious accounting-model conflict is discovered, STOP and report it.
12. Accounting calculations must be deterministic.
13. Monetary calculations must use decimal-safe arithmetic.
14. Never use floating-point arithmetic for accounting amounts.
15. All accounting records must be company-scoped.
16. Posted accounting records must not be casually editable or deletable.
17. Use database transactions for accounting operations.
18. Test accounting invariants extensively.

This phase must prioritize **financial correctness over development speed**.

---

# 3. Accounting Basis

The system will use:

```text
Double-entry accounting
+
Accrual accounting
```

The architecture must support standard accounting principles.

The accounting system must distinguish:

```text
Draft
Posted
```

A journal in Draft status may be edited.

A Posted journal becomes part of the accounting record and must be protected
from ordinary modification.

---

# 4. Company Scope

Every accounting record must belong to a company.

The architecture must follow:

```text
Authenticated User
        ↓
Active Company
        ↓
Accounting Transaction
        ↓
Accounts
        ↓
Journal
        ↓
Journal Lines
```

A user must never be able to access another company's accounting records.

This protection must be implemented server-side.

Do not rely on the frontend to supply a trusted `company_id`.

The backend must obtain the company context from the authenticated user's
authorized company context.

---

# 5. Core Accounting Model

The foundation should conceptually be:

```text
Company
   │
   ├── Accounting Periods
   │
   ├── Chart of Accounts
   │      │
   │      └── Accounts
   │
   └── Journals
          │
          └── Journal Lines
                 │
                 └── Accounts
```

A journal contains multiple journal lines.

Each journal line belongs to exactly one account.

A journal must have at least two lines.

---

# 6. Chart of Accounts Foundation

Create the Chart of Accounts structure.

The system must support standard account classifications.

At minimum:

```text
Asset
Liability
Equity
Revenue
Expense
```

These are the fundamental account types.

Do not create dozens of accounting types.

---

# 7. Account Model

Create an `accounts` table.

The exact final columns should be determined after inspecting the existing
architecture, but it should support at least:

```text
id
company_id
parent_id nullable
code
name
account_type
description nullable
is_active
is_system
created_at
updated_at
```

Additional fields may be introduced only when justified by the accounting model.

---

# 8. Account Code

Each account must have a company-unique account code.

Example:

```text
1000
1010
1100
2000
3000
4000
5000
```

The exact numbering scheme must remain configurable.

Do not hard-code a country's chart-of-accounts numbering system.

Constraint:

```text
company_id + code = UNIQUE
```

Two different companies may use the same account code.

---

# 9. Account Name

Account names must be meaningful and unique enough within the company.

Do not allow accidental duplicate accounts that create ambiguity.

Consider a company-scoped uniqueness strategy appropriate to the final schema.

---

# 10. Account Hierarchy

Support parent/child accounts.

Example:

```text
1000 Assets
    1100 Current Assets
        1110 Cash
        1120 Bank
        1130 Accounts Receivable

1200 Non-current Assets

2000 Liabilities
    2100 Current Liabilities
        2110 Accounts Payable

3000 Equity

4000 Revenue

5000 Expenses
```

The hierarchy should use:

```text
parent_id
```

or an equivalent simple tree structure.

Do not implement a complex nested-set/tree package.

A normal adjacency-list hierarchy is sufficient.

---

# 11. Account Type Rules

Each account must have exactly one fundamental account type:

```text
ASSET
LIABILITY
EQUITY
REVENUE
EXPENSE
```

Store these consistently.

Do not allow arbitrary strings.

Use an enum or validated controlled value according to Laravel/MySQL
architecture.

---

# 12. Normal Balance

The accounting engine must understand the normal balance of each account type.

Conceptually:

```text
Asset       → Debit
Expense     → Debit

Liability   → Credit
Equity      → Credit
Revenue     → Credit
```

This must be implemented centrally.

Do not duplicate these rules throughout controllers.

Create a reusable accounting rule/service.

---

# 13. Contra Accounts

The architecture must allow accounts whose balance behaves opposite to their
parent account type.

Examples:

```text
Accumulated Depreciation
Sales Returns
Allowance for Doubtful Accounts
```

Do not implement complex contra-account behavior yet.

However, the account model must not prevent it.

If a normal-balance override is required, design it explicitly rather than
hard-coding account behavior everywhere.

---

# 14. System Accounts

Support accounts that are system-controlled.

For example, future modules may require accounts for:

```text
Accounts Receivable
Accounts Payable
Sales Revenue
Purchase Expense
Inventory
Tax Payable
Cash
Bank
Retained Earnings
```

However:

**Do not create these accounts automatically in Phase 4 unless the approved
architecture explicitly requires it.**

The phase should create the `is_system` capability so future modules can
identify protected system accounts.

---

# 15. Account Deactivation

Accounts should generally be deactivated rather than deleted once they have
accounting history.

Use:

```text
is_active
```

An inactive account:

- cannot be used for new posted journals
- may remain visible in historical reports
- must retain historical relationships

Do not hard-delete accounts referenced by posted journal lines.

---

# 16. Account Deletion

Do not allow destructive deletion of accounts with accounting history.

At minimum:

```text
If account has journal lines:
    deletion must be rejected.
```

Return a clear API error.

Prefer deactivation.

This is critical for accounting integrity.

---

# 17. Accounting Periods

Create the foundation for accounting periods.

A period should support:

```text
id
company_id
name
start_date
end_date
status
created_at
updated_at
```

Possible statuses:

```text
OPEN
CLOSED
```

Keep the model simple.

Do not implement advanced fiscal-period workflows yet.

---

# 18. Period Rules

A journal's accounting date must belong to an appropriate accounting period.

A journal cannot be posted into a closed period.

The backend must enforce this.

Do not rely on frontend date validation.

---

# 19. Period Overlap

Prevent invalid overlapping accounting periods within the same company.

For example:

```text
Company A

January:
2027-01-01 → 2027-01-31

February:
2027-02-01 → 2027-02-28
```

is valid.

But:

```text
Period A:
2027-01-01 → 2027-01-31

Period B:
2027-01-15 → 2027-02-15
```

must be rejected if the application's period model requires non-overlapping
periods.

Implement this validation server-side.

---

# 20. Journal Model

Create a `journals` table.

The journal should support at minimum:

```text
id
company_id
journal_number
journal_date
description nullable
reference nullable
status
source_type nullable
source_id nullable
created_by
posted_by nullable
posted_at nullable
created_at
updated_at
```

Do not add unnecessary fields.

---

# 21. Journal Number

Journal numbers must be unique within a company.

Example:

```text
JNL-000001
JNL-000002
JNL-000003
```

The exact numbering mechanism should be implemented centrally.

Do not use:

```text
random UUID as the visible journal number
```

unless the approved architecture explicitly requires it.

The database primary key may still be an integer/UUID independent of the
human-readable journal number.

---

# 22. Journal Lines

Create `journal_lines`.

Each line should support at minimum:

```text
id
journal_id
account_id
description nullable
debit
credit
line_number
created_at
updated_at
```

The exact schema may include additional justified fields.

Financial values must use an appropriate `DECIMAL` precision/scale.

Do not use FLOAT or DOUBLE.

---

# 23. Debit/Credit Representation

Each journal line must represent either:

```text
Debit
```

or:

```text
Credit
```

A line must not contain both a positive debit and a positive credit.

Valid:

```text
debit = 100
credit = 0
```

or:

```text
debit = 0
credit = 100
```

Invalid:

```text
debit = 100
credit = 100
```

Also invalid:

```text
debit = 0
credit = 0
```

The exact database/application representation may use:

- separate debit/credit decimal fields, or
- a signed amount model.

For this project, separate:

```text
debit
credit
```

fields are preferred because they make accounting reporting and validation
straightforward.

---

# 24. Journal Balance Rule

Every journal must satisfy:

```text
SUM(debit) = SUM(credit)
```

before posting.

Example:

```text
Debit:
Cash                 1,000

Credit:
Sales Revenue        1,000
```

Valid.

Example:

```text
Debit:
Cash                 1,000

Credit:
Sales Revenue          900
```

Invalid.

The API must reject the second journal.

---

# 25. Minimum Journal Lines

A journal must contain at least two valid lines.

This is the minimum double-entry requirement.

Do not allow:

```text
one-line journal
```

to be posted.

---

# 26. Zero-Value Lines

Do not allow meaningless journal lines.

Reject:

```text
debit = 0
credit = 0
```

unless there is an explicitly documented reason.

Normal accounting journal lines must contain a positive amount on exactly one
side.

---

# 27. Negative Amounts

Do not use negative debit or credit values to represent the opposite side.

Reject:

```text
debit = -100
credit = 0
```

Use:

```text
debit = 0
credit = 100
```

instead.

This keeps the accounting model unambiguous.

---

# 28. Journal Status

Use controlled journal statuses.

At minimum:

```text
DRAFT
POSTED
```

Do not allow arbitrary status strings.

---

# 29. Draft Journals

Draft journals may be:

- created
- edited
- deleted/cancelled if appropriate

A draft journal does not affect the official ledger.

Draft amounts must not appear in official financial reports unless a future
report explicitly supports draft data.

---

# 30. Posting

Posting is the critical accounting operation.

Conceptually:

```text
Draft Journal
      ↓
Validate
      ↓
Validate Accounts
      ↓
Validate Company
      ↓
Validate Period
      ↓
Validate Debit/Credit
      ↓
Validate Balance
      ↓
Database Transaction
      ↓
POST
```

Only successfully validated journals may become posted.

---

# 31. Posting Must Be Atomic

Posting must use a database transaction.

If any part fails:

```text
ROLLBACK
```

The system must never leave:

```text
Journal = POSTED
Journal Lines = incomplete
```

or any equivalent inconsistent state.

---

# 32. Posted Journal Immutability

Once a journal is posted:

Do not allow normal users to:

- change journal date
- change accounts
- change debit
- change credit
- change lines
- change company
- delete the journal

Do not implement direct editing of posted accounting records.

---

# 33. Corrections

Incorrect posted accounting records must eventually be corrected through proper
accounting mechanisms such as:

```text
Reversal Journal
Adjustment Journal
```

Do not allow direct mutation of historical posted data.

A complete reversal workflow may belong to a later phase, but the Phase 4
architecture must not prevent it.

---

# 34. Posted Timestamp

When posting:

```text
posted_at
posted_by
```

must be recorded.

`posted_by` must come from the authenticated backend user.

Never trust the frontend to supply the posting user.

---

# 35. Journal Source

The architecture should support future source tracking.

Potential future values:

```text
MANUAL
SALES
PURCHASE
PAYMENT
EXPENSE
BANK
TAX
ADJUSTMENT
```

Do not implement all future source types now.

The database should simply be capable of recording:

```text
source_type
source_id
```

if appropriate.

Do not create fake source records.

---

# 36. General Ledger Foundation

The General Ledger should be based on posted journal lines.

Conceptually:

```text
Posted Journals
      ↓
Journal Lines
      ↓
Account
      ↓
Ledger
```

Do not create a duplicated ledger table merely to store balances unless there is
a strong, documented reason.

The source of truth should remain:

```text
Posted Journal Lines
```

Account balances should be derived from posted journal lines or from a carefully
controlled ledger mechanism.

Avoid duplicate sources of truth.

---

# 37. Account Balance Calculation

Implement a reusable mechanism/service for calculating an account's balance from
posted journal lines.

Conceptually:

```text
Account Balance
=
Total Debits
-
Total Credits
```

for debit-normal accounts.

For reporting, the system must also understand credit-normal accounts.

Do not hard-code:

```text
balance = debit - credit
```

without considering account normal balance.

Centralize the normal-balance logic.

---

# 38. Trial Balance Foundation

The Phase 4 architecture should support a Trial Balance.

The Trial Balance will later show:

```text
Account
Debit
Credit
```

The fundamental check:

```text
TOTAL TRIAL BALANCE DEBITS
=
TOTAL TRIAL BALANCE CREDITS
```

A reporting endpoint may be implemented if the phase scope permits, but do not
build complete financial reporting yet.

At minimum, the data model must support reliable Trial Balance calculation.

---

# 39. Accounting Transaction Service

Create a focused service layer.

A suitable structure may be:

```text
AccountingService
JournalService
JournalPostingService
AccountService
AccountingPeriodService
```

Do not create unnecessary service classes.

Responsibilities should remain clear.

### AccountService

- create account
- update account
- activate/deactivate
- validate account

### JournalService

- create draft journal
- update draft journal
- validate journal structure
- manage journal lines

### JournalPostingService

- validate journal
- validate period
- validate account status
- validate balance
- post atomically

### AccountingPeriodService

- create period
- validate date
- open/close period
- determine applicable period

---

# 40. Transaction Boundaries

Use database transactions for:

- journal creation where multiple records are inserted
- journal updates involving multiple lines
- posting
- period state changes where necessary
- account operations involving related records

Do not wrap every read operation in a database transaction.

---

# 41. Concurrency

Consider concurrent posting.

The posting operation must prevent inconsistent states caused by two requests
attempting to post the same draft simultaneously.

Use appropriate:

- database transaction
- row locking
- status validation

The same journal must not be posted twice.

---

# 42. Idempotency

Posting should be effectively idempotent.

If:

```text
Journal already POSTED
```

a second posting request must not create another accounting effect.

Return an appropriate response.

Do not duplicate journal entries.

---

# 43. Authorization

Integrate with Phase 2 permissions.

Introduce only the permissions required for this phase.

Potential permissions:

```text
accounts.view
accounts.create
accounts.update
accounts.activate
accounts.deactivate

journals.view
journals.create
journals.update
journals.delete
journals.post

accounting.periods.view
accounting.periods.create
accounting.periods.update
accounting.periods.close
```

Use the existing permission architecture.

Do not create a new authorization system.

---

# 44. Company Isolation

Test all accounting endpoints for company isolation.

Example:

```text
User
  ↓
Company A

Request:
Account belonging to Company B
```

must be rejected.

The same applies to:

- accounts
- journals
- journal lines
- accounting periods

Never trust a client-provided company ID.

---

# 45. API Endpoints

Implement a clean API.

Possible structure:

```text
GET    /api/accounts
POST   /api/accounts
GET    /api/accounts/{account}
PUT    /api/accounts/{account}
POST   /api/accounts/{account}/activate
POST   /api/accounts/{account}/deactivate

GET    /api/accounting/periods
POST   /api/accounting/periods
GET    /api/accounting/periods/{period}
PUT    /api/accounting/periods/{period}
POST   /api/accounting/periods/{period}/close

GET    /api/journals
POST   /api/journals
GET    /api/journals/{journal}
PUT    /api/journals/{journal}
DELETE /api/journals/{journal}
POST   /api/journals/{journal}/post
```

Adjust route names to match the existing API conventions.

Do not create unnecessary duplicate routes.

---

# 46. Journal Creation API

A journal creation request should conceptually look like:

```json
{
  "journal_date": "2027-01-15",
  "description": "Initial cash transaction",
  "reference": "REF-001",
  "lines": [
    {
      "account_id": 1,
      "description": "Cash received",
      "debit": "1000.00",
      "credit": "0.00"
    },
    {
      "account_id": 2,
      "description": "Revenue",
      "debit": "0.00",
      "credit": "1000.00"
    }
  ]
}
```

The backend must calculate/validate the accounting integrity.

Do not trust a frontend-calculated total.

---

# 47. Server-Side Accounting Validation

The backend must independently calculate:

```text
total_debit
total_credit
```

Then verify:

```text
total_debit == total_credit
```

Do not accept a client-provided:

```text
total
balance
is_balanced
```

as authoritative.

The server is the source of truth.

---

# 48. Decimal Precision

Define a consistent financial precision.

A practical starting point is:

```text
DECIMAL(20, 4)
```

or another explicitly justified precision based on the approved accounting
requirements.

Do not use arbitrary precision in different tables.

Document the chosen precision.

If currencies with more decimal places are expected later, ensure the design can
accommodate them.

Do not silently change precision in later phases.

---

# 49. Currency Foundation

Do not implement the full currency engine in Phase 4.

However, accounting architecture must not prevent future multi-currency support.

Journal and account design should not make it impossible to later record:

```text
transaction currency
base/company currency
exchange rate
foreign amount
base amount
```

Do not add speculative fields unless justified by the approved architecture.

The dedicated currency phase will define the final model.

---

# 50. Audit Foundation

Do not implement a complete audit log system yet.

However, ensure important accounting records capture:

```text
created_by
posted_by
created_at
posted_at
```

Future audit infrastructure will expand this.

---

# 51. Account Deletion Safety

Explicitly test:

```text
Account with no journal history
```

versus:

```text
Account with posted journal history
```

An account with posted history must not be physically deleted.

Return a clear explanation such as:

```text
"This account cannot be deleted because it has accounting history. Deactivate it instead."
```

The final API message should be user-friendly.

---

# 52. Period Closing Safety

Before closing a period, validate that:

- the period exists
- it belongs to the active company
- it is currently open
- the user has permission
- the operation is authorized

Once closed:

```text
New journals cannot be posted into that period.
```

Do not allow ordinary users to reopen a closed period unless explicitly
authorized.

If reopening is not implemented in Phase 4, document it.

---

# 53. Financial Integrity Tests

This phase requires stronger testing than normal CRUD modules.

At minimum test:

### Balanced journal

```text
Debit 1000
Credit 1000

→ POST succeeds
```

### Unbalanced journal

```text
Debit 1000
Credit 900

→ POST rejected
```

### One-line journal

```text
Debit 1000

→ POST rejected
```

### Zero lines

```text
→ POST rejected
```

### Negative debit

```text
Debit -100

→ rejected
```

### Both debit and credit

```text
Debit 100
Credit 100

→ rejected
```

### Closed period

```text
→ POST rejected
```

### Inactive account

```text
→ POST rejected
```

### Wrong company account

```text
→ rejected
```

### Duplicate posting

```text
POST
POST again

→ no duplicate accounting effect
```

---

# 54. Account Balance Tests

Test:

```text
Opening debit
+
Credit transaction
+
Debit transaction
```

and verify the calculated balance.

Test both:

- debit-normal accounts
- credit-normal accounts

Verify the normal-balance calculation.

---

# 55. Trial Balance Tests

Create test scenarios where multiple journals are posted.

Verify:

```text
Total Debits = Total Credits
```

at the Trial Balance level.

Verify that:

- draft journals are excluded
- posted journals are included
- another company's journals are excluded
- closed/open period filters behave correctly

---

# 56. Company Isolation Tests

Create at least:

```text
Company A
Company B
```

with separate:

```text
Accounts
Journals
Periods
```

Verify that Company A cannot read or modify Company B's accounting data.

This is mandatory.

---

# 57. Security Testing

Test for:

- IDOR
- mass assignment
- unauthorized posting
- unauthorized account modification
- unauthorized period closing
- cross-company access
- posting a journal twice
- posting to inactive accounts
- posting to closed periods

Do not rely only on controller-level tests.

---

# 58. Database Integrity

Use:

- foreign keys
- unique constraints
- indexes
- appropriate decimal precision
- appropriate delete behavior

Important constraints include:

```text
company_id + account_code
company_id + journal_number
```

and appropriate relationships for:

```text
journal_lines
accounts
journals
periods
```

---

# 59. Performance Considerations

Do not prematurely optimize.

However, create indexes for frequent accounting queries.

Likely indexed fields:

```text
company_id
account_id
journal_id
journal_date
status
posted_at
```

Composite indexes should be added where query patterns justify them.

Do not create dozens of unnecessary indexes.

---

# 60. API Resources

Return controlled representations.

Account response may contain:

```text
id
code
name
account_type
parent_id
is_active
```

Journal response may contain:

```text
id
journal_number
journal_date
description
reference
status
posted_at
created_by
posted_by
lines
```

Do not expose unnecessary internal fields.

---

# 61. No Frontend

Do not create the Next.js frontend in this phase.

Do not create:

- dashboards
- charts
- account pages
- journal forms
- financial report pages

The frontend will consume stable APIs later.

---

# 62. No Business Modules

Do not implement:

```text
Sales
Purchases
Invoices
Payments
Expenses
Customers
Suppliers
Inventory
Banking
Tax
```

These modules will eventually create accounting entries through this foundation.

They must not bypass the accounting engine.

---

# 63. Critical Architectural Rule

Future business modules must NOT directly manipulate:

```text
journal_lines
```

or manually calculate accounting balances.

Future architecture:

```text
Business Module
      ↓
Accounting Mapping
      ↓
Journal Service
      ↓
Posting Engine
      ↓
Journal Lines
      ↓
General Ledger
```

The accounting engine must remain the central source of accounting truth.

---

# 64. Required Documentation

Create:

```text
docs/reports/PHASE_4_REPORT.md
```

Document:

## Accounting Model

- account types
- normal balances
- journal model
- debit/credit representation

## Database

- tables
- relationships
- constraints
- indexes
- decimal precision

## Posting

- validation sequence
- transaction behavior
- immutability
- duplicate-post prevention

## Company Isolation

Explain how company scope is enforced.

## Accounting Periods

Explain:

- open
- closed
- posting restrictions

## API

List implemented endpoints.

## Permissions

List new permissions.

## Tests

Report exact:

```text
Tests:
Assertions:
Passed:
Failed:
Skipped:
```

## Security Review

Document all security tests.

## Known Limitations

Document what is intentionally deferred.

## Architectural Decisions

Record important accounting decisions made during implementation.

## Status

Use exactly:

```text
PASS
PASS WITH NOTES
BLOCKED
```

---

# 65. Final Acceptance Criteria

Phase 4 is complete only when:

- [ ] Chart of Accounts foundation exists
- [ ] Account hierarchy works
- [ ] Account types are controlled
- [ ] Normal balance rules are centralized
- [ ] Account codes are company-scoped and unique
- [ ] Accounts can be activated/deactivated
- [ ] Accounts with history cannot be deleted
- [ ] Accounting periods exist
- [ ] Period dates are validated
- [ ] Period overlap is prevented
- [ ] Closed periods reject posting
- [ ] Journal model exists
- [ ] Journal lines exist
- [ ] Debit/credit validation works
- [ ] Balanced journal validation works
- [ ] Unbalanced journals cannot be posted
- [ ] Zero/negative/dual-sided lines are rejected
- [ ] Posted journals are immutable
- [ ] Posting is transactional
- [ ] Duplicate posting is prevented
- [ ] `posted_by` and `posted_at` are recorded
- [ ] Account balances can be calculated correctly
- [ ] Trial Balance foundation is correct
- [ ] Company isolation is enforced
- [ ] Authorization is enforced
- [ ] Security tests pass
- [ ] Accounting integrity tests pass
- [ ] No seeders were executed without permission
- [ ] No fake accounting data was created
- [ ] No future business modules were implemented
- [ ] No frontend was implemented
- [ ] `PHASE_4_REPORT.md` exists
- [ ] Phase status is documented

---

# 66. STOP CONDITION

After Phase 4 is completed:

**STOP.**

Do not automatically proceed to business transactions.

The next phase should be reviewed separately because the next decision concerns
how the Chart of Accounts and accounting engine will be exposed to actual
business modules.

Wait for explicit approval before starting:

**PHASE 5 — Chart of Accounts & Accounting Operations UI/API**
