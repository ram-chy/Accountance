# Frontend Phase 4 — Dashboard Foundation, Overview Widgets & Reporting UI Implementation Report

- **Date:** 2026-10-11
- **Scope:** Frontend Phase 4 only (the authenticated accounting dashboard at the
  `(app)` home route, plus the reporting-period and formatting infrastructure it
  needs). No other business screens.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked, per the brief. The backend
  was not modified (`git status --short -- accounting-app/backend` is empty).

## 1. Pre-implementation review

| Check | Result |
| --- | --- |
| Repository root / branch | `D:\Development\Accountance`, branch `main` (HEAD `6f8bc9b`) |
| `git status --short` before work | Phase 2/3 output uncommitted; malformed-path artifacts and `prompt/frontend/` untracked; nothing disturbed |
| Prior phases read | `FRONTEND_BLUEPRINT.md` (§9 dashboard plan, §11 charts), `FRONTEND_PHASE_3_REPORT.md`, `FRONTEND_PHASE_2_REPORT.md`, `FRONTEND_API_HANDOFF.md` |
| Backend contract | Re-verified against `routes/api.php`, `Accounting/{ReportController,LedgerController,CashBankAccountController,JournalController}.php`, the report services (`Reports/*`, `CashBank/*`) and `JournalResource`/`CashBankAccountResource` (see §3/§5) |

## 2. What Phase 4 adds

1. A **dashboard data layer** (`src/lib/dashboard/*`) that fetches, validates and
   shapes every figure the dashboard shows, using only existing endpoints.
2. **Overview widgets**: cash & bank balance, receivables, payables, income,
   expenses and net profit/loss, each with loading/error/empty/unavailable states.
3. **Two charts** (hand-rolled SVG): income-vs-expenses per month and an expense
   breakdown by account.
4. A **recent-activity** table of the latest posted journals for the period.
5. A **reporting-period selector** with fiscal-year-aware presets and a custom
   range.
6. A rewritten `(app)` home page that renders the dashboard behind the session
   guard the layout already enforces.

## 3. Verified backend facts the dashboard relies on

| Fact | Source |
| --- | --- |
| P&L, receivables, payables under `accounting/reports/*`; permission `accounting.reports.view` | `ReportController.php`, `routes/api.php` |
| Per-account ledger balance `accounting/accounts/{account}/balance`; permission `accounting.ledger.view` | `LedgerController.php` |
| Cash/bank accounts list `cash-bank-accounts`; permission `accounting.cash_bank.view` | `CashBankAccountController.php` |
| Journals list `journals?status=POSTED`; permission `journals.view` | `JournalController.php` |
| Base-currency block `{code,name,symbol,decimals}` on reports; amounts are decimal strings (≤4 dp) | `Reports/*` services |
| Fiscal-year start month `settings.default_fiscal_year_start_month` | `CompanySettingResource` (surfaced via session) |
| Envelope `{success,message,data?}`; `X-Request-Id` on every response | `ApiResponse.php`, `AssignRequestId.php` |

All requests go through the Phase 3 proxy (`backendGet` → `/api/backend/*`), so
the bearer token and `X-Company-Id` stay server-side and a `401` follows the
central session-expiry flow.

## 4. Data layer (`src/lib/dashboard/`)

- **`types.ts`** — contracts mirroring the verified responses
  (`ProfitLossReport`, `SettlementTotalsReport`, `AccountBalance`,
  `CashBankAccount`, `JournalActivity`, `TrendPoint`, `CashBankTotal`) plus the
  `Loadable<T>` union (`loading | ready | error | unavailable`) every slice uses.
- **`api.ts`** — thin `backendGet` wrappers per endpoint (returns `unknown`).
- **`validators.ts`** — runtime narrowing (`parseProfitLoss`, `parseReceivables`,
  `parsePayables`, `parseAccountBalance`, `parseCashBankAccounts`,
  `parseJournals`). Required amounts missing/malformed → `DashboardDataError`;
  optional collection rows are filtered, so one bad row cannot blank a report.
- **`period.ts`** — pure date arithmetic: presets (this/last month, this quarter,
  this/previous financial year, last 12 months, custom), `financialYearRange`
  honouring the company fiscal start (fallback: calendar year), `monthBuckets`
  (clamped at the range ends) and `formatRangeLabel`.
- **`format.ts`** — `sumDecimalStrings` (BigInt fixed-point, no floating point),
  `formatMoneyValue` (defers to the shared precision-safe `formatMoney`),
  `formatCompactNumber` and `toDisplayNumber` (display-only proportions).
- **`use-dashboard-data.ts`** — orchestrates all calls per (company, period,
  permissions): each metric is independent (`Promise.allSettled`), a single
  `AbortController` per run guards every state write, permission-less slices are
  reported `unavailable` and never requested, and loading/no-company states are
  derived during render rather than written synchronously from the effect.

## 5. Handling the two documented backend gaps (blueprint §9.2)

The API has **no trend endpoint** and **no company-wide cash/bank balance
endpoint**. Both are handled with the bounded approaches the blueprint permits,
and both carry a user-visible caveat rather than fabricated data:

- **Income-vs-expenses trend:** derived from per-month `profit-loss` calls capped
  at `MAX_TREND_MONTHS = 12` (most recent months of the selected window). When the
  window exceeds the cap, the chart footnote states that only the latest 12 months
  are shown.
- **Cash & bank total:** summed client-side from the per-account ledger balances
  of the `cash-bank-accounts` list, using BigInt fixed-point arithmetic (never
  `Number`). Requires both `accounting.cash_bank.view` and
  `accounting.ledger.view`; the card is hidden otherwise.

This is recorded in `docs/FRONTEND_BLUEPRINT.md` §9.2/§9.5 as a follow-up for a
future aggregation endpoint; **no endpoint was invented**.

## 6. UI components (`src/components/dashboard/`)

- `dashboard-view.tsx` — the orchestrator; arranges the cards, charts and activity
  table, and gates metrics by role.
- `dashboard-page-header.tsx`, `reporting-period-selector.tsx` — title, company
  context and period control (presets resolve against `new Date()`; editing a
  date switches to a custom range).
- `financial-summary-grid.tsx` / `financial-summary-card.tsx` — the six overview
  metrics, each rendering loading / ready / error / unavailable, and a missing
  currency as a plain amount (never a guessed symbol).
- `financial-chart-card.tsx`, `income-expense-chart.tsx`,
  `expense-breakdown-chart.tsx` — panels and the two hand-rolled SVG charts
  (native `<title>` tooltips, an `aria-label`, a `sr-only` `figcaption` and a
  legend; colours from CSS variables).
- `recent-activity-table.tsx` — latest posted journals; status conveyed by text as
  well as colour.
- `states.tsx` — skeleton placeholders.

The `(app)` layout already blocks server-side without a session cookie
(`instant = false`), so `src/app/(app)/page.tsx` is a small server component that
exports page `metadata` and renders `<DashboardView/>`.

## 7. Verification performed

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npx eslint .` | Pass, 0 problems |
| Type-check | `npx tsc --noEmit` | Pass, 0 errors |
| Unit tests | `npm test` (Vitest) | Pass, **97 tests / 11 files** (was 62/7) |
| Build | `npm run build` | Pass; `/` remains dynamic (`ƒ`); session and backend routes unchanged |

New tests: `src/lib/dashboard/period.test.ts` (13), `format.test.ts` (10),
`validators.test.ts` (10), and `src/components/dashboard/dashboard-view.test.tsx`
(2, mocking `useAuth`/`usePermissions`/`useDashboardData`).

**Not verified:** a live Laravel instance (no PHP/DB in this environment).
Response shapes are covered by validator unit tests against fixtures taken from
the verified services; the view is covered by a component test with mocked data.

## 8. Deviations / notes

- **No charting library.** Charts are hand-rolled SVG; the blueprint's Recharts
  decision is superseded and annotated (§1/§11). This avoids a dependency the
  simple bar charts do not need.
- **Dashboard lives at the home route** (`(app)/page.tsx`), not a new
  `/dashboard` route; the nav already labels it "Home".
- **Client-side fetching** via `backendGet` (not server components) so the
  automatic 401 handling, permission gating and company-switch isolation are
  shared with the rest of the app.
- `use-dashboard-data.ts` derives loading/no-company state during render to
  satisfy `react-hooks/set-state-in-effect` while keeping the async fetch effect
  intact.

## 9. Files added/changed

- **Added:** `src/lib/dashboard/{types,api,validators,period,format,use-dashboard-data}.ts`,
  their `.test.ts` files, `src/components/dashboard/*` (components + tests), and
  this report.
- **Changed:** `src/app/(app)/page.tsx` (now the dashboard host),
  `docs/FRONTEND_BLUEPRINT.md` (§1, §9.5, §11, §15).
- **Untouched:** `accounting-app/backend/**`, `FRONTEND_API_HANDOFF.md` (reads
  only), Phase 0–3 reports, `prompt/frontend/`.

## 10. Recommended next steps

1. First full data module (chart of accounts) reusing `lib/api/backend.ts` and the
   `Loadable`/validator patterns established here.
2. Introduce a query cache (TanStack Query) and forms (react-hook-form + zod) with
   that screen; invalidate on company switch.
3. Revisit the trend and cash/bank-total workarounds if the backend adds
   aggregation endpoints (blueprint §9.2).
