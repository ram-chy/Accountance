# Accountance Accounting Web App

# Frontend Phase 2 — Authentication & Company Context

## Objective

Implement Frontend Phase 2 on top of the completed Phase 1 foundation.

The goal is to provide a secure, functional authentication experience, protected
application routes, current-user information, company selection, and
permission-aware navigation foundations.

The implementation must integrate with the existing Laravel API and preserve all
accounting and authorization rules.

**Implement Phase 2 only. Do not implement the graphical dashboard or accounting
modules.**

---

## 1. Mandatory Pre-Implementation Review

Before changing any files:

1. Inspect the repository root, current branch, and `git status --short`.
2. Preserve every existing user change and all uncommitted Phase 0/1/backend
   work.
3. Read the relevant Phase 0 and Phase 1 documentation, including:
   - `docs/FRONTEND_PHASE_0_AUDIT.md`
   - `docs/FRONTEND_BLUEPRINT.md`
   - `docs/FRONTEND_PHASE_0_REPORT.md`
   - `docs/FRONTEND_PHASE_1_REPORT.md`
4. Inspect the actual frontend implementation under `accounting-app/frontend`.
5. Review the API client, theme provider, environment configuration, layouts,
   shared UI components, error handling, and TypeScript configuration.
6. Inspect the Laravel authentication implementation, routes, middleware, JWT
   configuration, token expiration, refresh behavior, logout/revocation
   behavior, and current-user endpoint.
7. Inspect the actual company listing, membership, default-company,
   company-context, and permissions API contracts.
8. Inspect backend authorization and cross-company isolation tests where
   relevant.
9. Verify every endpoint and response shape against actual code. Do not rely
   solely on documentation.
10. Record any conflicting or missing contracts before deciding how to implement
    the frontend.

Do not create a second frontend application, replace the existing API client, or
rewrite working Phase 1 utilities.

### Repository safety

- Do not commit changes.
- Do not reset, stash, discard, or overwrite user changes.
- Do not run seeders, database resets, or destructive commands.
- Do not modify migrations or database schema.
- Do not change unrelated accounting logic.
- Do not reformat unrelated backend files.

---

## 2. Authentication Architecture Decision

The Phase 1 report leaves token storage and authentication transport unresolved.

Resolve this decision from verified backend behavior before implementing
authentication.

Evaluate these approaches:

### Preferred approach when compatible: BFF with HttpOnly cookies

A Next.js server-side layer manages the browser-facing session and communicates
with Laravel.

If this approach is selected:

- Keep Laravel as the authentication and authorization authority.
- Keep access and refresh tokens server-side wherever the verified Laravel
  contract permits.
- Use appropriately configured `HttpOnly`, `Secure` in production, and suitable
  `SameSite` cookies.
- Implement CSRF protection where required by the cookie/session design.
- Validate session state on protected server-rendered routes.
- Do not expose private credentials or tokens through `NEXT_PUBLIC_*` variables.
- Ensure the BFF does not become a second authorization engine.
- Handle token expiry and refresh without unbounded retry loops.
- Never blindly retry financial write operations.
- Do not invent Laravel refresh or revoke endpoints.

### Alternative: direct Laravel JWT authentication

Use direct browser-to-Laravel JWT authentication only if it is the best fit for
the existing API contract and project constraints.

If selected:

- Follow the verified login, token, current-user, refresh, and logout contract.
- Avoid persistent localStorage storage of sensitive bearer tokens by default.
- Document the chosen storage and threat model.
- Avoid exposing tokens to unrelated client code.
- Handle token expiration, revocation, and 401 responses consistently.
- Keep the API client extensible and preserve its existing call sites.

### Decision requirements

Document the selected approach and explain why it matches the actual backend and
deployment constraints.

Do not change the Laravel authentication system simply to make the frontend
easier.

If the existing contract makes a secure, reliable implementation impossible
without a significant backend change, document the blocker and ask for approval
instead of silently redesigning authentication.

---

## 3. Authentication API Contract

Verify and document the real endpoints and payloads for:

- Login.
- Logout.
- Current authenticated user.
- Token refresh, if supported.
- Password recovery, if implemented.
- Password reset, if implemented.
- Company listing.
- Company selection/context.
- User roles and permissions.

Record the exact request fields, response envelopes, HTTP statuses, validation
errors, and authentication headers.

Do not assume endpoint names such as `/api/auth/login`, `/api/auth/me`, or
`/api/companies` exist until verified.

Do not invent missing endpoints or silently return fabricated user/company
information.

If a necessary endpoint is missing, report it and determine whether an existing
supported endpoint provides the required capability. Ask for approval before
changing the backend contract.

---

## 4. Login Experience

Implement a professional, responsive login page using the Phase 1 design system.

Requirements:

- Follow the Accountance green-and-slate visual identity.
- Support Light and Dark Mode.
- Use accessible labels and keyboard navigation.
- Validate required fields on the client without replacing backend validation.
- Display server-side validation errors in a useful format.
- Display general authentication errors without leaking internal details.
- Prevent accidental duplicate submissions.
- Show a loading state while the request is processing.
- Preserve safe, non-sensitive input values where appropriate.
- Never log passwords, tokens, or sensitive authentication responses.
- Avoid revealing whether an account exists unless the backend contract
  explicitly requires that behavior.
- Redirect an authenticated user away from the login page to the intended safe
  destination or the application landing page.
- Prevent open redirects by validating any return URL against permitted internal
  paths.
- Do not hardcode user credentials or bypass authentication in development.

Use the existing shared Button, Card, Field, error-state, and notification
components wherever appropriate.

### Notifications

Use Sonner for toast notifications if it is not already installed and no
existing notification system is present.

- Add the Toaster once in the appropriate root/application layout.
- Match notifications to Light and Dark Mode.
- Use notifications for concise feedback.
- Keep field validation errors visible next to the relevant fields.
- Do not duplicate the same error in a toast and inline error unless there is a
  clear usability reason.
- Do not show success until the server confirms the operation.

Do not install a second toast library.

---

## 5. Authentication State and Session Lifecycle

Implement a maintainable authentication state model appropriate to the chosen
architecture.

Requirements:

- Provide a reliable way for the application to determine whether a user is
  authenticated.
- Load the current user's profile and permission information using the verified
  API contract.
- Represent loading, authenticated, unauthenticated, and error states
  explicitly.
- Handle expired or revoked sessions.
- Handle a 401 response consistently without creating redirect loops.
- Clear stale client-side authentication and company state when the session
  ends.
- Prevent authenticated application content from appearing briefly to an
  unauthenticated user.
- Avoid duplicating authentication state across unrelated providers.
- Avoid trusting user-controlled client state as proof of authorization.

If a BFF is used, do not expose bearer tokens to client components. The client
should interact with the BFF using the established same-origin session
mechanism.

If direct JWT authentication is used, implement the verified token transport
without introducing insecure persistent storage by default.

Do not add a complex state-management library unless the current architecture
demonstrates a concrete need.

---

## 6. Protected Routes and Layouts

Implement the protected route structure according to the Phase 0 blueprint.

Requirements:

- Separate public authentication routes from protected application routes.
- Protect the authenticated application layout.
- Preserve the original intended destination after login where safe.
- Redirect unauthenticated users to login.
- Redirect authenticated users away from login where appropriate.
- Provide a suitable loading state during session resolution.
- Provide a recoverable error state when the authentication API is unavailable.
- Ensure server-side protection is used where supported by the selected
  architecture.
- Do not rely exclusively on client-side route guards for security.

Do not build the full sidebar or dashboard yet. Implement only the minimum
shared authenticated layout needed for company context and the next phase.

The foundation page from Phase 1 may be retained or moved into the protected
application area as appropriate, provided the existing design and utilities
remain intact.

---

## 7. Current User and Permissions

Integrate the current-user and permission data using the verified API response.

Requirements:

- Define accurate TypeScript types for the actual response.
- Do not invent role or permission names.
- Keep the authenticated user's display information available to future
  application components.
- Provide a consistent way to check permissions for UI presentation.
- Ensure unknown or absent permissions fail closed for permission-gated UI.
- Do not treat frontend permission checks as a security boundary.
- Preserve the Laravel backend as the authoritative authorization layer.
- Do not hardcode Admin, Accountant, Manager, or Staff permissions if the
  backend contract differs from those documented roles.

Create a small, reusable permission-checking utility or hook only if justified
by the existing response format.

---

## 8. Company Context

Implement company selection using the existing company and membership API.

Requirements:

- Fetch only companies available to the authenticated user.
- Use the actual company response and membership contract.
- Respect the backend's default-company behavior.
- Support users who belong to multiple companies.
- Handle users who belong to only one company without unnecessary friction.
- Handle users with no available companies gracefully.
- Do not allow the user to select a company to which they do not belong.
- Ensure the selected company is validated by the backend on every relevant
  request.
- Send `X-Company-Id` through the existing API client integration point when the
  verified contract requires it.
- Do not duplicate company-context logic throughout individual API calls.
- Do not treat a stored company ID as authorization evidence.
- Clear or revalidate company state when the authenticated user changes or the
  session expires.
- Handle a company-context 403/404 response safely without exposing
  cross-company data.

### Persistence

Choose the company-selection persistence strategy based on the selected
authentication architecture.

Persist only the minimum necessary identifier or preference. Revalidate it
against the authenticated user's available companies on initialization.

Do not store sensitive company data or authentication tokens unnecessarily.

### Company context and server requests

If using a BFF, ensure server-side requests receive validated company context
without trusting arbitrary client-supplied identifiers.

If using direct JWT requests, centralize the company header through the existing
API client's header-provider mechanism.

Never bypass Laravel's existing `CompanyContext` or company-membership
authorization.

---

## 9. Session Expiry and Error Handling

Extend the existing API client only as necessary.

Requirements:

- Preserve `ApiError`, current response unwrapping, request IDs, and validation
  error support.
- Integrate 401 handling with the authentication lifecycle.
- Distinguish unauthenticated responses from forbidden responses.
- Avoid treating every 403 as a logout.
- Handle company-context errors separately where appropriate.
- Avoid redirect loops and duplicate concurrent logout requests.
- Handle network failures and backend downtime gracefully.
- Do not automatically retry POST, PUT, PATCH, or DELETE requests.
- If token refresh is supported, coordinate concurrent refresh requests and
  handle refresh failure safely.
- Never report an accounting operation as successful based only on optimistic
  client state.

Do not introduce a global interceptor that breaks server-side rendering or
assumes `window` is always available.

---

## 10. Environment and Deployment Configuration

Document all required frontend environment variables.

Requirements:

- Keep public configuration limited to values safe to expose to browsers.
- Never prefix secrets or private tokens with `NEXT_PUBLIC_`.
- Document local setup and expected Laravel API origin.
- For a BFF, document server-side API configuration separately from
  browser-facing configuration.
- Consider CORS, cookie domain, HTTPS, `SameSite`, and CSRF implications of the
  intended deployment.
- Do not weaken production cookie or transport security to make local
  development easier.
- Avoid hardcoding development URLs throughout the codebase.
- Update `.env.example` with placeholders only.

If deployment architecture is not yet finalized, document assumptions and avoid
locking in a cookie domain or production origin prematurely.

---

## 11. Automated Testing

Inspect the existing test setup first.

The Phase 1 report indicates that no frontend test framework was added.

Add a reasonable frontend test setup for Phase 2, preferably Vitest and React
Testing Library if compatible with the installed Next.js/React versions and
project conventions.

Keep dependencies minimal and compatible. Do not replace the existing lint or
build toolchain.

Cover the most important cases:

### Authentication

- Valid login response.
- Invalid credentials.
- Backend validation errors.
- Network failure.
- Duplicate submission prevention.
- Session resolution.
- Unauthenticated protected-route access.
- Authenticated login-page redirect.
- Session expiry and 401 handling.
- Safe return-path handling.

### Company context

- User with one company.
- User with multiple companies.
- Default company selection.
- No available companies.
- Invalid/stale stored company selection.
- Company header behavior according to the chosen architecture.
- User/session change clears stale company context.
- Forbidden or invalid company context.

### Permissions

- Allowed UI action.
- Missing permission.
- Unknown permission.
- UI permission checks do not replace backend authorization.

Mock API interactions in frontend tests. Do not require a live database or run
backend seeders.

Do not claim test coverage that was not implemented or executed.

---

## 12. Documentation

Update `docs/FRONTEND_BLUEPRINT.md` to reflect the actual implementation.

Create `docs/FRONTEND_PHASE_2_REPORT.md` documenting:

1. Authentication architecture selected and why.
2. Verified Laravel endpoints and response contracts used.
3. Login and session lifecycle.
4. Route protection strategy.
5. Current-user and permission integration.
6. Company-context behavior.
7. API client changes.
8. Notification library decision.
9. Dependencies added and justification.
10. Tests added and results.
11. Lint, TypeScript, and production build results.
12. Environment configuration and local run instructions.
13. Known limitations and unresolved backend requirements.
14. Files created or changed.
15. Recommended next phase.

Clearly distinguish verified backend behavior from assumptions and remaining
work.

---

## 13. Scope Restrictions

Do not:

- Build the graphical financial dashboard.
- Build accounting module screens.
- Implement company settings management.
- Implement journal posting UI.
- Change the accounting engine.
- Change database schema or migrations.
- Invent authentication endpoints.
- Replace Laravel JWT with another backend authentication system.
- Add an unnecessary global state library.
- Add multiple notification libraries.
- Run seeders, migrations, or database resets.
- Commit, reset, stash, or discard changes.
- Modify unrelated backend files.
- Proceed to Frontend Phase 3.

If a backend change is necessary, stop and document the exact requirement for
approval.

---

## 14. Completion Criteria

Phase 2 is complete when:

- The login flow works with the verified backend contract.
- Authentication state is handled consistently.
- Protected routes are enforced using the chosen architecture.
- Session expiry and authentication errors are handled.
- Current-user information is available to the application.
- Permission-aware UI checks use verified permission data.
- Company selection and company context work correctly.
- Company context is centralized and validated by the backend.
- Sonner or the existing notification system provides consistent feedback.
- Important authentication and company-context behaviors have automated tests.
- Lint, TypeScript checks, relevant tests, and production build have been run
  and reported.
- Phase 1 theme, API utilities, and UI components remain functional.
- Backend accounting logic and database schema remain unchanged.
- `docs/FRONTEND_PHASE_2_REPORT.md` accurately describes the result.

At completion, report the actual commands and results. List any unverified
browser behavior or unresolved API requirements explicitly.

**Stop after Phase 2. Wait for review and approval before starting Frontend
Phase 3 — Application Shell & Graphical Dashboard.**
