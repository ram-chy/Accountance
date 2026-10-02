# Phase 9 — Bank Reconciliation & Reconciliation Management

## 1. Phase Objective

Phase 9 introduces **Bank Reconciliation** on top of the existing Phase 7 Cash &
Banking implementation and Phase 6 accounting reports.

The objective is to allow authorized users to:

- select a classified BANK account;
- create a reconciliation for a statement period;
- enter the bank statement closing balance;
- view the ledger-side opening and closing balances;
- review cash/bank transactions and ledger movements for the reconciliation
  period;
- mark individual bank-side items as cleared/reconciled;
- identify uncleared differences;
- complete a reconciliation only when the reconciliation equation is satisfied;
- reopen a completed reconciliation only through an explicit authorized action;
- preserve reconciliation history;
- provide reconciliation summaries without creating a second accounting ledger.

Phase 9 is a **reconciliation layer**, not another accounting engine.

It must reuse:

- `Account`
- `BankAccount`
- `CashBankTransaction`
- `Journal`
- `JournalLine`
- `LedgerService`
- `JournalService`
- `JournalPostingService`
- `CashBankTransactionService`
- `CashBankPostingService`
- `Money`
- `CompanyContext`
- existing authorization infrastructure
- existing accounting date conventions
- existing company-scoped route bindings

Do not introduce a second balance calculation engine.

---

# 2. Scope

## In Scope

### Bank reconciliation

- reconciliation creation;
- reconciliation listing;
- reconciliation detail;
- statement opening balance;
- statement closing balance;
- reconciliation date range;
- ledger opening balance;
- ledger closing balance;
- cleared transaction tracking;
- uncleared transaction tracking;
- reconciliation difference;
- completion;
- reopening;
- reconciliation history;
- reconciliation summary;
- company isolation;
- authorization;
- validation;
- audit fields;
- exact decimal money handling.

### Reconciliation items

Phase 9 should support reconciliation against existing posted cash/bank
movements.

The implementation must be able to identify:

- deposits;
- withdrawals;
- internal transfers;
- other posted journal movements affecting the selected bank account.

The system must distinguish between:

1. **ledger movement**
2. **bank-cleared status**

A transaction being posted to the ledger does not automatically mean it has
cleared the bank.

---

# 3. Explicit Non-Goals

Do NOT implement:

- bank API integrations;
- automatic bank-feed imports;
- CSV/OFX/QIF import;
- OCR;
- transaction matching using AI;
- automatic reconciliation algorithms;
- fuzzy matching;
- automatic journal creation for reconciliation differences;
- reconciliation adjustment journals;
- bank fees automation;
- interest automation;
- currency conversion;
- multi-currency reconciliation;
- consolidated bank reconciliation;
- a second ledger;
- stored running balances on cash-bank transactions;
- changes to Phase 7 posting rules;
- changes to Phase 6 financial report calculations.

If a bank statement contains an item that does not exist in the ledger, Phase 9
should **identify the difference**, not silently create an accounting
transaction.

A future phase may introduce adjustment workflows.

---

# 4. Architectural Principle

The central rule of Phase 9:

> Reconciliation records what the bank says has cleared. Accounting records what
> the company has posted.

Do not merge these concepts.

The ledger remains the source of accounting truth.

The bank reconciliation layer records the external-clearing state.

No balance should be copied into `cash_bank_transactions`.

No balance should be copied into `accounts`.

No balance should be copied into `bank_accounts`.

---

# 5. Reconciliation Lifecycle

Use a simple lifecycle.

```text
DRAFT
  ↓
IN_PROGRESS
  ↓
RECONCILED
```

A completed reconciliation may be reopened:

```text
RECONCILED
  ↓
IN_PROGRESS
```

Do not allow:

```text
DRAFT → RECONCILED
```

unless the system's completion validation has been satisfied.

Do not allow:

```text
RECONCILED → DRAFT
```

Reopening must preserve historical information.

---

# 6. Reconciliation Model

Create:

```text
BankReconciliation
```

Suggested fields:

```text
id
company_id
bank_account_id
account_id
from_date
to_date
statement_opening_balance
statement_closing_balance
ledger_opening_balance
ledger_closing_balance
status
difference
created_by
completed_by
completed_at
reopened_by
reopened_at
notes
timestamps
```

Important:

`difference` should NOT become a permanent accounting balance.

It may be stored as a reconciliation snapshot for audit purposes, but it must
always be derived from the reconciliation calculation when the reconciliation is
displayed.

Prefer calculating it from current reconciliation facts rather than treating the
stored value as authoritative.

If practical, do not store `difference` at all.

Use:

```text
statement_closing_balance
ledger_closing_balance
cleared_adjustment
difference
```

as calculated values.

---

# 7. Reconciliation Items

Create a separate model:

```text
BankReconciliationItem
```

Suggested fields:

```text
id
company_id
bank_reconciliation_id
journal_id
journal_line_id
cleared_amount
cleared_at
cleared_by
notes
timestamps
```

Unique constraint:

```text
bank_reconciliation_id + journal_line_id
```

The item represents the relationship:

```text
reconciliation
        ↓
posted ledger line
        ↓
cleared by bank
```

Do not duplicate:

- transaction amount;
- debit;
- credit;
- journal date;
- reference;
- source type.

Read those from the existing journal line.

---

# 8. Why Journal Line Is the Reconciliation Reference

Phase 7 cash-bank transactions always create:

```text
Dr destination
Cr source
```

Therefore a bank account can be affected by:

- deposit;
- withdrawal;
- transfer;
- other accounting journals.

The reconciliation layer must therefore work from **posted journal lines for the
bank account**, not only from `cash_bank_transactions`.

This ensures:

- manual accounting entries remain visible;
- cash-bank transactions remain visible;
- one ledger remains authoritative;
- reconciliation does not accidentally ignore legitimate bank-account movements.

---

# 9. Bank Account Eligibility

Only accounts classified as:

```text
cash_bank_kind = BANK
```

may be selected for bank reconciliation.

Do not allow:

```text
CASH
NULL
```

accounts.

The account must:

- belong to the active company;
- be active;
- have an active `BankAccount` metadata record.

Use existing company-scoped account resolution.

Never trust:

```text
company_id
```

from the request.

---

# 10. Reconciliation Period Rules

Required:

```text
from_date
to_date
```

Rules:

```text
from_date <= to_date
```

The accounting date semantics must remain inclusive:

```text
journal_date >= from_date
journal_date <= to_date
```

Do not use:

```text
created_at
posted_at
```

for financial-period filtering.

---

# 11. Reconciliation Opening Balance

The ledger opening balance must be calculated from all posted journal lines
before:

```text
from_date
```

Formula:

```text
opening =
    cumulative debit
    -
    cumulative credit
```

Use the existing ledger logic.

Do not implement another SQL definition of the ledger balance.

Prefer extending/reusing:

```text
LedgerService
```

if the existing public API does not expose the required calculation.

---

# 12. Ledger Closing Balance

The ledger closing balance is:

```text
opening_balance
+
period_debits
-
period_credits
```

This is the actual ledger position through:

```text
to_date
```

Use the existing account balance semantics.

Do not add a stored balance column.

---

# 13. Statement Balance

The user enters:

```text
statement_opening_balance
statement_closing_balance
```

These represent the external bank statement.

They are not accounting entries.

They must never create journals.

---

# 14. Reconciliation Equation

The reconciliation must expose:

```text
ledger_closing_balance
statement_closing_balance
uncleared_debits
uncleared_credits
difference
```

The core equation is:

```text
statement_closing_balance
=
ledger_closing_balance
+ uncleared_items_adjustment
```

For a debit-positive ledger:

```text
uncleared_debits
```

increase the bank statement adjustment.

```text
uncleared_credits
```

decrease it.

Therefore:

```text
reconciled_balance =
    ledger_closing_balance
    - uncleared_debits
    + uncleared_credits
```

The reconciliation difference is:

```text
difference =
    statement_closing_balance
    - reconciled_balance
```

A reconciliation is complete only when:

```text
difference == 0
```

Use `Money` comparison.

Never use floating-point comparison.

---

# 15. Reconciliation Item Meaning

A posted ledger line may be:

```text
CLEARED
```

or:

```text
UNCLEARED
```

for the selected reconciliation.

Do not add a global `cleared` column to `journal_lines`.

The same journal line may participate in different reconciliation
periods/history.

Reconciliation status belongs to:

```text
BankReconciliationItem
```

not to the accounting ledger.

---

# 16. Existing Reconciliation Protection

A journal line should not be accidentally cleared twice in the same
reconciliation.

Enforce:

```text
unique(bank_reconciliation_id, journal_line_id)
```

A line already included in an older completed reconciliation should normally not
be available for a later reconciliation.

However, this rule must be carefully based on accounting dates and
reconciliation history.

Do not simply use:

```text
journal_line_id exists anywhere
```

because historical reconciliation records must remain valid.

A later reconciliation should only expose movements that have not already been
reconciled through an earlier completed reconciliation.

---

# 17. Transaction Visibility

The reconciliation transaction list should include posted journal lines
affecting the selected bank account.

Suggested response fields:

```json
{
  "journal_id": 123,
  "journal_line_id": 456,
  "journal_number": "JV-001",
  "journal_date": "2026-10-10",
  "reference": "CBN-00001",
  "description": "Deposit",
  "source_type": "CASH_BANK_TRANSACTION",
  "source_id": 88,
  "debit": "1000.0000",
  "credit": "0.0000",
  "amount": "1000.0000",
  "direction": "DEBIT",
  "cleared": false
}
```

Do not expose internal implementation details unnecessarily.

---

# 18. Reconciliation Summary

The detail endpoint should return:

```json
{
  "bank_account": {},
  "period": {
    "from": "2026-10-01",
    "to": "2026-10-31"
  },
  "balances": {
    "ledger_opening": "10000.0000",
    "ledger_closing": "15000.0000",
    "statement_opening": "10000.0000",
    "statement_closing": "14500.0000",
    "uncleared_debits": "700.0000",
    "uncleared_credits": "200.0000",
    "reconciled_balance": "14500.0000",
    "difference": "0.0000"
  },
  "summary": {
    "total_movements": 25,
    "cleared_movements": 21,
    "uncleared_movements": 4
  }
}
```

Exact naming may follow existing API conventions.

---

# 19. API Endpoints

Use a dedicated namespace:

```text
/api/accounting/bank-reconciliations
```

Recommended endpoints:

### List

```http
GET /api/accounting/bank-reconciliations
```

Filters:

```text
bank_account_id
status
from_date
to_date
```

### Create

```http
POST /api/accounting/bank-reconciliations
```

Creates a DRAFT reconciliation.

### Show

```http
GET /api/accounting/bank-reconciliations/{reconciliation}
```

Returns reconciliation summary and metadata.

### Update

```http
PUT /api/accounting/bank-reconciliations/{reconciliation}
```

Draft/in-progress metadata only.

Do not permit changing:

```text
bank_account_id
```

after reconciliation items have been attached.

Prefer preventing period changes after reconciliation items exist as well.

### Available movements

```http
GET /api/accounting/bank-reconciliations/{reconciliation}/movements
```

Returns eligible posted journal lines.

### Clear movement

```http
POST /api/accounting/bank-reconciliations/{reconciliation}/items
```

Body:

```json
{
  "journal_line_id": 456,
  "notes": "Cleared on October statement"
}
```

### Remove cleared movement

```http
DELETE /api/accounting/bank-reconciliations/{reconciliation}/items/{item}
```

Only allowed before completion.

### Complete

```http
POST /api/accounting/bank-reconciliations/{reconciliation}/complete
```

Only succeeds when:

```text
difference == 0
```

### Reopen

```http
POST /api/accounting/bank-reconciliations/{reconciliation}/reopen
```

Requires explicit permission.

---

# 20. Authorization

Add only the permissions actually required.

Recommended:

```text
accounting.bank_reconciliation.view
accounting.bank_reconciliation.create
accounting.bank_reconciliation.update
accounting.bank_reconciliation.complete
accounting.bank_reconciliation.reopen
```

Role matrix:

| Role       | View | Create | Update | Complete | Reopen |
| ---------- | ---: | -----: | -----: | -------: | -----: |
| Admin      |  yes |    yes |    yes |      yes |    yes |
| Accountant |  yes |    yes |    yes |      yes |    yes |
| Manager    |  yes |    yes |    yes |      yes |     no |
| Staff      |   no |     no |     no |       no |     no |

If the existing authorization architecture supports a simpler permission model
without reducing control, use the simpler architecture.

Do not create unnecessary permission granularity.

---

# 21. Authorization Enforcement

Follow the existing Phase 6/7 double-protection pattern:

### Request

`authorize()` checks permission.

### Controller

Controller performs the same explicit permission check before invoking the
service.

### Policy

Create:

```text
BankReconciliationPolicy
```

for resource-level lifecycle authorization where appropriate.

Do not depend on only frontend hiding.

---

# 22. Company Isolation

Every query must use:

```text
CompanyContext
```

No request may accept:

```text
company_id
```

as an authority.

Company isolation must apply to:

- reconciliation;
- bank account;
- journal;
- journal line;
- reconciliation item.

Cross-company resources should never be returned.

Prefer:

```text
404
```

through scoped route binding where that is the existing project convention.

Validation IDs should use company-scoped `exists` rules.

---

# 23. Bank Reconciliation Service Structure

Use focused services.

Recommended:

```text
app/Services/Accounting/Reconciliation/
```

### `BankReconciliationService`

Responsibilities:

- create;
- update;
- list;
- show;
- delete draft;
- calculate summary;
- validate lifecycle.

### `BankReconciliationMovementService`

Responsibilities:

- retrieve eligible ledger movements;
- determine cleared/uncleared state;
- add reconciliation items;
- remove reconciliation items.

### `BankReconciliationCompletionService`

Responsibilities:

- lock reconciliation;
- calculate final difference;
- verify zero difference;
- mark reconciled;
- stamp completion audit fields.

### `BankReconciliationReopenService`

Responsibilities:

- authorize reopening;
- lock record;
- change status to `IN_PROGRESS`;
- stamp reopen audit fields.

Do not build:

- generic reconciliation engine;
- metadata-driven rule system;
- DSL;
- accounting report engine;
- second ledger.

---

# 24. Money Handling

All monetary calculations must use:

```text
Money
```

Existing project scale:

```text
DECIMAL(20,4)
```

No:

```php
(float)
```

No:

```php
double
```

No JavaScript-style floating-point calculations in backend services.

Output amounts using:

```text
Money::toDatabase()
```

or the existing project formatter.

Tests must include non-round values such as:

```text
1175.2500
```

to prove exactness.

---

# 25. Concurrency

Completion and reopening must use row locking.

At minimum:

```text
lockForUpdate()
```

inside a database transaction.

Completion sequence:

1. lock reconciliation;
2. verify status;
3. load/validate bank account;
4. calculate current eligible movements;
5. calculate cleared state;
6. calculate difference;
7. require zero difference;
8. set status;
9. stamp completed_by;
10. stamp completed_at;
11. commit.

Do not calculate the final difference before acquiring the reconciliation lock.

---

# 26. Immutability

### DRAFT

Allowed:

- edit metadata;
- edit dates;
- edit statement balances;
- delete.

### IN_PROGRESS

Allowed:

- edit statement balances;
- clear movement;
- remove movement;
- add movement;
- update notes.

Do not allow changing:

```text
bank_account_id
```

once reconciliation items exist.

### RECONCILED

Immutable.

Allowed action:

```text
reopen
```

Only authorized users may reopen.

Do not directly update a reconciled record into arbitrary values.

---

# 27. Reopening

Reopening must not:

- delete reconciliation items;
- delete history;
- alter journals;
- alter journal lines;
- alter cash-bank transactions;
- create accounting entries.

It only changes reconciliation workflow state.

Keep:

```text
completed_by
completed_at
reopened_by
reopened_at
```

for auditability.

If the existing project prefers an event/history table for audit, reuse that
infrastructure instead of creating duplicate audit structures.

---

# 28. Statement Opening Balance

The user may enter the statement opening balance.

The system should also calculate:

```text
previous_reconciled_statement_closing
```

for the same bank account.

If a previous completed reconciliation exists immediately before this period,
expose:

```text
previous_reconciled_closing_balance
```

and validate/report whether:

```text
statement_opening_balance
==
previous_reconciled_closing_balance
```

Do not silently overwrite the user's supplied value.

If there is a mismatch, expose it clearly.

Suggested field:

```text
opening_balance_difference
```

This is a reconciliation warning, not an accounting journal.

---

# 29. First Reconciliation

For the first reconciliation of a bank account:

```text
previous_reconciled_closing_balance = null
```

The user supplies the statement opening balance.

The system calculates the ledger opening balance from posted journals.

Do not invent a prior reconciliation.

---

# 30. Movement Selection

Eligible movement:

```text
journal.status = POSTED
journal_line.account_id = selected bank account
journal_date <= reconciliation.to_date
```

The movement must not be already cleared in an earlier completed reconciliation.

For the current reconciliation:

```text
cleared = exists BankReconciliationItem
```

Only the current reconciliation may be modified.

---

# 31. Period Boundary

A reconciliation period normally covers:

```text
from_date → to_date
```

Movements before `from_date` are part of the opening ledger balance.

Movements through `to_date` are part of the period.

Movements after `to_date` are excluded.

Do not use transaction creation time.

---

# 32. Difference Calculation Example

Suppose:

```text
Ledger closing = 15,000
```

There is an uncleared withdrawal:

```text
700
```

There is an uncleared deposit:

```text
200
```

Then:

```text
reconciled balance
= 15,000
- 700
+ 200
= 14,500
```

If bank statement closing is:

```text
14,500
```

then:

```text
difference = 0
```

and reconciliation may be completed.

---

# 33. No Adjustment Journal

If:

```text
difference = 125.0000
```

the system must NOT:

- create a bank fee journal;
- create an adjustment journal;
- modify the bank account balance;
- modify the cash-bank transaction;
- mark the reconciliation complete.

Instead return a validation/business error such as:

```text
The reconciliation cannot be completed because the difference is 125.0000.
```

The exact message should follow the project's existing API error conventions.

---

# 34. Resources

Add:

```text
BankReconciliationResource
BankReconciliationMovementResource
```

The resource must expose calculated reconciliation figures but must not expose
implementation-only fields unnecessarily.

Do not add a resource that duplicates an existing report response without
purpose.

---

# 35. Requests

Add:

```text
CreateBankReconciliationRequest
UpdateBankReconciliationRequest
BankReconciliationFilterRequest
AddBankReconciliationItemRequest
CompleteBankReconciliationRequest
ReopenBankReconciliationRequest
```

Completion/reopen requests may contain no body if the project convention
supports action endpoints.

All IDs must be company scoped.

---

# 36. Database Indexes

Add appropriate indexes for:

### bank_reconciliations

```text
company_id
bank_account_id
status
from_date
to_date
```

Composite indexes should be considered where they match actual query patterns.

### bank_reconciliation_items

```text
company_id
bank_reconciliation_id
journal_line_id
```

Unique:

```text
bank_reconciliation_id + journal_line_id
```

Avoid unnecessarily duplicating indexes already supplied by foreign keys.

---

# 37. Foreign Keys

Use existing project conventions.

Expected:

```text
bank_reconciliations.company_id
bank_reconciliations.bank_account_id
bank_reconciliations.account_id
bank_reconciliations.created_by
bank_reconciliations.completed_by
bank_reconciliations.reopened_by

bank_reconciliation_items.company_id
bank_reconciliation_items.bank_reconciliation_id
bank_reconciliation_items.journal_id
bank_reconciliation_items.journal_line_id
bank_reconciliation_items.cleared_by
```

Historical reconciliation records should not be accidentally destroyed because
an operational user is deleted.

Follow existing user foreign-key deletion conventions in the project.

---

# 38. API Response Convention

Use the existing:

```php
ApiResponse::success()
```

wrapper.

Example:

```json
{
  "success": true,
  "message": "Bank reconciliation generated successfully.",
  "data": {}
}
```

Errors must use the project's existing validation/business exception format.

Do not introduce a new API response envelope.

---

# 39. Reporting

Phase 9 does not create another accounting report engine.

The reconciliation detail itself is the report.

It should expose:

### Ledger

```text
ledger_opening
period_debits
period_credits
ledger_closing
```

### Statement

```text
statement_opening
statement_closing
```

### Clearing

```text
cleared_count
uncleared_count
cleared_debits
cleared_credits
uncleared_debits
uncleared_credits
```

### Result

```text
reconciled_balance
difference
is_reconciled
```

---

# 40. Performance

Do not load the entire journal table into PHP.

Use SQL for:

- date filtering;
- account filtering;
- posted filtering;
- aggregate calculations.

Avoid:

```text
N+1 queries
```

for movement listing.

Use eager loading where appropriate:

```text
journal
source
```

if the project has suitable polymorphic relationships.

Do not query each movement individually.

---

# 41. Tests

Create a dedicated test directory:

```text
tests/Feature/Accounting/Reconciliation/
```

Recommended test files:

```text
BankReconciliationTest
BankReconciliationMovementTest
BankReconciliationCompletionTest
BankReconciliationAuthorizationTest
BankReconciliationCompanyIsolationTest
BankReconciliationIntegrityTest
```

Target approximately **50–70 tests**, depending on the existing test
architecture.

---

# 42. Required Test Coverage

## Creation

Test:

- bank account required;
- bank account must belong to company;
- CASH account rejected;
- inactive bank account rejected;
- date required;
- backwards date rejected;
- exact decimal balances;
- default status DRAFT.

## Company isolation

Test:

- foreign bank account rejected;
- foreign reconciliation inaccessible;
- foreign journal line cannot be added;
- unrelated company data never appears.

## Movement eligibility

Test:

- posted journal movement appears;
- draft journal movement does not appear;
- movement before period appears only as opening;
- movement after period excluded;
- already reconciled movement excluded;
- current reconciliation item appears as cleared.

## Clearing

Test:

- add movement;
- remove movement;
- duplicate movement rejected;
- foreign journal line rejected;
- wrong bank account rejected;
- wrong company rejected;
- reconciled reconciliation cannot be modified.

## Calculation

Test:

```text
ledger opening
ledger closing
statement closing
uncleared debits
uncleared credits
reconciled balance
difference
```

using exact four-decimal amounts.

## Completion

Test:

- zero difference completes;
- non-zero difference rejected;
- completion stamps user;
- completion stamps timestamp;
- completion changes status;
- completion is atomic;
- double completion rejected.

## Reopening

Test:

- reconciled reconciliation can be reopened by authorized role;
- Manager cannot reopen;
- Staff cannot access;
- reopening preserves items;
- reopening preserves completion history;
- reopening does not modify journals.

## Authorization

Verify:

| Role       | View | Create | Update | Complete | Reopen |
| ---------- | ---: | -----: | -----: | -------: | -----: |
| Admin      |    ✓ |      ✓ |      ✓ |        ✓ |      ✓ |
| Accountant |    ✓ |      ✓ |      ✓ |        ✓ |      ✓ |
| Manager    |    ✓ |      ✓ |      ✓ |        ✓ |      ✗ |
| Staff      |    ✗ |      ✗ |      ✗ |        ✗ |      ✗ |

## Ledger integrity

Prove that:

- reconciliation does not modify journal lines;
- reconciliation does not modify cash-bank transactions;
- reconciliation does not change account balances;
- existing `/accounting/accounts/{id}/balance` remains unchanged;
- Phase 6 cash/bank reporting remains unchanged.

---

# 43. Critical Integrity Test

Add one high-value integration test:

1. Create BANK account.
2. Create/post deposit.
3. Create/post withdrawal.
4. Create another journal movement.
5. Create reconciliation.
6. Verify ledger closing.
7. Clear selected movements.
8. Verify uncleared adjustment.
9. Enter matching statement closing balance.
10. Complete reconciliation.
11. Read existing account balance.
12. Read reconciliation.
13. Verify the account balance and journal totals are unchanged by
    reconciliation.
14. Reopen.
15. Verify cleared items remain.
16. Verify journals remain unchanged.

This proves that reconciliation is genuinely a read/reconciliation layer rather
than a second accounting mechanism.

---

# 44. Migration Verification

Because Phase 9 introduces new persistence, run:

```bash
php artisan migrate
php artisan migrate:rollback --step=<phase-9-migration-count>
php artisan migrate
```

If the project's migration workflow uses `migrate:fresh` in tests, verify that
as well.

Do not run seeders unless explicitly authorized.

---

# 45. Required Verification Commands

Run:

```bash
php artisan test tests/Feature/Accounting/Reconciliation
```

Then:

```bash
php artisan test
```

Then:

```bash
./vendor/bin/pint
```

Then:

```bash
./vendor/bin/pint --test
```

Then:

```bash
php artisan route:list --path=reconciliation
```

Then:

```bash
php artisan route:list --path=accounting
```

Then syntax checks on all new/changed PHP files.

---

# 46. Regression Requirement

Phase 9 must not regress:

- Phase 1–5 transactional accounting;
- Phase 6 reports;
- Phase 7 cash/bank transactions;
- existing account balance endpoint;
- authorization;
- company isolation;
- Money precision.

The full suite must remain green.

Report:

```text
Phase 9 tests:
X tests
Y assertions

Full suite:
X tests
Y assertions
0 failures
0 errors
0 skipped
```

Use the actual numbers. Never invent them.

---

# 47. Files Expected

Expected additions will be approximately:

```text
database/migrations/
    *_create_bank_reconciliations_table.php
    *_create_bank_reconciliation_items_table.php

app/Enums/
    BankReconciliationStatus.php

app/Models/
    BankReconciliation.php
    BankReconciliationItem.php

app/Services/Accounting/Reconciliation/
    BankReconciliationService.php
    BankReconciliationMovementService.php
    BankReconciliationCompletionService.php
    BankReconciliationReopenService.php

app/Http/Requests/Accounting/Reconciliation/
    CreateBankReconciliationRequest.php
    UpdateBankReconciliationRequest.php
    BankReconciliationFilterRequest.php
    AddBankReconciliationItemRequest.php
    CompleteBankReconciliationRequest.php
    ReopenBankReconciliationRequest.php

app/Http/Resources/
    BankReconciliationResource.php
    BankReconciliationMovementResource.php

app/Http/Controllers/Api/Accounting/
    BankReconciliationController.php

app/Policies/
    BankReconciliationPolicy.php

tests/Feature/Accounting/Reconciliation/
    BankReconciliationTest.php
    BankReconciliationMovementTest.php
    BankReconciliationCompletionTest.php
    BankReconciliationAuthorizationTest.php
    BankReconciliationCompanyIsolationTest.php
    BankReconciliationIntegrityTest.php
```

Adjust paths to match the project's existing namespace conventions.

---

# 48. Implementation Rules

Before changing code:

1. Inspect the existing Phase 7 implementation.
2. Inspect `LedgerService`.
3. Inspect `JournalService`.
4. Inspect `JournalPostingService`.
5. Inspect `CashBankTransaction`.
6. Inspect `BankAccount`.
7. Inspect account balance implementation.
8. Inspect existing authorization conventions.
9. Inspect existing company-scoped bindings.
10. Inspect existing Money implementation.
11. Inspect existing migration conventions.
12. Inspect existing audit/user foreign-key conventions.

Do not assume names or method signatures.

Reuse existing methods where possible.

If a required capability does not exist, extend the existing service rather than
creating a duplicate implementation.

---

# 49. Important Safety Rule

Do not modify Phase 7 posting behavior merely to make reconciliation easier.

Do not add:

```text
reconciled
```

to:

```text
cash_bank_transactions
journal_lines
accounts
```

Do not modify journal amounts.

Do not modify posted journals.

Do not create adjustment journals.

Do not create bank statement transactions.

The reconciliation layer must remain independent from accounting mutation.

---

# 50. No Premature Abstraction

Do not create:

```text
ReconciliationEngine
AccountingReconciliationEngine
GenericMatchingEngine
StatementParser
ReconciliationDSL
ReportRegistry
BalanceSnapshotEngine
```

Keep the implementation simple and focused.

---

# 51. Final Phase 9 Deliverable

At completion create:

```text
PHASE_9_REPORT.md
```

The report must document:

1. Phase objective.
2. Files changed.
3. Migrations.
4. Models.
5. Enums.
6. Services.
7. Controllers.
8. Requests.
9. Resources.
10. Policies.
11. Routes.
12. Authorization matrix.
13. Company isolation.
14. Reconciliation formulas.
15. Lifecycle rules.
16. Movement eligibility.
17. Money handling.
18. Performance approach.
19. Tests.
20. Commands run.
21. Issues discovered and fixes.
22. Known limitations.
23. Outstanding work.
24. Requirements traceability.
25. Final test totals.

Do not claim completion until the implementation and tests actually pass.

---

# 52. HARD STOP CONDITIONS

Stop and report instead of improvising if:

- existing ledger semantics conflict with this blueprint;
- existing account balance semantics differ from the assumed debit-positive
  calculation;
- Phase 7 does not expose enough information to identify bank-account journal
  lines;
- existing journal source relationships cannot safely identify the source
  document;
- company isolation cannot be guaranteed;
- reconciliation would require modifying posted accounting data;
- a requested requirement would require an accounting schema change outside this
  phase;
- tests reveal a Phase 5/6/7 regression;
- an existing architectural convention contradicts a proposed class/path/name.

Do not silently change the accounting model to make Phase 9 fit.

---

# 53. Definition of Done

Phase 9 is complete only when:

- bank reconciliation tables exist;
- migrations apply and rollback successfully;
- only BANK accounts can be reconciled;
- company isolation is enforced;
- reconciliation periods are validated;
- ledger balances come from existing ledger infrastructure;
- statement balances are external values;
- posted ledger movements can be cleared;
- duplicate reconciliation items are prevented;
- previously completed movements are protected;
- reconciliation difference is calculated using Money;
- zero difference is required for completion;
- completion is transactional and locked;
- reopening is explicitly authorized;
- reconciliation never modifies accounting journals;
- reconciliation never modifies cash-bank transactions;
- reconciliation never stores operational balances;
- API uses existing response conventions;
- authorization is tested;
- cross-company access is tested;
- draft journals are excluded;
- exact decimal handling is tested;
- Phase 6 reports remain unchanged;
- Phase 7 cash/bank functionality remains unchanged;
- full test suite passes;
- Pint passes;
- route verification passes;
- `PHASE_9_REPORT.md` is generated.

**Do not implement beyond this scope.**
