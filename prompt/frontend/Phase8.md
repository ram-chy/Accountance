# Accountance Frontend — Phase 8: Financial Years, Accounting Periods & Period Closing UI

## 1. Objective

Implement **Frontend Phase 8 only** for the Accountance Accounting Web App.

Build the Financial Years and Accounting Periods management UI using the
existing Laravel API and the established Next.js frontend architecture.

The module must support the backend capabilities that are actually implemented:

- Financial year listing, creation, and editing where supported.
- Financial year status and date-range display.
- Monthly accounting-period generation from a financial year.
- Accounting-period listing, filtering, creation, and editing where supported.
- Period closing-readiness review.
- Authorized period closing and reopening.
- Financial-year finalization when all backend prerequisites are satisfied.
- Clear display of validation errors, blocking controls, warnings, and lifecycle
  states.

**This is a frontend-only phase.** Do not modify the Laravel backend, database
schema, migrations, seeders, or existing accounting business logic.

Do not start Phase 9.

---

## 2. Mandatory Pre-Implementation Inspection

Before editing any file, inspect the current repository and the actual source
code.

### Frontend documentation

Read the latest versions of:

- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_API_HANDOFF.md`
- `docs/FRONTEND_PHASE_0_REPORT.md`
- Relevant Phase 1–7 implementation reports.
- Existing project rules and OpenCode instructions.
- The existing frontend documentation covering routes, permissions, API helpers,
  and company context.

Locate the repository's actual frontend and backend directories rather than
assuming the working directory.

### Existing frontend implementation

Inspect:

- Authentication and session handling.
- Company selection and company-switch behavior.
- Secure backend proxy and API helpers.
- Permission utilities and navigation.
- Existing account, journal, dashboard, and cash/banking modules.
- Existing date, status, dialog, table, and confirmation components.
- Runtime API validators and test conventions.
- Existing fiscal-year or accounting-period UI, if any.

Reuse the established architecture. Do not duplicate shared functionality.

### Actual Laravel backend contract

Read the relevant source files directly, including:

- `routes/api.php`
- Financial year and accounting period controllers.
- Corresponding form requests, resources, services, models, and enums.
- Authorization configuration, policies, and permission definitions.
- Closing-check/control services.
- Relevant accounting-period and financial-year feature tests.

The backend implementation is the source of truth. Earlier reports are
supporting documentation, not substitutes for inspecting the current source.

Before implementing the UI, record the exact API contract for every supported
operation:

| Operation                       | Required verification                                |
| ------------------------------- | ---------------------------------------------------- |
| List financial years            | Method, path, filters, pagination, response shape    |
| Create financial year           | Supported fields, date rules, response               |
| Update financial year           | Editable fields and closed-year restrictions         |
| Generate accounting periods     | Exact endpoint, request, idempotency, response       |
| List accounting periods         | Supported filters and response shape                 |
| Create/update accounting period | Actual support, required fields, overlap rules       |
| Read closing readiness          | Endpoint and complete finding structure              |
| Close a period                  | Endpoint, permission, blockers, success response     |
| Reopen a period                 | Endpoint, permission, restrictions, success response |
| Finalize a financial year       | Endpoint, permission, preconditions, response        |

Record the exact permissions and error formats. Do not invent an endpoint,
field, permission, status, or operation because it seems conventional.

If an operation is not supported by the existing backend, do not implement a
fake client-side equivalent. Document the limitation.

---

## 3. Non-Negotiable Architecture and Safety Rules

Follow all existing project conventions.

- Next.js App Router, React, strict TypeScript, and Tailwind CSS v4.
- All browser-originated backend requests must use the existing same-origin
  `/api/backend/*` proxy and established API helpers.
- Never expose the JWT to client-side JavaScript.
- Never add tokens, server-held company context, or secrets to client storage.
- Never trust a client-supplied company ID as authorization.
- The backend remains authoritative for all permissions, date rules, overlaps,
  lifecycle transitions, and close eligibility.
- Runtime-validate API responses using the existing validation conventions.
- Handle malformed or unknown status values safely.
- Abort obsolete requests and prevent stale results from appearing after company
  switches.
- Do not introduce TanStack Query, React Hook Form, Zod, or other dependencies
  without a demonstrated need and explicit justification.
- Do not introduce another API client, session mechanism, accounting state
  store, or permission system.
- Do not add calculated or stored accounting balances. Fiscal years and
  accounting periods are organizational and control structures, not monetary
  ledgers.

Do not change the existing backend implementation to make frontend development
easier.

---

## 4. Routes and Navigation

Use the current frontend route conventions and reconcile them with
`FRONTEND_BLUEPRINT.md`.

Prefer a clear route structure such as:

- `/financial-years`
- `/periods`

However, first inspect existing routes and the documented architecture. If the
project already has an established alternative, follow it and document any
intentional deviation.

Do not create multiple aliases for the same feature without a clear requirement.

Add the appropriate navigation entries under the existing Accounting section.
Gate navigation and actions using the actual backend permissions.

Do not reorganize the existing Accounts, Journals, Cash & Banking, or Dashboard
navigation.

---

## 5. Financial Years UI

Implement the financial-year management screen using the verified backend
contract.

### List view

Display only fields actually returned by the API, such as:

- Financial year name or label.
- Start date.
- End date.
- Status.
- Available period count, if actually returned or safely derived from loaded
  data.
- Supported actions.

Provide filters or search only where the API or existing client-side dataset
supports them accurately.

Show clear loading, error, empty, and populated states.

### Create and edit

Implement create/edit forms only for operations the backend supports.

Validate the actual fields and date constraints before submission, while
treating backend validation as authoritative.

Important:

- Financial years must not overlap when the backend prohibits overlap.
- Do not assume the year follows a calendar year.
- Do not assume a financial year starts in January.
- Do not silently change a company's fiscal-year configuration.
- Do not expose server-owned fields such as IDs, company IDs, status, or audit
  fields as editable inputs.
- A closed financial year must not appear editable if the backend prohibits
  changes.

### Financial-year finalization

Offer year finalization only when the verified permission and backend contract
allow it.

Before finalization:

1. Explain that finalization is a consequential accounting action.
2. Display the selected financial year and its dates.
3. Display known prerequisites, such as all periods being closed, when verified
   by the backend.
4. Require explicit confirmation.
5. Submit the actual finalization request.
6. Display the backend result or actionable validation errors.
7. Refresh the affected year and period data.

Never represent year finalization as a normal edit.

Do not implement a financial-year reopen action unless the current backend
explicitly supports it. The documented backend design states that financial-year
reopening is not implemented; verify that this remains true.

Do not create a closing journal or opening-balance workflow.

---

## 6. Accounting Periods UI

### Period list

Display the accounting periods with available fields such as:

- Period name.
- Start date.
- End date.
- Parent financial year.
- Status: `OPEN` or `CLOSED`.
- Close/reopen timestamps or actors only if returned by the API.
- Supported lifecycle actions.

Provide accurate filtering by financial year, status, and date range where
supported.

If the API returns a flat array without pagination metadata, follow the
established `normalizeList` conventions. Do not fabricate total counts or page
counts.

Use inclusive date ranges consistently with the backend contract.

### Create and edit

Implement only supported operations.

Preserve these rules:

- Do not allow overlapping periods through client-side validation when the
  relevant dates are available, but still rely on the backend for authoritative
  enforcement.
- A period belongs to the correct financial year according to the verified API
  contract.
- Do not allow editing a closed period when the backend rejects it.
- Do not let users directly edit status or audit fields.
- Do not infer the current period from the browser's date if the backend
  supplies the authoritative period association.
- Do not automatically reopen, close, or modify another period when editing a
  date range.

When a backend `422` reports an overlap or invalid year association, display the
actual error clearly.

### Generate monthly periods

If the existing backend exposes period generation, implement the corresponding
action on a financial-year detail area or an appropriate dialog.

Before triggering generation:

- Identify the selected financial year.
- Explain that the backend generates monthly periods within that year's date
  boundaries.
- Warn that the operation may create missing periods.
- Require explicit confirmation if the operation changes data.

Respect the verified backend semantics:

- Generation is idempotent.
- Existing periods must not be overwritten or duplicated.
- Generated periods are clipped to the financial year's start and end dates.
- The operation is scoped to the active company.

Do not generate periods in the browser. Do not simulate successful generation
locally. Submit the real backend request and refresh the relevant data.

If the endpoint returns the generated or existing periods, use that response.
Otherwise, reload the periods through the supported list API.

---

## 7. Period Closing Readiness Review

This is an important part of Phase 8.

The backend supports a read-only closing-check endpoint:

`GET /api/accounting/periods/{period}/closing-check`

Verify the current route and response directly from source before implementing
it.

The backend closing review may return fields including:

- Period details.
- `eligible`.
- `already_closed`.
- Summary counts for `PASS`, `WARNING`, and `FAIL`.
- `blocking_findings`.
- `warning_findings`.
- All `findings`.
- `required_action`.

Confirm the exact structure and validate every field the UI uses.

### Review UI

Provide a clear way to review a period before closing it.

The review should communicate:

- Whether the period is eligible to close.
- How many passing, warning, and failing controls exist.
- Each finding's severity, code, message, and required action when those fields
  exist.
- Which findings block closing.
- Whether the period is already closed.
- What the user should do next.

Use neutral, accessible status styling. Do not make warnings look like blocking
failures.

Do not invent control findings, assume a period is ready because the UI has no
errors, or implement the control logic a second time in the browser.

If the review endpoint fails, show an error and do not imply that closing is
safe.

Reading the review should use the actual view permission; do not incorrectly
require the close permission just to explain why a period cannot close.

---

## 8. Period Close and Reopen Lifecycle

Verify the current endpoints and permissions before implementing these actions.

Expected operations, subject to source verification:

- Close: `POST /api/accounting/periods/{period}/close`
- Reopen: `POST /api/accounting/periods/{period}/reopen`

### Close

- Show the closing-readiness review before confirmation.
- Clearly explain blocking findings.
- Disable or withhold the close action when the latest successful review says
  the period is ineligible.
- Require explicit confirmation for closing.
- Treat the backend's response as authoritative because eligibility can change
  between review and submission.
- On `422`, display the actual validation or blocking findings.
- On `403`, explain that the user lacks permission.
- On `404`, handle the resource as unavailable in the current company context.
- Refresh the period, year, and relevant list data after success.

Do not rely solely on the client's previous review result.

### Reopen

- Show the reopen action only when the current status, permissions, and backend
  rules allow it.
- Require explicit confirmation.
- Explain that reopening affects whether dates in that period can be posted to
  again, subject to the financial-year state.
- Surface backend refusal messages, including when a closed financial year
  prevents reopening the period.
- Refresh affected data after success.

Do not add an invented `LOCKED` state. The established period lifecycle is
`OPEN` / `CLOSED`.

Do not bypass the backend by editing a status field.

---

## 9. Permissions and Authorization

Inspect the actual authorization configuration and policies.

Verify and apply the relevant permissions, including where present:

- `accounting.periods.view`
- `accounting.periods.create`
- `accounting.periods.update`
- `accounting.periods.close`
- `accounting.periods.reopen`

Financial-year operations may reuse the accounting-period policy. Confirm the
actual behavior rather than assuming a separate permission namespace.

Implement permission-aware:

- Navigation.
- Create/edit buttons.
- Generate-periods action.
- Close and reopen actions.
- Financial-year finalization.

Users who lack permission to view must not trigger protected list/detail
requests merely because a component mounts.

UI permission checks are usability controls, not security boundaries. The
Laravel backend must remain authoritative.

---

## 10. Company Isolation and Asynchronous State

Use the existing company-context architecture.

Verify that all financial-year and period hooks:

- React to the active company changing.
- Abort obsolete reads where supported.
- Reset or derive stale loading/error/data state correctly.
- Never display the previous company's period list after switching companies.
- Prevent a pending mutation from being submitted under a silently changed
  company context.

Do not add `company_id` to request payloads unless the verified contract
explicitly requires it. Company scoping is expected to be derived and validated
by the existing backend context.

A resource that belongs to another company must not be exposed through
client-side fallbacks.

---

## 11. UX and Accessibility

Follow the existing application design:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, readable layouts.
- Existing shared buttons, tables, badges, dialogs, alerts, and skeletons.
- Semantic colors for pass, warning, fail, errors, and destructive
  confirmations.
- Responsive layout for desktop, tablet, and mobile.
- Keyboard-accessible dialogs and controls.
- Visible focus states and accessible labels.
- Clear date formatting using existing date utilities.
- Consistent status labels and confirmation patterns.

Include:

- Initial loading states.
- Empty states.
- Error and retry states.
- Form validation states.
- Pending mutation states.
- Success feedback.
- Clear permission-denied behavior.
- Clear closing-blocked behavior.
- Safe handling of malformed responses.

Do not add decorative financial totals or calculations that the API does not
support.

---

## 12. Data Layer and Runtime Validation

Create or extend a dedicated feature module consistent with the existing
structure, for example:

- `src/lib/financial-years/`
- `src/lib/periods/`
- `src/components/financial-years/`
- `src/components/periods/`

Adapt the names if the existing project uses a different established convention.

Separate:

- Types and status constants.
- Runtime response validators.
- API functions.
- Data hooks.
- UI components.

Use the project's existing API helpers and error-handling conventions.

Runtime validators should reject or safely handle malformed responses. Unknown
status values must not be treated as valid `OPEN` or `CLOSED` values.

Do not duplicate API request logic inside page components.

Avoid excessive abstraction. Keep the module simple and consistent with Phases
5–7.

---

## 13. Testing Requirements

Use the existing Vitest and React Testing Library setup.

Add focused tests for the actual implementation.

### Validators and API

- Valid and malformed financial-year responses.
- Valid and malformed accounting-period responses.
- Closing-check response validation.
- Unknown status handling.
- Exact endpoint and payload serialization.
- Period-generation request.
- Close/reopen/finalization methods.
- Backend error normalization and `422` field errors.
- Abort signal propagation.

### UI

- Financial-year list, empty state, loading state, and error state.
- Period filtering and navigation.
- Create/edit forms where supported.
- Period-generation confirmation and refresh.
- Closing-review rendering with pass, warning, and fail findings.
- Blocking findings prevent the normal close flow.
- Close and reopen confirmation.
- Financial-year finalization confirmation.
- Permission-gated actions.
- Missing view permission causes no protected read.
- Company switching cannot display stale data.
- Mutation pending and failure states.
- Accessible dialog behavior.

Test meaningful behavior rather than adding shallow tests just to increase the
count.

Do not delete or weaken existing tests to make the suite pass.

---

## 14. Documentation

Create:

`docs/FRONTEND_PHASE_8_REPORT.md`

The report must include:

1. Executive summary and status.
2. Initial Git status and preservation of pre-existing work.
3. Backend contract verified from actual source.
4. Exact routes and supported actions implemented.
5. Permissions and UI behavior.
6. Period-generation behavior.
7. Closing-check, close, reopen, and year-finalization behavior.
8. Company-isolation behavior.
9. Files added and changed.
10. Tests added and final quality-gate results.
11. Whether live backend integration was performed.
12. Known limitations and deviations from the blueprint.
13. Confirmation that backend files were untouched.
14. Confirmation that no commits, resets, stashes, cleanup, migrations, or
    seeders were performed.

Update `FRONTEND_BLUEPRINT.md` or `FRONTEND_API_HANDOFF.md` only when a verified
decision or implementation change warrants it. Preserve existing documentation
and record deviations explicitly.

Do not claim live integration or backend behavior was tested unless it was
actually tested.

---

## 15. Quality Gates

Run from the actual frontend directory:

```bash
npm run lint
npx tsc --noEmit
npm test
npm run build
```

If a command is unavailable, report the exact reason and run the closest
supported equivalent without changing package scripts unnecessarily.

Fix errors introduced by Phase 8. Do not hide errors by weakening TypeScript,
disabling lint rules globally, or removing tests.

Record the actual results and test counts in the Phase 8 report.

---

## 16. Scope Boundaries

### Included

- Financial-year UI.
- Accounting-period UI.
- Monthly period generation using the backend.
- Closing-readiness review.
- Authorized period close/reopen.
- Financial-year finalization.
- Permissions, validation, loading/error states, company isolation, and tests
  for this module.

### Excluded

- Any Laravel backend changes.
- Database schema changes, migrations, and seeders.
- New accounting controls or closing rules.
- Opening-balance management.
- P&L closing journals or retained-earnings posting.
- Financial-year reopening unless already implemented by the current backend and
  explicitly included in the verified contract.
- Bank reconciliation.
- New general-ledger or reporting modules.
- Dashboard redesign.
- Unrelated refactoring or dependency adoption.
- Starting Phase 9.

Do not implement an operation merely because it appears in a future roadmap.

---

## 17. Git and User-Work Preservation Rules

All existing project work is uncommitted/untracked. Preserve it.

Before implementation, inspect:

```bash
git status --short
git diff --stat
git diff
```

Also inspect relevant untracked files without modifying or removing them.

Then:

- Do not run `git commit`.
- Do not run `git reset`.
- Do not run `git stash`.
- Do not run `git clean`.
- Do not checkout or switch branches.
- Do not discard or overwrite unrelated user changes.
- Do not edit any file under the Laravel backend directory.
- Do not create or execute migrations or seeders.
- Do not install dependencies without clear justification.

At the end, report:

```bash
git status --short
git status --short -- accounting-app/backend
```

Use the correct repository-relative paths if the actual project layout differs.

The backend status must remain unchanged by this phase.

---

## 18. Stop Condition

Phase 8 is complete only when:

- The supported Financial Year and Accounting Period UI is implemented.
- Period generation and lifecycle actions use verified backend endpoints.
- Closing readiness is displayed accurately from the backend.
- Permissions and company isolation follow existing architecture.
- Tests, lint, TypeScript checking, and production build pass.
- `docs/FRONTEND_PHASE_8_REPORT.md` is complete.
- All known limitations and deviations are documented.
- Existing user changes remain preserved.
- The Laravel backend is untouched.

**Stop after Phase 8. Do not start Phase 9 or perform unrelated cleanup.**

When finished, provide a concise completion summary and direct me to
`docs/FRONTEND_PHASE_8_REPORT.md` for review before the next phase.
