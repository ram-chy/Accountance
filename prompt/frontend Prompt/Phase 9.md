# Accountance Frontend — Phase 9: Bank Reconciliation UI

## 1. Objective

Implement **Frontend Phase 9 only**: the Bank Reconciliation module for the
Accountance Accounting Web App.

Build a practical, accounting-safe interface on top of the existing Laravel API
and Next.js frontend architecture.

The module should support the verified backend capabilities for:

- Listing and filtering bank reconciliations.
- Creating a reconciliation.
- Viewing reconciliation details and eligible ledger movements.
- Adding and removing reconciliation items.
- Reviewing matched and unmatched movements.
- Completing a reconciliation.
- Reopening a completed reconciliation when permitted.
- Displaying reconciliation status, balances, differences, validation errors,
  and lifecycle restrictions when supported by the API.

**The actual backend implementation is authoritative.** Do not assume the API
exposes a particular field or operation until it has been verified in the
current source.

This is a frontend-only phase. Do not modify Laravel backend files, database
schema, migrations, seeders, or accounting rules.

Stop after Phase 9. Do not start Phase 10.

---

## 2. Critical Repository Preservation Warning

Phase 8's report records that a `git pull` was performed and local tracked
changes and conflicting untracked files were discarded or deleted. This
conflicts with the project's work-preservation requirements.

**Preserving existing work takes priority over updating the repository.**

Before doing anything else:

1. Inspect the repository root and current branch.
2. Run `git status --short`.
3. Run `git diff --stat` and `git diff`.
4. Inspect relevant untracked files and the existing Phase 1–8 frontend work.
5. Identify any existing Git changes, local reports, prompt files,
   documentation, and unrelated backend files.
6. Compare the current state with available Phase 8 documentation without
   overwriting files.

### Absolute restrictions

- Do not run `git pull` or `git fetch`.
- Do not run `git reset`, `git stash`, or `git clean`.
- Do not checkout or switch branches.
- Do not discard, delete, overwrite, or restore files simply to resolve
  conflicts.
- Do not use destructive cleanup commands.
- Do not commit changes.
- Do not assume untracked files are disposable.
- Do not attempt automatic recovery of allegedly lost work by overwriting
  current files.

If you discover that required files are missing or that previous work may have
been lost, document the situation and continue only with safely isolated work
that does not risk additional loss. If safe implementation is impossible, stop
and report the blocker instead of attempting destructive repair.

Preserve all current changes, including unrelated work.

---

## 3. Mandatory Pre-Implementation Inspection

Read the current versions of:

- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_API_HANDOFF.md`
- `docs/FRONTEND_PHASE_7_REPORT.md`, if present.
- `docs/FRONTEND_PHASE_8_REPORT.md`, if present.
- Existing Phase 0–6 reports and project instructions where relevant.
- Existing navigation, authorization, proxy, API helper, and testing
  documentation.

Inspect the actual source files in the repository. Do not assume the reports
exactly describe the current code.

### Frontend inspection

Review:

- Authentication and session handling.
- Active company selection and switching.
- The secure same-origin backend proxy.
- Existing API helper conventions.
- Accounts, journals, Cash & Banking, financial years, and periods modules.
- Shared tables, dialogs, forms, badges, confirmation dialogs, and notification
  components.
- Permission checks and navigation configuration.
- Date and decimal formatting utilities.
- Existing test conventions and API runtime validators.

Reuse existing patterns. Avoid duplicate infrastructure.

### Backend inspection

Inspect the actual Laravel source, including:

- `routes/api.php`
- Bank reconciliation controllers.
- Reconciliation models and resources.
- Form requests and validation rules.
- Reconciliation services and accounting movement queries.
- Reconciliation item creation/removal logic.
- Completion and reopening logic.
- Policies and `config/authorization.php`.
- Relevant feature tests and accounting-control services.

The frontend handoff identifies these endpoint families as candidates to verify:

| Operation                         | Reported endpoint                                                |
| --------------------------------- | ---------------------------------------------------------------- |
| List/create reconciliation        | `GET/POST /api/bank-reconciliations`                             |
| View/update/delete reconciliation | `GET/PUT/DELETE /api/bank-reconciliations/{reconciliation}`      |
| Read eligible movements           | `GET /api/bank-reconciliations/{reconciliation}/movements`       |
| Add an item                       | `POST /api/bank-reconciliations/{reconciliation}/items`          |
| Remove an item                    | `DELETE /api/bank-reconciliations/{reconciliation}/items/{item}` |
| Complete                          | `POST /api/bank-reconciliations/{reconciliation}/complete`       |
| Reopen                            | `POST /api/bank-reconciliations/{reconciliation}/reopen`         |

These paths are starting points, not permission to assume their current
implementation. Verify the methods, request bodies, response resources, route
binding, validation, and authorization from source before using them.

Before coding, produce a concise internal contract checklist covering every
supported operation.

---

## 4. Routes and Navigation

Follow the route conventions established in Phases 5–8.

Prefer:

- `/bank-reconciliations`
- `/bank-reconciliations/[id]`, if a detail route is appropriate for the
  verified design.

Use the established feature structure, for example:

- `src/lib/bank-reconciliations/`
- `src/components/bank-reconciliations/`
- `src/app/(app)/bank-reconciliations/`

Adapt these paths to the current repository conventions rather than duplicating
an existing implementation.

Add the Bank Reconciliation entry under the existing Banking navigation section.
Use actual permission checks.

Do not reorganize the existing Cash & Banking, Accounts, Journals, Financial
Years, or Periods routes.

Do not implement an unrelated bank-account management redesign.

---

## 5. Reconciliation List

Implement a list screen based on the verified list endpoint and response
resource.

Show fields only when returned by the API, such as:

- Reconciliation reference or number.
- Cash/bank account.
- Statement date or reconciliation date.
- Statement balance.
- Reconciled or calculated balance, if exposed.
- Difference, if supplied or defined by the backend.
- Status.
- Supported lifecycle actions.

Verify the exact lifecycle values from the model and enum. Do not invent status
values.

Provide server-side filters only where supported by the API, such as account,
status, and date range.

If pagination metadata is absent, follow the existing `normalizeList`
convention. Do not fabricate totals, last-page values, or exact result counts.

Handle the full-page/empty-next-page edge case without displaying a false empty
result.

Provide loading, error, retry, empty, and populated states.

---

## 6. Create and Edit Reconciliation

Implement create/edit only where the backend supports it.

Verify whether creation requires:

- A cash/bank account.
- A statement date or statement period.
- A statement closing balance.
- A reference or description.
- Any other backend-required fields.

Do not invent fields or send server-owned values.

Validation must reflect the real form requests, including:

- Required fields.
- Date formats and boundaries.
- Account eligibility.
- Amount precision.
- Duplicate or conflicting reconciliations.
- Restrictions after completion.

Use decimal strings for monetary values. Never use JavaScript floating-point
arithmetic to determine whether a financial reconciliation balances.

The backend remains authoritative. Display its validation errors clearly,
including field-specific `422` errors.

If the API does not permit editing a completed reconciliation, do not present an
edit action for that state.

---

## 7. Reconciliation Detail and Movement Review

Provide a useful detail screen showing the reconciliation's verified details and
lifecycle status.

If the movement endpoint exists as documented, use it to display eligible ledger
movements.

Verify the movement resource and whether the API returns:

- Movement ID.
- Posting date.
- Reference or journal number.
- Description.
- Debit and credit.
- Signed amount or transaction amount.
- Account.
- Reconciliation-item status.
- Other supported identifying fields.

Display only the fields actually returned.

### Movement selection

If the backend permits selecting ledger movements as reconciliation items:

- Provide clear selection controls.
- Distinguish already-reconciled, selected, eligible, and ineligible movements
  according to the actual contract.
- Submit the exact identifier expected by the API.
- Prevent duplicate selections in the client where possible.
- Refresh the reconciliation and movement data after successful mutations.
- Handle conflicts and stale eligibility responses from the backend.

Do not fabricate bank-statement lines or ledger movements.

Do not automatically mark every movement as reconciled merely because it falls
within a date range.

Do not modify or delete journal entries through this UI.

---

## 8. Reconciliation Items

Verify how the backend represents reconciliation items and how they relate to
ledger movements.

Implement adding and removing items only according to the actual API.

For every item operation:

- Verify the required payload.
- Use the correct reconciliation and item identifiers.
- Respect company scoping.
- Prevent duplicate submission while a request is pending.
- Show backend validation and authorization errors.
- Refresh affected detail, movement, and summary data after success.

If the backend requires a reconciliation to be open before changing items,
enforce that state in the UI as a convenience while relying on the backend for
final enforcement.

Do not create a second reconciliation engine in the browser.

Do not silently delete items when a user changes a date or account.

---

## 9. Balances and Difference Calculation

This area requires particular care.

First inspect how the backend defines statement balance, ledger balance, matched
amount, outstanding amount, and difference.

If the API returns an authoritative difference, display it without recalculating
it under a different convention.

If the backend does not return a difference but the source explicitly defines a
calculation, the frontend may display a derived value only when it can calculate
it exactly using decimal-string or BigInt fixed-point arithmetic and the
definition is unambiguous.

Document the sign convention.

Never:

- Sum values from different currencies.
- Use floating-point arithmetic for financial comparisons.
- Assume debit and credit signs without checking the backend convention.
- Label a ledger balance as a bank-statement balance.
- Treat a zero difference alone as proof that all required controls passed.
- Mark a reconciliation complete from client-side arithmetic.

Completion must be performed through the supported backend endpoint.

---

## 10. Complete and Reopen Lifecycle

Verify the backend lifecycle and permissions before implementing either action.

### Completion

If supported:

1. Show the current reconciliation status.
2. Display the available summary and any backend-provided blockers.
3. Explain the consequences of completing the reconciliation.
4. Require explicit confirmation.
5. Submit the real completion request.
6. Display the backend result or refusal.
7. Refresh the reconciliation and its related data.

The backend may require zero difference, no unresolved movements, or other
checks. Inspect the actual service and tests; do not invent these conditions.

If completion fails because of a backend control, show the returned message. Do
not force completion or create a fallback status update.

### Reopening

If supported:

1. Show the reopen action only for eligible states and users.
2. Explain the effect of reopening using the actual backend semantics.
3. Require explicit confirmation.
4. Submit the real reopen endpoint.
5. Surface any refusal from the backend.
6. Refresh the affected data.

Do not assume reopening is always permitted.

Do not directly mutate status fields or bypass the lifecycle endpoint.

---

## 11. Permissions

Verify the exact permissions from `config/authorization.php`, policies, and
tests.

The existing API handoff identifies `accounting.bank_reconciliation.*` as the
permission family. Confirm the actual suffixes for viewing, creating, updating,
deleting, completing, and reopening.

Apply permission checks to:

- Navigation visibility.
- List and detail reads.
- Create/edit forms.
- Add/remove reconciliation items.
- Complete action.
- Reopen action.
- Delete action, if supported.

Do not hard-code role names such as Admin, Accountant, or Manager as a
substitute for capability checks.

Users without view permission must not trigger protected reads merely because a
component mounts.

The Laravel backend remains authoritative. A hidden button is not a security
control.

---

## 12. Company Isolation and Request Lifecycle

Follow the existing company-aware hook architecture.

Ensure:

- Reads are keyed to the active company.
- Obsolete requests are aborted where supported.
- Stale reconciliation details are not displayed after a company switch.
- The detail page revalidates the selected reconciliation under the current
  company.
- Mutations cannot silently proceed under a different active company than the
  one under which the form was opened.
- No client-supplied `company_id` is sent unless the verified API contract
  explicitly requires it.
- Foreign-company identifiers are handled according to the backend's
  company-scoped route binding, expected to return `404`.

Never use cached data from a previous company as a fallback.

---

## 13. Data Layer and Runtime Validation

Use the existing frontend API helpers and error conventions.

Implement a focused feature module containing, as needed:

- Types and lifecycle constants.
- Runtime response validators.
- API functions.
- List/detail/movement hooks.
- Reconciliation-specific UI components.
- Tests for validators, API methods, hooks, and components.

Runtime validators must reflect actual backend resources.

Unknown lifecycle values must be handled safely. Do not interpret an unknown
status as completed or open.

Avoid duplicating request logic in page components.

Do not introduce a new query cache, form library, or validation dependency
without explicit justification and a clear benefit to the existing architecture.

Do not refactor unrelated modules.

---

## 14. UX and Accessibility

Follow the established Accountance design:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Clean, minimal layouts.
- Shared tables, forms, dialogs, badges, alerts, and notifications.
- Semantic status colors.
- Responsive desktop, tablet, and mobile layouts.
- Keyboard-accessible dialogs and controls.
- Visible focus indicators and accessible labels.
- Consistent date formatting and exact monetary presentation.

Provide:

- Loading skeletons.
- Empty states.
- Error and retry states.
- Form validation.
- Pending mutation indicators.
- Success feedback.
- Clear completion and reopening confirmations.
- Explicit explanations for permission-denied and backend-blocked actions.

Avoid unnecessary dashboard cards or summary totals that the API does not
support.

---

## 15. Testing Requirements

Use the existing Vitest and React Testing Library setup.

Add focused tests for actual behavior.

### Validators and API

- Valid and malformed reconciliation responses.
- Unknown lifecycle values.
- Movement and item response validation.
- List filters and pagination query serialization.
- Create/edit payloads.
- Movement retrieval.
- Adding and removing items.
- Completion and reopening endpoints.
- Error handling, including field-level `422` errors.
- Abort signal propagation.

### UI and security boundaries

- List rendering, loading, empty, and error states.
- Create/edit forms where supported.
- Reconciliation detail and movement display.
- Adding/removing eligible movements.
- Permission-gated controls.
- Missing view permission causes no protected request.
- Completion confirmation and backend failure.
- Reopening confirmation and backend failure.
- Pending-state duplicate-submit prevention.
- Company switching cannot display stale data.
- Malformed API responses do not crash the screen.
- Accessible dialog behavior.

Do not remove or weaken existing tests to make the suite pass.

Do not add meaningless tests solely to increase the test count.

---

## 16. Live Integration and Evidence

If a configured Laravel backend and database are available, use the existing
approved environment for safe read-only integration checks and explicitly
authorized test workflows.

Do not change backend files or run migrations or seeders to make the integration
work.

If live integration is unavailable, state that clearly. Use fixtures derived
from the actual backend source and tests, and distinguish source inspection from
runtime verification.

Do not claim that company isolation, permissions, completion rules, or financial
reconciliation were live-tested unless they actually were.

---

## 17. Documentation

Create:

`docs/FRONTEND_PHASE_9_REPORT.md`

Include:

1. Executive summary and completion status.
2. Initial Git status and pre-existing user work.
3. Any repository-preservation risks discovered before implementation.
4. Backend contract verified from actual source.
5. Exact routes and supported actions implemented.
6. Permissions and authorization behavior.
7. Movement-selection and reconciliation-item behavior.
8. Balance/difference definitions and arithmetic conventions.
9. Completion/reopen lifecycle.
10. Company isolation.
11. Files added and changed.
12. Tests and quality-gate results.
13. Live integration status.
14. Known limitations and deviations from the blueprint.
15. Confirmation that backend files were untouched.
16. Confirmation that no destructive Git operations, commits, migrations, or
    seeders were performed.

Update `FRONTEND_BLUEPRINT.md` or `FRONTEND_API_HANDOFF.md` only for verified
decisions or meaningful implementation changes. Do not overwrite prior reports.

If the repository already contains conflicting or incomplete reports, preserve
them and document the discrepancy rather than replacing them blindly.

---

## 18. Quality Gates

Run from the actual frontend directory:

```bash
npm run lint
npx tsc --noEmit
npm test
npm run build
```

Report the actual exit status and test counts.

If dependencies are missing, inspect the lockfile and existing package
configuration before installing anything. Avoid changing dependency versions or
package scripts unnecessarily.

Fix Phase 9 regressions without globally disabling lint rules, weakening
TypeScript, or removing tests.

---

## 19. Strict Scope Boundaries

### Included

- Bank reconciliation list and filters.
- Reconciliation create/edit where supported.
- Reconciliation detail.
- Ledger-movement review.
- Adding/removing reconciliation items.
- Completion and reopening where supported.
- Permissions, company isolation, validation, and tests.

### Excluded

- Laravel backend modifications.
- Database changes, migrations, and seeders.
- New accounting or reconciliation rules.
- Journal editing or posting outside existing supported workflows.
- Changes to cash/bank transaction logic.
- Tax, fixed assets, budgets, or dimension modules.
- Dashboard redesign.
- Unrelated refactoring.
- New dependency adoption without justification.
- Repository pulls, destructive Git operations, or cleanup.
- Starting Phase 10.

Do not implement unsupported features merely because they appear in the API
handoff or a future roadmap.

---

## 20. Final Verification and Stop Condition

Before finishing:

1. Re-run the required quality gates.
2. Review the final diff for unrelated changes.
3. Inspect the new Phase 9 report.
4. Verify that no backend files were edited.
5. Verify that no pre-existing user work was discarded.
6. Run `git status --short`.
7. Run `git status --short -- accounting-app/backend`, adjusting paths to the
   actual repository layout.
8. Record any pre-existing backend changes separately from Phase 9
   modifications.

Do not commit anything.

**Phase 9 is complete only when the supported reconciliation UI is implemented,
tests and quality gates pass, the report is written, and existing work remains
preserved.**

Stop after Phase 9. Do not start Phase 10.

Provide a concise completion summary and point me to
`docs/FRONTEND_PHASE_9_REPORT.md` for review before the next phase.
