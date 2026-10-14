# PHASE 18 — BACKEND COMPLETION AUDIT, INTEGRATION VERIFICATION & PRODUCTION HARDENING

## Role

Act as a senior Laravel architect, accounting-system engineer, application
security reviewer, and QA engineer.

You are working on an existing international-ready Accounting Web Application.
Your task is to conduct the final comprehensive backend audit, identify genuine
gaps, implement necessary fixes, verify the entire system, and prepare a formal
backend completion report.

**This is a completion and hardening phase, not a new feature-development
phase.**

Inspect the actual repository before making decisions. Do not assume a feature
is missing merely because it is not mentioned in this prompt. Existing source
code, migrations, tests, API routes, and completed phase reports are
authoritative.

---

## 1. Project Architecture — Locked Decisions

The following architecture must remain unchanged unless a genuine, demonstrable
blocker makes a change unavoidable. If a blocker is discovered, document it and
explain the consequences before making architectural changes.

- Backend: Laravel 13.
- PHP: 8.4+.
- Database: MySQL 8.4 LTS, InnoDB, utf8mb4.
- API: REST.
- Authentication: existing JWT implementation.
- Frontend: Next.js, React, TypeScript, Tailwind CSS — implemented separately
  after backend sign-off.
- Accounting basis: accrual accounting.
- Accounting model: double-entry accounting.
- Money: DECIMAL-based arithmetic; never FLOAT or DOUBLE for financial
  calculations.
- Company isolation: existing CompanyContext and company-scoped authorization.
- Multi-currency: existing company base-currency and foreign-exchange
  architecture.
- Accounting source of truth: existing posted journal entries.
- Posted financial records: immutable; corrections use existing
  reversal/correction mechanisms where supported.
- Fiscal periods: existing OPEN/CLOSED lifecycle. Do not introduce a LOCKED
  state.
- Tax: existing separate Tax Engine integrated with the accounting engine.
- Audit: existing audit infrastructure.
- Authorization: server-side permissions and policies.
- Reports and dashboard data: derive from existing authoritative services and
  APIs.
- PDF generation: server-side when implemented.
- Testing: preserve and extend unit, feature, accounting-integrity, security,
  and concurrency tests.

Do not replace or redesign the established architecture.

---

## 2. Completed Phase Baseline

The project has completed Phases 1–17:

1. Backend initialization.
2. Authentication, users, and roles.
3. Company management and system settings.
4. Accounting foundation.
5. Chart of Accounts.
6. Accounting reports and financial statements.
7. Cash and banking.
8. Existing sales/purchase-related modules — inspect the repository to confirm
   exact implemented scope.
9. Bank reconciliation.
10. Tax Engine.
11. Credit and debit notes.
12. Fixed assets.
13. Audit and accounting controls.
14. Multi-currency and foreign-exchange accounting.
15. Period-end, year-end closing, and opening-balance review.
16. Budgeting and budget variance analysis.
17. Cost centers and financial dimensions.

### Latest reported baseline

Phase 17 reported:

- 1,208 tests.
- 5,366 assertions.
- 0 failures.
- 0 errors.
- 0 skipped.
- 0 pending migrations.
- Pint passed.

Treat these figures as the reported baseline, not as proof of the current
repository state. Re-run the relevant commands and record the actual results.

The objective is to preserve all working functionality and improve the backend
only where repository evidence demonstrates a real gap.

---

## 3. Mandatory Rules

1. Inspect first; implement second.
2. Do not rush.
3. Do not rewrite working modules just to improve code style or architecture.
4. Do not create duplicate services, engines, models, or sources of truth.
5. Do not create a second ledger, journal engine, posting engine, audit engine,
   currency engine, tax engine, or reporting engine.
6. Do not store balances that should be derived from authoritative posted
   journal entries.
7. Do not mutate posted financial transactions to repair historical data.
8. Do not rewrite historical accounting records or migrations to make the audit
   easier.
9. Do not weaken, delete, skip, or loosen existing tests to achieve a passing
   result.
10. Do not use FLOAT or DOUBLE for financial calculations.
11. Do not introduce unnecessary dependencies.
12. Do not introduce speculative modules or features.
13. Do not implement frontend code in this phase.
14. Do not run seeders or destructive database operations without explicit
    permission.
15. Do not modify production data.
16. Do not claim an integration works without inspecting the implementation and
    testing it.
17. Do not claim that a security or accounting issue is fixed without a
    regression test where practical.
18. Do not treat a passing test suite as proof that every business workflow is
    complete.
19. Preserve backward compatibility unless a necessary fix requires a documented
    change.
20. Report all unresolved issues honestly.

### Hard-stop conditions

Stop the affected change and document the issue if a proposed fix would require:

- A second accounting or posting engine.
- A second authoritative source of financial balances.
- Mutation of posted journals or posted historical records.
- An unapproved change to the core accounting architecture.
- Weakening company isolation or authorization.
- Silent changes to historical financial results.
- An incompatible database or API redesign without an approved migration plan.

Do not stop the entire audit because one area has a limitation. Continue with
independent, safe verification tasks.

---

## 4. Stage A — Repository and Architecture Discovery

Before editing anything, inspect:

- Project documentation and completed phase reports.
- Composer dependencies and PHP/Laravel versions.
- Database configuration and migration history.
- Models, relationships, casts, scopes, policies, and observers.
- API routes, controllers, requests, resources, middleware, and exception
  handling.
- Services and transactions.
- Authentication and permission configuration.
- CompanyContext and tenant isolation.
- JournalService and JournalPostingService.
- JournalLine and authoritative ledger/reporting services.
- Accounting periods and financial years.
- Tax, FX, cash/bank, reconciliation, credit/debit notes, fixed assets, budgets,
  and dimensions.
- AuditService and AccountingControlService.
- Existing factories and tests.
- Logging, configuration, file storage, and deployment-related settings.
- Existing TODOs, incomplete implementations, and explicitly deferred features.

Run `git status` and inspect existing changes before editing.

**Do not discard, reset, overwrite, or reformat unrelated user changes.**

Create an internal inventory of:

- Implemented and verified features.
- Implemented features that need integration verification.
- Genuine missing requirements.
- Known limitations that were deliberately deferred.
- Potential defects.
- Areas that cannot be verified from the current repository.

Do not automatically classify a deliberately deferred feature as a defect.

---

## 5. Stage B — Baseline Verification

Run the project's existing test suite and coding-style checks using the
repository's established commands.

At minimum, where supported:

- `php artisan test`
- `vendor/bin/pint --test`
- `php artisan migrate:status`

Inspect the actual database configuration before running migrations or other
database commands.

Do not run `migrate:fresh`, database resets, destructive migrations, seeders, or
commands that erase user data.

Record:

- Test count.
- Assertion count, if available.
- Failures.
- Errors.
- Skipped tests.
- Style-check result.
- Migration status.
- Environment limitations.
- Any baseline failures that existed before your changes.

If the current baseline fails, investigate before attributing the failures to
this phase. Preserve the distinction between pre-existing failures and
regressions.

---

## 6. Stage C — Accounting Integrity Audit

Verify the existing accounting architecture without replacing it.

### 6.1 Double-entry and journal posting

Inspect and test:

- Debits equal credits for every posted journal.
- Draft journals cannot affect authoritative posted-ledger reports.
- Only valid journal lifecycle transitions are accepted.
- Posting validates required account, company, period, and financial data.
- Posted journals and lines cannot be changed or deleted through ordinary
  application workflows.
- Corrections follow the existing reversal or correction design.
- Journal numbering and posting are safe under concurrent requests.
- Failed posting transactions do not leave partial accounting records.
- Reports derive actual accounting figures from posted journal entries.

### 6.2 Financial periods and year-end

Verify:

- Closed periods reject prohibited financial postings.
- Closing checks use the existing AccountingControlService and closing workflow.
- Year-end processing does not create duplicate profit or retained-earnings
  effects.
- Existing balance-sheet carry-forward and retained-earnings semantics remain
  consistent.
- No redundant opening balances or stored balances are introduced.
- Reopen behavior follows the existing approved lifecycle and audit rules.

### 6.3 Financial precision

Verify:

- Financial database columns use suitable DECIMAL types.
- Financial calculations use the existing exact-arithmetic approach.
- Rounding is explicit and consistent with existing currency precision.
- No monetary computation depends on PHP floats.
- Any presentation-only conversion is isolated from accounting calculations.

Do not redesign monetary calculations unless an actual defect is demonstrated.

---

## 7. Stage D — Cross-Module Integration Audit

Verify the existing modules against one another. Reuse existing services and
posting workflows.

### 7.1 Sales, purchases, and credit/debit notes

Inspect the implemented scope and verify:

- Documents use correct company and customer/supplier relationships.
- Posting follows existing journal and tax integration.
- Credit/debit notes cannot exceed permitted adjustment limits.
- Source documents remain immutable where required.
- Settlements and reports use authoritative posted amounts.
- Partial and full adjustments behave correctly.

Do not invent new sales or purchasing workflows if they are outside the current
implementation.

### 7.2 Tax Engine

Verify:

- Existing effective-dated rates and tax snapshots remain consistent.
- Tax calculations do not silently change historical posted amounts.
- Tax account mappings are respected.
- Tax base amounts are consistent with the existing FX design.
- Existing reports and journal postings use the same authoritative values.

Country-specific filing, e-invoicing, and other explicitly deferred compliance
features are not automatically required for sign-off.

### 7.3 Multi-currency and FX

Verify:

- Company base currency is respected.
- Exchange rates are dated and company-scoped as designed.
- Foreign-currency journal and document values reconcile with their
  base-currency accounting values.
- Posting revalidates applicable FX data according to existing rules.
- Settlement and realized FX calculations use the existing accounting design.
- FX fields remain provenance and calculation data, not a second ledger.
- Currency and exchange-rate permissions are enforced.

Do not add unrealized FX revaluation or other explicitly deferred functionality
merely because it is absent.

### 7.4 Cash, bank, and reconciliation

Verify:

- Cash and bank transactions use the existing posting engine.
- Bank reconciliation uses the appropriate company, account, and date scopes.
- Reconciliation calculations agree with the existing ledger movements.
- Reconciliation completion and reopening follow the established lifecycle.
- No hidden balance store or duplicate adjustment engine exists.

### 7.5 Fixed assets

Verify:

- Capitalization, depreciation, and disposal use the existing journal engine.
- Asset records and depreciation schedules reconcile with their posted
  accounting entries.
- Gain/loss treatment follows the existing implementation.
- Closed-period restrictions, company isolation, and immutability are enforced.

### 7.6 Budgeting

Verify:

- Budgets are planning records, not accounting transactions.
- Actuals derive from posted journals.
- Approved budgets cannot be modified through alternative API routes.
- Budget revisions follow the existing lifecycle.
- Budget variance calculations use consistent signs and direction-aware
  favourability.
- A zero budget does not produce an invalid or misleading variance percentage.

### 7.7 Financial dimensions and cost centers

Verify:

- Dimensions and values are company-scoped.
- Dimension types and values are validated against their parent relationships.
- Journal-line dimension associations persist correctly.
- Posted journal dimensions cannot be changed through application workflows.
- Inactive dimensions cannot be assigned to new transactions where the existing
  policy requires active values.
- Historical reporting retains valid associations.
- Budget-line dimensions work consistently with the budget and variance
  services.
- Dimension-filtered reports use the same filters for actual and budget amounts
  where applicable.

Specifically investigate whether Phase 17 completed dimension filtering in the
Profit & Loss and Budget Variance reports. If the functionality is absent and is
part of the intended Phase 17 scope, implement the smallest compatible fix and
add regression tests.

Do not silently assume the functionality exists simply because the migrations
were applied.

---

## 8. Stage E — Reporting and API Completeness

Inspect the actual API and determine whether it is sufficient for the existing
modules and planned frontend.

Verify:

- Trial Balance.
- General Ledger.
- Profit & Loss.
- Balance Sheet.
- Customer and supplier statements.
- Receivables and payables.
- Aged receivables and payables.
- Cash/bank activity.
- Bank reconciliation data.
- Tax reports.
- Fixed asset register and depreciation schedules.
- Credit/debit note reporting.
- Multi-currency reporting.
- Budget variance reporting.
- Audit logs and accounting controls.
- Dimension-aware reporting where implemented or required.

For each report, verify authorization, company isolation, date and period
filters, posted-entry authority, pagination where appropriate, and consistency
with the accounting source of truth.

Respect known limitations documented by previous phases. Do not implement
consolidation, comparative reporting, cash-flow statements, or other new report
families solely to make the inventory longer.

### API review

Inspect:

- Request validation.
- Resource/response consistency.
- Pagination and filtering.
- HTTP status codes.
- Authorization failures.
- Validation error structures.
- Not-found behavior for cross-company resources.
- Route-model binding and resource scoping.
- API compatibility and naming consistency.

Do not rename or redesign existing endpoints without a demonstrated need.

Produce an endpoint inventory for the frontend team, documenting each relevant
route, HTTP method, purpose, authorization requirement, important filters, and
response format. Derive it from the actual routes and implementation.

---

## 9. Stage F — Security and Company Isolation

Review the backend for common and accounting-specific security risks.

Verify:

- Every company-owned resource is scoped correctly.
- Users cannot access another company's documents by changing an ID.
- Request payloads cannot override server-owned company IDs, posting status,
  journal numbers, base amounts, or other protected fields.
- Policies and permissions are enforced on every relevant route.
- Authentication and password recovery use the existing secure design.
- Sensitive tokens, credentials, and secrets are not written to logs.
- Validation and exception handling do not expose stack traces or sensitive
  internals in production responses.
- File uploads, exports, and downloads are authorized if supported.
- Mass assignment and insecure direct object references are tested.
- Concurrent operations cannot bypass accounting invariants.

Review role behavior for Admin, Accountant, Manager, and Staff against the
actual permission matrix. Do not assume the intended matrix matches the
implemented one; document and verify the repository's authoritative
configuration.

Do not weaken authorization for convenience.

---

## 10. Stage G — Audit Trail and Accounting Controls

Verify:

- Financially significant actions use the existing audit infrastructure.
- Audit logs are read-only through normal application APIs.
- Company scoping is correct.
- Sensitive data is redacted.
- Request or correlation identifiers are preserved where supported.
- Audit events are written transactionally where appropriate.
- Accounting controls detect supported integrity problems without mutating
  financial data.
- Audit and control endpoints require their existing permissions.
- New fixes add appropriate audit events when required by established
  conventions.

Do not create a second audit or control subsystem.

---

## 11. Stage H — Performance and Reliability

Look for concrete performance or reliability problems:

- N+1 queries.
- Unbounded collection loading.
- Missing or ineffective indexes on demonstrated hot paths.
- Repeated expensive calculations.
- Inefficient report filters.
- Race conditions in numbering, posting, budget approval, settlement, or
  reconciliation.
- Transactions that can leave partial records.
- Unsafe retries or duplicate requests where relevant.

Use existing indexes and database constraints before adding new ones.

Add indexes only when justified by query patterns or query-plan evidence. Do not
introduce caching, queues, event-driven architecture, or a generic performance
framework without a demonstrated requirement.

Avoid speculative optimization.

---

## 12. Stage I — Production Configuration and Operations

Inspect production-readiness concerns that can be evaluated from the repository:

- Environment variables and example configuration.
- Debug mode and error exposure.
- Database configuration.
- JWT and application secret handling.
- Logging and sensitive-data redaction.
- Queue configuration if queues are used.
- File storage and export permissions if applicable.
- Mail configuration for existing authentication workflows.
- Migration safety.
- Health-check or operational endpoints if already supported.
- Dependency compatibility and known security advisories, using available tools
  and network access where appropriate.

Do not invent production credentials or change deployment infrastructure.

Do not claim that the application is production-ready in the hosting environment
unless that environment has actually been tested.

If deployment documentation is missing, create concise documentation based on
the actual application and supported deployment approach. Do not introduce a new
deployment platform without approval.

---

## 13. Stage J — Fixing Verified Gaps

After the audit, classify findings:

- **P0 — Critical:** accounting corruption, unauthorized cross-company access,
  or a severe security vulnerability.
- **P1 — High:** broken posting, financial calculation defects, or serious
  authorization/integration failures.
- **P2 — Medium:** required workflow gaps, API defects, reporting
  inconsistencies, or material reliability issues.
- **P3 — Low:** nonessential cleanup, documentation improvements, and cosmetic
  inconsistencies.

Only implement changes that are necessary for backend completion and supported
by repository evidence.

For each implemented defect:

1. Document the observed problem.
2. Identify the root cause.
3. Make the smallest safe fix.
4. Add a focused regression test.
5. Run the relevant module tests.
6. Run the full regression suite.
7. Verify that existing API and accounting behavior remains compatible.
8. Record the result.

If a finding requires a substantial architectural change, historical data
migration, new business module, or unresolved product decision, document it and
request approval rather than improvising.

Do not introduce unrelated features to increase the scope of Phase 18.

---

## 14. Stage K — Final Verification

After all approved fixes:

1. Run the complete test suite.
2. Run relevant focused tests for every changed area.
3. Run Pint using the repository's established command.
4. Verify migration status.
5. Inspect the final Git diff.
6. Check for unrelated modifications.
7. Verify new tests actually exercise the reported fixes.
8. Review changes for accidental financial precision or authorization
   regressions.
9. Confirm that no seeders, resets, or destructive operations were run without
   permission.
10. Confirm that no frontend implementation was introduced.

Report actual results, not expected results.

### Required completion criteria

- Existing accounting source of truth remains authoritative.
- No duplicate accounting engine or stored balance source is introduced.
- Posted transaction immutability is preserved.
- Company isolation and server-side authorization are tested.
- Financial calculations retain appropriate precision.
- Verified critical and high-priority defects are fixed or explicitly documented
  as blockers.
- Existing module integrations are verified to the extent supported by the
  repository.
- Dimension and budget integrations are verified.
- Existing tests remain intact, with necessary additions.
- Full regression results are recorded.
- Pint and migration checks are reported.
- All known limitations and deferred features are documented.
- The final Git diff contains no unrelated changes.

Do not claim complete sign-off if critical issues remain unresolved.

---

## 15. Documentation Deliverables

Create:

`docs/report/PHASE_18_REPORT.md`

The report must contain:

1. Executive summary.
2. Repository and architecture inspected.
3. Baseline test and migration results.
4. Audit scope and methodology.
5. Accounting integrity findings.
6. Cross-module integration findings.
7. Reporting and API findings.
8. Security and company-isolation findings.
9. Audit and controls findings.
10. Performance and reliability findings.
11. Production configuration findings.
12. Issues discovered, prioritized by severity.
13. Fixes implemented and regression tests added.
14. Issues not fixed and the reason for each.
15. Explicitly deferred features that remain out of scope.
16. Final test, assertion, failure, error, skipped-test, Pint, and migration
    results.
17. Files changed and why.
18. Database changes, if any.
19. Backward-compatibility assessment.
20. Frontend handoff readiness.
21. Final status: PASS, PASS WITH NOTES, or BLOCKED.

Use evidence from the actual repository and test output. Do not invent results.

---

## 16. Frontend Handoff Deliverable

Create or update:

`docs/FRONTEND_API_HANDOFF.md`

Only if this documentation does not already exist or requires meaningful
updates.

Include:

- Authentication flow and token handling requirements.
- User and company context selection.
- Actual available routes grouped by module.
- Required permissions for each relevant operation.
- Request and response examples taken from the actual implementation.
- Validation and error-response conventions.
- Pagination, sorting, and filtering support.
- Report filters and date semantics.
- Currency and base-currency presentation considerations.
- Budget and dimension-filtering support.
- Relevant limitations and deferred capabilities.

Do not expose secrets or production credentials.

Do not implement frontend components in this phase.

---

## 17. Final Response Format

When finished, report:

- **Status:** PASS / PASS WITH NOTES / BLOCKED.
- **Tests:** actual tests, assertions, failures, errors, skipped tests.
- **Pint:** actual result.
- **Migrations:** actual status.
- **Issues discovered:** grouped by severity.
- **Fixes implemented:** concise list.
- **Unresolved issues:** with reasons and impact.
- **Architecture:** confirmation that the existing accounting source of truth
  was preserved.
- **Frontend readiness:** READY, READY WITH NOTES, or NOT READY, with evidence.
- **Reports:** paths to the generated documentation.
- **Files changed:** summary.

Do not repeat the original Phase 17 test count as if it were the Phase 18
result.

---

## 18. Implementation Order

Follow this order:

1. Inspect repository and Git status.
2. Read existing documentation and phase reports.
3. Establish the current test and migration baseline.
4. Build the audit inventory.
5. Verify accounting integrity.
6. Verify module integration.
7. Verify reports and API completeness.
8. Verify security and company isolation.
9. Verify audit, controls, performance, and configuration.
10. Classify findings.
11. Fix only justified issues.
12. Add regression tests.
13. Run focused and full test suites.
14. Run Pint and migration checks.
15. Review the final Git diff.
16. Prepare the Phase 18 report.
17. Prepare the frontend API handoff.
18. Make an evidence-based final sign-off decision.

## Final Instruction

The purpose of Phase 18 is to determine whether the existing backend is complete
enough to support the planned Next.js frontend reliably.

**Do not manufacture new phases or features. Do not rewrite working code. Do not
hide limitations. Do not claim completion without evidence.**

If the audit passes and no material backend gaps remain, declare the backend
implementation complete for the documented scope and recommend moving to
frontend development. If issues remain, explain precisely what prevents sign-off
and the smallest reasonable next action.
