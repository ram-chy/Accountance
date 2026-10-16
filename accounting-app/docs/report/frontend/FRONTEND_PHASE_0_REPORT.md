# Frontend Phase 0 — Report

**Phase:** Frontend Phase 0 (Initial Stage — discovery, verification, planning)
**Date:** 2026-10-11
**Backend head:** `f4723ce Phase 18: Backend Completion Audit & Frontend Handoff`
**Status:** **COMPLETE — awaiting review/approval before Frontend Phase 1.**

---

## 1. Summary

Phase 0 inspected the repository, verified the backend API contract against the
actual code (not just the handoff), and produced a frontend architecture, design
system and API-driven dashboard plan.

**Key facts:**

- The repository contains **only the Laravel backend** — no frontend exists.
  Phase 1 will scaffold a single Next.js application.
- The existing `docs/FRONTEND_API_HANDOFF.md` is **accurate in substance** and
  was verified against `routes/api.php`, controllers, requests, resources,
  middleware and config. A few **precision corrections** were found (§6).
- Two confirmed backend properties dominate frontend design:
  1. **Pagination metadata is absent** (`data` is a flat page array, no
     `links`/`meta`) — Phase 18 finding P2-1.
  2. **JWT with no refresh endpoint, 60-minute TTL**, and `auth.fresh` can
     invalidate a token on any request → design for a sudden `401`.
- No frontend code, no backend behaviour, no schema, no migrations and no
  existing changes were modified. `php`/`composer` are not installed in this
  environment, so the PHP test suite could not be run (static verification only).

---

## 1a. Verified facts / recommendations / unresolved — summary

- **Verified facts:** route inventory (211 routes), envelope and error formats,
  auth flow and token behaviour, company-context rules, role/permission matrix,
  decimal/date/currency representations, pagination behaviour, report response
  shapes, absence of a dashboard/audit-log/trend/cash-flow endpoint.
- **Recommendations:** BFF + httpOnly cookie token handling; `accounting-app/
  frontend` location; normalised pagination adapter; TanStack Query + RHF/zod
  (pending approval); Recharts with CSS-variable theming; Tailwind v4 `@theme`
  tokens; Vitest/RTL + Playwright; defer trend charts until a trends API exists.
- **Unresolved:** token-storage decision, app location, data-layer/form/UI/theme
  library choices, and the `per_page` cap + pagination-metadata backend follow-ups.

---

## 2. Files created or updated

**Created** (in `accounting-app/docs/`):

| File | Contents |
|---|---|
| `FRONTEND_PHASE_0_AUDIT.md` | Repository/backend findings, verified API contract, auth & company-context details, pagination/error findings, report shapes, risks, discrepancies. |
| `FRONTEND_BLUEPRINT.md` | Frontend architecture, route/folder structure, API client conventions, design tokens/theme, component strategy, auth/company integration, security, dashboard plan, testing, roadmap, open decisions. |
| `FRONTEND_PHASE_0_REPORT.md` | This report. |

**Updated:** none. **Existing docs preserved:** `docs/FRONTEND_API_HANDOFF.md`
and `docs/report/PHASE_18_REPORT.md` were read, not modified. No duplicate
handoff document was created.

---

## 3. Commands / checks performed and results

| Check | Result |
|---|---|
| `git status` | Clean tree at start; only `prompt/frontend/` untracked (this brief) — preserved. |
| `git log` | HEAD `f4723ce` (Phase 18). |
| Route inventory (`routes/api.php`) | 211 `Route::` declarations; verified against the handoff. |
| Response envelope (`app/Support/ApiResponse.php`, `bootstrap/app.php`) | Verified success/error shapes and status-code renderers. |
| Auth (`AuthenticatedSessionController`, `CurrentUserController`, `EnsureTokenIsFresh`, `config/jwt.php`, `.env.example`) | Verified token/expiry/freshness behaviour and that permissions only appear on `/auth/me`. |
| Company context (`CompanyContext`, `ResolveCompanyContext`, `CompanyContextController`, `CompanyPolicy`) | Verified header resolution, 403/404 semantics, switch header echo, and `companies.create` being Admin-only. |
| Authorization (`config/authorization.php`, `PermissionName`) | Verified full permission set and role matrix. |
| Pagination (`AccountController`, `JournalController`, filter requests, `PHASE_18_REPORT.md` P2-1) | Verified flat `data` array, no metadata; mixed `per_page` validation. |
| Decimals/dates (`AccountResource`, `JournalResource`, `SalesInvoiceResource`, report services, `config/accounting.php`, `config/app.php`) | Verified string money, date-only business dates, ISO-8601 UTC timestamps, UTC app timezone. |
| Existing frontend (`package.json`, `vite.config.js`, `resources/`) | Only Laravel's default Vite+Tailwind v4 asset pipeline; no product frontend. |
| Route model binding (`AppServiceProvider`) | Verified every company-owned model is bound to the active company → cross-company ids 404. |
| CORS config | No `config/cors.php`; framework defaults apply. |
| `php -v`, `composer --version` | **Not installed** → `artisan`/PHPUnit could not be run. |
| `node -v`, `npm -v` | Node `v24.1.0`, npm `11.4.2` (frontend tooling available). |

No migrations, seeders, destructive commands, commits, resets, stashes or
discards were run.

---

## 4. Confirmed API limitations

1. **Pagination metadata absent (P2-1).** List responses are a flat array; no
   `links`/`meta`/`total`. Last page detectable only by a short page.
2. **No refresh token.** 60-minute TTL; on expiry a `401`; re-authenticate.
3. **No dashboard / trend / cash-flow endpoint.** Dashboard trends need multiple
   calls or a future API; cash-flow statements are deferred by design.
4. **No public audit-log API.**
5. **`per_page` is not capped** on many list endpoints (only budget/dimension/
   fixed-asset/reconciliation filter requests cap at 100).
6. **Dimension filtering** exists only on P&L and Budget Variance.
7. **`GET /api/companies` is not paginated** (returns all memberships).
8. **No CORS config published** (framework defaults); the recommended BFF avoids
   relying on it.

---

## 5. Decisions that need user approval

From `FRONTEND_BLUEPRINT.md` §15:

1. **Token storage:** Next.js BFF + httpOnly cookie (**recommended**) vs
   in-memory token.
2. **App location:** `accounting-app/frontend` (**recommended**).
3. **Data layer:** TanStack Query (**recommended**) vs hand-rolled hooks.
4. **Forms:** react-hook-form + zod (**recommended**) vs native.
5. **Headless UI library:** none initially (**recommended**) vs Radix/Headless UI.
6. **Theme:** next-themes (**recommended**) vs custom provider.
7. **Trend charts:** defer until a trends API exists (**recommended**) vs bounded
   per-period calls.

Backend follow-ups (out of frontend scope, for the backend owner): publish
pagination metadata (P2-1) and normalise the `per_page` cap.

---

## 6. Corrections to `docs/FRONTEND_API_HANDOFF.md` (code is authoritative)

1. Fixed-asset depreciation is **`POST /{fixedAsset}/depreciation`**
   (handoff said `/depreciate`).
2. Fixed-asset creates are `POST /cash` and `POST /supplier-credit` — there is no
   `GET /cash` (handoff listed `GET`/`POST /cash`).
3. Manager holds `bank_reconciliation.view/create/update/complete` but **not**
   `reopen` (handoff's `bank_reconciliation.*` shorthand).
4. `GET /api/companies` is not paginated.
5. `per_page` is unbounded on many endpoints.
6. Permissions are returned by `/auth/me`; the login response loads roles without
   their permissions.

---

## 6a. Blockers

None. No blocking issue prevents an accurate architecture decision. The only
audit-environment limitation is the absence of PHP/Composer (static verification
only); this does not affect the frontend contract.

---

## 7. Next recommended step

**Frontend Phase 1 — Foundation**, scoped to:

1. Scaffold `accounting-app/frontend` (Next.js App Router + TS + Tailwind v4).
2. Design tokens + theme provider + base UI primitives + responsive app shell.
3. API client + envelope/error handling + pagination adapter.
4. Session BFF (login/logout/me + authenticated proxy) + 401 handling.
5. Public auth screens + protected `(app)` guard.
6. Company switcher + permission-aware navigation.
7. Quality gates: `lint`, `typecheck`, `test`, `build`.

**Stop after Phase 0. Wait for review and approval before implementing Frontend
Phase 1**, and confirm the decisions in §5 (especially token storage).
