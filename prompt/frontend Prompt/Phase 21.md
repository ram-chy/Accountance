# Accountance Frontend — Phase 21: Production Readiness, Live Integration & Release Verification

## 1. Role and objective

Act as a senior frontend engineer, integration-test engineer, and
release-readiness reviewer for the Accountance Accounting Web App.

**Phase 21 scope: Production Readiness, Live Integration & Release Verification
— frontend-focused, final planned phase.**

Repository root:

`D:\Development\Accountance`

Frontend:

`accounting-app/frontend`

Backend:

`accounting-app/backend`

Phase 20 is reported complete. The frontend now contains authentication
recovery, company administration, user administration, fixed assets, currencies,
exchange rates, budgets, dimensions, financial reports, cash and banking, taxes,
sales and receivables, purchases and payables, credit/debit notes, and the
existing accounting foundation.

The Phase 20 report is located at:

`accounting-app/docs/report/frontend/FRONTEND_PHASE_20_REPORT.md`

Earlier reports may be under `accounting-app/docs/report/frontend/` and
`accounting-app/docs/report/backend/`. Inspect the actual repository to find the
canonical documentation paths.

The previous phase reports record successful automated tests and production
builds, but they do **not** establish that the frontend has been tested against
a running Laravel backend.

Your job is to:

1. Audit the complete frontend for release readiness.
2. Verify frontend/backend contract compatibility against actual backend source.
3. Perform safe live integration checks if a usable backend environment is
   already available.
4. Fix confirmed frontend defects that are within scope.
5. Strengthen regression and integration coverage where appropriate.
6. Verify security-sensitive frontend and BFF behavior.
7. Produce a final, evidence-based release-readiness report.

Do not claim that the application is production-ready merely because automated
tests pass.

## 2. Mandatory safety rules

### 2.1 Preserve the current repository state

Before doing anything, inspect:

- Current repository root.
- Current branch.
- Current HEAD commit and subject.
- Complete `git status --short`.
- Existing frontend and backend report locations.

The Phase 20 report records that its implementation remains uncommitted.
However, repository state may have changed since that report was written.

**The actual current Git status is authoritative.**

Preserve all existing modified, untracked, renamed, and deleted files. Do not
assume a clean working tree or a particular commit history.

Do not overwrite or replace user work while performing audits, fixing defects,
or creating reports.

### 2.2 Prohibited operations

Unless the user explicitly authorizes them separately, do not:

- Create a Git commit or push.
- Run `git reset`, `git clean`, `git stash`, `git checkout`, `git switch`,
  `git restore`, `git pull`, `git fetch`, `git rebase`, or other
  repository-changing operations that may discard or rewrite work.
- Modify any file under `accounting-app/backend/`.
- Change database schemas, migrations, seeders, or backend configuration.
- Run migrations, seeders, database resets, or destructive database commands.
- Install, remove, or upgrade dependencies.
- Change `package.json` or lockfiles.
- Disable or weaken existing lint rules, TypeScript checks, or tests.
- Delete or weaken existing tests to make the suite pass.
- Modify earlier phase reports.
- Expose credentials, passwords, JWTs, cookies, API keys, recovery tokens, or
  private environment values in terminal output, tests, logs, or documentation.

Read-only Git inspection is permitted.

### 2.3 Frontend-only implementation boundary

This is a frontend integration and release-verification phase.

You may inspect backend source read-only. You may not modify backend
implementation, routes, validation, permissions, schema, migrations, or seeders.

If a backend defect, missing endpoint, inconsistent permission, or contract gap
is found, document it precisely. Do not implement a frontend workaround that
misrepresents server behavior.

If a frontend change is required to accommodate a verified backend contract,
make the smallest safe change and add appropriate regression coverage.

## 3. Establish the actual baseline

Before editing source:

1. Read the frontend master blueprint and frontend architecture documentation.
2. Read the Phase 17–20 reports.
3. Read the backend Phase 18 audit/hardening report and any later backend
   reports.
4. Inspect the current frontend route tree and navigation.
5. Inspect API/BFF utilities, session handling, runtime validators,
   authorization checks, error handling, and request cancellation.
6. Inspect the current test configuration and all available quality scripts.
7. Record the initial Git state and all pre-existing changes.
8. Run or inspect the baseline quality gates before changing code, if practical.

If a baseline gate fails, record the exact command and failure. Determine
whether it is pre-existing before attributing it to Phase 21.

Do not begin by refactoring the whole application. Prioritize evidence-based
defects and release risks.

## 4. Build a complete frontend/backend integration inventory

Inspect actual source rather than relying solely on previous reports.

Create an inventory of every significant frontend feature and its corresponding
backend API surface.

At minimum, cover the following areas if present in the current repository:

### Authentication and application access

- Login and logout.
- Session restoration and authenticated user retrieval.
- Password recovery and reset.
- Change password.
- Email-verification resend.
- BFF session cookie management.
- Company context and switching.
- Permission-aware navigation and route access.

### Company and system administration

- Company profile and settings.
- Company creation and lifecycle.
- Company membership operations.
- User listing and administration.
- Account profile and security settings.

### Core accounting

- Chart of accounts.
- Journal creation, editing, posting, and immutability.
- Accounting periods and fiscal years.
- Period closing and reopening where supported.
- Ledger, trial balance, profit and loss, and balance sheet.

### Operational accounting

- Cash and bank accounts.
- Cash/bank transactions and posting.
- Bank reconciliations.
- Tax configuration and tax reports.
- Customers and sales invoices.
- Customer receipts and allocations.
- Suppliers and purchase bills.
- Supplier payments and allocations.
- Credit notes and debit notes.

### Advanced accounting

- Fixed assets, depreciation, and disposals.
- Currencies and exchange rates.
- FX settings and supported currency behavior.
- Financial dimensions.
- Budgets and budget variance reporting.

For every area, identify:

- Frontend routes and principal components.
- API client functions.
- Actual backend endpoint and HTTP method.
- Expected query parameters and request payload.
- Response envelope and resource shape.
- Pagination behavior.
- Relevant permissions and lifecycle restrictions.
- Automated test coverage.
- Live integration status.
- Known limitations and contract gaps.

Do not assume every feature in this list exists or is fully implemented. Verify
the current repository.

Avoid producing a giant duplicated documentation dump. Summarize the inventory
in a compact table in the final report and expand only where necessary.

## 5. Verify API contract compatibility

For every implemented feature, compare the frontend client and runtime validator
with the corresponding backend source.

Focus on release-critical discrepancies:

- Incorrect paths or HTTP methods.
- Missing or incorrectly named request fields.
- Wrong nullability or optional-field semantics.
- Incorrect handling of Laravel validation errors.
- Incorrect response-envelope assumptions.
- Flat arrays mistaken for paginated resources, or vice versa.
- Missing, incorrectly typed, or renamed response fields.
- Enum and status mismatches.
- Incorrect permission keys.
- Incorrect handling of 401, 403, 404, 409, and 422 responses.
- Lifecycle actions exposed when the backend disallows them.
- Stale assumptions carried forward from earlier phase reports.
- Company-context mistakes or cross-company data leakage risks.
- Inaccurate display of server-owned or derived accounting values.

Check actual backend resources and requests. Do not treat a frontend mock as
evidence of a backend contract.

### Pagination

Some endpoints return Laravel pagination metadata; others return flat arrays
without pagination metadata.

The frontend must honor each endpoint's actual behavior.

Do not fabricate totals, page counts, `links`, `meta`, or `hasMore` values
unsupported by the contract.

### Accounting data integrity

Verify that:

- Money and accounting amounts remain decimal strings where required.
- Client-side JavaScript floating-point arithmetic is not used for authoritative
  accounting calculations.
- Journal and report balances are displayed from server-authoritative values.
- Currency conversion and FX rates use the correct direction and precision.
- Posted journals are not presented as editable.
- Closed-period restrictions are reflected accurately.
- Budget variance and other derived reports do not invent totals.
- Nullable values and zero-denominator results are handled correctly.

Fix confirmed frontend contract defects within scope and add tests.

## 6. Live integration verification

Live integration is a major objective of this phase, but it must be performed
safely and only when the environment is actually available.

### 6.1 Determine whether a safe environment exists

Inspect existing local configuration without exposing secret values.

Determine whether:

- Laravel can already run against an available development or test database.
- Required frontend environment variables are configured.
- The frontend can reach its own Next.js BFF.
- The BFF can reach the Laravel API.
- A dedicated test company and test users are available.
- Credentials are available through existing safe configuration or an approved
  test setup.

Do not print `.env` contents or secret values into terminal output or the phase
report.

Do not assume the API base URL, ports, database credentials, test credentials,
or environment configuration. Inspect configuration safely.

**If a suitable environment is unavailable, do not fabricate live results.**
Complete source-based verification and automated tests, then record the exact
missing prerequisites for future live verification.

### 6.2 Safety constraints for live checks

- Prefer an existing isolated development/test environment.
- Never target production for exploratory tests.
- Do not reset or reseed a database.
- Do not run migrations.
- Do not create or delete business records in a shared environment without
  explicit authorization.
- Do not post journals, close periods, approve budgets, deactivate real users,
  deactivate companies, or perform other consequential mutations during
  exploratory verification.
- Do not send real customer emails or trigger external notifications.
- Do not use real customer, supplier, payroll, or financial data unnecessarily.
- Do not expose test credentials or session tokens.
- Do not disable authentication or security controls to make integration tests
  pass.

If safe read-only authenticated checks require an existing test account, use
only an account that is already configured and authorized for testing.

If no approved test environment or test data exists, stop live checks at that
boundary and document the limitation.

### 6.3 Layered verification

Where supported, distinguish these levels clearly:

1. **Source verification:** frontend and backend source contracts agree.
2. **Automated unit/component tests:** local tests pass.
3. **BFF integration tests:** the frontend's same-origin proxy and session
   routes behave correctly under test.
4. **Live read-only integration:** a real running frontend/BFF communicates with
   a real running Laravel API.
5. **End-to-end workflow verification:** an approved isolated test environment
   supports actual user workflows.
6. **Production deployment verification:** a real deployed environment is
   checked, only if explicitly available and authorized.

Do not use a higher-level label when only a lower-level check was performed.

### 6.4 Recommended live checks

If a suitable environment is available, prioritize safe checks such as:

- Frontend startup and health.
- Public login page loads without a server error.
- BFF route responds according to its contract.
- Invalid or expired authentication is handled safely.
- Session restoration works for an approved test account.
- Authenticated user and active company context load correctly.
- Company-scoped read endpoints reject unauthorized or invalid company context.
- Dashboard and selected read-only reports load from actual backend data.
- Major module list pages handle real empty and populated responses.
- 401 and 403 responses do not leak sensitive content.
- Logout invalidates the active session.
- A subsequent request after logout does not retain authenticated access.

Do not claim that logout, session invalidation, or authorization was verified
unless the relevant request was actually exercised.

For workflows requiring mutations, use a dedicated isolated test company and
explicitly approved test data. Avoid posting accounting entries or changing real
company state unless the user authorizes it.

## 7. BFF and authentication security review

Inspect the actual current implementation.

Verify the following properties where applicable:

- JWTs remain server-side in encrypted HttpOnly cookies.
- JWTs are not exposed to browser JavaScript.
- The browser does not call Laravel directly.
- Authenticated requests use the same-origin BFF.
- Authorization and company-context headers are generated server-side.
- BFF route allowlists remain restrictive.
- Path traversal and unintended proxy destinations are rejected.
- Upstream redirects are handled safely.
- Response headers are filtered appropriately.
- Sensitive responses use the correct caching policy.
- Request body limits are enforced.
- Unauthorized requests clear or retain sessions according to the intended
  contract.
- Change-password token replacement is handled correctly.
- Company switching cannot authorize arbitrary company access.
- Error responses do not expose upstream stack traces or secrets.
- Password recovery avoids revealing whether an account exists.
- Return URLs cannot introduce open redirects.
- Session expiration does not leave protected data visible after logout or
  company switching.
- Role and permission checks are applied consistently.

Do not rewrite a working security-sensitive subsystem simply to improve style.

If a confirmed defect exists, make the smallest safe frontend fix and add a
focused regression test.

Document what was checked and what could not be verified.

This is not a penetration test or formal security certification. Do not claim
otherwise.

## 8. Frontend runtime and production configuration

Review production-relevant configuration and runtime behavior:

- Next.js production build and route generation.
- Required environment variables and startup validation.
- Missing configuration errors.
- Server-side versus client-side environment exposure.
- Secure cookie behavior under the configured deployment mode.
- API timeout and network-failure handling.
- Error boundaries and user-facing error states.
- Loading and empty states.
- Stale company-specific data after switching.
- Request cancellation and duplicate requests.
- Console errors and unhandled promise rejections in tested workflows.
- Broken navigation or inaccessible routes.
- Responsive layout and keyboard accessibility for critical workflows.
- Missing metadata or incorrect public/private route handling.
- Unexpected redirects and redirect loops.
- Handling of unsupported browser/API response data.

Do not introduce a new monitoring platform, analytics SDK, state-management
library, or other dependency in this phase.

Do not expose server-only secrets through `NEXT_PUBLIC_*` variables.

Do not change deployment architecture without a demonstrated requirement.

If production environment values are missing, document the variable names and
purpose only; never include their values.

## 9. Test strategy and regression coverage

Review the current Vitest configuration, test utilities, and existing tests.

Add or improve tests where a meaningful gap exists.

Prioritize:

- Authentication and session behavior.
- Company switching and stale-state prevention.
- BFF proxy security and header filtering.
- Error and validation handling.
- Representative accounting API clients and runtime validators.
- Permission gates and direct-route behavior.
- Financial decimal-string handling.
- Report parsing and null/empty results.
- Important workflow regressions from Phases 17–20.
- Any defect discovered during integration checks.

Do not create redundant tests merely to increase the count.

Do not convert an unverified live workflow into a passing mock test and then
call it live integration.

If the repository supports a separate integration-test command, use it only when
its environment and safety characteristics are understood.

Keep tests deterministic. Use mocks for unit/component coverage and real HTTP
only where a safe integration environment is available.

## 10. User experience and accessibility review

Retain the established visual direction:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, readable interfaces.
- Semantic status colors.
- Existing shared components and spacing conventions.

Review representative screens across:

- Dashboard.
- Accounting and journals.
- Financial reports.
- Cash and banking.
- Tax.
- Sales and receivables.
- Purchases and payables.
- Fixed assets.
- Currencies and FX.
- Budgets and dimensions.
- Company settings and user administration.
- Authentication and password recovery.

Check for:

- Obvious layout overflow.
- Missing loading, empty, and error states.
- Inaccessible forms or dialogs.
- Unclear destructive actions.
- Inconsistent labels or status names.
- Broken links.
- Unhelpful server error presentation.
- Incorrectly displayed financial precision.
- UI that falsely implies an unsupported capability.

Use existing project conventions. Avoid broad redesigns or unrelated visual
changes.

If browser-based visual testing tools are not available, state that visual
verification was not performed instead of inferring success from a build.

## 11. Fix strategy and scope control

Fix confirmed, material frontend defects discovered during the audit.

Prioritize in this order:

1. Security and authorization defects.
2. Accounting correctness and data-integrity display defects.
3. Broken authentication or company-context workflows.
4. API contract incompatibilities that prevent normal operation.
5. Broken core user workflows.
6. Release-blocking runtime or build defects.
7. Accessibility and significant usability defects.
8. Minor polish, only when safe and clearly justified.

For each fix:

- Record the problem and evidence.
- Make the smallest appropriate change.
- Add or update regression tests.
- Re-run relevant quality gates.

Do not turn Phase 21 into an unrestricted refactor.

Do not fabricate missing backend capabilities. Record those as release
limitations or backend follow-up work.

If a defect requires backend changes, stop at the frontend boundary and document
the backend dependency.

## 12. Required quality gates

Run these commands from `accounting-app/frontend`:

```bash
npm run lint
npx eslint . --max-warnings=0
npx tsc --noEmit
npx vitest run
npm run build
```

All five must pass.

If a gate fails:

1. Capture the exact failure.
2. Determine whether it is pre-existing or introduced by Phase 21.
3. Fix defects introduced by Phase 21.
4. Do not weaken rules or delete tests.
5. Record unresolved pre-existing blockers accurately.

Inspect the production build output and confirm that expected application routes
are emitted.

Do not claim a live integration pass based on these five commands.

## 13. Release-readiness classification

Provide an evidence-based release classification in the final report.

Use these classifications:

- **READY FOR NEXT CONTROLLED DEPLOYMENT STEP:** required checks for the scope
  actually verified have passed, no known release-blocking defect remains, and
  any outstanding environment-dependent verification is explicitly identified.
- **READY WITH DOCUMENTED LIMITATIONS:** no confirmed blocker remains within the
  verified scope, but non-blocking gaps or unavailable verification steps
  remain.
- **NOT READY:** a confirmed release-blocking defect remains, a required quality
  gate fails, or a critical security/accounting behavior is incorrect.
- **UNVERIFIED:** the available evidence is insufficient to support a meaningful
  readiness decision.

Do not label the application fully production-ready if the required live
environment, deployment configuration, or critical end-to-end workflows were not
verified.

Distinguish frontend release readiness from backend operational readiness and
full-system production readiness.

## 14. Final report

Create a new report at:

`accounting-app/docs/report/frontend/FRONTEND_PHASE_21_REPORT.md`

If the repository uses a different established canonical report directory,
verify it and use the existing convention without overwriting any report.

The report must include:

1. Executive summary.
2. Release-readiness classification and justification.
3. Initial branch, HEAD, and complete Git status.
4. Baseline quality-gate results.
5. Integration inventory across the major frontend modules.
6. Contract discrepancies found and their evidence.
7. Fixes implemented and regression tests added.
8. BFF, authentication, authorization, and company-context review.
9. Live environment availability and exact verification level achieved.
10. Live checks performed, with outcomes and any mutations clearly disclosed.
11. Checks not performed and the reason for each.
12. Quality-gate results and final test totals.
13. Runtime/configuration and UX/accessibility findings.
14. Remaining backend gaps and deployment prerequisites.
15. Known limitations and recommended follow-up actions.
16. Final Git status with all modified, deleted, and untracked paths.
17. Explicit confirmation that no backend files, dependencies, migrations,
    seeders, database resets, commits, pushes, or prohibited Git operations were
    performed.

For every important result, distinguish:

- `[source]` — verified by source inspection.
- `[tests]` — verified by automated tests.
- `[build]` — verified by a production build.
- `[live]` — exercised against a running frontend/BFF/backend.
- `[manual]` — manually inspected or tested.
- `[not verified]` — evidence unavailable.

Use these labels honestly.

Do not expose secrets in the report.

Do not overwrite previous phase reports.

## 15. Final acceptance criteria

Phase 21 is complete when:

- The current repository state has been inspected and preserved.
- Major frontend/backend contracts have been audited.
- Confirmed release-blocking frontend defects within scope have been fixed, or
  are explicitly documented as unresolved.
- Relevant security and accounting regression coverage exists.
- Live integration has been performed where safe and feasible, or its missing
  prerequisites are documented.
- All five quality gates pass, or confirmed pre-existing blockers are
  transparently reported.
- The final report is created at the established report location.
- Release readiness is classified based on evidence rather than assumptions.
- All Phase 21 work remains uncommitted.

## 16. Final stop condition

**Stop after Phase 21. This is the final planned frontend phase.**

Do not start a new implementation phase.

Do not create commits or push changes.

Do not modify backend files or dependencies.

Do not run migrations, seeders, database resets, or destructive operations.

Do not perform prohibited Git operations.

Do not overwrite earlier reports or discard user work.

At completion, provide a concise final summary containing:

- Release-readiness classification.
- Critical findings and fixes.
- Whether live integration was actually verified.
- Quality-gate results.
- Remaining blockers or deployment prerequisites.
- Final report path.
- Confirmation that all changes remain uncommitted.
