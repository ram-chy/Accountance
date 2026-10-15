# Accountance Frontend — Phase 19 Report

**Phase:** 19 — Budgets & Dimensions
**Scope:** Frontend only
**Date:** 2026-10-15
**Report path:** `accounting-app/docs/FRONTEND_PHASE_19_REPORT.md`

> **Git note.** The Phase 19 brief prohibits Git commit and push (§2.2 "Prohibited Git operations", §15 "Final Stop Condition"). No commit was created, no push was performed, and no other prohibited Git operation was run. All Phase 19 work remains uncommitted as modified and untracked files for the operator to review and commit if desired.

> **Verification legend used throughout.**
> **[source]** verified by reading the Laravel backend source.
> **[tests]** verified by the frontend automated test suite.
> **[build]** verified by a successful production build (routes emitted).
> **[live]** verified against a running backend/database — **none of Phase 19 was verified this way.**

---

## 1. Executive summary

Phase 19 delivered the Budgets & Dimensions area end to end against the verified Laravel API, frontend-only:

- **Dimensions** (`/dimensions`, `/dimensions/[id]`) — list, create, edit, activate/deactivate, and manage values (code/name, activate/deactivate, delete). Dimensions are company-scoped analytical axes (type: `COST_CENTER`, `PROJECT`, `DEPARTMENT`, `LOCATION`), and the `type` is fixed at creation and shown read-only on edit. Lifecycle controls are permission-gated, and backend refusals (e.g. a value/dimension referenced by journals or budget lines) are surfaced verbatim. [source][tests][build]
- **Budgets** (`/budgets`, `/budgets/[id]`) — list, create, edit, and lifecycle actions: approve (draft), revise (approved into a new draft version), and delete (draft). Budget lines are managed on the detail page: create, edit, delete, with per-line analytical label assignments via one picker per active dimension. Only Revenue/Expense accounts are offered; periods are scoped to the budget's own financial year. Amounts stay decimal strings. [source][tests][build]
- **Budget Variance reporting** (`/reports/budget-variance`) — a single-budget report comparing plan to posted actuals, with budget selector and optional dimension filter (dimension + dimension value). Filters seed from URL (`budget_id`, `dimension_id`, `dimension_value_id`) via `useSearchParams` (mirroring the existing general-ledger view pattern), with Apply/Clear. The UI renders backend-calculated values (budget/actual/variance, variance percentage, statuses and counts) and shows "n/a" when the plan is zero (variance percentage is null). A "View variance" link from each budget row/list/detail opens the report seeded with that budget. [source][tests][build]
- **Navigation** — an additive "Budgets & dimensions" section (Budgets, Dimensions, Budget variance) placed after "Multi-currency", with permission keys: `accounting.budgets.view` for Budgets and Budget variance; `accounting.dimensions.view` for Dimensions. Budget variance is gated on `accounting.budgets.view` per the backend policy. [source][tests]
- **Tests** — API clients, validators, and component tests for the new features. The full suite is **97 files / 957 tests, all passing**; Phase 19 added **5 test files (74 tests total additions)**: `lib/dimensions/api.test.ts` (10), `lib/dimensions/validators.test.ts` (17), `lib/budgets/api.test.ts` (13), `lib/budgets/validators.test.ts` (23), `components/reports/budget-variance-view.test.tsx` (11). Navigation tests were extended to cover the new section (+3 assertions). [tests]

**Scope boundary.** This is frontend-only. No backend file, dependency, lockfile, or prior report was changed. Uncommitted Phase 17/18 work is preserved. Live integration was **not** performed and is not claimed.

All quality gates pass (ESLint, TypeScript, Vitest, production build). No gate was weakened.

---

## 2. Initial branch, HEAD, and Git status

- Repository root: `D:\Development\Accountance`
- Branch: `main` (`main...origin/main`)
- HEAD: `4cb1614` — "feat(frontend): Phase 17 fixed assets foundation (types and validators)"

At the start of Phase 19, the working tree contained uncommitted Phase 17/18 work and prior untracked files (preserved). The final `git status --short` after Phase 19 is described in §14.

---

## 3. Pre-existing changes identified before implementation

Before modifying, the tree included:
- **Modified**: `accounting-app/frontend/src/lib/fixed-assets/validators.ts`, `accounting-app/frontend/src/lib/navigation/nav.test.ts`, `accounting-app/frontend/src/lib/navigation/nav.ts` (Phase 17/18 footprint). 
- **Deleted**: `"prompt/frontend Prompt/Phase8.md"`. 
- **Untracked**: Phase 17/18 app/lib/component directories (e.g. `app/(app)/fixed-assets/`, `components/fixed-asset-categories/`, `components/fixed-assets/`, `lib/fixed-asset-categories/*`, `lib/fixed-assets/*`), Phase 17/18 reports (`docs/FRONTEND_PHASE_17_REPORT.md`, `docs/FRONTEND_PHASE_18_REPORT.md`), multi-currency additions from Phase 18, and unrelated prompt directories/files (`"prompt/frontend Prompt/Phase 1"`, `"prompt/frontend Prompt/Phase 18.md"`, `"prompt/frontend Prompt/Phase 8.md"`). The brief itself (`"prompt/frontend Prompt/Phase 19..md"`) was also present.

None of these pre-existing items were modified or deleted during Phase 19 (only `nav.ts` and `nav.test.ts` were modified to add the Phase 19 section/tests, which is appropriate and additive). All other pre-existing modified/untracked files remain exactly as found. [source: git status]

---

## 4. Verified budget and dimension API contracts

All contracts below are verified from the backend [source]. The BFF path is `backendGet/Post/Put/Delete("accounting/...")` → `/api/backend/accounting/...`; the API wrapper unwraps the `{success, data, message?}` envelope and the list responses are Laravel paginators (shape `{data, meta}`) or flat arrays in some cases; the validators normalise via `normalizeList`.

### Dimensions (FinancialDimension / FinancialDimensionValue)

- Endpoints (company-scoped via context; `company_id` never sent by client):
  - `GET /accounting/dimensions` — list (perm `accounting.dimensions.view`). Filters: `type` ∈ `COST_CENTER|PROJECT|DEPARTMENT|LOCATION`; `is_active` (three-state: absent = all, `"1"` = active, `"0"` = inactive); `search`; `per_page`, `page`. [source]
  - `POST /accounting/dimensions` — create (201, perm `accounting.dimensions.create`). Body: `{type, code, name}`. [source]
  - `GET /accounting/dimensions/{dimension}` — show (view). Returns dimension with `values` when loaded. [source]
  - `PUT /accounting/dimensions/{dimension}` — update (perm `accounting.dimensions.update`). Body: `{code?, name?}` (type is **not editable**). [source]
  - `DELETE /accounting/dimensions/{dimension}` — delete (perm `accounting.dimensions.delete`). Refused while any value is referenced. [source]
  - `POST /accounting/dimensions/{dimension}/activate` — (perm `accounting.dimensions.update`) no body. [source]
  - `POST /accounting/dimensions/{dimension}/deactivate` — (perm `accounting.dimensions.update`) no body. [source]
  - Values nested: `GET /accounting/dimensions/{dimension}/values` (filters `is_active`, `search`, `per_page`, `page`), `POST /accounting/dimensions/{dimension}/values` (201, `{code,name}`; parent must be active), `PUT /accounting/dimensions/{dimension}/values/{value}` (`{code,name}`), `DELETE /accounting/dimensions/{dimension}/values/{value}` (refused while referenced), `POST /accounting/dimensions/{dimension}/values/{value}/activate|deactivate` (update perm). [source]

- Resources [source: `FinancialDimensionResource`, `FinancialDimensionValueResource`]:
  - `FinancialDimension`: `id, type, type_label, code, name, is_active, values?[], values_count?, created_at, updated_at`
  - `FinancialDimensionValue`: `id, financial_dimension_id, code, name, is_active, created_at, updated_at`

- Rules [source]: `type` fixed on update; `is_active`/`company_id` server-owned and never sent; values addressed through parent (tenant/nesting check); lifecycle refusal surfaced on delete/transition when in use.

### Budgets

- Endpoints (company-scoped via context; server-owned fields never sent by client):
  - `GET /accounting/budgets` — list (perm `accounting.budgets.view`). Filters: `financial_year_id`, `status` ∈ `DRAFT|APPROVED`, `search`, `per_page`, `page`. [source]
  - `POST /accounting/budgets` — create (201, perm `accounting.budgets.create`). Body: `{financial_year_id, code, name, notes?}`; server sets `DRAFT`, `version_number=1`. [source]
  - `GET /accounting/budgets/{budget}` — show (view). Loads `financial_year` and `lines` (each line may include `account`, `accounting_period`, `dimensions`). [source]
  - `PUT /accounting/budgets/{budget}` — update (draft-only, perm `accounting.budgets.update`). Body: `{code?, name?, notes?}` (financial year is fixed). [source]
  - `DELETE /accounting/budgets/{budget}` — delete (draft-only, perm `accounting.budgets.delete`). [source]
  - `POST /accounting/budgets/{budget}/approve` — approve (perm `accounting.budgets.approve`), no body; refuses if budget has no lines. [source]
  - `POST /accounting/budgets/{budget}/revise` — revise (approved-only, rides `accounting.budgets.create` effectively; perm `accounting.budgets.create` required). Body: `{name?, notes?}` (201 creates a new draft version, copies lines and labels). [source]
  - Budget lines: `POST /accounting/budgets/{budget}/lines` (201, draft-only; perm `accounting.budgets.update`). Body: `{account_id, accounting_period_id, amount (non-negative decimal string), description?, dimensions?[]}` where `dimensions = {dimension_id, value_id}[]`. [source]
    - `PUT /accounting/budgets/{budget}/lines/{line}` (draft-only, update perm). All optional; a present `dimensions` — even `[]` — **replaces** the whole label set. [source]
    - `DELETE /accounting/budgets/{budget}/lines/{line}` (draft-only). [source]

- Variance report: `GET /accounting/budgets/{budget}/variance` (perm `accounting.budgets.view`). Query: `dimension_id`, `dimension_value_id` (optional; null/absent = no filter). Response is the `BudgetVarianceReport` payload described in types/validators (§5/6). [source: `BudgetVarianceReportService.php`]

- Resources and shapes [source]: `BudgetResource` includes `company_id, financial_year_id, code, name, version_number, parent_budget_id?, status (DRAFT|APPROVED), notes?, created_by?, updated_by?, approved_by?, approved_at?, financial_year?, lines?, lines_count?, created_at, updated_at`. `BudgetLineResource`: `id, budget_id, account_id, accounting_period_id, amount (decimal string), description?, account?, accounting_period?, dimensions?[], created_at, updated_at`. `BudgetLineDimensionAssignment`: `{dimension_id, value_id}`.

### Permissions
- `accounting.budgets.view`, `accounting.budgets.create`, `accounting.budgets.update`, `accounting.budgets.delete`, `accounting.budgets.approve`. Revise rides `create`; budget-line endpoints authorize via `update` policy. Budget variance authorized via `view` on the budget. [source]
- `accounting.dimensions.view`, `accounting.dimensions.create`, `accounting.dimensions.update`, `accounting.dimensions.delete`. Activate/deactivate ride `update`. [source]

### Accounting/precision rules (verified)
- Amounts are canonical decimal strings (4-decimal precision for budget amounts in resource context; variance values are decimal strings). Never converted to JS `Number` for arithmetic. `isDecimalString` used in validators. [source]
- Variance: `variance = actual - budget`. Favourability: revenue account (credit-normal) — positive variance is favourable; expense account (debit-normal) — negative variance is favourable. `status` ∈ `FAVOURABLE|UNFAVOURABLE|ON_TARGET`. Net status computed as if revenue. `variance_percentage = (variance/budget)*100` as 4-decimal string, or **null** when plan is zero (cannot divide by zero). Both sides evaluated on the account's normal side. Summary aggregates within revenue/expenses sections only. [source: `BudgetVarianceReportService.php`]

---

## 5. Exact endpoints, payloads, response shapes, filters, permissions used

**BFF base:** all calls go to `/api/backend/accounting/*` via the same-origin proxy (JWT HttpOnly cookie; server company context; no `company_id` sent). Requests use `AbortController`; list responses normalised via `normalizeList`.

### Dimensions (frontend)

- `backendGet("accounting/dimensions", { query: {type?, is_active?("1"|"0"), search?, per_page?, page?}, signal?})` → `fetchFinancialDimensions`. Maps `is_active === undefined` → omit (all); otherwise `"1"`/`"0"`. [api.ts:30-57]
- `backendGet(\`accounting/dimensions/${id}\`, {signal?})` → `fetchFinancialDimension` (loads values). [59-62]
- `backendPost("accounting/dimensions", payload)` → create `{type, code, name}`. [64-67]
- `backendPut(\`accounting/dimensions/${id}\`, payload)` → update `{code?, name?}`. [69-75]
- `backendDelete(\`accounting/dimensions/${id}\`)`. [77-80]
- `backendPost(\`accounting/dimensions/${id}/activate\`)` and `/deactivate`. [82-90]
- Values: `backendGet(\`accounting/dimensions/${id}/values\`, {query: {is_active?, search?, per_page?, page?}, signal?})`. `backendPost(.../values, {code,name})`. `backendPut(.../values/${valueId}, {code,name})`. `backendDelete(.../values/${valueId})`. Activate/deactivate POST bodyless. [92-131]

### Budgets (frontend)

- `backendGet("accounting/budgets", { query: {financial_year_id?, status?, search?, per_page?, page?}, signal?})` → filters compacted (omit undefined). [api.ts:41-54]
- `backendGet(\`accounting/budgets/${id}\`, {signal?})`. [56-59]
- `backendPost("accounting/budgets", compact(payload))` → create `{financial_year_id, code, name, notes:string|null}`. [61-64]
- `backendPut(\`accounting/budgets/${id}\`, compact(payload))` → update `{code?, name?, notes?}`. [66-69]
- `backendDelete(\`accounting/budgets/${id}\`)`. [71-74]
- `backendPost(\`accounting/budgets/${id}/approve\`)`. [76-79]
- `backendPost(\`accounting/budgets/${id}/revise\`, compact(payload))` → `{name?, notes?}`. [81-84]
- Variance: `backendGet(\`accounting/budgets/${id}/variance\`, {query: {dimension_id?, dimension_value_id?}})` (both omitted if null). [86-97]
- Lines: `backendPost(\`accounting/budgets/${budgetId}/lines\`, compact({... payload with dimensions:[] }))`. [99-101]
- `backendPut(\`accounting/budgets/${budgetId}/lines/${lineId}\`, compact(payload))` (dimensions present = replace). [104-111]
- `backendDelete(\`accounting/budgets/${budgetId}/lines/${lineId}\`)`. [113-116]

### Validators/parsers (runtime safety)
- Dimensions: parse functions `parseFinancialDimension`, `parseFinancialDimensionValue`, list/detail/value-list parsers returning states `{status,...}`, throw helpers, and `validateFinancialDimensionCreate/Update` and `validateFinancialDimensionValueWrite` (trimmed required code/name; type required on create). Rows missing required fields or unknown type are dropped (never fabricated). [validators.ts:1-242]
- Budgets: parse functions for `Budget`, `BudgetLine`, `BudgetLineDimensionAssignment`, list/detail states, and full `parseBudgetVariance` with field-level parsers for sections/lines/counts (treats `variance_percentage` as `string | null` when null; else string). Validators: `validateBudgetCreate/Update/Revise`, `validateBudgetLineCreate/Update`; amounts checked as non-negative decimal via `NON_NEGATIVE_DECIMAL = /^\+?\d+(\.\d+)?$/` (no JS Number). [validators.ts:1-552]

---

## 6. Implemented routes, components, API clients, validators, hooks, navigation changes

### Routes (App Router, `(app)` group) — emitted by build
- `/dimensions` — `app/(app)/dimensions/page.tsx` (metadata: "Dimensions") → `<DimensionsView />`. [build]
- `/dimensions/[id]` — `app/(app)/dimensions/[id]/page.tsx` (metadata: "Dimension"); parses id, calls `notFound()` if non-integer/<=0 → `<DimensionDetailView dimensionId={id} />`. [build]
- `/budgets` — `app/(app)/budgets/page.tsx` → `<BudgetsView />`. [build]
- `/budgets/[id]` — `app/(app)/budgets/[id]/page.tsx`; id parsing + notFound → `<BudgetDetailView budgetId={id} />`. [build]
- `/reports/budget-variance` — `app/(app)/reports/budget-variance/page.tsx` → `<BudgetVarianceView />`. [build]

### Components

**Dimensions:**
- `dimensions/dimensions-view.tsx` — list view with search, type filter (`all`/4 types), active filter (`all/active/inactive`), pagination, create/edit/delete + activate/deactivate. Uses `useFinancialDimensions`, permission gates (`accounting.dimensions.view|create|update|delete`), shows toasts on transitions, surfaces backend errors verbatim. [components/dimensions/dimensions-view.tsx]
- `dimensions/dimension-detail-view.tsx` — shows dimension identity/type/status, manages its values (list with search/status, create/edit/delete + activate/deactivate), pagination, error/empty/loading states, permission gates. [dimension-detail-view.tsx]
- `dimensions/dimension-form-dialog.tsx` — create/edit dimension; `type` selectable only on create, read-only on edit (mirrors backend); code/name required, validates before submit. [dimension-form-dialog.tsx]
- `dimensions/dimension-value-form-dialog.tsx` — create/edit value under dimension; code/name required. [dimension-value-form-dialog.tsx]
- `dimensions/dimension-type-badge.tsx` — renders type as chip with backend `type_label` fallback; variants per type. [dimension-type-badge.tsx]

**Budgets:**
- `budgets/budgets-view.tsx` — list with search, financial year filter, status filter (`all/DRAFT/APPROVED`), pagination, "New budget", and per-row actions: Edit (draft), Approve (draft), Revise (approved), Variance (link), Delete (draft). Uses `useBudgets`, `useFinancialYearOptions`. [budgets-view.tsx]
- `budgets/budget-detail-view.tsx` — shows budget identity/lifecycle/notes, lines table (account, period, amount, dimensions, description), add/edit/delete line (draft only), top actions: View variance (link), Edit (draft), Approve (draft), Revise (approved), Delete (draft). Resolves dimension labels via `useDimensionLabelMap`. No totals computed in browser. [budget-detail-view.tsx]
- `budgets/budget-form-dialog.tsx` — create/edit budget; financial year fixed on edit, required code/name, notes nullable. [budget-form-dialog.tsx]
- `budgets/budget-line-form-dialog.tsx` — create/edit line: account picker offers only **active Revenue/Expense** accounts (filtered via `useAccountTypeOptions`), period picker scoped to budget's year via `useAccountingPeriodOptions`, amount non-negative decimal string, description nullable, dimensions via `BudgetLineDimensionsField`. Managed dimensions = assignments whose dimension is active (stale retired-dimension labels are dropped on save). [budget-line-form-dialog.tsx]
- `budgets/budget-line-dimensions-field.tsx` — one select per **active** dimension (child component per dimension so `useFinancialDimensionValueOptions` is called once per instance, not in a loop); offers only active values; normalises a stored value that became inactive by clearing it on load. Submits full set (present `dimensions` replaces). [budget-line-dimensions-field.tsx]
- `budgets/budget-action-dialogs.tsx` — `ApproveBudgetDialog` (confirms; backend refuses empty), `ReviseBudgetDialog` (optional name/notes), `DeleteBudgetDialog` (draft only). Toasts on success, verbatim backend errors. [budget-action-dialogs.tsx]
- `budgets/budget-status-badge.tsx` — DRAFT (warning) vs APPROVED (success). [budget-status-badge.tsx]

**Reports:**
- `reports/budget-variance-view.tsx` — filter bar: budget select, dimension select (active only), dimension value select (active only, disabled until dimension chosen, resets value on dimension change), Apply/Clear. Seeds initial filters from URL via `useSearchParams` (`readBudgetVarianceFilters`). Shows header (budget code/name/version/status, link back to budget, financial year, period range), dimension filter summary if applied, summary cards (Revenue, Expenses, Net with net status badge), counts card (Favourable/Unfavourable/On Target with semantic colors), lines table with Account/Period/Budget/Actual/Variance/Variance%/Status. `variance_percentage === null` → renders `"n/a"`. All figures are backend values; decimals from `base_currency.decimals`. States: loading/ready/error/notfound/unavailable; retry on error. [budget-variance-view.tsx]

### API clients (lib)
- `lib/dimensions/api.ts` — full CRUD + values + activate/deactivate, with query mapping (three-state `is_active`). [api.ts]
- `lib/budgets/api.ts` — CRUD + approve/revise + lines + variance, with `compact()` to drop undefined on writes. [api.ts]
- `lib/periods/use-period-options.ts` (additive) — walks pages of `GET /accounting/periods?financial_year_id=` (max 20 pages × 100) to build options for a single year (for budget line picker); sorts by start date; additive, no changes to existing periods code. [use-period-options.ts]

### Validators (lib)
- `lib/dimensions/validators.ts` — parse/throw/list/detail/value-list; create/update/value-write validation (trimmed required). [validators.ts]
- `lib/budgets/validators.ts` — parse budget/line/variance with field-level guards; `parseBudgetVariance` maps `variance_percentage` to `string | null` correctly (null when raw is null); input validators use `NON_NEGATIVE_DECIMAL` regex, never `Number`. Exports `isPositiveBudgetAmount`. [validators.ts]

### Hooks (lib)
- Dimensions: `use-dimensions.ts` (paginated list), `use-dimension.ts` (detail), `use-dimension-values.ts` (values), `use-dimension-options.ts` (all dimensions for pickers), `use-dimension-value-options.ts` (values for a dimension), `use-dimension-label-map.ts` (builds Map `{dimensionId:valueId}` → label by walking dimensions then each dimension's values; page-walk with caps). [hooks]
- Budgets: `use-budgets.ts` (paginated list), `use-budget.ts` (detail + lines), `use-budget-options.ts` (picker list, walks pages, sorts by code desc version), `use-budget-variance.ts` (single-budget report with dimension filter; maps 404→notfound, 403→unavailable). [hooks]
- Periods: `useAccountingPeriodOptions` in `lib/periods/use-period-options.ts`. [hook]

### Navigation changes
- `lib/navigation/nav.ts` — added additive section `"Budgets & dimensions"` after `"Multi-currency"` with:
  - Budgets → `/budgets`, permission `accounting.budgets.view`
  - Dimensions → `/dimensions`, permission `accounting.dimensions.view`
  - Budget variance → `/reports/budget-variance`, permission `accounting.budgets.view` (gated on budgets.view per backend policy). [nav.ts:195-214]
- `lib/navigation/nav.test.ts` — extended tests to assert the new section items/permissions (+3 assertions). No existing tests removed/altered. [nav.test.ts]

### Tests added
- `lib/dimensions/api.test.ts` (10) — fetch list/create/update/delete, activate/deactivate, values CRUD + activate/deactivate, query param mapping for `is_active` (undefined omits, true→"1", false→"0"), bodyless POST subresources. [tests]
- `lib/dimensions/validators.test.ts` (17) — valid/invalid rows, list/detail/value-list edge cases, create/update/value validation. [tests]
- `lib/budgets/api.test.ts` (13) — budgets CRUD, approve/revise, lines CRUD, variance with dimension filter params (omits null), uses compacted payloads. [tests]
- `lib/budgets/validators.test.ts` (23) — parse/validate budget/line/variance; verifies `variance_percentage` null handling and decimal-string checks. [tests]
- `components/reports/budget-variance-view.test.tsx` (11) — URL seeding (`budget_id`/`dimension_id`/`dimension_value_id`), filter application, empty states, permission gate, variance percentage "n/a" when null, summary rendering. [tests]
- `lib/navigation/nav.test.ts` — +3 tests for new section. [tests]

**Test totals (final):** 97 files / 957 tests passed. [tests]

---

## 7. Budget Variance and dimension-filter behavior

### Budget Variance
- **Single-budget report**: `GET /accounting/budgets/{budget}/variance` (no standalone global variance endpoint). Frontend requires a budget selection before loading and calls the per-budget endpoint. [api.ts:86-97], [use-budget-variance.ts]
- **Filters**: optional `dimension_id`, `dimension_value_id`. When null/absent, query omits keys (server means "all"). [api.ts:92-97]
- **URL seeding**: `BudgetVarianceView` reads `budget_id`, `dimension_id`, `dimension_value_id` from `useSearchParams()` via `readBudgetVarianceFilters()` on initial render (mirrors General Ledger view pattern). The form draft/applied states follow Apply/Clear pattern (Apply sets applied from draft; Clear resets both). [budget-variance-view.tsx:90-103, 227-267]
- **Dimension filter UI**: dimension select shows active dimensions only; value select shows active values only, disabled when no dimension selected, and resets value_id to null when dimension changes. Filter summary displayed when applied. [budget-variance-view.tsx:292-335, 427-434]
- **Backend-authoritative values**: budget/actual/variance, `variance_percentage` (string or null), statuses (`FAVOURABLE|UNFAVOURABLE|ON_TARGET`), counts are rendered verbatim from parsed report. No client-side percentage/total calculation. [budget-variance-view.tsx:177-179, 437-476]
- **Null percentage**: when `variance_percentage` is `null`, table shows `"n/a"`. [budget-variance-view.tsx:178]
- **Variance semantics**: favourability determined by account type per backend (revenue positive favourable, expense negative favourable). Net status computed as if revenue. [types.ts:33-45], [validators.ts:310-362], [budget-variance-view.tsx:55-66]
- **Navigation from budget**: budgets list/detail include "Variance" link to `/reports/budget-variance?budget_id=<id>`. [budgets-view.tsx:309-315], [budget-detail-view.tsx:318-320]

### Dimension filters
- Dimensions are the analytical axes; budget lines carry assignments `{dimension_id, value_id}`. The variance endpoint supports filtering by a single dimension/value pair (as above). The frontend sends only selected valid ids and omits nulls. Reset/Clear removes only those filters. [api.ts:86-97], [budget-variance-view.tsx:258-267, 427-434]

---

## 8. Accounting precision and currency-handling decisions

- **Decimal strings everywhere**: budget `amount`, variance `budget/actual/variance`, `variance_percentage` are stored/rendered as strings. Validators use regex (no `Number` coercion). [types.ts:91, 247-253], [validators.ts:488-498]
- **No client arithmetic**: no authoritative totals computed in browser; percentages are backend values (null when plan zero). [budget-detail-view.tsx:40-42], [budget-variance-view.tsx:177-179]
- **Base currency**: variance report includes `base_currency` (code/name/symbol/decimals). UI uses `decimals ?? MONEY_DISPLAY_DECIMALS` for formatting display values. [budget-variance-view.tsx:399-400]
- **Active-only pickers**: dimension and value selects filter to `is_active === true` to avoid assigning retired labels (with normalisation if stored value became inactive). [budget-variance-view.tsx:242-248], [budget-line-dimensions-field.tsx:59-67, 106-113]
- **Label filtering on save**: budget line form filters `assignments` to those whose `dimension_id` is in `activeDimensionIds` before submit (so retired-dimension labels are not sent). [budget-line-form-dialog.tsx:125-159]
- **Canonical formatting**: money formatting via existing utilities (`formatMoney`, `formatDecimal`), dates via `formatDateOnly`, `formatTimestamp`. [budget-variance-view.tsx:28-30], [budget-detail-view.tsx:21-23]

---

## 9. Tests added and total test-suite results

**Tests added (Phase 19):**
- `src/lib/dimensions/api.test.ts` — 10 tests
- `src/lib/dimensions/validators.test.ts` — 17 tests
- `src/lib/budgets/api.test.ts` — 13 tests
- `src/lib/budgets/validators.test.ts` — 23 tests
- `src/components/reports/budget-variance-view.test.tsx` — 11 tests
- `src/lib/navigation/nav.test.ts` — extended (+3 assertions)

**Total suite:** 97 files / 957 tests passed (all passing). [tests] (from `npx vitest run` final output)

---

## 10. Lint, strict ESLint, TypeScript, Vitest, and build results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
|---|---|---|
| ESLint | `npm run lint` | **0 problems** (clean) [lint] |
| Strict ESLint | `npx eslint . --max-warnings=0` | **0 problems** (clean) [eslint] |
| TypeScript | `npx tsc --noEmit` | **0 errors** [tsc] |
| Vitest | `npx vitest run` | **97 files / 957 tests passed** [tests] |
| Build | `npm run build` | **Succeeded**; routes emitted: `/budgets`, `/budgets/[id]`, `/dimensions`, `/dimensions/[id]`, `/reports/budget-variance` [build] |

No ESLint rule disabled, no TypeScript check weakened. All gates pass.

---

## 11. Live integration status

**Not performed.** No running backend or database was exercised; every claim is **[source]** (read from the backend), **[tests]**, or **[build]** (frontend automation). Live integration ([live]) is not claimed for any part of Phase 19.

---

## 12. Unsupported features and backend contract gaps

Verified against actual backend contracts; nothing unsupported was fabricated:

1. **Dimension type immutable on edit** — backend accepts only `code/name` on update; UI shows type read-only on edit and never sends it. [source][ui]
2. **is_active/company_id server-owned** — never sent by client forms. [source]
3. **Delete refusals** — dimensions/values and budgets/lines can be refused when referenced or in wrong state; UI surfaces backend's verbatim 422/403/409 errors (no pre-guessing). [source][ui]
4. **Approve requires non-empty budget** — backend refuses empty budget; dialog explains and relies on backend. [source]
5. **Revise rides create** — creates new draft version (copies lines/labels), approved version untouched. [source]
6. **Line dimensions replace on PUT** — a present `dimensions` (even `[]`) replaces whole set; UI builds full managed set. [source]
7. **Variance percentage null on zero plan** — rendered as `"n/a"` (not 0%). [source][ui]
8. **No standalone global variance endpoint** — only per-budget `GET /accounting/budgets/{budget}/variance`; UI enforces budget selection. [source]
9. **P&L dimension filter** — not added. Existing P&L behavior preserved; backend supports `dimension_id`/`dimension_value_id` on some report endpoints in general, but Phase 19 did not modify P&L. Documented as future work if needed. [source]
10. **Base currency read endpoint** — still not exposed in a simple company settings GET in the relevant surface (not required for this phase); variance returns `base_currency` in report payload. [source]

No other gaps identified; unsupported capabilities were not implemented.

---

## 13. Known limitations and suggested future work

- **Unverified against live server** — behavior derived from source + frontend tests. Field drift would be filtered/rejected by validators rather than mis-rendered, but end-to-end wiring unconfirmed. [live absent]
- **Label map walks pages per dimension** — `useDimensionLabelMap` loads all dimensions and all values (paginated with caps 20×100). For very large sets this is a small upfront cost; acceptable for typical company sizes. Could be optimised later with a bulk endpoint if added. [hook]
- **Period options walked for single year** — same pagination strategy (20×100). [hook]
- **Budget options picker walked** — loads all budgets for company (paginated). [hook]
- **No optimistic updates** — list/detail reload after mutations (explicit reload token), consistent with existing app patterns. [ui]
- **Stale retired labels** — dropped on save and warned in form; not auto-purged server-side except by save (correct behavior). [ui]

**Suggested future work:**
- Add bulk dimension-value lookup endpoint to avoid N-dimension page walks in large tenants.
- Expose company base currency via supported endpoint if a dedicated settings UI is added later.
- Consider dimension filtering in other reports (P&L/GL) if required, ensuring backend parameter names match exactly.
- Add server-side `priced`/usage flags surfaced in resources to pre-disable destructive actions (delete/deactivate) without guessing.

---

## 14. Final Git status, including all modified and untracked files

Final working tree state (from `git status --short`):

```
 M accounting-app/frontend/src/lib/fixed-assets/validators.ts  (pre-existing Phase 17)
 M accounting-app/frontend/src/lib/navigation/nav.test.ts       (modified: +Phase 19 tests)
 M accounting-app/frontend/src/lib/navigation/nav.ts            (modified: +Phase 19 section)
 D "prompt/frontend Prompt/Phase8.md"                          (pre-existing)
?? accounting-app/docs/FRONTEND_PHASE_17_REPORT.md             (pre-existing)
?? accounting-app/docs/FRONTEND_PHASE_18_REPORT.md             (pre-existing)
?? accounting-app/docs/FRONTEND_PHASE_19_REPORT.md             (this report)
?? accounting-app/frontend/src/app/(app)/budgets/               (Phase 19)
?? accounting-app/frontend/src/app/(app)/currencies/            (Phase 18)
?? accounting-app/frontend/src/app/(app)/currency-settings/     (Phase 18)
?? accounting-app/frontend/src/app/(app)/dimensions/            (Phase 19)
?? accounting-app/frontend/src/app/(app)/exchange-rates/        (Phase 18)
?? accounting-app/frontend/src/app/(app)/fixed-assets/          (Phase 17)
?? accounting-app/frontend/src/app/(app)/reports/budget-variance/ (Phase 19)
?? accounting-app/frontend/src/components/budgets/               (Phase 19)
?? accounting-app/frontend/src/components/currencies/            (Phase 18)
?? accounting-app/frontend/src/components/dimensions/            (Phase 19)
?? accounting-app/frontend/src/components/exchange-rates/        (Phase 18)
?? accounting-app/frontend/src/components/fixed-asset-categories/ (Phase 17)
?? accounting-app/frontend/src/components/fixed-assets/         (Phase 17)
?? accounting-app/frontend/src/components/fx-settings/           (Phase 18)
?? accounting-app/frontend/src/components/reports/budget-variance-view.test.tsx (Phase 19)
?? accounting-app/frontend/src/components/reports/budget-variance-view.tsx (Phase 19)
?? accounting-app/frontend/src/lib/accounts/use-account-type-options.ts (Phase 18 additive)
?? accounting-app/frontend/src/lib/budgets/                      (Phase 19)
?? accounting-app/frontend/src/lib/currencies/                   (Phase 18)
?? accounting-app/frontend/src/lib/dimensions/                   (Phase 19)
?? accounting-app/frontend/src/lib/exchange-rates/               (Phase 18)
?? accounting-app/frontend/src/lib/fixed-asset-categories/      (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/api.test.ts      (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/api.ts           (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/use-depreciation-report.ts (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-asset-schedule.ts (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-asset.ts (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-assets.ts (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/validators.test.ts (Phase 17)
?? accounting-app/frontend/src/lib/fx-settings/                  (Phase 18)
?? accounting-app/frontend/src/lib/periods/use-period-options.ts (Phase 19 additive)
?? "prompt/frontend Prompt/Phase 1"                             (pre-existing)
?? "prompt/frontend Prompt/Phase 18.md"                         (pre-existing brief)
?? "prompt/frontend Prompt/Phase 19..md"                        (this brief)
?? "prompt/frontend Prompt/Phase 8.md"                          (pre-existing)
```

No commit, no push, no other prohibited Git operation was performed.

---

## 15. Confirmation that no prohibited backend, database, dependency, or Git operations were performed

- No file under `accounting-app/backend/` was modified. [source: git status]
- No dependency, `package.json`, or lockfile was changed; nothing installed/removed.
- No migration or seeder was run; no database-mutating commands.
- No existing test was deleted or weakened (only `nav.test.ts` extended additively; other pre-existing tests unchanged).
- No completed phase's source altered except additive additions (`nav.ts`/`nav.test.ts` changes are additive for the new section). No prior phase reports or prompts were overwritten.
- No destructive Git operations (reset/clean/stash/checkout/switch/restore/pull/fetch/rebase). No commit/push.
- No backend API calls from browser to Laravel directly (all via same-origin BFF). No secrets exposed.

Phase 19 is complete as specified. All quality gates pass and the Phase 19 report is written.

**Stop condition met.** No Phase 20/21 started. No commit/push performed.