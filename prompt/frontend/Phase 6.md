# Accountance Frontend — Phase 6: Journal Entry UI

## 1. Objective

Implement **Phase 6 — Journal Entry UI** in the existing Accountance Accounting
Web App repository.

Build the journal entry module using the existing Laravel API, secure Next.js
BFF/API proxy, authentication, company context, permissions, design system, and
frontend conventions.

The module must support:

- Viewing and searching journal entries.
- Filtering journals by supported status, date, and other verified backend
  filters.
- Creating draft journal entries.
- Editing eligible draft journals.
- Managing journal lines with debit and credit amounts.
- Displaying debit and credit totals and the difference.
- Viewing journal details.
- Posting eligible draft journals through the existing backend posting endpoint.
- Clearly displaying validation errors and backend posting restrictions.

**Core accounting rule:** the frontend may help users prepare and validate
journal entries, but the backend remains authoritative for accounting validation
and posting. Posted journals must be immutable.

Do not start Phase 7 or implement unrelated business modules.

---

## 2. Mandatory pre-work: inspect before editing

Before making changes, inspect the repository and its current state.

1. Read `docs/FRONTEND_BLUEPRINT.md`, `docs/FRONTEND_API_HANDOFF.md`, the Phase
   5 report, and relevant Phase 0–4 reports.
2. Inspect `git status --short`, current diffs, and untracked files. Preserve
   all existing work, including unrelated files.
3. Inspect the existing implementation:
   - `src/lib/accounts/`
   - `src/components/accounts/`
   - `src/lib/dashboard/`
   - `src/lib/api/`
   - `src/lib/navigation/`
   - `src/app/api/backend/[...path]/route.ts`
   - `src/app/(app)/`
   - Existing authentication, company-context, permissions, UI, dialog, and test
     utilities.
4. Inspect the actual Laravel backend source and tests for:
   - Journal routes and HTTP methods.
   - Journal controller, service, request validation, resource, policy, and
     permission names.
   - Journal line fields and line-level validation.
   - Journal numbering and server-generated fields.
   - Draft update rules.
   - Posting prerequisites and failure responses.
   - Accounting-period restrictions.
   - Cross-company access protection.
   - Journal list filters, sorting, and pagination.
   - Exact response envelopes and error shapes.

Use the actual repository as the source of truth. Earlier reports are useful
context, not a substitute for inspecting current source.

Before implementation, write down the verified journal API contract in the Phase
6 report or an implementation checklist.

If a required backend capability does not exist, do not invent it. Document the
limitation and continue only with the functionality supported by the verified
contract.

## 3. Scope and routing

Create a journal module within the existing authenticated application shell.

Suggested routes, subject to the verified API and current frontend routing
conventions:

- `/journals` — journal list.
- `/journals/new` — create a draft journal.
- `/journals/[id]` — view a journal.
- `/journals/[id]/edit` — edit an eligible draft.

Reuse the current `(app)` route group and existing shell. Keep routing
consistent with the actual Next.js version and established frontend
architecture.

If a route or interaction would require a new backend endpoint, do not implement
it as if the endpoint existed.

Add or update navigation using the exact verified permission required to view
journals.

## 4. Existing architecture and security requirements

- All browser requests to Laravel must go through the existing same-origin API
  proxy and established `backendGet`, `backendPost`, `backendPut`, or other
  existing API helpers.
- Never call the Laravel base URL directly from browser components.
- Never expose JWTs, session secrets, or the server-side company context to
  browser JavaScript.
- Never store JWTs in local storage, session storage, or browser-accessible
  cookies.
- Reuse the existing authentication and active-company context.
- Do not introduce a separate authentication system, API client, or permission
  model.
- Respect the API proxy's current allowlist. If a verified journal route is not
  allowed, stop and document the required proxy change; do not bypass the proxy.
- The backend remains authoritative for permissions, company scoping, posting,
  period restrictions, and journal immutability.
- Do not trust client-supplied journal numbers, company IDs, posting metadata,
  or other server-owned fields.

## 5. Verify and document the API contract

Inspect and record the exact supported operations. Expected areas to investigate
include:

- List journals.
- Retrieve one journal and its lines.
- Create a draft journal.
- Update a draft journal.
- Post a draft journal.
- Any supported journal-number or account lookup endpoints.

The previously implemented backend has a draft-to-posted workflow and
server-issued sequential journal numbers, but verify the current source before
relying on those details.

For every operation, record:

- HTTP method and exact path.
- Required permission.
- Query parameters.
- Request payload.
- Success status and response shape.
- Validation error structure.
- Relevant conflict and authorization responses.
- Whether pagination metadata is actually returned.

Do not assume journal routes use the same pagination behaviour as the accounts
endpoint. Inspect the actual response.

## 6. Journal list

Implement a responsive journal list with only verified capabilities.

Include, where supported:

- Journal number.
- Journal date.
- Description or narration.
- Status.
- Debit and credit totals, only if the API provides enough trustworthy
  information to display them.
- Relevant actions.

Add server-side search and filters only where the backend supports them. Do not
simulate global filtering over a single fetched page.

Requirements:

- Loading skeleton or loading state.
- Empty state.
- Error state with retry.
- Clear filter/reset behaviour.
- Accurate pagination without fabricated totals.
- Permission-aware navigation and actions.
- Accessible status labels and readable dates.
- Currency-aware formatting using existing utilities.
- No claim that the list is exhaustive unless the API contract supports that
  conclusion.

Do not add client-side journal posting logic.

## 7. Create and edit draft journals

Implement a journal form using the actual backend contract.

Expected conceptual fields, subject to verification:

- Journal date.
- Description or narration.
- Reference, only if supported.
- One or more journal lines.
- Account selection.
- Debit or credit amount for each line.
- Line description or other supported line metadata.

### Journal line interaction

Where supported by the backend, users should be able to:

- Add a line.
- Remove a line.
- Select an account from the verified account lookup/list API.
- Enter a debit amount or credit amount.
- Enter a line description.
- Identify incomplete or invalid lines.
- See updated debit and credit totals.

Make line editing keyboard-accessible. Ensure that adding, deleting, or editing
one row does not accidentally overwrite another row's data.

### Debit/credit validation

Use exact decimal arithmetic for financial amounts. **Never use JavaScript
floating-point arithmetic as the source of truth for money.**

- Reuse existing exact-money utilities if they can support the required
  precision.
- Otherwise, implement a small, tested decimal utility appropriate to the
  verified API's accepted precision.
- Do not introduce an arbitrary rounding rule.
- Match the backend's accepted decimal precision and maximum amount constraints.
- Display debit total, credit total, and their difference.
- Provide immediate, comprehensible feedback when the entry is unbalanced.
- Require at least the minimum number of valid lines mandated by the backend.
- Do not submit invalid or incomplete rows.

The frontend's balance check is a usability aid only. The backend must
independently validate the journal before posting.

If the backend accepts one amount field per line rather than separate debit and
credit fields, adapt the UI to the real payload shape without changing
accounting semantics.

### Draft lifecycle

- Create the journal through the verified create endpoint.
- Edit only journals the backend allows to be edited.
- Do not offer draft editing for posted journals.
- Do not create a fake draft locally that appears persisted.
- Do not automatically post after saving a draft.
- Prevent duplicate submissions while a mutation is in progress.
- Preserve useful user-entered values when a recoverable validation error
  occurs.

## 8. Journal detail view

Display the verified journal fields and lines.

Include, where available:

- Journal number.
- Date.
- Description or narration.
- Status.
- Account code and name.
- Debit and credit amounts.
- Line descriptions.
- Total debits and credits.
- Relevant server-provided posting information.

The journal detail page must distinguish clearly between a draft and a posted
journal.

For a posted journal:

- Show a clear posted/read-only state.
- Hide or disable draft editing controls.
- Do not expose a normal edit, delete, or repost action unless the backend
  explicitly supports that operation.
- Do not implement an unverified reversal workflow in this phase.

For a draft:

- Show edit and post actions only when the user has the corresponding permission
  and the backend permits the operation.

## 9. Posting workflow

Implement posting only through the verified backend posting endpoint.

Before posting:

1. Show a confirmation dialog identifying the journal by its server-issued
   journal number when available.
2. Explain that posting finalizes the journal and that posted journals cannot be
   edited through the normal workflow.
3. Prevent duplicate posting requests.
4. Recheck the current journal state before allowing the action where
   appropriate.

After posting:

- Treat the backend response as authoritative.
- Refresh or replace the journal detail with the returned posted representation.
- Update the list state if applicable.
- Show a success notification only after the server confirms success.
- Display backend validation failures, permission errors, closed-period errors,
  and other relevant conflicts accurately.
- Never optimistically mark a journal as posted before the server confirms it.
- Do not retry posting automatically.
- Do not implement client-side ledger writes, account-balance changes, or
  journal-number generation.

If the backend rejects an unbalanced journal or a journal in a closed accounting
period, show the actual useful validation message without exposing stack traces
or internal implementation details.

## 10. Permissions

Inspect the actual permission enum, policies, role configuration, and journal
routes.

Use the exact verified permissions for:

- Viewing journal lists and details.
- Creating drafts.
- Updating drafts.
- Posting journals.

Do not assume the permission names in this brief are correct. Do not conflate
`accounts.view` with journal permissions or ledger permissions.

UI visibility is not authorization. The backend must continue enforcing all
permissions.

Handle forbidden responses safely, including cases where permissions differ by
role or company.

## 11. Account selection

Reuse the existing Chart of Accounts API helpers and validators where practical.

- Fetch accounts only through the secure proxy.
- Use verified account identifiers in journal-line payloads.
- Respect the active company.
- Avoid selecting inactive accounts if the backend disallows them for new
  journal lines.
- Do not infer account eligibility from the account name or type alone.
- Handle large account lists without silently truncating selectable results.
- If the accounts endpoint is paginated without totals, implement selection that
  respects the actual pagination contract or use a verified dedicated lookup
  endpoint if one exists.

If the backend accepts an account ID but requires the account to be active,
reflect that restriction in the selector while still relying on the backend for
final enforcement.

## 12. Company-switch isolation

The existing frontend avoids introducing a query cache unless needed. Follow the
established pattern unless repository inspection reveals a better existing
mechanism.

- Key requests and UI state to the active company.
- Abort obsolete requests when company context changes.
- Discard responses from an earlier company.
- Reset company-dependent journal and account-selection state.
- Ensure a journal from one company is never rendered as the active company's
  journal after a switch.
- Do not send an unscoped request if there is no active company.
- Do not preserve an unsaved draft across companies unless the existing
  application has an explicit, safe policy for that behaviour.

Add tests for company-switch isolation.

## 13. UI and accessibility

Follow the existing Accountance design system.

- Clean, minimal, professional interface.
- Calm green accent in Light Mode.
- Professional Dark Mode.
- Existing semantic colors for errors, warnings, and statuses.
- Reuse existing buttons, fields, dialogs, badges, notifications, formatting,
  and error components.
- Responsive layouts for desktop and mobile.
- Clear focus states, accessible labels, keyboard navigation, and sensible focus
  management.
- Avoid unnecessary animation, decorative clutter, and new dependencies.

Do not redesign the application shell during this phase.

## 14. Testing requirements

Use the existing Vitest and React Testing Library setup. Do not introduce a
second test framework.

Add tests for relevant areas, including:

### API and validators

- Verified endpoint paths, HTTP methods, and payload shapes.
- Correct parsing of journal and journal-line responses.
- Malformed responses.
- Supported list response and pagination formats.
- Validation error mapping.

### Financial correctness

- Balanced journal.
- Unbalanced journal.
- Empty and incomplete lines.
- Exact decimal handling, including fractional amounts.
- Large values and precision boundaries supported by the backend.
- No floating-point-based balance decisions.

### Lifecycle

- Create draft.
- Edit draft.
- Posted journal is read-only.
- Posting confirmation.
- Successful posting only after server confirmation.
- Posting rejection does not mark the journal as posted.
- Duplicate submissions are prevented.
- Closed-period or other server validation failures are displayed appropriately.

### Permissions and isolation

- No journal fetch without the required view permission.
- Create/edit/post controls follow the verified permissions.
- Forbidden API responses are handled safely.
- Company switching aborts or discards stale requests.
- No active company means no unscoped journal or account request.

### UI states

- Loading, empty, success, and error states.
- Retry.
- Field-level and general API errors.
- Accessible form and confirmation interactions.

Mocked tests do not count as live backend integration. Report this distinction
honestly.

## 15. Dependencies and implementation quality

Before adding dependencies, inspect `package.json` and existing utilities.

- Prefer existing exact-decimal, API, form, and UI helpers.
- Add a dependency only if it solves a concrete requirement that cannot be met
  safely by the current stack.
- Avoid unrelated refactoring.
- Avoid `any` and unsafe type assertions unless justified and narrowly
  contained.
- Keep API types, runtime validation, hooks, and UI components separated
  consistently with the current codebase.
- Ensure cleanup of event listeners, timers, and aborted requests.
- Keep mutation errors recoverable and visible.

## 16. Documentation and report

Create:

`docs/FRONTEND_PHASE_6_REPORT.md`

Include:

1. Scope and completion status.
2. Actual journal API contract, with source paths.
3. Implemented routes and UI components.
4. Permissions and role behaviour.
5. Journal-line data model and exact-decimal strategy.
6. Draft editing and posting behaviour.
7. Company-switch isolation.
8. Pagination and account-selection behaviour.
9. Tests and exact test counts.
10. Lint, TypeScript, test, and production-build results.
11. Known limitations and backend follow-ups.
12. Live backend integration status.
13. Files added or modified.
14. Confirmation that backend files were untouched.
15. Confirmation that no commits, resets, stash, clean, checkout, migrations, or
    seeders were performed.

Update `docs/FRONTEND_BLUEPRINT.md` only where the completed implementation
establishes a genuine architectural decision or a necessary clarification.

## 17. Validation gates

Run from `accounting-app/frontend`:

- `npm run lint`
- `npx tsc --noEmit`
- `npm test`
- `npm run build`

Resolve regressions caused by Phase 6 before declaring completion. Report any
pre-existing failures separately; do not conceal them.

Inspect `git diff` and `git status --short` at the end. Confirm that existing
Phase 0–5 work remains intact and the backend remains untouched.

## 18. Strict scope and Git safety rules

- Do not edit Laravel backend files.
- Do not create or run migrations or seeders.
- Do not commit, reset, stash, clean, checkout, switch branches, or discard
  changes.
- Do not overwrite or delete pre-existing user work.
- Do not implement reversal, reconciliation, reporting, or other future-phase
  modules.
- Do not fabricate API endpoints, permissions, fields, totals, or success
  states.
- Do not bypass the secure proxy.
- Do not claim live integration if it was not performed.

If an issue cannot be resolved within this phase without violating these
constraints, document it and stop at the appropriate boundary.

## 19. Completion criteria

Phase 6 is complete only when:

- The journal list, supported filters, detail view, draft creation/editing, and
  posting workflow are implemented against the verified API.
- Debit/credit totals use safe exact-decimal arithmetic.
- Posted journals are read-only.
- Backend validation and permission errors are surfaced accurately.
- Company switching cannot expose stale journal or account data.
- Tests, lint, type-check, and production build pass.
- `docs/FRONTEND_PHASE_6_REPORT.md` is complete.
- Backend files remain untouched and all work remains uncommitted.
- The final response reports actual results, limitations, and test counts.

**Stop after Phase 6. Do not start Phase 7.**
