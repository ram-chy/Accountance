# Frontend Phase 12 — Remaining Scope Audit & Final Roadmap

- **Date:** 2026-10-13
- **Phase:** 12 (Remaining Scope Audit & Final Roadmap)
- **Type:** Repository audit, gap analysis and roadmap planning
- **Scope:** Frontend only — inspection and documentation
- **Status:** Complete
- **Branch:** `main`
- **HEAD at audit:** `b3b6e30fa7b058cf58f7503aead9db0779b41c9a` (`b3b6e30`)
- **Backend files modified:** none
- **Application source/config/tests modified:** none
- **New file created:** `accounting-app/docs/FRONTEND_PHASE_12_REPORT.md` (this file)

> **Evidence legend used throughout this report**
> - **[VERIFIED]** — confirmed by reading the repository or by running a read-only command in this session.
> - **[INFERRED]** — a conclusion drawn from static inspection that was not executed.
> - **[RUNTIME]** — requires a running backend/database and cannot be settled statically.
> - **[RECOMMENDATION]** — proposed future work, not a finding.
> - **[UNRESOLVED]** — an open decision or external dependency.

---

## 1. Executive summary

The Accountance frontend is a mature, well-architected Next.js App Router application
with a strong security boundary and a coherent design system. Frontend Phases 0–10 are
committed at HEAD `b3b6e30`. Phase 11 (financial reports) is **present only as
uncommitted working-tree changes**.

**Headline findings**

1. **The Phase 11 work is not release-clean.** `npm run lint` **fails** (exit 1) with
   **7 errors and 17 warnings**, and **all 24 problems are confined to the uncommitted
   Phase 11 reports files**. `npx tsc --noEmit` passes (exit 0) and `npm test` passes
   (39 files / 409 tests), but the lint gate is red. **[VERIFIED]**
2. **The General Ledger screen is not functional.** `src/components/reports/general-ledger-view.tsx`
   fetches data through `useGeneralLedger` but renders **only a heading** — no movements,
   no running balance, no loading/error/empty states — while still being linked from the
   sidebar and used as the drill-down target from Trial Balance and Profit & Loss.
   **[VERIFIED]**
3. **No live/end-to-end integration has ever been performed.** Every phase report through
   Phase 11 records that integration was "not performed". Automated tests are unit/
   component tests against mocks only (`vitest` + `jsdom`); there is no browser/E2E
   harness (no Playwright/Cypress) and no automated accessibility checker (no axe).
   **[VERIFIED]**
4. **A large, agreed backend surface has no frontend at all.** Customers, suppliers,
   sales invoices, purchase bills, credit/debit notes, customer receipts, supplier
   payments, and the remaining reports (balance sheet, cash/bank, receivables/payables,
   aging, statements) are implemented and authorised in the backend and **already
   permitted by the proxy allow-list**, but have **no frontend routes, screens or API
   wrappers**. Fixed assets, currencies/FX, budgets and financial dimensions are in the
   blueprint's ordered roadmap and are likewise unbuilt on the frontend. **[VERIFIED]**
5. **The security boundary is strong and statically verified.** JWT is sealed with
   AES-256-GCM into an HttpOnly SameSite=Lax cookie and never exposed to browser
   JavaScript; the BFF proxy is an allow-list with traversal rejection, server-injected
   `Authorization`/`X-Company-Id`, byte-accurate body-size enforcement, `redirect:"manual"`
   and response-header filtering. **[VERIFIED]**

**Bottom line:** the frontend is **not production-ready**. The reporting module must be
remediated (lint + General Ledger), the agreed release modules must be built, and — most
importantly — live integration and user-workflow verification must actually be performed
before any release claim is credible. This audit proposes **9 remaining phases**, ending
at **final phase number 21**.

---

## 2. Audit scope and methodology

**Scope:** frontend inspection and documentation only. No backend changes, no new
features, no configuration or test changes, no dependency changes, no migrations.
Read-only Git operations only.

**Method**

1. Established the initial repository state (branch, HEAD, `git status --porcelain -uall`).
2. Read the master documentation: `docs/FRONTEND_BLUEPRINT.md`,
   `docs/FRONTEND_API_HANDOFF.md`, `docs/FRONTEND_PHASE_0_AUDIT.md`,
   `docs/FRONTEND_PHASE_0_REPORT.md` … `docs/FRONTEND_PHASE_11_REPORT.md`, and
   `docs/report/PHASE_18_REPORT.md`.
3. Read the backend route contract `backend/routes/api.php` (909 lines) in full, plus
   `backend/app/Enums/PermissionName.php`, `backend/config/authorization.php`, and
   `backend/config/permission.php`.
4. Read the frontend implementation directly (routes, components, hooks, validators,
   API clients, navigation, proxy policy, session handling, design primitives).
5. Ran the repository's own quality gates read-only and recorded exact results
   (`npm run lint`, `npx tsc --noEmit`, `npm test`, and a production build previously
   recorded). A build was not re-run in this session; its earlier result is marked as
   such where referenced.
6. Compared the two inventories module-by-module and produced the feature-coverage
   matrix (§8), then derived the roadmap (§15) from the gaps only.

**Explicitly not done:** no file under `backend/` was modified; no application source,
configuration, package manifest, lockfile, test or database file was modified; no
migration or seeder was run; no dependency was installed; no prohibited Git operation
was performed; nothing was committed.

---

## 3. Initial Git status and pre-existing user work

Recorded at the start of Phase 12 **[VERIFIED]**, reproduced verbatim:

```
$ git rev-parse --abbrev-ref HEAD
main
$ git rev-parse HEAD
b3b6e30fa7b058cf58f7503aead9db0779b41c9a

$ git status --porcelain -uall
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? "D\357\200\272DevelopmentAccountanceaccounting-appbackenddocsreportPHASE_17_REPORT.md"
?? accounting-app/docs/FRONTEND_PHASE_11_REPORT.md
?? accounting-app/frontend/src/app/(app)/reports/general-ledger/page.tsx
?? accounting-app/frontend/src/app/(app)/reports/profit-loss/page.tsx
?? accounting-app/frontend/src/app/(app)/reports/trial-balance/page.tsx
?? accounting-app/frontend/src/components/reports/general-ledger-view.tsx
?? accounting-app/frontend/src/components/reports/profit-loss-view.tsx
?? accounting-app/frontend/src/components/reports/trial-balance-view.tsx
?? accounting-app/frontend/src/lib/reports/api.ts
?? accounting-app/frontend/src/lib/reports/general-ledger-types.ts
?? accounting-app/frontend/src/lib/reports/profit-loss-types.ts
?? accounting-app/frontend/src/lib/reports/trial-balance-types.ts
?? accounting-app/frontend/src/lib/reports/types.ts
?? accounting-app/frontend/src/lib/reports/use-general-ledger.ts
?? accounting-app/frontend/src/lib/reports/use-profit-loss.ts
?? accounting-app/frontend/src/lib/reports/use-trial-balance.ts
?? accounting-app/frontend/src/lib/reports/validators.ts
?? "prompt/frontend/Phase 11.md"
?? "prompt/frontend/Phase 12.md"
```

**Interpretation**

- **Tracked modification (1):** `frontend/src/lib/navigation/nav.ts` — adds the three
  `/reports/*` sidebar links. This is the only tracked change.
- **Untracked — Phase 11 feature work:** the three report `page.tsx` files, the three
  `components/reports/*.tsx` view files, all nine `lib/reports/*.ts` files.
- **Untracked — documentation/prompts:** `docs/FRONTEND_PHASE_11_REPORT.md`,
  `prompt/frontend/Phase 11.md`, `prompt/frontend/Phase 12.md`.
- **Untracked — pre-existing stray artefact (not created by this phase):** the file whose
  name contains `U+F03A` private-use characters
  (`"D\357\200\272DevelopmentAccountanceaccounting-appbackenddocsreportPHASE_17_REPORT.md"`).
  This predates Phase 12 and was left untouched. Two similarly mangled root directories
  (`D:DevelopmentAccountanceaccounting-appdocsreport`) and a stray `PHASE_17_REPORT.md`
  also exist in the working tree from an earlier incident and were left untouched.

No cleanup, revert, reset, stash, checkout, switch or clean was attempted.

---

## 4. Repository-preservation confirmation

- **No backend file modified.** All inspection of `accounting-app/backend/**` was
  read-only. **[VERIFIED]**
- **No application source, configuration, package manifest, lockfile or test modified.**
  The only file Phase 12 created is `accounting-app/docs/FRONTEND_PHASE_12_REPORT.md`.
  **[VERIFIED — see §19 and §20]**
- **No existing user file deleted or overwritten.** The pre-existing tracked modification
  (`nav.ts`) and all untracked Phase 11 files, reports and stray artefacts are present
  unchanged in the final `git status` (§20). **[VERIFIED]**
- **No prohibited Git operation performed.** No `pull`, `fetch`, `reset`, `stash`, `clean`,
  `checkout`, `switch`, `restore`, or `commit`. **[VERIFIED]**
- **All prior phase reports preserved** (`FRONTEND_PHASE_0_REPORT.md` …
  `FRONTEND_PHASE_11_REPORT.md`, plus `docs/report/**`). **[VERIFIED]**
- **The target report path did not previously exist**, so it was created additively; no
  additive-alternative filename was required. **[VERIFIED]**

---

## 5. Documentation inspected

| Document | Purpose |
| --- | --- |
| `docs/FRONTEND_BLUEPRINT.md` | Frontend architecture, route/folder layout, ordering, build order (§14), open decisions (§15) |
| `docs/FRONTEND_API_HANDOFF.md` | Authoritative endpoint/permission/limitation reference for the Laravel API |
| `docs/FRONTEND_PHASE_0_AUDIT.md` | Initial frontend audit |
| `docs/FRONTEND_PHASE_0_REPORT.md` … `docs/FRONTEND_PHASE_11_REPORT.md` | Per-phase completion reports |
| `docs/report/PHASE_18_REPORT.md` | Backend completion audit ("PASS WITH NOTES"; P2-1 pagination gap) |
| `prompt/frontend/Phase 11.md`, `prompt/frontend/Phase 12.md` | Phase briefs |
| `backend/routes/api.php` | Full route/controller inventory and middleware groups |
| `backend/app/Enums/PermissionName.php` | Canonical permission string list |
| `backend/config/authorization.php` | Role → permission matrix |
| `backend/config/permission.php` | spatie/laravel-permission configuration |

**Master blueprint build order (`docs/FRONTEND_BLUEPRINT.md` §14, verbatim):**

> accounts → periods → journals → reports → customers/suppliers → invoices/bills →
> notes → receipts/payments → cash/bank → reconciliation → tax → adjustments → fixed
> assets → currencies → budgets/dimensions — with the dashboard last.

**Documented scope exclusions (`docs/FRONTEND_API_HANDOFF.md` §13, verbatim):**

> **Deferred by design:** comparative-period reporting, cash-flow statements,
> consolidation, unrealized FX revaluation, country-specific tax filing / e-invoicing,
> PDF generation endpoints. Do not assume these exist.

> **Dimension filtering is P&L and Budget Variance only.** Trial Balance, General Ledger
> and Balance Sheet are **not** dimension-filterable (deliberately deferred in Phase 17).

**All frontend phase reports state that live integration was not performed.** Phase 11
§14 is representative: *"Not performed (no PHP/DB in this environment). Implementation
follows exact backend contract from source inspection."*

### Documentation conflicts / inconsistencies recorded

- **[VERIFIED] Phase 11 report internal inconsistency.** `FRONTEND_PHASE_11_REPORT.md`
  declares `Status: In progress` (line 5), leaves §1 as the placeholder
  `[To be completed after inspection and implementation]` (line 9), yet ends with
  `Phase 11 complete.` (§16). The report is therefore not self-consistent.
- **[VERIFIED] Phase 11 report omits a lint result while labelling the gate "Lint/type-check/build".**
  §13 reports only `npx tsc --noEmit: exit 0`, the test suite and the build; it never
  reports an `npm run lint` result. An actual `npm run lint` in this audit **fails**
  (see §7). The heading implies a combined gate passed when lint was not reported.
- **[VERIFIED] Navigation vs blueprint scope conflict.** `docs/FRONTEND_BLUEPRINT.md` §14
  places **fixed assets, currencies and budgets/dimensions** in the ordered build order,
  but `frontend/src/lib/navigation/nav.ts` lists only *"Sales invoices, Customers, Credit
  notes, Customer receipts"*, *"Purchase bills, Suppliers, Debit notes, Supplier payments"*
  and *"Company settings, Users and roles"* in `upcoming` — it **omits fixed assets,
  currencies/FX, budgets and dimensions entirely**. This is carried forward to §18 as an
  unresolved decision. **[UNRESOLVED]**

---

## 6. Existing frontend route inventory

**[VERIFIED]** — enumerated from `frontend/src/app`.

### 6.1 Application (page) routes

| Route | File | Status |
| --- | --- | --- |
| `/login` | `src/app/(public)/login/page.tsx` | Complete |
| `/` (dashboard) | `src/app/(app)/page.tsx` | Complete |
| `/accounts` | `src/app/(app)/accounts/page.tsx` | Complete |
| `/journals` | `src/app/(app)/journals/page.tsx` | Complete |
| `/financial-years` | `src/app/(app)/financial-years/page.tsx` | Complete |
| `/periods` | `src/app/(app)/periods/page.tsx` | Complete |
| `/taxes` | `src/app/(app)/taxes/page.tsx` | Complete |
| `/taxes/[id]` | `src/app/(app)/taxes/[id]/page.tsx` | Complete |
| `/tax-calculator` | `src/app/(app)/tax-calculator/page.tsx` | Complete |
| `/tax-reports` | `src/app/(app)/tax-reports/page.tsx` | Complete |
| `/cash-bank` | `src/app/(app)/cash-bank/page.tsx` | Complete |
| `/cash-bank/transactions` | `src/app/(app)/cash-bank/transactions/page.tsx` | Complete |
| `/bank-reconciliations` | `src/app/(app)/bank-reconciliations/page.tsx` | Partial (see §8) |
| `/bank-reconciliations/[id]` | `src/app/(app)/bank-reconciliations/[id]/page.tsx` | Partial (see §8) |
| `/reports/trial-balance` | `src/app/(app)/reports/trial-balance/page.tsx` | Complete (uncommitted) |
| `/reports/profit-loss` | `src/app/(app)/reports/profit-loss/page.tsx` | Complete (uncommitted) |
| `/reports/general-ledger` | `src/app/(app)/reports/general-ledger/page.tsx` | **Partial / non-functional** (uncommitted) |

### 6.2 BFF / API routes

| Route | File | Purpose |
| --- | --- | --- |
| `/api/backend/[...path]` | `src/app/api/backend/[...path]/route.ts` | Authenticated allow-list reverse proxy |
| `/api/session/login` | `src/app/api/session/login/route.ts` | Login → sealed cookie |
| `/api/session/logout` | `src/app/api/session/logout/route.ts` | Clear session |
| `/api/session/me` | `src/app/api/session/me/route.ts` | Current user |
| `/api/session/company` | `src/app/api/session/company/route.ts` | Company switch / active company |

### 6.3 Navigation behaviour (`src/lib/navigation/nav.ts`)

- Sections: **Overview**, **Accounting**, **Sales and receivables** (empty `items`),
  **Purchases and payables** (empty `items`), **Banking**, **Reports**, **Company**
  (empty `items`).
- `upcoming` placeholders (non-clickable, never a link): *Ledgers*; *Sales invoices,
  Customers, Credit notes, Customer receipts*; *Purchase bills, Suppliers, Debit notes,
  Supplier payments*; *Company settings, Users and roles*.
- Implemented links are permission-filtered via `usePermissions`; an `upcoming` entry can
  never point at a non-existent route.
- **Note:** *Ledgers* is shown as `upcoming`, but `/reports/general-ledger` **is** linked
  as a real item in the Reports section. The "Ledgers" placeholder is therefore stale/
  redundant relative to the actual General Ledger link. **[INFERRED]**
- No link points at a route that does not exist. No implemented app route is missing from
  navigation. **[VERIFIED]**

---

## 7. Backend capability inventory

**[VERIFIED]** — read from `backend/routes/api.php`. Every company-scoped group sits
behind `['auth:api', 'auth.fresh', 'company.context', 'throttle:api']`; route-model
binding is company-scoped in `AppServiceProvider`, and cross-company ids return **404**,
not 403.

### 7.1 Authentication, users, companies

| Area | Routes |
| --- | --- |
| Public auth | `GET /api/health`; `POST /api/auth/register`, `/login`, `/forgot-password`, `/reset-password`; `GET /api/auth/reset-password/{token}`, `/email/verify/{id}/{hash}` |
| Authenticated auth | `GET /api/auth/me`; `POST /api/auth/logout`, `/email/verification-notification`; `PUT /api/auth/password` |
| Users | `GET/POST /api/users`; `GET/PUT/DELETE /api/users/{user}` |
| Companies | `GET/POST /api/companies`; `GET/PUT /api/companies/{company}`; `POST /{company}/activate`, `/{company}/deactivate`, `/{company}/switch`; `POST/DELETE /{company}/members[/{user}]` |
| Current company | `GET /api/company`; `GET/PUT /api/company/settings`; `PUT /api/company/settings/base-currency` |

### 7.2 Core accounting

| Area | Routes |
| --- | --- |
| Chart of accounts | `GET /api/accounts/types`; `GET/POST /api/accounts`; `GET/PUT/DELETE /api/accounts/{account}`; `POST /{account}/activate`, `/{account}/deactivate` |
| Accounting periods | `GET/POST /api/accounting/periods`; `GET/PUT /{period}`; `GET /{period}/closing-check`; `POST /{period}/close`, `/{period}/reopen` |
| Financial years | `GET/POST /api/accounting/financial-years`; `GET/PUT /{financialYear}`; `POST /{financialYear}/periods/generate`, `/{financialYear}/close` |
| Journals | `GET/POST /api/journals`; `GET/PUT/DELETE /{journal}`; `POST /{journal}/post` |
| Ledger reads | `GET /api/accounting/trial-balance`; `GET /api/accounting/accounts/{account}/balance` |
| Reports | `GET /api/accounting/reports/{trial-balance, general-ledger, profit-loss, balance-sheet, customer-statement, supplier-statement, receivables, payables, receivables-aging, payables-aging, cash-bank}` |

### 7.3 Cash, banking, reconciliation

| Area | Routes |
| --- | --- |
| Cash/bank accounts | `GET /api/cash-bank-accounts`; `PUT /{account}`; `DELETE /{account}/bank-details`; `POST /{account}/activate`, `/{account}/deactivate` |
| Transactions | `GET/POST`-driven `GET /api/cash-bank-transactions`; `POST /deposits`, `/withdrawals`, `/transfers`; `GET/PUT/DELETE /{transaction}`; `POST /{transaction}/post` |
| Reconciliation | `GET/POST /api/bank-reconciliations`; `GET/PUT/DELETE /{reconciliation}`; `GET /{reconciliation}/movements`; `POST /{reconciliation}/items`; `DELETE /{reconciliation}/items/{item}`; `POST /{reconciliation}/complete`, `/{reconciliation}/reopen` |

### 7.4 Tax

`GET/POST /api/accounting/taxes`; `GET/PUT/DELETE /{tax}`; `POST /{tax}/activate`,
`/{tax}/deactivate`; `GET/POST /{tax}/rates`; `PUT/DELETE /{tax}/rates/{rate}`;
`POST /{tax}/rates/{rate}/activate`, `/deactivate`; `GET/PUT /{tax}/account-mapping`;
`POST /api/accounting/tax/calculate`; `GET /api/accounting/tax-reports/summary`,
`/by-tax`.

### 7.5 Business modules present in the backend but absent on the frontend

| Module | Representative routes |
| --- | --- |
| Customers | `GET/POST /api/customers`; `GET/PUT /{customer}`; `POST /{customer}/deactivate` |
| Suppliers | `GET/POST /api/suppliers`; `GET/PUT /{supplier}`; `POST /{supplier}/deactivate` |
| Sales invoices | `GET/POST /api/sales-invoices`; `GET/PUT/DELETE /{invoice}`; `POST /{invoice}/post`; `GET /{invoice}/adjustable-lines` |
| Purchase bills | `GET/POST /api/purchase-bills`; `GET/PUT/DELETE /{bill}`; `POST /{bill}/post`; `GET /{bill}/adjustable-lines` |
| Credit/debit notes | `GET/POST /api/credit-debit-notes`; `GET/PUT/DELETE /{creditDebitNote}`; `POST /{creditDebitNote}/post` |
| Customer receipts | `GET/POST /api/customer-receipts`; `GET/PUT/DELETE /{receipt}`; `POST /{receipt}/post` |
| Supplier payments | `GET/POST /api/supplier-payments`; `GET/PUT/DELETE /{payment}`; `POST /{payment}/post` |
| Fixed assets | `GET/POST /api/accounting/fixed-asset-categories`; `GET/POST /api/accounting/fixed-assets` (`/register`, `/depreciation-report`, `/cash`, `/supplier-credit`, `/{id}/capitalize`, `/{id}/depreciation-schedule`, `/{id}/depreciation`, `/{id}/dispose`) |
| Currencies / FX | `GET/POST /api/accounting/currencies` (+ activate/deactivate); `GET/POST /api/accounting/exchange-rates` (+ activate/deactivate); `GET/PUT /api/accounting/fx-settings`; `GET /api/accounting/controls` |
| Budgets | `GET/POST /api/accounting/budgets`; `GET/PUT/DELETE /{budget}`; `POST /{budget}/approve`, `/{budget}/revise`; `GET /{budget}/variance`; `POST /{budget}/lines`; `PUT/DELETE /{budget}/lines/{line}` |
| Financial dimensions | `GET/POST /api/accounting/dimensions`; `GET/PUT/DELETE /{dimension}`; `POST /{dimension}/activate`, `/deactivate`; `GET/POST /{dimension}/values`; `GET/PUT/DELETE /{dimension}/values/{value}`; `POST /{dimension}/values/{value}/activate`, `/deactivate` |

**No inventory or payroll module exists in the backend.** **[VERIFIED]**

### 7.6 Permission model (`backend/config/authorization.php`, `PermissionName.php`)

- **Admin** — `*` (all permissions).
- **Accountant** — broad CRUD; **excludes** `accounting.currency.create`, currency
  activate/deactivate, `accounting.budgets.approve`, `accounting.periods.close/reopen`.
- **Manager** — read-only across modules **plus** `bank_reconciliation.*` actions,
  `accounting.budgets.approve`, `companies.update`/settings.
- **Staff** — `companies.view` only.

Frontend permission helper is `src/lib/permissions/permissions.ts`; it fails closed
(no role data ⇒ no permissions) and is used **only** for render decisions — the backend
remains the authority. **[VERIFIED]**

---

## 8. Feature coverage matrix

**Status values** are exactly the five defined by the brief: **Complete** (functionality
exists with sufficient verification evidence), **Partial** (some functionality, documented
gap), **Missing** (absent), **Unverified** (evidence insufficient), **Blocked** (external/
backend/infrastructure dependency prevents completion).

> Reminder: a route existing does **not** make a feature Complete. "Complete" below means
> the frontend screen exists, is wired to the verified backend contract, and has unit/
> component test evidence — **not** live integration (none exists; see §12).

| Feature/module | Backend status | Frontend status | Route/API evidence | Permission/security status | Test/integration status | Gap or dependency | Recommended phase |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Login / logout / session / me | Existing | Complete | `/login`, `/api/session/{login,logout,me}` | Verified (sealed HttpOnly cookie) | Unit: `lib/auth/*`; no live | None | No action |
| Company context & switch | Existing | Complete | `/api/session/company`, `useAuth` | Verified (server-injected `X-Company-Id`) | Unit: `lib/auth/snapshot.test.ts` | Company-switch cache invalidation is statically handled; live unverified | No action / Phase 21 |
| Register / forgot / reset password / email verification | Existing | Missing | Backend `POST /api/auth/register`, `/forgot-password`, `/reset-password`, `/email/verify/*` | Unverified (no UI) | None | No screens; only `/login` exists | Phase 20 |
| Users management | Existing | Missing | `GET/POST/PUT/DELETE /api/users` | Unverified (proxy allows `users`) | None | No screens; nav `upcoming` only | Phase 20 |
| Company settings (+ base-currency change) | Existing | Missing | `GET/PUT /api/company/settings`, `/settings/base-currency` | Unverified | None | No screens; FX implications | Phase 18/20 |
| Chart of accounts | Existing | Complete | `/accounts`, `lib/accounts/*` | Verified | Unit: `lib/accounts/*.test.ts`, `components/accounts/accounts-view.test.tsx` | Account picker caps at 50 (`ACCOUNT_LIST_PER_PAGE`) | Phase 13 (picker) |
| Journals & posting | Existing | Complete | `/journals`, `lib/journals/*` | Verified | Unit + component tests | None | No action |
| Financial years | Existing | Complete | `/financial-years`, `lib/financial-years/*` | Verified | Unit + component tests | None | No action |
| Accounting periods | Existing | Complete | `/periods`, `lib/periods/*` | Verified | Unit + component tests | `close/reopen` per backend permissions | No action |
| Trial Balance report | Existing | Complete (uncommitted) | `/reports/trial-balance`, `lib/reports/use-trial-balance.ts` | Verified (`accounting.reports.view`) | **No tests** for reports module | Needs tests; lint warning `exhaustive-deps` | Phase 13 |
| Profit & Loss report | Existing | Complete (uncommitted) | `/reports/profit-loss`, `lib/reports/use-profit-loss.ts` | Verified | **No tests**; `exhaustive-deps` warning | Dimension filter not exposed (backend supports it) | Phase 13 / Phase 19 |
| General Ledger report | Existing | **Partial** (non-functional) | `/reports/general-ledger`, `components/reports/general-ledger-view.tsx` | Verified route guard, but broken UX | **No tests**; lint **error** `set-state-in-effect` | Renders only a heading; no movements/balance/states | **Phase 13 (P0)** |
| Balance Sheet report | Existing | Missing | `GET /api/accounting/reports/balance-sheet` | Unverified | None | No route/screen | Phase 13 |
| Cash/bank report | Existing | Missing | `GET /api/accounting/reports/cash-bank` | Unverified | None | No route/screen | Phase 13 |
| Cash & bank account config | Existing | Complete | `/cash-bank`, `lib/cash-bank/*` | Verified | Unit + component tests | None | No action |
| Cash & bank transactions | Existing | Complete | `/cash-bank/transactions` | Verified | Unit + component tests | None | No action |
| Bank reconciliation | Existing | **Partial** | `/bank-reconciliations[/[id]]`, `lib/bank-reconciliations/*` | Verified guard | Unit + component tests | **Blocked sub-capability:** no read endpoint exposes `bank_accounts.id` (create/edit omitted) nor reconciliation item id (item removal only for session-cleared items) | Phase 21 (+ backend dep) |
| Tax definitions / rates / mapping | Existing | Complete | `/taxes`, `/taxes/[id]`, `lib/taxes/*` | Verified | Unit + component tests | None | No action |
| Tax calculator | Existing | Complete | `/tax-calculator` | Verified | Component test | None | No action |
| Tax reports (summary / by-tax) | Existing | Complete | `/tax-reports` | Verified | Component test | None | No action |
| Customers | Existing | Missing | `/api/customers` | Unverified (proxy allows) | None | No screens/API wrappers; nav `upcoming` | Phase 14 |
| Sales invoices | Existing | Missing | `/api/sales-invoices` | Unverified | None | No screens; draft vs posted lifecycle, no client-supplied numbers | Phase 14 |
| Customer receipts | Existing | Missing | `/api/customer-receipts` | Unverified | None | Depends on customers + invoices | Phase 14 |
| Customer statement report | Existing | Missing | `GET /api/accounting/reports/customer-statement` | Unverified | None | Depends on customers | Phase 14 |
| Receivables report | Existing | Missing | `GET /api/accounting/reports/receivables` | Unverified | None | Dashboard shows receivables KPI only | Phase 14 |
| Receivables aging report | Existing | Missing | `GET /api/accounting/reports/receivables-aging` | Unverified | None | No screen | Phase 14 |
| Suppliers | Existing | Missing | `/api/suppliers` | Unverified | None | No screens | Phase 15 |
| Purchase bills | Existing | Missing | `/api/purchase-bills` | Unverified | None | No screens | Phase 15 |
| Supplier payments | Existing | Missing | `/api/supplier-payments` | Unverified | None | Depends on suppliers + bills | Phase 15 |
| Supplier statement report | Existing | Missing | `GET /api/accounting/reports/supplier-statement` | Unverified | None | Depends on suppliers | Phase 15 |
| Payables report | Existing | Missing | `GET /api/accounting/reports/payables` | Unverified | None | Dashboard shows payables KPI only | Phase 15 |
| Payables aging report | Existing | Missing | `GET /api/accounting/reports/payables-aging` | Unverified | None | No screen | Phase 15 |
| Credit & debit notes | Existing | Missing | `/api/credit-debit-notes` (+ `adjustable-lines` on invoices/bills) | Unverified | None | Depends on invoices/bills | Phase 16 |
| Fixed assets (categories, assets, register, depreciation, disposal) | Existing | Missing | `/api/accounting/fixed-asset*` | Unverified | None | In blueprint §14 order; **not** in nav `upcoming` | Phase 17 |
| Currencies & exchange rates & FX settings | Existing | Missing | `/api/accounting/currencies`, `/exchange-rates`, `/fx-settings`, `/controls` | Unverified | None | In blueprint §14 order; **not** in nav `upcoming`; base-currency change coupling | Phase 18 |
| Budgets (lines, variance, approve, revise) | Existing | Missing | `/api/accounting/budgets*` | Unverified | None | In blueprint §14 order; **not** in nav `upcoming` | Phase 19 |
| Financial dimensions (values/lifecycle) | Existing | Missing | `/api/accounting/dimensions*` | Unverified | None | In blueprint §14 order; **not** in nav `upcoming`; unlocks P&L/budget-variance filters | Phase 19 |
| Dashboard | Existing | Complete | `/`, `lib/dashboard/*` | Verified | Unit + component tests | Consumes receivables/payables KPIs only | No action |
| BFF proxy & security boundary | N/A (frontend) | Complete | `/api/backend/[...path]`, `proxy-policy.ts`, `session.ts`, `session-crypto.ts` | Verified | Unit: `proxy-policy.test.ts`, `session-crypto.test.ts`, `backend.test.ts` | None | No action |
| Navigation | N/A (frontend) | Complete | `lib/navigation/nav.ts` | Verified (permission-filtered) | Covered indirectly | Stale "Ledgers" placeholder vs real GL link | Phase 13 |
| Live/E2E integration verification | N/A | **Unverified** | — | — | **Never performed** | No live harness of any kind | **Phase 21 (P0)** |
| Reports module automated tests | N/A | Missing | `lib/reports/*`, `components/reports/*` | N/A | **Zero reports tests** | 0 test files under `lib/reports`/`components/reports` | Phase 13 (P1) |

---

## 9. Accounting correctness findings

All statements below are **static** unless marked. No live ledger was exercised.

- **[VERIFIED] Posted journals are the source of balances.** Report hooks call the
  backend report endpoints; no client-side recomputation of ledger balances was found.
  Dashboard and reports read server totals.
- **[VERIFIED] Draft vs posted separation is honoured.** Journals expose create/edit
  (draft) separately from `POST /journals/{journal}/post`; the frontend does not present
  draft journals as posted activity in the ledger/report screens reviewed.
- **[VERIFIED] Monetary precision is decimal-string based.** `src/lib/format/money.ts`
  formats exact decimal strings for display and does **not** perform floating-point
  arithmetic for totals; totals come from the backend. Report types carry string decimals.
- **[VERIFIED] Backend totals remain authoritative.** Reports render server-provided
  figures; no frontend sum is authoritative.
- **[RUNTIME] Date-range semantics** (`from`/`to`, period/date filters) are passed through
  to the backend unchanged. Correctness of boundary behaviour (inclusive/exclusive) cannot
  be confirmed without a running backend.
- **[RUNTIME] Company isolation on manipulated identifiers.** The design is sound
  (company-scoped route-model binding returns 404 for cross-company ids, and the proxy
  injects `X-Company-Id` server-side from the sealed session), but no live test proves it.
- **[RUNTIME] Fiscal-year / period restrictions** are enforced by the backend; the
  frontend surfaces the errors it receives. Unverified at runtime.
- **[VERIFIED] Foreign-currency vs base-currency is not conflated in the UI.** Per
  `FRONTEND_API_HANDOFF.md` §13.6, documents are base-currency in the implemented scope
  and foreign provenance is on journal lines; the journals module labels foreign lines
  with their `currency_code` and renders base amounts as plain decimals.
- **[VERIFIED] Historical tax treatment** is delegated to the backend tax-report
  endpoints; no client-side tax recomputation exists.
- **[INFERRED] GL drill-down parameters.** Trial Balance and P&L link to
  `/reports/general-ledger?account_id=…&from=…&to=…`. The GL view parses these in a
  `useEffect` via `setState` (flagged by lint), and the target screen does not render the
  resulting data — so the drill-down currently terminates at a blank body.

No accounting-integrity **defect** was found in the committed code. The one
accounting-reporting defect is the non-functional General Ledger screen (P0, §13).

---

## 10. Security and permission findings

The security boundary is the strongest part of the implementation and is statically
verified. **[VERIFIED]** unless noted.

### 10.1 Session and token handling

- **JWT is sealed with AES-256-GCM** into an HttpOnly, SameSite=Lax session cookie
  (`lib/auth/session-crypto.ts`, `lib/auth/session.ts`). The token is **never** exposed to
  browser JavaScript. **[VERIFIED]**
- **Production requires `SESSION_COOKIE_SECRET` of at least 16 characters.** A development
  fallback is permitted only when `NODE_ENV !== "production"`. **[VERIFIED]**
- **401 clears the session** centrally. **[VERIFIED]**

### 10.2 BFF proxy boundaries (`lib/api/proxy-policy.ts`, `src/app/api/backend/[...path]/route.ts`)

- **Allow-list, not a transparent pipe.** Only families in `COMPANY_SCOPED_FAMILIES` and
  `GLOBAL_AUTHENTICATED_FAMILIES` are reachable; everything else returns a **404 that is
  indistinguishable from a real not-found** (no route enumeration). **[VERIFIED]**
- **Traversal is rejected:** percent-encoding, backslashes (`\`), slashes, whitespace,
  control chars and `.`/`..` are rejected by a conservative segment grammar; max 12
  segments, 128 chars/segment. **[VERIFIED]**
- **`POST /companies/{id}/switch` is explicitly blocked** at the proxy; the safe equivalent
  is the BFF's `/api/session/company` (which validates membership). **[VERIFIED]**
- **Headers:** `Authorization`, `Cookie`, `X-Company-Id`, `Host` and all other client
  headers are dropped; `Authorization` and `X-Company-Id` are written **server-side last**
  so a client header can never override them. Only `Accept`, `Content-Type` and a
  validated `X-Request-Id` are forwarded. **[VERIFIED]**
- **Request-size enforcement is byte-accurate:** both `Content-Length` and the actual
  `arrayBuffer().byteLength` are bounded at **10 MB**
  (`MAX_BODY_BYTES = 10 * 1024 * 1024`). **[VERIFIED]**
- **`redirect: "manual"`** prevents the proxy following upstream redirects; response
  headers are filtered to `Content-Type` and `X-Request-Id`, and responses are
  `Cache-Control: no-store` (user/company-specific data is not cached). `Set-Cookie` and
  `Location` from upstream never reach the browser. **[VERIFIED]**

### 10.3 Permissions and company context

- Permission helpers **fail closed** and are used only for rendering; the backend
  authorises every request. **[VERIFIED]**
- Company identifier is derived **server-side** from the sealed session, never trusted from
  the browser. **[VERIFIED]**
- Nav items carry the same permission string the backend enforces. **[VERIFIED]**

### 10.4 Findings

- **[VERIFIED] No security defect found** in the committed security implementation.
- **[INFERRED] Residual risk — company-switch stale data.** Company switching is handled
  server-side and hooks are keyed by company id with `AbortController`; no statically
  visible stale-cache path exists, but this is exactly the kind of behaviour that should be
  exercised in a live test (P0 verification, §13).
- **[RUNTIME] No live penetration/integration test of the proxy has been run.** All
  evidence is from unit tests (`proxy-policy.test.ts`, 21 tests) and source reading.

---

## 11. UI and accessibility findings

- **[VERIFIED] Design system is coherent:** shared `components/ui/*` primitives
  (`button`, `card`, `dialog`, `field`, `badge`, `empty-state`, `error-state`,
  `loading-state`, `spinner`, `toaster`), token-driven theming in `globals.css`
  (calm green light mode / professional dark mode) via CSS variables, and
  light/dark parity of chart colours.
- **[VERIFIED] Loading / empty / error states exist** as shared components and are used
  across modules — **except** in `general-ledger-view.tsx`, which imports them but does not
  render them.
- **[VERIFIED] Money/date formatting is centralised** (`lib/format/money.ts`,
  `lib/dashboard/format.ts`) and uses the company/report decimals.
- **[VERIFIED] Dialog accessibility is good:** `role="dialog"`, `aria-modal="true"`,
  `aria-labelledby`, Escape-to-close, focus moved into the panel, focus trapped
  (first/last Tab wrap) and restored on close, body scroll locked.
- **[INFERRED] Latent duplicate-id accessibility risk:** `Dialog` hardcodes
  `const titleId = "dialog-title"`. If two dialogs are ever rendered simultaneously, both
  would carry `id="dialog-title"` and `aria-labelledby` would resolve ambiguously. No
  current double-dialog usage was found, so this is a latent risk, not an active bug.
- **[INFERRED] Account picker cap:** `ACCOUNT_LIST_PER_PAGE = 50`
  (`lib/accounts/use-account-list.ts`). The General Ledger account selector loads page 1
  only, so a company with more than 50 accounts would have an incomplete picker.
- **[RUNTIME] Responsive layout, table behaviour on small screens, and keyboard
  navigation across all screens were not exercised in a browser.** No automated
  accessibility check exists (no axe), so accessibility claims beyond static source
  inspection are unverified.
- **[VERIFIED] No automated accessibility test suite exists.**

---

## 12. Automated testing and live integration status

**Read-only commands run in this audit (exact results):**

| Command | Working dir | Result |
| --- | --- | --- |
| `npm run lint` | `accounting-app/frontend` | **FAIL — exit 1; 7 errors, 17 warnings** |
| `npx tsc --noEmit` | `accounting-app/frontend` | **PASS — exit 0** |
| `npm test` (`vitest run`) | `accounting-app/frontend` | **PASS — 39 files, 409 tests** |

`npm run build` was previously recorded as succeeding (all routes emitted, including the
three `/reports/*`); it was **not** re-run in this session. **[RUNTIME]** for any re-run.

**Lint detail [VERIFIED]** — every one of the 24 problems is in the uncommitted Phase 11
files, so committed code (Phases 0–10) is lint-clean:

- `src/lib/reports/validators.ts` — **6 errors**, `@typescript-eslint/no-explicit-any` at
  lines `104:40`, `105:40`, `106:41`, `107:41`, `109:39`, `117:40`.
- `src/components/reports/general-ledger-view.tsx` — **1 error**,
  `react-hooks/set-state-in-effect` at `42:5`; plus multiple `no-unused-vars` warnings
  (`setReloadToken`, `accounts`, `state`, `apply`).
- `src/lib/reports/use-{general-ledger,profit-loss,trial-balance}.ts` — `react-hooks/exhaustive-deps`
  warnings (missing `filters` dependency) at `59:6` in each.
- Total: **7 errors, 17 warnings.**

**Test coverage inventory [VERIFIED]** — 39 test files, of which there are **zero** for the
reports module: nothing under `src/lib/reports/**` and nothing under
`src/components/reports/**` has a test. (The file `components/taxes/tax-reports-view.test.tsx`
is a *tax* report test, not a `lib/reports` test.) Areas covered: accounts, journals,
periods, financial-years, taxes, cash-bank, bank-reconciliations, dashboard, auth,
permissions, proxy policy, API client.

**Explicitly absent (all confirmed by repository inspection) [VERIFIED]:**

- No integration tests against a running backend.
- No browser/end-to-end tests — no Playwright, no Cypress.
- No automated accessibility tests — no axe.
- No accounting-precision property tests beyond unit formatting tests.
- No company-isolation runtime tests (only unit-level permission/proxy tests).

**Live integration status: never performed. [VERIFIED]**
Every phase report through Phase 11 states integration was not performed ("no PHP/DB in
this environment"). Tests are unit/component tests against mocks. A passing suite and a
successful production build **do not prove** live API integration or complete user
workflows. The frontend is therefore **Unverified** end-to-end.

---

## 13. P0 / P1 / P2 / P3 findings

### P0 — Critical (security, data isolation, accounting integrity, release-blocking)

**P0-1 — General Ledger screen is non-functional while linked and advertised.**
`/reports/general-ledger` renders only a heading; the data hook and filters are wired but
the movements table, running balance, and loading/error/empty states are never rendered.
It is linked from the sidebar and is the drill-down target of Trial Balance and P&L.
*Rationale:* it is an accounting-reporting surface presented to users as available; a
navigable report that shows nothing is a release-blocking defect and touches accounting-
reporting integrity. → **Phase 13.**

**P0-2 — The lint quality gate fails (exit 1).**
7 errors + 17 warnings, all in the Phase 11 reports files; the Phase 11 report labels the
gate "Lint/type-check/build" but reports only `tsc`. *Rationale:* the repository's own
release gate is red; shipping with a failing gate is release-blocking. → **Phase 13.**

**P0-3 — No live/end-to-end integration verification has ever been performed.**
No browser/E2E harness, no live backend run, no company-isolation or precision test
against a real database. *Rationale:* production readiness is unprovable without it; this
is the single biggest release risk. → **Phase 21.**

### P1 — Required (essential agreed-release functionality or integration)

**P1-1 — Zero automated tests for the reports module** (`lib/reports`, `components/reports`).
*Rationale:* Trial Balance, P&L and GL are release scope; regressions in financial figures
must be caught.

**P1-2 — Core reports missing: Balance Sheet, Cash/Bank report.**
Backend endpoints exist; no frontend routes. *Rationale:* central financial statements
named in the blueprint release scope.

**P1-3 — Sales & receivables module missing:** customers, sales invoices, customer receipts.
*Rationale:* first module group after reports in the blueprint §14 order; core agreed scope.

**P1-4 — Purchases & payables module missing:** suppliers, purchase bills, supplier payments.
*Rationale:* symmetric with P1-3; core agreed scope.

**P1-5 — Credit & debit notes missing.**
*Rationale:* required to correct posted invoices/bills; depends on P1-3/P1-4.

**P1-6 — Receivables/payables, aging and customer/supplier statement reports missing.**
Backend endpoints exist. *Rationale:* named reports in the blueprint; the dashboard already
surfaces receivables/payables KPIs, so the underlying data is in the agreed scope.

**P1-7 — Company administration missing:** company settings (incl. base-currency change)
and users & roles. *Rationale:* operations/compliance surfaces; company settings is already
referenced by the FX/base-currency contract.

**P1-8 — Authentication completion missing:** register, forgot/reset password, email
verification. *Rationale:* `FRONTEND_BLUEPRINT.md` §14 step 5 explicitly lists these
screens; only `/login` exists.

### P2 — Important (usability, accessibility, operational)

- **P2-1 — Fixed assets, currencies/FX and budgets/dimensions frontends missing.** In the
  blueprint §14 ordered roadmap, but **absent from nav `upcoming`** (scope conflict, §18).
  **[UNRESOLVED]**
- **P2-2 — Bank reconciliation create/edit is blocked** by the backend contract: no read
  endpoint exposes `bank_accounts.id` or the reconciliation item id. Backend dependency.
- **P2-3 — Account picker capped at 50 accounts** (`ACCOUNT_LIST_PER_PAGE`) with no
  pagination/search beyond the first page in the GL selector.
- **P2-4 — Latent duplicate `id="dialog-title"`** in `components/ui/dialog.tsx` if two
  dialogs ever render at once.
- **P2-5 — P&L dimension filter not exposed** even though the backend supports
  `dimension_id`/`dimension_value_id` (also depends on P2-1 dimensions).
- **P2-6 — Stale "Ledgers" `upcoming` placeholder** in nav alongside the real General
  Ledger link.
- **P2-7 — Phase 11 report inaccuracies** (empty §1, `Status: In progress` vs "complete",
  lint result omitted) — documentation integrity.
- **P2-8 — No automated accessibility checks** and no browser-level responsive/keyboard
  verification.
- **P2-9 — Backend pagination metadata absent (P2-1 in `PHASE_18_REPORT.md` / API handoff
  §13.1)** — list screens must design around it until the envelope is revised.

### P3 — Optional (safe to defer past initial release)

- **P3-1 — CSV/export** of report data.
- **P3-2 — Trend charts / cash-flow statements / comparative-period reporting** — explicitly
  deferred by design (`FRONTEND_API_HANDOFF.md` §13.5).
- **P3-3 — Consolidation, FX revaluation, country-specific tax filing / e-invoicing, PDF
  generation** — explicitly deferred by design; do not assume endpoints exist.

---

## 14. Deferred and out-of-scope functionality

Recorded as **out of scope / deferred by design** per documentation (not as "missing"):

- Comparative-period reporting, cash-flow statements, consolidation, unrealized FX
  revaluation, country-specific tax filing / e-invoicing, PDF generation endpoints
  (`FRONTEND_API_HANDOFF.md` §13.5).
- Dimension filtering for Trial Balance, General Ledger and Balance Sheet (deliberately
  deferred; only P&L and Budget Variance are dimension-filterable — §13.4).
- No public audit-log read API (§13.3).
- No refresh token; re-authenticate on 401 (§13.2).
- Backend pagination metadata is a **backend follow-up, not frontend scope**
  (`FRONTEND_BLUEPRINT.md` §15.8).

**Deferred within the frontend but planned:** the modules listed in §13 P1/P2 that the
blueprint orders. These are *planned*, not "not required".

---

## 15. Definitive remaining-phase roadmap

Derived **only** from the gaps in §8/§13, respecting the blueprint §14 dependency order
and separating feature work from cross-cutting production-readiness work. The backend
already implements every endpoint referenced; therefore no phase below requires backend
changes **except** where explicitly flagged (P2-2). Backend changes remain outside frontend
phases unless separately authorised.

Each phase below states: objective, in-scope features/routes, backend dependencies,
security/permission requirements, required tests, acceptance criteria, exclusions.

---

### Phase 13 — Complete Accounting Reporting Core & Phase 11 Remediation

- **Objective:** make the reporting module release-clean and complete the core financial
  statements.
- **In scope:** fix the 6 `no-explicit-any` errors in `lib/reports/validators.ts`; fix the
  `set-state-in-effect` error and unused-variable warnings in
  `components/reports/general-ledger-view.tsx`; implement the General Ledger movements
  table + running balance + loading/error/empty states; add the **Balance Sheet** report
  (`/reports/balance-sheet`) and the **Cash/Bank** report (`/reports/cash-bank`); add report
  tests; resolve the stale "Ledgers" nav placeholder; remove the `exhaustive-deps` warnings
  in the three `use-*` report hooks.
- **Backend dependencies:** existing `GET /api/accounting/reports/{general-ledger, balance-sheet, cash-bank}`.
- **Security/permissions:** `accounting.reports.view`; keep the proxy allow-list unchanged
  (reports are under the already-allowed `accounting` family).
- **Required tests:** validators + view tests for GL, Trial Balance, P&L, Balance Sheet,
  Cash/Bank; unit tests for the report hooks.
- **Acceptance criteria:** `npm run lint` exits 0; `npx tsc --noEmit` exits 0; `npm test`
  green; General Ledger renders movements and a running balance for a selected account and
  for Trial Balance / P&L drill-downs; Balance Sheet balances are shown exactly as returned.
- **Exclusions:** no dimension filter work (Phase 19); no export (P3).

### Phase 14 — Sales & Receivables

- **Objective:** build the sales/receivables screens and their reports.
- **In scope:** `/customers`, `/sales-invoices` (+ detail, draft-edit, post),
  `/customer-receipts`; reports `/reports/customer-statement`, `/reports/receivables`,
  `/reports/receivables-aging`; API wrappers + validators + tests; add real nav links.
- **Backend dependencies:** `/api/customers`, `/api/sales-invoices` (+ `/adjustable-lines`),
  `/api/customer-receipts`, and the matching report endpoints. All already allowed by the proxy.
- **Security/permissions:** sales permissions per `PermissionName.php`; never accept a
  client-supplied invoice number/status/total; keep draft vs posted on separate paths.
- **Required tests:** validators, API, hook and view tests; draft-immutability rendering.
- **Acceptance criteria:** a draft invoice can be created, edited and posted; a posted
  invoice is immutable in the UI; receipts apply to invoices; statements/receivables/aging
  render server figures; lint/tsc/test green.
- **Exclusions:** credit/debit notes (Phase 16); multi-currency documents (Phase 18).

### Phase 15 — Purchases & Payables

- **Objective:** build the purchase/payables screens and their reports.
- **In scope:** `/suppliers`, `/purchase-bills` (+ detail, draft-edit, post),
  `/supplier-payments`; reports `/reports/supplier-statement`, `/reports/payables`,
  `/reports/payables-aging`; wrappers + validators + tests; real nav links.
- **Backend dependencies:** `/api/suppliers`, `/api/purchase-bills` (+ `/adjustable-lines`),
  `/api/supplier-payments`, matching report endpoints.
- **Security/permissions:** purchasing permissions; same server-owned-field discipline.
- **Required tests:** validators, API, hook and view tests.
- **Acceptance criteria:** parallel to Phase 14 for the payables side; gates green.
- **Exclusions:** credit/debit notes (Phase 16).

### Phase 16 — Credit & Debit Notes

- **Objective:** implement note-based correction of posted invoices/bills.
- **In scope:** `/credit-debit-notes` (list, create, edit, post) with invoice/bill
  `adjustable-lines` selection; wrappers + validators + tests; nav link.
- **Backend dependencies:** `/api/credit-debit-notes`, `/api/sales-invoices/{invoice}/adjustable-lines`,
  `/api/purchase-bills/{bill}/adjustable-lines`.
- **Security/permissions:** notes permissions; posted notes immutable.
- **Required tests:** validators, API, hook and view tests; adjustable-line validation.
- **Acceptance criteria:** a note can be built from adjustable lines of a posted document
  and posted; UI shows resulting document state.
- **Exclusions:** applying notes to multi-currency documents (Phase 18).

### Phase 17 — Fixed Assets

- **Objective:** build fixed-asset management and depreciation.
- **In scope:** `/fixed-assets` (categories, asset register, capitalization,
  depreciation schedule + run, disposal), `/reports/fixed-assets` (register,
  depreciation report); wrappers + validators + tests; nav link.
- **Backend dependencies:** `/api/accounting/fixed-asset-categories`,
  `/api/accounting/fixed-assets` (`/register`, `/depreciation-report`, `/cash`,
  `/supplier-credit`, `/{id}/capitalize`, `/{id}/depreciation-schedule`,
  `/{id}/depreciation`, `/{id}/dispose`).
- **Security/permissions:** fixed-asset permissions; disposals are lifecycle actions.
- **Required tests:** validators, API, hook and view tests.
- **Acceptance criteria:** an asset can be created, capitalized, depreciated and disposed;
  schedules/reports render server figures; gates green.
- **Exclusions:** FX-denominated asset acquisition (Phase 18).

### Phase 18 — Multi-Currency & Foreign Exchange

- **Objective:** expose currency, exchange-rate and FX-settings management, and the
  base-currency change path.
- **In scope:** `/currencies`, `/exchange-rates`, `/settings/fx` (fx-settings + controls);
  base-currency change surfaced through company settings (shared with Phase 20); wrappers +
  validators + tests; nav links.
- **Backend dependencies:** `/api/accounting/currencies` (+ activate/deactivate),
  `/api/accounting/exchange-rates` (+ activate/deactivate),
  `/api/accounting/fx-settings`, `/api/accounting/controls`,
  `PUT /api/company/settings/base-currency`.
- **Security/permissions:** note the Accountant role **excludes** `accounting.currency.create`
  and currency activate/deactivate — the UI must reflect this; base-currency change is
  destructive/guarded.
- **Required tests:** validators, API, hook and view tests; permission-gating tests.
- **Acceptance criteria:** currencies/rates can be maintained per role; base-currency change
  is guarded and clearly communicated; gates green.
- **Exclusions:** FX revaluation and document-level multi-currency (deferred by design).

### Phase 19 — Budgets & Financial Dimensions

- **Objective:** build budgets, budget variance and financial dimensions, and expose the
  P&L dimension filter.
- **In scope:** `/budgets` (create, lines, approve, revise, variance),
  `/dimensions` (dimension + value lifecycle); add dimension filtering to the P&L report and
  budget variance; wrappers + validators + tests; nav links.
- **Backend dependencies:** `/api/accounting/budgets*`, `/api/accounting/dimensions*`.
- **Security/permissions:** `accounting.budgets.approve` is granted to Manager/Admin, not
  Accountant — the approve action must be permission-gated; dimension lifecycle permissions.
- **Required tests:** validators, API, hook and view tests; approve-permission gating.
- **Acceptance criteria:** a budget with lines can be approved and its variance viewed;
  dimensions and values can be managed; P&L accepts a dimension filter; gates green.
- **Exclusions:** dimension filtering for TB/GL/Balance Sheet (deferred by design).

### Phase 20 — Company Administration & Authentication Completion

- **Objective:** complete company administration and the authentication lifecycle.
- **In scope:** `/settings/company` (company settings; base-currency hand-off from Phase 18),
  `/users` (users & roles), and `/register`, `/forgot-password`, `/reset-password`,
  email-verification screens; wrappers + validators + tests; nav links.
- **Backend dependencies:** `GET/PUT /api/company/settings`, `GET/POST/PUT/DELETE /api/users`,
  `POST /api/auth/register`, `/forgot-password`, `/reset-password`, `/email/verify/*`,
  `/email/verification-notification`.
- **Security/permissions:** `companies.update`/settings and user-management permissions;
  password/verification flows must not leak tokens; server-owned fields only.
- **Required tests:** validators, API, hook and view tests; permission gating; auth-flow
  component tests.
- **Acceptance criteria:** an admin can manage company settings and users; a new user can
  register, reset a password and verify an email; gates green.
- **Exclusions:** SSO/external identity (not in scope).

### Phase 21 — Production Readiness, Live Integration & Release Verification

- **Objective:** prove the application works against a running backend and close residual
  risks before release.
- **In scope:** stand up the backend for real integration testing; end-to-end / browser
  coverage of the critical workflows (login → company switch → accounts → journals → post →
  reports → sales → purchases → notes → receipts/payments → cash/bank → reconciliation →
  tax); company-isolation tests against a live database; 401/403/404/409/422 handling;
  accessibility and responsive verification; address the bank-reconciliation contract
  limitation (P2-2) either via an authorised backend change or a documented workaround;
  design list screens around the missing pagination metadata (P2-9); performance pass;
  final release checklist.
- **Backend dependencies:** a running backend + database (requires explicit authorisation
  to stand up); P2-2 backend contract gap.
- **Security/permissions:** runtime confirmation that JWTs never reach JS, that the
  proxy rejects traversal and unlisted families, and that cross-company ids 404 live.
- **Required tests:** Playwright (or equivalent) E2E suite; live API smoke tests;
  axe accessibility runs; company-isolation tests.
- **Acceptance criteria:** all critical workflows pass end-to-end against a live backend;
  no P0/P1 open; release checklist (§17) fully satisfied.
- **Exclusions:** implementing new features; deferred-by-design items (§14).

---

## 16. Total proposed remaining phases and final phase number

- **Number of proposed remaining phases:** **9** (Phases **13–21**).
- **Final phase number:** **21**.
- **Required to finish the currently agreed release:** Phases **13** (reporting core +
  remediation), **14** (sales/receivables), **15** (purchases/payables), **16** (credit/debit
  notes), **20** (company admin + auth completion), and **21** (production readiness /
  live integration). Phase 13 and Phase 21 are release-blocking.
- **In the blueprint's ordered scope but release-criticality unresolved (P2-1/§18):**
  Phases **17** (fixed assets), **18** (multi-currency/FX), **19** (budgets/dimensions).
  They are *planned*, not optional, but whether they gate the *initial* release is an open
  decision because nav `upcoming` omits them.

**What must be true before the frontend can be considered ready for release:**

1. `npm run lint`, `npx tsc --noEmit`, `npm test` and `npm run build` all pass.
2. The General Ledger screen is functional and drill-downs render correctly.
3. The agreed release modules (Phases 13–16, 20) are implemented, tested and green.
4. Live end-to-end verification against a running backend has actually been performed,
   including company isolation and monetary precision.
5. No open P0 findings remain (P0-1, P0-2, P0-3 above).

---

## 17. Release-readiness checklist

| # | Item | Status |
| --- | --- | --- |
| 1 | `npm run lint` passes | ❌ **FAIL** (7 errors, 17 warnings) |
| 2 | `npx tsc --noEmit` passes | ✅ PASS |
| 3 | `npm test` passes | ✅ PASS (39 files / 409 tests) |
| 4 | `npm run build` succeeds | ⚠️ Previously recorded pass; not re-run this session |
| 5 | No P0 findings open | ❌ 3 open (P0-1 GL, P0-2 lint, P0-3 no live verification) |
| 6 | No P1 findings open | ❌ 8 open (P1-1 … P1-8) |
| 7 | General Ledger functional | ❌ Renders heading only |
| 8 | Core reports complete (TB, P&L, GL, BS, Cash/Bank) | ❌ BS + Cash/Bank missing; GL broken |
| 9 | Sales/receivables modules complete | ❌ Missing |
| 10 | Purchases/payables modules complete | ❌ Missing |
| 11 | Credit/debit notes complete | ❌ Missing |
| 12 | Customer/supplier statements, aging, AR/AP reports | ❌ Missing |
| 13 | Company settings + users/roles | ❌ Missing |
| 14 | Register / forgot / reset / verify screens | ❌ Missing |
| 15 | Reports module has automated tests | ❌ Zero tests |
| 16 | Live/E2E integration performed | ❌ Never performed |
| 17 | Automated accessibility checks | ❌ None |
| 18 | Company-isolation verified at runtime | ❌ Unverified |
| 19 | Security boundary intact | ✅ Statically verified (no defect found) |
| 20 | All prior reports preserved | ✅ Verified |
| 21 | No backend/source/config modified in Phase 12 | ✅ Verified |

**Verdict: NOT release-ready.**

---

## 18. Unresolved decisions and external dependencies

**[UNRESOLVED]**

1. **Release scope for fixed assets, currencies/FX, budgets and dimensions.** The
   blueprint §14 places them in the ordered build order, but nav `upcoming` omits them.
   Decide whether they gate the initial release (they are modelled as Phases 17–19).
2. **Bank-reconciliation create/edit.** Blocked by the backend contract (no exposed
   `bank_accounts.id` / reconciliation item id). Resolve via an authorised backend change
   or a documented frontend workaround.
3. **Pagination metadata.** `PHASE_18_REPORT.md` and `FRONTEND_API_HANDOFF.md` §13.1 record
   the backend pagination gap as a backend follow-up; list-screen design must cope until
   the envelope changes.
4. **Account-picker cap.** Whether the 50-account cap is acceptable or needs
   pagination/search in pickers.
5. **Phase 11 report integrity.** The report should be corrected (empty §1, status
   inconsistency, missing lint result) — a documentation decision.
6. **Dimension filters for TB/GL/Balance Sheet** remain deliberately deferred; confirm this
   stays out of the initial release.
7. **Live environment.** Phase 21 requires a running backend + database, which needs
   explicit authorisation and infrastructure.

---

## 19. Files created or modified during Phase 12

| File | Action |
| --- | --- |
| `accounting-app/docs/FRONTEND_PHASE_12_REPORT.md` | **Created** (this report; the only permitted new file) |

**No other file was created, modified or deleted.** In particular, `nav.ts` and all
untracked Phase 11 files/reports/prompts/stray artefacts are unchanged from the initial
state recorded in §3. **[VERIFIED]**

---

## 20. Final Git status and preservation confirmation

Recorded after writing this report **[VERIFIED]**:

```
$ git status --porcelain -uall
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? "D\357\200\272DevelopmentAccountanceaccounting-appbackenddocsreportPHASE_17_REPORT.md"
?? accounting-app/docs/FRONTEND_PHASE_11_REPORT.md
?? accounting-app/docs/FRONTEND_PHASE_12_REPORT.md
?? accounting-app/frontend/src/app/(app)/reports/general-ledger/page.tsx
?? accounting-app/frontend/src/app/(app)/reports/profit-loss/page.tsx
?? accounting-app/frontend/src/app/(app)/reports/trial-balance/page.tsx
?? accounting-app/frontend/src/components/reports/general-ledger-view.tsx
?? accounting-app/frontend/src/components/reports/profit-loss-view.tsx
?? accounting-app/frontend/src/components/reports/trial-balance-view.tsx
?? accounting-app/frontend/src/lib/reports/api.ts
?? accounting-app/frontend/src/lib/reports/general-ledger-types.ts
?? accounting-app/frontend/src/lib/reports/profit-loss-types.ts
?? accounting-app/frontend/src/lib/reports/trial-balance-types.ts
?? accounting-app/frontend/src/lib/reports/types.ts
?? accounting-app/frontend/src/lib/reports/use-general-ledger.ts
?? accounting-app/frontend/src/lib/reports/use-profit-loss.ts
?? accounting-app/frontend/src/lib/reports/use-trial-balance.ts
?? accounting-app/frontend/src/lib/reports/validators.ts
?? "prompt/frontend/Phase 11.md"
?? "prompt/frontend/Phase 12.md"
```

**Difference from the initial state (§3): exactly one new untracked file —
`accounting-app/docs/FRONTEND_PHASE_12_REPORT.md`.** Every pre-existing tracked
modification, untracked feature file, report and stray artefact is preserved. No branch
change, no commit, no destructive operation. **Preservation confirmed.**

---

## Appendix A — Phase 11 quality-gate claim vs. observed result

| Gate | Phase 11 report §13 claims | Observed in Phase 12 |
| --- | --- | --- |
| TypeScript | `npx tsc --noEmit: exit 0` | ✅ exit 0 |
| Unit tests | `vitest run` 409/409 | ✅ 39 files / 409 tests |
| Build | "Next.js build succeeds" | ⚠️ not re-run this session |
| **Lint** | **Not reported** (though the label reads "Lint/type-check/build") | ❌ **`npm run lint` exits 1 — 7 errors, 17 warnings** |

**Discrepancy recorded, not resolved:** the Phase 11 report is labelled
`Status: In progress`, has an empty §1, reports no lint result, and concludes
`Phase 11 complete.` An actual lint run fails. This is a documentation-integrity finding
(P2-7) and a release-gate finding (P0-2).

## Appendix B — Proxy allow-list vs backend modules

`src/lib/api/proxy-policy.ts` permits: `company`, `accounts`, `accounting`, `journals`,
`customers`, `suppliers`, `sales-invoices`, `purchase-bills`, `credit-debit-notes`,
`customer-receipts`, `supplier-payments`, `cash-bank-accounts`, `cash-bank-transactions`,
`bank-reconciliations` (company-scoped), plus `users` and `companies` (authenticated).
Every backend business module in §7.5 is therefore already reachable through the proxy
(the accounting-prefixed ones via the `accounting` family). **Conclusion:** the missing
modules are **frontend implementation gaps, not proxy-blocked.**
