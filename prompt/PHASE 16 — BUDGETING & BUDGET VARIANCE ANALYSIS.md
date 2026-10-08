# PHASE 16 — BUDGETING & BUDGET VARIANCE ANALYSIS

## 1. ROLE

You are implementing **Phase 16 — Budgeting & Budget Variance Analysis** for the
existing Accounting Web Application.

You are working inside:

```text
accounting-app/backend
```

This is an existing Laravel 13 accounting backend.

**Do not treat this as a greenfield project.**

Before writing any code, inspect the actual repository and the completed Phase
1–15 implementation.

The latest authoritative state is **Phase 15**.

Phase 15 completed with:

```text
1,180 tests
5,250 assertions
0 failures
0 errors
0 skipped
```

Do not assume the original phase numbering or an old blueprint accurately
describes the current repository.

The actual codebase is authoritative.

---

# 2. PRIMARY OBJECTIVE

Implement a practical, company-scoped **Budgeting and Budget Variance Analysis**
module.

The module must allow authorized users to:

1. create budget periods;
2. create budgets;
3. define budget lines by account and accounting period;
4. store planned debit/credit or income/expense amounts appropriately;
5. support multiple budget versions where justified;
6. activate/finalize a budget safely;
7. compare budget amounts against actual posted accounting amounts;
8. calculate budget variance;
9. provide budget-vs-actual reports;
10. preserve historical budget data;
11. integrate with the existing accounting reports without creating another
    ledger;
12. respect company isolation, authorization, fiscal periods, currency, audit
    and accounting controls.

The existing accounting ledger remains the **only source of truth for actual
financial amounts**.

Budget data is planning data.

It must never become a second accounting ledger.

---

# 3. IMPORTANT ARCHITECTURAL RULE

Do NOT create:

- a second ledger;
- a second journal engine;
- a second posting engine;
- stored actual account balances;
- duplicated financial transactions;
- budget journals;
- fake accounting entries for budgets;
- a parallel currency engine;
- a parallel audit system;
- a parallel permission system;
- a second reporting engine.

Budget values are **not accounting transactions**.

A budget must never create:

```text
journal
journal_line
ledger movement
account balance
```

The accounting engine remains unchanged as the source of actuals.

The budget engine stores only planning assumptions.

---

# 4. REQUIRED FIRST STEP — REPOSITORY INSPECTION

Before changing anything, inspect:

### Accounting

- Chart of Accounts
- Account model
- Journal
- JournalLine
- JournalService
- JournalPostingService
- LedgerService
- AccountingPeriodService
- FinancialYearService
- AccountingControlService
- PeriodClosingCheckService

### Reporting

- Trial Balance
- General Ledger
- Profit & Loss
- Balance Sheet
- Receivables
- Payables
- Customer/Supplier statements
- existing report request/response conventions
- existing report services

### Currency

Inspect Phase 14 implementation:

- Currency
- company base currency
- exchange rates
- Money handling
- journal FX fields
- base amounts
- currency authorization
- FX controls

Do not create another currency abstraction.

### Audit

Inspect:

- AuditLog
- AuditService
- AuditAction
- request/correlation ID conventions
- secret scrubbing

Use the existing audit infrastructure.

### Authorization

Inspect:

- roles
- permissions
- policies
- FormRequest authorization
- company-scoped authorization conventions

Do not create a second permission system.

### Company Context

Inspect:

- CompanyContext
- company-scoped route binding
- company membership
- cross-company 404 behavior

Follow the existing company isolation pattern.

### Database

Inspect:

- migration conventions
- decimal precision
- foreign keys
- indexes
- CHECK constraints
- unique constraints
- deletion behavior

Do not blindly introduce tables before understanding the existing schema.

### Tests

Inspect existing:

- Accounting tests
- Currency tests
- Report tests
- Authorization tests
- company-isolation tests
- concurrency tests
- audit tests

Follow established testing style.

---

# 5. AUTHORITATIVE PREVIOUS-PHASE RULE

Phase 15 deliberately did **not** create an opening-balance subsystem or P&L
closing journal because the existing ledger and reporting architecture already
derives those figures.

Do not reintroduce either system as part of budgeting.

Phase 15 also explicitly deferred:

- budgeting;
- forecasting;
- external integrations;
- consolidation;
- tax filing/compliance;
- e-invoicing;
- payroll;
- inventory;
- manufacturing;
- CRM;
- frontend dashboard.

Phase 16 should focus only on **Budgeting & Budget Variance Analysis**.

---

# 6. SCOPE

Phase 16 should cover:

1. Budget master
2. Budget periods
3. Budget versions
4. Budget lines
5. Account-level budgeting
6. Optional monthly/period allocation
7. Budget activation/finalization
8. Budget immutability rules
9. Budget-vs-actual reporting
10. Variance calculation
11. Favorable/unfavorable variance interpretation
12. P&L budget comparison
13. Balance-sheet budgeting only if the existing architecture makes it safe and
    simple
14. Company isolation
15. Fiscal-period integration
16. Base-currency handling
17. Authorization
18. Auditability
19. Concurrency protection
20. Comprehensive automated testing

Do not implement every possible enterprise budgeting feature.

Keep the implementation appropriate for the current accounting application.

---

# 7. BUDGET MODEL

First determine the simplest sound model that fits the existing architecture.

A likely conceptual structure is:

```text
Budget
    ↓
Budget Version
    ↓
Budget Line
    ↓
Account
    ↓
Accounting Period
```

However:

**Do not create this exact schema blindly.**

Inspect the repository first.

Every table must have a clear responsibility.

Do not create tables merely because they are common in ERP systems.

---

# 8. BUDGET OWNERSHIP

Every budget must be company scoped.

The client must never be able to establish ownership by supplying an arbitrary:

```text
company_id
```

Company ownership must come from the established CompanyContext.

Cross-company access must follow the existing application convention.

Where applicable:

```text
another company's budget → 404
```

Do not leak whether the requested resource exists in another company.

---

# 9. BUDGET PERIODS

Budgets must align with the application's accounting periods.

Do not create a completely independent calendar system unless inspection proves
that it is necessary.

A budget line should have an unambiguous planning period.

Prefer using existing accounting-period concepts when appropriate.

Example:

```text
Budget
    FY 2027
        January
        February
        March
        ...
```

But do not assume monthly budgeting is mandatory if the existing fiscal-period
architecture supports another period granularity.

Inspect first.

---

# 10. BUDGET VERSIONS

The design should support practical versioning.

Example:

```text
FY2027 Budget
    ├── Original
    ├── Revised
    └── Forecast
```

However, Phase 16 is **not** a forecasting engine.

A version should exist only if it solves a real budgeting requirement such as:

- original approved budget;
- revised approved budget.

Do not create unnecessary workflow complexity.

Possible lifecycle:

```text
DRAFT
    ↓
APPROVED
```

If the existing application already has a suitable lifecycle convention, reuse
it.

Do not invent:

```text
DRAFT
PENDING_APPROVAL
APPROVED
LOCKED
ARCHIVED
SUPERSEDED
...
```

unless there is a demonstrated requirement.

---

# 11. BUDGET LINE

A budget line should identify at minimum:

```text
budget
account
period
amount
```

Potential additional fields:

```text
description
dimension/category
notes
currency
```

Only add fields that are actually justified.

Do not build a full multidimensional budgeting system in this phase.

---

# 12. MONEY

Budget amounts must use the same monetary precision and conventions already
established by the application.

Never use:

```text
FLOAT
DOUBLE
```

for budget amounts.

Use:

```text
DECIMAL(...)
```

consistent with the existing accounting implementation.

Do not introduce a new Money implementation if the project already has one.

---

# 13. DEBIT/CREDIT MODEL

Before implementing budget lines, inspect how the existing reports represent
account activity.

Determine whether the cleanest budget model is:

```text
budget_amount
```

combined with the account's normal balance,

or whether explicit:

```text
budget_debit
budget_credit
```

is necessary.

Prefer the simpler model if it can correctly represent:

- revenue;
- expense;
- asset;
- liability;
- equity;
- contra accounts.

Do not duplicate accounting semantics unnecessarily.

The budget must ultimately be comparable with the actual account
balance/activity produced by the ledger.

---

# 14. ACTUAL AMOUNTS

Actual amounts must come from:

```text
posted journal lines
```

through the existing accounting/reporting architecture.

Do not store:

```text
actual_amount
actual_balance
actual_total
```

inside budget records.

Do not create a background synchronization process that copies ledger balances
into budget tables.

The report should calculate:

```text
Actual
    ↓
existing ledger/reporting services
```

and:

```text
Budget
    ↓
budget tables
```

then compare them.

---

# 15. BUDGET VS ACTUAL

The central reporting capability should provide:

```text
Budget
Actual
Variance
Variance %
```

Conceptually:

```text
Variance = Actual - Budget
```

But favorable/unfavorable interpretation must depend on the account type.

For example:

### Expense

```text
Actual > Budget
```

is generally unfavorable.

### Revenue

```text
Actual > Budget
```

is generally favorable.

Do not encode this as a simplistic universal rule.

Use the account's accounting nature / normal balance and existing account
semantics.

Inspect the current Chart of Accounts implementation before deciding the exact
calculation.

---

# 16. ZERO BUDGET HANDLING

Variance percentage must handle:

```text
Budget = 0
```

safely.

Do not produce:

```text
INF
NaN
division by zero
```

Define a deterministic API representation for zero-budget variance.

For example:

```text
variance_percent = null
```

when percentage calculation is mathematically undefined.

Use the project's existing API conventions where applicable.

---

# 17. SIGN HANDLING

This is critical.

Inspect the existing Trial Balance, P&L and Ledger implementations before
implementing variance.

Do not assume:

```text
positive = revenue
negative = expense
```

or the reverse.

The existing accounting sign conventions must be respected.

Budget-vs-actual reporting must reconcile with the application's existing
financial statements.

---

# 18. PROFIT & LOSS BUDGET

Provide a budget comparison suitable for P&L analysis.

At minimum consider:

```text
Revenue
Cost / Expense
Net Profit
```

with:

```text
Budget
Actual
Variance
Variance %
```

However, reuse the existing P&L account grouping and classification logic.

Do not create a second P&L calculation engine.

The budget report should use the existing report/account classification
infrastructure wherever possible.

---

# 19. BALANCE SHEET BUDGETING

Do not automatically implement full balance-sheet budgeting.

First inspect whether the current architecture can support it correctly without
introducing significant complexity.

If balance-sheet budgeting would require:

- a separate projection engine;
- transaction simulation;
- opening balance forecasting;
- inter-account dependency modeling;
- cash-flow projection;

then explicitly defer it.

The implementation report must explain the decision.

A smaller, correct P&L budget is preferable to an incomplete pseudo-forecasting
engine.

---

# 20. FORECASTING IS OUT OF SCOPE

Do not implement:

- rolling forecasts;
- predictive forecasting;
- AI forecasting;
- statistical forecasting;
- cash-flow forecasting;
- automatic forecast generation;
- machine learning;
- trend prediction.

Phase 16 is:

```text
Budget
+
Actual
+
Variance
```

It is not:

```text
Budget
+
Forecast
+
Prediction
```

---

# 21. BUDGET APPROVAL / FINALIZATION

A finalized/approved budget must not be casually modified.

At minimum:

```text
DRAFT → APPROVED
```

After approval:

- prevent direct destructive modification;
- preserve historical values;
- use a revised version if changes are required;
- audit the transition.

Do not allow:

```text
approved budget → arbitrary update
```

without a justified revision mechanism.

If versioning makes approval complexity unnecessary, prefer the simpler design.

---

# 22. BUDGET DELETION

Deletion must be conservative.

A draft budget may be deleted if it has no dependent finalized financial
meaning.

An approved/finalized budget must not be hard deleted.

Do not cascade-delete budget history.

Prefer:

```text
draft → deletable
approved → immutable
```

or the equivalent lifecycle already established by the repository.

---

# 23. FISCAL PERIOD INTEGRATION

Budgeting must respect the existing fiscal-year/accounting-period model.

Do not create a second fiscal calendar.

Validate:

- budget period belongs to the budget's fiscal year where applicable;
- period belongs to the active company;
- dates are valid;
- duplicate budget lines cannot silently overwrite each other.

A budget may plan for a future accounting period even if that period is not
currently open for posting.

Do not incorrectly require the budget period to be OPEN merely because actual
journals require an open period.

Budget planning and journal posting have different purposes.

---

# 24. CLOSED PERIODS

A closed accounting period does not necessarily make its historical budget
invalid.

A report comparing:

```text
Budget
vs
Actual
```

for a closed period must continue to work.

Do not mutate accounting history to accommodate budgeting.

---

# 25. MULTI-CURRENCY

Phase 14 established the application's currency architecture.

Do not create another currency engine.

First determine whether Phase 16 should store:

```text
base-currency budget only
```

or:

```text
budget currency + base amount
```

The safest default is to budget in the company's base currency unless inspection
proves foreign-currency budgeting is already required.

If foreign-currency budgeting is implemented, reuse:

- Currency;
- company base currency;
- Money;
- exchange-rate conventions;
- existing authorization;
- existing FX controls.

Do not create a new exchange-rate mechanism.

---

# 26. REPORTING

At minimum provide an API for budget-vs-actual analysis.

Potential endpoint:

```text
GET /api/accounting/budgets
```

and a dedicated comparison endpoint such as:

```text
GET /api/accounting/budgets/{budget}/variance
```

Exact routes must follow existing API conventions.

Possible filters:

```text
budget
version
period
date range
account
account type
```

Only implement filters that are genuinely supported by the underlying report
design.

Do not create a generic report DSL.

---

# 27. REPORT OUTPUT

A useful response should make it possible for the future Next.js frontend to
display:

```text
Account
Period
Budget
Actual
Variance
Variance %
Status / Favorability
```

Do not build the frontend in Phase 16.

The API must nevertheless be structured cleanly for future dashboard/report
consumption.

---

# 28. DASHBOARD

Do NOT implement the Next.js dashboard in this phase.

Do not:

- install frontend dependencies;
- create React components;
- create charts;
- redesign frontend architecture.

Only expose clean backend APIs.

The existing frontend plan remains separate.

---

# 29. AUTHORIZATION

Follow the existing role and permission model.

Do not create a new role system.

Likely capabilities may include:

```text
accounting.budgets.view
accounting.budgets.create
accounting.budgets.update
accounting.budgets.approve
accounting.budgets.delete
```

But these are only candidates.

Inspect the existing permission naming convention and role matrix first.

Do not blindly add permissions.

Expected principle:

```text
Admin
    full budgeting administration

Accountant
    budgeting/accounting operations according to existing conventions

Manager
    budgeting review/approval according to existing conventions

Staff
    read-only or no access according to established authorization policy
```

Do not grant permissions arbitrarily.

All authorization must be server-side.

---

# 30. AUDIT

Use the existing AuditService.

Do not create another audit table/system.

Audit financially meaningful budget actions such as:

- budget created;
- budget updated while draft;
- budget approved/finalized;
- budget revision created;
- budget deleted where permitted.

Audit data should identify:

- company;
- actor;
- action;
- resource;
- timestamp;
- request/correlation ID;
- before/after where appropriate.

Do not log:

- passwords;
- JWTs;
- secrets;
- sensitive credentials.

---

# 31. CONCURRENCY

Budgeting must be safe under concurrent requests.

Test at minimum:

### Duplicate line creation

Two requests attempt to create the same:

```text
budget + version + account + period
```

combination.

Expected:

```text
one succeeds
one is rejected
```

### Approval race

Two requests attempt to approve the same draft budget.

Expected:

```text
one succeeds
the other is rejected safely
```

### Draft update vs approval

A concurrent update must not silently modify an already-approved budget.

Use:

- database uniqueness;
- transactions;
- row locking where appropriate;
- existing Laravel patterns.

Do not rely only on application-level checks.

---

# 32. DATABASE CONSTRAINTS

Where appropriate, enforce invariants at the database level.

Examples:

- unique budget/version identifiers within a company;
- unique budget line for its natural key;
- valid foreign keys;
- appropriate indexes;
- positive/valid amounts where applicable;
- valid lifecycle values.

Use the application's existing migration/constraint conventions.

Do not create destructive migrations.

Do not change existing financial tables unless absolutely necessary.

---

# 33. NO SECOND SOURCE OF TRUTH

This is a hard requirement.

Actual accounting data must remain:

```text
Journal
    ↓
Journal Lines
    ↓
Ledger / Reports
```

Budget data must remain:

```text
Budget
    ↓
Budget Lines
```

Comparison:

```text
Budget
     +
Existing Actual Reports
     ↓
Budget Variance Report
```

Never:

```text
Journal
     ↓
Copied Actual Table
     ↓
Budget Engine
```

---

# 34. ACCOUNTING INTEGRITY

Budgeting must never alter:

- posted journals;
- journal lines;
- account balances;
- fiscal-period state;
- tax records;
- FX records;
- fixed-asset accounting;
- invoices;
- bills;
- payments.

Creating or modifying a budget must not create accounting entries.

---

# 35. PERIOD-CLOSE INTEGRATION

Phase 15 introduced controlled period closing.

Budgeting must not weaken those controls.

A period close must continue to be based on:

- accounting controls;
- posted accounting history;
- existing closing-check logic.

Budget data must not cause a valid period to become financially uncloseable
unless the existing accounting design explicitly defines such a control.

Do not modify Phase 15 closing logic unnecessarily.

---

# 36. ACCOUNTING CONTROL INTEGRATION

Inspect `AccountingControlService`.

If budget-specific integrity checks are genuinely useful, add them through the
existing control architecture.

Potential examples:

- duplicate budget line;
- invalid budget account;
- invalid budget period;
- approved budget containing invalid references.

Do not add speculative controls.

Do not create a second control framework.

---

# 37. REPORTING RECONCILIATION

The budget-vs-actual report must reconcile to existing accounting reports.

For example:

```text
Actual P&L
```

and:

```text
Budget vs Actual P&L
```

must agree on the actual values for the same:

- company;
- period;
- account scope;
- date range.

If they disagree, the implementation is not complete.

Write explicit tests for this.

---

# 38. API SECURITY

Verify:

- authentication required;
- permission required;
- company isolation;
- route model binding;
- cross-company resources return 404;
- no client-controlled company ownership;
- no unauthorized approval;
- approved budgets cannot be mutated through hidden fields;
- mass assignment is safe;
- validation cannot bypass authorization;
- no stack traces in production;
- no secrets in audit/logging.

Follow existing security conventions.

---

# 39. SAFE MASS ASSIGNMENT

Do not make server-owned fields fillable.

Examples may include:

```text
company_id
created_by
approved_by
approved_at
status
```

where applicable.

These must be assigned by trusted server-side logic.

Do not trust:

```text
company_id
status
approved_by
approved_at
```

from request payloads.

---

# 40. TESTING REQUIREMENTS

Create comprehensive tests.

At minimum cover:

### Budget lifecycle

- create draft;
- update draft;
- approve/finalize;
- reject invalid transitions;
- approved budget immutability;
- safe deletion.

### Budget lines

- create;
- update;
- duplicate prevention;
- account validation;
- period validation;
- amount precision.

### Company isolation

- own company budget accessible;
- another company's budget returns 404;
- another company's account cannot be referenced.

### Authorization

Test every relevant role and permission.

### Budget vs actual

Test:

- zero actual;
- zero budget;
- positive variance;
- negative variance;
- revenue;
- expense;
- multiple periods;
- multiple accounts;
- closed periods;
- posted journals only;
- draft journals excluded;
- unposted documents excluded.

### Reporting reconciliation

Verify actual values equal existing accounting reports.

### Approval

- approval authorization;
- approval audit;
- concurrent approval;
- modification after approval rejected.

### Audit

Verify important budget lifecycle actions produce the expected audit records.

### Currency

If base-currency budgeting is used:

- verify base-currency semantics;
- verify company currency isolation;
- verify no unintended FX conversion.

If foreign-currency budgeting is implemented:

- test exchange-rate behavior thoroughly.

### Concurrency

Include database-backed concurrency/integrity tests consistent with the existing
test architecture.

---

# 41. NO SEEDERS

Do not create or run seeders unless explicitly requested.

Do not populate fake:

- budgets;
- budget lines;
- accounts;
- financial data.

Factories may be added if genuinely useful for automated tests.

---

# 42. NO UNNECESSARY PACKAGES

Do not install packages unless absolutely necessary.

The current project intentionally uses a minimal Laravel dependency set.

Do not add:

- spreadsheet packages;
- chart packages;
- analytics packages;
- forecasting libraries;
- reporting DSL packages;

unless the repository inspection proves an unavoidable requirement.

Prefer native Laravel/PHP/database functionality.

---

# 43. MIGRATION SAFETY

Use additive migrations.

Before modifying any existing table, ask:

```text
Can Phase 16 be implemented without modifying an existing accounting table?
```

Prefer:

```text
new budget tables
+
existing accounting tables
```

Do not alter journal or ledger schema merely for convenience.

If an existing schema modification is truly necessary:

1. explain why;
2. identify affected data;
3. create a safe migration;
4. test migration;
5. test rollback where appropriate;
6. verify existing tests.

---

# 44. PERFORMANCE

Avoid N+1 queries in:

- budget listings;
- budget line listings;
- variance reports;
- account summaries.

Use appropriate:

- eager loading;
- indexes;
- grouped queries;
- database aggregation.

Do not prematurely introduce caching.

Correctness comes first.

---

# 45. API CONVENTIONS

Follow the existing:

- route naming;
- controller structure;
- FormRequest conventions;
- Resource/response conventions;
- validation messages;
- pagination;
- error response format.

Do not invent a second API response structure.

---

# 46. DOCUMENTATION

Update documentation only where necessary.

Add:

```text
docs/report/PHASE_16_REPORT.md
```

The report must contain:

1. Executive Summary
2. Scope
3. Repository Inspection Findings
4. Existing Architecture Reused
5. Database Changes
6. Budget Domain Model
7. Lifecycle
8. Budget Line Semantics
9. Actual Amount Source
10. Variance Calculation
11. Reporting APIs
12. Authorization
13. Company Isolation
14. Audit
15. Concurrency
16. Security Review
17. Tests
18. Migration Verification
19. Files Changed
20. Known Limitations
21. Deferred Features
22. Final Status

---

# 47. PHASE REPORT REQUIREMENTS

The final report must explicitly state:

### What was implemented

Be precise.

### What was intentionally not implemented

Especially:

- forecasting;
- predictive analytics;
- cash-flow forecasting;
- consolidation;
- multidimensional budgeting;
- frontend;
- external integrations.

### What existing systems were reused

For example:

```text
LedgerService
AccountingPeriodService
FinancialYearService
Currency
AuditService
Authorization
CompanyContext
Existing report services
```

Only mention systems actually reused.

### Tests

Report exact:

```text
Phase 16 tests
Phase 16 assertions
Full test count
Full assertion count
Failures
Errors
Skipped
```

### Migration status

Report:

```text
migrate:status
pending migrations
```

### Code style

Run the project's existing Pint verification.

---

# 48. REQUIRED COMMANDS

At minimum, inspect and run appropriate commands such as:

```bash
php artisan test
php artisan migrate:status
php artisan route:list --path=api
./vendor/bin/pint --test
```

During implementation, run focused tests first, then the full suite.

Do not report tests as passing unless they were actually executed.

---

# 49. REGRESSION BASELINE

Phase 15 baseline:

```text
1,180 tests
5,250 assertions
0 failures
0 errors
0 skipped
```

Phase 16 must preserve all existing tests.

Expected result:

```text
Phase 15 tests
    +
Phase 16 tests
    =
Phase 16 full suite
```

Any regression must be investigated and fixed.

Do not simply weaken or delete existing tests.

Do not modify existing tests merely to make the suite pass.

---

# 50. HARD STOP CONDITIONS

STOP implementation and report the issue if you discover that:

1. budgeting requires a second accounting engine;
2. actual balances must be duplicated into budget tables;
3. existing ledger semantics are insufficient and would need unsafe
   modification;
4. existing accounting reports cannot provide reliable actual amounts;
5. a migration would risk existing posted financial data;
6. currency behavior cannot be implemented safely;
7. company isolation cannot be guaranteed;
8. approved budgets would require destructive historical modification;
9. a proposed design requires a new audit system;
10. a proposed design requires a new permission system;
11. the implementation would require speculative forecasting;
12. a requested feature belongs more naturally to another future phase.

Do not force implementation through an unsafe architectural problem.

Document the finding instead.

---

# 51. DO NOT REBUILD COMPLETED PHASES

Do not rewrite:

- Chart of Accounts;
- Journal Engine;
- Posting Engine;
- Ledger;
- Reports;
- Tax Engine;
- Credit/Debit Notes;
- Fixed Assets;
- Audit;
- Controls;
- Multi-Currency;
- Period Closing;
- Year Closing.

If a completed subsystem needs a small compatibility change, make the smallest
safe change and document it.

Do not refactor working accounting infrastructure without a demonstrated need.

---

# 52. DO NOT ADD FUTURE COMPLEXITY

Do not implement placeholders for:

- AI forecasting;
- machine learning;
- consolidation;
- multi-entity planning;
- workforce budgeting;
- project budgeting;
- department hierarchy;
- cost-center hierarchy;
- scenario engines;
- statistical forecasting;
- external ERP synchronization.

Design the module so these could be added later if needed, but do not build them
now.

---

# 53. IMPLEMENTATION ORDER

Follow this order:

## STEP 1 — INSPECT

Read the actual repository and Phase 15 state.

Do not code yet.

## STEP 2 — ARCHITECTURE DECISION

Determine:

- budget model;
- version model;
- period relationship;
- line uniqueness;
- amount/sign semantics;
- approval lifecycle;
- actual-report integration;
- currency behavior.

Document the decisions before implementation.

## STEP 3 — DATABASE

Create only the necessary additive migrations.

## STEP 4 — DOMAIN MODELS

Implement models and relationships.

## STEP 5 — SERVICES

Implement budgeting services using existing architecture.

Prefer domain services over putting complex financial logic in controllers.

## STEP 6 — AUTHORIZATION

Implement permissions/policies using existing conventions.

## STEP 7 — API

Implement:

- CRUD where appropriate;
- approval/finalization;
- budget-vs-actual reporting;
- filters supported by the domain.

## STEP 8 — AUDIT

Integrate with existing AuditService.

## STEP 9 — CONTROLS

Integrate only justified budget integrity checks into the existing control
architecture.

## STEP 10 — TESTS

Write focused tests.

Then run the complete regression suite.

## STEP 11 — SECURITY REVIEW

Review:

- company isolation;
- authorization;
- mass assignment;
- approved-budget immutability;
- concurrency;
- SQL/data integrity;
- audit data.

## STEP 12 — CODE QUALITY

Run:

```bash
./vendor/bin/pint --test
```

Fix issues.

## STEP 13 — FINAL REPORT

Create:

```text
docs/report/PHASE_16_REPORT.md
```

with exact implementation and test results.

---

# 54. DEFINITION OF DONE

Phase 16 is complete only when:

- [ ] budgeting architecture is documented;
- [ ] budgets are company scoped;
- [ ] budget periods align correctly with existing fiscal periods;
- [ ] budget lines are implemented;
- [ ] duplicate budget lines are prevented;
- [ ] budget amounts use exact decimal arithmetic;
- [ ] draft budgets can be managed safely;
- [ ] approved budgets are protected from arbitrary mutation;
- [ ] revisions are handled without rewriting historical approved data;
- [ ] actual amounts come from the existing accounting ledger/reporting path;
- [ ] draft/unposted accounting data is excluded from actuals;
- [ ] budget-vs-actual reporting works;
- [ ] variance calculation is correct;
- [ ] zero-budget cases are safe;
- [ ] revenue/expense favorability is handled correctly;
- [ ] reports reconcile with existing accounting reports;
- [ ] authorization is server-side;
- [ ] company isolation is enforced;
- [ ] audit events use the existing audit system;
- [ ] concurrency protection exists;
- [ ] no second ledger was created;
- [ ] no second accounting engine was created;
- [ ] no second currency engine was created;
- [ ] no second audit system was created;
- [ ] no unnecessary packages were installed;
- [ ] no seeders were run;
- [ ] migrations are verified;
- [ ] Pint passes;
- [ ] Phase 15 regression remains green;
- [ ] full test suite passes;
- [ ] Phase 16 report is complete.

---

# 55. FINAL INSTRUCTION

Take your time.

Do not rush into implementation.

First understand the actual repository.

If the repository differs from assumptions in this prompt, **the repository
wins**.

Do not blindly follow this document where existing architecture provides a
better established solution.

Prefer:

```text
existing proven architecture
        +
smallest safe addition
        +
strong tests
```

over:

```text
new framework
+
new engine
+
new abstraction
```

The goal is not to make Phase 16 look large.

The goal is to add a **correct, maintainable, auditable budgeting capability**
without destabilizing the accounting system that has already been built.

After implementation, provide a precise Phase 16 report and clearly state:

```text
PASS
PASS WITH NOTES
or
BLOCKED
```

with exact reasons.
