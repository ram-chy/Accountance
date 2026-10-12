# Accountance — Frontend Blueprint

**Scope:** proposed architecture for the Accountance frontend, to be built in
Frontend Phase 1+.
**Backend:** Laravel 13 REST API + MySQL 8.4, JWT (`auth:api`), multi-company,
double-entry engine as the single source of truth.
**Companion:** `docs/FRONTEND_PHASE_0_AUDIT.md` (verified contract),
`docs/FRONTEND_API_HANDOFF.md` (endpoint reference).

This blueprint is **provisional architecture**, not implementation. Decisions
that need user sign-off are marked **[APPROVAL]**.

---

## 1. Stack and principles

- **Next.js (App Router) + React + TypeScript**, single application.
- **Tailwind CSS v4** (CSS-first `@theme`; no `tailwind.config.js` needed). The
  backend already uses Tailwind v4 in its Vite asset pipeline, so the team is
  already on v4.
- **Charts are hand-rolled SVG** (Phase 4 decision): the dashboard only needs
  simple bar/breakdown charts, so no charting dependency was adopted (Recharts
  is no longer planned). See `FRONTEND_PHASE_4_REPORT.md`.
- API-driven: Laravel owns accounting logic, calculations, authorization and
  validation. **The frontend never computes an authoritative balance or posts an
  entry itself.**
- Financial integrity: preserve decimal precision; **no JavaScript
  floating-point arithmetic on money**.
- Accessibility: semantic HTML, keyboard navigation, visible focus, contrast.
- Responsive: desktop, tablet, mobile.
- Avoid unnecessary dependencies, premature abstraction and architectural
  complexity. Justify every library.

### 1a. Application location **[APPROVAL]**

Create the app at **`accounting-app/frontend`** (sibling of
`accounting-app/backend`). This keeps a clear monorepo-ish split, avoids nesting
a frontend inside the Laravel app, and does not disturb backend tooling.
Alternative: a separate repository — rejected for Phase 1 to keep the API
contract, docs and app versioned together.

Package manager: **npm** (available in the environment: npm 11.4.2 / Node 24).
Switch to pnpm only if the team wants it; it is not required.

---

## 2. Route and folder organisation

Top-level routes via App Router route groups:

```
src/app/
  layout.tsx                      # root: theme provider, fonts, <html>
  globals.css                     # Tailwind v4 + design tokens

  (public)/
    login/page.tsx
    forgot-password/page.tsx
    reset-password/[token]/page.tsx
    verify-email/page.tsx
    layout.tsx                    # minimal, no app chrome

    (app)/
      layout.tsx                    # protected shell: sidebar + topbar + company switcher
      page.tsx                      # dashboard (home route, Phase 4)
      accounts/                     # chart of accounts (Phase 5, at /accounts)
      journals/                     # journal entries (Phase 6, at /journals)
      cash-bank/                    # cash & banking (Phase 7, at /cash-bank)
        page.tsx                    # overview: classified accounts + ledger balances
        transactions/page.tsx       # transaction list (+filtered by ?account_id)

    accounting/
      # accounts moved to /accounts (Phase 5); journals moved to /journals
      # (Phase 6); remaining modules below
      periods/…
      reports/
        trial-balance/…
        general-ledger/…
        profit-loss/…
        balance-sheet/…
        receivables/…  payables/…
        customer-statement/…  supplier-statement/…
      dimensions/…
      budgets/…
      tax/…
      currencies/…  exchange-rates/…
      controls/…

    sales/…                       # customers, invoices, receipts
    purchases/…                   # suppliers, bills, payments
    credit-debit-notes/…
    bank-reconciliations/…
    fixed-assets/…
    companies/…  company/settings/…
    users/…

  api/                            # Next route handlers (the BFF, see §6)
    session/login/route.ts
    session/logout/route.ts
    session/me/route.ts
    backend/[...path]/route.ts    # authenticated proxy to Laravel
```

Feature-source layout (co-located, feature-oriented). As implemented in Phases
1–5, feature code lives under `src/lib/<feature>/` (data layer) and
`src/components/<feature>/` (UI) rather than a single `src/features/` folder:

```
src/
  app/                            # routing + page composition only
  lib/
    api/                          # fetch client, envelope, pagination adapter, ApiError
    accounts/                     # types.ts, api.ts, validators.ts, use-*.ts (+ tests)
    dashboard/                    # same shape (Phase 4)
    journals/                     # data layer (Phase 6)
    cash-bank/                    # data layer (Phase 7): accounts, transactions, balances
    …
    auth/  permissions/  navigation/   # session helpers, capability utils, nav model
  components/
    ui/                           # dumb, reusable primitives (Button, Input, Dialog…)
    accounts/                     # feature UI (view, table, dialogs)
    dashboard/                    # feature UI (Phase 4)
    journals/                     # feature UI (Phase 6)
    cash-bank/                    # feature UI (Phase 7)
    app/                          # shell: Sidebar, Topbar, CompanySwitcher, PageHeader
```

**Rule:** add a feature folder only when its phase starts. Do not pre-create
empty folders for every future module.

---

## 3. API client conventions

- **Base path:** `/api` on the Laravel host.
- **Envelope:** every response is `{success, message, data?}` or
  `{success:false, message, errors?}` (`app/Support/ApiResponse.php`).
- **Single entry point:** `lib/api/client.ts`

  ```ts
  type Envelope<T> = { success: boolean; message: string; data?: T };

  class ApiError extends Error {
    status: number;            // HTTP status
    errors?: Record<string, string[]>;  // 422 field map
    requestId?: string;        // X-Request-Id, for support
  }

  // unwraps data, throws ApiError on !success or non-2xx
  async function apiFetch<T>(path: string, init?: RequestInit): Promise<T>;
  ```

- **Auth + company headers are attached server-side** (in the BFF), never by the
  browser (see §6): `Authorization: Bearer <jwt>` and `X-Company-Id: <id>`.
- **Request id:** surface `X-Request-Id` from error responses in the error UI /
  logging so bugs correlate with backend audit rows.
- **Typed responses:** one `types.ts` per feature, hand-written from
  `FRONTEND_API_HANDOFF.md` + the verified resources. (Generate later from an
  OpenAPI spec only if the backend publishes one — it does not today.)

### 3a. Pagination adapter (required)

Because paginated endpoints currently return a flat `data` array with **no
`links`/`meta`** (audit §4.5), the list screens cannot depend on page metadata.
Provide a **normalised adapter** that supports the current shape *and* a future
Laravel paginator shape, so the contract can change without touching screens:

```ts
type ListResult<T> = { items: T[]; hasMore: boolean; perPage: number };

function normalizeList<T>(payload: unknown, perPage: number): ListResult<T> {
  // Current: data is T[]
  // Future:  data is { data: T[], meta: {...}, links: {...} }
  // Detect and unwrap both. hasMore = items.length === perPage (current)
  // or meta.current_page < meta.last_page (future).
}
```

- Default `per_page = 25` (or 50 where the endpoint defaults to 50) and always
  send it explicitly.
- "Load more"/next-page UI only; **no numbered pages** until metadata exists.
- Keep all query parameters when changing page/filters.

### 3b. Error handling

- One `ApiError` type; one `<ErrorState>` for page-level failures and one field
  map renderer for `422`.
- Central 401 handling: clear the session and route to `/login` (see §6/§8).
- 403 → "not permitted" view (do not hide the fact that the resource exists when
  the URL is known).
- 404 cross-company ids look like "not found" by design; render a normal
  not-found state.
- 429 → show a retry hint using `Retry-After`.
- Network/unknown → generic error with the `X-Request-Id`.

---

## 4. State, data fetching and forms

- **Server components** fetch read-only data (lists, reports) through the BFF
  for first paint and SEO-irrelevant but fast rendering.
- **Client components** handle interactive filters, forms and mutations. Use the
  smallest tool that works: React hooks + a light query cache.
  - **[APPROVAL]** Recommend **TanStack Query** for client-side caching,
    optimistic-free mutations, and consistent loading/error states. It is the
    one non-trivial dependency with a strong justification (many list/filter
    screens, revalidation after posting). Alternative: hand-rolled hooks to stay
    dependency-light.
- **Forms:** propose **react-hook-form + zod** for the larger accounting forms
  (multi-line journals/invoices) — justification: dynamic line arrays, nested
  field errors (e.g. `lines.0.debit`), and client-side shape checks that mirror
  the server. **[APPROVAL]** The server remains the validator of record; zod only
  prevents obvious mistakes. If rejected, use controlled components + small
  validators.
- **Never** send server-owned fields (`status`, numbers, totals, `company_id`,
  `journal_id`, `posted_*`, `created_by`). Forms submit only the documented
  inputs.
- **Decimal inputs:** treat money as strings end-to-end; validate format with a
  regex + the endpoint's decimal limit; render in a `MoneyInput`.

---

## 5. Design system specification

Tailwind v4 CSS-first tokens in `globals.css` via `@theme` + CSS variables, with
a `.dark` class toggling the dark set. Never hard-code hex values in components;
always use semantic tokens.

### 5.1 Brand + surface tokens

**Light mode** (baseline values from the brief; validate contrast):

| Token | Value | Use |
|---|---|---|
| `--color-primary` | `#2E7D32` | primary actions, active nav |
| `--color-primary-strong` | `#1B5E20` | hover/pressed |
| `--color-primary-subtle` | `#E8F5E9` | selected rows, accents |
| `--color-bg-app` | `#F7F9F7` | app background |
| `--color-bg-surface` | `#FFFFFF` | cards, tables |
| `--color-border` | `#E5EAE5` | dividers, inputs |
| `--color-text` | `#26332B` | primary text |
| `--color-text-muted` | `#647067` | secondary text |

**Dark mode:**

| Token | Value |
|---|---|
| `--color-bg-app` | `#111713` |
| `--color-bg-surface` | `#1B241D` |
| `--color-border` | `#2A382D` |
| `--color-primary` | `#81C784` |
| `--color-text` | `#E5EEE6` |

### 5.2 Semantic status tokens (separate from brand)

Define independently of the green palette so meaning is never inferred from the
brand colour:

| Token | Light | Dark | Use |
|---|---|---|---|
| `--color-success-*` | green set | adjusted | posted, favourable, balanced |
| `--color-warning-*` | amber set | adjusted | draft stale, unassigned, near-limit |
| `--color-danger-*` | red set | adjusted | errors, unbalanced, overdue, 403 |
| `--color-info-*` | blue/slate set | adjusted | hints, filters, informational |

- Every token pair (text on surface) must meet **WCAG AA** contrast; validate and
  adjust the initial values rather than shipping them blindly.
- Status is conveyed by **text + icon + colour**, never colour alone (accessibility
  + colour-blind users).
- Financial states map to semantics deliberately: `POSTED` = neutral/success,
  `DRAFT` = muted, `overdue` = danger, `CANCELLED/VOID` = muted, favourable
  variance = success, unfavourable = danger.

### 5.3 Theme management

- CSS variables + `.dark` on `<html>`.
- **[APPROVAL]** Use **next-themes** (tiny, avoids flash-of-wrong-theme and
  handles system preference) or a ~30-line custom provider. Default: respect
  system preference, persist the user's explicit choice.

---

## 6. Authentication and company context **[DECIDED — implemented in Phase 2]**

The backend is **bearer-JWT with no refresh endpoint and no cookie support**. Per
the brief, tokens must not go to `localStorage` by default. **Decision: the BFF +
httpOnly session cookie pattern (§6.1) is adopted and implemented in Phase 2** —
see `FRONTEND_PHASE_2_REPORT.md`.

### 6.1 Recommended: Next.js BFF with an httpOnly session cookie

```
Browser ──(fetch, no token)──▶ Next route handler ──(Bearer JWT)──▶ Laravel API
        ◀──data + httpOnly cookie──                ◀──envelope──
```

- Login: browser posts credentials to `POST /api/session/login`. The handler
  calls Laravel `POST /api/auth/login`, then stores the JWT in an **httpOnly,
  Secure, SameSite=Lax** cookie (signed/encrypted). The browser never sees the
  token.
- Every authenticated call goes through `app/api/backend/[...path]/route.ts`,
  which reads the cookie, attaches `Authorization` and `X-Company-Id`, forwards
  to Laravel, and unwraps the envelope.
- Logout: `POST /api/session/logout` calls Laravel `/api/auth/logout` (revokes
  the token) and clears the cookie.
- `GET /api/session/me` calls Laravel `/api/auth/me` and returns the user +
  capability set for UI gating.
- Company selection: store the chosen id in the session (server-side) — it is
  not secret — and send it as `X-Company-Id`. `/api/companies` populates the
  switcher; `POST /api/companies/{id}/switch` sets the default server-side.
- **CSRF:** `SameSite=Lax` blocks cross-site state changes; additionally require a
  same-origin `Origin`/`Sec-Fetch-Site` check (and/or a double-submit token) on
  mutating route handlers.
- **Proxy allowlist:** the catch-all proxy must whitelist HTTP methods and path
  prefixes to Laravel's `/api` surface so it cannot be abused as an open proxy.
- Token expiry (60 min): the handler maps Laravel 401 → clear cookie → client
  re-authenticates. No silent refresh exists, so a periodic `/session/me` ping or
  a 401-on-next-action is the trigger.

**Phase 2 status:** implemented `POST /api/session/{login,logout}`, `GET
/api/session/me`, and `POST /api/session/company` (`app/api/session/*`). The JWT
is sealed with Web Crypto AES-256-GCM into the `accountance_session` cookie
(`HttpOnly`, `Secure` in production, `SameSite=Lax`); the browser client reaches
the BFF through the existing `apiFetch` (`baseUrl: ""`) — no token is ever sent
to the browser. `GET /api/session/me` also re-points the cookie if the stored
company is no longer a valid active membership. The token strategy is fixed, so
no backend change was required.

**Phase 3 status (implemented):** the catch-all forwarding proxy
`app/api/backend/[...path]/route.ts` now exists and completes the model above.
Policy lives in the pure, unit-tested `lib/api/proxy-policy.ts`: an allow-list of
HTTP methods (`GET/POST/PUT/PATCH/DELETE`) and route families taken from the
verified `routes/api.php`; `auth/*`, `health`, `password/*` and the raw
`companies/{id}/switch` are **not** reachable. Path segments are validated
(rejecting `.`/`..`, percent-encoding, backslashes, control chars, overlong or
over-count segments) and a rejection is always a 404 so probing reveals nothing.
`Authorization` (from the cookie) and `X-Company-Id` (from the server-side active
company) are injected server-side and can never be supplied or overridden by the
browser; `Cookie`, `X-Company-Id`, `Authorization` and `Host` from the client are
dropped. Company-scoped families require an active company and otherwise fail
with `409` rather than letting Laravel fall back to the user's default. An
upstream `401` clears the BFF session; every other status (including Laravel's
`422` validation errors and `403`/`404`) is passed through, with `X-Request-Id`
preserved and `Set-Cookie`/`Location` stripped. The browser reaches it through
`lib/api/backend.ts` (`backendGet/Post/Put/Patch/Delete`), which shares the
central 401 handler with the session client. See `FRONTEND_PHASE_3_REPORT.md`.

### 6.2 Alternative: in-memory token (simpler, worse UX)

Keep the JWT only in a React context (never persisted); a page reload forces
re-login. No BFF/server layer; the browser calls Laravel directly (needs CORS
config and exposes the token to any XSS). Acceptable only for a demo.

### 6.3 Rejected

`localStorage`/`sessionStorage` for the JWT (XSS token theft) and a backend
cookie-auth redesign (out of scope; would change the API contract).

### 6.4 Permissions

- Obtain capabilities from `/auth/me` (`roles[].permissions[].name`; roles are
  **not** a reliable gate).
- Build a `usePermissions()` helper and a `config/nav.ts` where each nav item
  declares its required capability. Hide/disable nav the user cannot use.
- Always still rely on the server: a hidden button is never the only protection,
  and the UI must handle a `403` gracefully if permissions change mid-session.

---

## 7. Component strategy

- **`components/ui`** — unstyled-behaviour primitives with project styling:
  `Button`, `IconButton`, `Input`, `MoneyInput`, `Select`, `Combobox`, `DatePicker`,
  `Checkbox`, `Switch`, `Dialog`, `Sheet`, `Tabs`, `Tooltip`, `Toast`,
  `Badge` (status), `Card`, `Table`.
  - Prefer accessible primitives. **[APPROVAL]** If a headless library is wanted
    (e.g. Radix/Headless UI) it must be justified per component; start
    hand-rolled for the simple ones and add a library only for genuinely hard
    widgets (date picker, combobox, dialog focus trap).
- **`components/data`** — `DataTable` (sortable headers where the server supports
  a fixed sort, row actions, empty/loading/error states), `Pagination`
  (load-more/next-prev, no page numbers yet), `FilterBar`, `EmptyState`,
  `SkeletonTable`.
- **`components/charts`** — thin Recharts wrappers that read theme tokens via CSS
  variables and accept already-computed series (data comes from the API).
- **Feature components** live under `features/<module>/components`.
- **States are a first-class pattern:** every data screen defines **loading,
  empty, success, failure** and (where relevant) **partial/forbidden** states.

---

## 8. Security and financial integrity safeguards

- Protected routes: `(app)` layout is server-guarded (no session cookie → redirect
  to `/login`); public routes under `(public)`.
- Session expiry: central 401 handling clears the session and redirects; show a
  message when the user is returned to login mid-task.
- Cross-company isolation: always send `X-Company-Id`; treat `404` as "not found"
  (by design) and `403` as "no access". Never cache data across company switches —
  invalidate queries when the company changes.
- Server-side authorization is authoritative; UI gating is convenience only.
- Tokens: kept server-side in an httpOnly cookie (recommended path); never logged,
  never placed in URLs, never exposed to client JS.
- Decimals/currency: render exact strings; format for display with
  `Intl.NumberFormat` using the company/report `base_currency.decimals`; **no
  float math**. If a client-side sum is ever unavoidable (e.g. a visual column
  total), use a decimal helper (e.g. `big.js`) on strings — never `Number()`.
- Dates/timezones: treat date-only values as calendar dates (do not parse through
  `Date` as UTC instants); convert UTC timestamps to the company `timezone` for
  display; respect company `date_format`.
- Prevent duplicate financial submissions: disable and lock submit buttons while
  a post/approve/close request is in flight; use an idempotency guard
  (e.g. a request key in component state) so double-click cannot double-post.
- Failed/ambiguous posting: no optimistic updates for irreversible operations
  (post, approve, close/reopen, complete, capitalize, dispose). On an ambiguous
  failure, send the user to re-read the record ("verify before retry") rather
  than retrying blindly — the backend numbering/posting is transactional but the
  client cannot assume the request did not land.
- Drafts vs posted: `PUT` edits drafts; `POST /post` posts. Never offer edit or
  delete on posted records. Never send `status`.

---

## 9. Dashboard visualisation plan (documented, not implemented)

**Principle:** every dashboard figure comes from an existing backend endpoint, or
is explicitly marked as a **future API requirement**. No hardcoded production
values; sample data only in tests/stories.

### 9.1 Available data → widgets

| Widget | Source (verified) | Notes |
|---|---|---|
| Revenue / Expenses / Net profit KPIs | `GET /accounting/reports/profit-loss` (`revenue.total`, `expenses.total`, `net_profit`, `is_profitable`) | Accrual basis. Range = selected period. |
| Receivables KPI | `GET /accounting/reports/receivables` (`totals.balance_due`) | As-of date. |
| Payables KPI | `GET /accounting/reports/payables` (`totals.balance_due`) | As-of date. |
| Asset / liability / equity totals | `GET /accounting/reports/balance-sheet` (`totals.*`, `is_balanced`) | Point-in-time. |
| Cash & bank position | `GET /accounting/reports/balance-sheet` (cash/bank asset accounts) or `GET /accounting/reports/cash-bank` per account | Cash report is per-account, not company-wide. |
| Expense breakdown | `GET /accounting/reports/profit-loss` (`expenses.accounts[]`) | Per-account totals for the range. |
| Budget vs actual | `GET /accounting/budgets` + `GET /accounting/budgets/{id}/variance` (`summary.net`, `lines[]`) | Favourability is server-computed. |
| Aging (receivables/payables) | `GET /accounting/reports/receivables-aging` / `payables-aging` | Uses configured buckets. |
| Financial alerts | Derived from `GET /accounting/controls`, aging reports, budget variance, `balance-sheet.totals.is_balanced` | No alerts endpoint; assemble from these. |
| Tax summary | `GET /accounting/tax-reports/summary` | For a tax widget if required. |
| Fiscal period filter | `GET /accounting/financial-years`, `GET /accounting/periods` | Populate the period/fiscal-year selector. |

### 9.2 Trend charts — future API requirement

The brief asks for **income vs expenses**, **cash-flow trend** and
**profitability trend**. The current API is **single-range**:

- A monthly series requires **N calls** (one P&L per month) — acceptable for a
  dashboard but chatty and not atomic.
- There is **no cash-flow statement endpoint** (explicitly deferred in the
  backend). A cash-flow *trend* can only be approximated from repeated
  `reports/cash-bank` calls or general-ledger on cash accounts.

**Recommendation:** display the detail widgets now and treat the trend charts as
follow-ups on a **future API**, e.g. `GET /accounting/reports/trends` returning
monthly buckets in one call. Do **not** invent the endpoint or hardcode series.
Until then, either (a) omit trend charts, or (b) render them from bounded
per-period calls behind a clearly-labelled "may be incomplete" behaviour.

### 9.3 Filters and drill-down

- **Company filter:** the global company switcher (sets `X-Company-Id`).
- **Date/fiscal filter:** date range or fiscal period; defaults to the current
  period.
- **Drill-down:** each KPI/card links to the corresponding report route with the
  active filters preserved in the query string.

### 9.4 Accrual vs cash — must be labelled

- **P&L, balance sheet, receivables/payables, budgets** are **accrual** figures
  derived from posted journal entries.
- **Cash & bank** reports reflect actual money movements.
- Label charts accordingly; never present accrual profit as "cash".

### 9.5 Phase 4 status (implemented)

The authenticated dashboard is implemented at the `(app)` home route
(`src/app/(app)/page.tsx` → `DashboardView`). It shows cash & bank balance,
receivables, payables, income, expenses, net profit/loss, an income-vs-expenses
chart, an expense breakdown and a recent-activity list, all from the sources in
§9.1 and only for slices the user's role may read. Two documented gaps are
worked around with the bounded approaches §9.2 permits:

- **Income-vs-expenses trend:** derived from per-month `profit-loss` calls,
  capped at the 12 most recent months of the selected window (`MAX_TREND_MONTHS`)
  and labelled when the window was trimmed.
- **Cash & bank total:** summed client-side from per-account ledger balances
  (needs `accounting.cash_bank.view` + `accounting.ledger.view`) using BigInt
  fixed-point arithmetic — no floating-point money math.

Charts are hand-rolled SVG. Figures are validated at runtime and rendered from
their original decimal strings. All requests go through the Phase 3 proxy via
`backendGet`; a `401` follows the central session flow.

---

## 10. Tables, filtering, sorting, pagination

- Server-side filtering/sorting only (no client-side re-sorting of a partial
  page).
- Fixed sort per endpoint; expose only the columns/sort the API actually supports.
- Filters are URL query state (shareable, back-button friendly) and are preserved
  across pagination via `withQueryString` semantics.
- Pagination UI: "Next/Previous" or "Load more" using the adapter's `hasMore`
  (short page detection). Document the limitation in the UI (no total count).
- Provide skeleton rows for loading and an explicit empty state.

---

## 11. Charts

- Charts are hand-rolled SVG components (`src/components/dashboard/*chart*.tsx`)
  as of Phase 4; colours are read from CSS variables so they follow light/dark
  automatically. No charting library is used.
- Money axes/labels formatted with the decimal-safe formatter.
- Accessible fallback: provide a data table / summary alongside or behind a
  toggle; do not rely on colour alone.

---

## 12. Testing and quality gates

- **Unit + component:** Vitest + React Testing Library. Cover formatters
  (money/date), the pagination adapter, the API client's envelope/error handling,
  and permission gating.
- **E2E (critical flows):** Playwright — login, company switch, create+post a
  journal, generate a report, permission-denied behaviour. Mock the Laravel API
  at the network layer (or run against a seeded test backend) — **do not** run
  migrations/seeders in this phase.
- **Quality gates (exact commands, run in `accounting-app/frontend`):**
  - `npm run lint`
  - `npx tsc --noEmit` (`package.json` has no `typecheck` script)
  - `npm run test`
  - `npm run build`
  These must pass before a frontend phase is considered complete.

---

## 13. Environment variables

Server-only (never exposed to the browser):

| Name | Purpose |
|---|---|
| `API_BASE_URL` | Laravel base URL incl. `/api` (e.g. `http://127.0.0.1:8000/api`). Falls back to `NEXT_PUBLIC_API_BASE_URL` when unset. |
| `SESSION_COOKIE_SECRET` | signs/encrypts the session cookie (AES-256-GCM; **required in production**, min 16 chars) |
| `SESSION_COOKIE_NAME` | optional override |

Public (browser-safe) — only non-sensitive values:

| Name | Purpose |
|---|---|
| `NEXT_PUBLIC_APP_NAME` | display name |

Rules: never put the JWT, client secret or any token in a `NEXT_PUBLIC_*`
variable. Keep `.env.local` out of git; commit an `.env.example`.

---

## 14. Ordered implementation roadmap

Frontend Phase 1 (foundation) — recommended first step:

1. Scaffold `accounting-app/frontend` (Next.js App Router + TS + Tailwind v4).
2. Add design tokens (`globals.css`), theme provider, base `ui` primitives and
   the app shell (sidebar, topbar, responsive layout).
3. Implement the API client, envelope/error handling and the pagination adapter.
4. Implement the session BFF (login/logout/me + authenticated proxy) per §6 and
   central 401 handling.
5. Implement the login/forgot/reset/verify screens and the protected `(app)`
   guard.
6. Implement the company switcher and permission-aware navigation.
7. Establish the quality gates (`lint`, `typecheck`, `test`, `build`) and CI.

Then, feature-by-feature, following the backend module order (accounts → periods
→ journals → reports → customers/suppliers → invoices/bills → notes →
receipts/payments → cash/bank → reconciliation → tax → adjustments → fixed assets
→ currencies → budgets/dimensions), with the dashboard last once its data sources
are confirmed (blueprint §9).

**Status (as of Phase 7):** shipped — foundation/shell/auth/company context
(Phases 1–2), accounts (Phase 5), journals (Phase 6), cash & banking (Phase 7);
dashboard implemented in Phase 4. Still to build — periods, reports,
customers/suppliers, invoices/bills, notes, receipts/payments, reconciliation,
tax, adjustments, fixed assets, currencies, budgets/dimensions.

---

## 15. Open decisions (needs approval)

1. **Token storage:** BFF + httpOnly cookie (recommended) vs in-memory token.
   — **DECIDED (Phase 2): BFF + encrypted httpOnly cookie.**
2. **App location:** `accounting-app/frontend` (recommended) vs elsewhere.
3. **Data layer:** TanStack Query (recommended) vs hand-rolled hooks.
   — **DECIDED (Phases 3–6): hand-rolled hooks** (company-keyed runs +
   `AbortController`); revisit if a screen needs cross-screen caching.
4. **Forms:** react-hook-form + zod (recommended) vs native controlled forms.
   — **DECIDED (Phases 2–6): native controlled components** + small validators.
5. **Headless UI library:** none initially (recommended) vs Radix/Headless UI.
6. **Theme:** next-themes (recommended) vs custom provider.
7. **Trend charts:** **DECIDED (Phase 4): bounded per-month P&L calls** (max 12
   months, newest first), labelled when trimmed; revisit if a trends API ships.
8. **Backend follow-up (not frontend scope):** adopt pagination metadata
   (P2-1) and a consistent `per_page` cap.
9. **Accounts route/layout:** **DECIDED (Phase 5):** Chart of Accounts lives at
   `/accounts` with feature code in `src/lib/accounts/` + `src/components/accounts/`.
10. **Journals route/layout:** **DECIDED (Phase 6):** Journal Entries live at
    `/journals` with feature code in `src/lib/journals/` +
    `src/components/journals/`. Amounts are rendered as plain decimals (no
    currency symbol), because the session exposes no currency code
    (`SessionCompany` carries only `settings.default_currency_id`); foreign lines
    are labelled with their `currency_code`. The `source_type` enum mirrors the
    backend `JournalSource` enum exactly.
11. **Cash & banking route/layout:** **DECIDED (Phase 7):** Cash & Banking lives
    at `/cash-bank` (overview) and `/cash-bank/transactions` with feature code in
    `src/lib/cash-bank/` + `src/components/cash-bank/`. Classification and bank
    details are created and edited from the chart of accounts; this module manages
    an already-classified account and its bank-detail lifecycle. Balances come
    from the per-account ledger read
    (`GET /api/accounting/accounts/{account}/balance`, perm
    `accounting.ledger.view`) and are labelled "Ledger balance"; **no company-wide
    cash total** is shown because the account/transaction resources expose no
    currency. The account "detail" is the manage dialog plus a transactions list
    filtered by `?account_id` rather than a dedicated `/cash-bank/[id]` route.
    (The Phase 4 dashboard's client-side cash aggregate of §9.5 is separate and
    unchanged.)
12. **`JournalSource` enum correction:** **Phase 7 fix.** Phase 6 trimmed
    `JOURNAL_SOURCE_VALUES` to six values; the correct backend enum has **eleven**
    (`MANUAL, ADJUSTMENT, SALES_INVOICE, PURCHASE_BILL, CUSTOMER_RECEIPT,
    SUPPLIER_PAYMENT, CASH_BANK_TRANSACTION, CREDIT_DEBIT_NOTE, FIXED_ASSET,
    FIXED_ASSET_DEPRECIATION, FIXED_ASSET_DISPOSAL`). `src/lib/journals/types.ts`
    now mirrors all eleven, and the Phase 6 report §13.4 is corrected in
    `docs/FRONTEND_PHASE_7_REPORT.md`.
