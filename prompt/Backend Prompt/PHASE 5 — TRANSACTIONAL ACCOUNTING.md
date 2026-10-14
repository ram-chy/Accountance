# PHASE 5 — TRANSACTIONAL ACCOUNTING

## OpenCode Implementation Prompt

You are working on an existing Laravel 13 API accounting application.

Do NOT redesign the existing architecture.

This phase must build on the completed Phase 1, Phase 2, Phase 3, and Phase 4
implementations.

---

# 1. IMPORTANT — READ THE EXISTING PROJECT FIRST

Before changing any code:

1. Read the existing project structure.
2. Read the existing Phase 1–4 implementation.
3. Read the existing:
   - Company model and CompanyContext
   - User/authentication architecture
   - PermissionName
   - policies
   - FormRequests
   - API Resources
   - Money value object
   - AccountingRules
   - AccountService
   - AccountingPeriodService
   - JournalService
   - JournalPostingService
   - LedgerService
   - JournalSource / JournalStatus / related enums
   - existing migrations
   - existing tests
   - existing exception/response conventions
4. Do not create parallel implementations of functionality that already exists.
5. Reuse existing accounting services and conventions.

Internet access is allowed if genuinely required for technical verification.

Do not rush.

Do not create seeders unless explicitly instructed.

---

# 2. PHASE 5 OBJECTIVE

Build the first transactional accounting layer.

Phase 5 introduces:

- Customers
- Suppliers
- Sales Invoices
- Purchase Bills
- Customer Receipts
- Supplier Payments
- Transaction-to-journal integration
- Transaction status/lifecycle
- Accounting-safe posting
- Transaction-level company isolation
- Transaction-level authorization
- Audit references between business transactions and accounting journals

The accounting system must remain double-entry.

Every financially completed transaction must ultimately produce a balanced
POSTED journal through the existing accounting posting architecture.

---

# 3. STRICT ARCHITECTURAL RULE

## NEVER create accounting entries directly from controllers.

Business modules must NOT manually manipulate:

- journals.status
- journal_lines
- posted_by
- posted_at
- ledger balances

Do not duplicate posting logic.

All accounting entries must pass through the existing:

`JournalService`

and

`JournalPostingService`

architecture.

The conceptual flow is:

```text
Business Transaction
        ↓
Transaction Validation
        ↓
Transaction State Change
        ↓
Create Draft Journal
        ↓
Validate Accounting Structure
        ↓
JournalPostingService
        ↓
POSTED Journal
        ↓
LedgerService
```

The transaction and its accounting journal must be committed atomically.

If accounting posting fails, the business transaction must not be left in a
financially completed state.

If the business transaction fails, its journal must not be posted.

---

# 4. PHASE 5 SCOPE

## 4.1 Customers

Create a company-scoped customer master.

Suggested fields:

- id
- company_id
- customer_code
- name
- email
- phone
- address
- city
- state
- country_code
- tax_identifier nullable
- receivable_account_id
- is_active
- timestamps

Rules:

- customer belongs to exactly one company
- customer_code unique per company
- customer name may be duplicated if the business requires it
- receivable account must belong to the same company
- receivable account must be an appropriate active account
- company_id is always server-owned
- customer cannot reference another company's account
- inactive customers cannot be used for new sales invoices
- existing historical transactions remain accessible

Do not hard-delete a customer with financial transaction history.

---

# 5. SUPPLIERS

Create a company-scoped supplier master.

Fields:

- id
- company_id
- supplier_code
- name
- email
- phone
- address
- city
- state
- country_code
- tax_identifier nullable
- payable_account_id
- is_active
- timestamps

Rules:

- supplier belongs to exactly one company
- supplier_code unique per company
- payable account must belong to the same company
- payable account must be active
- inactive suppliers cannot be used for new purchase bills
- historical transactions remain accessible
- no hard deletion once financial history exists

---

# 6. SALES INVOICES

Create company-scoped sales invoices.

Suggested structure:

### sales_invoices

- id
- company_id
- customer_id
- invoice_number
- invoice_date
- due_date
- status
- subtotal
- tax_total
- discount_total
- grand_total
- paid_total
- balance_due
- notes
- journal_id nullable
- created_by
- timestamps

### sales_invoice_lines

- id
- sales_invoice_id
- line_number
- description
- quantity
- unit_price
- discount
- tax_rate
- tax_amount
- line_total
- revenue_account_id

Do not store derived values unless there is a clear architectural reason.

At minimum:

- subtotal
- tax_total
- grand_total

must be calculated server-side.

Never trust client-supplied totals.

---

# 7. SALES INVOICE NUMBERING

Invoice numbering must be server-generated.

Use the company's configured invoice prefix from Company Settings.

Example:

```text
INV-000001
INV-000002
INV-000003
```

or the existing configured prefix.

Do not use:

- client-generated invoice numbers
- timestamps as invoice numbers
- random UUIDs as human invoice numbers

Create a concurrency-safe sequence mechanism similar to the existing
JournalNumberSequence.

Invoice numbers must never be silently reused.

---

# 8. SALES INVOICE LIFECYCLE

Use an explicit state machine.

Minimum states:

```text
DRAFT
    ↓
POSTED
    ↓
PARTIALLY_PAID
    ↓
PAID
```

Cancellation must be designed carefully.

Do NOT allow:

```text
POSTED → DRAFT
```

Do NOT edit a posted financial invoice in place.

A posted invoice is an accounting document.

If cancellation is required, implement a proper reversal/cancellation workflow
rather than mutating the original posted financial history.

If the complete cancellation design is not necessary for this phase, keep
cancellation explicitly out of scope rather than implementing an unsafe
shortcut.

---

# 9. SALES ACCOUNTING

A normal credit sale should produce:

```text
Dr Accounts Receivable
    Cr Sales Revenue
```

If tax is applicable:

```text
Dr Accounts Receivable       Gross Amount
    Cr Sales Revenue         Net Amount
    Cr Tax Payable           Tax Amount
```

The exact account selection must come from the invoice/customer configuration
and/or validated account mappings.

Never hard-code arbitrary account IDs.

All referenced accounts must:

- belong to the active company
- exist
- be active
- be valid for the transaction purpose

The journal must be created and posted through the existing posting engine.

---

# 10. PURCHASE BILLS

Create company-scoped purchase bills.

Suggested structure:

### purchase_bills

- id
- company_id
- supplier_id
- bill_number
- bill_date
- due_date
- status
- subtotal
- tax_total
- discount_total
- grand_total
- paid_total
- balance_due
- notes
- journal_id nullable
- created_by
- timestamps

### purchase_bill_lines

- id
- purchase_bill_id
- line_number
- description
- quantity
- unit_cost
- discount
- tax_rate
- tax_amount
- line_total
- expense_account_id

Do not implement inventory valuation in this phase.

For Phase 5, purchase bills are accounting transactions.

---

# 11. PURCHASE BILL ACCOUNTING

A normal credit purchase should produce:

```text
Dr Expense / Purchase Account
    Cr Accounts Payable
```

With tax:

```text
Dr Expense / Purchase Account
Dr Input Tax
    Cr Accounts Payable
```

The exact accounts must be explicitly selected/validated.

Do not assume all purchases are inventory.

Inventory accounting belongs to a future inventory phase.

---

# 12. CUSTOMER RECEIPTS

Implement customer receipt transactions.

Example:

```text
Receipt
    ↓
Customer
    ↓
Outstanding invoice(s)
    ↓
Payment account
```

Accounting:

```text
Dr Cash / Bank
    Cr Accounts Receivable
```

The receipt must support:

- payment date
- payment account
- amount
- reference
- notes
- customer
- allocation to one or more outstanding invoices

Do not allow allocation greater than:

```text
invoice outstanding balance
```

unless an explicit customer-credit/advance mechanism has been designed.

Do not silently create negative invoice balances.

---

# 13. SUPPLIER PAYMENTS

Implement supplier payment transactions.

Accounting:

```text
Dr Accounts Payable
    Cr Cash / Bank
```

Support:

- supplier
- payment date
- payment account
- amount
- reference
- notes
- allocation against outstanding purchase bills

Do not allow allocation greater than the outstanding payable unless an explicit
advance-payment mechanism is implemented.

---

# 14. PAYMENT ALLOCATION

Payment allocation must be represented explicitly.

Do not store only:

```text
invoice.paid_total
```

and try to infer which payment paid it.

Use allocation tables.

Example:

### customer_receipt_allocations

- id
- receipt_id
- sales_invoice_id
- amount

### supplier_payment_allocations

- id
- payment_id
- purchase_bill_id
- amount

Constraints:

- allocation belongs to same company
- referenced transaction belongs to same company
- amount > 0
- allocation cannot exceed available outstanding balance
- total allocations cannot exceed payment amount

All allocation calculations must use exact decimal arithmetic.

Use the existing Money implementation.

---

# 15. PAYMENT ACCOUNT

Cash and bank accounts must be normal accounting accounts.

Do not create a separate hidden financial balance system.

Example:

```text
Account Type = ASSET

Cash
Bank
Petty Cash
```

Payment transactions affect those accounts through journals.

Their balances must therefore come from:

```text
Journal Lines
    ↓
LedgerService
```

not from a separate `cash_balance` or `bank_balance` column.

---

# 16. TRANSACTION ↔ JOURNAL RELATIONSHIP

Each financially posted transaction should have a direct reference to its
accounting journal.

Example:

```text
sales_invoices.journal_id
purchase_bills.journal_id
customer_receipts.journal_id
supplier_payments.journal_id
```

The existing journal:

```text
source_type
source_id
```

must also be populated appropriately.

Do not create a second journal table.

Do not create a second ledger.

The relationship should be:

```text
Business Transaction
        ↕
Accounting Journal
        ↓
Journal Lines
        ↓
Ledger
```

---

# 17. JOURNAL SOURCE TYPES

Extend the existing `JournalSource` enum only if required.

Possible values:

```text
MANUAL
SALES_INVOICE
PURCHASE_BILL
CUSTOMER_RECEIPT
SUPPLIER_PAYMENT
```

Follow the existing enum architecture.

Do not create arbitrary strings throughout the codebase.

---

# 18. ATOMIC TRANSACTION RULE

This is one of the most important requirements of Phase 5.

For example, when posting a sales invoice:

```text
BEGIN TRANSACTION

1. Lock invoice
2. Verify invoice is eligible for posting
3. Validate customer
4. Validate accounts
5. Calculate totals
6. Create accounting journal
7. Create journal lines
8. Post through JournalPostingService
9. Store journal_id on invoice
10. Change invoice status to POSTED

COMMIT
```

If any step fails:

```text
ROLLBACK
```

The result must never be:

```text
Invoice = POSTED
Journal = missing
```

or:

```text
Journal = POSTED
Invoice = DRAFT
```

---

# 19. IMMUTABILITY

After financial posting:

## Sales Invoice

Do not allow modification of:

- customer
- invoice date
- amounts
- lines
- accounts
- journal relationship

## Purchase Bill

Same principle.

## Receipt

Same principle.

## Supplier Payment

Same principle.

If a correction is required, use a proper reversal/adjustment mechanism.

Do not simply reopen and edit posted accounting documents.

---

# 20. COMPANY ISOLATION

Every Phase 5 resource must follow the Phase 3/4 company architecture.

Never accept company_id from the client as authoritative.

Never trust:

```text
company_id
```

from:

- request body
- query string
- route parameter

The active company comes from:

```text
CompanyContext
```

Cross-company references must fail safely.

Examples:

- customer from Company A cannot be used in Company B invoice
- supplier from Company A cannot be used in Company B bill
- account from Company A cannot be used in Company B invoice
- invoice from Company A cannot be paid by Company B
- payment from Company A cannot allocate to Company B bill

Prefer `404` for cross-company resource lookup where consistent with Phase 4.

---

# 21. AUTHORIZATION

Reuse the existing Phase 2/4 permission architecture.

Do not create a second permission system.

Add only the permissions actually required.

Suggested permissions:

```text
customers.view
customers.create
customers.update
customers.deactivate

suppliers.view
suppliers.create
suppliers.update
suppliers.deactivate

sales.invoices.view
sales.invoices.create
sales.invoices.update
sales.invoices.post

purchases.bills.view
purchases.bills.create
purchases.bills.update
purchases.bills.post

customer.receipts.view
customer.receipts.create
customer.receipts.post

supplier.payments.view
supplier.payments.create
supplier.payments.post
```

Use the established:

- PermissionName
- Gate
- Policies
- FormRequest authorization
- company membership

architecture.

Do not bypass CompanyPolicy/company membership.

---

# 22. ROLE MATRIX

Do not rewrite existing permissions.

Extend the current role matrix consistently.

Suggested baseline:

| Permission Area              | Admin | Accountant | Manager | Staff |
| ---------------------------- | ----: | ---------: | ------: | ----: |
| Customers view               |     ✓ |          ✓ |       ✓ |       |
| Customers create/update      |     ✓ |          ✓ |         |       |
| Customers deactivate         |     ✓ |          ✓ |         |       |
| Suppliers view               |     ✓ |          ✓ |       ✓ |       |
| Suppliers create/update      |     ✓ |          ✓ |         |       |
| Suppliers deactivate         |     ✓ |          ✓ |         |       |
| Sales invoices view          |     ✓ |          ✓ |       ✓ |       |
| Sales invoices create/update |     ✓ |          ✓ |         |       |
| Sales invoices post          |     ✓ |          ✓ |         |       |
| Purchase bills view          |     ✓ |          ✓ |       ✓ |       |
| Purchase bills create/update |     ✓ |          ✓ |         |       |
| Purchase bills post          |     ✓ |          ✓ |         |       |
| Customer receipts view       |     ✓ |          ✓ |       ✓ |       |
| Customer receipts create     |     ✓ |          ✓ |         |       |
| Customer receipts post       |     ✓ |          ✓ |         |       |
| Supplier payments view       |     ✓ |          ✓ |       ✓ |       |
| Supplier payments create     |     ✓ |          ✓ |         |       |
| Supplier payments post       |     ✓ |          ✓ |         |       |

Follow the existing authorization philosophy rather than blindly copying this
table if the current project has an established convention.

Document any intentional difference.

---

# 23. ACCOUNT VALIDATION

Transactions must not simply accept arbitrary account IDs.

For example:

### Sales invoice

Revenue account:

- must belong to current company
- must be active
- must be appropriate for revenue

Receivable account:

- must belong to current company
- must be active
- must be an asset/receivable-compatible account

### Purchase bill

Expense account:

- must belong to current company
- must be active
- must be appropriate for expense/purchase

Payable account:

- must belong to current company
- must be active
- must be liability-compatible

### Receipt

Payment account:

- must belong to current company
- must be active
- must be an asset account

### Supplier payment

Payment account:

- same rule

Centralize these rules.

Do not scatter account-type checks through controllers.

---

# 24. TAX

Implement only a simple transaction-level tax mechanism in this phase if
required by the existing specification.

Do not build a full international tax engine yet.

Do not introduce:

- country-specific tax law engines
- VAT jurisdiction engines
- GST engines
- tax filing
- tax return generation
- complex tax exemptions

The architecture should leave room for these later.

Tax amounts must still be calculated server-side and represented explicitly in
journal lines.

---

# 25. CURRENCY

Do NOT implement currency conversion in Phase 5.

Do NOT introduce exchange-rate calculations.

Do not pretend multi-currency support exists.

Use the existing company currency architecture.

If the project does not yet have the `currencies` table, do not invent a fake
currency system.

Keep the current Phase 3/4 decision intact.

---

# 26. REPORTING

Add basic transaction queries required to operate the modules.

At minimum:

### Sales

- invoice list
- invoice detail
- outstanding invoices
- customer receivable balance
- invoice payment history

### Purchases

- bill list
- bill detail
- outstanding bills
- supplier payable balance
- bill payment history

### Payments

- customer receipt list
- supplier payment list

Balances must be derived from:

```text
Posted Journals
+
Payment Allocations
```

Do not introduce a second financial balance source.

---

# 27. API DESIGN

Follow existing API conventions.

Suggested endpoints:

## Customers

```text
GET    /api/customers
POST   /api/customers
GET    /api/customers/{customer}
PUT    /api/customers/{customer}
POST   /api/customers/{customer}/activate
POST   /api/customers/{customer}/deactivate
```

## Suppliers

```text
GET    /api/suppliers
POST   /api/suppliers
GET    /api/suppliers/{supplier}
PUT    /api/suppliers/{supplier}
POST   /api/suppliers/{supplier}/activate
POST   /api/suppliers/{supplier}/deactivate
```

## Sales invoices

```text
GET    /api/sales/invoices
POST   /api/sales/invoices
GET    /api/sales/invoices/{invoice}
PUT    /api/sales/invoices/{invoice}
POST   /api/sales/invoices/{invoice}/post
```

## Purchase bills

```text
GET    /api/purchases/bills
POST   /api/purchases/bills
GET    /api/purchases/bills/{bill}
PUT    /api/purchases/bills/{bill}
POST   /api/purchases/bills/{bill}/post
```

## Customer receipts

```text
GET    /api/customer-receipts
POST   /api/customer-receipts
GET    /api/customer-receipts/{receipt}
POST   /api/customer-receipts/{receipt}/post
```

## Supplier payments

```text
GET    /api/supplier-payments
POST   /api/supplier-payments
GET    /api/supplier-payments/{payment}
POST   /api/supplier-payments/{payment}/post
```

Use the actual project's route naming conventions if they differ.

---

# 28. VALIDATION

All validation must happen server-side.

Never trust:

- subtotal
- tax_total
- grand_total
- paid_total
- balance_due
- journal_id
- company_id
- created_by
- posted_by
- posted_at
- status

Client-supplied derived/accounting fields must either be rejected or ignored
according to the established project convention.

Use exact decimal arithmetic.

Do not use floats for money.

---

# 29. CONCURRENCY

Protect against:

- duplicate invoice numbers
- duplicate bill numbers
- double posting
- double payment
- over-allocation
- simultaneous allocation
- concurrent posting of the same transaction

Use database transactions and appropriate row locking.

Do not rely solely on pre-flight validation.

Database uniqueness constraints must back up application validation.

---

# 30. PAYMENT ALLOCATION CONCURRENCY

This deserves special attention.

Two users must not be able to simultaneously allocate payments against the same
invoice and cause:

```text
allocated_total > invoice_total
```

Lock the relevant financial records during allocation.

The same rule applies to purchase bills.

Test concurrent allocation behavior as far as the test infrastructure reasonably
permits.

---

# 31. DATABASE INTEGRITY

Use foreign keys wherever appropriate.

Important relationships:

```text
customers.company_id → companies.id
suppliers.company_id → companies.id

sales_invoices.company_id → companies.id
sales_invoices.customer_id → customers.id
sales_invoices.journal_id → journals.id

sales_invoice_lines.sales_invoice_id → sales_invoices.id
sales_invoice_lines.revenue_account_id → accounts.id

purchase_bills.company_id → companies.id
purchase_bills.supplier_id → suppliers.id
purchase_bills.journal_id → journals.id

purchase_bill_lines.purchase_bill_id → purchase_bills.id
purchase_bill_lines.expense_account_id → accounts.id
```

Do not introduce redundant company IDs into child tables unless there is a
demonstrated architectural requirement.

Maintain the Phase 4 principle:

> Avoid duplicated ownership information that can disagree.

---

# 32. AUDITABILITY

Every financial transaction must preserve:

- creator
- creation time
- posting user
- posting time
- journal reference
- source type
- source ID

Do not overwrite historical financial facts.

---

# 33. NO SOFT ACCOUNTING HISTORY DESTRUCTION

Do not implement generic:

```text
DELETE posted invoice
DELETE posted bill
DELETE posted payment
```

as a normal CRUD operation.

Financial history must remain traceable.

If deletion is allowed, it must be restricted to unposted drafts.

---

# 34. SERVICE ARCHITECTURE

Use dedicated services.

Suggested:

```text
CustomerService
SupplierService

SalesInvoiceService
SalesInvoicePostingService

PurchaseBillService
PurchaseBillPostingService

CustomerReceiptService
CustomerReceiptPostingService

SupplierPaymentService
SupplierPaymentPostingService

PaymentAllocationService
```

Do not put accounting workflows into controllers.

Do not put large accounting workflows inside Eloquent models.

Business rules belong in services.

---

# 35. ACCOUNTING POSTING ADAPTER

Prefer a clean boundary between transaction logic and accounting logic.

For example:

```text
SalesInvoicePostingService
        ↓
Accounting Journal creation
        ↓
JournalPostingService
```

The business service determines:

- which accounts
- which amounts
- which journal source

The existing posting engine determines:

- structural validity
- balance
- account ownership
- account activity
- accounting period
- posted state
- posted_by
- posted_at
- immutability

Do not duplicate those checks unnecessarily.

---

# 36. TRANSACTION STATUS VS JOURNAL STATUS

Keep these concepts separate.

Example:

```text
Invoice:
DRAFT
POSTED
PARTIALLY_PAID
PAID

Journal:
DRAFT
POSTED
```

Do not add:

```text
Journal = PAID
```

The journal records accounting entry state.

The invoice records business transaction state.

---

# 37. PAYMENT STATUS

Payment allocation should determine invoice/bill payment state.

For invoices:

```text
POSTED + paid_total = 0
        → POSTED

0 < paid_total < grand_total
        → PARTIALLY_PAID

paid_total = grand_total
        → PAID
```

Use exact decimal comparisons.

Never calculate financial state with floating-point arithmetic.

---

# 38. TESTING REQUIREMENTS

Do not consider Phase 5 complete merely because the happy path works.

Write comprehensive feature tests.

At minimum test:

## Company isolation

- customer isolation
- supplier isolation
- invoice isolation
- bill isolation
- receipt isolation
- payment isolation
- cross-company account references
- cross-company journal references

## Authorization

Test the role matrix through actual HTTP requests.

## Customer

- create
- update
- activation
- duplicate code
- company isolation
- account validation
- deletion/history behavior

## Supplier

Same coverage.

## Sales Invoice

- draft creation
- numbering
- line calculation
- server-side totals
- invalid account
- cross-company account
- posting
- journal creation
- balanced journal
- immutable posted invoice
- correct receivable balance
- invalid period
- inactive account

## Purchase Bill

Equivalent coverage.

## Customer Receipt

- creation
- posting
- allocation
- partial payment
- full payment
- over-allocation rejection
- cross-company allocation
- correct journal

## Supplier Payment

Equivalent coverage.

## Concurrency

Test where practical:

- duplicate number generation
- duplicate posting
- over-allocation
- simultaneous transaction operations

---

# 39. ACCOUNTING TEST CASES

Explicitly verify the journal generated by every transaction.

For a sales invoice:

```text
Debit  Accounts Receivable
Credit Revenue
```

For a purchase bill:

```text
Debit  Expense/Purchase
Credit Accounts Payable
```

For a customer receipt:

```text
Debit  Cash/Bank
Credit Accounts Receivable
```

For a supplier payment:

```text
Debit  Accounts Payable
Credit Cash/Bank
```

Do not only test transaction status.

Test the actual journal lines and amounts.

---

# 40. LEDGER TESTS

After posting each transaction, verify the existing LedgerService produces the
expected result.

Example:

```text
Invoice posted:
AR increases
Revenue increases

Receipt posted:
Cash/Bank increases
AR decreases
```

The accounting reports must reflect the transaction without maintaining a
separate balance table.

---

# 41. MIGRATIONS

Create migrations only for Phase 5 entities.

Do not modify Phase 4 tables unnecessarily.

If a Phase 4 migration genuinely requires modification, stop and explain why
before making the change.

Prefer additive migrations.

---

# 42. SEEDERS

DO NOT create seeders.

DO NOT modify existing seeders.

Use factories only for automated tests where necessary.

---

# 43. FRONTEND

This phase is BACKEND ONLY.

Do not build the Next.js frontend in this phase.

The backend must expose a clean API that a future frontend can consume.

Do not add UI dependencies.

Do not modify frontend files unless a backend contract document requires it and
the project already contains frontend infrastructure that must be updated.

---

# 44. DOCUMENTATION

Create a final report:

```text
PHASE_5_REPORT.md
```

The report must contain:

1. Phase objectives
2. Files changed
3. Migrations
4. Database schema
5. Models
6. Services
7. API endpoints
8. Authorization matrix
9. Accounting rules
10. Transaction → journal mappings
11. Payment allocation design
12. Company isolation
13. Validation
14. Concurrency protection
15. Test coverage
16. Tests run and exact result
17. Issues discovered
18. Issues fixed
19. Known limitations
20. Outstanding work
21. Requirements traceability

Do not claim something is tested unless it was actually tested.

Do not claim concurrency was tested if only sequential tests were executed.

---

# 45. REQUIRED FINAL VERIFICATION

Before declaring Phase 5 complete:

Run:

```bash
php artisan migrate:fresh
php artisan test
```

Also run the relevant static/code quality checks already established by the
project.

Confirm:

- migrations work
- rollback works
- fresh migration works
- all previous Phase 1–4 tests still pass
- all Phase 5 tests pass
- no accounting regression exists

Report the exact:

```text
Tests:
Assertions:
```

numbers.

---

# 46. DO NOT DO THESE THINGS

Do NOT:

- redesign CompanyContext
- enable Spatie teams mode
- create a second authorization system
- create a second ledger
- create stored account balances
- bypass JournalPostingService
- allow clients to set company_id
- allow clients to set journal_id
- allow clients to set posted_by
- allow clients to set posted_at
- accept client-calculated financial totals as authoritative
- use floats for money
- create inventory functionality
- create payroll
- create budgets
- create currency conversion
- create tax filing
- create default account seeders
- reopen accounting periods
- edit posted journals
- edit posted financial transactions
- silently reverse accounting history
- create frontend UI
- modify unrelated Phase 1–4 architecture

---

# 47. IMPORTANT EXISTING PROJECT NOTES

Carry these existing items forward without attempting to solve them
opportunistically unless Phase 5 genuinely requires them:

- Wildcard CORS remains a development-only concern and must be restricted before
  production.
- User activation permission asymmetry remains from earlier phases.
- `User::$fillable` contains `is_active`.
- Phase 3 currency foreign keys remain deferred until the currency module
  exists.
- Phase 4 has not implemented currency conversion.
- Phase 4 has not implemented a genuine two-connection concurrent posting test.

Do not use Phase 5 as an excuse to perform unrelated refactoring.

---

# 48. DEFINITION OF DONE

Phase 5 is complete only when:

```text
Customers
        ↓
Suppliers
        ↓
Sales Invoices
        ↓
Purchase Bills
        ↓
Customer Receipts
        ↓
Supplier Payments
```

all operate within the existing company boundary,

AND

```text
Every posted financial transaction
        ↓
creates exactly one appropriate accounting journal
        ↓
through the existing posting engine
        ↓
with balanced journal lines
        ↓
inside an atomic transaction
```

AND

```text
Posted transaction
        ↓
Immutable financial history
        ↓
LedgerService
        ↓
Correct account balances
```

AND

```text
303+ existing tests
        +
Phase 5 tests
        =
All green
```

No Phase 5 feature should introduce a second source of accounting truth.

---

# 49. FINAL INSTRUCTION TO OPENCODE

Implement this phase carefully.

Do not rush.

First inspect the existing Phase 1–4 code and tests.

Then create a concise implementation plan internally.

Then implement incrementally.

After each major module, run its relevant tests.

Do not wait until the end to discover architectural conflicts.

If the existing implementation contradicts any requirement in this prompt, STOP
and report the contradiction rather than silently redesigning the existing
architecture.

At the end, produce:

```text
PHASE_5_REPORT.md
```

and provide the exact test command results.

Do not begin Phase 6.

Do not build the frontend.

Do not create seeders.

Stop after Phase 5 and wait for review.
