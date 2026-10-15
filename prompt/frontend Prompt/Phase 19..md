# Phase 19 — Budgets & Dimensions

## 1. Role and Objective

You are working on **Accountance**, an international-ready, multi-company
accounting web application.

Implement **Frontend Phase 19: Budgets & Dimensions** in the existing Next.js
frontend, integrating with the existing Laravel REST API.

This phase must deliver a reliable interface for managing accounting dimensions,
creating and maintaining budgets, and reviewing budget performance through
supported reports.

**This is a frontend-only phase.** The backend has already been developed in
previous phases. Inspect the real Laravel implementation and its tests before
implementing each feature. Use the actual API contract as the source of truth.

Do not invent endpoints, fields, permissions, accounting behavior, or workflows.
If the backend does not support a requested capability, document the gap instead
of implementing a fake or frontend-only substitute.

Complete Phase 19 only. Do not begin Phase 20 or Phase 21.

## 2. Mandatory Repository Preservation Rules

Before making changes:

1. Confirm the actual repository root, frontend root, current branch, HEAD, and
   working-tree status.
2. Read the master blueprint, frontend blueprint, and relevant phase reports.
3. Inspect `docs/FRONTEND_PHASE_17_REPORT.md` and
   `docs/FRONTEND_PHASE_18_REPORT.md`, if present.
4. Inspect the current Phase 17 fixed-assets and Phase 18 multi-currency
   implementations and their uncommitted changes.
5. Inspect the existing budgets, dimensions, Profit & Loss, and Budget Variance
   frontend/backend implementations.
6. Identify every modified, staged, untracked, ignored, and pre-existing file
   relevant to the work.
7. Record the initial state before modifying any file.

**Important:** The Phase 17 and Phase 18 reports indicate that their work was
intentionally left uncommitted. Preserve those changes exactly as found. Do not
assume the current working tree matches an earlier report.

The repository may contain unrelated untracked files. Preserve them.

### Strictly prohibited unless the user separately authorizes it

- `git reset`, `git clean`, `git stash`, destructive checkout/restore commands,
  or equivalent operations.
- Pulling, fetching, switching branches, rebasing, or other Git operations that
  could disturb the current repository.
- Committing or pushing changes.
- Modifying any Laravel backend source file, route, migration, seeder, backend
  test, or database schema.
- Running migrations, seeders, or database-mutating commands.
- Adding, removing, or upgrading dependencies.
- Modifying `package.json`, package scripts, or lockfiles.
- Deleting, weakening, or rewriting existing tests merely to obtain passing
  results.
- Overwriting completed phase reports or prompts.
- Refactoring unrelated completed features.

If an existing contract is inadequate, record the precise limitation and
continue with the supported functionality.

## 3. Existing Architecture

Preserve the application's established stack and conventions:

- Laravel REST API backend.
- Next.js App Router.
- React and strict TypeScript.
- Tailwind CSS v4.
- MySQL.
- Same-origin Next.js Backend-for-Frontend (BFF) proxy.
- JWT authentication held server-side in an encrypted HttpOnly cookie.
- Server-generated authorization headers and server-side company context.
- Existing API wrappers, runtime validators, hooks, and `AbortController`
  patterns.
- Existing reusable components, form controls, tables, dialogs, notifications,
  and formatting utilities.
- Existing Vitest test setup.

Do not introduce a new API architecture, state-management library, form library,
validation library, or UI framework.

### Security requirements

- All browser-originated API requests must use the existing same-origin BFF
  proxy.
- Never expose JWTs, encryption keys, or server secrets to client-side code.
- Do not allow client-provided company IDs to override trusted company context.
- Never call the Laravel API directly from browser components.
- Respect the existing proxy allowlist and security controls.
- Follow existing caching rules for sensitive accounting data.
- Treat frontend permission checks as presentation controls, not replacements
  for backend authorization.
- Never log credentials, tokens, or sensitive accounting payloads.

## 4. Backend Contract Discovery

Before implementation, inspect the real backend source and tests for the
following areas.

### Budgets

Inspect the actual implementations of:

- Budget routes and route names.
- Budget controllers.
- Budget models and relationships.
- Request validation classes.
- API resources and response envelopes.
- Budget services and accounting rules.
- Policies and permission definitions.
- Budget creation and update behavior.
- Budget status and lifecycle behavior.
- Budget line structure and monetary precision.
- Period, fiscal-year, account, and dimension associations.
- Budget Variance report endpoints and their filters.
- Profit & Loss reporting endpoints where relevant to comparisons.

Search the source for terms such as `Budget`, `BudgetLine`, `budget`,
`budget-variance`, and `dimensions`. Treat these as search hints, not proof of
particular endpoint names.

### Dimensions

Inspect the actual implementations of:

- Dimension types or dimension definitions.
- Dimension values or dimension records.
- Dimension CRUD and lifecycle endpoints.
- Dimension assignment to journal entries or journal lines.
- Dimension filtering in reports.
- Required versus optional dimensions.
- Account or transaction compatibility restrictions.
- Dimension validation and authorization.
- Dimension-related indexes, constraints, and service rules.
- Any existing dimension management frontend code.

Determine whether the backend uses named dimensions, dimension types, dimension
values, or a different structure. Reproduce the existing model rather than
introducing a new taxonomy.

### Required contract inventory

Before writing feature code, record:

- HTTP methods and exact routes.
- Request payload fields and required/optional behavior.
- Response shapes and API envelopes.
- Pagination format and supported filters.
- Permission keys for each read and write operation.
- Company-scoping behavior.
- Validation and lifecycle rules.
- Monetary precision and rounding behavior.
- Reporting filter semantics.
- Known API limitations.

Do not rely exclusively on previous prompts or reports. Verify the current
source.

## 5. Dimension Management

Implement the dimension-management functionality that the existing backend
actually supports.

Potential capabilities include the following, but only when confirmed by source
inspection:

- Dimension list and detail.
- Create and edit dimensions.
- Dimension type or category selection.
- Dimension value management.
- Activation or deactivation.
- Search, filters, or pagination.
- Validation of dimension names, codes, and values.
- Display of usage restrictions or in-use status when returned by the API.

### Requirements

1. Use the backend's real dimension model and terminology.
2. Do not invent a parent-child hierarchy or type/value relationship.
3. Do not assume dimensions can be deleted.
4. Do not assume a dimension can be deactivated while in use.
5. Use exact backend permission identifiers.
6. Show appropriate loading, empty, error, and permission-denied states.
7. Display backend validation errors beside the relevant fields where possible.
8. Confirm destructive or consequential lifecycle actions when supported.
9. Do not allow frontend-only changes to imply that a dimension was saved.
10. Keep existing journal and transaction workflows intact.

### Dimension assignments

Inspect whether dimensions can be assigned to journal entries, journal lines, or
other accounting records.

If the existing API exposes dimension assignment within a supported workflow,
add or enhance the UI only where it is necessary and consistent with the
existing architecture.

Do not redesign the journal editor or previously completed sales, purchase,
cash/bank, fixed-asset, or other transaction forms simply to add dimension
fields.

If dimensions cannot be assigned through existing write endpoints, document that
limitation. Do not send guessed dimension IDs or payload properties.

## 6. Budget Management

Implement the budget functionality supported by the backend.

Potential capabilities include:

- Budget list and detail.
- Budget creation.
- Budget editing.
- Budget status and lifecycle display.
- Fiscal-year or accounting-period association.
- Budget line management.
- Account selection.
- Dimension assignment.
- Budget activation, approval, or other status transitions, if supported.
- Budget deletion, if explicitly supported.
- Read-only budget views where mutation permissions are absent.

Do not assume the backend supports all of these operations.

### Budget form requirements

Inspect the actual budget and budget-line contracts before designing the form.

If supported, the interface should allow users to configure the appropriate
combination of:

- Budget name or reference.
- Fiscal year or accounting period.
- Budget lines.
- Account selections.
- Amounts.
- Dimension assignments.
- Any backend-supported status or descriptive fields.

Only show fields supported by the API. Respect required fields and backend
validation.

### Accounting correctness

- Keep monetary values as decimal strings across API contracts, validation, form
  state, and submission where appropriate.
- Do not use JavaScript `Number` for accounting calculations.
- Do not calculate or persist authoritative budget totals in the browser.
- If line totals or summaries are derived in the UI, use exact decimal
  arithmetic and label them as previews where appropriate.
- Let the backend enforce accounting periods, account eligibility, dimension
  validity, lifecycle rules, and authorization.
- Do not assume budgets are annual, monthly, or quarterly until the source
  confirms the model.
- Do not invent budget approval workflows.
- Do not imply that a budget creates journal entries unless the backend
  explicitly implements that behavior.
- Do not assume budget amounts are always expressed in the company's base
  currency; verify the actual contract.

### Budget editing and deletion

Determine exactly which budget states allow edits or deletion.

- Disable unsupported actions.
- Follow backend state restrictions.
- Surface backend errors such as conflicts or validation failures.
- Do not make posted accounting records editable as a side effect of budget
  functionality.
- Do not invent a delete endpoint when none exists.

## 7. Budget Variance Reporting

Inspect the existing Budget Variance endpoint and current report implementation
before making changes.

Determine the exact supported filters and returned data, including whether the
report supports:

- Budget selection.
- Fiscal year or accounting period.
- Date range.
- Account.
- Dimension.
- Actual amount.
- Budgeted amount.
- Variance amount.
- Variance percentage.
- Grouping or aggregation.
- Pagination.

Only implement filters and columns verified in the API contract.

### Report behavior

- Keep backend-calculated values authoritative.
- Do not fabricate missing budget lines or actual balances.
- Do not treat absent fields as zero unless the API contract explicitly defines
  that behavior.
- Do not assume that every budget line has actual activity.
- Do not calculate variance from mixed currencies.
- Clearly label positive and negative variances using the backend's documented
  sign convention.
- Use semantic colors carefully; do not rely on color alone to communicate
  meaning.
- Render empty results and incomplete filter selections clearly.
- Preserve the existing Profit & Loss and other financial report behavior.

### Dimension filters in reports

The backend previously required dimension-filter fixes in Profit & Loss and
Budget Variance reporting. Inspect the current implementation to verify the
actual supported behavior.

If the frontend has a dimension filter:

- Use the exact supported query parameter names.
- Send only selected, valid dimension values.
- Preserve unrelated filters when a dimension filter changes.
- Ensure reset and clear-filter actions remove only the intended filters.
- Verify that changing filters triggers a fresh request.
- Do not add dimension filters to reports that do not support them.
- Do not claim a report is dimension-filtered unless the filter is actually
  transmitted to and honored by the backend.

Add regression tests for existing report-filter behavior before changing it.

## 8. User Experience and Visual Design

Follow the existing Accountance design system:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, uncluttered layouts.
- Green as the primary accent, not an excessive background color.
- Semantic colors reserved for meaningful statuses, warnings, and errors.
- Responsive tables and forms.
- Accessible labels, keyboard navigation, focus states, and dialogs.
- Consistent spacing, typography, and page headers.
- Clear loading skeletons, empty states, errors, and success feedback.

Reuse existing components and design tokens. Do not build a parallel design
system.

### Navigation

Inspect the existing navigation and permission model before adding items.

Add only routes that have real functionality. Use the exact backend permission
keys and preserve the existing navigation structure.

Potential routes are suggestions only:

- `/dimensions`
- `/dimensions/[id]`
- `/budgets`
- `/budgets/[id]`
- `/budgets/new`
- `/budgets/[id]/edit`
- `/reports/budget-variance`

Use the current route conventions. If an equivalent route already exists, extend
it rather than creating a duplicate.

Do not create placeholder routes for unsupported features.

## 9. API Types, Runtime Validation, and Hooks

For each supported endpoint:

1. Define strongly typed request and response contracts.
2. Add runtime response validation following the project's established patterns.
3. Implement API wrappers through the existing same-origin BFF.
4. Add hooks using existing loading, cancellation, and error-handling
   conventions.
5. Respect the actual API response envelope.
6. Handle validation errors through existing error utilities.
7. Respect the actual permission model.
8. Preserve decimal strings for monetary values.
9. Use actual supported pagination and filter behavior.

Do not use `any` to bypass TypeScript errors.

Do not invent `links` or `meta` fields. Some existing backend endpoints return
flat arrays without Laravel pagination metadata. Inspect each endpoint and
follow the established behavior for that specific contract.

Do not create fake fallback objects that make malformed responses appear valid.

Avoid unnecessary new shared abstractions. If a shared utility is genuinely
required, ensure it is additive and does not change existing feature behavior.

## 10. Testing Requirements

Add focused tests for the functionality implemented in this phase.

### Dimension tests

Where supported, test:

- API routes and query parameters.
- Runtime response validation.
- List, detail, create, and update behavior.
- Lifecycle actions.
- Permission-gated controls.
- Empty, loading, and error states.
- Invalid and malformed response data.
- Backend validation-error presentation.

### Budget tests

Where supported, test:

- Budget list and detail.
- Creation and editing.
- Exact request payloads.
- Budget-line handling.
- Decimal-string preservation.
- Account, period, and dimension selection.
- Lifecycle restrictions.
- Permission-gated actions.
- Validation errors and failed requests.
- Unsupported operations remaining unavailable.

### Budget Variance tests

Test:

- Actual supported filters.
- Query-parameter mapping.
- Budget and period selection where supported.
- Dimension filtering.
- Clearing filters.
- Missing or empty results.
- Error states.
- Correct display of returned variance values.
- No unsupported calculations or invented zero values.

### Regression tests

Run the full existing frontend test suite. Preserve regression coverage for:

- Phase 13 financial reporting.
- Phase 14 sales and receivables.
- Phase 15 purchases and payables.
- Phase 16 credit and debit notes.
- Phase 17 fixed assets.
- Phase 18 currencies, exchange rates, and FX settings.
- Existing journal, cash/bank, accounting-period, and tax functionality.

Do not delete, weaken, or broadly rewrite existing tests to make the suite pass.

## 11. Quality Gates

Run the available commands from the actual frontend directory:

1. `npm run lint`
2. `npx eslint . --max-warnings=0`
3. `npx tsc --noEmit`
4. `npx vitest run`
5. `npm run build`

If a command does not exist, inspect the existing `package.json` and report the
available equivalent. Do not modify package scripts just to accommodate the
prompt.

Fix issues introduced by Phase 19. Avoid unrelated refactoring.

Record the real exit status and relevant counts for every gate. Do not claim a
check passed unless it ran successfully.

## 12. Live Integration and Database Safety

Do not run migrations, seeders, or database-changing commands.

If an already-configured development backend is available, use it only for safe,
non-destructive verification that does not expose secrets or modify data.

Do not create, edit, delete, approve, or post accounting data in a live or
shared environment without explicit authorization.

Clearly distinguish:

- Unit and component tests.
- Static analysis and TypeScript checks.
- Production build verification.
- Read-only live API checks.
- Browser end-to-end testing.

If live integration is not performed, state so explicitly.

## 13. Required Phase Report

Create:

`accounting-app/docs/FRONTEND_PHASE_19_REPORT.md`

Follow the style and detail level of the previous frontend phase reports.

Include:

1. Phase summary and completion status.
2. Initial repository root, branch, HEAD, and Git status.
3. Pre-existing changes identified before implementation.
4. Verified budget and dimension API contracts.
5. Exact endpoints, payloads, response shapes, filters, and permissions used.
6. Implemented routes, components, API clients, validators, hooks, and
   navigation changes.
7. Budget Variance and dimension-filter behavior.
8. Accounting precision and currency-handling decisions.
9. Tests added and total test-suite results.
10. Lint, strict ESLint, TypeScript, Vitest, and build results.
11. Live integration status.
12. Unsupported features and backend contract gaps.
13. Known limitations and suggested future work.
14. Final Git status, including all modified and untracked files.
15. Confirmation that no prohibited backend, database, dependency, or Git
    operations were performed.

Do not overwrite Phase 17 or Phase 18 reports. Preserve all pre-existing
prompts, reports, and untracked files.

## 14. Completion Criteria

Phase 19 is complete only when:

- The existing repository state and user work are preserved.
- The actual backend contract has been inspected.
- Every implemented feature is supported by a real endpoint and permission.
- Dimension management follows the backend's actual data model.
- Budget creation and editing follow the actual accounting and lifecycle rules.
- Budget Variance filters use the correct parameters and returned data.
- Financial amounts retain exact decimal handling.
- No unsupported accounting calculations or workflows have been fabricated.
- Focused tests and existing regression tests pass.
- All available quality gates have been run and accurately reported.
- `docs/FRONTEND_PHASE_19_REPORT.md` is complete.
- No backend changes, dependency changes, migrations, seeders, destructive Git
  operations, commits, or pushes have occurred.

## 15. Final Stop Condition

Work methodically:

1. Inspect the repository and preserve the working tree.
2. Verify the backend contracts.
3. Implement the supported functionality.
4. Add tests and regression coverage.
5. Run the quality gates.
6. Write the Phase 19 report.
7. Review the final Git status.

**Stop after Phase 19. Do not start Phase 20 or Phase 21. Do not commit or
push.**

Return a concise summary of completed functionality, the verified test/build
results, any backend gaps, and the report path.
