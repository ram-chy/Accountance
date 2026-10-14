# Accountance Frontend — Phase 17: Fixed Assets & Depreciation

**Project:** Accountance Accounting Web App  
**Phase:** 17  
**Scope:** Frontend only  
**Primary Modules:** Fixed Asset Register, Asset Categories, Depreciation, Asset
Disposal, Fixed Asset Reports  
**Backend Changes:** Strictly prohibited  
**Git Commit/Push:** Prohibited unless explicitly authorized separately  
**Phase Report:** `accounting-app/docs/FRONTEND_PHASE_17_REPORT.md`

---

## 1. Objective

Implement the Fixed Assets frontend using the existing Laravel API and
established Accountance frontend architecture.

The goal is to provide a reliable interface for managing fixed assets and their
accounting lifecycle, including acquisition, depreciation, disposal, and related
reporting where supported by the backend.

The scope includes:

1. Fixed asset register.
2. Fixed asset categories or classes, if supported.
3. Fixed asset creation and editing.
4. Asset acquisition and capitalization details.
5. Depreciation configuration.
6. Depreciation calculation or preview, if supported.
7. Depreciation posting.
8. Asset disposal and disposal posting.
9. Asset detail and accounting history.
10. Fixed asset reports and summaries, if supported.
11. Navigation and permission handling.

**Critical rule:** Inspect the existing backend implementation before deciding
the actual feature set. Do not invent depreciation methods, asset lifecycle
states, disposal rules, or API endpoints.

This is a frontend-only phase. Do not implement multi-currency/FX, budgets,
dimensions, company administration, or Phase 18 work.

## 2. Mandatory Repository Preservation

The Phase 16 report states that Phases 14–16 remain uncommitted, including
customer/supplier workflows, reports, credit notes, debit notes, navigation
changes, and tests.

Treat all existing tracked modifications and untracked files as user-owned work.

### 2.1 Initial inspection

Before editing, inspect and record:

- Repository root.
- Current branch and HEAD.
- `git status --short --branch`.
- `git diff --stat`.
- Tracked modifications and untracked files.
- Existing Phase 14, Phase 15, and Phase 16 reports.
- Existing prompts for those phases.
- Current frontend architecture and navigation.
- Fixed asset backend routes, controllers, services, models, policies, request
  validators, resources, and tests.

Do not assume the working tree still matches the Phase 16 report. Inspect it
afresh.

### 2.2 Prohibited Git operations

Do not run:

- `git pull`
- `git fetch`
- `git reset`
- `git stash`
- `git clean`
- `git checkout`
- `git switch`
- `git restore` to discard changes
- `git commit`
- `git push`
- `git commit --amend`

Do not change branches, discard user work, delete unrelated files, or clean up
unfamiliar files automatically.

If unexpected changes or conflicts exist, preserve them and report the blocker.

### 2.3 File boundaries

- Do not modify anything under `accounting-app/backend/`.
- Do not modify backend routes, controllers, services, models, policies,
  migrations, permissions, or tests.
- Do not run migrations or seeders.
- Do not install, remove, or upgrade dependencies.
- Do not modify `package.json` or lockfiles.
- Do not delete or weaken existing tests.
- Do not overwrite previous phase reports.
- Do not refactor unrelated modules.
- Do not commit or push.

Create:

`accounting-app/docs/FRONTEND_PHASE_17_REPORT.md`

If the report already exists, preserve it and use a different filename.

---

## 3. Backend Contract Discovery

Inspect the actual fixed asset implementation before writing frontend code.

Inspect the applicable source files under `accounting-app/backend/`, including:

- `routes/api.php` and any related route registration.
- Fixed asset controllers.
- Fixed asset request validators.
- Fixed asset services.
- Fixed asset models and relationships.
- API resources and response transformations.
- Policies and permission registration.
- Depreciation calculation and posting services.
- Asset disposal services.
- Journal creation and posting behavior.
- Accounting period validation.
- Chart-of-accounts integration.
- Fixed asset backend tests.
- Relevant accounting configuration and documentation.

Use the actual paths present in the repository.

### 3.1 Contract inventory

Document the verified contracts before implementation.

| Area                 | Verify                                                                   |
| -------------------- | ------------------------------------------------------------------------ |
| Asset register       | List, search, filters, pagination, detail                                |
| Asset creation       | Required fields, identifiers, acquisition values, dates                  |
| Asset updates        | Editable fields and lifecycle restrictions                               |
| Categories           | Whether a separate category resource exists                              |
| Accounts             | Required asset, accumulated depreciation, expense, and disposal accounts |
| Depreciation         | Supported methods, useful life, residual value, frequency, start date    |
| Depreciation preview | Whether a preview or schedule endpoint exists                            |
| Depreciation posting | Supported route, payload, lifecycle, period restrictions                 |
| Disposal             | Supported disposal types, proceeds, dates, gain/loss behavior            |
| Reports              | Actual report endpoints, filters, totals, and row structure              |
| Permissions          | Exact permission keys and policy checks                                  |
| Statuses             | Actual asset and transaction lifecycle states                            |
| Company scope        | Company isolation and cross-company ID behavior                          |
| Money and dates      | Decimal precision, date semantics, and supported currency fields         |

For every implemented endpoint, record:

- HTTP method and route.
- Query parameters.
- Request payload.
- Response payload.
- Permission key.
- Lifecycle restrictions.
- Validation and error behavior.
- Company-scoping behavior.

Do not invent endpoints or response fields. If a requested capability is not
supported, document the limitation.

---

## 4. Preserve the Existing Frontend Architecture

Use the existing:

- Next.js App Router.
- React and strict TypeScript.
- Tailwind CSS v4.
- Same-origin Next.js BFF.
- Encrypted HttpOnly session cookie.
- Server-held Laravel JWT.
- Server-generated `Authorization` and `X-Company-Id` headers.
- Existing API client and proxy.
- Existing runtime validation patterns.
- Existing hooks and abort handling.
- Existing UI components and form conventions.
- Existing money and date formatting utilities.
- Existing Vitest and React Testing Library setup.
- Existing permission-aware navigation.

Inspect the implementations of journals, chart of accounts, accounting periods,
cash/bank, and reporting before implementing fixed assets. Reuse established
conventions where appropriate.

Do not introduce a new frontend architecture or data-fetching library.

### Security requirements

- Never expose the JWT to browser JavaScript.
- Never call the Laravel API directly from browser components.
- Route requests through the existing BFF.
- Never trust a browser-supplied company ID for authorization.
- Do not bypass the existing proxy allow-list.
- Do not leak internal exceptions or sensitive backend details.
- Preserve company isolation and permission checks.
- Prevent stale company data from remaining visible after a company switch.

If a required endpoint is not supported by the current proxy, investigate the
existing architecture safely. Do not bypass security restrictions or modify the
backend.

---

## 5. Proposed Routes

Use these route names where they fit the existing conventions and verified
capabilities:

- `/fixed-assets`
- `/fixed-assets/new`
- `/fixed-assets/[id]`
- `/fixed-assets/[id]/edit`
- `/fixed-assets/depreciation`
- `/fixed-assets/disposals`
- `/fixed-assets/reports`

If the backend supports asset categories as a separately managed resource,
consider:

- `/fixed-assets/categories`

Do not create placeholder pages that imply a feature is functional when its
required backend support is absent.

Inspect existing route groups before creating files to avoid collisions.

Document the final routes in the Phase 17 report.

---

## 6. Fixed Asset Register

Implement the fixed asset register using the actual list and detail contracts.

### 6.1 Register list

Support only filters actually accepted by the backend, potentially including:

- Search by asset code or name.
- Asset status.
- Category or class.
- Acquisition date range.
- Depreciation status.
- Pagination.

Display available information such as:

- Asset code.
- Asset name or description.
- Category.
- Acquisition date.
- Acquisition cost.
- Residual value.
- Useful life.
- Accumulated depreciation.
- Net book value.
- Asset status.

Only display fields returned by the API or derived through an explicitly
supported calculation.

Do not invent totals or pagination metadata.

If the backend exposes only flat arrays, follow the existing `hasMore`
convention instead of fabricating `links`, `meta`, or total counts.

### 6.2 Asset detail

Display the actual asset fields and relationships supported by the API.

Where available, include:

- Asset identity and description.
- Acquisition date and cost.
- Capitalization or in-service date.
- Category.
- Useful life.
- Depreciation method.
- Residual value.
- Depreciation start date.
- Asset account.
- Accumulated depreciation account.
- Depreciation expense account.
- Accumulated depreciation.
- Net book value.
- Current lifecycle status.
- Depreciation history.
- Disposal history.
- Related journal references.

Do not display fabricated ledger balances or transaction history.

If the backend does not expose a relationship, do not assume that a client-side
lookup can safely reconstruct it.

### 6.3 Asset creation and editing

Inspect the actual create/update requests and field restrictions.

Potential fields may include asset code, name, description, acquisition date,
acquisition cost, useful life, residual value, depreciation method, in-service
date, and account mappings. Include only fields the backend accepts.

Requirements:

- Mirror backend validation.
- Exclude server-owned fields from write payloads.
- Respect nullable fields.
- Prevent duplicate submissions.
- Preserve entered data when validation fails.
- Display field-level errors.
- Respect draft, active, disposed, and other actual lifecycle restrictions.
- Do not create an acquisition journal directly from the browser.
- Do not imply that an asset has been capitalized until the backend confirms the
  operation.

If asset creation is separate from acquisition posting, reflect that separation
in the UI.

---

## 7. Asset Categories and Account Mappings

First determine whether categories are a distinct backend resource or merely an
attribute of an asset.

If category CRUD is supported, implement the supported list, create, update, and
lifecycle operations.

If categories are predefined or managed outside the API, use the supported
options endpoint or existing data source. Do not create a local-only
category-management feature.

### Account selectors

Where account mappings are required, reuse the existing Chart of Accounts API
and selector patterns.

Verify the required account types and constraints from backend validators and
services.

Potential mappings include:

- Fixed asset account.
- Accumulated depreciation account.
- Depreciation expense account.
- Disposal gain account.
- Disposal loss account.

These are examples only. Implement the actual backend-supported fields and
rules.

Requirements:

- Respect `accounts.view` and any relevant accounting permissions.
- Filter account choices only when the backend contract supports or the source
  clearly establishes the relevant type.
- Do not send account names when the API expects IDs.
- Do not assume every asset requires all possible mappings.
- Display backend validation errors for invalid account combinations.

Do not change the Chart of Accounts module unless a minimal integration fix is
essential.

---

## 8. Depreciation Management

Depreciation is an accounting operation, not merely a display calculation.
Follow the backend's actual rules.

### 8.1 Discover supported methods

Inspect the depreciation service and tests to establish:

- Supported depreciation methods.
- Useful-life constraints.
- Residual-value constraints.
- Depreciation start-date rules.
- Frequency and period boundaries.
- Rounding rules.
- Treatment of partially used periods.
- Whether depreciation is calculated per asset or in batches.
- Whether depreciation schedules are persisted or calculated on demand.
- How closed accounting periods are handled.
- Whether posting can be repeated safely.
- How posted depreciation affects asset book value.

Do not assume straight-line depreciation is the only method or that all standard
methods are implemented.

Expose only supported methods and constraints.

### 8.2 Depreciation schedule or preview

If the backend provides a preview or schedule endpoint, implement the
corresponding view.

Display only values supported by the response, potentially including:

- Asset.
- Period.
- Opening net book value.
- Depreciation amount.
- Accumulated depreciation.
- Closing net book value.
- Posting status.
- Journal reference.

Do not independently generate an authoritative depreciation schedule in the
browser.

If no preview endpoint exists, do not fabricate a schedule. A limited read-only
view of posted depreciation history may be implemented if the API exposes it.

### 8.3 Depreciation posting

If the backend supports depreciation posting, implement the verified workflow.

Requirements:

- Respect exact posting permissions.
- Respect accounting period status.
- Respect asset eligibility and status.
- Prevent duplicate submissions.
- Confirm irreversible operations.
- Show the server-returned result.
- Refresh asset details, history, and relevant reports after success.
- Handle validation failures and conflicts, including attempts to post into
  closed periods or duplicate a posting.

Do not generate or submit journal entries directly from the browser unless the
existing backend explicitly defines that as the supported user workflow. In
particular, do not bypass the dedicated depreciation service.

If the backend posts depreciation in batches, reflect that behavior instead of
pretending that each asset can be posted independently.

---

## 9. Asset Disposal

Inspect the actual disposal implementation before designing this workflow.

Determine whether disposal supports:

- Partial or full disposal.
- Disposal date.
- Sale proceeds.
- Disposal fees.
- Asset condition or reason.
- Gain/loss calculation.
- Accumulated depreciation handling.
- Partial-period depreciation.
- Disposal journal posting.
- Cancellation or reversal.
- Disposal of an already-depreciated asset.
- Restrictions based on status or accounting period.

Do not assume that all these operations exist.

### 9.1 Disposal form

Include only fields supported by the verified contract.

Use existing selectors for relevant cash/bank accounts or other mappings if
required by the backend.

Provide clear confirmation before irreversible disposal or posting actions.

### 9.2 Disposal detail

Display server-returned information such as:

- Asset.
- Disposal date.
- Proceeds.
- Fees.
- Net book value at disposal.
- Gain or loss.
- Status.
- Journal reference.

Do not calculate a definitive accounting gain/loss independently unless the
backend explicitly exposes a preview contract that defines the same calculation.
Server-returned results remain authoritative.

### 9.3 Lifecycle

Expose only supported transitions.

A disposed asset must not appear freely editable or eligible for depreciation if
the backend prohibits those operations.

Handle server-side conflicts safely and refresh all affected records after a
successful disposal.

---

## 10. Fixed Asset Reporting

Inspect the available fixed asset report endpoints before implementing report
pages.

Potential reports include:

- Fixed asset register.
- Depreciation schedule.
- Depreciation history.
- Accumulated depreciation.
- Net book value.
- Asset disposal summary.

Implement only reports supported by the API.

For each report:

- Use verified query parameters.
- Parse the real response schema.
- Display server-provided totals.
- Respect date boundaries and accounting period restrictions.
- Use exact monetary formatting.
- Provide drill-downs only where the relevant entity or route is known.
- Support loading, empty, error, and retry states.

Do not derive a fixed asset register by merging unrelated API responses unless
the backend contract explicitly supports that approach and the required data is
complete.

If no report endpoint exists, document the missing capability instead of
creating a misleading report.

---

## 11. Navigation and Permissions

Inspect backend permission registration and existing
`src/lib/navigation/nav.ts`.

Add navigation entries for implemented pages using exact permission keys.

Verify permissions for:

- Asset viewing.
- Asset creation.
- Asset updates.
- Asset activation or other lifecycle operations.
- Depreciation viewing.
- Depreciation posting.
- Disposal creation.
- Disposal posting.
- Report viewing.
- Category management, if supported.

Do not guess permission names.

Use the existing navigation permission-filtering mechanism and update navigation
tests.

Navigation visibility is not an authorization boundary. The backend remains
authoritative.

If a feature lacks a required permission or endpoint, do not expose an
operational link to a nonfunctional page.

---

## 12. API Types, Validators, Hooks, and Components

Follow existing feature organization.

Create feature-local modules as appropriate for the verified capabilities,
including:

- Types.
- Runtime validators.
- API functions.
- Data hooks.
- List and detail components.
- Forms.
- Tests.

Use the established frontend patterns from the existing accounting, journals,
periods, reporting, and other business modules.

Requirements:

- Validate untrusted API response data.
- Use strict TypeScript.
- Use `unknown` and explicit narrowing where appropriate.
- Do not use `any`.
- Do not blindly cast API responses.
- Forward abort signals where supported.
- Avoid stale asynchronous responses overwriting newer results.
- Reuse the existing API error helpers.
- Handle company context and permission changes correctly.
- Keep server-owned fields out of write requests.
- Do not introduce dependencies.

Do not refactor shared utilities without a direct, necessary reason.

---

## 13. Monetary Precision, Dates, and Accounting Integrity

### Monetary precision

- Keep monetary values as decimal strings at API boundaries.
- Use the existing `formatMoney` and formatting conventions.
- Do not use JavaScript floating-point arithmetic for accounting calculations.
- Respect the backend's rounding behavior.
- Do not calculate authoritative accumulated depreciation or net book value from
  partial client data.
- Use server-returned totals wherever available.

### Dates

- Use existing date utilities.
- Avoid timezone shifts for date-only values.
- Distinguish acquisition date, in-service date, depreciation period, posting
  date, and disposal date.
- Respect the backend's fiscal-period boundaries.
- Do not invent timestamps.

### Lifecycle and posting

- Clearly distinguish unposted operations from posted accounting transactions.
- Never simulate successful posting by updating local status alone.
- Respect immutable posted records.
- Never bypass accounting-period restrictions.
- Refresh affected data after successful mutations.
- Use explicit confirmation for irreversible operations.

### Company isolation

- Never send a client-owned company ID for authorization.
- Use the established session and BFF company context.
- Clear or invalidate relevant data after company switching.

---

## 14. UI and UX Requirements

Maintain the existing Accountance design system:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Clean, minimal layouts.
- Responsive desktop, tablet, and mobile views.
- Consistent forms, tables, badges, dialogs, spacing, and typography.
- Accessible labels and keyboard navigation.
- Semantic status colors.

Every implemented page must handle:

- Loading.
- Empty results.
- API errors.
- Permission denial.
- Missing or inaccessible records.
- Field-level validation.
- Pending mutations.
- Duplicate submission prevention.
- Confirmation for irreversible operations.

Use existing components where possible. Do not introduce an unrelated dashboard
redesign.

---

## 15. Automated Tests

Use the existing Vitest and React Testing Library setup. Do not install testing
dependencies.

### Asset register tests

- Response parsing.
- Search and supported filters.
- Pagination behavior.
- Detail rendering.
- Create/update payload validation.
- Permission and company gating.
- Loading, empty, and error states.

### Depreciation tests

- Supported method options.
- Schedule/preview response parsing, if available.
- Exact monetary formatting.
- Period and asset eligibility handling.
- Posting payload and HTTP method.
- Duplicate-submission prevention.
- Conflict and validation error handling.
- Posted-history rendering.

### Disposal tests

- Disposal request validation.
- Detail response parsing.
- Supported proceeds and fee fields.
- Server-returned gain/loss presentation.
- Lifecycle restrictions.
- Posting or disposal confirmation.
- Duplicate submission and error handling.

### Reporting and navigation tests

- Actual report response parsing.
- Server-returned totals.
- Filters and date boundaries.
- Drill-down navigation.
- Exact permission keys.
- Hidden links without permissions.
- Existing navigation assertions remain valid.
- Company-switch stale-data behavior.

Test only features actually implemented. Where the backend lacks a capability,
test the appropriate unsupported or unavailable state if one is exposed to
users.

Do not delete or weaken existing tests.

---

## 16. Quality Gates

Inspect the existing `package.json` scripts and use the project's established
commands.

Run from `accounting-app/frontend`:

1. `npm run lint`, if the script exists.
2. `npx eslint . --max-warnings=0`.
3. `npx tsc --noEmit`.
4. `npx vitest run`.
5. `npm run build`.

If a script does not exist, use the established equivalent and explain the
substitution.

Fix all Phase 17 lint errors and warnings without disabling rules or weakening
TypeScript.

Do not install, remove, or upgrade dependencies.

Record exact commands, exit codes, and results in the Phase 17 report.

If a command fails, investigate and fix in-scope problems. Do not claim the
quality gate passed if it remains red.

---

## 17. Live Integration

Earlier frontend phase reports state that live backend/database integration has
not been performed.

Do not claim live integration unless the frontend has actually been tested
against a running backend and database.

Do not start or reconfigure the backend, modify databases, run migrations, or
seed data without explicit authorization.

If live integration is unavailable:

- Verify contracts through backend source inspection and existing tests.
- Complete frontend automated tests.
- Record live integration as not performed.
- Document the smoke tests needed for Phase 21.

Recommended future smoke tests:

1. Asset creation and validation.
2. Asset detail and account mapping.
3. Depreciation preview or schedule, if supported.
4. Depreciation posting.
5. Duplicate depreciation prevention.
6. Closed-period posting rejection.
7. Accumulated depreciation and net book value after posting.
8. Asset disposal and accounting result.
9. Disposal of ineligible or already-disposed assets.
10. Fixed asset reports and totals.
11. Company isolation.
12. Permission-denied responses.
13. Posted-record immutability.
14. Date and rounding boundaries.

---

## 18. Phase 17 Report

Create:

`accounting-app/docs/FRONTEND_PHASE_17_REPORT.md`

Include:

1. Executive summary.
2. Initial branch, HEAD, and Git status.
3. Repository-preservation confirmation.
4. Verified backend endpoint inventory.
5. Exact permission keys.
6. Asset register implementation.
7. Asset category and account mapping behavior.
8. Depreciation methods and supported workflow.
9. Depreciation preview, schedule, and posting behavior.
10. Disposal workflow and accounting results.
11. Reports and filters.
12. Files added and modified.
13. Test files and total test count.
14. Exact lint, ESLint, TypeScript, test, and build results.
15. Live integration status.
16. Missing backend contracts and blocked functionality.
17. Known limitations and risks.
18. Final Git status.
19. Confirmation that backend files, dependencies, previous reports, and
    unrelated user work were preserved.

Clearly distinguish between:

- Verified by backend source inspection.
- Verified by automated tests.
- Verified by production build.
- Verified against a live backend.
- Not verified.

Do not claim completion of unsupported functionality.

---

## 19. Acceptance Criteria

### Fixed asset register

- Supported asset list, detail, creation, and editing workflows work.
- Fields and validation match the backend.
- Account mappings use verified contracts.
- Lifecycle restrictions are respected.

### Depreciation

- Supported methods and constraints are reflected accurately.
- Any preview or schedule uses the actual API contract.
- Posting follows backend permissions and accounting-period rules.
- Duplicate and invalid operations are handled safely.
- Book values are not presented as authoritative when they have not been
  verified.

### Disposal

- Supported disposal workflow is implemented.
- Required account mappings and inputs match the backend.
- Disposal accounting results come from the backend.
- Ineligible lifecycle transitions are prevented or safely rejected.

### Reporting and navigation

- Implemented reports match actual API contracts.
- Navigation uses exact permission keys.
- Existing modules remain functional.
- Company isolation and session security remain intact.

### Quality and preservation

- Required quality gates pass.
- New workflows have meaningful automated tests.
- No backend files or dependencies are modified.
- No previous reports or unrelated user work are overwritten.
- No prohibited Git operations occur.
- No commit or push is performed.
- The Phase 17 report accurately documents the outcome.

If a backend capability is missing, mark the affected criterion as blocked. Do
not fabricate endpoints or implement accounting logic in the frontend to
compensate.

---

## 20. Stop Condition

After Phase 17:

- Stop implementation work.
- Do not start Phase 18.
- Do not implement multi-currency/FX.
- Do not implement budgets or dimensions.
- Do not implement company administration.
- Do not perform unrelated refactoring.
- Do not commit or push.

Provide a concise final summary containing:

- Implemented modules and routes.
- Verified backend capabilities.
- Depreciation and disposal behavior.
- Test count and results.
- Lint, TypeScript, and build results.
- Known blockers.
- Phase 17 report path.
- Confirmation that existing work was preserved.

**Begin with repository inspection and backend contract discovery. Implement
only the fixed asset functionality supported by the existing backend. Stop at
Phase 17.**
