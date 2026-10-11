# Frontend Phase 0 — Repository & API Audit

**Phase:** Frontend Phase 0 (discovery, verification, planning)
**Date:** 2026-10-11
**Auditor role:** frontend Phase 0 inspection
**Backend head at inspection:** `f4723ce Phase 18: Backend Completion Audit & Frontend Handoff`
**Companion documents:** `docs/FRONTEND_API_HANDOFF.md` (backend-authored),
`docs/FRONTEND_BLUEPRINT.md` (proposed frontend architecture),
`docs/FRONTEND_PHASE_0_REPORT.md` (summary / decisions).

> This document records **verified facts** and clearly labels **recommendations**
> and **unresolved questions**. Endpoints were verified against `routes/api.php`,
> controllers, FormRequests, Resources, middleware and config — not against the
> handoff alone.

---

## 1. Scope and method

Frontend Phase 0 is discovery only. No frontend UI was implemented, no backend
behaviour was changed, no migrations/seeders/destructive commands were run, and
no existing changes were committed, stashed or discarded.

Method:

1. Inspected the Git repo, branch and working tree.
2. Read the existing documentation (`docs/FRONTEND_API_HANDOFF.md`,
   `docs/report/PHASE_18_REPORT.md`).
3. Verified the route table in `routes/api.php` line by line.
4. Read auth, company-context, authorization, response-envelope and
   route-model-binding code.
5. Inspected representative controllers, FormRequests and API Resources for
   request/response shapes, pagination, decimals, dates and errors.
6. Checked the local toolchain and installed dependencies.

Evidence is cited by file path. Where the handoff and the code disagreed, the
code was treated as authoritative and the disagreement is recorded in §8.

---

## 2. Repository findings

- **Repository root:** `E:\Ram\Accountance`.
- **Branch:** `main`, tracking `origin/main`, working tree clean at start.
- **Layout:**
  - `accounting-app/backend` — Laravel 13 REST API (the only application).
  - `accounting-app/docs` — handoff + phase reports.
  - `prompt/` — phase briefs (backend phases + this Phase 0 brief).
- **No `BLUEPRINT.md`** exists anywhere in the repo (the brief referred to it
  conditionally). The authoritative backend docs are
  `accounting-app/docs/FRONTEND_API_HANDOFF.md` and
  `accounting-app/docs/report/PHASE_18_REPORT.md`.
- **Untracked user content:** `prompt/frontend/` (the Phase 0 brief itself) is
  untracked. It was preserved untouched.
- **Toolchain on the inspection machine:** Node `v24.1.0`, npm `11.4.2`.
  **PHP and Composer are not installed** and `backend/vendor` is absent, so
  `php artisan`, `composer` and the PHPUnit suite **could not be executed** in
  this environment. All verification is static (source reading). This is a
  limitation of the audit environment, not of the project.

---

## 3. Existing frontend / backend status

- **No frontend application exists.** There is no Next.js app, no `app/`
  frontend, and no separate frontend package.
- `accounting-app/backend/package.json` + `vite.config.js` + `resources/` are
  the **Laravel default asset pipeline** (Vite 8 + Tailwind CSS **v4** +
  `laravel-vite-plugin`), used only for the framework's own mail/error views.
  This is **not** a product frontend and must not be extended into one.
- **Conclusion:** Frontend Phase 1 will scaffold a new, single Next.js
  application. No second frontend exists to collide with.

---

## 4. Verified API contract

### 4.1 Base path, headers, envelope

- Every business route is under `/api` (`bootstrap/app.php` routes `routes/api.php`
  with the `api` prefix). Health is `GET /api/health`.
- `Accept: application/json` is expected; all responses are JSON.
- **Request id:** global `App\Http\Middleware\AssignRequestId` echoes an
  `X-Request-Id` header on every response — a fresh UUID, or an echo of a valid
  inbound value matching `^[A-Za-z0-9-]{1,64}$` (anything else is replaced, never
  rejected). Quote it in bug reports; it correlates to backend audit rows.
- **Success envelope** (`app/Support/ApiResponse.php`):
  ```json
  { "success": true, "message": "…", "data": … }
  ```
  `data` is **omitted** when null (e.g. logout).
- **Error envelope:**
  ```json
  { "success": false, "message": "…", "errors": { "field": ["…"] } }
  ```
  `errors` is present only when there is a field map (validation).
- **Status codes** (from `bootstrap/app.php` renderers):
  - `200` / `201` success.
  - `401` unauthenticated, expired/invalid/blacklisted token (four distinct
    JWT exception renderers all return `401` with a human message).
  - `403` authenticated but not permitted; also inactive user, missing/inactive
    company context, and "company exists but you are not a member".
  - `404` not found **and** cross-company ids (deliberate, to avoid disclosure).
  - `405` wrong method.
  - `422` validation failure (`{success:false,message,errors}`).
  - `429` rate limited (`throttle:*`), with `Retry-After`.
  - `500` unexpected (generic message when debug off).
  - `503` health when the DB is unreachable.

### 4.2 Authentication (verified)

Public, `throttle:auth` (`routes/api.php` lines 55–98):

| Method | Path | Notes |
|---|---|---|
| GET | `/api/health` | no auth |
| POST | `/api/auth/register` | `throttle:register` |
| POST | `/api/auth/login` | `throttle:login` |
| POST | `/api/auth/forgot-password` | `throttle:forgot-password` |
| POST | `/api/auth/reset-password` | `throttle:reset-password` |
| GET | `/api/auth/reset-password/{token}` | token `[A-Za-z0-9]+`; validity check |
| GET | `/api/auth/email/verify/{id}/{hash}` | `signed` + `throttle:verify-email` |

Authenticated, `auth:api` + `auth.fresh` (`routes/api.php` lines 107–119):

| Method | Path |
|---|---|
| GET | `/api/auth/me` |
| POST | `/api/auth/logout` |
| PUT | `/api/auth/password` |
| POST | `/api/auth/email/verification-notification` |

**Login** (`app/Http/Controllers/Api/AuthenticatedSessionController.php`):
returns `{ token, token_type:"bearer", expires_in, user }`.
- `expires_in = config('jwt.ttl') * 60`. `.env.example` sets `JWT_TTL=60`, so
  **`expires_in = 3600` seconds** by default. `config/jwt.php` is HS256,
  blacklist enabled, `refresh_ttl` 20160 min (2 weeks) — but see below.
- **No refresh endpoint exists.** On expiry the API returns `401`
  `"Your token has expired. Please sign in again."`
- **`auth.fresh`** (`app/Http/Middleware/EnsureTokenIsFresh.php`) re-checks on
  every request: the user still exists and is active (else `403`), and the JWT
  `pv` password-version claim still matches (else `401`). So a password change or
  deactivation invalidates live tokens immediately. **Design for a sudden 401 on
  any call.**
- **Login failures are intentionally indistinguishable** (unknown email, wrong
  password, deactivated account → same `401`). Do not build UI that explains why.
- **`GET /api/auth/me`** returns the user resource with
  `roles[].permissions[]` loaded (`CurrentUserController`), while the **login
  response loads roles without permissions** (`AuthenticatedSessionController`
  loads only `roles`). The frontend must call `/api/auth/me` (or `/api/companies`
  + permissions endpoint) to obtain the **permission set** used to drive UI.
- `UserResource` fields: `id, first_name, last_name, full_name, email, mobile_no,
  email_verified, is_active, roles[], last_login_at, created_at, updated_at`.
  **Permissions are not on the user** — they hang off `roles[].permissions[]`.

### 4.3 Company context and permissions (verified)

- Active company is resolved **only** from the `X-Company-Id` header, falling
  back to the user's default company (`app/Services/CompanyContext.php`,
  `app/Http/Middleware/ResolveCompanyContext.php`). It is **never** accepted in a
  URL or body.
- Header rules:
  - Valid numeric id, caller is a member, company active → resolved.
  - Header names a real company the caller is **not** a member of → **`403`**.
  - Header names a non-existent company → **`404`**.
  - Header omitted → caller's default company; if none, request is rejected
    (`403` "No company is associated…").
- Company routes (`routes/api.php` 150–205): list/create/read/update,
  `activate`/`deactivate`, `switch`, member add/remove, plus
  `GET /api/company`, `GET|PUT /api/company/settings`,
  `PUT /api/company/settings/base-currency`.
- `POST /api/companies/{company}/switch` returns the company and **echoes
  `X-Company-Id` in the response headers**; store and resend that id.
- `GET /api/companies` returns the caller's companies (`CompanyResource`), each
  carrying `settings` and `is_default`. **This endpoint is NOT paginated** — it
  uses `->get()` (`CompanyController::index`). It requires `companies.view`.
  `POST /api/companies` requires `companies.create`, which only **Admin** holds
  (from `config/authorization.php`).
- **Permissions → frontend:** `config/authorization.php` defines the full
  permission name set and role matrix; permission names are also enumerated in
  `app/Enums/PermissionName.php`. Roles: `Admin (*)`, `Accountant`, `Manager`,
  `Staff (companies.view only)`. The frontend should **gate UI on capability
  names** (from `/api/auth/me`), never on hard-coded role names; the server is
  authoritative and returns `403`.

**Role matrix (verified from `config/authorization.php`):**

- **Admin** — all permissions.
- **Accountant** — full CRUD on accounts, journals, customers/suppliers,
  invoices/bills, receipts/payments, notes, cash/bank, reconciliations, fixed
  assets, dimensions, taxes; budgets view/create/update/delete but **not
  approve**; reports/ledger/periods/tax reads; controls view; FX/rates
  view/create/update; **no** `accounting.currency.create/update/activate/
  deactivate` (global master data).
- **Manager** — read-only across modules; **plus** `bank_reconciliation.view/
  create/update/complete` (no `reopen`), `accounting.budgets.view` +
  `accounting.budgets.approve`, `companies.update`, `companies.settings.*`.
  Holds no `.create/.post/.update` on any transactional document; dimensions
  **view only**.
- **Staff** — `companies.view` only.

### 4.4 Response shapes, decimals, dates, currency

- **Money is returned as strings** with up to 4 decimal places, never JSON
  numbers (e.g. `"1000.0000"`). Documented in `FRONTEND_API_HANDOFF.md` §1 and
  confirmed in resources (`JournalLineResource`, `SalesInvoiceResource` via
  `Money::toDatabase()`, report services via `->toDatabase()` / `(string)`).
- **Amounts are base currency**; journal lines additionally expose FX provenance
  (`currency_id`, `currency_code`, `exchange_rate`, `foreign_debit`,
  `foreign_credit`; all `null` for a base-currency line).
- **Business dates** are date-only strings `YYYY-MM-DD` (e.g. `JournalResource`
  `journal_date => toDateString()`, `SalesInvoiceResource` `invoice_date`/
  `due_date`).
- **Timestamps** are ISO-8601 with offset, UTC (`toIso8601String()`), e.g.
  `2026-10-11T09:00:00+00:00`.
- **App timezone is UTC** (`config/app.php` → `'timezone' => 'UTC'`). Each
  company carries its own `timezone` and `date_format`
  (`CompanyResource`, `CompanySettingResource`). Reports take **date-only**
  windows (`from_date`/`to_date`, aliases `from`/`to`); point-in-time reports use
  `as_of`. The backend does not convert company dates — the frontend must present
  UTC timestamps in the company timezone and must not reinterpret date-only
  values as instants.
- **Currency metadata** is available via `base_currency`
  `{code,name,symbol,decimals}` on reports and via `/api/accounting/currencies`.
- `accounting.rounding.max_input_decimals = 10`; money is stored at scale 4
  (`config/accounting.php`); exchange rates at scale 10.

### 4.5 Pagination — verified behaviour (important)

**Confirmed: paginated list endpoints return a flat `data` array with no
`links`/`meta`/`total`/`current_page`/`last_page`.** The controllers call
`paginate()` then wrap `ResourceCollection::collection($paginator)` inside the
plain `ApiResponse::success(['data' => …])` array; serialisation through
`jsonSerialize()` emits only the page items. `docs/report/PHASE_18_REPORT.md`
finding **P2-1** verified this empirically:

```json
{ "success": true, "message": "Accounts retrieved successfully.",
  "data": [ { "id": 1 }, { "id": 2 } ] }
```

Consequences and details:

- The only way to know you are on the last page is to receive **fewer than
  `per_page`** items. Do **not** build a numbered page control that needs
  `last_page`.
- `per_page` defaults vary: **50** for accounts, accounting periods, financial
  years, users; **25** for journals, customers, suppliers, sales invoices,
  purchase bills, credit/debit notes, receipts, payments, cash/bank
  transactions, bank reconciliations, budgets, dimensions, fixed assets.
- `per_page` **validation** is inconsistent (verified):
  - Capped `1..100`: budgets, dimensions, dimension values, fixed-asset
    categories, fixed assets, bank reconciliations (via their FilterRequests).
  - **Not capped / not validated** (raw `$request->integer('per_page', …)`):
    accounts, accounting periods, financial years, journals, customers,
    suppliers, sales invoices, purchase bills, credit/debit notes, receipts,
    payments, cash/bank transactions, users. Treat as a backend follow-up; the
    frontend should always send a sane explicit `per_page`.
- **`GET /api/companies` is not paginated** (`->get()`), unlike the list
  endpoints above.
- Sorting is **fixed per endpoint** (e.g. accounts by `code`, journals by
  `journal_date` desc then `id` desc); there is no generic `sort` parameter.

### 4.6 Filters (verified representative)

- **Accounts:** `search`, `account_type`, `is_active` (three-state: absent = all,
  `true`/`false`).
- **Journals:** `from`, `to`, `status`, `search` (description/reference/number),
  `per_page`.
- **Sales invoices / purchase bills:** `status`, `customer_id`/`supplier_id`,
  `from`, `to`, `outstanding` (bool), `search`, `per_page`.
- **Cash/bank transactions:** `transaction_type`, `status`, `account_id`,
  `from`, `to`, `reference`, `per_page`.
- **Budgets:** `financial_year_id`, `status`, `search`, `per_page`.
- **Dimensions:** `type`, `is_active`, `search`, `per_page`.
- **Reports:** `from_date`/`to_date` (aliases `from`/`to`), `as_of`,
  `account_id`, `customer_id`, `supplier_id`, `dimension_id`,
  `dimension_value_id`, `include_zero_balances` (trial balance).
- Query strings are preserved on redirect by `withQueryString()` where used.

### 4.7 CORS (configuration note)

There is **no `config/cors.php`** in the repo. Laravel's framework defaults apply
(paths `api/*`, permissive origins). If the browser is to call the Laravel API
**directly** cross-origin, CORS must be constrained for production. The
recommended architecture in `FRONTEND_BLUEPRINT.md` (a server-side session/proxy
in Next.js) sidesteps this by making the API call server-to-server. This is a
**decision item** (§9).

---

## 5. Endpoint inventory (verified)

The route table was counted at **211 `Route::` declarations** in
`routes/api.php`. The grouping below was verified directly; the authoritative
per-endpoint detail (request examples, capability per route) is already
maintained in `docs/FRONTEND_API_HANDOFF.md` §2–§11 and is **not duplicated**
here. Corrections to that document are in §8.

Verified module inventory:

- **Auth / health / users / companies / company settings** — as in §4.2–4.3.
- **Chart of accounts** — `GET /api/accounts/types`, CRUD + activate/deactivate.
- **Accounting periods & financial years** — CRUD, `closing-check`, `close`,
  `reopen`, `periods/generate`, `financial-years/{id}/close`.
- **Journals & ledger** — CRUD + `post`; `GET /api/accounting/trial-balance`,
  `GET /api/accounting/accounts/{account}/balance`.
- **Reports** — all under `/api/accounting/reports/*`: `trial-balance`,
  `general-ledger`, `profit-loss`, `balance-sheet`, `customer-statement`,
  `supplier-statement`, `receivables`, `payables`, `receivables-aging`,
  `payables-aging`, `cash-bank` (all read-only, posted data only).
- **Customers / suppliers** — CRUD (no DELETE) + `deactivate`.
- **Sales invoices / purchase bills** — CRUD + `post` + `adjustable-lines`.
- **Credit/debit notes** — one resource, `note_type` filter, CRUD + `post`.
- **Customer receipts / supplier payments** — CRUD + `post`.
- **Cash & banking** — cash/bank account config; deposits/withdrawals/transfers;
  CRUD + `post`.
- **Bank reconciliation** — CRUD, `movements`, `items`, `complete`, `reopen`.
- **Tax** — taxes, rates, account mapping, `tax/calculate`, tax reports.
- **Fixed assets** — categories; assets `register`, `depreciation-report`,
  `cash`, `supplier-credit`, CRUD, `capitalize`, `depreciation-schedule`,
  `depreciation`, `dispose`.
- **Multi-currency** — currencies, exchange rates, FX settings, controls.
- **Budgets & dimensions** — as in `FRONTEND_API_HANDOFF.md` §10.

**There is no dashboard endpoint.** Dashboard data must be assembled from the
existing report/summary endpoints (see blueprint §9). No endpoint was found that
returns a monthly/trend series in one call.

**No public audit-log API** exists (audit is written internally only).

---

## 5a. Report response shapes (verified, for dashboard planning)

- **Profit & Loss** (`ProfitLossReportService::report`):
  `{ period:{from,to}, base_currency:{code,name,symbol,decimals},
  revenue:{total,accounts[]}, expenses:{total,accounts[]},
  gross_profit, net_profit, is_profitable }`, plus (only when a dimension
  filter is active) `dimension_filter` and
  `unassigned:{revenue,expenses,net}`. `unfiltered = filtered + unassigned`.
- **Balance Sheet** (`BalanceSheetReportService`):
  `{ period:{from,to}, base_currency, assets:{total,accounts[]},
  liabilities:{total,accounts[]}, equity:{total,accounts[],retained_earnings},
  totals:{assets,liabilities,equity,liabilities_and_equity,is_balanced,
  difference}, … }`.
- **Receivables / Payables**:
  `{ as_of, base_currency, rows[], count,
  totals:{grand_total,paid_total,balance_due} }`.
- **Receivables/Payables aging**: bucket-based rows using the configured aging
  buckets (`config/accounting.php` → `reports.aging_buckets`).
- **Cash/bank report** delegates to the ledger generator for one account over a
  date range (running balance rows). It is **not** a company-wide cash-flow
  statement.
- **Budget variance**: `{ budget, period, summary:{revenue,expenses,net,counts},
  lines[], dimension_filter? }` (see handoff §10).
- **Trial balance / general ledger**: `period`, `rows[]`, `totals`, with money
  and `is_balanced` where applicable.
- **Tax reports**: `summary` and `by-tax` on posted document tax snapshots.

---

## 6. Integration risks and unresolved questions

| # | Risk / question | Severity | Status |
|---|---|---|---|
| R1 | **Pagination metadata absent** — cannot build page-number UI; last page only detectable by short page. | High | Confirmed backend limitation (P2-1); a platform decision is pending. |
| R2 | **No refresh token; 60-min TTL** — any call can `401`; UI must handle sudden re-auth. | High | Confirmed; design for it. |
| R3 | **Token storage** — backend is bearer-JWT with no cookie support; naive `localStorage` risks XSS token theft. | High | Recommendation in blueprint §6; **needs approval**. |
| R4 | **`/auth/me` vs login role payload mismatch** — permissions only on `/auth/me`. | Medium | Confirmed; must call `/auth/me`. |
| R5 | **`per_page` not capped on many endpoints** — a client could request unbounded pages. | Medium | Confirmed; frontend sends explicit bounded `per_page`; backend follow-up. |
| R6 | **No dashboard/trend endpoint** — KPI/trend charts need multiple calls or a future API. | Medium | Confirmed; blueprint §9 marks future API needs. |
| R7 | **No CORS config published** — browser-direct calls rely on framework defaults. | Medium | Config item; BFF approach avoids it. |
| R8 | **`expires_in` is a fixed 3600**, not derived from an actual token `exp`; clock skew possible. | Low | Confirmed; treat server `401` as authoritative. |
| R9 | **Cross-company ids return 404, not 403** — error UI must not assume 403 is the only "denied" state. | Low | Confirmed; document in error handling. |
| R10 | **No PHP/Composer/vendor locally** — automated backend verification unavailable here. | Info | Environment limitation; recorded in report §3. |

Unresolved questions for the user (see also the Phase 0 report):

1. Token storage strategy — approve the BFF/httpOnly-cookie approach, or accept
   in-memory-only with re-login on reload?
|2. Frontend location — confirm `accounting-app/frontend` (sibling of `backend`).
|3. Chart library — confirm Recharts (brief) and its version.
|4. Whether to add a normalized **pagination adapter** now (frontend-side only)
   so the UI is ready if the backend later adds `meta` (recommended, no backend
   change).

---

## 7. Financial-integrity and security safeguards (facts the frontend must respect)

- The backend is the **single source of truth** for posting, numbering,
  balances, allocations and period control. The frontend must **never** compute
  an authoritative balance or post a journal/document itself.
- Posted journals and documents are **immutable**; `PUT` edits drafts,
  `POST /post` posts (two separate paths). Do not offer edit/delete on posted
  records.
- Posting is **irreversible** — do not use optimistic updates for post/approve/
  close/complete/capitalize/dispose actions.
- Server-owned fields (`status`, numbers, `journal_id`, totals, `company_id`,
  `created_by`, `posted_by`, `posted_at`) are **not request inputs**; forms must
  not submit them.
- Cross-company ids return `404`; authorization returns `403`. Both are
  server-authoritative.
- Preserve decimal precision: no JS float arithmetic on money.

---

## 7a. Security / integrity notes for the frontend

- Protected routes + short-lived session; central 401 handling that clears the
  session and routes to login.
- Permission-aware navigation driven by `/auth/me` capabilities; the server
  still enforces every action.
- Company isolation via `X-Company-Id`; never trust a client-supplied company id
  beyond selecting context — the server re-validates membership.
- Avoid optimistic UI for irreversible accounting acts; on ambiguous/failed
  posting show a "verify before retry" state and re-read the document.

---

## 8. Discrepancies found vs `docs/FRONTEND_API_HANDOFF.md`

The handoff is accurate in substance. These precision fixes were found by
reading the routes directly:

1. **Fixed-asset depreciation path.** Handoff §11 says
   `POST /{fixedAsset}/depreciate`. The actual route is
   **`POST /api/accounting/fixed-assets/{fixedAsset}/depreciation`**
   (route name `accounting.fixed-assets.depreciate`) — `routes/api.php` line 804.
2. **Fixed-asset create routes.** Handoff §11 lists "`GET`/`POST /cash`/`POST
   /supplier-credit`". There is **no** `GET /cash`; the actual routes are
   `POST /api/accounting/fixed-assets/cash` and
   `POST /api/accounting/fixed-assets/supplier-credit`.
3. **Manager reconciliation grant.** Handoff §12 says "`bank_reconciliation.*`
   actions"; the matrix grants `view/create/update/complete` but **not
   `reopen`**.
4. **`GET /api/companies` not paginated.** The handoff's pagination section is
   general; companies use `->get()` and return all rows.
5. **`per_page` validation** applies only where a FilterRequest exists (see
   §4.5); several endpoints accept an unbounded raw integer.
6. **Permissions only on `/auth/me`, not on login.** Login loads roles without
   their permissions.

These do not change the architecture; they are recorded so the frontend client
matches the code.

---

## 9. Audit conclusions

- The repo has **no frontend**; a single Next.js app is the correct Phase 1
  outcome.
- The backend contract is well documented and **matches the code**, with the
  precision fixes in §8.
- The **pagination gap (P2-1)** and the **bearer-JWT-without-refresh** model are
  the two facts that most shape frontend design.
- The backend is ready for frontend integration; the remaining decisions are
  frontend-side (token storage, folder location, chart/testing libraries) and are
  raised in `docs/FRONTEND_PHASE_0_REPORT.md` for approval.
