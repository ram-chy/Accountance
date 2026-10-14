# Accountance Frontend — Phase 3: Authenticated API Proxy & Application Shell

## 1. Role and Objective

Act as a senior full-stack engineer specializing in Laravel REST APIs, Next.js
App Router, TypeScript, web application security, and accounting software
architecture.

Implement **Frontend Phase 3 only** for the Accountance Accounting Web App.

The goal is to establish a secure, reusable, company-aware API communication
layer and a professional authenticated application shell. This foundation must
be ready for subsequent accounting modules without requiring major architectural
changes.

**Do not implement accounting business screens in this phase.**

Work carefully. Inspect the existing repository and documentation before
modifying anything. Preserve the established architecture, existing working
code, and user changes.

---

## 2. Project Context

Repository root: `E:\Ram\Accountance` or the actual current repository root.

Frontend application: `accounting-app/frontend`

Backend application: `accounting-app/backend`

Read these documents before implementation:

- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_PHASE_0_AUDIT.md`
- `docs/FRONTEND_PHASE_0_REPORT.md`
- `docs/FRONTEND_PHASE_1_REPORT.md`
- `docs/FRONTEND_PHASE_2_REPORT.md`
- Any existing API handoff documentation, including
  `docs/FRONTEND_API_HANDOFF.md` if present.
- Relevant backend authentication, company context, authorization, routing, and
  API response source code.

Verify the actual file locations before reading. Do not assume every listed path
exists exactly as written.

### Existing technology stack

- Next.js App Router, currently reported as Next.js 16.4.0.
- React 19.3.0.
- TypeScript with strict checking.
- Tailwind CSS v4.
- Laravel 13 REST API.
- MySQL 8.4 LTS.
- Existing Laravel bearer-JWT authentication.
- Existing BFF session architecture using encrypted `HttpOnly` cookies.
- Vitest unit testing.
- Sonner notifications, already added in Phase 2.
- Existing dependency and design-system choices from Phases 0–2.

Check actual installed versions and configuration before relying on these
reported versions.

### Existing authentication architecture

Phase 2 established:

1. The browser communicates with same-origin Next.js session endpoints.
2. The Laravel JWT is held inside an encrypted server-side session cookie.
3. Client-side JavaScript must never receive the JWT.
4. Next.js server code reads the session and communicates with Laravel.
5. The frontend uses `/api/session/login`, `/api/session/logout`,
   `/api/session/me`, and `/api/session/company`.
6. Laravel provides the authenticated user and permission information.
7. Company context is selected through a validated company membership and
   represented to Laravel through `X-Company-Id`.
8. The backend currently has no JWT refresh endpoint.
9. Company switching updates the user's default company in the backend.

Preserve this architecture unless source-code inspection proves a specific issue
that requires a documented correction. Do not revert to browser-side JWT
storage.

### Current frontend files

Phase 1 created reusable API utilities, response types, pagination
normalization, formatting helpers, theme tokens, and basic UI components.

Phase 2 added the authentication provider, session routes, encrypted cookie
helpers, permission utilities, navigation foundation, app shell, company
switcher, user menu, and Sonner host.

Inspect the actual implementation before changing or duplicating any of these
components.

---

## 3. Mandatory Safety Rules

Before editing:

1. Run `git status --short` from the repository root.
2. Inspect the existing diff and untracked files.
3. Record the initial state in your report.
4. Inspect the frontend package scripts and current dependencies.
5. Review Phase 2 authentication/session code and relevant backend source.

**Never:**

- Discard, reset, overwrite, or silently remove existing user work.
- Run `git reset`, `git clean`, stash, or destructive Git operations.
- Create a commit.
- Modify Laravel backend business logic or database schemas.
- Add migrations, seeders, or database data.
- Invent endpoints, permissions, request headers, or response formats.
- Expose JWTs, cookie secrets, or other server secrets to the browser.
- Add a generic unrestricted proxy that forwards arbitrary URLs or headers.
- Disable authentication or authorization to make tests pass.
- Build chart-of-accounts, journals, ledger, reports, dashboard metrics, or
  other accounting modules in this phase.
- Introduce unnecessary dependencies or refactor unrelated code.

Keep all Phase 3 changes uncommitted.

If a security issue requires a backend change, document the issue and stop at
the frontend boundary unless the fix can be made entirely within the existing
frontend architecture.

---

## 4. Phase 3 Scope

Implement these workstreams in order:

1. Pre-implementation inspection and architecture validation.
2. Secure authenticated API proxy.
3. Centralized company-context forwarding.
4. API error and expired-session handling.
5. Company-switch cache invalidation and context consistency.
6. Authenticated application shell and navigation foundation.
7. Security, isolation, and regression tests.
8. Documentation and final verification.

Do not start the next workstream until the relevant existing implementation has
been understood.

---

## 5. Workstream A — Pre-Implementation Inspection

Inspect:

- `src/lib/api/client.ts`
- `src/lib/api/server-config.ts`
- `src/lib/api/response.ts`
- `src/lib/auth/session.ts`
- `src/lib/auth/session-cookie.ts`
- `src/lib/auth/session-crypto.ts`
- `src/lib/auth/laravel.ts`
- `src/lib/auth/snapshot.ts`
- `src/lib/auth/client.ts`
- `src/lib/auth/paths.ts`
- `src/lib/permissions/permissions.ts`
- `src/lib/navigation/nav.ts`
- `src/components/auth/auth-provider.tsx`
- `src/components/app/app-shell.tsx`
- `src/components/app/app-header.tsx`
- `src/components/app/company-switcher.tsx`
- `src/app/api/session/**`
- `src/proxy.ts`
- Relevant frontend and backend documentation.
- Laravel middleware, company-context resolution, authorization, routes, and API
  response handling.

Use the actual file structure if it differs.

Confirm:

- How server-side session retrieval works.
- How session decryption failures and expired cookies are handled.
- How the active company ID is selected and validated.
- How the Laravel bearer token is currently attached to requests.
- Which Laravel routes require `X-Company-Id`.
- Whether the backend uses `{success, data}`, Laravel paginator responses, or
  other documented response envelopes.
- Whether a generic backend proxy was explicitly deferred in Phase 2.
- Whether current route protection and the authenticated layout already cover
  the intended application shell.
- Whether the backend supports the headers and status codes required for the
  planned proxy.

Write down the findings before implementing. Reuse existing helpers whenever
practical.

If a critical backend contract is unclear, do not guess. Document the
uncertainty and either test a conservative behavior or stop that specific
feature.

---

## 6. Workstream B — Secure Authenticated API Proxy

Implement a same-origin API proxy under a suitable route such as:

`src/app/api/backend/[...path]/route.ts`

Use the Next.js 16 conventions and inspect the existing route structure before
creating it.

The proxy must allow the browser to call the same-origin frontend while Next.js
forwards authorized requests to the Laravel API.

### 6.1 Authentication

For each protected request:

1. Read the authenticated session using the existing server-only session
   utilities.
2. Decrypt and validate the session cookie using the established session
   implementation.
3. Obtain the Laravel JWT exclusively in server-side code.
4. Forward the token upstream as `Authorization: Bearer <token>`.
5. Never serialize the token into a browser response, log, error message, URL,
   or client-side state.
6. Reject unauthenticated requests using the existing API error conventions.
7. Do not forward a request when session validation or decryption fails.

Do not create a second session system.

### 6.2 Safe upstream URL construction

The proxy must use the configured server-only Laravel API origin.

- Reuse the existing server configuration utilities.
- Validate the configured origin and required path format.
- Treat the incoming path as untrusted input.
- Prevent path traversal, protocol-relative URLs, encoded traversal, and
  arbitrary upstream destinations.
- Do not accept an arbitrary upstream URL from a query parameter, request body,
  or header.
- Do not forward credentials to a different origin.
- Preserve valid path segments and query parameters where appropriate.
- Reject unsupported methods and paths with consistent error responses.
- Never expose internal upstream URLs or credentials in public errors.

Use an explicit allowlist or a narrow API-prefix policy based on the verified
backend route contract. Do not create a proxy that blindly forwards every
Laravel route.

Prefer an allowlist of supported methods and path families. If a broader
API-prefix approach is required for future accounting modules, validate path
segments and block authentication/session-management paths that must not be
forwarded through this route.

Do not proxy the frontend's `/api/session/*` endpoints back to Laravel.

### 6.3 HTTP methods and bodies

Support only the methods required by the verified API contract, normally:

- GET
- POST
- PUT
- PATCH
- DELETE

Support request bodies for applicable methods. Preserve valid content types and
query parameters.

Do not automatically retry POST, PUT, PATCH, or DELETE requests.

Handle empty responses, `204 No Content`, JSON responses, and non-JSON upstream
responses safely.

Do not transform successful responses in ways that break existing Laravel API
contracts. Preserve useful status codes and response data, while filtering
headers that must not be forwarded.

Never forward the browser's `Cookie`, arbitrary `Authorization`, `Host`,
hop-by-hop headers, or untrusted company headers to Laravel.

Construct upstream authorization and company headers on the server.

### 6.4 Response headers

Forward only explicitly approved response headers, such as suitable content-type
and request-correlation headers.

Do not blindly forward:

- `Set-Cookie`
- Hop-by-hop headers
- Internal infrastructure headers
- Headers that could disclose server details

If the upstream returns a request ID, preserve it where safe so users and
developers can correlate errors.

### 6.5 Request size and error handling

Use a reasonable, documented request-body size policy compatible with expected
JSON accounting requests. Do not add arbitrary limits that would break normal
accounting operations.

Return predictable JSON errors for proxy-generated failures. Do not expose stack
traces, session ciphertext, JWTs, environment variables, or raw upstream
exception details.

For upstream timeouts or connection failures, return a suitable gateway/service
error. Avoid swallowing errors into fake success responses.

If streaming or binary requests are outside the current application's needs,
explicitly document that limitation rather than implementing a fragile partial
solution.

---

## 7. Workstream C — Company Context Forwarding

The active company is a security-sensitive context.

Use the existing authenticated session snapshot and company-selection utilities.
Do not trust a browser-provided `X-Company-Id` as authoritative.

For every proxied request that requires company context:

1. Obtain the active company ID from the server-validated session.
2. Validate that the ID corresponds to an active company in the authenticated
   session.
3. Construct `X-Company-Id` on the server.
4. Never forward a conflicting browser-supplied company header.
5. Never fall back to an arbitrary company ID from a query parameter or body.
6. Preserve backend membership and permission enforcement; the proxy is not a
   replacement for Laravel authorization.

Inspect the actual Laravel company-context middleware and route groups to
determine which requests require the header. Apply the header consistently to
the appropriate routes.

If no valid active company exists:

- Return a clear, consistent error for company-scoped requests.
- Do not guess a company.
- Do not forward the request without required context.

Avoid adding a database lookup to every frontend request if the existing
validated session snapshot is sufficient. If freshness requires revalidation,
document the chosen policy.

### Company-switch consistency

After a successful call to the existing company-switch session endpoint:

- Update the frontend's active-company snapshot.
- Prevent subsequent requests from using stale company context.
- Ensure previously displayed company-scoped data is not reused under the new
  company.
- Refresh the current-user/session snapshot when needed.
- Keep the browser and server session state consistent.
- Show clear success or failure feedback through the existing Sonner
  integration.

Do not invalidate unrelated global data unnecessarily.

The application must fail safely if the backend switch succeeds but refreshing
the frontend snapshot fails. Do not silently claim that the entire switch
completed successfully when the active context is uncertain.

---

## 8. Workstream D — API Client and Error Handling

Review the existing Phase 1 `apiFetch` client and Phase 2 browser BFF client.

Keep a clear distinction between:

- Browser-to-Next.js same-origin requests.
- Server-to-Laravel requests.
- Session/authentication requests.
- Company-scoped accounting API requests.

Avoid duplicate fetch abstractions that disagree on errors, headers, or response
envelopes.

### Requirements

1. Preserve existing API error types and envelope conventions unless the
   implementation demonstrably requires a compatible extension.
2. Keep validation errors available to future forms.
3. Preserve request IDs where available.
4. Handle empty bodies and `204` correctly.
5. Do not treat HTTP 200 with `{success: false}` as success.
6. Do not fabricate pagination totals.
7. Do not retry writes automatically.
8. Avoid exposing internal server error details.
9. Ensure JSON parsing failures are handled safely.
10. Make API functions usable by later modules without requiring each screen to
    reimplement authentication or company headers.

### Expired sessions

The backend has no JWT refresh endpoint, and the reported JWT TTL is 60 minutes.

When the upstream API returns a verified authentication-expiration response:

- Invalidate the frontend's authenticated state.
- Clear or invalidate the server session as appropriate.
- Direct the user to the login page with a safe return path where appropriate.
- Avoid redirect loops.
- Avoid clearing the session for ordinary authorization failures such as
  insufficient permissions (`403`).
- Distinguish upstream `401` from unrelated gateway failures.
- Do not implement token refresh without an actual backend refresh contract.

Review the existing `401` handler before changing it. Ensure server-side API
calls and browser-side API calls cannot create inconsistent logout behavior.

---

## 9. Workstream E — Authenticated Application Shell

Use the existing Phase 2 application shell and design system. Improve only what
is needed to make the foundation ready for future accounting modules.

The shell should include:

- A stable authenticated layout.
- Application identity and navigation structure.
- Active-company switcher.
- User/account menu and sign-out.
- Loading, error, and empty states.
- Responsive navigation suitable for desktop and smaller screens.
- Permission-aware navigation using the existing permission utilities.
- Accessible focus states and keyboard interactions.

### Navigation architecture

Create or refine the navigation configuration so future modules can be added
without rewriting the shell.

Use module groupings that fit the existing frontend blueprint, for example:

- Overview
- Accounting
- Sales
- Purchases
- Banking and Payments
- Reports
- Company Settings

These are navigation architecture examples, not permission to implement the
modules or invent their routes.

Only expose links for routes that actually exist. Future modules may be
represented as non-clickable placeholders only if the current design system
supports that pattern and the placeholder clearly indicates that the module is
not yet available.

Do not invent permission names. Inspect the backend authorization definitions
and existing frontend permission utilities.

### Visual design

Follow the established Accountance design system:

- Calm green primary brand/accent.
- Neutral slate-based surfaces.
- Clean, minimal, professional layout.
- Light Mode that is comfortable for long work sessions.
- Professional Dark Mode.
- Semantic colors reserved for appropriate success, warning, error, and
  informational states.
- Consistent spacing, typography, borders, focus indicators, and loading states.

Do not replace the existing theme system. Do not add a new icon package or UI
framework without demonstrating a clear need.

No accounting dashboard charts or financial widgets in this phase.

---

## 10. Workstream F — Security and Isolation Tests

Use the existing Vitest setup and test conventions. Add dependencies only if
necessary and compatible with the current project.

At minimum, test the following.

### Proxy authentication

- Requests without a valid session are rejected.
- Valid sessions forward the JWT only to the configured Laravel origin.
- The browser never receives the JWT.
- Session decryption failures fail safely.
- Client-supplied `Authorization` and `Cookie` headers are not forwarded.
- Sensitive upstream response headers are not exposed.

### Path and method validation

- Allowed methods work.
- Unsupported methods are rejected.
- Arbitrary upstream URLs cannot be selected by a request.
- Traversal and encoded traversal paths are rejected.
- Query parameters are preserved where valid.
- Session/authentication endpoints cannot be accidentally proxied back to
  Laravel.

### Company isolation

- A valid active company produces the correct server-generated `X-Company-Id`.
- A browser-supplied conflicting company header cannot override the active
  company.
- Missing or invalid active-company context fails safely.
- Company switching updates the next request's company context.
- A stale previous-company context is not reused after switching.

### Error handling

- Upstream `401` triggers the intended session-expiration behavior.
- Upstream `403` does not automatically sign out the user.
- Gateway failures return consistent errors.
- Empty and `204` responses are handled.
- Non-JSON upstream responses do not crash the application.
- Write operations are not automatically retried.

### Application shell

- Unauthenticated users are redirected correctly.
- Navigation respects actual permissions.
- Sign-out clears the session and updates client state.
- Loading and error states render correctly.
- Company-switch failure does not falsely report success.

Mock upstream HTTP calls and sessions in unit tests. Do not require production
credentials or a real database to run the unit suite.

Add integration tests where the existing test environment supports them. Clearly
distinguish mock tests from real-backend verification.

---

## 11. Workstream G — Documentation

Update `docs/FRONTEND_BLUEPRINT.md` only where necessary to reflect the
implemented and verified architecture.

Create:

`docs/FRONTEND_PHASE_3_REPORT.md`

The report must contain:

1. Scope and completion status.
2. Initial Git status and files already changed before work.
3. Architecture findings from source inspection.
4. Files created, modified, or removed.
5. Proxy route and supported methods/path policy.
6. Authentication and server-side JWT handling.
7. Company-context validation and header-forwarding behavior.
8. Session-expiration and error-handling policy.
9. Navigation and application-shell changes.
10. Security controls and known limitations.
11. Commands executed and their actual results.
12. Unit-test count and coverage summary.
13. Lint, type-check, and production-build results.
14. Whether a real Laravel integration test was performed.
15. Any remaining risks, blocked items, or deviations.
16. Confirmation that no backend files, migrations, seeders, or commits were
    created or modified.

Never claim that a check passed unless it was actually run successfully.

---

## 12. Validation Checklist

From `accounting-app/frontend`, run the available equivalent of:

1. Lint.
2. TypeScript type-check.
3. Unit tests.
4. Production build.
5. Any relevant existing security or integration test scripts.

Use the actual scripts from `package.json`; do not assume every command exists.

Verify at least these runtime behaviors using local mocks where necessary:

- Unauthenticated API request.
- Authenticated API request.
- JWT forwarded only server-side.
- Company header generated server-side.
- Conflicting browser company header ignored.
- Upstream `401`.
- Upstream `403`.
- Upstream gateway failure.
- Company switch followed by a request under the new company.
- Safe path and query handling.

If a validation fails, investigate and fix the underlying Phase 3 issue. Do not
weaken security checks to make the suite green.

If the production build creates generated files, inspect the Git diff and ensure
no secrets or unrelated generated artifacts are included.

---

## 13. Completion Criteria

Phase 3 is complete only when:

- [ ] The proxy uses the existing server-side encrypted session.
- [ ] JWTs remain inaccessible to browser JavaScript.
- [ ] Upstream URLs and paths are validated.
- [ ] Unsupported methods and unsafe paths are rejected.
- [ ] Company context is generated from validated session state.
- [ ] Browser-supplied authentication/company headers cannot override server
      values.
- [ ] Session expiration is handled consistently.
- [ ] Company switching cannot reuse stale company-scoped data.
- [ ] The existing app shell remains functional and responsive.
- [ ] Navigation uses verified permissions and real routes only.
- [ ] Security and isolation tests cover the critical behaviors above.
- [ ] Lint, type-check, tests, and production build pass.
- [ ] `docs/FRONTEND_PHASE_3_REPORT.md` is complete.
- [ ] The backend remains untouched.
- [ ] No commit, reset, stash, migration, or seeder was performed.

If any critical security or integration requirement cannot be satisfied safely,
report it as blocked rather than claiming full completion.

---

## 14. Out of Scope

Do not implement:

- Chart of Accounts UI.
- Journal entry UI or posting forms.
- Ledger or trial-balance screens.
- Financial reporting screens.
- Dashboard charts or financial metrics.
- Customer, sales, purchasing, inventory, tax, or payment modules.
- Backend API changes.
- JWT refresh functionality without a real backend contract.
- New authentication or password-recovery endpoints.
- Unrequested database schema changes.

Those belong to later phases.

---

## 15. Final Instructions

Work methodically. Prefer small, understandable changes over complex
abstractions.

Preserve the current codebase and user changes. Do not rush, do not commit, and
do not modify the backend.

At the end, provide a concise implementation summary and the complete Phase 3
report path. Clearly identify anything that remains unverified against a real
Laravel instance.

**Stop after Phase 3. Do not begin Phase 4 or any accounting module until the
Phase 3 report has been reviewed and approved.**
