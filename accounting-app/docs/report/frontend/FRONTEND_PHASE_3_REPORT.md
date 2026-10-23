# Frontend Phase 3 — Authenticated API Proxy & Application Shell Implementation Report

- **Date:** 2026-10-11
- **Scope:** Frontend Phase 3 only (secure company-aware API communication layer +
  authenticated application shell). No accounting business screens.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked, per the brief. The backend
  was not modified (`git status --short -- accounting-app/backend` is empty).

## 1. Pre-implementation review

| Check | Result |
| --- | --- |
| Repository root / branch | `D:\Development\Accountance`, branch `main` (HEAD `6f8bc9b`) |
| `git status --short` before work | Phase 2 output uncommitted; malformed-path artifacts and `prompt/frontend/` untracked; nothing disturbed |
| Prior phases read | `FRONTEND_BLUEPRINT.md`, `FRONTEND_PHASE_2_REPORT.md`, `FRONTEND_API_HANDOFF.md` |
| Backend contract | Re-verified against `routes/api.php`, `ResolveCompanyContext.php`, `CompanyContext.php`, `bootstrap/app.php`, `AssignRequestId.php`, `ApiResponse.php` (see §3) |

## 2. What Phase 3 adds

1. A single authenticated, company-aware **backend proxy** that the browser uses
   for all business data — the endpoint Phase 2 deliberately deferred.
2. A **browser client** for that proxy that reuses the shared `apiFetch` and the
   central 401 handling.
3. A **professional application shell**: persistent sidebar navigation, an
   off-canvas mobile drawer, and the existing company switcher / theme toggle /
   account menu, with planned modules shown as clearly-marked, non-clickable
   placeholders.

## 3. Verified backend facts the proxy relies on

| Fact | Source |
| --- | --- |
| Route families / prefixes | `routes/api.php` |
| `X-Company-Id` is a hint validated against membership + active state | `CompanyContext.php`, `ResolveCompanyContext.php` |
| Company-scoped group is guarded by `company.context`; `users`/`companies` are authenticated but not company-scoped | `routes/api.php`, `bootstrap/app.php` |
| `403` = exists but not a member, or no active company; `404` = not found | `ResolveCompanyContext.php` (the BFF mirrors the `403` for "no company") |
| Every response carries `X-Request-Id` | `AssignRequestId.php` |
| Envelope `{success,message,data?}` / `{success:false,message,errors?}` | `ApiResponse.php` |

Company-scoped families allow-listed by the proxy: `company`, `accounts`,
`accounting`, `journals`, `customers`, `suppliers`, `sales-invoices`,
`purchase-bills`, `credit-debit-notes`, `customer-receipts`, `supplier-payments`,
`cash-bank-accounts`, `cash-bank-transactions`, `bank-reconciliations`.
Authenticated-but-global families: `users`, `companies`.

## 4. Proxy policy (`lib/api/proxy-policy.ts`)

Pure, framework-free, and unit-tested. It is **not** a transparent pipe:

- **Method allow-list:** `GET/POST/PUT/PATCH/DELETE`; anything else → `405`.
- **Path allow-list:** only the families above; everything else (including
  `auth/*`, `health`, `password/*`) → `404`.
- **Explicitly blocked:** `companies/{id}/switch` (the same effect is exposed
  safely via the BFF's own `/api/session/company` route).
- **Segment validation:** rejects `.`/`..`, any segment outside
  `[A-Za-z0-9._~-]` (which defeats percent-encoding / double-encoding,
  backslashes, slashes, whitespace, control chars), empty or overlong segments,
  and over-count paths. Every rejection returns `404` so probing reveals nothing.
- **Header hygiene:** only `Accept`, `Content-Type` and a validated `X-Request-Id`
  are forwarded upstream; `Authorization` and `X-Company-Id` are written last and
  can never be supplied or overridden by the browser. Response headers are
  filtered to `Content-Type`, `X-Request-Id` and `Cache-Control: no-store`;
  `Set-Cookie` and `Location` are stripped.
- **Body limit:** documented 10 MB JSON cap (`413`); declared `Content-Length`
  checked before reading.
- **Timeout:** 30 s upstream; abort → `504`.

## 5. Route handler (`app/api/backend/[...path]/route.ts`)

Thin shell over the policy:

1. Reject a non-allow-listed method (`405`, with `Allow`).
2. Require a valid encrypted session cookie, else `401`.
3. Validate the path; reject unknown/traversal with `404`.
4. For a company-scoped family with no active company in the session →
   `403` (never forward and let Laravel fall back to the user's default; this
   matches Laravel's own company-context refusal).
5. Build the URL + headers server-side; `Authorization` from the cookie,
   `X-Company-Id` from the server-side active company.
6. Forward server-to-server, then pass the status and body through unchanged.
   An upstream `401` clears the session; all other statuses (notably `422`
   validation and `403`/`404`) are preserved so the browser's `ApiError` is
   identical to the session client's.

Registered in the production build as `ƒ /api/backend/[...path]` (dynamic). It is
excluded from the `proxy.ts` matcher (which skips `api`), so the handler itself
is the authorization gate.

## 6. Browser client (`lib/api/backend.ts`)

- `backendGet/Post/Put/Patch/Delete` → `apiFetch` with `baseUrl: ""` against
  `/api/backend/*`; leading slashes are normalised.
- Reuses the Phase 1 envelope/`ApiError`/request-id handling.
- Reports `401` to a **shared** unauthorized registry
  (`lib/api/unauthorized.ts`), now also used by the session client
  (`lib/auth/client.ts` re-exports `onUnauthorized` so existing imports/tests are
  unchanged). The proxy handler and the session client therefore agree on one
  session-expiry reaction.

## 7. Application shell

- `lib/navigation/nav.ts` — `NAV_SECTIONS` with real `items` (permission-gated)
  and `upcoming` module labels. Placeholders are never links.
- `components/app/app-sidebar.tsx` — renders sections; real items are
  permission-filtered links with active state; `upcoming` entries render as
  `aria-disabled` rows with a "Soon" badge.
- `components/app/app-shell.tsx` — fixed desktop sidebar + off-canvas mobile
  drawer (Escape to close, backdrop click, closes on navigation), framing the
  header and main content.
- `components/app/app-header.tsx` — mobile navigation trigger
  (`aria-expanded`/`aria-controls`), mobile brand, company switcher, theme
  toggle, account menu. The old inline top nav was removed (navigation now lives
  in the sidebar).

## 8. Verification performed

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npx eslint .` | Pass, 0 problems |
| Type-check | `npx tsc --noEmit` | Pass, 0 errors |
| Unit tests | `npm test` (Vitest) | Pass, **62 tests / 7 files** (was 36/5) |
| Build | `npm run build` | Pass; `/api/backend/[...path]` registered dynamic; `/`, `/login`, `/api/session/*` unchanged |

New tests: `src/lib/api/proxy-policy.test.ts` (21) covering allow-list,
blocked families, traversal/encoding rejection, URL building, header
forwarding/override protection and response-header filtering; and
`src/lib/api/backend.test.ts` (5) covering proxy paths, body, and 401 vs 403
notification.

**Not verified:** a live Laravel instance (no PHP/DB in this environment); the
policy and handler logic are verified by unit tests, and the upstream call shape
by static reading of the verified contract. The proxy has no UI of its own and is
exercised by feature phases.

## 9. Deviations / notes

- **No accounting screens** were built, per the brief.
- A route handler test that instantiates `NextResponse`/`cookies()` was not added;
  the security-critical logic is isolated in the pure policy module and tested
  there, which keeps the handler a thin adapter.
- Upstream redirects are fetched with `redirect: "manual"` and no `Location` is
  forwarded, so the proxy can never be turned into a client-side open redirect.

## 10. Files added/changed

- **Added:** `src/lib/api/proxy-policy.ts`, `src/lib/api/backend.ts`,
  `src/lib/api/unauthorized.ts`, `src/lib/api/proxy-policy.test.ts`,
  `src/lib/api/backend.test.ts`, `src/app/api/backend/[...path]/route.ts`,
  `src/components/app/app-sidebar.tsx`, and this report.
- **Changed:** `src/components/app/app-shell.tsx`, `src/components/app/app-header.tsx`,
  `src/lib/navigation/nav.ts`, `src/lib/auth/client.ts`, `docs/FRONTEND_BLUEPRINT.md`.
- **Untouched:** `accounting-app/backend/**`, `FRONTEND_API_HANDOFF.md` (reads only),
  Phase 0/1/2 reports, `prompt/frontend/`.

## 11. Recommended next steps (Phase 4+)

1. First data module (accounts / chart of accounts) using `lib/api/backend.ts`.
2. Introduce TanStack Query + react-hook-form/zod with that screen; invalidate
   client caches on company switch.
3. Add a pagination adapter for the flat `data` array contract (blueprint §3a).
4. Consider server-side permission gating for routes as modules arrive.
