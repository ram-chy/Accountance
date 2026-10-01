# Phase 6 — Accounting Reports & Financial Statements

## Role

You are working on an existing Laravel accounting/ERP backend.

Implement **Phase 6 — Accounting Reports & Financial Statements** on top of the
completed Phase 1–5 system.

Do not redesign the existing architecture.

Do not replace existing accounting services, journal infrastructure,
authorization architecture, tenancy rules, or transaction models unless a
concrete defect prevents the Phase 6 requirements from being implemented
correctly.

Work carefully and incrementally. Inspect the existing code before modifying it.

---

# 1. Phase 6 Objective

Phase 5 established the transactional accounting engine:

- Customers and suppliers
- Sales invoices
- Purchase bills
- Customer receipts
- Supplier payments
- Payment allocations
- Journal generation
- Journal posting
- Company-scoped accounting
- Accounting periods
- Exact monetary arithmetic
- Role-based authorization

Phase 6 turns those transactions into **read-only accounting reports and
financial statements**.

The reports must read from the existing accounting truth:

- `journals`
- `journal_lines`
- accounts
- customers
- suppliers
- sales invoices
- purchase bills
- receipts
- payments
- allocation tables
- accounting periods

Do **not** create a second ledger or duplicate financial truth.

The objective is to provide reliable reporting without introducing stored
account balances or other duplicated accounting state.

---

# 2. Important Existing Architecture — MUST PRESERVE

Before writing code, inspect and understand the existing Phase 1–5
implementation.

The following rules are mandatory.

## 2.1 Single ledger

The existing `JournalService` and `JournalPostingService` are the accounting
ledger.

Phase 6 must only READ the posted journal data.

Do not create:

- report-specific ledger tables
- account balance tables
- monthly balance snapshots
- duplicated transaction ledgers
- stored trial-balance totals
- stored customer balances
- stored supplier balances

If a report needs a balance, calculate it from posted journal lines.

---

## 2.2 Company isolation

Every report must be scoped to the active company.

Never trust a `company_id` supplied by the client.

Use the existing:

- `CompanyContext`
- `CompanyScope`
- company-scoped relationships
- existing authorization architecture

A user must never be able to request another company's report.

---

## 2.3 Posted journals only

Financial reports must only include accounting entries that are actually posted.

Do not include:

- draft journals
- draft invoices
- draft bills
- draft receipts
- draft supplier payments
- deleted drafts
- unposted transactions

The journal is the accounting source of truth.

---

## 2.4 Accounting periods

Respect the existing Phase 4 accounting-period architecture.

Do not create another period implementation.

Inspect the existing `AccountingPeriodService` and journal date/period
relationships before implementing report filtering.

Reports must work correctly for:

- a specific date
- a date range
- a financial year
- an accounting period where applicable

Do not allow Phase 6 to reopen or modify closed periods.

Reports are read-only.

---

# 3. Phase 6 Reports

Implement the following reports.

## A. Trial Balance

Provide:

- account code
- account name
- account type
- debit total
- credit total
- net balance
- optional zero-balance filtering
- grand totals

Rules:

```text
debit_total = SUM(posted journal lines.debit)

credit_total = SUM(posted journal lines.credit)

net_balance = debit_total - credit_total
```

The report must be generated from posted journal lines.

The report must prove:

```text
total debits == total credits
```

for the selected reporting range.

Do not use stored account balances.

---

# 4. General Ledger

Implement an account-level General Ledger report.

Input:

```text
account_id
from_date
to_date
```

Output:

- account information
- opening balance
- transaction rows
- running balance
- closing balance

Each transaction row should include, where available:

- journal date
- journal number/reference
- description
- source/type
- debit
- credit
- running balance

Opening balance:

```text
opening_balance =
SUM(debits before from_date)
-
SUM(credits before from_date)
```

Running balance:

```text
running_balance =
opening_balance
+
SUM(debits through current row)
-
SUM(credits through current row)
```

Closing balance:

```text
closing_balance =
opening_balance
+
period_debits
-
period_credits
```

Use deterministic ordering.

Do not depend on database row order.

Use:

1. journal date
2. journal id
3. journal line id

or the existing equivalent identifiers.

---

# 5. Profit & Loss Statement

Implement a Profit & Loss report.

The report must calculate:

- revenue
- expenses
- net profit / net loss

Use account types already established by Phase 4/5.

At minimum:

```text
Revenue accounts
Expense accounts
```

Calculate:

```text
total_revenue
total_expenses
net_profit = total_revenue - total_expenses
```

Respect the normal accounting sign convention when presenting the report.

Do not alter journal values merely to make the report look positive.

Group results by:

- account
- account code
- account name

Then provide category totals.

The report must support:

```text
from_date
to_date
```

---

# 6. Balance Sheet

Implement a Balance Sheet report.

Include:

- Assets
- Liabilities
- Equity

Use the account types already defined by the existing accounting system.

Calculate balances from posted journal lines.

The report must provide:

### Assets

Each asset account:

- account code
- account name
- balance

### Liabilities

Each liability account:

- account code
- account name
- balance

### Equity

Each equity account:

- account code
- account name
- balance

### Current period result

Include the current reporting-period profit/loss as appropriate without creating
a new stored balance.

The report must satisfy:

```text
Assets == Liabilities + Equity
```

within the accounting system's exact decimal arithmetic.

Do not introduce a stored retained-earnings balance merely to make this report
balance.

---

# 7. Customer Statement

Implement a customer statement.

Input:

```text
customer_id
from_date
to_date
```

The statement should show the customer's accounting activity.

Include:

- customer information
- opening balance
- invoices
- receipts
- adjustments represented by posted journals if applicable
- debit
- credit
- running balance
- closing balance

Important:

The existing Phase 5 allocation system is the source for document settlement.

Do not calculate customer balances from `paid_total` because that is not a
stored column.

Use the existing accounting relationships and posted journal data.

Where document-level detail is required, connect:

```text
SalesInvoice
CustomerReceipt
CustomerReceiptAllocation
Journal
JournalLine
```

without creating duplicate balance data.

---

# 8. Supplier Statement

Implement the supplier equivalent.

Input:

```text
supplier_id
from_date
to_date
```

Include:

- supplier information
- opening balance
- purchase bills
- supplier payments
- posted accounting activity
- debit
- credit
- running balance
- closing balance

Use:

```text
PurchaseBill
SupplierPayment
SupplierPaymentAllocation
Journal
JournalLine
```

as appropriate.

Do not create stored supplier balances.

---

# 9. Outstanding Receivables Report

Implement an Accounts Receivable outstanding report.

It should identify posted sales invoices that still have an outstanding balance.

For every invoice show:

- invoice number
- invoice date
- customer
- grand total
- allocated amount
- outstanding amount
- status

The outstanding amount must be derived from Phase 5 allocation data.

Conceptually:

```text
paid_total =
SUM(posted customer receipt allocations)

balance_due =
grand_total - paid_total
```

Do not add a `paid_total` or `balance_due` column.

Support filters such as:

```text
customer_id
from_date
to_date
```

Only include invoices with:

```text
balance_due > 0
```

---

# 10. Outstanding Payables Report

Implement the supplier equivalent.

For every outstanding purchase bill show:

- bill number
- bill date
- supplier
- grand total
- allocated amount
- outstanding amount
- status

Use:

```text
SupplierPaymentAllocation
```

as the settlement source.

Do not create stored balance fields.

Only include:

```text
balance_due > 0
```

---

# 11. Receivables Aging

Implement an Accounts Receivable Aging report.

Group outstanding invoices into configurable aging buckets.

Default buckets:

```text
Current       0–30 days
31–60 days
61–90 days
91–120 days
121+ days
```

Use the invoice date as the aging basis unless the existing project already has
a documented due-date field.

Do not invent a due-date model if none exists.

If the current schema does not support a due date, explicitly use invoice date
and document that decision in the Phase 6 report.

For each customer provide:

- customer
- current
- 31–60
- 61–90
- 91–120
- 121+
- total outstanding

All figures must be derived from outstanding invoice balances.

---

# 12. Payables Aging

Implement the equivalent supplier aging report.

Buckets:

```text
Current       0–30 days
31–60 days
61–90 days
91–120 days
121+ days
```

Use bill date unless an existing documented due-date field exists.

Show:

- supplier
- bucket totals
- total outstanding

---

# 13. Cash / Bank Account Activity

Implement a report for payment/cash/bank accounts.

The existing Phase 5 payment transactions debit or credit the selected payment
account.

The report should show:

- account
- opening balance
- transaction date
- reference
- description
- debit
- credit
- running balance
- closing balance

This must be derived from posted journal lines.

Do not create a cash-balance table.

---

# 14. Reporting API Design

Follow the existing Laravel API architecture.

Inspect existing controllers, requests, resources, policies and route
conventions before implementation.

Create a reporting namespace such as:

```text
app/Services/Accounting/Reports/
app/Http/Controllers/Api/Accounting/
app/Http/Requests/Accounting/Reports/
app/Http/Resources/Accounting/Reports/
```

Only introduce a different structure if the existing project architecture
clearly requires it.

Prefer dedicated report services rather than placing SQL/reporting logic
directly inside controllers.

Example conceptual services:

```text
TrialBalanceReportService
GeneralLedgerReportService
ProfitLossReportService
BalanceSheetReportService
CustomerStatementReportService
SupplierStatementReportService
ReceivablesReportService
PayablesReportService
ReceivablesAgingReportService
PayablesAgingReportService
CashBankReportService
```

Shared report/query logic may be extracted where appropriate.

Do not create an unnecessarily complicated reporting framework.

Keep the architecture simple.

---

# 15. API Endpoints

Add read-only endpoints following existing route conventions.

Suggested structure:

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

These are examples of the intended API shape.

First inspect the existing route conventions and adapt the exact paths
consistently.

No report endpoint may mutate accounting data.

No report endpoint should accept:

```text
company_id
journal_id
balance
paid_total
```

as trusted client-supplied accounting values.

---

# 16. Validation

Create dedicated Form Requests for report filters where appropriate.

Validate:

- dates
- date ranges
- account IDs
- customer IDs
- supplier IDs
- optional filters

Rules:

```text
from_date <= to_date
```

IDs must belong to the active company.

Do not rely only on generic `exists` validation if that would allow
cross-company references.

Use the existing company-scoped architecture.

---

# 17. Authorization

Extend the existing authorization system.

Do not create a second authorization mechanism.

Add appropriate accounting report permissions.

Suggested permissions:

```text
accounting.reports.view
accounting.reports.trial_balance
accounting.reports.general_ledger
accounting.reports.profit_loss
accounting.reports.balance_sheet
accounting.reports.customer_statement
accounting.reports.supplier_statement
accounting.reports.receivables
accounting.reports.payables
accounting.reports.aging
accounting.reports.cash_bank
```

Before adding permissions, inspect the existing permission naming conventions.

Avoid unnecessary permissions if the existing architecture supports a simpler
reporting permission.

The important rule is:

- Admin: report access
- Accountant: report access
- Manager: report access
- Staff: no accounting reports

Do not change existing Phase 5 transaction permissions.

Posting remains restricted exactly as established in Phase 5.

---

# 18. Money Handling

This is an accounting system.

Do not use PHP floats for financial calculations.

Use the existing:

```text
Money
DECIMAL(20,4)
```

architecture.

Report calculations must preserve the existing four-decimal precision.

Do not introduce:

```php
(float) ...
```

for financial values.

Do not use floating-point arithmetic in SQL if the existing schema/database
strategy can avoid it.

Prefer database aggregation for large journal datasets and convert results
through the existing Money abstraction at the application boundary.

---

# 19. Performance

Reports may operate on large journal tables.

Avoid obvious N+1 queries.

For list reports:

- aggregate journal lines in SQL where practical
- eager load required relationships
- batch settlement calculations
- avoid querying the same account repeatedly
- avoid loading the entire journal table into PHP

For example, Trial Balance should not execute one query per account.

Use grouped aggregation.

General Ledger should retrieve the relevant account's journal lines in a
deterministic query.

Customer/Supplier statements should not execute one query for every transaction.

---

# 20. Date Semantics

Be explicit about date boundaries.

For a date range:

```text
from_date 00:00:00
through to_date 23:59:59
```

or use the existing project's date-only journal semantics if journal dates are
stored as dates.

Inspect the current schema before choosing.

Do not silently mix:

- transaction date
- created_at
- posted_at

unless the report requirement specifically calls for it.

Accounting reports should normally be based on the journal/document accounting
date, not database creation time.

---

# 21. Report Consistency

All reports must use the same accounting truth.

For example:

Trial Balance:

```text
SUM(all posted journal lines)
```

General Ledger:

```text
SUM(posted journal lines for one account)
```

Profit & Loss:

```text
SUM(posted revenue + expense accounts)
```

Balance Sheet:

```text
SUM(posted balance-sheet accounts)
```

Customer/Supplier statements:

```text
posted accounting activity for the relevant control account
```

Receivables/Payables:

```text
Phase 5 document totals
+
Phase 5 allocation data
```

Do not build different calculations for the same underlying accounting fact in
different services.

If a reusable query is appropriate, create it once and reuse it.

---

# 22. Zero-Balance Handling

Reports should support sensible zero-balance filtering.

For Trial Balance and Balance Sheet:

```text
include_zero_balances = false
```

should be the default unless the existing API conventions suggest otherwise.

The report should still include grand totals based on all relevant accounts, not
merely visible rows, where appropriate.

Document this behavior in the API resource/report response if necessary.

---

# 23. JSON Response Shape

Follow the existing API response conventions.

Do not invent a completely new response wrapper if the project already has one.

Conceptually:

```json
{
  "data": {
    "period": {
      "from": "2026-10-01",
      "to": "2026-10-31"
    },
    "rows": [],
    "totals": {}
  }
}
```

Adapt this to the existing project's established response format.

Reports should return structured data suitable for the future Next.js frontend.

Do not generate HTML inside the API.

---

# 24. Export

Do NOT implement PDF/Excel export unless the existing Phase 6 brief or project
architecture already requires it.

The primary Phase 6 objective is correct accounting data through API endpoints.

If export is not already specified, leave it out and record it as future work.

Do not introduce a large export subsystem unnecessarily.

---

# 25. Testing Requirements

Add comprehensive Feature/Unit tests.

At minimum test:

## Trial Balance

- correct debit totals
- correct credit totals
- balanced totals
- date filtering
- company isolation
- draft journal exclusion
- zero-balance filtering
- authorization

## General Ledger

- opening balance
- period transactions
- running balance
- closing balance
- deterministic ordering
- date filtering
- company isolation
- account isolation
- authorization

## Profit & Loss

- revenue aggregation
- expense aggregation
- net profit
- net loss
- date filtering
- draft exclusion
- company isolation
- authorization

## Balance Sheet

- asset totals
- liability totals
- equity totals
- accounting equation
- date filtering
- company isolation
- authorization

## Customer Statement

- opening balance
- invoices
- receipts
- running balance
- closing balance
- customer isolation
- cross-company rejection
- date filtering

## Supplier Statement

Same equivalent coverage.

## Receivables

- fully unpaid invoice
- partially paid invoice
- fully paid invoice excluded
- multiple allocations
- date filtering
- customer filtering
- company isolation

## Payables

Equivalent coverage.

## Aging

- current invoice
- 31–60
- 61–90
- 91–120
- 121+
- fully paid invoices excluded
- partially paid invoices correctly aged
- date/reference date behavior
- company isolation

## Cash/Bank

- opening balance
- receipts
- supplier payments
- running balance
- closing balance
- company isolation

---

# 26. Critical Integrity Tests

Add explicit tests proving:

### No draft journals enter reports

Create a draft journal and a posted journal.

The report must only include the posted journal.

### Cross-company isolation

Create identical accounts/documents in two companies.

Company A reports must never include Company B data.

### Report consistency

For the same period:

```text
Trial Balance total debit == Trial Balance total credit
```

and:

```text
P&L result
```

must reconcile with the Balance Sheet treatment of the current-period result.

### No stored balance dependency

Where practical, mutate or bypass any cached/derived-looking value and prove
reports still derive their figures from journal/allocation data.

Do not add artificial stored balances merely to satisfy a test.

### Allocation-based settlement

Receivables and payables must change when allocation rows change, subject to the
existing Phase 5 transaction rules.

They must not depend on a hypothetical `paid_total` database column.

---

# 27. Regression Protection

Before modifying anything:

```bash
php artisan test
```

Record the baseline.

After implementation run:

```bash
php artisan test
```

Also run:

```bash
./vendor/bin/pint --test
php artisan route:list --path=api
```

If migrations are changed, run:

```bash
php artisan migrate:fresh --env=testing --force
php artisan migrate:rollback --env=testing --force
php artisan migrate --env=testing --force
```

Do not run seeders unless explicitly instructed.

---

# 28. Do Not Break Phase 5

The following behavior must remain unchanged unless a concrete Phase 6 defect
requires otherwise:

- Customer deactivation instead of deletion
- Supplier deactivation instead of deletion
- Invoice lifecycle
- Bill lifecycle
- Receipt lifecycle
- Supplier payment lifecycle
- Payment allocation rules
- Allocation locking
- Document numbering
- Transaction account resolution
- Journal posting
- Accounting period checks
- Company isolation
- Existing role permissions
- Money precision
- No stored paid/balance columns
- No second ledger

Do not refactor these systems merely for stylistic reasons.

---

# 29. Known Phase 5 Limitations

Do not silently implement these as part of Phase 6:

- concurrency race tests
- advance/on-account payments
- credit notes
- purchase returns
- foreign exchange
- VOID lifecycle
- reopening closed accounting periods

They remain future work unless a report explicitly requires reading an existing
Phase 5 structure.

---

# 30. Special Attention: Accounting Sign Conventions

Before implementing P&L and Balance Sheet presentation, inspect the existing
Phase 4 account and journal conventions.

Do not assume the project's presentation sign convention.

Determine from the existing implementation:

- how debit balances are represented
- how credit balances are represented
- account type semantics
- equity behavior
- journal line conventions

Then implement reports consistently.

Do not "fix" the accounting engine as part of report presentation unless an
actual inconsistency is proven by tests.

---

# 31. No Premature Abstraction

Keep the implementation simple.

Do not create:

- generic report engines
- dynamic query DSLs
- metadata-driven report generators
- unnecessary repository layers
- unnecessary interfaces
- speculative caching
- report snapshot tables

A small number of focused report services is preferable.

Reuse existing accounting services and models.

---

# 32. Implementation Workflow

Follow this exact workflow.

## STEP 1 — Inspect

Inspect:

- Phase 4 accounting implementation
- Phase 5 transaction implementation
- `JournalService`
- `JournalPostingService`
- `AccountingPeriodService`
- `CompanyContext`
- `CompanyScope`
- `Money`
- account model
- journal model
- journal line model
- all Phase 5 transaction models
- authorization configuration
- existing API response conventions
- existing test helpers

Do not write code yet.

## STEP 2 — Map Existing Accounting Truth

Document internally:

```text
Account
    ↓
Journal
    ↓
JournalLine
```

and:

```text
SalesInvoice
    ↓
CustomerReceiptAllocation
    ↓
CustomerReceipt
    ↓
Journal
```

and:

```text
PurchaseBill
    ↓
SupplierPaymentAllocation
    ↓
SupplierPayment
    ↓
Journal
```

Verify the actual relationships in the repository.

Do not assume names from this prompt are exact if the code differs.

## STEP 3 — Design

Determine:

- report services
- report requests
- report resources
- routes
- permissions
- reusable aggregation queries
- test structure

Keep the design minimal.

## STEP 4 — Implement

Implement the reports in this order:

1. Trial Balance
2. General Ledger
3. Profit & Loss
4. Balance Sheet
5. Customer Statement
6. Supplier Statement
7. Receivables
8. Payables
9. Receivables Aging
10. Payables Aging
11. Cash/Bank Activity

Run focused tests after each logical group.

## STEP 5 — Authorization

Add and test report permissions.

## STEP 6 — Integrity Verification

Verify:

- company isolation
- posted-only reporting
- date filtering
- accounting equation
- allocation-based settlement
- no N+1 behavior in obvious paths
- no stored balance dependency

## STEP 7 — Full Regression

Run the complete test suite.

Do not stop after report-specific tests pass.

---

# 33. Code Quality

Follow the existing project's coding conventions.

Use:

- strict typing where already used
- dependency injection
- Form Requests
- API Resources
- Policies/permission middleware
- database aggregation
- existing Money abstraction
- existing company-scoping mechanisms

Do not introduce a new coding style.

Do not modify unrelated Phase 1–5 files.

If an existing bug blocks Phase 6, fix the smallest possible root cause and add
a regression test.

---

# 34. Final Verification Report

At the end create:

```text
PHASE_6_REPORT.md
```

The report must contain:

## 1. Phase Objectives

What was implemented.

## 2. Files Changed

Grouped by:

- migrations
- models
- services
- controllers
- requests
- resources
- policies
- routes
- authorization
- tests

If no migration was required, explicitly say so.

## 3. Report Definitions

For every report document:

- source tables
- calculation
- date semantics
- sign convention
- filters

## 4. API Endpoints

List:

- method
- path
- permission
- purpose

## 5. Authorization Matrix

Document actual role behavior.

## 6. Company Isolation

Explain how report queries remain tenant-scoped.

## 7. Accounting Integrity

Explain:

- posted-only rule
- trial balance equality
- balance sheet equation
- P&L treatment
- allocation-based AR/AP

## 8. Performance

Document aggregation strategy and any known query concerns.

## 9. Tests

Report exact:

```text
Tests:
Assertions:
Failures:
Errors:
Skipped:
```

Include both:

```text
Phase 6 tests
Full suite
```

## 10. Commands Run

Include exact verification commands.

## 11. Issues Discovered

List defects discovered by tests and how they were fixed.

## 12. Known Limitations

Do not hide limitations.

## 13. Outstanding Work

List remaining accounting/reporting work.

## 14. Requirements Traceability

Create a table:

| Requirement | Implementation | Test |
| ----------- | -------------- | ---- |

Every Phase 6 requirement must appear.

---

# 35. HARD STOP CONDITIONS

Stop and report instead of guessing if:

1. The existing journal/account structure contradicts the report assumptions.
2. Account type semantics are ambiguous.
3. Existing date semantics are unclear and materially affect financial results.
4. A report cannot be implemented without creating a new accounting truth.
5. A Phase 5 defect would require changing financial transaction behavior.
6. A requirement would require reopening or modifying a closed accounting
   period.
7. The existing authorization architecture cannot support the requested report
   permissions without a broader redesign.

Do not silently invent accounting rules.

---

# 36. Final Success Criteria

Phase 6 is complete only when:

- all requested reports are implemented
- reports are company-scoped
- only posted accounting data is included
- report calculations use existing accounting truth
- no second ledger exists
- no stored account balances are introduced
- receivable/payable balances remain allocation-derived
- Trial Balance debits equal credits
- Balance Sheet balances
- P&L calculates correctly
- General Ledger has correct opening/running/closing balances
- customer and supplier statements reconcile with accounting activity
- aging reports correctly derive outstanding balances
- cash/bank activity derives from posted journals
- authorization is enforced
- cross-company access is blocked
- validation is covered
- regression tests pass
- Pint passes
- route verification passes
- `PHASE_6_REPORT.md` is created

At completion, provide the exact test and verification results.

Do not claim success based on inspection alone. Only report a requirement as
verified when the implementation or test actually proves it.
