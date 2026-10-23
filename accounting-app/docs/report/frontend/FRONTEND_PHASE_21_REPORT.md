# Frontend Phase 21 Report — Production Readiness, Live Integration & Release Verification

> **Git note.** The Phase 21 brief prohibits commits, pushes, rebases, resets and
> other prohibited Git operations (§2.2, §16). No commit or push was created and
> no prohibited Git operation was run. All Phase 21 work remains **uncommitted**
> as modified/untracked files for the operator to review (§16).

> **Evidence legend.** `[source]` source inspection · `[tests]` automated tests ·
> `[build]` production build · `[live]` exercised against running frontend/BFF/Laravel ·
> `[manual]` manually inspected · `[not verified]` evidence unavailable.

> **Post-release corrections (2026-10-17).** Later backend work (commit
> `aecede2`, "Backend release remediation") and a read-only release audit
> (`CONTROLLED_RELEASE_AUDIT_REPORT.md`) superseded parts of this report.
> The body below is preserved as the historical Phase 21 record; the following
> statements are corrected:
>
> - **F-2 is resolved.** The emailed password-reset link now targets the SPA at
>   `{FRONTEND_URL}/reset-password?token=…&email=…`, built from configuration in
>   `App\Support\FrontendUrl`; it no longer points at the API probe. The API
>   token probe `GET /api/auth/reset-password/{token}` is retained unchanged.
> - **F-3 is resolved.** Unauthenticated API requests now return the 401 JSON
>   envelope for every `Accept` header (`bootstrap/app.php`); the missing
>   `login`-route lookup was removed via `redirectGuestsTo(fn () => null)`.
> - **The 409 narrative is superseded.** The backend does **not** return 409 for
>   a missing company: `ResolveCompanyContext` returns **403**
>   ("No company is associated with your account…"). The
>   `409 "Select a company before making this request."` was emitted only by this
>   project's BFF proxy and has since been aligned to **403**
>   (`src/app/api/backend/[...path]/route.ts`) so both layers agree. No frontend
>   code ever branched on a 409, so this was a contract/documentation issue, not
>   a UI defect.
> - **§5 inventory correction:** there is no `POST /journals/{id}/void` route in
>   the backend or the frontend client; only `POST /journals/{id}/post` exists.
> - **Route-count note:** the companion backend remediation report recorded
>   **216** routes; a re-measurement on 2026-10-17 gives **215**
>   (`php artisan route:list --json`), with `password.reset` and
>   `auth.password.reset` still registered.

---

## 1. Executive summary

Phase 21 audited the frontend/backend contract surface, reviewed the BFF and
authentication security layer, and — with operator authorisation — performed
**live integration verification against a real running Laravel API and the real
Next.js BFF**.

Headline results:

- **One confirmed release-blocking frontend defect was found and fixed:** the
  Next.js auth gate (`src/proxy.ts`) gated `/forgot-password` and
  `/reset-password`, so a signed-out visitor (including someone following the
  emailed reset link) was redirected to `/login`. Phase 20's password-recovery
  workflow was therefore unreachable for exactly the users who need it. Fixed
  with two matcher exclusions and covered by a new 18-case regression test.
- **Live integration was actually performed** at layers 1–4 of the brief's
  taxonomy, plus the authentication/company/session workflows at layer 5:
  30+ company-scoped reads, session restore, company switching, logout, upstream
  401 session clearing, password recovery end-to-end, token rotation, and BFF
  security probes.
- **All five quality gates pass** (lint, `eslint --max-warnings=0`, `tsc`,
  Vitest 110 files / 1083 tests, production build).
- The BFF security model was verified (`[source]` + `[live]`): JWTs stay
  server-side in AES-256-GCM HttpOnly cookies, the browser never calls Laravel,
  the proxy is allow-listed, headers are rebuilt server-side, upstream 401 clears
  the session, and responses are `no-store`.
- **No frontend contract defect remains** that blocks normal operation.
  Remaining items are backend-side gaps and environment/deployment
  prerequisites (notably: the password-reset **email link points at the API
  URL**, not the web app — a backend notification-target issue).

---

## 2. Release-readiness classification

**Frontend: READY WITH DOCUMENTED LIMITATIONS.**

Justification:

- The scope actually verified has no known release-blocking frontend defect: the
  single blocker found (gated recovery routes) is fixed and regression-tested.
- All five required gates pass; the production build emits the expected routes
  and the proxy middleware.
- Live verification reached layer 4 (real frontend/BFF ↔ real Laravel) and the
  authentication/company/session workflows at layer 5.
- Non-blocking gaps remain and are documented, not hidden:
  no visual/keyboard (browser) verification was possible; production deployment
  (layer 6) was not verified; accounting-document posting workflows were not
  exercised (not authorised); and a **backend-side** integration gap means the
  emailed recovery link does not land on the web app.

**Full-system production readiness: NOT READY.** Independent of the frontend,
the backend gaps in §14 (notably the reset-email link target and `APP_DEBUG`
stack traces for non-JSON unauthenticated requests) and the deployment
prerequisites (permission seeding, secrets, `APP_URL`, mail delivery) must be
addressed first. This report covers the frontend only.

---

## 3. Initial branch, HEAD and Git status

- Branch: `main`
- HEAD: `f39b838 Phase 21 Running` (the Phase 20 work was already committed and
  pushed by the operator before this phase).
- Working tree at the start of Phase 21: **clean** (`git status --short` empty).

---

## 4. Baseline quality-gate results (before any Phase 21 change)

| Gate | Command | Result |
|---|---|---|
| Lint | `npm run lint` | exit 0 `[build]` |
| ESLint (strict) | `npx eslint . --max-warnings=0` | exit 0 `[build]` |
| TypeScript | `npx tsc --noEmit` | exit 0 `[build]` |
| Unit/component | `npx vitest run` | **108 files / 1058 tests** pass `[tests]` |
| Production build | `npm run build` | exit 0; proxy + routes emitted `[build]` |

So the repository was green before Phase 21; any change in the final totals is
attributable to this phase.

---

## 5. Integration inventory

The frontend currently contains **80 `page.tsx` routes**, **10 BFF route
handlers** (one wildcard proxy + nine session routes) and **25 `lib/**/api.ts`**
data modules.

| Area | Frontend routes (examples) | Backend surface | Envelope / pagination | Tests | Live |
|---|---|---|---|---|---|
| Auth & access | `/login`, `/forgot-password`, `/reset-password`, `/settings/security` | `POST /auth/{register,login,logout,password,forgot-password,reset-password}`, `GET /auth/me`, `POST /email/verification-notification` | `{success,message,data}` | `[tests]` client/password/paths/snapshot/session-crypto | `[live]` |
| Session/BFF | `src/app/api/session/*` (9 routes), `src/app/api/backend/[...path]` | server-to-server to Laravel | `{success,message,data}` | `[tests]` proxy-policy, backend | `[live]` |
| Company admin | `/companies/new`, `/settings/company`, `/settings/company/members` | `GET/POST /companies`, `POST /companies/{id}/switch`, member ops, `GET/PUT /company`, `/company/settings` | `{…}` | `[tests]` company-create-form | `[live]` |
| Users | `/settings/users` | `GET/POST/PUT/DELETE /users` | `{…}` | `[tests]` users validators | `[live]` |
| Chart of accounts | `/accounts`, `/accounts/types` | `GET/POST /accounts`, `GET /accounts/types`, `GET /accounts/{id}/balance` | flat `data` array | `[tests]` accounts validators (19) | `[live]` |
| Journals | `/journals` | `GET/POST/PUT /journals`, `POST /journals/{id}/post` | flat `data` array | `[tests]` | `[live]` |
| Periods / fiscal years | `/periods`, `/financial-years` | `GET /accounting/{periods,financial-years}` | flat `data` array | `[tests]` | `[live]` |
| Reports | `/reports/{trial-balance,profit-loss,balance-sheet,general-ledger,cash-bank,receivables,payables,…}` (11) | `GET /accounting/reports/*` (and `GET /accounting/trial-balance`) | object (`period,base_currency,rows,totals`) | `[tests]` reports api + views | `[live]` |
| Dashboard | `/` | reports + journals + cash-bank | mixed | `[tests]` partly | `[live]` page load |
| Cash & banking | `/cash-bank`, `/cash-bank/transactions`, `/bank-reconciliations` | `/cash-bank-accounts`, `/cash-bank-transactions`, `/bank-reconciliations` | flat `data` array | `[tests]` cash-bank validators | `[live]` |
| Taxes | `/taxes`, `/tax-calculator`, `/tax-reports` | `/accounting/taxes`, `/accounting/tax/calculate` | flat `data` array | `[tests]` | `[live]` |
| Sales & receivables | `/customers`, `/sales-invoices`, `/customer-receipts` | `/customers`, `/sales-invoices`, `/customer-receipts` | flat `data` array | `[tests]` totals, payables-aging view | `[live]` |
| Purchases & payables | `/suppliers`, `/purchase-bills`, `/supplier-payments` | `/suppliers`, `/purchase-bills`, `/supplier-payments` | flat `data` array | `[tests]` | `[live]` |
| Credit/debit notes | `/credit-notes`, `/debit-notes` | `/credit-debit-notes` | flat `data` array | `[tests]` totals | `[live]` |
| Fixed assets | `/fixed-assets/*` (register, depreciation, disposals, categories, reports) | `/accounting/fixed-assets*` | flat `data` array | `[tests]` | `[live]` |
| Currencies / FX | `/currencies`, `/exchange-rates`, `/currency-settings` | `/accounting/currencies`, `/exchange-rates`, `/fx-settings` | **bare `{data}` (envelope exception)** | `[tests]` fx-settings validators | `[live]` |
| Dimensions | `/dimensions` | `/accounting/dimensions` | flat `data` array | `[tests]` validators | `[live]` |
| Budgets | `/budgets`, `/budgets/[id]` | `/accounting/budgets` | flat `data` array | `[tests]` validators | `[live]` |

Notes:
- Every module follows the same shape: a browser `lib/**/api.ts` that calls the
  same-origin `/api/backend/<path>` proxy, plus a runtime validator that parses
  unknown payloads. This uniformity is what made a compact audit possible.
- `[live]` in the table means the endpoint was actually requested and returned
  200 against an empty test company (see §10).

---

## 6. Contract discrepancies found and their evidence

Findings are ordered by severity. None is a confirmed frontend defect except
F-1 (fixed).

**F-1 — Release-blocking: public password-recovery routes were gated (`[live]`).**
`src/proxy.ts` matcher excluded only `api`, `login`, `_next/*` and static files.
Signed-out requests to the recovery screens were redirected before the page
could render:

```
GET /forgot-password                      -> 307 /login?next=%2Fforgot-password
GET /reset-password?token=…&email=…       -> 307 /login?next=%2Freset-password…
```

Phase 20's "authentication completion" deliverable was therefore unreachable
without an existing session — i.e. precisely when it is needed. **Fixed** (§7).

**F-2 — Backend: reset email links to the API, not the web app (`[live]` + `[source]`).**
The default Laravel `ResetPassword` notification resolves the named
`password.reset` route. The backend is intentionally headless and names its
**GET API probe** `password.reset` (`routes/api.php:83-86`, with an explanatory
comment). The emitted link is consequently:

```
http://<api-host>/api/auth/reset-password/<token>?email=<email>
```

A recipient clicking it lands on a JSON token-probe endpoint, not the SPA's
`/reset-password?token=&email=` form. The frontend recovery flow itself is
correct and now reachable (F-1 fixed, `[live]` verified end-to-end), but the
**emailed-link leg cannot complete in a browser**. Frontend cannot fix what URI
the backend puts in the mail: **backend follow-up required** (configure the
notification/`APP_URL` or a proxy rewrite so the link targets the SPA). Not a
frontend defect; recorded as a release prerequisite.

**F-3 — Backend: unauthenticated non-JSON requests return a 500 debug trace (`[live]`).**
`GET /api/auth/me` (and `/api/journals`) without a token and **without
`Accept: application/json`** returned HTTP 500 with a `Route [login] not
defined` stack trace (the framework tried to redirect to a non-existent web
login route). With `Accept: application/json` (what the BFF always sends) the
same request correctly returns `401 {"success":false,"message":"Unauthenticated."}`.
Because the browser never calls Laravel directly, the frontend is unaffected —
but a non-JSON client can elicit a stack trace, and the local `.env` has
`APP_DEBUG` enabled. **Backend follow-up** (JSON 401 for all clients;
`APP_DEBUG=false` in production).

**F-4 — Backend: self-registered users cannot create a company (`[source]` + `[live]`).**
`POST /auth/register` forces the **Staff** role; `config/authorization.php`
gives Staff only `companies.view`. A newly registered user therefore receives
`403 "This action is unauthorized."` from `POST /companies` and has no company,
so every company-scoped screen returns the BFF's `409 "Select a company before
making this request."`. This is a backend onboarding/permission decision
(an Admin must create the company and add members). Frontend handles the
no-company state safely (`409`, not a crash). Documented, not changed.

**F-5 — Backend: `GET /accounting/fx-settings` returns `201` on first read (`[live]` + `[source]`).**
The service creates the singleton row lazily; Laravel's resource layer returns
`201 Created` when `wasRecentlyCreated` is true. Subsequent reads return `200`.
Unconventional for a GET but harmless: the frontend only reads the body and
2xx is accepted. No frontend change.

**F-6 — Minor: report range parameters are dual-named (`[live]`).**
The frontend sends `from`/`to`; the backend accepts both those and
`from_date`/`to_date` (both `200` live). Not a defect; recommend standardising
(backend) to avoid future drift.

**F-7 — Backend lists are flat arrays with no pagination metadata (`[source]` + `[live]`).**
Confirmed live: every list returned a bare `data` array. `normalizeList` does
**not** invent `total`/`lastPage`/`links`; it only infers `hasMore` from a full
page and uses `meta` when a paginator is present. Contract-compliant.

**F-8 — Preserved exception: `/accounting/currencies` and `/accounting/fx-settings` use a bare `{data}` envelope** (`[source]` + `[live]`: top-level keys = `data` only). `apiFetch` unwraps `{data}` without requiring `success`, so both parse correctly.

**No incorrect paths/methods/fields, enum mismatches, permission-key errors, or
lifecycle actions exposed beyond backend rules were found** in the audited
surface. Spot-verified live: `POST /companies/{id}/switch` for a company the
caller is not a member of returns `403`; a non-existent company returns `404`;
`/reports/cash-bank` without `account_id` returns `422` (the frontend never
sends it without an account selected — it gates the fetch until then `[source]`).

---

## 7. Fixes implemented and regression tests added

### Fix 1 (only code change in this phase)

**File:** `accounting-app/frontend/src/proxy.ts`
**Change:** added `forgot-password` and `reset-password` to the matcher's
negative look-ahead so the public recovery screens skip the auth gate.

```
matcher: ["/((?!api|login|forgot-password|reset-password|_next/static|_next/image|favicon.ico|.*\\..*).*)"]
```

Smallest appropriate change; no other behaviour touched. Live-verified:

| Request (signed out) | Before | After |
|---|---|---|
| `GET /forgot-password` | `307 → /login` | **`200`** |
| `GET /reset-password?token=…&email=…` | `307 → /login` | **`200`** |
| `GET /login` | `200` | `200` |
| `GET /` | `307 → /login` | `307 → /login` (unchanged) |
| `GET /journals` | `307 → /login?next=…` | `307 → /login?next=…` (unchanged) |

### Regression tests added

1. **`src/proxy.test.ts` (18 tests)** — compiles the real `config.matcher[0]`
   using Next's documented **start-anchored** matcher semantics and asserts:
   public paths (`/login`, `/forgot-password`, `/reset-password`, `/api/*`,
   `/_next/*`, static files) are **not** gated; app paths (`/`, `/journals`,
   `/settings/users`, `/reports/*`, `/companies/new`, …) **are** gated; and
   app paths that merely contain a public name (`/journals/forgot-password`,
   `/settings/reset-password`) stay protected.
2. **`src/lib/api/pagination.test.ts` (7 tests)** — pins the three list shapes
   the backend actually emits (flat `data` array; bare `{data:[…]}` envelope
   exception; Laravel paginator) and asserts `normalizeList` never fabricates
   metadata, correctly derives `hasMore`, and returns `[]` for garbage instead
   of throwing.

Test rationale (§9): both target a **defect or contract risk discovered during
integration** (the matcher gate; the envelope-exception list shape), not
count padding. No existing test was weakened or deleted.

Net test change: **+2 files, +25 tests.**

---

## 8. BFF, authentication, authorization and company-context review

**Verified by source and/or live exercise. This is not a penetration test.**

| Property | Result |
|---|---|
| JWT never reaches the browser | ✅ `[source]` AES-256-GCM sealed in an HttpOnly cookie; `[live]` `/api/session/login` body contains **no** `token` field |
| Browser never calls Laravel directly | ✅ `[source]` all business calls go to `/api/backend/*`; `[live]` BFF port served them |
| Cookie flags | ✅ `[live]` `Path=/; Max-Age; HttpOnly; SameSite=Lax`; `Secure` when `NODE_ENV=production` `[source]` |
| Session secret handling | ✅ `[source]` `SESSION_COOKIE_SECRET` ≥16 chars required in production, hard failure otherwise (fails closed); dev-only fallback |
| Proxy allow-list | ✅ `[source]` first-segment families; `[live]` `auth/login`, `audit-logs`, `sessions`, `companies/1/switch` → `404` |
| Path traversal | ✅ `[live]` `/api/backend/accounts/..%2Fusers` → `404` |
| Method restriction | ✅ `[source]` allow-listed verbs, else `405` + `Allow` |
| Server-derived auth/company headers | ✅ `[source]` `Authorization`/`X-Company-Id` written **last**; `[live]` client-sent `X-Company-Id: 4` was ignored (still `200` on own company) |
| Client header filtering | ✅ `[source]` only `Accept`, `Content-Type`, pattern-checked `X-Request-Id` forwarded |
| Response header filtering | ✅ `[live]` proxy responses carried only `Content-Type`/`X-Request-Id` + `Cache-Control: no-store`; **no** `Set-Cookie`, **no** `Location` |
| Sensitive caching | ✅ `[live]` `cache-control: no-store` on proxy reads |
| Upstream redirect handling | ✅ `[source]` `redirect: "manual"`; upstream `Location` is dropped |
| Timeout / network failure | ✅ `[source]` 30s → `504`; fetch failure → `502`; `API_BASE_URL` unset → `502` with a clear message |
| Body-size limit | ✅ `[source]` declared and actual length checked → `413` |
| Unauthorized handling | ✅ `[live]` upstream `401` clears the session (`Set-Cookie … Max-Age=0; HttpOnly`) and returns `401` |
| No-company handling | ✅ `[live]` company-scoped call with no active company → `409` (session retained) |
| Logout invalidation | ✅ `[live]` after logout: proxy → `401`, page → `307 /login`, `/api/session/me` → `401` |
| Change-password rotation | ✅ `[live]` old token `401`, new token `200`; BFF re-seals the cookie |
| **Password reset revokes all tokens** | ✅ `[live]` after a reset, the BFF-held token was rejected upstream → session cleared (used to prove the upstream-401 path) |
| Company switching cannot grant arbitrary access | ✅ `[live]` foreign → `403`, non-existent → `404`, own → `200`; failed switch leaves the session intact |
| Password recovery avoids account enumeration | ✅ `[live]` unknown email and known email both return the same generic message; bad/garbage token → `400`; valid → `200`; token is single-use (`[live]` reuse → `400`) |
| Open redirect on `next` | ✅ `[source]`/`[tests]` `sanitizeNextPath` (with `paths.test.ts`); `[live]` `next` preserved as an encoded same-origin path |
| Error bodies leak nothing upstream | ✅ `[live]` proxy errors are the local `jsonFailure` envelope; upstream 401 body was the clean `{"success":false,"message":"Unauthenticated."}` |
| Session expiry | ⚠️ `[not verified]` — not exercised (would require waiting out the TTL); cookie `expiresAt` is derived from Laravel `expires_in` `[source]` |

---

## 9. Live environment availability and exact verification level

**Environment found (inspected without printing secrets):**

- PHP 8.4.12 CLI and Composer present; MySQL listening on 3306; backend `.env`
  configured with a working DB connection.
- Frontend had **no** `.env`/`.env.local`; the BFF needs `API_BASE_URL`, so the
  dev server was started with `API_BASE_URL=http://127.0.0.1:8000/api`. In dev,
  the session secret falls back to the documented development value.
- Backend health: `GET /api/health` → `{"status":"ok","database":"connected"}`.
- The dev DB contained **no** pre-existing users, companies, permissions or
  credentials, and the repo documents none.

**Level achieved (brief §6.3):**

- Layer 1 source verification — ✅
- Layer 2 automated tests — ✅ (110 files / 1083 tests)
- Layer 3 BFF integration tests — ✅ (existing `proxy-policy`/`backend` tests + new `proxy` matcher tests)
- Layer 4 live read-only integration — ✅ (real Next dev server ↔ real Laravel)
- Layer 5 end-to-end workflow verification — ⚠️ **partial**: authentication,
  session, company-context, recovery and switching workflows were exercised
  end-to-end; **accounting-document posting workflows were not** (not authorised)
- Layer 6 production deployment verification — ❌ `[not verified]` (no deployed environment)

**Operator authorisations obtained:** (1) perform the full live check and
register a fresh throwaway test user; (2) promote that throwaway user to the
Admin role in the local dev database so company-scoped reads could be exercised.

---

## 10. Live checks performed, outcomes and disclosed mutations

### 10.1 Unauthenticated / error-envelope checks `[live]`

| Check | Outcome |
|---|---|
| `GET /auth/me` no token (JSON) | `401 {"success":false,"message":"Unauthenticated."}` |
| `POST /auth/login` bad credentials | `401` "These credentials do not match our records." |
| `POST /auth/forgot-password` unknown email | `200` generic (no enumeration) |
| `GET /auth/reset-password/<bad>` | `400` invalid/expired |
| Company-scoped request with no company (BFF) | `409` "Select a company before making this request." |
| Foreign company switch | `403` "This action is unauthorized." |
| Non-existent company switch / resource | `404` "The requested endpoint was not found." |
| Validation failure (`POST /accounts` empty) | `422` with a field→messages `errors` map |
| Proxy read after logout / with revoked token | `401`, session cookie cleared |

### 10.2 Authenticated, company-scoped reads `[live]`

With a fresh Admin test user and a dedicated empty test company, all of the
following returned **`200`** with contract-correct shapes (flat `data` arrays or
the documented object for reports): `company`, `company/settings`, `accounts`,
`accounts/types`, `journals`, `customers`, `suppliers`, `sales-invoices`,
`purchase-bills`, `credit-debit-notes`, `customer-receipts`, `supplier-payments`,
`cash-bank-accounts`, `cash-bank-transactions`, `bank-reconciliations`,
`accounting/budgets`, `accounting/dimensions`, `accounting/fixed-assets`,
`accounting/fixed-asset-categories`, `accounting/taxes`, `accounting/currencies`,
`accounting/exchange-rates`, `accounting/fx-settings`, `accounting/periods`,
`accounting/financial-years`, `accounting/reports/{trial-balance,profit-loss,balance-sheet,cash-bank}`,
`accounting/controls`, `users`, `companies`.

Report shape confirmed: `{period, base_currency, rows, totals, include_zero_balances}`.
`accounting/reports/cash-bank` without `account_id` → `422` (backend requirement;
the frontend gates the fetch until an account is selected `[source]`).

### 10.3 BFF session and security `[live]`

- `POST /api/session/login` → `200`; body contained **no** JWT; cookie
  `HttpOnly; SameSite=Lax; Path=/; Max-Age`. `Secure` absent in dev (expected).
- `GET /api/session/me` → snapshot with roles, companies and active company id.
- `/api/backend/{journals,accounts,company/settings,users,accounting/reports/trial-balance}`
  → `200`; `X-Company-Id` injected server-side.
- Allow-list / traversal rejections → `404` (no info leak).
- Client-supplied `X-Company-Id` ignored → `200` (company context is server-owned).
- **Upstream 401 clears the session:** revoked the BFF-held token via a password
  reset, then `GET /api/backend/users` → `401` with
  `Set-Cookie: accountance_session=; Path=/; Max-Age=0; HttpOnly`; subsequent
  proxy → `401`, `/api/session/me` → `401`, `GET /` → `307 /login`.

### 10.4 Password recovery end-to-end `[live]`

`forgot-password` → token extracted from the local mail log (never printed) →
BFF token probe `200` / garbage token `400` → consume `200` → login with the new
password `200` → token reuse `400` (single-use) → BFF login after reset `200`.

### 10.5 Mutations performed (all disclosed, all in the local dev database)

- **12 throwaway test users** created via the public `register` endpoint
  (test emails only; no real data).
- **Admin role assigned** to the throwaway test users used for company-scoped
  checks (operator-approved). Auth/security controls were otherwise never disabled.
- **4 dedicated empty test companies** created (`Phase21 Live/BFF/Iso/Final Co`);
  0 journals, 0 invoices — **no accounting business records were created**.
- **`php artisan app:sync-roles`** run once: an idempotent, non-destructive
  upsert of the configured role/permission definitions (104 permissions). This
  is **not** a migration, seeder or reset; without it, every permission-gated
  policy check fails for every user on a fresh DB (see §14).
- Password resets and JWT logouts against the throwaway accounts.
- Read-only DB inspection via `artisan tinker` (counts only).
- Mail was confirmed to be `MAIL_MAILER=log` **before** any recovery test, so no
  external email was sent.
- **Not** run: migrations, seeders, DB resets, destructive commands, journal
  posting, period closing, user/company deactivation.

**Final DB state:** 12 users, 4 companies, 4 memberships, 104 permissions,
**0 journals, 0 invoices**.

### 10.6 Process note (disclosure)

While extracting the reset token from the local mail log for the recovery test,
one command printed the **token path segment** into terminal output (the URL
shape is `/reset-password/<token>` in the path, not a `token=` query parameter,
so the redaction in that one command did not cover it). The value was **never
written to the report**, and it was **immediately consumed** by completing the
reset — making it invalid (single-use) — on a **throwaway** test account. All
later token handling used variable-only extraction. No credential, JWT, cookie
value or secret appears anywhere in this report or in the terminal history used
to build it.

---

## 11. Checks not performed and why

| Not performed | Reason |
|---|---|
| Visual / responsive / keyboard-accessibility verification | No browser-automation or screenshot tooling is available in this environment; the brief requires stating this rather than inferring success from a build (§10). |
| Console-error / unhandled-rejection monitoring in a real browser | Same tooling limitation (see §13 for what the server log did reveal). |
| Production deployment verification (layer 6) | No deployed environment exists/was authorised. |
| Accounting-document posting workflows (create/post journal, invoice, note, receipt…) | Would create business records; not authorised for exploratory verification (§6.2). Documented as a residual gap. |
| Session/JWT expiry behaviour | Would require waiting out the TTL; not exercised. `[source]`-verified only. |
| Direct cross-company `GET` using a real foreign company id | Not exercised directly; bracketed by `[live]` foreign-switch `403`, bogus-id `404`, and the `[live]` proof that the client cannot inject `X-Company-Id`. |
| Rate-limit (`429`) behaviour | Throttle middleware confirmed `[source]`; a `429` was not deliberately triggered. |
| Proxy `413` body limit and `405` method rejection | `[source]`/`[tests]` only (unit-covered); not live-triggered. |

---

## 12. Quality-gate results and final test totals

Run after the fix and new tests (`accounting-app/frontend`):

| Gate | Command | Result |
|---|---|---|
| Lint | `npm run lint` | **exit 0** `[build]` |
| ESLint (strict) | `npx eslint . --max-warnings=0` | **exit 0** `[build]` |
| TypeScript | `npx tsc --noEmit` | **exit 0** `[build]` |
| Unit/component | `npx vitest run` | **110 files / 1083 tests, all passing** `[tests]` |
| Production build | `npm run build` | **exit 0** `[build]` |

Build output inspection `[build]`: the proxy (`ƒ Proxy (Middleware)`) and the
auth routes `/login`, `/forgot-password`, `/reset-password` are emitted
(confirmed in `.next/server/app/`), alongside the full app route set.

Delta from baseline: **+2 test files, +25 tests** (all introduced by Phase 21).

---

## 13. Runtime / configuration and UX / accessibility findings

**Configuration `[source]`:**

- Environment variables referenced: `API_BASE_URL` (server-only Laravel origin),
  `SESSION_COOKIE_SECRET` (required in production, ≥16 chars, high-entropy),
  `SESSION_COOKIE_NAME` (optional), `NEXT_PUBLIC_API_BASE_URL` (non-secret
  fallback), `NODE_ENV`. `.env.example` documents each; **no secret is exposed
  through any `NEXT_PUBLIC_*` variable**.
- Fail-closed behaviour is present: production without `SESSION_COOKIE_SECRET`
  throws; missing `API_BASE_URL` yields a clear `502` from the proxy.
- Cookies are `Secure` only under `NODE_ENV=production` → production requires
  HTTPS/WSS termination.

**Runtime findings `[live]`:**

- **No route-level error boundary or loading boundary exists** — there is no
  `error.tsx`, `global-error.tsx` or `loading.tsx` anywhere under `src/app`
  (only `not-found.tsx`). Individual views implement their own loading/empty/
  error states (the hooks expose `PERMISSION_REASON` / `NO_COMPANY_REASON`
  style states), so failures are usually contained; but an uncaught render error
  in a segment would fall through to the framework default. **Recommended
  follow-up** (non-blocking): add a top-level `error.tsx`.
- **Next 16 "instant navigation" validation errors on authenticated page loads.**
  Loading `/`, `/settings/users` and `/reports/trial-balance` returned `200` but
  the server logged `Could not validate that a segment in your UI has instant
  navigation … dropped segment: src/app/(app)/…/page.tsx`. This is a
  **pre-existing** diagnostic (unrelated to the proxy change; the pages are
  dynamic in the build) surfaced by live authenticated loads. Public pages that
  opt out explicitly (e.g. `/reset-password` sets `export const instant = false`)
  do not trigger it. **Recommended follow-up:** opt the `(app)` segments in or
  out of instant-navigation per the Next docs. Non-blocking (pages render).
- `GET /` and other app routes redirect to `/login` with a sanitised `next`
  (no open redirect) — `[live]` + `[tests]`.
- No redirect loops observed `[live]`.

**UX / accessibility (source-level review only — visual verification not performed):**

- Established conventions are consistent across modules: green Light Mode /
  professional Dark Mode, shared `Card`/`EmptyState`/`Button` primitives,
  semantic status colours, and uniform list states.
- Money/accounting amounts are displayed from server-provided values; the only
  client-side `Number()` conversions found are **presentational** (e.g. dashboard
  formatting) and are not part of authoritative totals, which flow through the
  backend and dedicated `totals.test.ts` suites `[source]`/`[tests]`.
- Permission-gated actions are hidden when the role lacks the permission, and
  the no-company / no-permission states present plain-language reasons rather
  than raw server errors `[source]`.
- **`[not verified]`:** layout overflow, focus order, dialog accessibility,
  contrast, and responsive breakpoints — these require a browser and were not
  checked (per §11).

---

## 14. Remaining backend gaps and deployment prerequisites

**Backend gaps (out of frontend scope; documented per §2.3/§11):**

1. **Reset email link target** (F-2) — must point at the SPA
   `/reset-password?token=&email=`, not the API probe. Blocking for the
   emailed-link journey.
2. **Non-JSON unauthenticated requests return a 500 stack trace** (F-3) —
   make the API always answer `401` JSON; ensure `APP_DEBUG=false` in production.
3. **Permission seeding is a per-environment step** — a fresh database has roles
   but **zero** permissions until `php artisan app:sync-roles` runs; until then
   every policy check fails. Add it to deployment/onboarding automation.
4. **Onboarding path for the first company** (F-4) — a self-registered Staff user
   cannot create a company; provisioning an Admin / invite flow is required.

**Deployment prerequisites (frontend):**

- `API_BASE_URL` → Laravel `/api` origin, reachable **server-to-server** (no
  browser CORS needed, since the browser only calls same-origin BFF routes).
- `SESSION_COOKIE_SECRET` → ≥16 chars, high entropy (`openssl rand -base64 32`),
  required in production (app fails closed without it).
- HTTPS termination (production cookies are `Secure`), and `NODE_ENV=production`.
- Optional: `SESSION_COOKIE_NAME`.

**Deployment prerequisites (backend/system):**

- Correct `APP_URL` and a mail transport that is **not** `log`; notification URL
  fixed (gap 1). Backend `.env` DB/mail/JWT settings; `APP_DEBUG=false`.
- Run `app:sync-roles` (and `app:create-admin`) on each environment.

---

## 15. Known limitations and recommended follow-up

1. Fix the reset-email link target (backend) so recovery works from a mail client.
2. Add a route-level `error.tsx` (and consider `loading.tsx`) for the `(app)` group.
3. Resolve the Next 16 instant-navigation diagnostics for `(app)` segments.
4. Add backend automation to seed permissions (`app:sync-roles`) on deploy.
5. Provide an onboarding path for the first company (Admin provisioning / invites).
6. Standardise report range parameter names (`from`/`to` vs `from_date`/`to_date`).
7. Provide a browser-based visual/accessibility pass before a public release
   (not possible in this environment).
8. Consider test data / a seeded test admin for future live verification so the
   throwaway-user + manual-promotion dance is unnecessary.

---

## 16. Final Git status

Working tree after Phase 21 (no commits, no pushes):

```
 M accounting-app/frontend/src/proxy.ts
?? accounting-app/frontend/src/proxy.test.ts
?? accounting-app/frontend/src/lib/api/pagination.test.ts
?? accounting-app/frontend/AGENTS.md
?? accounting-app/docs/report/frontend/FRONTEND_PHASE_21_REPORT.md
```

- `M src/proxy.ts` — the F-1 matcher fix.
- `?? src/proxy.test.ts`, `?? src/lib/api/pagination.test.ts` — new regression tests.
- `?? FRONTEND_PHASE_21_REPORT.md` — this report.
- `?? accounting-app/frontend/AGENTS.md` — **not authored by Phase 21**: it is
  generated automatically by `next dev` ("✓ Generated AGENTS.md for AI agents"),
  which is why it only appears after the live run. It can be committed by the
  operator (the file itself notes this keeps the tree clean) or ignored; no
  Phase 21 code depends on it.

No files were deleted. No earlier report was modified.

---

## 17. Confirmation of constraints

- **No backend file, dependency or configuration was modified.**
- **No migration, seeder, database reset or destructive database command was
  run.** (The only backend-side command run was the idempotent
  `app:sync-roles`, disclosed in §10.5; DB writes were limited to the disclosed
  throwaway users/companies and role assignment.)
- **No dependency was added, removed or upgraded.**
- **No commit, push, rebase, reset, tag or other prohibited Git operation was
  performed.** All Phase 21 changes remain uncommitted.
- **No earlier phase report was overwritten.**
- **No secret, credential, JWT or session-token value appears in this report**;
  the single token-exposure incident and its remediation are disclosed in §10.6.

---

*Phase 21 is the final planned frontend phase. No new implementation phase was
started.*
