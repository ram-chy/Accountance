# PHASE 12 — FIXED ASSET MANAGEMENT

## 1. ROLE

You are implementing **Phase 12 — Fixed Asset Management** for the existing Accounting Web Application.

You are working inside:

```text
accounting-app/backend
```

This is an existing Laravel 13 accounting backend.

**Do not treat this as a greenfield project.**

Before writing code, inspect the existing application and the completed Phase 1–11 implementation, especially:

- Chart of Accounts
- Journal / JournalPostingService
- LedgerService
- AccountingPeriodService
- Company context
- Users / roles / permissions
- Audit conventions
- Money handling
- Document numbering
- Reports
- Cash & Banking
- Bank Reconciliation
- Tax Engine
- Sales Invoices
- Purchase Bills
- Customer Payments
- Supplier Payments
- Expenses
- Credit & Debit Notes
- existing migration conventions
- existing API response/resource conventions
- existing testing conventions

The Phase 11 implementation report is authoritative for the current architecture.

Phase 11 confirms that the application now has:

- a single existing journal/ledger path;
- immutable posted financial documents;
- fiscal-period enforcement;
- exact `Money` arithmetic;
- company isolation;
- server-side authorization;
- auditability;
- tax snapshot integrity;
- ledger-derived reporting;
- no stored accounting balances;
- no second accounting engine.

Do not duplicate any of those systems.

---

# 2. PRIMARY OBJECTIVE

Implement a practical **Fixed Asset Management** module that integrates with the existing accounting engine.

The module must support the complete accounting lifecycle of a fixed asset without creating a second accounting system.

The intended flow is:

```text
Asset Master
    ↓
Asset Acquisition
    ↓
Capitalization
    ↓
Depreciation
    ↓
Accumulated Depreciation
    ↓
Asset Disposal
```

The accounting effect of every financial event must go through the existing journal/posting architecture.

The module must remain simple and suitable for the current accounting application.

---

# 3. IMPORTANT ARCHITECTURAL RULE

Do NOT create:

- a second ledger;
- a second journal engine;
- a separate accounting posting engine;
- stored account balances;
- independent financial calculations that disagree with the General Ledger;
- a complex asset-management framework;
- country-specific fixed-asset compliance;
- an ERP-sized asset hierarchy;
- unnecessary workflow states.

Reuse existing infrastructure wherever possible.

The existing journal and ledger remain the source of truth for accounting.

---

# 4. SCOPE

Phase 12 should cover:

1. Fixed Asset Master
2. Asset Categories
3. Asset Accounts / Account Mapping
4. Asset Acquisition / Capitalization
5. Depreciation configuration
6. Depreciation calculation
7. Depreciation posting
8. Accumulated depreciation
9. Asset disposal
10. Gain/loss on disposal
11. Asset register
12. Asset depreciation schedule
13. Basic fixed-asset reporting
14. Accounting integration
15. Company isolation
16. Authorization
17. Auditability
18. Safe deletion
19. Concurrency/integrity protection
20. Comprehensive automated testing

---

# 5. EXPLICIT NON-GOALS

Do NOT implement in this phase:

- inventory management;
- stock depreciation;
- tax depreciation;
- country-specific tax depreciation rules;
- GST/VAT compliance;
- e-invoicing;
- tax filing;
- payroll;
- lease accounting;
- impairment accounting;
- revaluation accounting;
- component accounting;
- asset leasing;
- asset insurance;
- barcode/RFID management;
- maintenance management;
- physical asset tracking;
- purchase-order integration unless the existing application already requires it;
- automatic payment/refund behaviour;
- multi-currency fixed assets unless the existing accounting foundation already requires it;
- frontend;
- PDF printing;
- dashboards;
- seeders;
- unrelated refactoring.

Do not silently expand the scope.

If a requirement would require one of these systems, document it as deferred rather than implementing it.

---

# 6. INSPECT THE EXISTING ACCOUNTING MODEL FIRST

Before implementation, determine exactly how the current system represents:

- accounts;
- account types;
- journals;
- journal lines;
- posting;
- fiscal periods;
- companies;
- audit fields;
- permissions;
- document numbering;
- money;
- transactions;
- reporting;
- tax;
- users.

Do not assume field names.

Do not assume service names.

Use the actual existing implementation.

If the existing architecture already contains an appropriate concept, extend it rather than introducing a duplicate.

---

# 7. FIXED ASSET CATEGORY

Create an asset-category/master-data concept only if the existing application does not already provide an equivalent.

A category should conceptually support:

- id
- company_id
- name
- code
- description
- useful_life_months
- depreciation_method
- asset_account_id
- accumulated_depreciation_account_id
- depreciation_expense_account_id
- gain_on_disposal_account_id
- loss_on_disposal_account_id
- is_active
- timestamps

Use the existing account relationship conventions.

Do not store account balances here.

Do not allow arbitrary client-controlled account mappings without server-side validation.

---

# 8. FIXED ASSET MASTER

Create a fixed asset entity.

Conceptually it should support:

- id
- company_id
- asset_number
- name
- category_id
- description
- acquisition_date
- capitalization_date
- original_cost
- salvage_value
- useful_life_months
- depreciation_method
- depreciation_start_date
- asset_account_id
- accumulated_depreciation_account_id
- depreciation_expense_account_id
- status
- disposed_at
- disposal_proceeds
- disposal_gain_loss
- created_by
- posted_by where appropriate
- timestamps

Inspect the existing conventions before finalizing the schema.

Do not allow client-supplied accounting totals.

---

# 9. ASSET NUMBERING

Reuse the existing `DocumentNumberSequence` infrastructure if appropriate.

Do not invent an unrelated numbering system.

Use a dedicated fixed-asset document-number type if the current numbering architecture requires it.

The number must be:

- server generated;
- company scoped;
- unique;
- not editable by clients.

Do not use random UUIDs as a human-facing asset number unless the existing architecture explicitly uses them for documents.

---

# 10. ASSET STATUS

Keep the lifecycle simple.

Recommended conceptual lifecycle:

```text
DRAFT
    ↓
ACTIVE
    ↓
FULLY_DEPRECIATED
    ↓
DISPOSED
```

Do not add unnecessary intermediate states.

The exact enum names must follow existing application conventions.

Rules:

- draft assets may be edited;
- posted/capitalized assets are protected from arbitrary mutation;
- fully depreciated assets remain active until disposal;
- disposed assets cannot receive normal depreciation;
- disposed assets cannot be disposed again.

Do not allow a client to directly manipulate status.

---

# 11. ACQUISITION / CAPITALIZATION

The system must support capitalization of an asset.

The acquisition amount must be calculated/validated server-side.

Do not trust client-provided totals.

The capitalization transaction must create a balanced journal using the existing journal infrastructure.

For a cash purchase:

```text
Dr Fixed Asset
    Cr Cash/Bank
```

For a supplier-credit acquisition:

```text
Dr Fixed Asset
    Cr Accounts Payable
```

However, do not create a new supplier-payment/accounting engine.

If the existing purchase-bill architecture can legitimately support asset acquisition, inspect it and integrate appropriately.

Do not force purchase-bill integration if it would create unnecessary coupling.

The final implementation must clearly document which acquisition path is supported.

---

# 12. CAPITALIZATION RULES

A fixed asset should not become an active depreciable asset until capitalization succeeds.

Validate:

- company ownership;
- valid asset account;
- valid category;
- positive original cost;
- salvage value >= 0;
- salvage value < original cost unless the existing accounting policy explicitly permits otherwise;
- valid useful life;
- valid depreciation method;
- valid capitalization date;
- open fiscal period for posting;
- active accounts.

The server must reject invalid combinations.

---

# 13. DEPRECIATION METHODS

Phase 12 should support:

## Straight-Line Depreciation

Use:

```text
Depreciable Amount =
Original Cost - Salvage Value
```

Then:

```text
Periodic Depreciation =
Depreciable Amount / Useful Life
```

Use exact decimal arithmetic.

Do not use FLOAT or DOUBLE.

Do not calculate financial values with JavaScript/frontend arithmetic.

---

# 14. DEPRECIATION PRECISION

Reuse the existing `Money` and decimal arithmetic conventions.

Do not introduce a second money utility.

Depreciation must:

- use exact decimal arithmetic;
- avoid floating-point calculations;
- never depreciate below salvage value;
- never depreciate beyond the depreciable amount;
- handle final-period rounding correctly.

The final depreciation period may need to absorb rounding differences so total accumulated depreciation equals exactly:

```text
Original Cost - Salvage Value
```

Do not create an unexplained rounding balance.

---

# 15. DEPRECIATION PERIOD

Use the existing fiscal-period infrastructure.

A depreciation posting must:

- have a financial date;
- fall within an open accounting period;
- use the existing `AccountingPeriodService`;
- create a journal through the existing posting path.

Do not create a separate depreciation-period engine.

---

# 16. DEPRECIATION POSTING

Each posted depreciation transaction should create a balanced journal:

```text
Dr Depreciation Expense
    Cr Accumulated Depreciation
```

Use the configured accounts from the asset/category.

The journal must use the existing:

- JournalService;
- JournalPostingService;
- JournalSource;
- fiscal-period validation;
- company context;
- audit conventions.

Add a new journal source enum only if the current enum architecture requires it.

Do not write directly to journal tables from controllers.

---

# 17. DEPRECIATION IDEMPOTENCY

This is critical.

The system must not allow the same asset depreciation period to be posted twice.

Define an explicit uniqueness/integrity rule for the supported depreciation period.

The database and service layer should both protect against duplicate posting.

Consider concurrent requests:

```text
Request A → depreciation calculation
Request B → same depreciation calculation
```

Only one must be able to post the same depreciation period.

Use database transactions and appropriate locking/unique constraints.

Do not rely solely on frontend disabling a button.

---

# 18. DEPRECIATION SCHEDULE

Provide a server-side depreciation schedule calculation.

The schedule should show, at minimum:

- period/date;
- opening book value;
- depreciation expense;
- accumulated depreciation;
- closing book value.

The schedule must be derived from the asset configuration and posted depreciation data.

Do not create a second stored balance source of truth.

Clearly distinguish:

- projected/unposted depreciation;
- posted depreciation.

If the existing application architecture makes a different distinction necessary, document it.

---

# 19. ACCUMULATED DEPRECIATION

Do not maintain a manually editable accumulated depreciation field as an accounting source of truth.

The authoritative accounting amount comes from posted journal entries.

If a cached/display value is technically necessary, it must never become the accounting source of truth.

Prefer deriving it from posted depreciation journals or a controlled existing reporting service.

---

# 20. ASSET BOOK VALUE

Book value should be conceptually:

```text
Book Value =
Original Cost - Accumulated Depreciation
```

But do not independently store an accounting balance that can diverge from the ledger.

The system should ensure:

```text
Book Value >= Salvage Value
```

unless disposal has occurred.

---

# 21. ASSET DISPOSAL

Support disposal of an active fixed asset.

A disposal must be a financial transaction and must create a balanced journal.

The system should calculate:

```text
Net Book Value =
Original Cost - Accumulated Depreciation
```

Then determine:

```text
Gain/Loss =
Disposal Proceeds - Net Book Value
```

Do not trust client-supplied gain/loss values.

---

# 22. DISPOSAL ACCOUNTING

For a disposal with proceeds, the journal should conceptually account for:

- removal of the fixed asset cost;
- removal of accumulated depreciation;
- cash/bank or receivable proceeds;
- gain or loss.

Example when proceeds exceed book value:

```text
Dr Cash / Bank                  proceeds
Dr Accumulated Depreciation     accumulated depreciation
    Cr Fixed Asset              original cost
    Cr Gain on Disposal         gain
```

Example when proceeds are below book value:

```text
Dr Cash / Bank                  proceeds
Dr Accumulated Depreciation     accumulated depreciation
Dr Loss on Disposal             loss
    Cr Fixed Asset              original cost
```

Use the existing accounting engine.

Do not create a disposal-specific ledger.

---

# 23. DISPOSAL VALIDATION

Before disposal:

- asset must belong to current company;
- asset must be active;
- asset must have been capitalized;
- asset must not already be disposed;
- disposal date must be valid;
- disposal date must respect fiscal periods;
- proceeds must be non-negative;
- configured accounts must be active;
- calculated book value must be valid.

After disposal:

- asset cannot be depreciated further;
- asset cannot be disposed again;
- posted disposal cannot be edited;
- posted disposal cannot be hard deleted.

---

# 24. DISPOSAL WITH ZERO PROCEEDS

Support a disposal with:

```text
proceeds = 0
```

The resulting loss should be calculated from the net book value.

Do not require a cash/bank account when proceeds are zero unless the existing accounting model requires another disposal settlement account.

Document the accounting treatment clearly.

---

# 25. TAX BOUNDARY

Do not create a fixed-asset tax engine.

Phase 10 already established the Tax Engine as a separate system.

Do not automatically invent tax treatment for:

- acquisition;
- depreciation;
- disposal.

If an existing tax/accounting rule is applicable, reuse it only where the current architecture explicitly supports it.

Country-specific tax depreciation remains out of scope.

---

# 26. ACCOUNTING REPORT INTEGRATION

Fixed-asset journals must automatically flow into existing ledger-derived reports.

At minimum verify:

- General Ledger;
- Trial Balance;
- Profit & Loss;
- Balance Sheet.

Depreciation should affect:

```text
Depreciation Expense
```

and:

```text
Accumulated Depreciation
```

Disposal should affect the appropriate asset, accumulated depreciation, cash/receivable and gain/loss accounts.

Do not modify reports merely to add special fixed-asset calculations if existing ledger-derived reports already include the journals.

Follow the same principle confirmed in Phase 11: reuse ledger-derived reporting rather than creating report-specific accounting logic.

---

# 27. FIXED ASSET REGISTER

Add a read-only asset register/report endpoint.

It should support useful filters such as:

- category;
- status;
- acquisition date;
- capitalization date;
- active/disposed;
- asset number.

The register may display:

- asset number;
- asset name;
- category;
- acquisition cost;
- accumulated depreciation;
- book value;
- status.

These financial values must be derived from authoritative accounting data.

---

# 28. DEPRECIATION REPORT

Provide a read-only depreciation report/schedule.

It should support appropriate date/asset filters.

Clearly identify:

- posted depreciation;
- projected depreciation if supported.

Do not turn the report into a write endpoint.

---

# 29. API DESIGN

Follow existing API conventions.

Potential routes:

```text
GET    /api/fixed-assets
POST   /api/fixed-assets
GET    /api/fixed-assets/{fixedAsset}
PUT    /api/fixed-assets/{fixedAsset}
DELETE /api/fixed-assets/{fixedAsset}

POST   /api/fixed-assets/{fixedAsset}/capitalize

GET    /api/fixed-assets/{fixedAsset}/depreciation-schedule

POST   /api/fixed-assets/{fixedAsset}/depreciation

POST   /api/fixed-assets/{fixedAsset}/dispose

GET    /api/fixed-assets/register
GET    /api/fixed-assets/depreciation-report
```

These are proposed routes, not guaranteed final routes.

Inspect the existing route conventions and use the smallest consistent design.

Do not create duplicate endpoints merely because the proposed list contains them.

---

# 30. PERMISSIONS

Introduce permissions only if the existing permission architecture does not already contain suitable equivalents.

Conceptually:

```text
accounting.fixed_asset.view
accounting.fixed_asset.create
accounting.fixed_asset.update
accounting.fixed_asset.delete
accounting.fixed_asset.capitalize
accounting.fixed_asset.depreciate
accounting.fixed_asset.dispose
```

Follow the established role model.

Do not grant permissions arbitrarily.

Expected principle:

- Admin: full access
- Accountant: accounting operations
- Manager: according to existing financial approval conventions
- Staff: no accounting posting capability

Inspect the existing role matrix before implementing.

Server-side authorization is mandatory.

---

# 31. COMPANY ISOLATION

Every asset query and mutation must be company scoped.

Never trust:

```text
company_id
```

from the client.

The company context must determine ownership.

Cross-company access should follow the application's established behavior.

Phase 11 uses company-scoped route binding and returns 404 for another company's resource. Follow that convention where applicable.

---

# 32. SAFE DELETION

Deletion rules must follow existing accounting conventions.

A draft/unposted asset may be deleted only if safe.

A capitalized/posted asset must not be hard deleted.

An asset with:

- capitalization journal;
- depreciation journals;
- disposal journal;

must be protected from destructive deletion.

Prefer database RESTRICT relationships where appropriate.

Do not create cascading deletion that can destroy accounting history.

---

# 33. AUDITABILITY

Use the existing audit conventions.

Financially significant actions should identify:

- who created the asset;
- who capitalized it;
- who posted depreciation;
- who disposed it;
- relevant dates.

Do not create a second audit system.

---

# 34. DATABASE DESIGN

Use additive migrations.

Do not destructively modify existing accounting tables unless absolutely required and explicitly justified.

Potential tables:

```text
fixed_asset_categories
fixed_assets
fixed_asset_depreciations
fixed_asset_disposals
```

However, **do not create all four automatically**.

Inspect the actual domain model first.

Avoid unnecessary tables.

In particular, do not create a table simply to store a balance that can be derived from journals.

Every table must have a clear responsibility.

Use:

- foreign keys;
- appropriate indexes;
- company scoping;
- timestamps;
- appropriate CHECK constraints where supported;
- decimal types for money.

---

# 35. MONEY TYPES

Use the existing application's exact money type and decimal precision.

Never introduce:

```text
FLOAT
DOUBLE
```

for monetary values.

Do not use floating-point arithmetic anywhere in:

- acquisition;
- depreciation;
- disposal;
- gain/loss.

---

# 36. TRANSACTIONAL INTEGRITY

Capitalization, depreciation posting and disposal must be transactional.

A financial operation must not leave partial state such as:

```text
asset marked active
but journal missing
```

or:

```text
journal posted
but asset disposal state missing
```

Use the existing transaction/posting architecture.

Where necessary:

```text
DB transaction
    ↓
validate
    ↓
lock relevant asset/source
    ↓
create/post journal
    ↓
update asset state
    ↓
commit
```

Do not perform financial posting outside the established transaction pattern.

---

# 37. CONCURRENCY

Explicitly test concurrent scenarios.

At minimum:

### Depreciation

Two requests attempt to post the same asset depreciation period.

Expected:

```text
one succeeds
one is rejected
```

### Disposal

Two requests attempt to dispose the same asset.

Expected:

```text
one succeeds
one is rejected
```

### Capitalization

Two requests must not create duplicate capitalization accounting for the same asset.

Use database constraints and locking where appropriate.

---

# 38. SECURITY REVIEW

Verify:

- company isolation;
- authorization;
- tampered asset IDs;
- tampered category IDs;
- tampered account IDs;
- client-supplied totals;
- client-supplied status;
- client-supplied journal IDs;
- client-supplied accumulated depreciation;
- client-supplied gain/loss;
- duplicate posting;
- cross-company source references;
- deleted/inactive accounts;
- disposed asset reuse;
- invalid fiscal period;
- unauthorized report access.

Server-owned fields must not be trusted from request payloads.

---

# 39. TESTING REQUIREMENTS

Create focused tests for at least:

## Asset Master

- create;
- validation;
- update draft;
- safe deletion;
- company isolation.

## Capitalization

- successful capitalization;
- balanced journal;
- correct accounts;
- fiscal-period validation;
- duplicate capitalization prevention;
- unauthorized capitalization.

## Depreciation

- straight-line calculation;
- salvage value;
- exact decimal arithmetic;
- final-period rounding;
- cannot exceed depreciable amount;
- cannot depreciate disposed asset;
- duplicate period prevention;
- journal balance;
- fiscal-period enforcement;
- concurrency.

## Disposal

- disposal above book value;
- disposal below book value;
- disposal at book value;
- zero proceeds;
- correct gain/loss;
- correct journal;
- cannot dispose twice;
- cannot depreciate after disposal;
- concurrency.

## Reports

- asset register;
- depreciation schedule;
- General Ledger;
- Trial Balance;
- P&L;
- Balance Sheet.

## Security

- all role permissions;
- cross-company access;
- tampered account IDs;
- tampered category IDs;
- server-owned fields;
- unauthorized posting;
- unauthorized disposal.

---

# 40. REGRESSION TESTING

Before declaring Phase 12 complete:

Run:

```bash
php artisan test
```

The Phase 11 baseline is:

```text
891 tests
4404 assertions
0 failures
0 errors
```

The exact post-Phase-12 count will naturally increase.

Do not claim success merely because the new tests pass.

The **entire application test suite must pass**.

Also run:

```bash
./vendor/bin/pint --test
```

and verify migrations.

---

# 41. MIGRATION VERIFICATION

Verify:

```bash
php artisan migrate
```

Then verify rollback/re-run using the existing project's migration test/convention.

Do not leave migrations in a partially applied state.

Do not use seeders.

Do not create fake production data.

---

# 42. ROUTE VERIFICATION

Verify the final API routes with the project's existing route-list conventions.

Check:

- authentication middleware;
- company context;
- throttling;
- authorization;
- route model binding;
- resource response consistency.

---

# 43. DOCUMENTATION

Create:

```text
docs/report/PHASE_12_REPORT.md
```

Follow the repository's actual report directory convention.

The report must document:

1. objectives;
2. architecture reviewed;
3. database changes;
4. models;
5. services;
6. accounting flow;
7. capitalization;
8. depreciation;
9. disposal;
10. gain/loss;
11. API endpoints;
12. permissions;
13. company isolation;
14. fiscal-period handling;
15. reports;
16. security verification;
17. concurrency handling;
18. tests;
19. migration verification;
20. commands executed;
21. known limitations;
22. deferred features;
23. requirements traceability;
24. final status.

Do not claim a requirement was verified unless the command/test actually ran.

---

# 44. HARD STOPS

STOP implementation and document the issue if any of the following occurs:

1. Existing journal architecture cannot represent fixed-asset transactions cleanly.
2. Existing account model cannot support required asset/depreciation accounts without redesign.
3. Existing fiscal-period rules conflict with depreciation posting.
4. Existing company context cannot safely isolate assets.
5. Existing authorization model cannot safely protect financial operations.
6. Existing Money implementation cannot support required depreciation precision.
7. Correct disposal accounting would require a second ledger/journal engine.
8. Correct depreciation would require storing a second accounting balance source.
9. Existing purchase/acquisition architecture creates unavoidable duplicate accounting.
10. A requirement would force tax-compliance or inventory functionality into this phase.

If a hard stop occurs:

- do not work around it with an unrelated architecture;
- document the exact conflict;
- identify the affected existing classes/tables/services;
- explain the smallest architectural change required;
- stop before introducing speculative infrastructure.

---

# 45. CODE QUALITY RULES

Follow the existing project's conventions.

Do not:

- rewrite working services unnecessarily;
- rename unrelated classes;
- reorganize unrelated directories;
- refactor old modules merely for style;
- introduce generic frameworks;
- introduce repositories solely because they are fashionable;
- introduce CQRS/event sourcing;
- introduce a new calculation engine;
- introduce a new accounting abstraction;
- introduce unnecessary interfaces;
- create speculative multi-country compliance abstractions.

Prefer the simplest implementation that preserves accounting correctness.

---

# 46. FINAL ACCEPTANCE CRITERIA

Phase 12 is complete only when:

- fixed assets can be created safely;
- capitalization creates balanced accounting entries;
- depreciation is calculated exactly;
- depreciation posts through the existing journal engine;
- depreciation cannot exceed depreciable value;
- disposal calculates book value and gain/loss correctly;
- disposal creates balanced accounting entries;
- posted financial records are immutable;
- fiscal periods are respected;
- company isolation is enforced;
- authorization is enforced;
- duplicate/concurrent financial operations are prevented;
- existing reports correctly reflect fixed-asset journals;
- asset register works;
- depreciation schedule works;
- no second accounting engine exists;
- no stored accounting balance becomes a second source of truth;
- migrations pass;
- focused tests pass;
- full application test suite passes;
- Pint passes;
- no seeders were created or executed;
- no frontend was created;
- no unrelated modules were changed;
- `docs/report/PHASE_12_REPORT.md` exists and accurately records the implementation.

---

# 47. FINAL INSTRUCTION TO OPENCODE

**Inspect first. Implement second.**

Do not assume the proposed table names, service names, routes, permissions or field names are correct until you inspect the current Phase 1–11 implementation.

Reuse the existing accounting engine, ledger, fiscal-period system, Money implementation, company context, authorization, audit conventions and reporting architecture.

Do not create a second accounting system.

Do not run seeders.

Do not create frontend code.

Do not hurry.

Make the smallest coherent implementation that provides a reliable fixed-asset accounting layer.

Run focused tests throughout development, then run the complete application test suite.

If something fundamental conflicts with the existing architecture, trigger the appropriate hard stop instead of inventing a workaround.

At the end, produce:

```text
docs/report/PHASE_12_REPORT.md
```

with an honest PASS / PASS WITH NOTES / BLOCKED status and exact test results.
