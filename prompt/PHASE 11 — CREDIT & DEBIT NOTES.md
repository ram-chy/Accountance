# PHASE 11 — CREDIT & DEBIT NOTES

## 1. Phase Title

**PHASE 11 — Credit & Debit Notes**

---

# 2. Objective

Implement a complete **Credit Note and Debit Note module** for the existing
Laravel 13 accounting backend.

The module must provide a controlled accounting mechanism for correcting or
adjusting already-issued sales invoices and purchase bills without mutating the
original posted financial transaction.

The implementation must integrate with:

- existing Sales Invoice architecture;
- existing Purchase Bill architecture;
- existing Customer/Supplier architecture;
- existing Tax Engine from Phase 10;
- existing Chart of Accounts;
- existing Journal/JournalPosting engine;
- existing Ledger;
- existing Settlement/payment allocation system;
- existing fiscal-period controls;
- existing company isolation;
- existing authorization;
- existing Money implementation;
- existing reporting architecture.

The fundamental rule is:

> **A posted invoice or bill is never edited to correct its financial meaning. A
> Credit Note or Debit Note creates a separate accounting transaction that
> adjusts the original document.**

---

# 3. CURRENT SYSTEM STATE

Before implementation, inspect the actual repository.

Do not assume the original roadmap reflects the current code.

The Phase 10 Tax Engine is already implemented and verified with:

- effective-dated tax rates;
- tax calculation;
- tax account mappings;
- tax snapshots;
- tax-aware invoice/purchase integration;
- tax reports;
- company isolation;
- authorization;
- historical tax protection.

The Phase 10 report records:

```text
799 tests
3647 assertions
0 failures
0 errors
Pint clean
```

and final status:

```text
PASS WITH NOTES
```

The notes include:

- inclusive tax is available through calculation but deliberately refused on
  documents;
- multi-tax document lines cannot attribute themselves to a single `tax_id`;
- Phase 10 also fixed a Phase 9 bank reconciliation scoping defect.

Treat these as current system behavior.

Do not redesign them during Phase 11.

---

# 4. IMPORTANT OPENCODE INSTRUCTIONS

Before changing code:

1. Read the current repository.
2. Read the existing accounting documentation.
3. Read the actual Phase 5 transaction implementation.
4. Read the Phase 6 reporting implementation.
5. Read the Phase 7 Cash & Banking implementation.
6. Read the Phase 8 fiscal-period implementation.
7. Read the Phase 9 Bank Reconciliation implementation.
8. Read the Phase 10 Tax Engine implementation and report.
9. Inspect:
   - SalesInvoice model/service/controller;
   - SalesInvoiceLine;
   - PurchaseBill model/service/controller;
   - PurchaseBillLine;
   - CustomerReceipt/payment allocation services;
   - SupplierPayment/payment allocation services;
   - SettlementService;
   - DocumentCalculator;
   - TaxCalculationService;
   - TaxRuleResolver;
   - JournalService;
   - JournalPostingService;
   - LedgerService;
   - account resolver services;
   - fiscal-period services;
   - company context;
   - authorization configuration;
   - document-number infrastructure;
   - existing enums;
   - existing report services;
   - factories and test helpers.

10. Do not rewrite working code merely to fit this phase.
11. Reuse existing accounting infrastructure.
12. Do not create a second accounting engine.
13. Do not create a second tax engine.
14. Do not introduce a generic document framework unless existing code clearly
    requires it.
15. Do not run seeders.
16. Do not create frontend code.

---

# 5. Core Principle

Credit/Debit Notes are **financial documents**, not ledger adjustments entered
directly by users.

The flow must remain:

```text
Credit/Debit Note
        ↓
Validation
        ↓
Business Calculation
        ↓
Tax Calculation / Tax Snapshot
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
Settlement / Statements / Reports
```

No endpoint may directly insert journal lines.

---

# 6. Document Types

Support four distinct document types.

## Sales

### Sales Credit Note

Reduces the amount owed by a customer.

Example:

Original invoice:

```text
Dr Accounts Receivable 110
    Cr Revenue          100
    Cr Output Tax        10
```

Credit note:

```text
Dr Revenue              100
Dr Output Tax            10
    Cr Accounts Receivable 110
```

---

### Sales Debit Note

Increases the amount owed by a customer.

Example:

```text
Dr Accounts Receivable 110
    Cr Revenue          100
    Cr Output Tax        10
```

---

## Purchase

### Purchase Credit Note

Reduces the amount owed to a supplier.

Conceptually:

```text
Dr Accounts Payable
    Cr Purchase/Expense
    Cr Input Tax
```

---

### Purchase Debit Note

Increases the amount owed to a supplier.

Conceptually:

```text
Dr Purchase/Expense
Dr Input Tax
    Cr Accounts Payable
```

These are accounting examples.

The exact account mapping must reuse the existing invoice/bill posting
architecture.

---

# 7. Do Not Mutate Original Documents

This is a hard requirement.

A posted:

```text
Sales Invoice
Purchase Bill
```

must remain historically intact.

Do not:

- change its total;
- change its tax;
- change its line amount;
- rewrite its journal;
- delete its journal;
- alter its tax snapshot;
- alter its original customer/supplier;
- recalculate its historical values because a current configuration changed.

The adjustment must be represented by a separate Credit/Debit Note.

---

# 8. Relationship to Original Document

Every note must reference the document it adjusts.

Conceptually:

```text
credit/debit note
    ↓
original sales invoice
```

or:

```text
credit/debit note
    ↓
original purchase bill
```

The relationship must be company scoped.

A note from Company A must never be allowed to reference a document belonging to
Company B.

---

# 9. Adjustment Limits

The system must prevent over-adjustment.

For example:

```text
Original Invoice = 1,000
Existing Credit Notes = 700
```

The remaining adjustable amount is:

```text
300
```

A new credit note for:

```text
400
```

must be rejected.

The calculation must consider all relevant existing posted adjustments against
the original document.

Do not rely on client-provided remaining balance.

Calculate it server-side.

---

# 10. Full vs Partial Adjustment

Support:

- partial credit/debit note;
- full adjustment where valid.

The note must clearly record its own amount.

Do not copy the entire invoice and assume it is automatically a full adjustment.

---

# 11. Document Lifecycle

Follow the existing document lifecycle conventions.

At minimum:

```text
DRAFT
    ↓
ISSUED
    ↓
POSTED
```

If the existing application uses a different document status model, inspect and
reuse it.

Do not invent a second lifecycle enum if the existing transaction architecture
already has an appropriate one.

Only draft notes may be edited or deleted.

Once posted:

```text
POSTED
```

the note is immutable.

Corrections require another accounting document/process.

---

# 12. Cancellation

Do not introduce arbitrary deletion of posted notes.

If cancellation is already supported by the existing financial-document
architecture, integrate with it.

Otherwise, do not invent a new cancellation accounting mechanism in Phase 11.

A posted note must never disappear through:

```text
DELETE
```

---

# 13. Database Design

Inspect existing invoice/bill schema and naming conventions first.

Create additive migrations only.

Do not modify existing historical migrations unnecessarily.

Conceptually, the module may require:

```text
credit_debit_notes
credit_debit_note_lines
```

or an equivalent structure if the existing architecture already has a suitable
document abstraction.

---

# 14. Note Header

The header should conceptually support:

```text
id
company_id
note_number
note_type
source_document_type
source_document_id
customer_id / supplier_id
note_date
status
subtotal
discount
tax_total
grand_total
reason
reference
journal_id
created_by
posted_by
posted_at
timestamps
```

Do not blindly implement every field.

Inspect the existing invoice/bill fields and reuse established conventions.

---

# 15. Note Line

A line should conceptually support:

```text
id
note_id
source_line_id
description
quantity
unit_price
discount
tax_id
tax_rate
tax_amount
line_subtotal
line_total
timestamps
```

Again, inspect existing document line structure first.

The key requirement is that the note stores its own historical calculation.

---

# 16. Tax Integration

Phase 10 Tax Engine must be the source of tax calculation.

Do not recreate:

```text
tax = amount × rate
```

inside Credit/Debit Note services.

Reuse:

```text
TaxCalculationService
TaxRuleResolver
DocumentCalculator
```

where appropriate.

---

# 17. Tax Snapshot

Every posted note must preserve its tax calculation.

The note must not later recalculate its historical tax from current tax
configuration.

For example:

```text
Note created at 10%
↓
Posted
↓
Tax rate changed to 12%
```

The posted note remains 10%.

This follows the Phase 10 historical tax integrity model.

---

# 18. Tax Type

The note must use the tax side appropriate to its source document.

Sales:

```text
OUTPUT
```

Purchase:

```text
INPUT
```

Do not allow a client to arbitrarily attach an incompatible tax type.

Use the existing `TaxType` semantics.

---

# 19. Tax Calculation Basis

Follow the existing Phase 10 document behavior.

Important:

> **Do not introduce inclusive tax into posted credit/debit notes.**

Phase 10 deliberately restricts documents to exclusive tax because existing
document totals and journal semantics are based on:

```text
line_total = net + tax
```

Inclusive tax remains available only through the calculation endpoint.

Credit/Debit Notes must preserve that established behavior unless a future phase
deliberately redesigns document semantics.

---

# 20. Multiple Taxes

Respect the existing Phase 10 limitation.

If the current document architecture cannot attribute multiple taxes to one
`tax_id`, do not silently invent a new tax attribution system.

Inspect how Phase 10 currently handles:

```text
tax_ids
tax_rate
tax_amount
tax_id = null
```

and preserve the same historical/reporting semantics.

If the existing document calculation already supports the necessary structure,
reuse it.

---

# 21. Source Line Relationship

Where possible, a note line should identify the original invoice/bill line it
adjusts.

This allows:

- traceability;
- validation;
- partial adjustments;
- quantity limits;
- historical context.

However, do not assume every adjustment must reference an individual line if the
existing business model allows document-level adjustments.

Support the simplest safe model compatible with the existing invoice/bill
architecture.

---

# 22. Quantity Adjustment

If the existing invoice/bill has quantity-based lines, inspect whether
Credit/Debit Notes should support quantity adjustments.

If implemented:

```text
adjusted_quantity <= remaining_adjustable_quantity
```

must be enforced server-side.

Never trust the client.

If quantity adjustment is not appropriate for an existing document type,
document the limitation instead of inventing behavior.

---

# 23. Amount Adjustment

The system must calculate:

```text
subtotal
discount
tax
grand_total
```

using the same monetary conventions as the original document.

Do not accept client-calculated totals as authoritative.

The client may submit input values, but the server must calculate the financial
result.

---

# 24. Accounting Mapping

Reuse the existing document posting architecture.

Do not create:

```text
CreditNotePostingEngine
DebitNotePostingEngine
```

if the existing posting service can be extended cleanly.

The goal is:

```text
Document calculation
        ↓
Accounting mapping
        ↓
Existing JournalService
        ↓
Existing JournalPostingService
```

---

# 25. Journal Source

Inspect the existing `JournalSource` enum and document source conventions.

Add dedicated source values only if required.

For example:

```text
SALES_CREDIT_NOTE
SALES_DEBIT_NOTE
PURCHASE_CREDIT_NOTE
PURCHASE_DEBIT_NOTE
```

or a generic note source with the note type preserved elsewhere.

Choose the smallest design that remains traceable.

---

# 26. Journal Traceability

A posted note must be traceable to its journal.

The journal must be traceable back to:

```text
company
note
source document
```

Do not create a separate ledger.

---

# 27. Journal Balance

Every note posting must satisfy:

```text
Total Debits = Total Credits
```

Test this explicitly.

Examples:

### Sales Credit

```text
Dr Revenue
Dr Output Tax
    Cr Accounts Receivable
```

### Sales Debit

```text
Dr Accounts Receivable
    Cr Revenue
    Cr Output Tax
```

### Purchase Credit

```text
Dr Accounts Payable
    Cr Purchase/Expense
    Cr Input Tax
```

### Purchase Debit

```text
Dr Purchase/Expense
Dr Input Tax
    Cr Accounts Payable
```

The actual account resolution must follow existing transaction logic.

---

# 28. Customer/Supplier Settlement

Credit/Debit Notes must affect outstanding balances through accounting.

Do not create a second customer/supplier balance field.

The existing settlement architecture must remain the source of truth.

Inspect:

```text
SettlementService
customer receipts
supplier payments
allocation logic
```

and ensure the note's accounting effect is reflected correctly.

---

# 29. Already Paid Documents

Consider cases where the original invoice/bill has already been fully paid.

A credit note may still reduce the customer's net receivable, but this must not
automatically become a payment/refund.

Do not implement automatic refunds in Phase 11.

The system should represent the accounting adjustment and resulting
customer/supplier balance.

If the balance becomes credit/overpayment, expose it through existing
settlement/reporting semantics rather than inventing a refund workflow.

---

# 30. Payment Allocation

Do not silently rewrite existing payment allocations when a note is posted.

Existing allocations must remain historically correct.

If a note creates an outstanding credit requiring future settlement behavior,
document the resulting state.

Do not build a new payment allocation engine.

---

# 31. Fiscal Period

Use the existing fiscal-period controls.

A note's posting date must respect:

```text
OPEN
CLOSED
LOCKED
```

period rules already implemented in the application.

Do not create a separate period validator.

---

# 32. Document Numbering

Reuse the existing document number sequence infrastructure.

Do not generate numbers with:

```text
random strings
UUIDs as human-facing document numbers
MAX(id)+1
```

Use the established company-scoped document numbering mechanism.

Choose clear prefixes only after inspecting existing numbering conventions.

For example, a possible convention could be:

```text
SCN-
SDN-
PCN-
PDN-
```

but do not implement these exact prefixes without checking the existing project
conventions.

---

# 33. API Design

Follow existing REST conventions.

Potential endpoints:

```http
GET    /api/accounting/credit-debit-notes
POST   /api/accounting/credit-debit-notes
GET    /api/accounting/credit-debit-notes/{note}
PUT    /api/accounting/credit-debit-notes/{note}
DELETE /api/accounting/credit-debit-notes/{note}
POST   /api/accounting/credit-debit-notes/{note}/post
```

If the existing application prefers separate resource routes, use the existing
convention.

Filtering should support relevant dimensions such as:

```text
type
status
customer_id
supplier_id
source_document_id
from_date
to_date
```

Do not expose `company_id` as a filter.

---

# 34. Source Document Endpoints

Provide safe server-side ways to obtain adjustable source documents/lines if
needed by the frontend.

For example:

```http
GET /api/accounting/sales-invoices/{invoice}/adjustable-lines
GET /api/accounting/purchase-bills/{bill}/adjustable-lines
```

Only add these if they are necessary.

Do not create duplicate invoice/bill querying APIs unnecessarily.

---

# 35. Authorization

Add narrowly scoped permissions.

Potential permissions:

```text
accounting.credit_debit_note.view
accounting.credit_debit_note.create
accounting.credit_debit_note.update
accounting.credit_debit_note.delete
accounting.credit_debit_note.post
```

Follow existing permission naming conventions.

Do not modify unrelated role permissions.

Expected baseline:

### Admin

Full access.

### Accountant

Full operational access.

### Manager

Follow the project's existing financial-document policy. Do not automatically
grant posting if the established authorization model distinguishes preparation
from posting.

### Staff

No access unless existing business requirements explicitly require it.

---

# 36. Server-Side Authorization

Enforce authorization through:

- Form Requests;
- Policies;
- Controllers;
- company-scoped route bindings where appropriate.

Do not depend on frontend visibility.

Register policies for every new model.

Do not repeat the Phase 10 mistake of leaving a model unregistered so it falls
through to an allow path.

---

# 37. Company Isolation

No endpoint may accept client-controlled:

```text
company_id
```

Company must come from:

```text
CompanyContext
```

All source documents, customers, suppliers, tax records and accounts must belong
to the active company.

Cross-company references must fail safely.

Prefer the existing behavior of returning a resource-not-found response where
appropriate rather than disclosing another tenant's records.

---

# 38. Safe Deletion

Draft notes may be deleted according to existing document rules.

Posted notes must not be hard deleted.

If a source document is already referenced by a posted note, prevent destructive
deletion of the source document if the existing invoice/bill deletion rules do
not already handle this.

Reuse existing deletion guards.

---

# 39. Reporting Integration

Update existing reporting only where necessary.

At minimum inspect:

- Customer Statement
- Supplier Statement
- Receivables
- Payables
- Aging reports
- Profit & Loss
- Balance Sheet
- Tax reports

Credit/Debit Notes must be reflected correctly in accounting-derived reports.

Do not build duplicate balance calculations.

---

# 40. Tax Report Integration

Phase 10 tax reports are sourced from posted document-line tax snapshots.

Phase 11 notes must be included consistently.

A posted sales credit note should reduce output tax.

A posted sales debit note should increase output tax.

A purchase credit note should reduce input tax.

A purchase debit note should increase input tax.

Do not change the Phase 10 reporting source-of-truth model.

---

# 41. Customer Statement

Customer statements must show the financial effect of notes.

Conceptually:

```text
Invoice       +110 receivable
Receipt       -110 receivable
Credit Note   -20 receivable
Debit Note    +20 receivable
```

Use the existing statement conventions.

Do not maintain a stored customer balance.

---

# 42. Supplier Statement

Supplier statements must similarly reflect:

```text
Purchase Bill
Supplier Payment
Purchase Credit Note
Purchase Debit Note
```

using the existing payable-positive conventions.

---

# 43. Receivables / Payables

Existing reports must derive balances from accounting/document data.

Do not introduce:

```text
stored outstanding_after_credit_note
stored customer_balance
stored supplier_balance
```

---

# 44. Profit & Loss

Notes must correctly affect revenue/expense balances.

Examples:

Sales Credit Note:

```text
reduces revenue
```

Sales Debit Note:

```text
increases revenue
```

Purchase Credit Note:

```text
reduces purchase/expense
```

Purchase Debit Note:

```text
increases purchase/expense
```

Use the existing journal/report normal-balance conventions.

---

# 45. Balance Sheet

The resulting effect on:

- receivables;
- payables;
- tax assets/liabilities;
- equity through retained earnings;

must naturally arise from the posted journal.

Do not add special balance-sheet adjustment logic.

---

# 46. Tax Historical Integrity

Test this explicitly:

```text
Original invoice:
Tax = 10%

Credit Note:
Tax = 10%

Tax configuration later changes to 15%

Existing invoice remains 10%
Existing credit note remains 10%
```

No report should recalculate either historical transaction from the current
rate.

---

# 47. Concurrency

Credit/debit adjustment limits are financial integrity rules.

Use appropriate database locking/transaction boundaries when calculating and
creating/posting adjustments.

Consider concurrent requests such as:

```text
Original invoice remaining adjustment = 100

Request A creates credit note = 70
Request B creates credit note = 50
```

The final posted amount must never exceed the allowed adjustment.

Do not rely only on application-level checks without considering concurrent
transactions.

Add at least one concurrency/integrity test if the project's existing test
environment supports it.

---

# 48. Performance

Avoid N+1 queries when determining:

- source document adjustments;
- adjustable lines;
- tax resolution;
- customer/supplier information.

Prefer:

- eager loading;
- grouped aggregate queries;
- existing settlement/report services;
- batch tax resolution.

Do not add caching prematurely.

---

# 49. API Response Conventions

Reuse:

```text
ApiResponse::success(...)
```

and existing error conventions.

Do not invent a second API response structure.

Validation errors must follow the application's existing Laravel API format.

---

# 50. Resources

Use API Resources only if that matches existing transaction endpoints.

The response should expose enough information for the frontend later, including:

- note number;
- note type;
- status;
- source document;
- customer/supplier;
- dates;
- lines;
- tax;
- totals;
- journal reference where appropriate.

Do not expose internal fields unnecessarily.

---

# 51. Testing Requirements

Create focused tests for:

## Document Creation

- create sales credit note;
- create sales debit note;
- create purchase credit note;
- create purchase debit note;
- validation;
- company isolation;
- authorization.

## Source Document Validation

- source document exists;
- source belongs to company;
- correct source type;
- customer/supplier relationship;
- source is eligible for adjustment;
- source is not an invalid draft;
- source is not from another company.

## Adjustment Limits

Test:

- partial adjustment;
- full adjustment;
- over-adjustment rejection;
- multiple notes against one source;
- exact remaining amount;
- concurrent adjustment integrity where feasible.

## Tax

Test:

- tax calculation;
- tax snapshot;
- tax account;
- inactive tax;
- missing tax account;
- tax-rate change after posting;
- sales tax;
- purchase tax.

## Accounting

Test:

- journal created;
- journal source;
- journal linkage;
- correct debit/credit sides;
- journal balance;
- ledger effect.

## Lifecycle

Test:

- draft update;
- draft delete;
- post;
- posted update rejection;
- posted delete rejection;
- double-post rejection.

## Customer/Supplier

Test:

- customer statement;
- supplier statement;
- receivable effect;
- payable effect.

## Reports

Test:

- P&L;
- Balance Sheet;
- tax reports;
- customer statement;
- supplier statement;
- aging where applicable.

## Security

Test:

- unauthorized role;
- cross-company source document;
- cross-company customer/supplier;
- cross-company tax;
- cross-company account;
- tampered IDs;
- client-supplied company ID if attempted.

---

# 52. Regression Testing

The Phase 10 report established:

```text
799 tests
3647 assertions
0 failures
0 errors
```

before/after Phase 10 verification.

Do not assume this remains true after Phase 11.

Run:

```bash
php artisan test
```

and report the new total.

The entire existing suite must pass before declaring Phase 11 complete.

---

# 53. Migration Verification

If migrations are created:

1. Run migrations.
2. Run the focused Phase 11 tests.
3. Run the full suite.
4. Test rollback.
5. Re-run migrations.
6. Verify foreign keys and indexes.

Do not modify existing migrations unless absolutely necessary.

---

# 54. Code Quality

Run:

```bash
./vendor/bin/pint --test
```

Also run:

```bash
php -l
```

on changed PHP files where useful.

Verify routes:

```bash
php artisan route:list --path=accounting
```

Verify migration state:

```bash
php artisan migrate:status
```

---

# 55. No Seeder Rule

Do not:

- create seeders;
- run seeders;
- insert sample credit notes;
- insert sample debit notes.

All tests must use factories/test fixtures.

---

# 56. No Frontend

Do not create:

- Next.js pages;
- React components;
- Tailwind files;
- frontend API clients.

Phase 11 is backend only.

---

# 57. Explicit Non-Goals

Do NOT implement:

- customer refunds;
- supplier refunds;
- payment reversal workflows;
- payment allocation redesign;
- tax filing;
- GST/VAT returns;
- country-specific compliance;
- e-invoicing;
- e-way bills;
- inventory return engine;
- stock restoration;
- fixed asset adjustments;
- foreign exchange gains/losses;
- multi-currency accounting;
- automated bank reconciliation changes;
- frontend.

These belong to later phases.

---

# 58. Important Inventory Boundary

Do not automatically implement physical inventory return/restoration through
Credit Notes.

A financial Credit Note can exist independently from a physical goods return.

If the existing application has no inventory-return requirement yet, keep Phase
11 purely financial.

Do not invent inventory transactions.

---

# 59. Important Payment Boundary

A Credit/Debit Note changes the financial document position.

It does **not** automatically mean:

```text
refund customer
refund supplier
reverse payment
```

Keep payment workflows separate.

---

# 60. Service Architecture

Follow existing project structure.

A reasonable conceptual structure may be:

```text
app/
├── Models/
│   ├── CreditDebitNote.php
│   └── CreditDebitNoteLine.php
│
├── Services/
│   └── Accounting/
│       └── Notes/
│           ├── CreditDebitNoteService.php
│           ├── CreditDebitNoteCalculator.php
│           ├── CreditDebitNotePostingService.php
│           └── CreditDebitNoteAdjustmentService.php
```

These are suggestions only.

If existing invoice/bill services already contain reusable calculation/posting
logic, extend them carefully rather than creating duplicate services.

---

# 61. Reuse Before Abstraction

Before creating a new service, determine whether the existing:

```text
DocumentCalculator
SettlementService
TaxCalculationService
TaxRuleResolver
JournalService
JournalPostingService
TransactionAccountResolver
LedgerService
DocumentNumberSequence
```

already provides the required behavior.

Prefer:

```text
reuse
```

over:

```text
duplicate
```

---

# 62. No Second Accounting Engine

This is a hard architectural constraint.

Do not implement a note-specific accounting system.

The correct design is:

```text
Note
 ↓
existing calculation/mapping
 ↓
existing JournalService
 ↓
existing JournalPostingService
 ↓
existing Ledger
```

---

# 63. No Stored Balances

Do not add:

```text
customer_balance
supplier_balance
remaining_invoice_balance
remaining_bill_balance
```

to the note.

Calculate financial state from the existing source-of-truth architecture.

---

# 64. No Client-Controlled Totals

Do not trust:

```text
subtotal
tax_total
grand_total
remaining_adjustment
```

from the client.

They may be accepted as display hints if the existing API convention requires
them, but server-side calculation must be authoritative.

---

# 65. Document Number Uniqueness

Document numbers must be company scoped.

Do not make human-facing note numbers globally unique unless the existing
numbering architecture explicitly requires it.

---

# 66. Auditability

Use existing audit fields and mechanisms.

At minimum, posted notes must identify:

```text
created_by
posted_by
posted_at
```

If the existing models also use updated-by fields, follow that convention.

Do not create a separate audit framework.

---

# 67. Error Handling

Return clear validation errors for:

- source document not found;
- source document belongs to another company;
- source document is not eligible;
- adjustment exceeds remaining amount;
- incompatible tax;
- missing tax account;
- invalid period;
- posted note modification;
- already posted note;
- unauthorized operation.

Do not expose sensitive cross-company existence information.

---

# 68. Requirements Traceability

The final report must map every requirement in this prompt to:

```text
implemented
tested
deferred
not applicable
```

Do not mark a requirement complete merely because code exists.

It must have verification evidence where applicable.

---

# 69. Final Report

Create:

```text
docs/reports/PHASE_11_REPORT.md
```

The report must contain:

## 1. Phase Objectives

## 2. Current Architecture Reviewed

## 3. Database Changes

## 4. Models

## 5. Enums

## 6. Services

## 7. Document Lifecycle

## 8. Source Document Relationship

## 9. Adjustment Calculation

## 10. Tax Integration

## 11. Historical Snapshot Integrity

## 12. Accounting Integration

## 13. Journal Examples

## 14. Customer/Supplier Settlement Effects

## 15. API Endpoints

## 16. Permissions

## 17. Authorization

## 18. Company Isolation

## 19. Fiscal Period Handling

## 20. Reports

## 21. Security Verification

## 22. Performance

## 23. Tests

## 24. Commands Executed

## 25. Migration Verification

## 26. Known Limitations

## 27. Deferred Features

## 28. Requirements Traceability

## 29. Final Status

Use exactly one:

```text
PASS
PASS WITH NOTES
BLOCKED
```

Do not claim PASS unless the full required verification has actually been
performed.

---

# 70. Hard Stop Conditions

Stop and report instead of guessing if:

- the existing invoice/bill architecture cannot safely support notes;
- adjustment limits cannot be enforced atomically;
- the existing settlement model conflicts with note accounting;
- tax snapshots cannot be preserved;
- existing journal posting cannot represent the required correction;
- source document relationships are ambiguous;
- customer/supplier balance semantics are unclear;
- fiscal-period behavior conflicts with the note lifecycle;
- implementing notes would require redesigning the existing accounting engine;
- inventory behavior would be required but is not defined;
- payment/refund behavior becomes necessary to make the accounting model
  correct.

Do not silently invent business rules.

---

# 71. Final Verification

Before completion, verify:

### Architecture

- [ ] Existing accounting engine reused
- [ ] Existing tax engine reused
- [ ] Existing settlement engine reused
- [ ] No second ledger
- [ ] No second tax engine
- [ ] No stored balances

### Financial Integrity

- [ ] Posted source documents remain immutable
- [ ] Posted notes are immutable
- [ ] Adjustment limits enforced
- [ ] Concurrent over-adjustment prevented
- [ ] Journals balance
- [ ] Fiscal periods respected
- [ ] Tax snapshots preserved

### Security

- [ ] No client-controlled company_id
- [ ] Cross-company access blocked
- [ ] Server-side authorization
- [ ] Policy registration verified
- [ ] Tampered IDs rejected
- [ ] Safe deletion enforced

### Reporting

- [ ] Customer statements reflect notes
- [ ] Supplier statements reflect notes
- [ ] Receivables reflect notes
- [ ] Payables reflect notes
- [ ] P&L reflects notes
- [ ] Balance Sheet reflects notes
- [ ] Tax reports reflect notes

### Quality

- [ ] Focused tests pass
- [ ] Full suite passes
- [ ] Pint passes
- [ ] Migrations verified
- [ ] Routes verified
- [ ] No seeders
- [ ] No frontend
- [ ] No unrelated refactoring

---

# 72. Final Instruction

Build Phase 11 as a **financial correction/adjustment layer**, not as a
replacement for invoices, payments, tax, or accounting.

The desired architecture is:

```text
Original Invoice/Bill
        │
        │ remains immutable
        ↓
Credit/Debit Note
        │
        ├── own calculation
        ├── own tax snapshot
        ├── source-document reference
        └── own journal
                  │
                  ↓
          Existing Ledger
                  │
                  ├── Customer/Supplier Statement
                  ├── Receivables/Payables
                  ├── P&L
                  ├── Balance Sheet
                  └── Tax Reports
```

Prefer:

```text
simple
+
exact
+
traceable
+
company-scoped
+
immutable
+
reusable
```

over unnecessary abstraction.

Inspect first.

Implement carefully.

Test thoroughly.

Do not hurry.

Do not run seeders.

Do not create frontend code.

Do not break Phases 1–10.

When complete, create:

```text
docs/reports/PHASE_11_REPORT.md
```

and stop.
