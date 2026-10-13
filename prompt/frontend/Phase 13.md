# Accountance Frontend — Phase 13: Complete Accounting Reporting Core & Phase 11 Remediation

**Project:** Accountance Accounting Web App  
**Phase:** 13  
**Scope:** Frontend only  
**Priority:** Release-blocking remediation and financial reporting  
**Backend Changes:** Strictly prohibited  
**Git Commit:** Prohibited  
**Phase 13 Report:** `accounting-app/docs/FRONTEND_PHASE_13_REPORT.md`

---

## 1. Objective

Complete and stabilize the accounting reporting module using the existing
Laravel backend contracts and frontend architecture.

Phase 12 identified the following release-blocking issues:

1. `npm run lint` fails with 7 errors and 17 warnings, all in the uncommitted
   Phase 11 reports files.
2. The General Ledger page renders only a heading and does not display the
   report data.
3. Trial Balance, Profit & Loss, and General Ledger have no dedicated automated
   tests.
4. The Balance Sheet and Cash/Bank reports have backend endpoints but no
   frontend pages.
5. The navigation contains a stale `Ledgers` upcoming placeholder despite an
   implemented General Ledger route.
6. The Phase 11 report has inconsistent completion status and omits the actual
   lint result.

Resolve these issues without disturbing previous work.

**This phase must deliver a functional, tested financial reporting module. Do
not proceed to Phase 14.**

## 2. Mandatory Repository Preservation

The repository contains important uncommitted and untracked work from Phase 11,
including the reports implementation and navigation changes. Preserve it.

### 2.1 Before changing anything

Inspect and record:

- Current branch and HEAD.
- `git status --short --branch`.
- `git diff --stat`.
- Existing tracked modifications and untracked files.
- Current versions of all Phase 11 report files.
- Existing frontend test and component conventions.
- `docs/FRONTEND_PHASE_12_REPORT.md`.

Treat the Phase 12 audit as the baseline, but inspect the actual current
repository because the working tree may have changed since the audit.

### 2.2 Prohibited Git operations

Never run:

- `git pull` or `git fetch`
- `git reset`
- `git stash`
- `git clean`
- `git checkout` or `git switch`
- `git restore` to discard changes
- `git commit`

Do not change branches, delete files, overwrite existing user work wholesale, or
perform automated conflict cleanup.

### 2.3 File boundaries

- Do not modify anything under `accounting-app/backend/`.
- Do not modify backend routes, controllers, services, models, permissions,
  migrations, or tests.
- Do not run migrations or seeders.
- Do not install, remove, upgrade, or downgrade dependencies.
- Do not modify package manifests or lockfiles.
- Do not delete existing tests or reports.
- Do not overwrite prior phase reports.
- Preserve all unrelated tracked modifications, untracked files, prompts, and
  documentation.
- Do not commit any changes.

You may modify frontend source and add frontend tests required by this phase.
The only new phase report should be
`accounting-app/docs/FRONTEND_PHASE_13_REPORT.md`.

If that report already exists, inspect it and preserve existing content. Add a
clearly named alternative report if necessary rather than overwriting user work.

If an unexpected repository state makes safe work impossible, stop and report
the blocker instead of attempting recovery.

---

## 3. Inspect the Existing Implementation First

Before editing, inspect the actual source code for:

- `src/lib/reports/types.ts`
- `src/lib/reports/trial-balance-types.ts`
- `src/lib/reports/profit-loss-types.ts`
- `src/lib/reports/general-ledger-types.ts`
- `src/lib/reports/validators.ts`
- `src/lib/reports/api.ts`
- `src/lib/reports/use-trial-balance.ts`
- `src/lib/reports/use-profit-loss.ts`
- `src/lib/reports/use-general-ledger.ts`
- `src/components/reports/trial-balance-view.tsx`
- `src/components/reports/profit-loss-view.tsx`
- `src/components/reports/general-ledger-view.tsx`
- `src/app/(app)/reports/trial-balance/page.tsx`
- `src/app/(app)/reports/profit-loss/page.tsx`
- `src/app/(app)/reports/general-ledger/page.tsx`
- `src/lib/navigation/nav.ts`
- Relevant UI primitives, report tests, API client, permission helpers,
  formatting utilities, and route conventions.
- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_API_HANDOFF.md`
- `docs/FRONTEND_PHASE_11_REPORT.md`
- `docs/FRONTEND_PHASE_12_REPORT.md`

Use the exact repository paths if they differ.

Read the backend route contracts and relevant controller/service/validator code
for the existing reporting endpoints. This is read-only inspection only.

Do not assume the Phase 12 report is perfectly current. Verify every relevant
finding against source.

---

## 4. Reporting Architecture and Shared Requirements

Follow the established frontend architecture.

- Next.js App Router.
- Strict TypeScript.
- Existing API client and BFF proxy.
- Existing report validators and data-fetching patterns, corrected where
  necessary.
- Existing permission helpers and navigation conventions.
- Existing reusable UI primitives.
- Existing green-based Light Mode and professional Dark Mode.
- Existing `formatMoney` and date-formatting utilities.
- Existing test framework and test conventions.

Do not introduce a new data-fetching library, schema-validation dependency,
chart library, or other package.

### 4.1 Security

- All report requests must go through the existing same-origin BFF.
- Never expose a JWT to browser JavaScript.
- Never send a browser-owned `company_id` as an authorization mechanism.
- Never bypass the proxy allow-list.
- Preserve server-generated `Authorization` and `X-Company-Id` headers.
- Respect the existing company context and session lifecycle.
- All report pages and navigation links must respect `accounting.reports.view`.
- Backend authorization remains authoritative.

### 4.2 Accounting correctness

- Display figures returned by the backend.
- Do not recalculate financial totals or balances in the frontend.
- Do not use JavaScript floating-point arithmetic for money.
- Preserve monetary decimal strings through API parsing and rendering.
- Use the existing safe money-formatting utility.
- Display base currency and reporting period where supplied by the API.
- Do not include draft journals in report calculations; the backend is
  authoritative.
- Preserve the distinction between debit, credit, signed net balance, and
  account-normal balance.
- Do not invent missing fields, totals, account names, or opening balances.
- Show zero values when the backend returns them; do not treat a valid zero as
  missing.
- Respect backend date semantics and existing aliases.
- Do not imply that a report has been verified against live data unless it
  actually has.

---

## 5. Fix Phase 11 TypeScript Validator Errors

The Phase 12 audit identified six `@typescript-eslint/no-explicit-any` errors
in:

`src/lib/reports/validators.ts`

Inspect the exact code and replace `any` with appropriate strict types.

Requirements:

- Use `unknown` at untrusted-data boundaries.
- Narrow types explicitly.
- Reuse shared report types where appropriate.
- Validate objects, arrays, nullable values, IDs, date fields, and monetary
  decimal strings.
- Preserve the real backend response contract.
- Do not weaken validation just to satisfy lint.
- Do not use broad type assertions to bypass compiler errors.
- Do not invent response fields.
- Ensure malformed responses fail safely with the established API error
  behavior.

Add focused validator tests covering:

- Valid response payloads.
- Missing required fields.
- Invalid primitive types.
- Invalid array entries.
- Nullable and optional fields supported by the actual contract.
- Decimal strings and zero values.
- Malformed payloads that must be rejected.

The validator must remain consistent with the actual backend source.

---

## 6. Complete the General Ledger Report

**Route:** `/reports/general-ledger`

The Phase 12 audit found that `general-ledger-view.tsx` fetches report data but
renders only a heading. Complete this screen.

### 6.1 Report controls

Provide:

- Account selection.
- From-date and to-date filters.
- Apply/reload behavior consistent with existing reports.
- URL query parameter support for `account_id`, `from`, and `to`.
- Preservation of report filters during navigation where applicable.

The account selector must work for users with more than 50 accounts.

Inspect the existing account-list API and account picker implementation. Use the
actual supported search and pagination contract. Do not silently limit the
selector to the first page if more accounts exist.

If the API lacks a necessary capability, document the limitation and provide the
safest practical frontend behavior without changing the backend.

The report requires `accounting.reports.view`. If the account picker requires a
separate permission, inspect the actual permission definitions and follow the
existing permission policy. Do not assume that a report permission automatically
grants permission to read every account-management endpoint.

### 6.2 Report header

Display the fields actually supplied by the backend, where available:

- Account code and name.
- Account type.
- Reporting period.
- Base currency.
- Opening balance.

Do not fabricate account details.

### 6.3 Movements table

Render the General Ledger movement rows using the verified contract:

- `journal_id`
- `journal_line_id`
- `journal_number`
- `journal_date`
- `reference`
- `description`
- `source_type`
- `source_id`
- `debit`
- `credit`
- `running_balance`
- `currency_id`
- `exchange_rate`
- `foreign_debit`
- `foreign_credit`

Render only fields useful to the agreed report UI, but do not discard data
required to distinguish currencies or explain a movement.

At minimum, the user must be able to review:

- Date.
- Journal number.
- Reference or description.
- Debit.
- Credit.
- Running balance.

Use the actual contract to determine optional values and labels.

### 6.4 Summary

Display backend-provided values:

- Period debits.
- Period credits.
- Closing balance.
- Closing signed balance, where useful to distinguish presentation from signed
  value.

Do not calculate these totals from rows.

### 6.5 Journal drill-down

Verify the existing journal detail route and actual `journal_id` semantics.

Link to journal detail only if the route and identifier match the backend
contract. Preserve the selected report period and account when returning to the
General Ledger if the existing navigation patterns support this safely.

### 6.6 Loading, error, empty, and success states

Implement all states:

- Initial loading.
- Reloading existing data.
- Validation failure.
- API failure.
- Permission denied.
- Invalid or unavailable account.
- Empty movements.
- Successful report with rows.
- Successful report with zero debit or credit amounts.

Do not leave stale data presented as though it belongs to a new account or
period.

### 6.7 React state and hooks

The audit found a `react-hooks/set-state-in-effect` lint error.

Refactor the URL/query initialization and filter handling without introducing
effect-driven state-update loops.

Use the existing hooks and state conventions. Do not silence lint rules or
disable React Hooks linting.

Resolve all report hook dependency warnings by making dependencies correct and
stable. Do not suppress `react-hooks/exhaustive-deps` to make lint pass.

---

## 7. Trial Balance and Profit & Loss

Keep the existing routes:

- `/reports/trial-balance`
- `/reports/profit-loss`

Do not rewrite these pages unnecessarily. Inspect them and make targeted
corrections.

### 7.1 Trial Balance

Verify and test:

- Date range filters.
- `include_zero_balances`.
- Account rows.
- Debit and credit totals.
- Net balances.
- Overall totals and balanced/difference indicators.
- Base currency and reporting period.
- Drill-down to General Ledger.

Render backend totals as authoritative. Do not recompute them.

### 7.2 Profit & Loss

Verify and test:

- Date range filters.
- Revenue section and account rows.
- Expenses section and account rows.
- Gross profit.
- Net profit.
- Profitability status.
- Base currency and reporting period.
- Drill-down to General Ledger with the selected account and period preserved.

Dimension filtering is explicitly deferred to Phase 19. Do not implement it in
this phase.

### 7.3 Shared behavior

Both reports must:

- Have loading, error, empty, and success states.
- Validate response payloads.
- Respect the report permission.
- Handle company switching without displaying stale company data.
- Use exact decimal strings and the established formatting utilities.
- Preserve existing filter semantics.

---

## 8. Add the Balance Sheet Report

**Route:** `/reports/balance-sheet`

Before implementation, inspect the exact endpoint and response contract in:

- `backend/routes/api.php`
- The relevant report controller.
- The relevant report service and request validator.
- Existing report tests and API handoff documentation.

Endpoint family identified by Phase 12:

`GET /api/accounting/reports/balance-sheet`

The handoff indicates an `as_of` parameter. Verify its exact format,
requiredness, default behavior, response fields, grouping, totals, and
permission before implementation.

### Requirements

- Create strict TypeScript response types.
- Add runtime validators.
- Add an API method using the existing API client.
- Add a data-fetching hook following established patterns.
- Add a report view and route.
- Add navigation under the Reports section.
- Display the as-of date and base currency where supplied.
- Render backend-provided account groupings and totals.
- Preserve the distinction between assets, liabilities, and equity exactly as
  represented by the backend.
- Do not calculate the accounting equation totals in the frontend.
- Do not assume a date-range filter: this is a point-in-time report.
- Do not add dimension filters; they are deferred by design.
- Provide loading, error, empty, and success states.

Use the exact response contract. If the report has nested groupings, render
those groupings without flattening away their accounting meaning.

Do not assume that every field in the Trial Balance or Profit & Loss exists in
the Balance Sheet response.

---

## 9. Add the Cash/Bank Report

**Route:** `/reports/cash-bank`

Inspect the actual backend route, controller, service, and validation contract
before implementing.

Endpoint family identified by Phase 12:

`GET /api/accounting/reports/cash-bank`

The audit identified date filters and account filters, but their exact names and
semantics must be verified.

### Requirements

- Create strict response types.
- Add runtime validators.
- Add an API method through the existing client.
- Add a data-fetching hook.
- Add the report view and page.
- Add navigation under Reports.
- Render backend-provided account-level rows, balances, totals, and reporting
  periods as supported by the actual contract.
- Support only filters that the backend genuinely accepts.
- Preserve exact decimal strings.
- Do not calculate account totals or balances in the frontend.
- Provide loading, error, empty, and success states.
- Respect report permissions and company isolation.

Do not confuse this report with the existing cash/bank account configuration
screen at `/cash-bank`. Keep the routes distinct.

Do not add CSV export or new chart libraries.

---

## 10. Navigation

Inspect `src/lib/navigation/nav.ts`.

- Keep the existing Trial Balance, Profit & Loss, and General Ledger links.
- Add Balance Sheet and Cash/Bank report links.
- Use the actual permission string `accounting.reports.view` after verifying it
  in the repository.
- Remove or revise the stale `Ledgers` upcoming placeholder so the navigation
  does not suggest the General Ledger is unavailable.
- Preserve the existing navigation structure, styling, and permission filtering.
- Do not change unrelated navigation sections or introduce future-phase links
  for unimplemented modules.

Add or update tests for report navigation and permission visibility using the
existing test conventions.

---

## 11. Automated Tests

Phase 12 found zero tests under `src/lib/reports/**` and
`src/components/reports/**`.

Add meaningful automated tests for all five reports:

1. Trial Balance.
2. Profit & Loss.
3. General Ledger.
4. Balance Sheet.
5. Cash/Bank.

Follow the installed Vitest and React Testing Library setup. Do not install new
testing packages.

### 11.1 Validator tests

Test valid, invalid, missing, nullable, and malformed response data.

### 11.2 API tests

Test:

- Correct endpoint.
- Correct query parameters.
- Date handling.
- Account selection.
- Boolean filter serialization.
- Abort signal forwarding where the existing API layer supports it.
- API errors and malformed responses.

Mock at the established client boundary, not by bypassing the implementation
under test.

### 11.3 Hook tests

Test:

- Initial loading.
- Successful data load.
- Request failure.
- Filter changes.
- Request cancellation.
- Reload behavior.
- Avoiding stale results when the company or filters change.

Do not use tests that depend on timing assumptions without controlling the test
environment.

### 11.4 Component tests

Test:

- Correct rendering of report headings and filters.
- Backend values displayed accurately.
- Loading, error, empty, and success states.
- Report totals rendered from the backend.
- General Ledger movements and running balance.
- Trial Balance/P&L drill-down links.
- Balance Sheet groupings.
- Cash/Bank report filters and output.
- Permission behavior.
- Accessible labels and keyboard interactions where applicable.

Use representative decimal strings such as `0.0000`, `1250.2500`, and negative
values where supported. Verify formatting without introducing frontend
arithmetic.

### 11.5 No false confidence

A mocked test suite does not prove live integration. Document that limitation
accurately.

Do not claim that live backend or end-to-end testing was performed unless it
actually was.

---

## 12. Fix All Phase 13 Lint Findings

The Phase 12 baseline recorded:

- 7 errors.
- 17 warnings.
- All findings located in Phase 11 reports files.

Run the actual lint command and inspect its current output. The count may have
changed.

Fix all errors and warnings introduced by the report module, including:

- Explicit `any` types.
- React state updates inside effects where prohibited by the current lint rules.
- Unused variables and imports.
- Incorrect or incomplete effect dependencies.

Do not:

- Disable lint rules.
- Add broad ESLint suppressions.
- Weaken TypeScript strictness.
- Replace specific types with `any`.
- Remove meaningful tests just to pass lint.
- Hide warnings by changing the lint configuration.

The final requirement is:

`npm run lint` exits with code 0 and reports no remaining warnings attributable
to the reporting module.

If the repository has unrelated pre-existing lint findings outside the reporting
module, document their exact files and messages. Do not modify unrelated
features solely to improve the report.

---

## 13. Quality Gates

Inspect `package.json` and the repository's scripts before running checks.

Run, where safely supported:

1. `npm run lint`
2. `npx tsc --noEmit`
3. `npm test`
4. `npm run build`

Run them from `accounting-app/frontend`.

Do not install or update dependencies. If a command would rewrite tracked files
or lockfiles, do not run it.

Record the exact command, exit code, and concise result.

Requirements:

- Lint passes.
- TypeScript passes.
- All tests pass, including new report tests.
- Production build passes.
- New report routes are included in the build.
- No new unhandled React warnings are introduced by report tests.

If a gate fails, investigate and fix in-scope issues. Do not falsely report
completion when a gate remains red.

---

## 14. Live Integration Limitation

Phase 12 confirms that live integration has never been performed.

Do not claim that this phase proves live API correctness.

If no running backend and database are available:

- Do not start, configure, migrate, or seed the backend without explicit
  authorization.
- Complete static contract inspection and mocked automated tests.
- Record live integration as **not performed**.
- List the precise smoke tests still needed for Phase 21.

At minimum, the future runtime verification must cover:

- Each report against real company data.
- Account selection and General Ledger drill-down.
- Date-boundary behavior.
- Empty result sets.
- Permission-denied responses.
- Company switching.
- Cross-company identifier isolation.
- Exact monetary rendering.
- Production proxy behavior.

---

## 15. Phase 13 Report

Create:

`accounting-app/docs/FRONTEND_PHASE_13_REPORT.md`

Include:

1. Executive summary.
2. Initial branch, HEAD, and Git status.
3. Repository-preservation confirmation.
4. Files added and modified.
5. Phase 11 lint defects found and fixed.
6. General Ledger implementation details.
7. Trial Balance and Profit & Loss changes.
8. Balance Sheet implementation and verified contract.
9. Cash/Bank implementation and verified contract.
10. Navigation changes.
11. New test files and their coverage.
12. Quality-gate results with exact commands and exit codes.
13. Live integration status.
14. Known limitations and deferred items.
15. Remaining risks for Phase 14 and Phase 21.
16. Final Git status.
17. Confirmation that backend files, dependencies, and previous reports remain
    unchanged.

Distinguish between:

- Verified by source inspection.
- Verified by automated tests.
- Verified by a production build.
- Verified against a live backend.
- Not yet verified.

Do not label the phase complete if its acceptance criteria or mandatory quality
gates fail.

---

## 16. Acceptance Criteria

Phase 13 is complete only when all applicable criteria below are satisfied.

### General Ledger

- The screen renders actual API response rows.
- Opening balance, debit, credit, running balance, and closing balance are
  displayed according to the real contract.
- Account selection works beyond the first 50 accounts where the API supports
  pagination/search.
- Trial Balance and P&L drill-downs preserve account and date filters.
- Loading, error, empty, and success states work.
- No React Hooks lint suppression is used.

### Trial Balance and Profit & Loss

- Existing behavior remains intact.
- Validators and automated tests cover both reports.
- Backend-provided totals remain authoritative.
- Date filters and drill-downs work as specified.

### Balance Sheet and Cash/Bank

- Both routes exist.
- Both use verified backend contracts.
- Filters reflect actual endpoint capabilities.
- Backend totals and groupings render correctly.
- Validators, API clients, hooks, views, and tests are included.
- Navigation is permission-filtered.

### Quality and preservation

- `npm run lint` passes with zero reporting-module warnings.
- `npx tsc --noEmit` passes.
- `npm test` passes.
- `npm run build` passes.
- No backend files are changed.
- No dependencies are added or updated.
- No prior reports or user work are overwritten.
- No prohibited Git operations are performed.
- No commit is created.
- The Phase 13 report accurately documents the work and limitations.

If any acceptance criterion cannot be satisfied, report the precise blocker and
mark Phase 13 incomplete.

## 17. Stop Condition

After completing Phase 13:

- Stop all implementation work.
- Do not start Phase 14.
- Do not implement customers, invoices, suppliers, bills, receipts, payments,
  notes, fixed assets, currencies, budgets, dimensions, or company
  administration in this phase.
- Do not perform unrelated refactoring.
- Do not commit changes.

Provide a concise final summary stating:

- What was implemented.
- Which report routes now work.
- New test coverage.
- Actual lint, TypeScript, test, and build results.
- Any unresolved blockers.
- The location of `FRONTEND_PHASE_13_REPORT.md`.
- Confirmation that existing work was preserved.
