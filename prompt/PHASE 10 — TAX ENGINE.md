# PHASE 10 — TAX ENGINE

## 1. Phase Title

**PHASE 10 — Tax Engine**

---

## 2. Objective

Implement a reusable, company-scoped **Tax Engine** for the Accounting
Application.

The Tax Engine must calculate and classify taxes independently from the
accounting ledger while integrating with the existing accounting/posting
architecture when tax-bearing business transactions create accounting entries.

The implementation must:

- reuse the existing accounting engine;
- reuse the existing `Money` value object and decimal precision;
- reuse existing company context and authorization;
- reuse the existing Chart of Accounts;
- reuse the existing journal and posting infrastructure;
- avoid creating a second accounting engine;
- avoid hard-coding a single country's tax system;
- remain architecture-ready for multiple tax jurisdictions and tax types;
- avoid unnecessary abstractions;
- preserve all existing Phase 1–9 behavior.

This phase is primarily the **Tax Configuration + Tax Calculation Foundation**.

Do not attempt to build a complete country-specific tax filing system in this
phase.

---

# 3. IMPORTANT INSTRUCTIONS FOR OPENCODE

Before writing code:

1. Read the existing project structure.
2. Read the relevant existing accounting documentation.
3. Inspect the actual implementations from previous phases.
4. Inspect:
   - `Account`
   - `Journal`
   - `JournalLine`
   - `LedgerService`
   - `JournalService`
   - `JournalPostingService`
   - `Money`
   - CompanyContext
   - authorization configuration
   - existing invoice/purchase transaction services
   - existing accounting enums
   - existing report services
   - existing factories and test helpers.
5. Do not assume the old blueprint matches the current code.
6. Treat the existing implementation as the source of truth.
7. Do not rewrite working accounting code merely to fit this phase.
8. Reuse existing patterns wherever they already solve the problem.
9. Do not create duplicate concepts.
10. Do not create a generic framework/DSL/configuration engine unless the
    existing code genuinely requires it.

### No Seeder Rule

**Do not run or create seeders unless explicitly requested.**

Do not insert sample tax rates automatically.

---

# 4. Current Architecture That Must Be Preserved

The application currently uses:

- Laravel 13
- PHP 8.4+
- MySQL 8.4 LTS
- REST API
- JWT authentication
- company-scoped architecture
- `Money` value object
- DECIMAL monetary storage
- Chart of Accounts
- journal + journal lines
- journal posting engine
- General Ledger
- financial reports
- cash/bank transactions
- bank reconciliation
- server-side authorization
- audit-related controls
- fiscal/accounting date concepts already present in the project.

The accounting flow remains:

```text
Business Transaction
        ↓
Validation
        ↓
Business Calculation
        ↓
Tax Calculation
        ↓
Accounting Mapping
        ↓
Journal
        ↓
Journal Lines
        ↓
Debit/Credit Validation
        ↓
POST
        ↓
General Ledger
        ↓
Reports
```

The Tax Engine must occupy the **Tax Calculation** layer.

---

# 5. Core Accounting Principle

Do not change the fundamental accounting rule:

> Every posted financial transaction must ultimately produce balanced journal
> entries.

Therefore:

```text
Total Debits = Total Credits
```

The Tax Engine must never bypass the existing journal/posting engine.

---

# 6. Tax Engine Design Principles

The Tax Engine must be:

### 6.1 Separate from accounting

Tax calculation determines:

```text
taxable amount
tax rate
tax amount
tax components
```

Accounting determines:

```text
which accounts are debited/credited
```

Do not combine these responsibilities into one service.

---

### 6.2 International-ready

Do not hard-code:

```text
GST
VAT
CGST
SGST
IGST
Sales Tax
```

as the fundamental architecture.

The engine must support generic tax concepts such as:

```text
Tax
Tax Rate
Tax Type
Tax Component
Tax Rule
Tax Account Mapping
```

Country-specific tax systems can later be represented using these primitives.

---

### 6.3 Company scoped

Every tax configuration must belong to a company.

Never trust a client-provided `company_id`.

Use the existing:

```text
CompanyContext
```

architecture.

---

### 6.4 Money precision

Never use:

```text
float
double
```

for tax calculations.

Use the existing `Money` infrastructure and the application's established
decimal scale.

Do not introduce a different monetary precision system.

---

# 7. Scope of Phase 10

Implement the following:

## A. Tax Master Data

Create:

- Taxes
- Tax Rates
- Tax Account Mappings

---

## B. Tax Calculation Engine

Support:

- taxable amount
- tax rate
- tax amount
- tax-inclusive calculation
- tax-exclusive calculation
- multiple tax components
- rounding
- exact monetary calculations.

---

## C. Tax Rules

Provide a simple reusable mechanism to determine whether a tax applies to a
transaction/item.

The design should remain simple.

Do not create a generic rules programming language.

---

## D. Accounting Mapping

Allow taxes to map to appropriate Chart of Accounts accounts.

Examples:

```text
Output Tax
    ↓
Tax Liability Account
```

```text
Input Tax
    ↓
Tax Recoverable / Tax Asset Account
```

The exact account types must follow the existing Chart of Accounts architecture.

---

## E. Transaction Integration Foundation

The Tax Engine should expose a clean service API that future Sales Invoice and
Purchase Bill flows can use.

Do not unnecessarily rewrite existing transaction flows if they already work.

If existing invoice/purchase posting requires minimal integration to support
tax, make only the required changes.

---

## F. Tax Reporting Foundation

Provide read-only tax summaries sufficient for:

- tax collected
- tax paid/recoverable
- net tax position
- tax by tax code
- date range filtering.

Do not build government filing/export formats in this phase.

---

# 8. Database Design

Before creating migrations, inspect the existing schema and naming conventions.

Use company-scoped tables.

Suggested conceptual structure:

## `taxes`

Fields should conceptually support:

```text
id
company_id
code
name
description
tax_type
calculation_method
is_active
timestamps
```

Do not blindly copy these fields. Adjust them to match the existing project
conventions.

---

## `tax_rates`

Conceptually:

```text
id
company_id
tax_id
rate
effective_from
effective_to
is_active
timestamps
```

Requirements:

- exact decimal rate storage;
- no FLOAT/DOUBLE;
- support future rate changes;
- do not overwrite historical tax configuration blindly.

---

## `tax_account_mappings`

Conceptually:

```text
id
company_id
tax_id
input_account_id
output_account_id
timestamps
```

The mapping must reference existing Chart of Accounts accounts.

Validate:

- company ownership;
- active account;
- correct account suitability;
- no cross-company references.

If the existing account model already has a better mapping structure, reuse it.

---

# 9. Tax Type

The architecture should support generic tax classification.

Possible conceptual values:

```text
SALES
PURCHASE
BOTH
```

Do not assume these are necessarily the final enum values.

Inspect existing project conventions first.

The important requirement is that the engine can distinguish:

```text
output/sales tax
```

from:

```text
input/purchase tax
```

---

# 10. Calculation Method

The engine must support at least:

### Tax Exclusive

Example:

```text
Net Amount = 100.00
Tax Rate = 10%

Tax = 10.00
Gross = 110.00
```

---

### Tax Inclusive

Example:

```text
Gross Amount = 110.00
Tax Rate = 10%

Net = 100.00
Tax = 10.00
```

Use exact decimal/Money arithmetic.

Do not use floating-point arithmetic.

---

# 11. Multiple Tax Components

The engine must support multiple tax components on a taxable amount.

Example:

```text
Tax A = 5%
Tax B = 3%
```

The result should clearly expose:

```text
taxable_amount
tax_components
total_tax
gross_amount
```

The calculation order must be deterministic.

Do not introduce complex cascading tax behavior unless existing requirements
require it.

If cascading tax is not supported, document it explicitly rather than silently
implementing a potentially incorrect interpretation.

---

# 12. Rounding

Tax rounding must be explicit and deterministic.

Inspect the application's existing decimal/Money conventions first.

Define:

- calculation precision;
- final tax rounding precision;
- rounding mode;
- where rounding occurs.

Do not allow different services to independently round tax amounts.

The Tax Engine must own tax calculation rounding.

---

# 13. Tax Calculation Result

Create a clear result structure/value object/service result.

Conceptually:

```text
TaxCalculationResult
```

should expose:

```text
taxable_amount
tax_components[]
total_tax
gross_amount
```

Each component should expose enough information to identify:

```text
tax id
tax code
tax name
rate
taxable amount
tax amount
account mapping information where appropriate
```

Do not expose internal database models unnecessarily.

---

# 14. Tax Calculation Service

Create a focused service such as:

```text
TaxCalculationService
```

or follow the existing project naming pattern.

It should be responsible only for tax calculation.

It should not:

- create journals;
- post journals;
- mutate invoices;
- mutate purchase bills;
- write ledger balances;
- create arbitrary accounting transactions.

---

# 15. Tax Rule Resolution

Create a simple rule-resolution layer if required.

Conceptually:

```text
TaxRuleResolver
```

It determines applicable taxes based on approved inputs.

Potential inputs may include:

```text
transaction type
tax id
item/product
customer/supplier
date
```

Do not add complex geographic/jurisdiction logic unless supported by current
requirements.

The architecture should allow such rules later.

---

# 16. Tax Account Mapping

Tax mapping must reuse existing accounts.

For output tax:

```text
Tax → Liability Account
```

For recoverable/input tax:

```text
Tax → Asset Account
```

Do not create automatic accounts unless the existing accounting architecture
explicitly supports controlled system-account creation.

Do not create a second account hierarchy.

---

# 17. Accounting Integration

The Tax Engine must integrate with the existing posting architecture without
replacing it.

For a sales transaction conceptually:

```text
Dr Accounts Receivable       110
    Cr Sales Revenue         100
    Cr Output Tax             10
```

For a purchase transaction conceptually:

```text
Dr Purchase/Expense          100
Dr Input Tax                  10
    Cr Accounts Payable      110
```

These are examples of accounting mapping only.

Do not implement country-specific tax accounting assumptions beyond the
configured tax type/account mapping.

---

# 18. Existing Transaction Integration

Inspect the current implementations of:

- Sales Invoice
- Sales Invoice Items
- Customer Receipts
- Purchase Bill
- Purchase Bill Items
- Supplier Payments.

Determine whether tax support can be introduced without disrupting existing
behavior.

### Important

Do not automatically add tax fields to every existing transaction table.

First inspect whether the current architecture supports:

```text
invoice-level tax
line-level tax
```

and determine the smallest clean schema change.

If tax is added to invoices/items, document why.

---

# 19. Tax Snapshot / Historical Integrity

Once a financial document is issued/posted, its historical tax result must not
change merely because a tax rate configuration changes later.

Therefore, inspect the existing transaction architecture and implement an
appropriate snapshot strategy.

Possible approach:

```text
invoice item
    ↓
tax calculation
    ↓
persist applied tax/rate/amount
```

The exact schema should follow the existing document architecture.

Do not rely solely on re-reading the current tax rate for historical posted
transactions.

This requirement is critical.

---

# 20. Posted Transaction Immutability

Do not allow tax configuration changes to mutate previously posted financial
transactions.

For example:

```text
Tax Rate = 10%
Invoice posted
Later tax rate changes to 12%
```

The historical invoice must remain based on its original tax calculation.

Corrections must use the existing accounting correction/reversal mechanisms.

---

# 21. Tax Configuration Lifecycle

Tax master data should support safe lifecycle behavior.

At minimum:

```text
Active
Inactive
```

Prefer deactivation over destructive deletion when historical references exist.

If deletion is blocked, return a useful API error explaining why.

Follow the same safe-deletion conventions already used elsewhere in the
application.

---

# 22. API Design

Follow the existing API conventions.

Potential endpoints:

### Taxes

```http
GET    /api/accounting/taxes
POST   /api/accounting/taxes
GET    /api/accounting/taxes/{tax}
PUT    /api/accounting/taxes/{tax}
DELETE /api/accounting/taxes/{tax}
```

### Tax Rates

```http
GET    /api/accounting/taxes/{tax}/rates
POST   /api/accounting/taxes/{tax}/rates
PUT    /api/accounting/taxes/{tax}/rates/{rate}
DELETE /api/accounting/taxes/{tax}/rates/{rate}
```

### Tax Mapping

```http
GET /api/accounting/taxes/{tax}/account-mapping
PUT /api/accounting/taxes/{tax}/account-mapping
```

### Calculation

If a calculation endpoint is useful:

```http
POST /api/accounting/tax/calculate
```

This endpoint must be treated as a calculation operation, not an accounting
write.

Do not persist anything merely because the calculation endpoint was called.

### Tax Reports

Potentially:

```http
GET /api/accounting/tax-reports/summary
GET /api/accounting/tax-reports/by-tax
```

Follow existing report naming conventions if the current application has a
better pattern.

---

# 23. API Security

Never accept:

```text
company_id
```

from the client as the authority for tenancy.

Use:

```text
CompanyContext
```

and server-side scoping.

Validate all referenced:

- tax IDs;
- tax rate IDs;
- account IDs;
- transaction IDs.

All references must belong to the active company.

---

# 24. Permissions

Add narrowly scoped permissions.

Suggested:

```text
accounting.tax.view
accounting.tax.create
accounting.tax.update
accounting.tax.delete
accounting.tax.calculate
accounting.tax.report.view
```

Do not automatically use wildcard permissions for normal roles.

Follow the existing role permission architecture.

Expected baseline:

### Admin

Full tax access.

### Accountant

Tax configuration, calculation and reporting.

### Manager

Read/report access according to existing project authorization conventions;
configuration write access should be explicitly justified.

### Staff

No tax configuration access unless the existing role design requires limited
calculation access.

Do not silently modify unrelated permissions.

---

# 25. Authorization

Authorization must be enforced server-side.

Use the existing combination of:

- middleware;
- request authorization;
- policies/gates;
- company context.

Do not rely on frontend permissions.

Do not assume that hiding a button is security.

---

# 26. Tax Reports

Implement a small read-only reporting layer.

At minimum support:

## Tax Summary

For a date range:

```text
taxable amount
tax amount
```

grouped by tax.

---

## Output Tax

Show tax collected from sales transactions.

---

## Input Tax

Show recoverable/input tax from purchases where supported.

---

## Net Tax Position

Conceptually:

```text
Output Tax - Input Tax
```

Do not treat this as a government filing liability calculation unless the
configured accounting model explicitly supports it.

Clearly distinguish:

```text
accounting tax position
```

from:

```text
official tax filing liability
```

---

# 27. Report Source of Truth

Tax reports must derive their figures from persisted transactional/accounting
data.

Do not introduce:

```text
stored tax balance
stored running tax total
tax snapshot ledger
```

unless there is a proven requirement.

Do not maintain a second tax ledger.

---

# 28. Existing Reporting Integration

Inspect Phase 6 reporting architecture.

If tax reports can reuse existing report infrastructure, do so.

Do not create a duplicate reporting framework.

The existing accounting reports must continue working unchanged.

---

# 29. Journal Integrity

If tax integration creates or modifies journal mappings:

Every journal must satisfy:

```text
SUM(debits) = SUM(credits)
```

Tax must never create an unbalanced journal.

Do not bypass:

```text
JournalService
JournalPostingService
LedgerService
```

where those are the established posting paths.

---

# 30. Fiscal Period Rules

Inspect the existing fiscal period/period locking implementation.

Tax-bearing accounting transactions must respect existing:

```text
Open
Closed
Locked
```

rules.

The Tax Engine must not provide a way to post into a closed/locked period.

---

# 31. Multi-Currency Readiness

Do not build full multi-currency tax support in this phase unless existing
transaction infrastructure already requires it.

However, do not design the Tax Engine in a way that prevents future:

```text
transaction currency
base currency
exchange rate
```

support.

Do not introduce hard-coded currency assumptions.

---

# 32. Auditability

Tax configuration changes should be compatible with the existing audit
architecture.

At minimum, identify:

- who created tax configuration;
- who changed tax configuration;
- when it changed.

Do not create a second unrelated audit system.

Reuse the existing audit mechanism if already available.

---

# 33. Database Integrity

Use appropriate:

- foreign keys;
- indexes;
- unique constraints;
- company scoping;
- active/effective-date constraints where appropriate.

Think carefully about uniqueness.

For example, a tax code should generally be unique within a company, not
globally.

Do not create overly broad global unique constraints.

---

# 34. Performance

Avoid:

```text
N+1 tax queries
```

especially when calculating multiple invoice lines.

Prefer:

- eager loading;
- bulk loading of tax configurations;
- in-memory reuse of immutable configuration during a calculation.

Do not introduce caching unless profiling demonstrates a need.

---

# 35. Testing Requirements

Create comprehensive tests.

At minimum test:

## Tax Configuration

- create tax;
- update tax;
- deactivate tax;
- duplicate code protection;
- company isolation;
- authorization;
- safe deletion.

## Tax Rates

- create rate;
- effective date validation;
- rate precision;
- duplicate/overlapping configuration handling;
- inactive rate behavior;
- company isolation.

## Calculation

Test:

- tax-exclusive calculation;
- tax-inclusive calculation;
- zero tax;
- decimal values;
- rounding;
- multiple tax components;
- exact Money results;
- invalid/inactive tax;
- date-sensitive rate resolution.

## Accounting Mapping

Test:

- valid output tax account;
- valid input tax account;
- wrong company account;
- inactive account;
- unsuitable account type if enforced.

## Historical Integrity

Critical tests:

1. Calculate/post using 10%.
2. Change the configured tax rate to 12%.
3. Verify the historical transaction still reflects 10%.

## Journal Integrity

If transaction integration is included:

```text
total debit = total credit
```

must always hold.

## Authorization

Test all relevant roles.

## Company Isolation

Create Company A and Company B.

Verify:

```text
Company A cannot access Company B tax configuration.
Company A cannot use Company B tax rate.
Company A cannot use Company B tax account mapping.
```

## Security

Test:

- tampered IDs;
- cross-company IDs;
- unauthorized calculation;
- unauthorized configuration changes;
- invalid tax references.

---

# 36. Regression Testing

Do not stop after new tests pass.

Run the complete application test suite.

The previous Phase 9 report only explicitly documented four reconciliation
feature tests and Pint. Do not assume the full suite has already been verified
after Phase 9.

Therefore this phase must establish the actual current baseline and report:

```text
Before Phase 10
After Phase 10
```

where possible.

The full suite must pass before declaring the phase complete.

---

# 37. Code Quality

Run:

```bash
./vendor/bin/pint --test
```

and fix only relevant issues.

Check syntax for changed PHP files.

Verify:

```bash
php artisan route:list
```

for all new routes.

Verify migrations.

Verify database constraints.

---

# 38. Migration Verification

If migrations are added:

1. Run migrations against the test database.
2. Verify successful creation.
3. Run the test suite.
4. Verify rollback where practical.
5. Verify migration can be reapplied cleanly.

Do not modify historical migrations that are already part of the established
project unless absolutely necessary.

Prefer new migrations.

---

# 39. Documentation

Update only documentation that is genuinely required.

Create:

```text
docs/reports/PHASE_10_REPORT.md
```

The report must document:

- objectives;
- implementation;
- migrations;
- models;
- enums;
- services;
- API endpoints;
- permissions;
- authorization;
- tax calculation rules;
- rounding rules;
- historical snapshot behavior;
- accounting integration;
- tests;
- commands executed;
- security checks;
- known limitations;
- deferred work;
- final status.

---

# 40. Explicit Non-Goals

Do NOT implement in Phase 10:

- government tax filing;
- GST return filing;
- VAT return filing;
- tax authority API integration;
- e-invoicing;
- e-way bills;
- country-specific compliance reports;
- tax payment workflows;
- tax refund workflows;
- payroll taxes;
- withholding tax unless already required by existing architecture;
- fixed asset tax depreciation;
- deferred tax accounting;
- tax audit portal;
- PDF tax filing exports;
- Excel tax filing templates;
- frontend;
- dashboard tax widgets.

These can be future phases.

---

# 41. Frontend

Do not implement the Next.js frontend in this phase.

The backend API must be complete and documented first.

The future frontend will consume:

```text
Tax APIs
Tax Calculation API
Tax Reports API
```

Do not create frontend files.

---

# 42. No Unnecessary Refactoring

This is critical.

Do not:

- rewrite the accounting engine;
- rename existing accounting tables;
- replace the Money class;
- replace JournalService;
- replace JournalPostingService;
- replace LedgerService;
- redesign CompanyContext;
- redesign authorization;
- redesign Phase 7 Cash & Banking;
- redesign Phase 9 Bank Reconciliation.

Only change existing code where Phase 10 genuinely requires integration.

---

# 43. Expected Service Structure

Use the existing project structure.

A reasonable structure may be:

```text
app/
├── Models/
│   ├── Tax.php
│   ├── TaxRate.php
│   └── TaxAccountMapping.php
│
├── Services/
│   └── Accounting/
│       └── Tax/
│           ├── TaxService.php
│           ├── TaxRateService.php
│           ├── TaxCalculationService.php
│           ├── TaxRuleResolver.php
│           ├── TaxAccountMappingService.php
│           └── TaxReportService.php
```

This is only a suggested structure.

Follow the project's existing organization if it already has an appropriate
pattern.

Do not create services simply to satisfy this suggested list.

---

# 44. Required Engineering Checks Before Completion

Before declaring Phase 10 complete, verify:

### Architecture

- [ ] Existing accounting engine reused
- [ ] No second accounting engine
- [ ] Tax Engine separated from ledger
- [ ] CompanyContext reused
- [ ] Money reused
- [ ] Existing authorization reused

### Tax Integrity

- [ ] Exact decimal calculations
- [ ] Explicit rounding
- [ ] Inclusive calculation
- [ ] Exclusive calculation
- [ ] Multiple tax components
- [ ] Historical tax result preserved
- [ ] Inactive tax cannot be incorrectly applied

### Accounting

- [ ] Tax accounts belong to active company
- [ ] Tax mappings use existing Chart of Accounts
- [ ] Journals remain balanced
- [ ] Existing posting engine remains source of truth
- [ ] Closed/locked periods respected

### Security

- [ ] No client-controlled company_id
- [ ] Cross-company access blocked
- [ ] Authorization enforced server-side
- [ ] Tampered tax/rate/account IDs rejected
- [ ] Safe deletion/deactivation implemented

### Quality

- [ ] New tests pass
- [ ] Full test suite passes
- [ ] Pint passes
- [ ] Routes verified
- [ ] Migrations verified
- [ ] No seeders executed
- [ ] No frontend created
- [ ] No unrelated files modified

---

# 45. Hard Stop Conditions

Stop and report instead of guessing if:

- existing invoice/purchase architecture conflicts with the planned tax
  integration;
- the existing Money implementation cannot safely support required calculations;
- account-type semantics are unclear;
- fiscal-period behavior is unclear;
- tax historical snapshot requirements cannot be satisfied cleanly;
- an existing service appears to already provide the required functionality;
- implementing tax requires a major redesign of existing accounting code;
- requirements imply country-specific legal/compliance behavior not defined by
  this project.

Do not silently invent accounting or tax rules.

---

# 46. Final Verification Commands

Run appropriate project commands, including:

```bash
php artisan test
```

```bash
./vendor/bin/pint --test
```

```bash
php artisan route:list --path=accounting
```

and any relevant focused test commands.

Also verify:

```bash
php artisan migrate:status
```

and migration behavior where applicable.

Do not run seeders.

---

# 47. Final Report

Create:

```text
docs/reports/PHASE_10_REPORT.md
```

Include:

## 1. Phase Objectives

## 2. Existing Architecture Reviewed

## 3. Database Changes

## 4. Models

## 5. Enums

## 6. Services

## 7. Tax Calculation Rules

## 8. Rounding Rules

## 9. Historical Tax Integrity

## 10. Accounting Integration

## 11. API Endpoints

## 12. Permissions

## 13. Authorization

## 14. Company Isolation

## 15. Tests

## 16. Commands Executed

## 17. Security Verification

## 18. Performance Considerations

## 19. Known Limitations

## 20. Deferred Features

## 21. Requirements Traceability

## 22. Final Status

Use one of:

```text
PASS
PASS WITH NOTES
BLOCKED
```

Do not claim PASS unless the required verification has actually been performed.

---

# 48. Final Requirement

The result must be a **simple, maintainable, accounting-safe Tax Engine** that
fits the existing Laravel 13 accounting application.

The implementation must prefer:

```text
simple architecture
+
existing infrastructure
+
exact money calculations
+
strong company isolation
+
historical integrity
+
server-side authorization
+
comprehensive tests
```

over unnecessary abstraction.

Do not hurry.

Inspect first.

Implement second.

Test thoroughly.

Do not break working accounting functionality.

Do not create seeders without explicit permission.

Do not create the frontend.

Do not implement future tax-compliance modules prematurely.

When complete, produce the required:

```text
docs/reports/PHASE_10_REPORT.md
```

and stop.
