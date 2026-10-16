# Accountance Frontend — Phase 17 Report

**Phase:** 17 — Fixed Assets & Depreciation
**Scope:** Frontend only
**Date:** 2026-10-14
**Report path:** `accounting-app/docs/FRONTEND_PHASE_17_REPORT.md`

> **Git note.** The Phase 17 brief prohibits Git commit and push unless
> explicitly authorized by the operator (§2.2 "Prohibited Git operations",
> §20 "Stop Condition"). No commit was created, no push was performed, and no
> other prohibited Git operation (pull/fetch/reset/stash/clean/checkout/switch/
> restore/amend) was run. All Phase 17 work remains uncommitted as modified and
> untracked files for the operator to review and commit if desired.

> **Verification legend used throughout.**
> **[source]** verified by reading the Laravel backend source.
> **[tests]** verified by the frontend automated test suite.
> **[build]** verified by a successful production build (routes emitted).
> **[live]** verified against a running backend/database — **none of Phase 17
> was verified this way.**

---

## 1. Executive summary

Phase 17 delivered the Fixed Assets & Depreciation area end to end against the
verified Laravel API, frontend-only:

- **Fixed asset register** — list with the filters the backend actually accepts
  (search, status, acquisition method, category, acquisition-date range),
  `hasMore` pagination, and a responsive table/card list. Two list endpoints back
  it: `/fixed-assets` (every lifecycle state) and `/fixed-assets/register`
  (capitalised assets only).
- **Fixed asset categories** — full CRUD against the separate
  `fixed-asset-categories` resource, including activate/deactivate, with the five
  account mappings an asset inherits.
- **Asset creation and editing** — the acquisition method is carried by the
  route (`/cash` vs `/supplier-credit`) because the verified request body has no
  `acquisition_method` field; editing is drafts-only, matching the backend rule.
- **Depreciation** — a read-only schedule (server-computed) and per-asset posting
  of the next charge; only `STRAIGHT_LINE` exists, so it is the only method
  presented. There is **no batch endpoint**, so the UI posts one asset at a time.
- **Disposal** — proceeds, date, optional reason, and proceeds account; the
  carrying value, gain and loss are computed by the server and never in the
  browser.
- **Reporting** — one server-computed report (depreciation by period) plus an
  honest reports hub linking to the register and the disposal register. No report
  is fabricated.
- **Navigation** — a real, permission-keyed "Fixed assets" section replacing
  nothing and inventing nothing.
- **Tests** — API clients, validators, and component tests covering registers,
  detail lifecycle gating, and the depreciation report. The full suite is
  **83 files / 798 tests, all passing**; Phase 17 added 7 test files (71 tests)
  and extended the navigation test.

All quality gates pass (ESLint, TypeScript, Vitest, production build). No backend
file, dependency, lockfile, or prior report was changed. Live integration was
**not** performed and is not claimed.

---

## 2. Initial branch, HEAD, and Git status

- Repository root: `D:\Development\Accountance`
- Branch: `main` (`main...origin/main`)
- HEAD: `4cb1614` — "feat(frontend): Phase 17 fixed assets foundation (types and validators)"

`git status --short --branch` at start of Phase 17:

```
## main...origin/main
 M accounting-app/frontend/src/lib/fixed-assets/validators.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? accounting-app/frontend/src/app/(app)/fixed-assets/
?? accounting-app/frontend/src/components/fixed-asset-categories/
?? accounting-app/frontend/src/components/fixed-assets/
?? accounting-app/frontend/src/lib/fixed-asset-categories/api.ts
?? accounting-app/frontend/src/lib/fixed-asset-categories/use-fixed-asset-categories.ts
?? accounting-app/frontend/src/lib/fixed-asset-categories/use-fixed-asset-category-options.ts
?? accounting-app/frontend/src/lib/fixed-asset-categories/validators.ts
?? accounting-app/frontend/src/lib/fixed-assets/api.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-depreciation-report.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-asset-schedule.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-asset.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-assets.ts
?? "prompt/frontend Prompt/Phase 1"
```

The working tree was inspected afresh rather than trusting the Phase 16 report.
The only pre-existing tracked modifications were the Phase 17 foundation files
(`fixed-assets/validators.ts`, `nav.ts`, `nav.test.ts`); the repository root and
HEAD differ from earlier phases' machines, confirming the "do not assume the tree
still matches the report" instruction.

`"prompt/frontend Prompt/Phase 1"` is an unrelated pre-existing untracked file.
It was **not** touched, deleted, or cleaned up.

`.gitignore`d vendor/build artefacts (`node_modules`, `.next`, `vendor`) are not
listed.

---

## 3. Repository-preservation confirmation

- No file under `accounting-app/backend/` was modified. [source: `git status`]
- No dependency, `package.json`, or lockfile was changed; nothing was installed.
- No migration or seeder was run.
- No existing test was deleted or weakened. The only pre-existing test file
  changed is `nav.test.ts`, and only to add the new Fixed assets assertions
  (existing assertions preserved).
- Prior phase reports and prompts were not overwritten. The only new document is
  this report.
- The only shared-code change is the minimal, necessary navigation section in
  `nav.ts` (a new section; no existing section altered).
- No prohibited Git operation was run.

---

## 4. Verified backend endpoint inventory

From `accounting-app/backend/routes/api.php` (Phase 12 fixed assets), all inside
the `auth:api, auth.fresh, company.context, throttle:api` group under
`/accounting`. `fixedAsset` and `fixedAssetCategory` are bound to the active
company in `AppServiceProvider`, so a cross-tenant id 404s before any controller
runs. [source]

### Fixed assets

| Method | Path | Action | Route name |
| ------ | ---- | ------ | ---------- |
| GET | `/accounting/fixed-assets` | `index` | `accounting.fixed-assets.index` |
| POST | `/accounting/fixed-assets/cash` | `storeCash` (201) | `accounting.fixed-assets.store-cash` |
| POST | `/accounting/fixed-assets/supplier-credit` | `storeSupplierCredit` (201) | `accounting.fixed-assets.store-supplier-credit` |
| GET | `/accounting/fixed-assets/register` | `register` | `accounting.fixed-assets.register` |
| GET | `/accounting/fixed-assets/depreciation-report` | `depreciationReport` | `accounting.fixed-assets.depreciation-report` |
| GET | `/accounting/fixed-assets/{fixedAsset}` | `show` | `accounting.fixed-assets.show` |
| PUT | `/accounting/fixed-assets/{fixedAsset}` | `update` | `accounting.fixed-assets.update` |
| DELETE | `/accounting/fixed-assets/{fixedAsset}` | `destroy` | `accounting.fixed-assets.destroy` |
| POST | `/accounting/fixed-assets/{fixedAsset}/capitalize` | `capitalize` | `accounting.fixed-assets.capitalize` |
| GET | `/accounting/fixed-assets/{fixedAsset}/depreciation-schedule` | `depreciationSchedule` | `accounting.fixed-assets.depreciation-schedule` |
| POST | `/accounting/fixed-assets/{fixedAsset}/depreciation` | `depreciate` | `accounting.fixed-assets.depreciate` |
| POST | `/accounting/fixed-assets/{fixedAsset}/dispose` | `dispose` | `accounting.fixed-assets.dispose` |

The static `register` and `depreciation-report` routes are declared **before**
`{fixedAsset}` in the backend so the static paths win the match. [source]

### Fixed asset categories

| Method | Path | Action | Route name |
| ------ | ---- | ------ | ---------- |
| GET | `/accounting/fixed-asset-categories` | `index` | `accounting.fixed-asset-categories.index` |
| POST | `/accounting/fixed-asset-categories` | `store` | `accounting.fixed-asset-categories.store` |
| GET | `/accounting/fixed-asset-categories/{fixedAssetCategory}` | `show` | `accounting.fixed-asset-categories.show` |
| PUT | `/accounting/fixed-asset-categories/{fixedAssetCategory}` | `update` | `accounting.fixed-asset-categories.update` |
| DELETE | `/accounting/fixed-asset-categories/{fixedAssetCategory}` | `destroy` | `accounting.fixed-asset-categories.destroy` |
| POST | `/accounting/fixed-asset-categories/{fixedAssetCategory}/activate` | `activate` | `...activate` |
| POST | `/accounting/fixed-asset-categories/{fixedAssetCategory}/deactivate` | `deactivate` | `...deactivate` |

### Filter vocabulary (verified from the request classes) [source]

- `FixedAssetFilterRequest`: `status`, `acquisition_method`,
  `fixed_asset_category_id`, `from`, `to`, `search`, `page`, `per_page`
  (capped at 100). Dates address `acquisition_date`, never `created_at`.
- `FixedAssetDepreciationReportRequest`: `from` and `to` are **required** (and
  `from` after `to` is refused), plus optional `fixed_asset_category_id`.
- `FixedAssetCategoryFilterRequest`: `is_active`, `search`, per-page/pagination.
  No date range (a category has no transaction date).

### Shared envelope

Every endpoint answers with the application's standard `ApiResponse` envelope
(`{ success, message, data, … }`). `index`/`register` return a **flat array** as
`data` with no `links`/`meta`/totals; the frontend therefore uses the shared
`hasMore` pagination convention and never fabricates totals. [source]

---

## 5. Exact permission keys

From `accounting-app/backend/config/authorization.php` /
`app/Enums/PermissionName.php`, enforced by `FixedAssetPolicy` and
`FixedAssetCategoryPolicy`. [source]

| Ability | Permission key |
| ------- | -------------- |
| view (list + detail + register + report) | `accounting.fixed_asset.view` |
| create | `accounting.fixed_asset.create` |
| update | `accounting.fixed_asset.update` |
| delete | `accounting.fixed_asset.delete` |
| capitalise | `accounting.fixed_asset.capitalize` |
| depreciate | `accounting.fixed_asset.depreciate` |
| dispose | `accounting.fixed_asset.dispose` |

Categories share the same `accounting.fixed_asset.*` family (there is no separate
category permission key); their policy checks the fixed-asset abilities. The
frontend gates every create/update/lifecycle control on these exact keys, and
navigation uses `accounting.fixed_asset.view`. Navigation visibility is UI only;
the backend remains authoritative (a 403 is surfaced, not hidden).

---

## 6. Asset register implementation

### 6.1 Register list — `/fixed-assets` and `/fixed-assets/register`

`FixedAssetsView` (`src/components/fixed-assets/fixed-assets-view.tsx`) has a
`mode` prop:

- `mode="index"` (route `/fixed-assets`) reads `GET accounting/fixed-assets` —
  every lifecycle state, so drafts stay visible and can be capitalised.
- `mode="register"` (route `/fixed-assets/register`) reads
  `GET accounting/fixed-assets/register` — capitalised assets only. The status
  filter is hidden in this mode because the register is *defined* by status and a
  contradictory filter would mislead.

Both share one screen: search, an optional "More filters" panel (status,
acquisition method, category, acquired from/to), `hasMore` pagination, and a
responsive table that becomes stacked cards below the medium breakpoint. The
list displays only fields the `FixedAssetResource` returns — asset number, name,
category, acquisition date, cost, salvage value, useful life, and the
**server-computed** accumulated depreciation and net book value. No totals row is
shown because the endpoint returns no totals. [source][tests][build]

### 6.2 Asset detail — `/fixed-assets/[id]`

`FixedAssetDetailView` renders identity, description, serial number, supplier
reference, lifecycle status badge, acquisition method, category link, acquisition
date, capitalisation date, depreciation start, cost, salvage, useful life,
monthly charge, and the five account mappings, plus accumulated depreciation and
net book value (server-computed). It shows the posted depreciation history and,
when present, the disposal summary (date, proceeds, carrying value at disposal,
gain/loss, journal). Lifecycle actions are shown strictly by status and
permission:

| Status | Offered actions |
| ------ | --------------- |
| `DRAFT` | Edit, Capitalise, Delete |
| `ACTIVE` | Post depreciation, Dispose |
| `FULLY_DEPRECIATED` | Dispose |
| `DISPOSED` | (read-only) |

Edit is a link to `/fixed-assets/[id]/edit`; the other actions open confirmation
dialogs. [source][tests]

### 6.3 Creation and editing — `/fixed-assets/new`, `/fixed-assets/[id]/edit`

`FixedAssetForm` collects name, description, serial number, supplier reference,
category, acquisition date, depreciation start, original cost, salvage value, and
the acquisition account. The **acquisition method is a form choice that selects
the endpoint** (`CASH` → `POST /cash`, `SUPPLIER_CREDIT` →
`POST /supplier-credit`) because `StoreFixedAssetRequest` accepts no
`acquisition_method` field. [source]

Payload rules mirrored from the request validators [source]:
- `salvage_value` is a plain `decimal` rule (not `nullable`) on the backend, so a
  blank/`null` value is **omitted** from the body rather than sent as `null`; the
  service then defaults it to zero. (`api.ts` `toMoneyBody`.)
- Other nullable fields (`description`, `serial_number`, `supplier_reference`)
  are sent as `null` when blank, which the rules accept.
- Money is forwarded as a decimal string verbatim; no floating-point arithmetic
  touches the payload.
- Server-owned fields (`asset_number`, status, accumulated depreciation, carrying
  value, accounts snapshot, journals, timestamps) are never sent.

Client validation mirrors the required fields (name, category, original cost >
0, acquisition account, date format) for immediate feedback; **the backend is the
validator of record** and its field errors are displayed verbatim through the
shared `firstFieldErrors` mapping. Editing is drafts-only and the form re-checks
the status before offering the editor. Duplicate submissions are prevented with a
`submitting` guard and a disabled/loading button. [source][tests]

### 6.4 Route segments

Static siblings (`new`, `register`, `categories`, `depreciation`, `disposals`,
`reports`) coexist with the `[id]` dynamic segment; Next.js gives static segments
precedence, and the backend likewise declares its static paths first. [build]

---

## 7. Asset categories and account mapping behavior

Categories are a **distinct, fully-CRUD backend resource**, so
`/fixed-assets/categories` implements the supported list, create, update and
lifecycle (activate/deactivate) and delete. [source]

`FixedAssetCategoryFormDialog` collects code, name, description, useful life
(months), the depreciation method (fixed straight-line, shown read-only), and the
five account mappings:

| Category field | Backend eligibility rule (TransactionAccountResolver) [source] |
| -------------- | -------------------------------------------------------------- |
| `asset_account_id` | must be an **ASSET** account |
| `accumulated_depreciation_account_id` | must be an **ASSET** account **and CREDIT-normal** (contra) |
| `depreciation_expense_account_id` | must be an **EXPENSE** account |
| `gain_on_disposal_account_id` (optional) | must be a **REVENUE** account |
| `loss_on_disposal_account_id` (optional) | must be an **EXPENSE** account |

Account ids are sent (never names), via the shared Chart-of-Accounts
`useAccountOptions` hook, so `accounts.view` is respected.

**Deliberate non-filtering of account choices.** The backend contract *does*
establish the account type per field, but the resolver's rules also include
`normal_balance = CREDIT` for the accumulated-depreciation account, which the
`accounts` list filter cannot express (`account_type` is a type filter only). The
selects therefore show the full chart of accounts and rely on the backend's
field-mapped validation errors, rather than half-filtering and implying the list
is fully eligible. This is recorded as a deliberate choice in §17.

Client validation mirrors the required fields (code, name, useful life ≥ 1, the
three required accounts); backend errors are displayed per field. [source][tests]

---

## 8. Depreciation methods and supported workflow

The backend enum `DepreciationMethod` has exactly **one** case: `STRAIGHT_LINE`.
There is no declining-balance or units-of-production method, no rate, and no
usage input. The UI presents straight-line only and offers no method picker
beyond the read-only straight-line display on the category form. [source]

Constraints verified from the request validators and calculator [source]:
- Useful life is in **months** (1–65535).
- Salvage value is a non-negative decimal, defaulted to zero.
- Depreciation starts from `depreciation_start_date`.
- Charges are **monthly**, derived from the stored schedule; the period, amount
  and posting date come from the schedule, not the client.
- There is **no batch depreciation endpoint** — depreciation is posted per asset.

The asset detail view shows the posted depreciation history (period, amount,
final-period flag, journal) from the asset resource. [source]

---

## 9. Depreciation preview, schedule, and posting behavior

### 9.1 Schedule (read-only) — `GET /{id}/depreciation-schedule`

`DepreciationSchedule` renders the server's `upcoming_periods` (period number,
period start/end, posting date, amount, final-period flag). Nothing is computed
in the browser. When no further periods remain it shows a neutral "nothing
scheduled" state. [source][tests]

### 9.2 Posting — `POST /{id}/depreciation`

`DepreciateDialog` posts the next scheduled charge, optionally forwarding
`as_of` (the date the "has this period ended" question is asked against;
omitted → today, server-side). It sends no `period_number` or `amount`, because
which period is next is a function of the posted rows the server alone can see.
The dialog states the server may refuse if no period has ended yet. On success
the asset detail refreshes from the server-returned asset (status may have moved
to `FULLY_DEPRECIATED`). Duplicate posts are prevented by the `submitting` guard;
server validation failures (including closed-period rejections) are surfaced
verbatim. [source][tests]

There is no client-side preview that computes an authoritative amount; the only
figure shown in the dialog is the server-returned `monthly_charge`. [source]

---

## 10. Disposal workflow and accounting results

`POST /{id}/dispose` (`DisposeFixedAssetRequest`) accepts exactly: `proceeds`
(optional, non-negative, defaults to zero), `proceeds_account_id` (required, must
be an ASSET account — cash/bank or receivable), `disposal_date` (required), and
`reason` (optional, max 100 chars). The **carrying value, gain and loss are
absent from the rules** and are computed by `FixedAssetDisposalService` from the
posted depreciation rows — a client cannot send a gain the register would
disagree with. [source]

`DisposeDialog` collects those four inputs and sends nothing else. `proceeds`
blank is sent as `null` (the backend defaults it to zero). The proceeds-account
select uses the shared account picker. On success the detail view renders the
server-returned disposal (proceeds, carrying value, gain, loss, net result,
journal) and the asset becomes `DISPOSED`. [source][tests]

Disposal is offered for `ACTIVE` and `FULLY_DEPRECIATED` assets and refused for
`DRAFT` and already-`DISPOSED` assets, matching `isDisposable()`. The disposal is
irreversible, so it is behind a confirmation dialog. [source]

There is **no disposal-list endpoint**; the disposal register
(`/fixed-assets/disposals`, `DisposalsView`) is the fixed-asset list filtered to
`status=DISPOSED`, which is the honest way to present it. Per-disposal gain/loss
lives on the asset detail, not on the list. [source][tests]

---

## 11. Reports and filters

### 11.1 Depreciation report — `/fixed-assets/depreciation`

`GET /accounting/fixed-assets/depreciation-report?from=&to=&fixed_asset_category_id=`
returns `{ from, to, total_depreciation, assets: [{ fixed_asset_id,
asset_number, name, category, period_count, total_depreciation, entries }] }`.
`from`/`to` are required and matched against each charge's **own period dates**
(so a run posted in arrears reports under the month it covers). The report view
defaults the range to the current month, offers a category filter, and displays
the server's total and per-asset subtotals — **nothing is summed in the
browser**. Each asset number drills down to `/fixed-assets/[id]`. Loading, empty,
error/retry, company and permission states are handled. [source][tests]

### 11.2 Reports hub — `/fixed-assets/reports`

The module exposes exactly one server-computed report plus two register-style
views, so the hub is an honest index of the implemented surfaces (depreciation
report, register, disposals) with a one-line description of what each shows. It
does **not** fabricate a net-book-value, accumulated-depreciation, or disposal-
summary report the backend cannot produce. [source][tests]

### 11.3 Register — `/fixed-assets/register`

The capitalised-asset register (see §6.1). [source][build]

---

## 12. Files added and modified

### New — lib layer
- `src/lib/fixed-assets/api.ts` (`"use client"`)
- `src/lib/fixed-assets/use-fixed-assets.ts`
- `src/lib/fixed-assets/use-fixed-asset.ts`
- `src/lib/fixed-assets/use-fixed-asset-schedule.ts`
- `src/lib/fixed-assets/use-depreciation-report.ts`
- `src/lib/fixed-asset-categories/api.ts`
- `src/lib/fixed-asset-categories/validators.ts`
- `src/lib/fixed-asset-categories/use-fixed-asset-categories.ts`
- `src/lib/fixed-asset-categories/use-fixed-asset-category-options.ts`

### Pre-existing (Phase 17 foundation commit `4cb1614`) — modified in the tree
- `src/lib/fixed-assets/types.ts` (committed; unchanged since)
- `src/lib/fixed-assets/validators.ts` (committed; extended)

### New — components
- `src/components/fixed-assets/fixed-assets-view.tsx`
- `src/components/fixed-assets/fixed-assets-table.tsx`
- `src/components/fixed-assets/fixed-assets-list-skeleton.tsx`
- `src/components/fixed-assets/fixed-asset-status-badge.tsx`
- `src/components/fixed-assets/fixed-asset-form.tsx`
- `src/components/fixed-assets/fixed-asset-edit-view.tsx`
- `src/components/fixed-assets/fixed-asset-detail-view.tsx`
- `src/components/fixed-assets/depreciation-schedule.tsx`
- `src/components/fixed-assets/fixed-asset-action-dialogs.tsx`
- `src/components/fixed-assets/depreciation-report-view.tsx`
- `src/components/fixed-assets/disposals-view.tsx`
- `src/components/fixed-assets/reports-hub-view.tsx`
- `src/components/fixed-asset-categories/fixed-asset-categories-view.tsx`
- `src/components/fixed-asset-categories/fixed-asset-category-form-dialog.tsx`

### New — routes (`src/app/(app)/fixed-assets/`)
- `page.tsx`
- `new/page.tsx`
- `[id]/page.tsx`
- `[id]/edit/page.tsx`
- `register/page.tsx`
- `categories/page.tsx`
- `depreciation/page.tsx`
- `disposals/page.tsx`
- `reports/page.tsx`

Dynamic pages parse `id` with `Number` and call `notFound()` on a non-integer.

### Modified
- `src/lib/navigation/nav.ts` — new "Fixed assets" section (6 items, all gated on
  `accounting.fixed_asset.view`): Fixed assets, Register, Categories,
  Depreciation, Disposals, Reports.
- `src/lib/navigation/nav.test.ts` — the section assertion updated to include
  Register; placeholder assertion preserved.

### Removed
- `src/lib/fixed-assets/use-fixed-asset-options.ts` — an unused options hook that
  duplicated the register walk with no consumer. Removed before delivery so no
  dead code ships; `fetchFixedAssetRegister` is now used by the register route
  instead.

---

## 13. Test files and total test count

New (Phase 17):

| File | Tests | Covers |
| ---- | ----- | ------ |
| `src/lib/fixed-assets/validators.test.ts` | 20 | asset/status/method/row parsing, list envelope + flat array, invalid-row rejection, schedule/report/dispose parsing, create/update/dispose write rules |
| `src/lib/fixed-assets/api.test.ts` | 15 | proxy paths and verbs, filter query string, cash vs supplier-credit routes, omitted `salvage_value`, bodyless capitalize, optional `as_of`, dispose payload |
| `src/lib/fixed-asset-categories/validators.test.ts` | 9 | category parsing, method rejection, list/detail parsing, create/update rules |
| `src/lib/fixed-asset-categories/api.test.ts` | 8 | paths/verbs, `is_active` → `1`/`0` mapping, activate/deactivate |
| `src/components/fixed-assets/fixed-assets-view.test.tsx` | 8 | company/permission gating, list render, draft-only Edit, empty/error/retry, register variant (no status filter) |
| `src/components/fixed-assets/fixed-asset-detail-view.test.tsx` | 7 | identity/book values, draft vs active action gating, permission gating, not-found, capitalise and dispose dialogs |
| `src/components/fixed-assets/depreciation-report-view.test.tsx` | 4 | server total + per-asset group, empty state, company/permission states |

Extended:
- `src/lib/navigation/nav.test.ts` — the Fixed assets section assertion now
  includes the Register entry.

Full suite: **83 files / 798 tests, all passing.** [tests]

> One full-suite run during development reported a single failure that did not
> reproduce on two subsequent full runs (both green). No Phase 17 test failed;
> the flake is attributed to a timeout under parallel load and is noted here for
> transparency rather than hidden.

---

## 14. Exact lint, ESLint, TypeScript, test, and build results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
| ---- | ------- | ------ |
| ESLint (project) | `npm run lint` | **exit 0** — no errors, no warnings |
| TypeScript | `npx tsc --noEmit` | **exit 0** — 0 errors |
| Tests | `npx vitest run` | **exit 0** — 83 files / 798 tests passed |
| Build | `npm run build` | **exit 0** — "Compiled successfully"; all 9 `/fixed-assets/**` routes emitted |

`npm run lint` is the project's ESLint script (`eslint .`), matching §16's
established equivalent; it reports `0 problems`. No dependency, lockfile, or lint
rule was modified to pass, and no rule was disabled. TypeScript was not weakened.

Routes emitted by the build [build]:

```
/fixed-assets
/fixed-assets/[id]
/fixed-assets/[id]/edit
/fixed-assets/categories
/fixed-assets/depreciation
/fixed-assets/disposals
/fixed-assets/new
/fixed-assets/register
/fixed-assets/reports
```

---

## 15. Live integration status

**Not performed.** No running backend/database integration was executed, no
migration or seeder was run, and no database was modified. No claim of live API
correctness is made. Contracts were verified through backend source inspection
and the frontend automated tests only. [source][tests][build]; [live] = none.

Suggested Phase 21 live smoke tests (from brief §17):

1. Asset creation and validation (both cash and supplier-credit).
2. Asset detail and account mapping.
3. Depreciation schedule endpoint values.
4. Depreciation posting.
5. Duplicate depreciation prevention.
6. Closed-period posting rejection.
7. Accumulated depreciation and net book value after posting.
8. Asset disposal and accounting result.
9. Disposal of ineligible or already-disposed assets.
10. Fixed asset reports and totals.
11. Company isolation.
12. Permission-denied responses.
13. Posted-record immutability.
14. Date and rounding boundaries.

---

## 16. Missing backend contracts and blocked functionality

- **No batch depreciation endpoint.** Each asset is posted individually; the UI
  does not pretend a batch run exists. (Acceptance criterion "posting follows
  backend rules" is met per-asset.)
- **No disposal-list endpoint.** The disposal register is the asset list filtered
  to `status=DISPOSED`; per-disposal gain/loss is shown only on the asset detail.
- **No additional report endpoints.** There is no net-book-value, accumulated-
  depreciation, or disposal-summary report, so none is built. The register and
  the depreciation report cover the supported reporting.
- **No asset-code entry.** `asset_number` is server-generated, so it is never an
  input.
- **No category permission family.** Categories reuse the fixed-asset
  permissions; there is no separate `accounting.fixed_asset_category.*` key to
  gate on.
- **Normal-balance constraint not expressible as a list filter.** See §7 and §17.

None of these blocked a Phase 17 acceptance criterion, because the brief
conditions each capability on backend support and forbids fabricating endpoints
or accounting logic to compensate.

---

## 17. Known limitations and risks

- **Account selects are not type-filtered.** The backend establishes the account
  *type* per mapping, but the accumulated-depreciation rule also requires a
  CREDIT normal balance, which the `accounts` list filter cannot express. The
  selects show the whole chart of accounts and rely on the backend's field-mapped
  errors. Deliberate, per §7. [source]
- **Depreciation is per-asset.** There is no batch workflow; a multi-asset run
  means opening each asset. [source]
- **Advisory client validation only.** Client checks give immediate feedback but
  never gate the ledger; the server is authoritative and its errors are shown.
- **`from` after `to` on the depreciation report.** The report defaults to a
  valid range and the backend refuses an inverted range; the view surfaces that
  error rather than inventing a client rule.
- **`register` vs `index` distinction is a URL.** The register route reads a
  different endpoint; a user who only visits `/fixed-assets` sees drafts too,
  which is intended (drafts must remain capitalisable).
- **Live end-to-end testing deferred** (see §15).
- **One non-reproducing flaky full-suite run** (see §13).

---

## 18. Final Git status

All Phase 17 work is uncommitted, as required by the brief:

```
## main...origin/main
 M accounting-app/frontend/src/lib/fixed-assets/validators.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? accounting-app/frontend/src/app/(app)/fixed-assets/
?? accounting-app/frontend/src/components/fixed-asset-categories/
?? accounting-app/frontend/src/components/fixed-assets/
?? accounting-app/frontend/src/lib/fixed-asset-categories/
?? accounting-app/frontend/src/lib/fixed-assets/api.test.ts
?? accounting-app/frontend/src/lib/fixed-assets/api.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-depreciation-report.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-asset-schedule.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-asset.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-fixed-assets.ts
?? accounting-app/frontend/src/lib/fixed-assets/validators.test.ts
?? accounting-app/docs/FRONTEND_PHASE_17_REPORT.md
?? "prompt/frontend Prompt/Phase 1"   (pre-existing, untouched)
```

No commit, no push, and no other prohibited Git operation was performed.

---

## 19. Confirmation of preservation

- Backend files, routes, controllers, services, models, policies, migrations,
  permissions, and tests: **unchanged**.
- Frontend dependencies, `package.json`, and lockfiles: **unchanged**; nothing
  installed, removed, or upgraded.
- Prior phase reports and prompts: **unchanged**.
- Existing modules and tests: **unchanged and green** (798/798).
- The only shared-code change is the additive navigation section, plus its test.
- No prohibited Git operation was run; no commit or push was performed.
- Quality gates: ESLint, TypeScript, Vitest, and the production build all pass.
