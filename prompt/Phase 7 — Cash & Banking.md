# Phase 7 — Cash & Banking

## 1. Phase Objective

Phase 7 adds the operational **Cash & Banking** module on top of the completed
Phase 1–6 accounting foundation.

Phase 6 provides read-only reporting, including the existing Cash/Bank Activity
report. Phase 7 now provides the transactional capability required to create and
post legitimate cash and bank movements.

The phase should support:

- Cash accounts
- Bank accounts
- Deposits
- Withdrawals
- Transfers between cash/bank accounts
- Bank/cash ledger activity
- Opening balances where supported by the existing accounting architecture
- Posting these transactions into the existing accounting ledger
- Company isolation
- Role/permission enforcement
- Auditability
- Exact monetary arithmetic using the existing `Money` implementation

The implementation must reuse the existing accounting engine, journal/posting
infrastructure, account model, company context, authorization system, and Phase
6 reporting services.

Do **not** create a second accounting engine.

Do **not** create a separate cash ledger that duplicates `journal_lines`.

The accounting journal remains the financial source of truth.

---

# 2. Scope

## In Scope

### 2.1 Cash Accounts

Allow a company to identify accounts used for physical cash.

Examples:

- Main Cash
- Petty Cash
- Office Cash
- Branch Cash

Cash accounts should use the existing Chart of Accounts wherever possible.

Do not create a second account hierarchy merely for cash.

---

### 2.2 Bank Accounts

Allow a company to identify accounts used for bank transactions.

Examples:

- HDFC Bank
- SBI Current Account
- ICICI Bank
- Business Savings Account

Bank account information should contain only information that is genuinely
required by the existing project requirements.

Potential fields:

- account name
- bank name
- account number/reference
- branch
- IFSC or equivalent bank identifier where applicable
- opening balance if the existing architecture requires it
- status/active flag if consistent with existing master-data patterns

Do not store sensitive banking credentials.

Never store:

- internet banking passwords
- OTPs
- PINs
- CVV
- card authentication secrets

---

# 3. Important Architectural Rule

The existing Chart of Accounts is the accounting source of truth.

Cash and bank operational records must ultimately post through the existing
journal/posting system.

For example:

## Cash deposit

If money is deposited from a bank account into cash:

```text
Dr Cash Account
    Cr Bank Account
```

## Cash withdrawal from bank

```text
Dr Cash Account
    Cr Bank Account
```

## Transfer between two bank accounts

```text
Dr Destination Bank Account
    Cr Source Bank Account
```

The exact account-side implementation must follow the existing Phase 4/5
journal-posting conventions rather than inventing a new posting mechanism.

---

# 4. Transaction Types

Phase 7 should support three fundamental movement types.

## 4.1 Deposit

Money enters a cash/bank account.

Examples:

- cash deposited into bank
- external money received into bank
- opening operational balance where explicitly supported

The transaction must identify the destination account.

---

## 4.2 Withdrawal

Money leaves a cash/bank account.

Examples:

- cash withdrawal
- bank withdrawal
- bank charges where the existing accounting architecture supports an expense
  account

The transaction must identify the source account.

---

## 4.3 Transfer

Money moves from one cash/bank account to another cash/bank account.

Examples:

```text
Cash → Bank
Bank A → Bank B
Bank → Cash
Cash → Cash
```

A transfer must contain:

- source account
- destination account
- amount
- transaction date
- reference
- description/remarks where consistent with existing transaction patterns

Source and destination must be different accounts.

A transfer must generate a balanced journal entry.

---

# 5. Account Eligibility

Do not assume that every account in the Chart of Accounts can be used as a
cash/bank account.

The implementation must inspect the existing account type/subtype architecture
first.

The preferred design is:

- reuse existing `ASSET` accounts
- identify eligible cash/bank accounts using the existing account classification
  if one already exists
- only introduce a new classification field if the existing schema genuinely
  cannot distinguish cash/bank accounts

Do not introduce unnecessary account-type abstractions.

If the existing Chart of Accounts already has a suitable subtype/category
mechanism, use it.

---

# 6. Cash/Bank Account Model

Before creating a new model, inspect the existing `Account` model and accounting
schema.

If the existing account structure can represent cash/bank identity cleanly,
extend it minimally.

If operational bank metadata cannot reasonably belong on `accounts`, create a
focused model such as:

```text
BankAccount
```

or an appropriately named equivalent consistent with the existing project.

The relationship must remain company-scoped.

A bank record must never be usable by another company.

The same rule applies to cash-account configuration if a separate model is
introduced.

---

# 7. Transaction Persistence

A cash/bank transaction needs an operational record so the application can
identify:

- transaction type
- source account
- destination account
- amount
- date
- reference
- description
- status
- journal linkage

However, the operational record must **not become a second balance ledger**.

Do not store:

- running balance
- current balance
- calculated closing balance
- duplicated debit total
- duplicated credit total

Balances must continue to be derived from accounting journal lines.

A transaction may store a reference to its resulting journal/document because
traceability is required.

---

# 8. Transaction Status

Follow the existing accounting transaction/document lifecycle.

Inspect Phase 4/5 before implementation.

Do not invent a second status system if an existing transaction status enum or
posting lifecycle can be reused.

At minimum, the implementation must distinguish between:

- draft/unposted
- posted

A draft cash/bank transaction must not affect:

- Trial Balance
- General Ledger
- P&L
- Balance Sheet
- Cash/Bank Activity
- any other posted accounting report

Once posted, its journal must become visible through the existing reporting
infrastructure.

---

# 9. Posting Rules

Cash/bank posting must use the existing journal/posting services.

Do not directly insert arbitrary `journal_lines` from the controller.

Preferred flow:

```text
Controller
    ↓
Form Request
    ↓
Cash/Bank Service
    ↓
Existing Journal/Posting Service
    ↓
Journal
    ↓
Journal Lines
```

The exact service names must be discovered from the existing codebase before
implementation.

OpenCode must not guess existing service names.

---

# 10. Deposit Posting

For a deposit into an account:

```text
Debit destination cash/bank account
Credit appropriate source account
```

The source account depends on the business transaction.

Do not hard-code an arbitrary offset account.

If the existing requirement only defines an internal bank/cash transfer, use the
source cash/bank account.

If an external deposit requires an offset account, require an appropriate
account selection rather than silently choosing one.

---

# 11. Withdrawal Posting

For a withdrawal:

```text
Credit source cash/bank account
Debit appropriate destination/expense/account
```

The offset account must be explicit where required.

Do not automatically post every withdrawal to an expense account.

For example, transferring money from Bank A to Cash is not an expense:

```text
Dr Cash
Cr Bank A
```

Whereas a bank charge could be:

```text
Dr Bank Charges Expense
Cr Bank
```

The transaction type and selected accounts must determine the correct journal.

---

# 12. Transfer Posting

Internal transfer:

```text
Dr destination
Cr source
```

Requirements:

- source required
- destination required
- source != destination
- both accounts belong to active company
- both accounts must be eligible cash/bank accounts
- amount > 0
- transaction must be balanced
- one operational transaction should produce one balanced journal

Do not create two unrelated transactions for a transfer.

---

# 13. Opening Balances

Before implementing opening balances, inspect whether the existing accounting
system already has a formal opening-balance mechanism.

If one exists:

- reuse it.

If none exists:

- do not create a second permanent balance system merely for Phase 7.

If the business requirement requires an opening balance, represent it through
the existing accounting journal mechanism using the appropriate opening-balance
treatment.

The balance must ultimately be visible through:

- Trial Balance
- General Ledger
- Cash/Bank Activity
- Balance Sheet where applicable

Do not store an independent `opening_balance` that becomes authoritative.

---

# 14. Cash/Bank Ledger

Phase 6 already provides:

```text
GET /api/accounting/reports/cash-bank
```

Phase 7 must make actual posted cash/bank transactions appear in that existing
report automatically through journal posting.

Do not create another cash ledger endpoint that duplicates Phase 6.

The existing General Ledger/Cash Bank reporting infrastructure remains the
read-only reporting layer.

Phase 7 adds transaction creation/posting.

---

# 15. API Endpoints

Use the existing API conventions.

The exact naming should follow the project's existing resource naming style.

Recommended endpoints:

```text
GET    /api/accounting/cash-bank/accounts
POST   /api/accounting/cash-bank/accounts

GET    /api/accounting/cash-bank/transactions
GET    /api/accounting/cash-bank/transactions/{id}

POST   /api/accounting/cash-bank/deposits
POST   /api/accounting/cash-bank/withdrawals
POST   /api/accounting/cash-bank/transfers

POST   /api/accounting/cash-bank/transactions/{id}/post
```

If the existing project uses a different transaction lifecycle, follow that
architecture instead.

Do not create endpoints that duplicate existing accounting journal APIs.

---

# 16. API Response

Use the existing:

```php
ApiResponse::success()
```

response convention.

Example:

```json
{
  "success": true,
  "message": "Cash transfer posted successfully.",
  "data": {
    "id": 1,
    "transaction_type": "transfer",
    "transaction_date": "2027-02-10",
    "source_account": {},
    "destination_account": {},
    "amount": "1000.0000",
    "status": "POSTED"
  }
}
```

The exact existing API resource/response conventions must be inspected first.

Do not introduce a new response format.

---

# 17. Permissions

Phase 6 introduced:

```text
accounting.reports.view
```

Do not reuse that permission for write operations.

Add only the permissions actually required by the existing authorization
architecture.

Recommended minimum permissions:

```text
accounting.cash_bank.view
accounting.cash_bank.create
accounting.cash_bank.post
```

If the existing architecture makes a simpler permission model appropriate, use
fewer permissions rather than creating unnecessary granular permissions.

Role expectations should follow the existing accounting role model.

At minimum:

| Role       |                                          View |                 Create |                           Post |
| ---------- | --------------------------------------------: | ---------------------: | -----------------------------: |
| Admin      |                                           Yes |                    Yes |                            Yes |
| Accountant |                                           Yes |                    Yes |                            Yes |
| Manager    |                                           Yes |                    Yes |                            Yes |
| Staff      | Follow existing accounting transaction policy | Follow existing policy | Follow existing posting policy |

Do not change Phase 5 permissions or posting authorization unrelated to Cash &
Banking.

---

# 18. Company Isolation

Company isolation is mandatory.

Every cash/bank operation must operate inside the active `CompanyContext`.

Never accept `company_id` from the client.

All of the following must be company-scoped:

- cash/bank account lookup
- source account
- destination account
- transaction
- journal
- journal lines
- bank metadata
- account configuration

A cross-company source or destination account must be rejected.

A transaction from another company must never be visible.

Use the same company-isolation patterns already established in Phases 4–6.

---

# 19. Validation

Validation must include:

### Common

- transaction date required
- valid date
- amount required
- amount > 0
- maximum decimal precision consistent with `DECIMAL(20,4)`
- reference length according to existing project conventions
- description length according to existing conventions

### Transfer

- source account required
- destination account required
- source != destination
- both accounts belong to active company
- both accounts are eligible cash/bank accounts

### Deposit

- destination account required
- destination belongs to active company
- destination is eligible

### Withdrawal

- source account required
- source belongs to active company
- source is eligible

Never trust client-supplied:

- company ID
- journal ID
- journal balance
- calculated balance
- posting result

---

# 20. Money Handling

Use the existing `Money` value object everywhere.

Never use:

```php
(float) $amount
```

Never perform financial calculations using floating-point arithmetic.

Maintain:

```text
DECIMAL(20,4)
```

and the project's existing money serialization convention.

Amounts returned by APIs must remain compatible with the existing accounting
API.

---

# 21. Posting Integrity

Every posted transaction must satisfy:

```text
total debits == total credits
```

Posting must happen atomically.

If journal creation succeeds but the operational transaction update fails, the
entire operation must roll back.

If operational transaction creation succeeds but journal posting fails, the
entire operation must roll back.

Use Laravel database transactions and the existing posting service.

Do not implement manual rollback logic where the existing accounting service
already provides atomicity.

---

# 22. Duplicate Posting Protection

A transaction must not be posted twice.

Before posting:

- verify its current status
- verify whether a journal is already associated
- reject duplicate posting

A repeated POST request must not create a second journal.

This should be covered by a feature test.

---

# 23. Edit/Delete Rules

Inspect existing accounting transaction rules before implementing mutation
endpoints.

Once a cash/bank transaction is posted, do not allow arbitrary mutation that can
alter accounting history.

If the existing project supports cancellation/reversal:

- reuse that mechanism.

If it does not:

- keep posted transactions immutable.

Do not implement destructive deletion of posted financial transactions.

Draft transactions may be editable according to the existing accounting
lifecycle.

---

# 24. Account Balance

Do not add a stored balance column.

A cash/bank balance must be derived from posted journal lines.

For an asset-normal cash/bank account:

```text
balance = total debits - total credits
```

The existing General Ledger and Trial Balance remain authoritative.

If the UI needs a current balance, calculate it from the existing
ledger/reporting infrastructure.

Do not maintain a cached balance in Phase 7.

---

# 25. Transaction Listing

The transaction listing should support useful filters consistent with existing
APIs.

Potential filters:

- transaction type
- status
- account
- source account
- destination account
- from date
- to date
- reference

All date filtering must use the accounting transaction date, not `created_at`.

Pagination should follow existing project conventions.

Do not introduce a custom pagination implementation.

---

# 26. Auditability

Every posted cash/bank transaction must be traceable:

```text
Cash/Bank Transaction
        ↓
Journal
        ↓
Journal Lines
```

The API should expose enough information to trace the transaction without
exposing internal implementation details unnecessarily.

A transaction should retain its journal reference/relationship where consistent
with the existing architecture.

---

# 27. Bank Metadata

If a separate bank-account configuration model is required, keep it deliberately
small.

Possible fields:

```text
id
company_id
account_id
bank_name
account_name
account_number/reference
branch
ifsc/reference
is_active
timestamps
```

Do not introduce:

- reconciliation engine
- statement-import engine
- bank API integrations
- online banking authentication
- payment gateway integration

Those belong to future phases.

---

# 28. Cash Account Configuration

Cash accounts should preferably reuse existing accounts.

If configuration is needed, it should be minimal.

For example:

```text
account_id
cash_account_type
is_active
```

Do not create a separate balance table.

---

# 29. Bank Reconciliation

Bank reconciliation is **not Phase 7 scope** unless the existing project already
contains a reconciliation mechanism.

Do not implement:

- statement imports
- CSV bank statement matching
- automatic matching
- unmatched transaction queues
- reconciliation periods
- reconciliation reports

Phase 7 only establishes correct cash/bank transactions and ledger visibility.

---

# 30. Reporting Integration

The following Phase 6 reports must automatically reflect posted Phase 7
transactions:

### Trial Balance

Cash/bank account balances must change through journal lines.

### General Ledger

The cash/bank account must show the transaction.

### Balance Sheet

Cash/bank asset balances must change.

### Cash/Bank Activity

The existing report must display the posted transaction.

No Phase 6 report should be rewritten merely to accommodate Phase 7.

If an integration defect is found, fix the smallest underlying issue.

---

# 31. Testing Requirements

Add focused feature tests.

Recommended test files:

```text
tests/Feature/Accounting/CashBank/
    CashBankAccountTest.php
    CashBankDepositTest.php
    CashBankWithdrawalTest.php
    CashBankTransferTest.php
    CashBankPostingTest.php
    CashBankIsolationTest.php
    CashBankPermissionTest.php
```

The exact structure should follow the existing test organization if it differs.

---

# 32. Critical Tests

At minimum, prove:

### Accounts

- cash/bank account can be identified
- bank metadata is company-scoped
- inactive account cannot be used if the project supports active/inactive status

### Deposit

- valid deposit can be created
- correct journal is generated
- debit/credit are correct
- journal balances
- posted transaction appears in Cash/Bank Activity

### Withdrawal

- valid withdrawal can be created
- correct journal is generated
- debit/credit are correct
- journal balances

### Transfer

- Bank A → Bank B works
- Bank → Cash works
- Cash → Bank works
- source and destination cannot be identical
- both accounts must belong to active company
- correct journal is generated
- one transfer creates one balanced accounting event

### Draft

- draft transaction does not affect reports

### Posting

- draft can be posted according to existing workflow
- posted transaction cannot be posted twice
- posted transaction cannot be silently edited
- posting is atomic

### Isolation

- another company's account cannot be selected
- another company's transaction cannot be viewed
- another company's bank metadata cannot be accessed

### Authorization

- unauthorized role receives 403
- authorized accounting roles can perform permitted operations
- existing Phase 5 permissions remain unchanged

### Precision

Use non-round values such as:

```text
1175.2500
```

and verify exact ledger values.

### Reporting integration

After posting a cash/bank transaction, verify:

- Trial Balance
- General Ledger
- Balance Sheet
- Cash/Bank Activity

all reflect the same accounting movement.

---

# 33. Accounting Integrity Tests

The following properties are mandatory.

## Property 1 — No duplicate ledger

A single operational transaction must not generate duplicate journal entries.

## Property 2 — Balanced posting

Every posted transaction must satisfy:

```text
sum(debits) == sum(credits)
```

## Property 3 — No draft leakage

Draft transactions must not appear in posted reports.

## Property 4 — Company isolation

A company must never see another company's cash/bank transaction.

## Property 5 — Ledger consistency

The operational transaction amount must equal the corresponding journal
movement.

## Property 6 — Report consistency

The same posted transaction must appear consistently in:

- Trial Balance
- General Ledger
- Cash/Bank Activity
- Balance Sheet where applicable

## Property 7 — No stored balance dependency

The system must not require a stored cash/bank balance to calculate the
financial balance.

---

# 34. Performance

Avoid N+1 queries.

Account listings should not query the database once per account.

Transaction listings should eager-load required relationships.

Posting should use a bounded number of queries.

Do not load the entire journal table into PHP.

Use SQL aggregation where appropriate.

Do not introduce caching in Phase 7.

---

# 35. Service Architecture

Use focused services.

Recommended structure:

```text
app/Services/Accounting/CashBank/
    CashBankAccountService.php
    CashBankTransactionService.php
    CashBankPostingService.php
```

These names are recommendations only.

OpenCode must first inspect existing service naming and reuse existing
abstractions where appropriate.

Do not create:

```text
AccountingEngine
CashBankEngine
TransactionEngine
PostingEngine
ReportEngine
```

Do not create a generic workflow/DSL framework.

The project has deliberately avoided premature abstraction in Phase 6.

Continue that approach.

---

# 36. Controllers

Controllers should remain thin.

They should:

- authorize
- validate
- call the appropriate service
- return the existing API response

Controllers must not contain:

- journal construction logic
- debit/credit calculations
- balance calculations
- company-isolation logic duplicated manually
- financial arithmetic

---

# 37. Form Requests

Use dedicated Form Requests consistent with existing phases.

Possible requests:

```text
CashBankAccountRequest
CashBankDepositRequest
CashBankWithdrawalRequest
CashBankTransferRequest
CashBankTransactionFilterRequest
```

Do not create a large generic request abstraction unless existing architecture
already uses one.

Company-scoped account validation must happen at request level and again be
protected by service-level lookup where appropriate.

---

# 38. No Unnecessary Migration

Before creating migrations, inspect the current schema.

Phase 7 should add only the schema required to represent information that
genuinely does not already exist.

Do not create migrations merely to duplicate:

- account balances
- ledger entries
- debit/credit totals
- journal lines
- company information

If existing `accounts` can represent cash/bank identity adequately, use them.

---

# 39. No Seeder Without Permission

Do not run or create seeders automatically.

Do not modify production/demo data.

If test fixtures require data, use the existing factories/helpers inside tests.

---

# 40. Existing Phase 6 Compatibility

Phase 6 is considered complete and must remain stable.

Do not change:

```text
GET /api/accounting/reports/trial-balance
GET /api/accounting/reports/general-ledger
GET /api/accounting/reports/profit-loss
GET /api/accounting/reports/balance-sheet
GET /api/accounting/reports/customer-statement
GET /api/accounting/reports/supplier-statement
GET /api/accounting/reports/receivables
GET /api/accounting/reports/payables
GET /api/accounting/reports/receivables-aging
GET /api/accounting/reports/payables-aging
GET /api/accounting/reports/cash-bank
```

unless a genuine Phase 7 integration defect requires a minimal correction.

Do not change the Phase 6 report response contract unnecessarily.

---

# 41. Regression Requirement

Before implementation:

```text
php artisan test
```

must establish the current baseline.

The expected Phase 6 baseline is:

```text
509 tests
2398 assertions
0 failures
0 errors
0 skipped
```

After implementation:

```text
php artisan test
```

must pass with the new Phase 7 tests included.

The Phase 1–6 tests must continue passing.

---

# 42. Required Verification

Run, as applicable:

```bash
php artisan test tests/Feature/Accounting/CashBank
php artisan test
./vendor/bin/pint
./vendor/bin/pint --test
php artisan route:list --path=accounting
php artisan route:list --path=cash-bank
```

Run syntax checks on all changed PHP files.

If migrations are added, verify them using the project's existing migration-test
procedure.

Do not run seeders.

---

# 43. Phase 7 Deliverable

Create:

```text
PHASE_7_REPORT.md
```

The report must document:

1. Phase objective
2. Files changed
3. Migrations
4. Models
5. Services
6. Controllers
7. Requests
8. Resources
9. Policies/permissions
10. Routes
11. Cash/bank transaction definitions
12. Posting rules
13. Company isolation
14. Accounting integrity
15. Report integration
16. Tests
17. Commands executed
18. Issues discovered and fixes
19. Known limitations
20. Outstanding work
21. Requirements traceability
22. Final test count and assertion count

Do not claim a feature is implemented unless it is actually implemented and
tested.

---

# 44. Explicitly Out of Scope

The following must NOT be implemented in Phase 7:

- Bank reconciliation
- Bank statement import
- CSV statement matching
- OFX/QFX import
- Open banking APIs
- Payment gateway integration
- Online banking credentials
- Cheque management
- Credit card processing
- Foreign currency conversion
- Multi-currency banking
- Budgeting
- Forecasting
- Financial dashboard redesign
- PDF/Excel/CSV report export
- Comparative financial reporting
- Historical allocation reconstruction
- COGS implementation
- General ledger redesign
- New accounting engine
- Cached balances
- Balance snapshots
- Automatic background reconciliation
- Unrequested schema redesign

These may belong to later phases.

---

# 45. Implementation Philosophy

Keep Phase 7 consistent with the project's existing architecture:

```text
Simple
Explicit
Company-scoped
Journal-driven
Money-safe
Read/write separated
No duplicated accounting truth
No premature abstraction
```

The accounting journal remains authoritative.

Operational Cash/Bank transactions are the user-facing transaction layer.

Phase 6 reports remain the read-only reporting layer.

The relationship should be:

```text
Cash/Bank Transaction
        ↓
Existing Accounting Posting
        ↓
Journal
        ↓
Journal Lines
        ↓
Phase 6 Reports
```

Not:

```text
Cash/Bank Transaction
        ↓
Separate Cash Ledger
        ↓
Separate Balance
        ↓
Reports
```

---

# 46. Definition of Done

Phase 7 is complete only when:

- Cash/bank accounts can be identified using the existing accounting structure.
- Bank metadata, if required, is company-scoped.
- Deposits work.
- Withdrawals work.
- Internal transfers work.
- Transactions use the existing accounting posting infrastructure.
- Posted transactions create balanced journal entries.
- Draft transactions do not affect accounting reports.
- Duplicate posting is prevented.
- Posted financial history cannot be silently altered.
- Company isolation is enforced.
- Authorization is enforced.
- No stored cash/bank balance becomes authoritative.
- Phase 6 Cash/Bank Activity reflects posted transactions.
- Trial Balance reflects the movements.
- General Ledger reflects the movements.
- Balance Sheet reflects the movements where applicable.
- Money precision remains intact.
- No Phase 1–6 regression occurs.
- All new feature tests pass.
- Full test suite passes.
- Pint passes.
- Route verification passes.
- No seeders are run without explicit permission.
- `PHASE_7_REPORT.md` accurately documents the implementation.

# 47. Final Hard Stop

OpenCode must **not** begin coding immediately.

First:

1. Inspect the existing accounting schema.
2. Inspect `Account`, `Journal`, `JournalLine`, posting services, transaction
   statuses, permissions, company scoping, and Phase 6 Cash/Bank reporting.
3. Identify whether cash/bank account classification already exists.
4. Identify whether an existing transaction/document abstraction can be reused.
5. Identify the existing journal-posting workflow.
6. Identify existing authorization conventions.
7. Identify existing API response and request conventions.
8. Produce a short implementation plan based on the actual repository.
9. Identify any conflict between this specification and the existing
   implementation.
10. Only then implement.

Do not replace working architecture merely because another architecture appears
cleaner.

If an existing component already solves the requirement, reuse it.

If a requirement cannot be implemented honestly without a schema change, stop
and document the reason before introducing the schema change.

After implementation, run the focused Phase 7 tests first, then the complete
regression suite.

The final response must report:

```text
Phase 7 status:
Tests:
Assertions:
Failures:
Errors:
Skipped:
Routes:
Migrations:
Seeders:
Pint:
```

and must clearly distinguish implemented functionality from known limitations.
