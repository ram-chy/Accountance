# PHASE 17 — COST CENTERS & FINANCIAL DIMENSIONS

## ROLE

You are implementing **Phase 17 — Cost Centers & Financial Dimensions** of an
existing production-oriented Accounting Web Application.

This is an existing Laravel accounting system with Phases 1–16 already
implemented and tested.

You are **NOT starting a new project**.

Your job is to inspect the existing repository, understand the current
accounting architecture, and implement Phase 17 **without breaking or
duplicating any existing accounting subsystem**.

---

# 1. PROJECT CONTEXT

Current architecture:

- Backend: Laravel 13
- PHP: 8.4+
- Database: MySQL 8.4 LTS
- Storage engine: InnoDB
- Character set: utf8mb4
- API: REST
- Authentication: JWT
- Multi-company architecture
- Company context is already implemented
- Accounting basis: Accrual
- Accounting model: Double-entry
- Money: `DECIMAL(20,4)`
- Money arithmetic: existing `App\Support\Money`
- Internal accounting amounts are never FLOAT/DOUBLE
- Posted financial transactions are immutable
- Corrections use existing reversal/correction mechanisms
- Fiscal periods already exist
- Multi-currency and FX accounting already exist
- Tax engine already exists
- Audit infrastructure already exists
- Accounting controls already exist
- Financial reporting already exists
- Budgeting and budget-vs-actual reporting were completed in Phase 16
- Frontend is NOT part of this phase
- Next.js frontend will be implemented later

The accounting engine already has a single source of truth:

```text
Business Transaction
        ↓
Existing Journal Engine
        ↓
Journal
        ↓
Journal Lines
        ↓
Existing Ledger / Reporting Services
```

Phase 17 must preserve that architecture.

---

# 2. CURRENT BASELINE — DO NOT REGRESS

Phase 15 baseline:

- 1,180 tests
- 5,250 assertions

Phase 16 final baseline:

- **1,208 tests**
- **5,358 assertions**
- **0 failures**
- **0 errors**
- **0 skipped**

Phase 16 added:

- Budget master
- Budget versions
- Budget lines
- Revenue/Expense budgeting
- Budget approval
- Budget revision
- Budget-vs-actual reporting

The Phase 16 report confirms:

- Existing accounting engine remains the source of truth.
- Budgets never post.
- Actuals are derived from posted journals.
- Budget values do not store actuals or ledger balances.
- Budget variance uses the existing ledger/reporting path.
- Budgeting is company scoped.
- Approved budgets are immutable.
- Multi-currency budgets are intentionally deferred.
- Balance-sheet budgeting is intentionally deferred.

Treat **1,208 tests / 5,358 assertions / 0 failures** as the regression baseline
for this phase.

Do not weaken, delete, rewrite, or bypass existing tests merely to make Phase 17
pass.

---

# 3. PHASE 17 OBJECTIVE

Implement a reusable **Financial Dimensions framework**, with **Cost Center** as
the first concrete/useful dimension.

The purpose is to allow management and accounting reports to answer questions
such as:

- How much did Sales spend?
- How much revenue came from a particular department?
- What are the expenses of a particular cost center?
- What is the P&L for a specific cost center?
- What was budgeted for a cost center?
- What was actually spent?
- What is the budget variance by cost center?
- Can management filter financial reports by a dimension?

The framework must be extensible enough to support additional dimensions later,
such as:

- Department
- Branch
- Project
- Location
- Business Unit
- Division

BUT:

**Do not implement all of those dimensions now unless the existing architecture
proves that doing so is necessary.**

Phase 17 should establish the reusable foundation and implement Cost Center as
the first supported dimension.

---

# 4. CRITICAL ARCHITECTURAL PRINCIPLE

## Financial dimensions are analytical metadata.

They are NOT another accounting system.

Do NOT create:

- a second ledger
- a dimension ledger
- dimension journals
- dimension balances
- duplicated debit/credit accounting
- stored financial balances per cost center
- a separate posting engine
- a separate reporting engine
- a second currency system
- a second audit system

The architecture must remain:

```text
                    Existing Accounting Engine
                              │
                              ▼
                         Journal Lines
                              │
                 ┌────────────┴────────────┐
                 │                         │
          Accounting Amounts        Dimension Metadata
                 │                         │
                 ▼                         ▼
              Ledger              Dimension Association
                 │                         │
                 └────────────┬────────────┘
                              ▼
                    Existing + Dimension-aware
                           Reporting
```

The accounting amount remains authoritative in `journal_lines`.

Dimensions only describe **where / why / organizationally how** the accounting
amount should be analyzed.

---

# 5. FIRST STEP — INSPECT BEFORE CODING

Before creating any migration, model, service, controller, or route:

Inspect the repository thoroughly.

At minimum inspect:

## Accounting

- `Journal`
- `JournalLine`
- `JournalService`
- `JournalPostingService`
- `LedgerService`
- `JournalReportService`
- `ProfitLossReportService`
- Trial Balance
- General Ledger
- Balance Sheet
- Accounting Rules
- Account / AccountType / NormalBalance
- Accounting periods
- Financial years

## Budgeting

Inspect all Phase 16 implementation:

- `Budget`
- `BudgetLine`
- `BudgetService`
- `BudgetLineService`
- `BudgetVarianceReportService`
- budget migrations
- budget requests
- budget resources
- budget policy
- budget routes
- budget tests

Pay special attention to how budget lines currently identify:

- account
- accounting period
- financial year
- company
- amount

## Multi-company

Inspect:

- `CompanyContext`
- company-scoped route bindings
- model scopes
- authorization
- service-level company checks

## Authorization

Inspect:

- `PermissionName`
- `config/authorization.php`
- role grants
- policies
- `AppServiceProvider`

## Audit

Inspect:

- `AuditService`
- `AuditAction`
- audit models
- audit tests

## Controls

Inspect:

- `AccountingControlService`
- existing integrity controls
- control reporting APIs

## Reporting

Inspect:

- all existing report services
- report controller
- report requests
- report resources
- existing filtering patterns

## Money

Inspect:

- `App\Support\Money`
- accounting precision configuration
- existing decimal validation

## Tests

Inspect:

- accounting feature tests
- journal tests
- posting tests
- reporting tests
- budget tests
- authorization tests
- company isolation tests
- concurrency tests

Do not make architectural assumptions before this inspection.

---

# 6. HARD-STOP CONDITIONS

Before implementation, explicitly determine whether any of the following are
true.

If a hard-stop condition is encountered, STOP and document it rather than
forcing an unsafe implementation.

### HARD STOP 1

The existing journal architecture cannot associate analytical metadata with
journal lines without breaking posted-journal immutability.

### HARD STOP 2

Adding dimensions requires creating a second accounting or ledger engine.

### HARD STOP 3

Existing reports cannot derive dimension-aware actuals from the existing journal
lines.

### HARD STOP 4

Phase 16 budget integration would require duplicating actual amounts.

### HARD STOP 5

The proposed schema requires rewriting historical accounting data.

### HARD STOP 6

Existing multi-company isolation cannot be preserved.

### HARD STOP 7

The implementation would require changing the meaning of existing journal
amounts.

### HARD STOP 8

The implementation would silently introduce floating-point monetary
calculations.

### HARD STOP 9

The implementation requires modifying posted journal lines after posting.

### HARD STOP 10

The implementation requires a second authorization, audit, or currency
framework.

If none apply, continue.

Document the hard-stop assessment in the final report.

---

# 7. DESIGN OBJECTIVE

Create a reusable financial-dimension structure.

Conceptually:

```text
Company
   │
   └── Financial Dimension
          │
          ├── Dimension Type
          │
          └── Dimension Values
```

Example:

```text
Dimension Type: COST_CENTER

Values:
    CC-001 Sales
    CC-002 Marketing
    CC-003 Administration
    CC-004 Operations
```

Potential future types:

```text
DEPARTMENT
BRANCH
PROJECT
LOCATION
BUSINESS_UNIT
```

But only implement what is justified by the inspection.

---

# 8. IMPORTANT: DO NOT OVER-ENGINEER THE DIMENSION MODEL

The framework must be reusable, but do not build an enterprise OLAP/cube system.

Do NOT introduce:

- dimension hierarchies unless already required
- recursive trees
- arbitrary formulas
- dimension allocation engines
- percentages
- weighted allocations
- statistical cubes
- snapshots
- materialized dimension balances
- reporting DSL
- generic query language
- event-sourced dimension system

unless the repository already has such infrastructure and Phase 17 genuinely
requires it.

The first implementation should remain simple.

---

# 9. RECOMMENDED CONCEPTUAL MODEL

Unless repository inspection demonstrates a better existing convention, use a
structure conceptually similar to:

```text
financial_dimensions
financial_dimension_values
```

Example:

```text
financial_dimensions

id
company_id
code
name
type
is_active
created_by
updated_by
created_at
updated_at
```

and:

```text
financial_dimension_values

id
financial_dimension_id
code
name
is_active
created_at
updated_at
```

However:

**Do not blindly copy this schema.**

Inspect the existing conventions and determine whether:

- `type` should be an enum
- `type` belongs on the dimension or value
- Cost Center should be its own entity
- a generic dimension framework is justified
- existing master-data conventions suggest another design

Document the architectural decision before implementation.

---

# 10. COST CENTER

Cost Center should be the first supported financial dimension.

Example:

```text
COST_CENTER
    ├── SALES
    ├── MARKETING
    ├── ADMIN
    └── OPERATIONS
```

A cost center should be:

- company scoped
- uniquely identifiable within the company
- active/inactive
- auditable
- authorization controlled
- safe to reference from accounting transactions
- protected from destructive deletion when historical accounting references
  exist

Do not hard-code these names.

The user/company creates them.

---

# 11. JOURNAL LINE INTEGRATION

This is one of the most important parts of Phase 17.

Determine the safest way to associate financial dimensions with existing journal
lines.

Preferred conceptual result:

```text
Journal
   └── Journal Lines
          ├── Debit/Credit Amount
          └── Dimension Associations
```

The accounting amount remains on the existing `journal_lines`.

Dimension metadata should be associated separately unless repository inspection
proves that existing journal-line columns are the correct extension point.

Potential structure:

```text
journal_line_dimension_values
```

with a conceptual relationship:

```text
journal_line
     │
     └── dimension value
              │
              └── financial dimension
```

The exact schema must be decided after inspecting the repository.

---

# 12. DIMENSION CARDINALITY

Determine and document whether a journal line can have:

### Option A

Exactly one dimension value per dimension type.

Example:

```text
Journal Line
    Department = Sales
    Cost Center = CC-001
```

### Option B

Multiple values of the same dimension type.

Example:

```text
Journal Line
    Cost Centers:
        CC-001 60%
        CC-002 40%
```

For Phase 17:

**Prefer Option A unless the existing business requirements clearly require
allocations.**

Do NOT build weighted allocations merely because they might be useful someday.

If one value per dimension type is selected, enforce uniqueness:

```text
(journal_line_id, financial_dimension_id)
```

or the equivalent structure.

Document the decision.

---

# 13. DRAFT JOURNALS

Dimension values may be added/changed while a journal is still editable, subject
to the existing journal lifecycle.

The implementation must integrate with existing draft-journal behavior.

Example:

```text
DRAFT
  ↓
dimensions editable
  ↓
POST
  ↓
dimensions become immutable
```

Do not create a second draft/posting mechanism.

Use the existing journal lifecycle.

---

# 14. POSTED JOURNAL IMMUTABILITY

This is a hard accounting rule.

After a journal is posted:

- debit/credit values cannot change
- account cannot change
- date cannot change
- company cannot change
- currency cannot change
- dimension associations cannot change

A user must NOT be able to:

```text
POSTED JOURNAL
      ↓
change cost center
```

because that would rewrite historical management reporting.

If a correction is required, it must use the existing correction/reversal
mechanism.

Add tests specifically proving that dimension metadata on posted journals cannot
be changed.

---

# 15. DIMENSION VALIDATION

Before a dimension association is accepted:

- dimension must belong to the active company
- dimension must be active
- dimension value must belong to that dimension
- dimension value must belong to the active company through its parent
- dimension value must be active
- journal line must belong to the active company
- journal must be compatible with the existing lifecycle
- cross-company IDs must fail safely
- posted journals must reject dimension mutation

Never trust company IDs from request bodies.

Use existing company context.

---

# 16. ACCOUNT TYPE RULES

Do not automatically assume dimensions apply equally to every account.

Inspect the existing accounting/reporting semantics.

A reasonable default is:

- Revenue → dimension applicable
- Expense → dimension applicable

Balance-sheet dimensions should be evaluated carefully.

Do not blindly allow:

```text
Asset
Liability
Equity
```

dimension analysis if the resulting reports would be misleading.

However, do not hard-code an artificial restriction without inspecting the
existing architecture.

Document the final rule.

---

# 17. REPORTING

Phase 17 must make the dimensions useful.

At minimum implement dimension-aware reporting for the existing P&L path.

For example:

```text
GET existing P&L
    ?cost_center_id=...
```

or the repository's equivalent filter convention.

The report should continue to derive values from:

```text
POSTED JOURNAL LINES
```

with dimension filtering applied to the analytical association.

Do NOT store:

```text
cost_center_balance
cost_center_revenue
cost_center_expense
```

or similar cached financial totals.

---

# 18. COST CENTER P&L

A user should be able to request:

```text
P&L
    Company: A
    Period: January 2026
    Cost Center: Sales
```

and receive the same accounting figures as the existing P&L, filtered by the
selected dimension.

The implementation must prove that:

```text
Company P&L
```

and:

```text
Sum of dimension P&Ls
```

are logically consistent **where every relevant journal line has been assigned a
dimension**.

Do not artificially force reconciliation if unassigned journal lines are
allowed.

Instead, expose/report the unassigned amount clearly if appropriate.

---

# 19. UNASSIGNED DIMENSIONS

Determine whether a journal line may exist without a dimension.

Do NOT automatically make dimensions mandatory for every accounting line.

That could break existing modules and historical transactions.

A safe default is:

```text
Dimension assignment = optional
```

unless the repository already establishes mandatory dimensional accounting.

If optional:

Reports should distinguish:

```text
Assigned
Unassigned
```

where this improves reconciliation.

Do not silently discard unassigned posted activity.

---

# 20. BUDGET INTEGRATION

This is a major Phase 17 requirement.

Phase 16 currently has:

```text
Budget
   ↓
Budget Line
   ├── Account
   ├── Accounting Period
   └── Amount
```

Phase 17 should evaluate extending this to:

```text
Budget
   ↓
Budget Line
   ├── Account
   ├── Accounting Period
   ├── Dimension
   └── Amount
```

The objective is to support:

```text
Budget vs Actual
        +
Cost Center
```

Example:

```text
Sales Department
    Budget Expense: 100,000
    Actual Expense: 92,000
    Variance: 8,000 Favourable
```

Do not store actual amounts in budget tables.

Actuals must still come from the existing ledger.

---

# 21. BUDGET DIMENSION DESIGN

Determine whether:

- budget line references one dimension value
- budget line can contain multiple dimensions
- dimension is optional

For Phase 17:

**Prefer one optional value per supported dimension type unless a stronger
existing requirement exists.**

Do not create allocation percentages.

Do not create another budget engine.

The existing:

```text
BudgetVarianceReportService
```

should be extended rather than replaced.

---

# 22. BUDGET/ACTUAL RECONCILIATION

The dimension-aware variance report must still use:

```text
Actual = existing posted ledger activity
```

and:

```text
Variance = Actual - Budget
```

using the existing Phase 16 sign convention.

Favourability must continue to use account-type normal-side logic.

Do not introduce a different variance formula for dimensions.

---

# 23. REPORT FILTERING

Inspect existing report APIs and use their conventions.

Potential filters include:

```text
dimension_type
dimension_id
dimension_value_id
cost_center_id
```

Do not expose multiple redundant query parameters if one canonical mechanism can
handle the use case.

Prefer a coherent API such as:

```text
?dimension[cost_center]=CC-001
```

ONLY if this fits the existing API architecture.

Do not invent a new reporting query language.

---

# 24. GENERAL LEDGER

Evaluate whether the existing General Ledger can safely support dimension
filtering.

If yes, add it using the existing report service.

Example:

```text
General Ledger
    Account: Advertising Expense
    Cost Center: Marketing
```

The underlying accounting entries remain the same.

Only the analytical filter changes.

---

# 25. TRIAL BALANCE

Do NOT automatically add cost-center filtering to Trial Balance.

Inspect the accounting meaning first.

If a dimension-filtered Trial Balance can be implemented without creating
misleading totals, document and implement it.

Otherwise explicitly defer it.

Never add a report merely for symmetry.

---

# 26. BALANCE SHEET

Do not automatically add dimension filtering to Balance Sheet.

A balance-sheet dimension report can be conceptually misleading depending on how
dimension tags are assigned.

Inspect the existing architecture and accounting semantics.

If not safe:

- do not implement it;
- document why;
- leave existing Balance Sheet unchanged.

---

# 27. AUDIT

Reuse the existing `AuditService`.

Do NOT create a new dimension audit system.

Audit at minimum:

- dimension creation
- dimension update
- dimension activation/deactivation
- dimension deletion where allowed
- dimension value creation
- dimension value update
- dimension value activation/deactivation
- dimension value deletion where allowed

For journal-line dimension assignment:

- draft changes should follow existing journal audit behavior
- posted dimension mutation must be rejected

If a new audit action is required, extend the existing `AuditAction` enum rather
than introducing free-form strings.

---

# 28. DELETION RULES

Do not allow deletion that would orphan historical accounting meaning.

For example, if a cost center is referenced by posted journal lines:

```text
DELETE COST CENTER
```

must not destroy the historical reference.

Preferred approach:

```text
ACTIVE
  ↓
INACTIVE
```

rather than destructive deletion.

If the existing master-data architecture has a proven deletion convention,
follow it.

Database `RESTRICT` should be preferred where appropriate.

---

# 29. AUTHORIZATION

Inspect existing permission naming.

Likely permissions may be conceptually similar to:

```text
accounting.dimensions.view
accounting.dimensions.create
accounting.dimensions.update
accounting.dimensions.delete
```

and potentially:

```text
accounting.dimension_values.view
accounting.dimension_values.create
accounting.dimension_values.update
accounting.dimension_values.delete
```

But:

**Do not blindly create these names.**

Follow the existing permission granularity.

The role model should preserve separation of duties.

Likely baseline:

```text
Admin
    all

Accountant
    manage

Manager
    view

Staff
    none
```

But inspect existing authorization conventions first.

If Cost Center approval is not required, do not invent an approval workflow.

---

# 30. COMPANY ISOLATION

Every dimension operation must be company scoped.

Examples:

```text
Company A
    Cost Center A1

Company B
    Cost Center B1
```

Company A must never be able to:

- read B1
- update B1
- delete B1
- attach B1 to a journal line
- attach B1 to a budget line
- filter reports using B1

Expected behavior for unauthorized cross-company IDs should follow existing
conventions, typically:

```text
404
```

rather than exposing resource existence.

Test this through actual HTTP requests.

---

# 31. ROUTE MODEL BINDING

Follow the existing company-scoped binding pattern.

If dimensions receive explicit route bindings, they must be company scoped.

For example:

```text
/api/accounting/dimensions/{dimension}
/api/accounting/dimensions/{dimension}/values/{value}
```

Do not rely solely on controller checks if the existing architecture uses scoped
bindings.

Inspect and follow the existing pattern.

---

# 32. API DESIGN

Implement only APIs justified by the phase.

Likely conceptual endpoints:

```text
GET    /api/accounting/dimensions
POST   /api/accounting/dimensions
GET    /api/accounting/dimensions/{dimension}
PUT    /api/accounting/dimensions/{dimension}
DELETE /api/accounting/dimensions/{dimension}

GET    /api/accounting/dimensions/{dimension}/values
POST   /api/accounting/dimensions/{dimension}/values
GET    /api/accounting/dimensions/{dimension}/values/{value}
PUT    /api/accounting/dimensions/{dimension}/values/{value}
DELETE /api/accounting/dimensions/{dimension}/values/{value}
```

Cost Center can be represented as:

```text
dimension.type = COST_CENTER
```

if the generic framework is selected.

Do not create duplicate:

```text
/api/cost-centers
```

and:

```text
/api/dimensions
```

management systems unless there is a compelling architectural reason.

Avoid duplicate APIs.

---

# 33. API RESPONSE DESIGN

Use existing resource conventions.

Dimension response should expose only safe fields, for example:

```text
id
code
name
type
is_active
```

Value response:

```text
id
dimension_id
code
name
is_active
```

Do not expose server-owned fields as writable.

Never trust request fields for:

- company_id
- created_by
- updated_by
- approval fields
- audit actor
- posted journal ownership

---

# 34. POSTING INTEGRATION

Inspect how journal lines are created and posted.

Dimension assignment must integrate into the existing posting transaction.

The desired conceptual sequence is:

```text
Create Draft Journal
        ↓
Create Journal Lines
        ↓
Assign Dimension Metadata
        ↓
Validate Journal
        ↓
Post Existing Journal
        ↓
Dimension Associations Become Immutable
```

Do not create:

```text
DimensionPostingService
```

or a separate dimension posting engine.

If the existing posting engine needs a small extension, extend it.

Do not duplicate it.

---

# 35. TRANSACTIONAL INTEGRITY

Dimension assignment and journal operations must respect existing transactions.

For example, posting must not result in:

```text
Journal POSTED
but
Dimension association missing
```

when the dimension was part of the valid draft transaction.

Use the existing transaction boundary.

Do not create a second transaction boundary that can produce partial accounting
state.

---

# 36. CONCURRENCY

Protect:

- dimension creation
- value creation
- update
- deletion/deactivation
- journal dimension assignment
- budget dimension assignment
- revision/approval interactions

Use existing `lockForUpdate` conventions where appropriate.

Database unique indexes must remain the final concurrency guard.

Translate expected uniqueness races into validation responses rather than 500
errors.

Test meaningful races.

Do not claim theoretical concurrency safety without a real test.

---

# 37. DATABASE DESIGN

All migrations must be additive.

Do not modify existing historical accounting data.

Potential new structures may include:

```text
financial_dimensions
financial_dimension_values
journal_line_dimension_values
```

and possibly an extension to budget lines.

But the exact design must be determined after inspection.

Use:

- foreign keys
- appropriate `ON DELETE`
- unique indexes
- indexes for report filtering
- check constraints through existing `SchemaCheck::add()`
- `DECIMAL(20,4)` where monetary fields exist

Dimension metadata itself is not monetary.

Do not add monetary balances to dimension tables.

---

# 38. INDEXING

Dimension reporting may query:

```text
journal_line
    → dimension association
    → dimension value
```

Design indexes for realistic queries.

At minimum evaluate indexes around:

```text
journal_line_id
dimension_id
dimension_value_id
```

and combinations needed for:

- company isolation
- report filtering
- uniqueness
- budget filtering

Do not add arbitrary indexes without query justification.

---

# 39. NO STORED DIMENSION BALANCES

Absolutely do NOT add:

```text
cost_center_balance
cost_center_revenue
cost_center_expense
dimension_total
dimension_actual
```

or similar persisted financial totals.

These would create another source of truth.

All financial figures must continue to derive from posted journal lines.

---

# 40. MULTI-CURRENCY COMPATIBILITY

Phase 14 already established:

- company base currency
- foreign currency journal data
- base ledger amounts
- FX rate snapshots
- realized FX
- reporting based on base amounts

Phase 17 must not modify this architecture.

Dimension reports must use the same financial amount basis as the existing
reports.

Do not:

- create dimension currencies
- create dimension exchange rates
- convert dimension amounts independently
- duplicate FX logic

If a report displays currency, use the existing company/report currency
conventions.

---

# 41. TAX COMPATIBILITY

Do not create dimension-specific tax logic.

Tax remains governed by the existing Tax Engine.

If tax-generated journal lines inherit dimension metadata, determine the safest
existing integration point.

Do not duplicate tax calculations.

---

# 42. FIXED ASSETS COMPATIBILITY

Inspect whether fixed-asset-generated journals can carry dimension metadata.

Do not create special fixed-asset dimension accounting.

If dimensions can be assigned naturally through existing journal generation,
support that.

Otherwise document the limitation.

---

# 43. CASH / BANK COMPATIBILITY

Inspect whether cash/bank transactions can carry dimensions.

Do not create a second cash/bank dimension engine.

Dimension metadata must remain attached to the existing journal lines.

---

# 44. REPORTING PERFORMANCE

Avoid N+1 queries.

A dimension-aware report should use:

- joins
- constrained eager loading
- grouped queries
- existing `LedgerService` abstractions

where appropriate.

Do not rewrite the reporting engine.

If extending `JournalReportService` is appropriate, extend it in a
backward-compatible way.

Existing reports without dimension filters must produce the same results as
before.

---

# 45. BACKWARD COMPATIBILITY

This is mandatory.

Existing API calls such as:

```text
GET /api/accounting/reports/profit-loss
```

must continue working exactly as before.

Adding dimension filtering must not change results when no dimension filter is
supplied.

This invariant must be tested.

---

# 46. TEST REQUIREMENTS

Create focused feature tests.

At minimum test:

## Dimension lifecycle

- create dimension
- update dimension
- list dimensions
- show dimension
- activate/deactivate
- delete where allowed
- duplicate code rejected
- cross-company access rejected

## Dimension values

- create value
- update value
- list values
- duplicate value code rejected
- inactive dimension rejected
- inactive value rejected
- cross-company value rejected

## Authorization

Test every relevant permission through actual HTTP endpoints:

- Admin
- Accountant
- Manager
- Staff

Do not test only the policy class.

## Journal integration

Test:

- draft journal accepts dimension assignment
- draft dimension can be changed
- posted journal dimension cannot be changed
- cross-company dimension rejected
- inactive dimension rejected
- inactive value rejected
- dimension value from another dimension rejected

## Reporting

Test:

- normal P&L remains unchanged without filter
- P&L filtered by cost center
- correct revenue
- correct expense
- correct net result
- draft journals ignored
- other-company journals ignored
- date/period filtering preserved
- unassigned activity handled according to the chosen design

## Budget integration

If budget dimensions are implemented:

- dimension can be attached to budget line
- foreign dimension rejected
- inactive dimension rejected
- approved budget remains immutable
- revision copies dimension metadata
- budget-vs-actual filtered by dimension
- actual still comes from posted ledger
- variance calculation remains correct
- favourability remains correct

## Immutability

Explicitly test that:

```text
POSTED JOURNAL
    ↓
dimension mutation
```

is rejected.

## Security

Test:

- ID tampering
- cross-company access
- nested-resource tampering
- unauthorized report filters
- server-owned field injection
- mass assignment attempts

---

# 47. ACCOUNTING INTEGRITY TESTS

Extend the existing accounting control philosophy where appropriate.

Potential new control:

```text
INVALID_DIMENSION_REFERENCE
```

if useful.

Potential checks:

- dimension association references existing dimension value
- dimension value belongs to correct dimension
- dimension belongs to correct company
- posted journal dimension references are valid
- no orphaned dimension associations
- no cross-company dimension references

Do NOT automatically create a control for every validation already enforced by
foreign keys.

Controls should identify meaningful accounting integrity conditions.

Reuse:

```text
AccountingControlService
```

rather than creating:

```text
DimensionControlService
```

unless a genuinely separate service boundary is necessary.

---

# 48. AUDIT + IMMUTABILITY

Historical dimension metadata is part of the meaning of posted financial data.

Therefore:

```text
Posted accounting + dimension metadata
```

must be treated as immutable.

If a dimension itself becomes inactive later, historical reports must still be
able to identify the old value.

Therefore:

**INACTIVE ≠ deleted historical identity**

Do not make historical reports lose their dimension names because a master
record is deactivated.

---

# 49. REVERSALS / CORRECTIONS

Inspect the existing reversal/correction mechanisms.

When a posted journal is reversed:

- follow the existing reversal behavior
- dimension metadata should be handled consistently
- do not manually duplicate dimensions outside the existing accounting flow

The reversal must remain auditable.

Do not create a special Phase 17 reversal mechanism.

---

# 50. SEEDERS

**DO NOT RUN SEEDERS.**

Do not add automatic demo dimensions or cost centers unless explicitly required
by the repository's existing test infrastructure.

Factories for tests are acceptable if consistent with existing patterns.

Production seeders must not be executed.

---

# 51. FRONTEND

**NO FRONTEND WORK IN PHASE 17.**

Do not implement:

- Next.js pages
- dashboards
- React components
- Tailwind UI
- frontend state management
- frontend charts

This phase is backend/API/accounting architecture only.

Frontend integration will happen later.

---

# 52. DOCUMENTATION

Update documentation only where necessary.

At minimum produce:

```text
docs/report/PHASE_17_REPORT.md
```

The report must document:

1. Executive summary
2. Existing architecture inspected
3. Hard-stop assessment
4. Final dimension architecture decision
5. Database schema
6. Cost Center design
7. Journal integration
8. Budget integration
9. Reporting integration
10. Authorization
11. Company isolation
12. Audit
13. Accounting controls
14. Concurrency
15. API endpoints
16. Tests
17. Full regression
18. Migration verification
19. Known limitations
20. Deferred features
21. Files changed
22. Final status

---

# 53. FINAL REPORT MUST STATE EXACT COUNTS

Before declaring completion, run:

```bash
php artisan test
```

and report exact:

```text
Tests
Assertions
Failures
Errors
Skipped
```

Compare against:

```text
Phase 16 baseline:
1,208 tests
5,358 assertions
0 failures
0 errors
0 skipped
```

Also run:

```bash
vendor/bin/pint --test
```

or the repository's established equivalent.

Verify migrations:

```bash
php artisan migrate:status
```

and report:

```text
Pending migrations: 0
```

if that is actually true.

Never invent test counts.

---

# 54. REGRESSION EXPECTATION

The final test count should be:

```text
>= 1,208 tests
>= 5,358 assertions
0 failures
0 errors
0 skipped
```

The exact expected increase depends on the tests actually added.

Do not artificially manipulate test counts.

---

# 55. PERFORMANCE VERIFICATION

Verify that existing reports without dimension filters remain functionally
equivalent.

Where practical, inspect generated SQL or query structure for dimension-aware
reports and ensure there are no obvious N+1 queries.

Do not prematurely optimize with caching or materialized balances.

---

# 56. SECURITY REQUIREMENTS

Verify:

- company ownership cannot be forged
- dimension IDs cannot cross companies
- dimension values cannot cross dimensions
- posted journals cannot have dimensions changed
- approved budgets cannot be mutated
- server-owned fields are protected
- authorization is enforced server-side
- nested resource IDs are scoped correctly
- no sensitive data leaks through validation errors
- no stack traces are returned
- no secrets appear in logs/audit metadata
- mass assignment cannot modify protected fields

---

# 57. ERROR HANDLING

Use existing API error conventions.

Expected business-rule failures should be represented consistently, normally as
validation/business errors.

Do not expose raw:

- SQL errors
- stack traces
- database internals

Translate expected uniqueness/concurrency violations into meaningful API
responses.

---

# 58. IMPLEMENTATION ORDER

Follow this sequence:

## STEP 1 — Repository inspection

Inspect the entire relevant accounting architecture.

Do not modify files yet.

## STEP 2 — Architectural decision

Document:

- generic dimension vs dedicated Cost Center
- dimension/value structure
- journal-line association
- cardinality
- unassigned behavior
- account applicability
- budget integration
- reporting integration

## STEP 3 — Database design

Create only necessary additive migrations.

## STEP 4 — Models / enums

Implement models, relationships, casts, scopes and enums.

## STEP 5 — Dimension services

Implement lifecycle and validation services.

## STEP 6 — Journal integration

Integrate dimensions into existing draft journal behavior and posting.

## STEP 7 — Budget integration

Extend Phase 16 budget lines only if the final architecture supports it cleanly.

## STEP 8 — Reporting

Extend existing reporting services without creating another reporting engine.

## STEP 9 — Authorization

Add permissions and policies according to existing conventions.

## STEP 10 — Audit

Reuse existing AuditService.

## STEP 11 — Accounting controls

Extend AccountingControlService only where meaningful.

## STEP 12 — API

Add REST endpoints and resources.

## STEP 13 — Tests

Add comprehensive feature/security/integrity/concurrency tests.

## STEP 14 — Regression

Run focused accounting tests first, then the complete suite.

## STEP 15 — Code style

Run Pint.

## STEP 16 — Migration verification

Verify all migrations and zero pending migrations.

## STEP 17 — Final review

Inspect changed files for:

- duplicate logic
- second sources of truth
- unsafe mass assignment
- missing company scoping
- missing authorization
- posted-data mutation
- N+1 queries
- unnecessary abstractions
- premature future features

## STEP 18 — Final report

Create:

```text
docs/report/PHASE_17_REPORT.md
```

---

# 59. DO NOT DO THESE THINGS

Do NOT:

- rewrite the accounting engine
- rewrite LedgerService
- create a second ledger
- create dimension balances
- create a second posting engine
- create a second reporting engine
- create a second audit engine
- create a second currency engine
- create a second tax engine
- modify posted journal amounts
- modify posted dimension associations
- add balance-sheet budgeting casually
- add multi-currency budgeting casually
- add forecasting
- add scenario planning
- add weighted dimension allocations without demonstrated need
- add department/project/branch modules unnecessarily
- add frontend
- run seeders
- rewrite existing migrations
- modify historical accounting data
- weaken existing tests
- remove failing tests to achieve green
- suppress accounting integrity errors
- introduce FLOAT/DOUBLE for money
- add caching before proving it is necessary
- over-engineer a generic reporting DSL

---

# 60. IMPORTANT DESIGN PHILOSOPHY

Prefer:

```text
existing service
    +
small safe extension
```

over:

```text
new parallel service
```

Prefer:

```text
existing journal
    +
dimension metadata
```

over:

```text
dimension journal
```

Prefer:

```text
existing ledger
    +
dimension filtering
```

over:

```text
dimension ledger
```

Prefer:

```text
existing BudgetVarianceReportService
    +
dimension filtering
```

over:

```text
new BudgetDimensionEngine
```

Prefer:

```text
inactive
```

over destructive deletion when historical accounting depends on the record.

Prefer a smaller correct Phase 17 over a larger architecture that creates future
migration risk.

---

# 61. DEFINITION OF DONE

Phase 17 is complete only when all of the following are true:

### Architecture

- [ ] Existing accounting engine remains the only accounting engine.
- [ ] Existing ledger remains the only ledger.
- [ ] Existing posting engine remains authoritative.
- [ ] Dimensions are analytical metadata.
- [ ] No stored dimension financial balances exist.

### Cost Centers / Dimensions

- [ ] Financial dimension architecture is implemented.
- [ ] Cost Center is supported.
- [ ] Dimension values are company scoped.
- [ ] Activation/deactivation works.
- [ ] Historical references remain valid.
- [ ] Duplicate codes are prevented.

### Journal Integration

- [ ] Draft journals can receive dimensions where supported.
- [ ] Dimension assignments participate in existing transaction boundaries.
- [ ] Posted journal dimension metadata is immutable.
- [ ] Reversal/correction follows existing accounting architecture.

### Reporting

- [ ] P&L supports appropriate dimension filtering.
- [ ] Existing P&L without filters remains unchanged.
- [ ] Actuals derive from posted journals.
- [ ] No actuals are stored in dimension tables.
- [ ] Unassigned activity is handled explicitly.

### Budget Integration

- [ ] Budget dimensions are implemented only if architecturally safe.
- [ ] Budget actuals still derive from the ledger.
- [ ] Budget variance logic remains consistent with Phase 16.
- [ ] Approved budgets remain immutable.

### Security

- [ ] Company isolation is enforced.
- [ ] Cross-company access returns the established safe response.
- [ ] Server-owned fields are protected.
- [ ] Policies are explicitly registered.
- [ ] Nested resources are safely scoped.

### Audit

- [ ] Dimension lifecycle actions are audited.
- [ ] Existing AuditService is reused.
- [ ] No second audit system exists.

### Controls

- [ ] Meaningful dimension integrity controls are integrated into
      AccountingControlService.
- [ ] No automatic repair is introduced.

### Testing

- [ ] Feature tests pass.
- [ ] Authorization tests pass.
- [ ] Company isolation tests pass.
- [ ] Posted immutability tests pass.
- [ ] Reporting tests pass.
- [ ] Budget integration tests pass if applicable.
- [ ] Concurrency tests pass.
- [ ] Full regression passes.

### Quality

- [ ] `php artisan test` passes.
- [ ] `vendor/bin/pint --test` passes.
- [ ] `php artisan migrate:status` has 0 pending migrations.
- [ ] No seeders were run.
- [ ] No frontend was modified.
- [ ] No unnecessary files were changed.
- [ ] `docs/report/PHASE_17_REPORT.md` exists.

---

# 62. FINAL STATUS RULE

Do not declare:

```text
PASS
```

merely because the feature appears to work.

Use:

```text
PASS
```

only when all acceptance criteria are genuinely satisfied.

Use:

```text
PASS WITH NOTES
```

when the phase is functionally complete but has intentional documented
limitations.

Use:

```text
BLOCKED
```

if a hard-stop condition prevents safe implementation.

Use:

```text
FAIL
```

if the implementation violates an architectural or accounting integrity
requirement.

---

# 63. FINAL RESPONSE FORMAT

After implementation, provide a concise final response containing:

## Phase 17 Status

```text
PASS
```

or:

```text
PASS WITH NOTES
```

or:

```text
BLOCKED
```

## Summary

What was implemented.

## Architecture Decision

Explain the final financial-dimension architecture and why it was selected.

## Accounting Integrity

Confirm that:

- existing ledger remains authoritative
- no second accounting engine exists
- posted journals remain immutable
- dimensions are analytical metadata

## Budget Integration

Explain exactly how Phase 16 budgeting was integrated, or why it was
intentionally deferred.

## API

List new/changed endpoints.

## Security

Summarize company isolation and authorization.

## Tests

Report exact:

```text
Tests:
Assertions:
Failures:
Errors:
Skipped:
```

## Regression

Compare with:

```text
Phase 16:
1,208 tests
5,358 assertions
0 failures
```

## Migrations

Report exact migration status.

## Limitations

List only genuine remaining limitations.

## Deferred Features

List features intentionally deferred to future phases.

## Files Changed

List added and modified files.

---

# FINAL INSTRUCTION

Take your time.

**Inspect first. Design second. Implement third. Test fourth. Report last.**

Do not rush into coding.

Do not assume the suggested schema is correct until the repository has been
inspected.

Do not create duplicate accounting infrastructure.

Do not compromise posted accounting immutability.

Do not compromise company isolation.

Do not compromise monetary precision.

Do not run seeders.

Do not implement frontend work.

Do not silently expand the scope.

If the existing architecture provides a better, simpler, safer solution than the
conceptual design in this prompt, prefer the existing architecture and document
the decision.

The objective is not merely to "add cost centers".

The objective is to establish a **clean, extensible, auditable
financial-dimension foundation that integrates with the existing accounting
ledger and Phase 16 budgeting without requiring architectural rework later.**
