# Accountance Frontend — Phase 20: Company Administration & Authentication Completion

## 1. Role and objective

You are working as a senior frontend engineer on the Accountance Accounting Web
App.

**Phase 20 scope: Company Administration & Authentication Completion — frontend
only.**

Repository root:

`D:\Development\Accountance`

Frontend:

`accounting-app/frontend`

Backend:

`accounting-app/backend`

The backend implementation is reported complete through Phase 18, and the
frontend has completed Phase 19. Before implementing anything, inspect the
actual repository, existing frontend architecture, backend routes, controllers,
policies, requests, resources, services, tests, and authorization configuration.

Your task is to finish the supported company administration and
authentication-related frontend workflows without duplicating existing
functionality or inventing backend capabilities.

This is an implementation task, not merely an audit or planning exercise.

## 2. Mandatory safety and repository rules

### 2.1 Preserve existing work

The working tree contains uncommitted work from Phases 17, 18, and 19, including
modified files, new application routes, components, libraries, tests, and
reports.

The Phase 19 report identifies pre-existing changes including:

- Modified fixed-asset validators.
- Modified navigation configuration and navigation tests.
- Untracked fixed-asset routes, components, libraries, and reports.
- Untracked currency, exchange-rate, and FX-settings functionality.
- Untracked budget and dimension functionality.
- Existing prompt files and directories.
- A previously deleted prompt file that must remain untouched unless the
  operator explicitly asks otherwise.

These are examples, not an exhaustive inventory. Inspect the actual Git status
before making changes.

**Never overwrite, delete, restore, discard, rename, or silently replace
existing user work.**

### 2.2 Prohibited operations

Unless the user explicitly authorizes them separately, do not:

- Run `git reset`, `git clean`, `git stash`, `git checkout`, `git switch`,
  `git restore`, `git pull`, `git fetch`, `git rebase`, or other operations that
  may change, discard, or rewrite repository state.
- Create a Git commit or push to any remote.
- Run database migrations or seeders.
- Execute database-mutating commands.
- Modify any file under `accounting-app/backend/`.
- Change `package.json`, package lockfiles, dependency versions, or
  install/remove dependencies.
- Weaken, delete, skip, or disable existing tests or lint rules.
- Overwrite reports from earlier phases.
- Claim that live integration succeeded unless it was actually performed and
  verified.

Read-only Git commands, such as `git status --short`,
`git branch --show-current`, and `git rev-parse HEAD`, are permitted.

### 2.3 Scope restrictions

Phase 20 is frontend-only. All backend source inspection is read-only.

Do not change Laravel code, database schema, API contracts, permissions,
migrations, or seeders to make a frontend implementation easier.

If a required capability is missing from the backend, document it as a gap. Do
not fabricate an endpoint or simulate successful persistence.

## 3. Required initial inspection

Before editing any files:

1. Confirm repository root, current branch, HEAD, and complete initial
   `git status --short`.
2. Inspect the existing frontend master blueprint, frontend architecture
   blueprint, roadmap, API/BFF documentation, and relevant completed phase
   reports when present.
3. Read the current navigation and application-shell implementation.
4. Inspect authentication, session, company-context, permissions, and API helper
   implementations.
5. Inspect the backend route definitions and relevant controllers, Form
   Requests, resources, policies, services, models, tests, and permission/role
   definitions.
6. Identify which Phase 20 features already exist and which are genuinely
   missing.
7. Write down the actual endpoints, request payloads, response shapes,
   authorization rules, and lifecycle restrictions before implementing the
   corresponding UI.

Do not assume that a route exists because it appears in a previous plan. Verify
it in source.

Do not duplicate working features from earlier phases. Extend the existing
implementation only when a real gap is found.

## 4. Existing architecture that must be preserved

The existing application uses:

- Next.js App Router.
- React and strict TypeScript.
- Tailwind CSS v4.
- Laravel REST API.
- A same-origin Next.js Backend-for-Frontend (BFF) proxy.
- JWT credentials held server-side in an encrypted HttpOnly cookie.
- Server-generated Authorization and company-context headers.
- Existing API helpers, runtime validators, API hooks, reusable UI components,
  notifications, formatting utilities, and navigation permission checks.
- Existing hand-written hooks and request cancellation patterns rather than
  introducing a new data-fetching library.

Follow the existing architecture exactly.

### Security requirements

- Never expose the Laravel JWT to browser JavaScript.
- Never store authentication tokens in localStorage or sessionStorage.
- Never call the Laravel API directly from browser-side components.
- Never allow a client-supplied company ID to override the trusted server-side
  company context.
- Do not trust client-supplied roles, permissions, user IDs, or company
  membership.
- Do not treat hiding a button as sufficient authorization.
- Preserve the current BFF route allowlist and its security controls.
- Never expose secrets, authentication cookies, or sensitive server responses in
  logs, reports, or error messages.
- Preserve existing CSRF protections and cookie security settings.
- Avoid leaking company-specific information after logout, session expiration,
  or company switching.

## 5. Phase 20 functional scope

Implement only the features supported by the existing backend. Each subsection
below is a discovery and implementation target, not a claim that every endpoint
exists.

### 5.1 Company profile and settings administration

Inspect the existing company settings and company context implementations.

Determine which company profile fields and system settings can actually be read
and updated. Potential fields may include:

- Company name and legal or trading name.
- Registration or tax identification details, if exposed.
- Address and contact information.
- Country, locale, timezone, and date/number formatting preferences, if
  supported.
- Base currency, where a supported read and write workflow exists.
- Invoice and quotation prefixes.
- Payment terms.
- Fiscal-year configuration, where supported by the existing API.
- Company lifecycle status, if administrative changes are supported.

Implement an appropriate company settings interface using only verified fields
and endpoints.

Requirements:

- Respect company scoping and membership authorization.
- Distinguish editable, read-only, and server-managed fields.
- Preserve nullable-field semantics and the backend's actual clearing behavior.
- Do not send server-owned fields such as IDs, ownership fields, membership
  data, or lifecycle metadata unless the backend contract explicitly requires
  them.
- Display validation errors from the API in a useful form.
- Show loading, saving, success, failure, and retry states.
- Prevent duplicate submissions while a mutation is in progress.
- Do not imply that a setting was saved until the server confirms success.
- Avoid duplicating existing fiscal-year, accounting-period, currency, or FX
  settings screens.

If the base currency can be updated but cannot be retrieved reliably, do not
invent its current value or silently introduce a misleading editor. Document the
limitation and implement only a safe workflow supported by the actual contract.

### 5.2 Company listing, creation, and lifecycle

Inspect the backend's company-related endpoints and policies.

Determine whether the current authenticated user can:

- List accessible companies.
- Create a company.
- View company details.
- Update company details.
- Activate, deactivate, archive, or otherwise change company lifecycle state.
- Change their default company.
- Switch active company context.

Implement only supported workflows that are not already implemented correctly.

The existing company switcher and company-context infrastructure must remain
compatible.

Requirements:

- Show only companies the backend authorizes the user to access.
- Do not assume that all authenticated users can create companies.
- Respect the backend's actual company membership rules.
- Do not allow switching to a company for which the user lacks membership.
- Do not treat a successful switch as permission to access arbitrary company
  IDs.
- After switching companies, refresh or invalidate company-scoped UI state
  according to existing application patterns.
- Avoid displaying stale records, financial totals, or cached report data from
  the previous company.
- Handle expired sessions and invalid company context safely.
- If company creation is supported, validate the form according to the actual
  API contract.
- If deletion or archival is unsupported, do not display a fake action.

If the current application already supports company switching correctly, retain
it and focus on missing administration features.

### 5.3 Company membership and user administration

Inspect actual backend support for company memberships, invitations, user
administration, and membership lifecycle.

Determine which of the following are genuinely supported:

- Viewing company members.
- Viewing individual member details.
- Adding or inviting an existing user.
- Assigning or changing a member's role.
- Removing a member from a company.
- Activating or deactivating membership.
- Changing the default company for a user, if permitted.
- Viewing membership status and available actions.

Implement supported functionality only.

Important rules:

- A user account and a company membership are different concepts. Preserve this
  distinction.
- Do not create a global user-management screen if the backend only supports
  company-scoped membership management.
- Do not assume that inviting users by email is supported.
- Do not invent invitation tokens, registration workflows, or invitation emails.
- Do not assume the backend permits changing the role of the current user.
- Respect last-administrator protections or other membership restrictions if
  present in the backend.
- Surface server refusals when a membership or role change is prohibited.
- Never allow a client to assign permissions directly unless the backend
  explicitly supports such a workflow.
- Use the real membership and role identifiers from the API.
- Prevent cross-company membership access and ID tampering.

If no appropriate membership-management API exists, document that gap and do not
simulate persistence.

### 5.4 Role and permission administration

Inspect the existing role and permission implementation before deciding whether
a UI is needed.

Determine whether roles and permissions are:

1. Fixed application-defined roles and permissions.
2. Backend-provided assignable roles.
3. Dynamically manageable roles or permissions.

Build a management UI only for operations the backend explicitly supports.

If roles are fixed, display the available role names and descriptions only when
the API provides enough information to do so accurately. Do not add role
creation, permission editing, or custom-role assignment without corresponding
backend support.

If role assignment is supported, implement it using the actual authorization
rules and available roles.

Preserve all existing permission checks and role mappings. Do not infer that
Admin, Manager, Accountant, or Regular User has a particular permission without
verifying the current backend and frontend authorization configuration.

### 5.5 Authenticated user profile

Inspect existing `/me` or equivalent session endpoints and account-related API
routes.

Determine whether the frontend already supports:

- Viewing the current user's profile.
- Updating editable profile fields.
- Changing a password.
- Viewing session information.
- Logging out.
- Recovering or refreshing the session.

Avoid duplicating working features.

If supported, implement the missing authenticated profile workflows using the
existing session and BFF architecture.

Requirements:

- Read the authenticated user from the trusted session endpoint.
- Treat user IDs, roles, and permissions returned by the server as
  authoritative.
- Do not allow profile updates to modify protected fields such as role,
  permissions, account status, or company membership.
- Validate required fields and password confirmation according to the actual
  backend contract.
- Never log passwords or return them in UI state, notifications, or error
  messages.
- Clear password fields after successful submission.
- Handle invalid credentials, expired sessions, rate limits, and validation
  errors according to actual API behavior.
- Preserve the existing login/logout flows and encrypted HttpOnly cookie
  behavior.

### 5.6 Authentication completion and recovery

Inspect the current authentication routes and backend implementation to identify
genuinely incomplete workflows.

Potential targets include:

- Login and logout.
- Session restoration and authenticated user refresh.
- Password recovery request.
- OTP or recovery-code verification.
- Password reset.
- Password change for an authenticated user.
- Session expiration and unauthorized-request handling.
- Authentication error and retry states.

Implement only the missing workflows for which the backend provides real
endpoints and sufficient contract information.

Do not invent email delivery, OTP validity periods, recovery codes, rate limits,
or password rules. Verify them in backend source.

Preserve existing authentication routes, request payloads, and cookie semantics.
Do not change the authentication mechanism.

For recovery screens:

- Do not reveal whether an email belongs to an account if the backend
  intentionally returns a generic response.
- Do not expose OTPs or recovery tokens in URLs, logs, or browser storage.
- Do not persist sensitive recovery information beyond the existing intended
  flow.
- Provide clear validation and expiry/error states based on verified API
  behavior.
- Avoid changing working login and session code merely to refactor it.

If authentication recovery is already complete, test and harden the existing
frontend instead of creating duplicate screens.

## 6. Routes and information architecture

Inspect the current route structure and navigation before selecting routes.

Possible routes, **only when justified by actual backend support and current
gaps**, include:

- `/settings/company`
- `/settings/company/members`
- `/settings/profile`
- `/settings/security`
- `/companies/new`
- `/forgot-password`
- `/reset-password`

These are suggestions, not mandatory routes.

Reuse existing routes where possible. Do not introduce a parallel settings
hierarchy or duplicate company switcher.

Any new routes must:

- Use the existing App Router structure.
- Use the authenticated application shell or public authentication layout as
  appropriate.
- Enforce access restrictions consistently with existing patterns.
- Handle invalid IDs and inaccessible resources safely.
- Appear in navigation only when appropriate.
- Have accurate page metadata.
- Respect direct URL access and refresh behavior.

Do not expose company administration links to users lacking the required
permission.

## 7. Navigation and authorization

Inspect the current navigation configuration and the actual backend permission
matrix.

Add or update navigation entries only for features implemented in this phase.

Use verified permission keys. Do not invent permission names or assume that a
role name is equivalent to a permission.

Requirements:

- Keep navigation changes additive.
- Preserve all existing navigation items and permission mappings.
- Ensure the sidebar does not show actions the user cannot access, according to
  the existing permission model.
- Ensure direct route access also handles unauthorized responses appropriately.
- Use the existing 403/unavailable state patterns.
- Handle session expiration consistently across the application.
- Avoid treating a missing permission property as permission granted.

Where a feature is available only to a company administrator, enforce that
restriction using the actual backend contract and existing frontend
authorization model.

## 8. API clients, runtime validation, and state management

All API communication must use the established same-origin BFF and existing API
helpers.

For each feature implemented:

1. Define TypeScript types from the verified response contract.
2. Add or extend API functions following existing naming and error-handling
   conventions.
3. Add runtime validators for untrusted API responses.
4. Handle envelopes, pagination, nullability, and optional fields according to
   actual backend responses.
5. Use existing hooks and request-cancellation patterns.
6. Add permission-aware UI behavior.
7. Implement loading, empty, error, retry, saving, and success states.
8. Test success, validation failure, authorization failure, missing records, and
   relevant lifecycle restrictions.

Do not assume every list returns the same pagination shape. Existing API
responses may contain Laravel pagination metadata or flat arrays.

Do not invent `links`, `meta`, totals, user counts, membership statuses, or
default roles when they are not returned.

Do not create fake local records to make an incomplete endpoint appear
functional.

Preserve server-generated fields and company context.

## 9. UI/UX standards

Maintain the established Accountance visual design:

- Calm, green-based Light Mode.
- Professional Dark Mode.
- Minimal and easy-on-the-eyes layouts.
- Green as a restrained primary brand/accent color.
- Semantic colors reserved for meaningful success, warning, error, and
  information states.
- Consistent spacing, typography, dialogs, forms, tables, buttons, badges, and
  notifications.
- Responsive behavior for desktop, tablet, and smaller screens.
- Accessible labels, keyboard navigation, focus indicators, and dialog behavior.

Use existing shared components before creating new ones.

For administrative operations:

- Confirm potentially consequential actions such as removing memberships or
  deactivating a company.
- Explain the target of the action in confirmation dialogs.
- Disable duplicate submissions while an operation is pending.
- Display backend errors clearly without exposing internal stack traces or
  sensitive information.
- Do not optimistically present a destructive or administrative change as
  successful before confirmation from the server.

Avoid unnecessary dashboards, charts, complex abstractions, new state-management
libraries, or decorative components.

## 10. Testing requirements

Add tests for every new or materially changed feature.

Follow the repository's existing Vitest and React Testing Library conventions.

At minimum, cover the relevant cases for implemented functionality:

### API and validator tests

- Correct endpoint and HTTP method.
- Correct request payload.
- Correct handling of nullable and optional fields.
- Correct envelope and list normalization.
- Invalid or incomplete response handling.
- Validation errors and non-success responses.
- No client-supplied company context or server-owned fields where these must be
  omitted.

### Component and workflow tests

- Loading, success, empty, and error states.
- Permission-based visibility.
- Form validation.
- Successful saves and failed saves.
- Confirmation dialogs for consequential actions.
- Duplicate-submission prevention.
- Company switching behavior and state refresh, if modified.
- Session expiration and unauthorized responses, if modified.
- Membership and role restrictions, if implemented.
- Password recovery and reset behavior, if implemented.

### Regression tests

- Existing login and logout behavior.
- Existing company context and company switcher.
- Existing BFF proxy security.
- Existing navigation and permission gates.
- Existing accounting modules, including budgets, dimensions, currencies,
  exchange rates, reports, and fixed assets.

Do not delete or weaken tests to make the suite pass.

Do not add dependencies solely to make testing easier.

## 11. Security review

Review relevant existing frontend security controls without unnecessarily
refactoring unrelated code.

Verify that:

- JWTs remain server-held in encrypted HttpOnly cookies.
- Browser components never receive the raw JWT.
- Authenticated requests go through the same-origin BFF.
- The BFF allowlist and header filtering remain intact.
- Company context is generated and validated server-side.
- Redirects and return URLs cannot introduce open redirects.
- Login and logout continue to handle cookies correctly.
- Authentication failures clear or retain session state according to the
  existing secure behavior.
- Error displays do not leak sensitive information.
- User and membership IDs are not treated as proof of authorization.
- Sensitive forms do not expose password or recovery values after completion.
- Newly introduced routes follow the existing authorization model.

Do not claim a comprehensive security audit unless you actually performed one.
Report the scope and limitations of your review.

## 12. Quality gates

Run the following commands from `accounting-app/frontend`:

```bash
npm run lint
npx eslint . --max-warnings=0
npx tsc --noEmit
npx vitest run
npm run build
```

All five must pass.

If a gate fails:

1. Identify whether the failure is caused by Phase 20 changes or a pre-existing
   issue.
2. Fix issues introduced by Phase 20.
3. Do not silently weaken checks or change unrelated code to conceal a failure.
4. Report any genuine pre-existing blocker clearly.

Review the production build output and verify that newly implemented routes are
emitted.

Do not claim that live backend integration passed based solely on mocks, source
inspection, tests, or a production build.

## 13. Documentation and phase report

Create a new report:

`accounting-app/docs/FRONTEND_PHASE_20_REPORT.md`

Do not overwrite or modify reports from earlier phases.

The report must include:

1. Executive summary and completion status.
2. Initial branch, HEAD, and Git status.
3. Pre-existing modifications and untracked files identified before
   implementation.
4. Actual backend endpoints, payloads, response shapes, and permissions
   verified.
5. Features implemented and routes added or modified.
6. Existing features inspected and intentionally left unchanged.
7. API clients, validators, hooks, and reusable components added or modified.
8. Authentication and company-context security considerations.
9. Tests added and test-suite totals.
10. Results of all five quality gates.
11. Live integration status, explicitly distinguishing source inspection,
    automated tests, build verification, and live testing.
12. Unsupported features and backend contract gaps.
13. Known limitations and recommended follow-up work.
14. Final `git status --short`, including all modified, deleted, and untracked
    paths.
15. Confirmation that no backend files, dependencies, database operations,
    commits, pushes, or prohibited Git operations were performed.

Be precise. Do not claim a feature is implemented if it was merely inspected or
planned.

If no meaningful feature is missing, document the audit findings and implement
only justified improvements rather than manufacturing unnecessary work.

## 14. Acceptance criteria

Phase 20 is complete only when:

- Company administration workflows supported by the backend are implemented or
  existing implementations are verified and intentionally retained.
- Supported profile and authentication workflows are complete without
  duplicating existing screens.
- Membership and role management are implemented only where the backend supports
  them.
- API requests and authorization follow the existing BFF architecture.
- Runtime validation and error handling follow project conventions.
- New and changed functionality has appropriate tests.
- Existing functionality from Phases 17–19 remains intact.
- All five quality gates pass, or any confirmed pre-existing blocker is reported
  transparently.
- `accounting-app/docs/FRONTEND_PHASE_20_REPORT.md` is created.
- All Phase 20 work remains uncommitted.

## 15. Final stop condition

**Stop after Phase 20.**

Do not start Phase 21 or any other phase.

Do not create a commit or push.

Do not perform prohibited Git operations.

Do not modify backend files, dependencies, lockfiles, migrations, seeders, or
database contents.

Preserve all pre-existing work, including untracked files and the pre-existing
deleted prompt file.

At completion, provide a concise summary of implemented features, routes, test
results, quality-gate outcomes, unresolved backend gaps, and the report path.
Clearly state that the changes remain uncommitted and that live integration was
not performed unless it was genuinely verified.
