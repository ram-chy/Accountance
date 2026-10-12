# Accountance Frontend — Phase 5: Chart of Accounts UI

## 1. Objective

Act as a senior full-stack engineer specializing in Laravel REST APIs, Next.js
App Router, TypeScript, accounting systems, financial data integrity, and secure
multi-company applications.

Implement **Frontend Phase 5 only: Chart of Accounts management** for the
Accountance Accounting Web App.

Build a complete, professional, responsive interface for viewing and managing
the company's chart of accounts using the existing backend API.

The goal is to deliver a reliable accounting module that follows the established
architecture and design system without introducing a separate frontend
accounting engine or changing backend business rules.

Work methodically. Inspect the repository, existing implementation, backend
contract, and documentation before editing any files.

---

## 2. Project Context

Repository root: `D:\Development\Accountance` or the actual current repository
root.

Frontend: `accounting-app/frontend`

Backend: `accounting-app/backend`

Read the existing documentation where available:

- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_PHASE_0_AUDIT.md`
- `docs/FRONTEND_PHASE_0_REPORT.md`
- `docs/FRONTEND_PHASE_1_REPORT.md`
- `docs/FRONTEND_PHASE_2_REPORT.md`
- `docs/FRONTEND_PHASE_3_REPORT.md`
- `docs/FRONTEND_PHASE_4_REPORT.md`
- `docs/FRONTEND_API_HANDOFF.md`, if present.
- Relevant backend route, controller, request, resource, service, policy, and
  authorization files for accounts.

Verify the real paths and file contents. Do not assume a report's descriptions
are equivalent to the current implementation.

### Existing stack

- Next.js App Router, React, and strict TypeScript.
- Tailwind CSS v4.
- Laravel REST API.
- MySQL accounting backend.
- Existing bearer-JWT authentication held server-side in the encrypted
  `HttpOnly` session.
- Same-origin backend proxy at `/api/backend/*`.
- Existing `backendGet`, `backendPost`, `backendPut`, `backendPatch`, and
  `backendDelete` helpers.
- Existing API error types and response-envelope handling.
- Existing permission utilities and permission-aware navigation.
- Existing theme system and reusable UI components.
- Vitest test suite.
- Sonner notifications.

Inspect actual versions and source before relying on this list.

### Existing architectural rules

- All business requests must use the existing same-origin backend proxy.
- Never store the Laravel JWT in browser storage or expose it to client
  components.
- The backend is authoritative for authentication, permissions, company
  isolation, and accounting validation.
- Financial values must not be processed using `Number` or floating-point
  arithmetic where exact monetary calculations are required.
- Company context must follow the established `X-Company-Id` handling.
- Posted financial records are immutable under the established accounting
  architecture.
- Reuse existing abstractions before introducing new ones.

---

## 3. Mandatory Safety Rules

Before editing:

1. Run `git status --short` at the repository root.
2. Inspect the current diff and untracked files.
3. Record the initial working-tree state.
4. Inspect `package.json`, current dependencies, existing components, API
   helpers, and test configuration.
5. Verify the actual Laravel account routes and their authorization
   requirements.

Never:

- Discard, reset, overwrite, stash, or clean existing user work.
- Run destructive Git operations.
- Create a commit.
- Modify backend business logic or database schema.
- Create or run migrations or seeders.
- Invent API routes, fields, permissions, enum values, or validation rules.
- Bypass the proxy or send requests directly from the browser to Laravel.
- Store authentication tokens in browser storage.
- Treat UI visibility as a replacement for backend authorization.
- Add unrelated accounting modules.
- Build a separate accounting calculation engine in the frontend.

All Phase 5 changes must remain uncommitted.

If the actual backend contract differs from this prompt, follow the verified
implementation and document the discrepancy.

---

## 4. Phase 5 Scope

Implement only the Chart of Accounts module:

1. Inspect and document the actual account API contract.
2. Add the authenticated Chart of Accounts route and page.
3. Display and search accounts.
4. Filter accounts using supported backend fields.
5. Implement pagination only according to the verified API contract.
6. Add account creation.
7. Add account editing.
8. Support account activation/deactivation where the backend provides these
   operations.
9. Support contra-account configuration where the API supports it.
10. Implement account-specific validation, permissions, error handling, and
    loading states.
11. Add tests and documentation.
12. Verify that company switching cannot expose stale account data.

Do not implement journal entries, ledger screens, trial balance, reporting,
invoices, banking, or other business modules.

---

## 5. Workstream A — Verify the Backend Contract

Inspect the actual backend source, including as applicable:

- `routes/api.php`
- Account controller and service classes.
- Account form requests and validation rules.
- Account resource and response serialization.
- Account policies and authorization middleware.
- Account-related routes.
- Company-scoping mechanisms.
- Existing account-related tests.
- Permission definitions and role-permission mappings.
- Existing pagination conventions.
- Any account enums or model casts.

The backend was previously reported to provide company-scoped chart-of-accounts
CRUD, activation/deactivation, contra-account support, and account-code/name
uniqueness. Verify each capability in the current source.

Create a verified contract table in the Phase 5 report:

| Operation                  | Actual endpoint | Method | Permission | Request/response shape |
| -------------------------- | --------------- | ------ | ---------- | ---------------------- |
| List accounts              | Verify          | Verify | Verify     | Verify                 |
| View account, if supported | Verify          | Verify | Verify     | Verify                 |
| Create account             | Verify          | Verify | Verify     | Verify                 |
| Update account             | Verify          | Verify | Verify     | Verify                 |
| Activate/deactivate        | Verify          | Verify | Verify     | Verify                 |

Do not guess missing details.

### Contract requirements

Verify:

- Account ID format.
- Account code and name fields.
- Account type and subtype values.
- Normal-balance behavior.
- Contra-account representation.
- Active/inactive status representation.
- Whether `normal_balance` may be `null`.
- Server-generated fields and immutable fields.
- Unique account-code/name rules and their actual scope.
- Required and optional fields for create and update.
- Which fields can be edited after the account has been used.
- Whether deletion exists and what constraints apply.
- Filter and sort parameters.
- Pagination response structure.
- Exact permission names.

If a requested capability does not exist in the backend, do not simulate it
locally or invent an endpoint. Omit the control and explain the limitation in
the report.

---

## 6. Workstream B — Route and Page

Use the existing authenticated application layout and navigation system.

Prefer a route consistent with the existing navigation, such as:

`/accounts`

Confirm the actual routing conventions before creating it.

Update `src/lib/navigation/nav.ts` only if necessary to add a real Chart of
Accounts link.

The link must use the exact permission required by the verified backend
contract. Do not invent permission names.

The page must:

- Render inside the authenticated application shell.
- Respect the active company context.
- Provide a clear page title and concise explanation.
- Offer the account list and permitted actions.
- Remain usable on desktop, tablet, and mobile.
- Follow the existing Light/Dark theme system.
- Provide loading, error, empty, and unavailable states.

Do not replace or restructure the dashboard.

---

## 7. Workstream C — Data Layer

Create a small, reusable account data layer using the existing API client and
established conventions.

Possible organization, adjusted to fit the actual repository:

- `src/lib/accounts/types.ts`
- `src/lib/accounts/api.ts`
- `src/lib/accounts/validators.ts`
- `src/lib/accounts/query-keys.ts`, if a query-cache library is introduced.
- `src/lib/accounts/*.test.ts`

Do not create duplicate API clients or bypass `backend.ts`.

### Runtime validation

Treat upstream JSON as untrusted data.

Use runtime validation consistent with the existing dashboard validators and the
actual project dependencies.

Validate:

- Required IDs and identifiers.
- Account code and name.
- Type and subtype.
- Normal balance.
- Contra-account information.
- Status.
- Relevant timestamps and other displayed fields.

Do not silently convert malformed required values into plausible defaults.

If one row is invalid, follow an explicit, documented strategy consistent with
the existing UI architecture. Do not display fabricated financial metadata.

### Pagination

The existing project has documented a backend pagination caveat: some list
endpoints may not return standard Laravel `links` and `meta`.

Inspect the actual account-list response before implementing pagination.

- If the endpoint returns a flat `data` array, use the actual contract.
- If it returns Laravel paginator metadata, use that metadata.
- Do not fabricate `total`, `last_page`, or total page counts.
- Do not claim that more records exist unless the contract supports that
  conclusion.
- If pagination is unavailable, use a clearly documented alternative that does
  not silently omit accounts.

The Chart of Accounts must not imply that the displayed list contains every
account when the backend response is only a partial page.

---

## 8. Workstream D — Account List and Search

Build a clean account table or responsive equivalent.

Display the fields that the backend actually supplies, prioritizing:

- Account code.
- Account name.
- Account type.
- Subtype, if available.
- Normal balance, if meaningful.
- Contra-account indicator, if available.
- Active/inactive status.
- Available actions.

Do not invent balances or imply that an account's balance is included in the
account-list endpoint.

### Search and filters

Implement only filters supported by the verified backend.

Examples, only if the API supports them:

- Search by account code or name.
- Filter by account type.
- Filter by active/inactive status.
- Sort by supported fields.

If the backend does not support a filter, either implement a clearly labeled
client-side filter over a demonstrably complete dataset or omit it. Never apply
a client-side filter to an incomplete paginated dataset while implying it
searches all accounts.

Search must be debounced only if needed. If adding debounce logic, ensure timers
are cleaned up and stale requests cannot overwrite newer search results.

Provide:

- Clear filters.
- Empty search results.
- Loading skeletons.
- Recoverable error state with retry.
- Accessible labels and keyboard navigation.
- A sensible narrow-screen layout.

Do not add unsupported bulk actions.

---

## 9. Workstream E — Create and Edit Accounts

Implement forms using the project's existing form stack if one exists.

If no suitable form stack exists, evaluate adding React Hook Form and Zod only
if the benefits justify the dependency. Avoid duplicate validation systems.

### Form behavior

Support the verified fields required for account creation and editing.

Possible fields include:

- Account code.
- Account name.
- Account type.
- Subtype.
- Normal balance override, where supported.
- Contra-account configuration, where supported.
- Other explicitly editable fields in the verified backend contract.

Do not make all fields editable simply because they appear in the response.

### Validation

- Follow backend validation rules.
- Use client-side validation for immediate feedback, not as a replacement for
  backend validation.
- Display field-specific errors returned by the API.
- Display general API errors without leaking internal details.
- Preserve valid user-entered values after validation failure.
- Disable duplicate submission while a request is pending.
- Prevent accidental closing or data loss when practical.
- Distinguish create and edit modes clearly.
- Reset form state correctly when changing between records.

### Server-owned fields

Do not allow the browser to control fields that the backend owns, such as
generated identifiers, company ownership, or protected system fields.

Never send the active company ID as an authoritative ownership field in the
account payload unless the verified contract explicitly requires a
non-authoritative field for a documented purpose. Company scoping belongs to the
existing server-side company-context mechanism.

### Editing used accounts

Inspect the backend's actual accounting restrictions.

If the backend prevents changing certain fields after an account has been used,
reflect that behavior in the UI where the contract allows it. The backend
remains authoritative.

Do not bypass restrictions or invent a workaround.

---

## 10. Workstream F — Activation, Deactivation, and Deletion

Implement activation/deactivation only if the corresponding backend operations
are verified.

For these actions:

- Use the exact backend route and method.
- Show a confirmation dialog for consequential changes.
- Explain the account's identity in the confirmation.
- Prevent duplicate requests.
- Update or invalidate the relevant account data after success.
- Preserve the current list and show a useful error after failure.
- Handle backend restrictions accurately.

Deletion is optional and must not be invented.

If deletion exists, inspect backend safeguards and implement the UI only when
its behavior is understood. Respect all in-use-account restrictions and backend
errors.

Never implement a frontend-only delete, activate, or deactivate operation that
merely changes the display without changing the backend.

---

## 11. Workstream G — Permissions and Company Isolation

Inspect the exact backend permissions for account viewing, creation, updating,
activation/deactivation, deletion, and ledger-related access.

Use the existing permission utilities.

Requirements:

- Hide or disable actions that the current user is not allowed to perform.
- Do not request data if the user lacks the required viewing permission.
- Do not confuse `accounts.view` with `accounting.ledger.view`.
- Do not show account balances unless an authorized endpoint explicitly provides
  them.
- Handle `401`, `403`, `404`, `409`, and `422` responses according to the actual
  backend contract.
- Never treat a hidden button as a security control.

### Company-switch isolation

The dashboard already depends on active company state, and the Phase 3 report
identified cache invalidation as a follow-up concern.

Before introducing any cache:

1. Inspect how the existing company switcher updates the session snapshot.
2. Inspect how the current dashboard aborts stale requests.
3. Determine whether existing API state can persist after a company switch.
4. Implement the smallest safe correction needed for the account module.

If TanStack Query is introduced, use company-aware query keys that include the
active company ID. Invalidate or remove the relevant company-scoped queries
after a successful company switch.

Never render cached Company A account data after switching to Company B.

If no query cache is introduced, implement an equivalent safe reset and
stale-response prevention strategy for the account module.

Test this behavior.

---

## 12. Workstream H — Query Cache and Form Dependencies

Evaluate whether to introduce TanStack Query in this phase.

If adopted:

- Add it only once at the appropriate application-provider level.
- Use consistent query keys.
- Include active company ID and relevant filters in company-scoped keys.
- Handle loading, error, retry, and mutation states.
- Invalidate relevant queries after successful mutations.
- Clear or isolate company-scoped cache entries on company switching and logout.
- Do not automatically retry writes.
- Avoid unbounded automatic retries for failed reads.
- Do not cache sensitive responses persistently in browser storage.

If not adopted, document the reason and ensure the existing data-fetching
approach provides correct cache invalidation and stale-request handling.

Do not introduce TanStack Query, React Hook Form, or Zod solely because they
were previously recommended. Inspect existing dependencies and choose a coherent
implementation.

---

## 13. Workstream I — UI and Accessibility

Follow the existing Accountance design system:

- Calm green primary brand/accent.
- Neutral slate-based surfaces.
- Clean, professional layout.
- Comfortable Light Mode.
- Professional Dark Mode.
- Consistent typography, spacing, borders, and focus indicators.
- Semantic colors reserved for appropriate statuses and feedback.

Reuse existing buttons, cards, fields, badges, loading states, error states, and
empty states.

Avoid adding another UI framework or icon package without a clear need.

Accessibility requirements:

- Every form control has an accessible label.
- Validation errors are associated with their fields.
- Dialogs support keyboard interaction and appropriate focus handling.
- Status is conveyed through text, not color alone.
- Tables have meaningful headers.
- Interactive controls have visible focus indicators.
- The interface remains usable at narrow viewport widths.
- Loading and mutation states are communicated to assistive technologies where
  appropriate.

Do not redesign the entire application shell.

---

## 14. Workstream J — Tests

Use the existing Vitest setup and conventions.

Add tests for:

### Contract and validation

- Valid account response.
- Missing required fields.
- Malformed IDs or account types.
- Nullable normal-balance fields.
- Contra-account representation.
- Invalid status values.
- Flat-list and paginated responses, according to the actual API contract.

### API behavior

- Correct endpoint and HTTP method.
- Correct query parameters.
- Correct request payloads.
- Correct handling of `401`, `403`, `404`, `409`, and `422`.
- Empty and malformed responses.
- No direct browser-to-Laravel requests.

### UI behavior

- Loading, success, empty, and error states.
- Search and supported filters.
- Permission-based action visibility.
- Create and edit validation.
- Field-specific backend errors.
- Activation/deactivation confirmation, if implemented.
- Mutation success and failure handling.

### Company isolation

- Company-aware cache keys, if using TanStack Query.
- Stale requests cannot overwrite data for a newly selected company.
- Account data from the previous company is cleared or isolated after switching.
- A missing active company does not lead to an unscoped request.

Use mocked API responses for deterministic unit tests. Clearly distinguish
unit-test coverage from verification against a live Laravel backend.

---

## 15. Documentation

Create:

`docs/FRONTEND_PHASE_5_REPORT.md`

Update `docs/FRONTEND_BLUEPRINT.md` only where the implemented architecture
requires it.

The report must include:

1. Scope and completion status.
2. Initial Git status and pre-existing changes.
3. Actual account API contract and source locations.
4. Routes and methods implemented.
5. Permission names and their verified purpose.
6. Fields supported for create and edit.
7. Search, filtering, sorting, and pagination behavior.
8. Activation/deactivation/deletion capabilities and restrictions.
9. Company-switch isolation strategy.
10. Dependencies added or reused, with reasons.
11. Files created, modified, or removed.
12. Tests and actual test counts.
13. Lint, type-check, and production-build results.
14. Whether live backend integration was performed.
15. Known limitations and deviations from the blueprint.
16. Confirmation that the backend was not modified.
17. Confirmation that no commits, migrations, or seeders were performed.

Do not claim any test or validation passed unless it was actually run.

---

## 16. Validation Checklist

From `accounting-app/frontend`, run the available equivalent of:

1. Lint.
2. TypeScript type-check.
3. Unit tests.
4. Production build.

Use the scripts that actually exist in `package.json`.

Manually or through component/integration tests verify:

- Account list loading.
- Empty state.
- Search and supported filters.
- Create account.
- Edit account.
- Activation/deactivation if supported.
- Validation errors.
- Permission-denied behavior.
- API/network errors.
- Company switching.
- Light/Dark themes.
- Mobile layout.
- Keyboard and focus behavior.

If a check fails, investigate and fix the underlying issue. Do not weaken
validation or permissions merely to make tests pass.

Inspect the final Git diff and confirm that unrelated user changes remain
intact.

---

## 17. Completion Criteria

Phase 5 is complete only when:

- [ ] The real backend contract has been verified.
- [ ] The Chart of Accounts page uses the authenticated application shell.
- [ ] Requests go through the existing secure same-origin proxy.
- [ ] Account responses are runtime-validated.
- [ ] The list accurately represents the API's pagination capabilities.
- [ ] Search and filters use supported behavior.
- [ ] Create and edit forms follow backend validation rules.
- [ ] Activation/deactivation works only if supported by the backend.
- [ ] Permissions control UI actions without replacing backend authorization.
- [ ] Company switching cannot expose stale account data.
- [ ] Loading, error, empty, and mutation states are implemented.
- [ ] Tests cover the core behavior and security boundaries.
- [ ] Lint, type-check, tests, and production build pass.
- [ ] The Phase 5 report is complete.
- [ ] Backend files remain untouched.
- [ ] No commits, resets, stash, migrations, or seeders were performed.

If a required capability cannot be verified or safely implemented, document it
as blocked rather than claiming completion.

---

## 18. Out of Scope

Do not implement:

- Journal entry creation or posting.
- General ledger or trial-balance pages.
- Profit and loss or balance-sheet screens.
- Cash/bank management.
- Customers, suppliers, sales, purchases, or inventory.
- Dashboard redesign.
- Backend API or database changes.
- New permissions or accounting rules.
- Bulk account operations unless already explicitly supported and necessary.

---

## 19. Final Instructions

Work methodically and keep the implementation simple.

Use the verified backend contract as the source of truth. Preserve all existing
user changes and all previous phase functionality.

Do not commit.

When finished, provide a concise summary and create
`docs/FRONTEND_PHASE_5_REPORT.md`.

**Stop after Phase 5. Do not start Phase 6 until the implementation report has
been reviewed and approved.**
