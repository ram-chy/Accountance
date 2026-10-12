# Accountance Frontend — Phase 10: Tax Management & Tax Reporting UI

## 1. Objective

Implement **Frontend Phase 10 only** for the existing Accountance Accounting Web
App.

Build a production-quality Tax Management module using the existing Laravel API
and Next.js frontend architecture.

The scope is:

1. Tax master list and detail/management interface.
2. Create and edit tax records.
3. Activate and deactivate taxes.
4. Tax rate management, including supported rate lifecycle actions.
5. Tax account mapping.
6. Read-only tax calculation tool.
7. Tax summary report.
8. Tax-by-tax report.
9. Permissions, company isolation, runtime validation, accessibility, and
   automated tests.

**The actual backend source is authoritative.** Verify all endpoints, request
fields, response shapes, validation rules, permissions, and lifecycle
restrictions before implementing each operation.

Do not invent missing API capabilities. If the backend does not support a
planned operation, document the gap and implement only the verified
capabilities.

This is a frontend-only phase. Do not modify the Laravel backend, migrations,
database schema, seeders, or accounting rules.

Stop after Phase 10. Do not start Phase 11.

---

## 2. Repository Preservation — Mandatory

All frontend work from previous phases remains uncommitted or untracked. The
Phase 9 report also documents pre-existing changes in
`src/lib/navigation/nav.ts` and pre-existing untracked backend files.

Preserve all existing work.

Before changing files:

1. Confirm the repository root and current branch.
2. Run `git status --short`.
3. Run `git diff --stat` and inspect relevant existing diffs.
4. Identify untracked files belonging to Phases 8 and 9, existing prompt files,
   reports, and backend work.
5. Read the current Phase 9 report and the existing frontend blueprint/API
   handoff.
6. Record the initial Git state in the Phase 10 report.

### Absolute restrictions

- Do not run `git pull` or `git fetch`.
- Do not run `git reset`, `git stash`, or `git clean`.
- Do not switch or checkout branches.
- Do not delete, discard, overwrite, or restore files to resolve conflicts.
- Do not commit changes.
- Do not replace existing documentation wholesale.
- Do not modify or remove unrelated user work.
- Do not run migrations or seeders.
- Do not change backend files.

If a required file is missing or repository state is ambiguous, stop the risky
operation and document the issue. Do not attempt automatic recovery by
overwriting current files.

---

## 3. Mandatory Inspection Before Coding

Read the current versions of:

- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_API_HANDOFF.md`
- `docs/FRONTEND_PHASE_9_REPORT.md`
- Relevant earlier frontend phase reports.
- Current navigation, authorization, API proxy, session, company-context, UI,
  and test implementations.

Inspect the actual Laravel source in the current repository, including:

- `routes/api.php`
- Tax controllers.
- Tax and tax-rate models and enums.
- Tax resources.
- Store/update form requests.
- Tax services and calculation services.
- Tax account-mapping implementation.
- Tax reporting services and resources.
- `config/authorization.php`
- Relevant feature tests.

Verify the API contract directly from source and tests. Do not rely solely on
the API handoff or earlier reports.

Before implementation, write a concise contract checklist for every operation
that Phase 10 intends to support.

If the repository source contradicts a prior report, follow the actual source
and document the discrepancy.

---

## 4. Verified Endpoint Families to Investigate

The existing API handoff identifies these endpoint families:

| Capability                      | Reported endpoint                             |
| ------------------------------- | --------------------------------------------- |
| List and create taxes           | `/api/accounting/taxes`                       |
| View, update, and delete a tax  | `/api/accounting/taxes/{tax}`                 |
| Activate/deactivate a tax       | Tax-specific lifecycle endpoints              |
| List/create tax rates           | `/api/accounting/taxes/{tax}/rates`           |
| View/update/delete a rate       | Tax-rate-specific endpoints                   |
| Activate/deactivate a rate      | Tax-rate-specific lifecycle endpoints         |
| Read/update tax account mapping | `/api/accounting/taxes/{tax}/account-mapping` |
| Calculate tax without writing   | `POST /api/accounting/tax/calculate`          |
| Tax summary report              | `GET /api/accounting/tax-reports/summary`     |
| Tax-by-tax report               | `GET /api/accounting/tax-reports/by-tax`      |

These are discovery starting points, not a substitute for source verification.

For each endpoint, verify:

- Exact HTTP method and URL.
- Required permission.
- Query parameters.
- Request payload.
- Response resource shape.
- Pagination behaviour.
- Validation and error format.
- Company-scoping behaviour.
- Lifecycle restrictions.
- Whether the operation is read-only or mutates data.

Do not guess endpoint suffixes or request fields for activate/deactivate or
tax-rate operations.

---

## 5. Routes and Navigation

Follow the existing frontend conventions established in Phases 5–9.

Use a route structure similar to:

- `/taxes`
- `/tax-reports`

Add tax-rate and account-mapping interfaces within the tax module using a
structure consistent with the existing application.

Possible implementation locations:

- `src/lib/taxes/`
- `src/components/taxes/`
- `src/app/(app)/taxes/`
- `src/app/(app)/tax-reports/`

Adapt these paths to the actual repository conventions.

Add navigation entries under an appropriate existing accounting or configuration
section. Use the actual permissions returned by the authentication/session
system.

Do not reorganize existing navigation sections or rename established routes.

---

## 6. Tax Master Management

Implement a tax list using the verified list endpoint.

Show fields only when present in the backend resource. Possible fields include:

- Tax name.
- Code.
- Description.
- Tax type or classification.
- Active/inactive state.
- Supported rate summary, if returned.

Do not fabricate aggregate totals or fields that are not returned by the API.

Provide search, filters, and pagination only where supported by the backend.

### Create and edit

Implement create/edit forms based on the actual form requests.

- Use exact field names and accepted enum values.
- Respect required and nullable fields.
- Keep server-owned fields out of request payloads.
- Display field-specific validation errors.
- Prevent duplicate submissions while a mutation is pending.
- Refresh affected data after a successful mutation.

Do not assume every tax field is editable after activation or after being
referenced by a transaction. Inspect the backend rules and tests.

### Activate/deactivate

Implement lifecycle actions only where supported.

- Show the current state.
- Ask for confirmation when appropriate.
- Disable the action while pending.
- Display backend validation or conflict errors.
- Refresh the tax and related rate data after success.

Never change active state locally without a successful API response.

### Delete

Only implement delete if supported by the actual backend contract.

Respect restrictions for taxes referenced by existing documents or rates. Show
the backend refusal message clearly.

Do not introduce a separate client-side deletion policy that conflicts with
backend behaviour.

---

## 7. Tax Rate Management

Inspect the tax-rate resource, model, form requests, and lifecycle services.

Implement the supported rate operations, including list, create, update, delete,
activate, or deactivate only where the backend allows them.

Display verified fields, which may include:

- Rate value.
- Effective date or date range.
- Active state.
- Rate type.
- Other fields returned by the resource.

Do not assume the exact rate fields or date semantics until verified.

### Financial precision

Tax rates and monetary values must preserve the backend's decimal-string
representation.

- Do not use floating-point arithmetic for tax calculations.
- Do not silently round values in a way that changes the API value.
- Use existing formatting utilities where appropriate.
- Keep entered rate precision consistent with the server's validation rules.

If rates have effective-date rules, make those rules clear in the form and error
messages. Do not infer effective dates or automatically rewrite them.

If overlapping or conflicting rates are rejected by the backend, surface those
errors rather than inventing a frontend alternative.

---

## 8. Tax Account Mapping

Implement the account-mapping interface using the verified endpoint and payload.

Inspect the backend to determine:

- Which account roles can be mapped.
- Which accounts are eligible.
- Whether mappings are optional or required.
- Whether accounts must be active.
- Whether mappings are tax-specific or shared.
- Whether clearing an existing mapping is supported.

Reuse the existing Chart of Accounts API and picker patterns where appropriate.

If the backend requires account IDs, submit the exact IDs it expects. Do not
confuse a ledger account ID with a different identifier.

Respect the user's `accounts.view` permission when loading account labels. Do
not issue protected account reads when the user lacks permission.

If the API accepts a mapping but does not provide a way to read or clear it,
document the limitation. Do not fabricate missing behaviour.

After saving, refresh the mapping from the server and display the persisted
result.

---

## 9. Tax Calculation Tool

Build a small, read-only tax calculation interface using the verified endpoint:

`POST /api/accounting/tax/calculate`

The existing handoff documents an example payload with:

- `amount` as a decimal string.
- `date` in `YYYY-MM-DD` format.
- Optional `tax_ids` array.
- Optional `basis` of `EXCLUSIVE` or `INCLUSIVE`.

Verify these details against the actual backend before use.

The calculation tool must:

1. Accept a valid amount and date.
2. Allow selection of applicable taxes if the endpoint supports it.
3. Support the verified inclusive/exclusive basis options.
4. Send the exact request expected by the backend.
5. Display the calculation result returned by the API.
6. Surface validation errors and unsupported combinations.
7. Make clear that the tool calculates a result and does not create or post an
   accounting document.

Do not reimplement the tax engine in the browser.

Do not use the browser's floating-point arithmetic to reproduce or verify tax
totals.

Do not save or post anything from this tool unless the actual endpoint
explicitly performs that operation. The reported endpoint is read-only and
writes nothing.

Do not infer a tax result if the API response is malformed or missing required
values.

---

## 10. Tax Reports

Implement read-only tax reports using the verified endpoints:

- `GET /api/accounting/tax-reports/summary`
- `GET /api/accounting/tax-reports/by-tax`

Verify the exact filters, date semantics, response fields, totals, and grouping
behaviour from source.

Build a report screen with:

- Appropriate date filters, if supported.
- Loading, empty, error, and retry states.
- Readable summary figures.
- A tax-by-tax breakdown.
- Consistent monetary formatting.
- Responsive tables or cards.
- Clear indication of the report period.

Use backend totals as authoritative. Do not recalculate totals in the browser or
combine values with incompatible currencies.

Do not assume that report totals represent all tax transactions regardless of
document status. Inspect the report service to understand which accounting
records are included.

If the report has no pagination or returns a flat array, reflect that contract
accurately.

Do not invent export, print, or drill-down functionality if no existing
supported mechanism exists.

---

## 11. Permissions and Authorization

Verify the exact permission names from `config/authorization.php`, route
middleware, policies, and tests.

The handoff identifies these permission families:

- `accounting.tax.*`
- `accounting.tax.report.view`
- `accounting.tax.calculate`

Confirm the exact permissions for each action, including tax-rate management and
account mapping.

Apply capability checks to:

- Navigation visibility.
- Tax list and detail reads.
- Tax create/edit/delete.
- Tax activation/deactivation.
- Tax-rate management.
- Account mapping.
- Tax calculation.
- Tax reports.

Do not hard-code role names as a replacement for permission checks.

A user without the required view permission must not trigger protected API reads
simply because a page or component mounts.

The backend remains the security authority. UI visibility is not a substitute
for server authorization.

Handle `401`, `403`, `404`, `422`, `429`, and unexpected errors according to the
existing API helper conventions.

---

## 12. Company Isolation and Request Lifecycle

Use the existing company-aware hooks and secure same-origin BFF proxy.

Every browser request must go through the established `/api/backend/*`
mechanism. Do not call Laravel directly from client components.

Ensure:

- No JWT is exposed to browser JavaScript.
- The BFF supplies the server-held Authorization token and active
  `X-Company-Id`.
- Company-scoped tax resources are loaded for the active company.
- Obsolete reads are aborted where supported.
- Switching companies does not leave stale tax data on screen.
- Forms cannot silently submit under a different company than the one in which
  they were opened.
- Server-owned `company_id` is never added to payloads unless the verified API
  explicitly requires it.
- Cross-company identifiers are handled according to backend route binding.

Do not use data from the previous company as a fallback after a failed read.

Global master data, if any tax data is system-global rather than company-scoped,
must be identified from the actual source. Do not assume every resource follows
the same tenancy model.

---

## 13. Data Layer and Runtime Validation

Use the existing frontend conventions for API methods, validators, hooks, and
errors.

Create a focused tax feature module containing, as needed:

- Types and verified enum constants.
- Runtime validators.
- API methods.
- List and detail hooks.
- Rate-management hooks.
- Account-mapping methods.
- Tax calculation methods.
- Tax report methods.
- UI components and tests.

Runtime validators must reflect actual API resources.

Unknown enum values must be handled safely. Never silently reinterpret an
unknown tax type, rate basis, or status as a known value.

Keep API request logic out of presentation components where practical.

Do not add TanStack Query, React Hook Form, Zod, or another dependency just to
implement this phase. Reuse the current hand-rolled API and validation patterns
unless a compelling, documented reason exists.

Do not refactor unrelated modules.

---

## 14. UX and Accessibility

Follow the established Accountance design system:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Clean, minimal layouts.
- Shared buttons, forms, tables, dialogs, badges, alerts, and notifications.
- Semantic status colors.
- Responsive desktop, tablet, and mobile layouts.
- Keyboard-accessible controls and dialogs.
- Visible focus indicators and accessible labels.
- Consistent date and decimal formatting.

Provide:

- Loading skeletons or equivalent loading states.
- Empty states.
- Error and retry states.
- Field validation.
- Pending mutation indicators.
- Success feedback.
- Confirmation dialogs for destructive or lifecycle actions.
- Clear messages when the backend refuses an operation.

Avoid unnecessary summary cards or totals that the API does not support.

---

## 15. Testing Requirements

Use the existing Vitest and React Testing Library setup.

Add focused tests for the behaviour implemented.

### Validators and API

- Valid and malformed tax resources.
- Valid and malformed rate resources.
- Unknown enum values.
- Account-mapping response validation.
- Tax-calculation request and response validation.
- Tax report validation.
- Query/filter serialization.
- Correct HTTP methods and request bodies.
- Abort-signal propagation.
- Error handling, including field-level `422` errors.
- Empty and flat-array list responses.

### UI and security boundaries

- Tax list loading, populated, empty, and error states.
- Create/edit forms where supported.
- Activate/deactivate confirmation and failure states.
- Delete restrictions and backend refusal.
- Tax-rate CRUD and lifecycle operations where supported.
- Account mapping and permission-gated account reads.
- Tax calculation success and validation failure.
- Summary and tax-by-tax report filters and results.
- Missing permission causes no protected request.
- Company switching does not display stale data.
- Pending-state duplicate-submit prevention.
- Malformed API responses do not crash the UI.
- Accessible dialog behaviour.

Do not remove or weaken existing tests to make the suite pass.

Do not add meaningless tests merely to increase the count.

---

## 16. Live Integration

If a configured Laravel backend and database are available, use the existing
approved environment for safe integration checks.

Do not modify backend code, run migrations, or seed data to make integration
possible.

If live integration is unavailable, state that clearly. Derive fixtures from the
actual source and tests, and distinguish source inspection from runtime
verification.

Do not claim that authorization, company isolation, tax calculation, or report
accuracy was live-tested unless it actually was.

---

## 17. Documentation

Create:

`docs/FRONTEND_PHASE_10_REPORT.md`

Include:

1. Executive summary and completion status.
2. Initial Git status and pre-existing changes.
3. Backend contract verified from actual source.
4. Routes and operations implemented.
5. Tax and tax-rate lifecycle behaviour.
6. Account-mapping behaviour.
7. Tax calculation request/response contract.
8. Tax report filters, date semantics, and returned totals.
9. Permission and company-isolation handling.
10. Files added and modified.
11. Tests and quality-gate results.
12. Live integration status.
13. Known limitations and deviations from the blueprint.
14. Confirmation that backend files were untouched.
15. Confirmation that no destructive Git operations, commits, migrations, or
    seeders were performed.

Update `FRONTEND_BLUEPRINT.md` or `FRONTEND_API_HANDOFF.md` only for verified
implementation decisions or meaningful contract discrepancies. Preserve earlier
reports and document disagreements rather than replacing prior work.

---

## 18. Quality Gates

Run from the actual frontend directory:

```bash
npm run lint
npx tsc --noEmit
npm test
npm run build
```

Report actual exit statuses and test counts.

If dependencies are missing, inspect the package configuration and lockfile
first. Do not update dependencies unnecessarily.

Fix Phase 10 regressions without disabling lint rules globally, weakening
TypeScript, or removing tests.

---

## 19. Strict Scope Boundaries

### Included

- Tax master management.
- Tax-rate management.
- Tax account mapping.
- Read-only tax calculation.
- Tax summary report.
- Tax-by-tax report.
- Permissions, company isolation, runtime validation, accessibility, and tests.

### Excluded

- Laravel backend changes.
- Database changes, migrations, or seeders.
- New tax rules or accounting calculations.
- Posting journals or changing accounting balances.
- Editing invoices, bills, receipts, payments, or credit/debit notes.
- Currency and exchange-rate management.
- Budget and dimension management.
- Fixed asset management.
- Bank reconciliation fixes.
- Dashboard redesign.
- Unrelated refactoring.
- New dependencies without justification.
- Destructive Git operations.
- Starting Phase 11.

Do not implement unsupported capabilities just because they appear in the
roadmap.

If an API gap prevents an operation, document it as a limitation and do not
invent an identifier, endpoint, payload, or client-side workaround that changes
accounting semantics.

---

## 20. Final Verification and Stop Condition

Before finishing:

1. Run all quality gates.
2. Review the final diff for unrelated changes.
3. Verify the Phase 10 report.
4. Verify that no backend files were modified.
5. Verify that pre-existing Phase 8 and Phase 9 work remains preserved.
6. Run `git status --short`.
7. Run `git status --short -- accounting-app/backend`, adjusting paths to the
   actual repository layout.
8. Separate pre-existing changes from Phase 10 changes in the report.

Do not commit anything.

**Phase 10 is complete only when the supported tax-management and tax-reporting
UI is implemented, the tests and quality gates pass, the report is written, and
existing user work remains preserved.**

Stop after Phase 10. Do not start Phase 11.

Provide a concise completion summary and point me to
`docs/FRONTEND_PHASE_10_REPORT.md` for review before the next phase.
