# Frontend Phase 2 — Authentication & Company Context Implementation Report

- **Date:** 2026-10-11
- **Scope:** Frontend Phase 2 only (authentication + company context). No
  dashboard or accounting modules.
- **Status:** Complete. Lint, type-check, unit tests, and production build pass;
  the full BFF session flow was verified end-to-end against a mock backend.
- **Not committed:** all work is uncommitted/untracked, per the brief. The backend
  was not modified (`git status --short -- accounting-app/backend` is empty).

## 1. Pre-implementation review

| Check | Result |
| --- | --- |
| Repository root / branch | `D:\Development\Accountance`, branch `main` (HEAD `6f8bc9b`, Phase 1) |
| `git status --short` before work | Only untracked Phase 0/1 docs, `prompt/frontend/` and malformed-path artifacts; no user changes disturbed |
| Prior phases read | `FRONTEND_BLUEPRINT.md`, `FRONTEND_PHASE_0_AUDIT.md`, `FRONTEND_PHASE_0_REPORT.md`, `FRONTEND_PHASE_1_REPORT.md` |
| Backend contract | Verified directly against controllers, resources, routes, middleware, and config (see §3) |

## 2. Architecture decision (was open in Phase 1)

**Decided: same-origin Next.js BFF with an encrypted `HttpOnly` session cookie**
(blueprint §6.1, the recommended pattern). Rationale:

- The backend is bearer-JWT with **no refresh endpoint and no cookie support**.
- Storing the JWT in `localStorage` is XSS-exposed and was explicitly disallowed.
- A same-origin BFF avoids CORS entirely (no `config/cors.php` exists) and keeps
  the token server-side where client JavaScript can never read it.

The token is sealed with **Web Crypto AES-256-GCM** (`SESSION_COOKIE_SECRET`,
SHA-256-derived key, fresh 96-bit IV per seal, `base64url(iv).base64url(ct)`).
The cookie is `HttpOnly; Secure (production); SameSite=Lax; Path=/`, and mutating
routes additionally require a same-origin request.

## 3. Verified backend contract (no backend change needed)

| Endpoint | Method | Notes |
| --- | --- | --- |
| `/api/auth/login` | POST | `{email,password}` → `{token, token_type, expires_in, user}`; `401` bad creds; `422` validation |
| `/api/auth/logout` | POST | Revokes the presented token |
| `/api/auth/me` | GET | Returns `UserResource` with `roles[].permissions[].name` |
| `/api/companies` | GET | Flat `data` array of `CompanyResource` (needs `companies.view`) |
| `/api/companies/{id}/switch` | POST | Validates membership; sets the user's default company |

Active company is conveyed via the `X-Company-Id` header (a hint validated against
membership + active state). No refresh endpoint exists; JWT TTL is 60 minutes.
`config/authorization.php` gives the Admin role the literal `['*']`, which
`RolePermissionSynchroniser` expands to the full explicit permission list — so
`/auth/me` never emits `*`; the UI still treats a literal `*` defensively.

## 4. What was built

App location: `accounting-app/frontend`.

```
src/
  proxy.ts                         Next 16 proxy — cookie-presence/expiry gate + safe `next`
  app/
    layout.tsx                     Root: ThemeProvider > AuthProvider > children + Toaster
    (public)/login/
      page.tsx                     Server guard (redirect if authenticated) + safe next
      login-form.tsx               Client credential form
    (app)/
      layout.tsx                   Server guard (no session → /login) + AppShell
      page.tsx                     Minimal authenticated home (session/company proof)
    api/session/
      login/route.ts               POST credentials → seal JWT → return snapshot
      logout/route.ts              Best-effort revoke → always clear cookie
      me/route.ts                  Re-read user/companies, re-point stale company
      company/route.ts             Validate + persist active company
  lib/
    api/server-config.ts           Server-only Laravel origin (API_BASE_URL)
    api/response.ts                BFF success/failure envelope
    api/client.ts                  +`baseUrl` override on apiFetch (Phase 1 extended)
    auth/types.ts                  SessionUser/SessionCompany/SessionSnapshot
    auth/session-crypto.ts         AES-256-GCM seal/open (Web Crypto)
    auth/session-cookie.ts         Cookie name (no next/headers — proxy-safe)
    auth/session.ts                cookies() read/write/clear + options
    auth/laravel.ts                Server-to-server Laravel client
    auth/snapshot.ts               buildSessionSnapshot + pure pickActiveCompany
    auth/http.ts                   same-origin check + JSON body reader
    auth/client.ts                 Browser BFF client + 401 handler registry
    auth/paths.ts                  safe-next sanitizer (anti open-redirect)
    permissions/permissions.ts     collectPermissions + fail-closed checks
    navigation/nav.ts              Permission-aware nav config (foundation)
  components/
    auth/auth-provider.tsx         Session context (never holds the JWT)
    auth/use-permissions.ts        can/canAny/canAll hook
    auth/permission-gate.tsx       Conditional rendering wrapper
    app/app-shell.tsx              Client shell: loading/error/ready states
    app/app-header.tsx             Permission-filtered nav + switcher + menus
    app/company-switcher.tsx       Active-company selector (toasts errors)
    app/user-menu.tsx              Account menu with sign-out
    ui/toaster.tsx                 sonner host, themed via our ThemeProvider
```

Also: `vitest.config.ts`, `vitest.setup.ts`, `package.json` `test` script,
`.env.example` (`API_BASE_URL`, `SESSION_COOKIE_SECRET`, `SESSION_COOKIE_NAME`).
`src/app/page.tsx` (the Phase 1 preview) was replaced by `(app)/page.tsx`.

## 5. Key implementation decisions

1. **No token in the browser.** The JWT lives only inside the encrypted cookie;
   the client snapshot (`/api/session/me`) never includes it. `NEXT_PUBLIC_*` is
   not used for anything sensitive.
2. **`/auth/me`, not the login payload, is the source of truth.** The login
   response loads roles without permissions, so the BFF builds the snapshot via
   `/auth/me`. If those reads fail transiently, the session is still established
   and permissions fail closed until `/me` succeeds.
3. **A generic catch-all forwarding proxy is deferred.** Phase 2 only needs the
   session surface; `X-Company-Id` forwarding lands with the first data module.
   `apiFetch` gained a `baseUrl` override so the same client reaches both the
   same-origin BFF (`baseUrl: ""`) and Laravel (server-side).
4. **Single shared `AuthProvider` in the root layout.** The login page and the
   authenticated app share one snapshot; no duplicate session probes. 401s from
   authenticated calls clear state and redirect to `/login?next=…`.
5. **Safe redirects.** `sanitizeNextPath` rejects absolute/protocol-relative
   URLs, backslashes, and the login route, preventing open-redirect abuse.
6. **Reads fail closed, writes fail loud.** A missing secret makes reads behave
   as "signed out" (redirect to login) but makes login/company writes surface a
   clear server error, so a misconfiguration is caught at the first write.
7. **Next 16 specifics.** `proxy.ts` (not `middleware.ts`); `cookies()` is async;
   `export const dynamic = "force-dynamic"` is forbidden under `cacheComponents`,
   so the auth layouts/pages use `export const instant = false` instead.

## 6. Verification performed

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npx eslint .` | Pass, 0 problems |
| Type-check | `npx tsc --noEmit` | Pass, 0 errors (after `next build` generates route types) |
| Unit tests | `npm test` (Vitest) | Pass, 36 tests / 5 files |
| Build | `npm run build` | Pass; `/`, `/login`, `/api/session/*` dynamic; Proxy registered |
| SSR smoke | `next start` + `curl` | `/` → `307 → /login`; `/login` → `200`; `/api/session/me` (no cookie) → `401`; cross-origin login → `403`; `next` preserved on redirect |
| End-to-end | `next start` (pointed at a mock Laravel) + cookie jar | Login sets an encrypted `HttpOnly; Secure; SameSite=lax` cookie; `/me` returns the snapshot; company switch persists (`activeCompanyId` 1 → 2); mock observed `Authorization: Bearer …` on every upstream call and none on the browser login |

Tests cover: permission collection/`*` wildcard/fail-closed, cookie crypto
round-trip + tamper/wrong-key rejection, safe-next sanitization, `pickActiveCompany`
selection rules, and the BFF browser client (URLs, methods, bodies, 401 handling).

**Not verified:** a real Laravel instance (no PHP/DB in this environment); the
provider's live hydration in a real browser. The API contract was verified
statically against the backend source and behaviourally against a faithful mock.

## 7. Deviations from the blueprint

- **`app/api/backend/[...path]` catch-all proxy not implemented yet** (see §5.3);
  the session endpoints are implemented instead.
- **Forgot/reset/verify screens not implemented** — the backend exposes only
  login/logout/me in scope; no reset endpoints were invented.
- **No TanStack Query / react-hook-form / zod** — still deferred to the screens
  that need them.

## 8. Recommended next steps (Phase 3+)

1. Add the authenticated data proxy (`X-Company-Id` forwarding) and invalidate
   caches on company switch.
2. Broaden permission-aware navigation into the full accounting sidebar.
3. Introduce TanStack Query + react-hook-form/zod with the first data screens.
4. Consider server-side permission gating for routes as modules arrive.

## 9. Files added/changed (all uncommitted)

- New: everything under `src/lib/auth/`, `src/lib/permissions/`,
  `src/lib/navigation/`, `src/components/auth/`, `src/components/app/`,
  `src/app/(app)/`, `src/app/(public)/`, `src/app/api/`, `src/proxy.ts`,
  `src/lib/api/{response,server-config}.ts`, `src/components/ui/toaster.tsx`,
  `vitest.config.ts`, `vitest.setup.ts`, and this report.
- Changed: `src/app/layout.tsx`, `src/lib/api/client.ts`, `package.json`,
  `.env.example`, `docs/FRONTEND_BLUEPRINT.md`.
- Removed: `src/app/page.tsx` (moved to `src/app/(app)/page.tsx`).
- Untouched: `accounting-app/backend/**`, `docs/FRONTEND_API_HANDOFF.md`, Phase 0/1
  docs, `prompt/frontend/`.
