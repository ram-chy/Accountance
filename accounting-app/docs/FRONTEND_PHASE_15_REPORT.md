# Accountance Frontend — Phase 15 Report

**Phase:** 15 — Purchases & Payables (Suppliers, Purchase Bills, Supplier Payments, Payables Reports)
**Scope:** Frontend only
**Date:** 2026-10-13
**Report path:** `accounting-app/docs/FRONTEND_PHASE_15_REPORT.md`

> **Git note.** The Phase 15 brief prohibits Git commit and push unless
> explicitly authorized by the operator (§17 "Git Commit: Prohibited" /
> §18 "Git Push: Prohibited"). No commit was created, no push was performed,
> and no other prohibited Git operation (pull/fetch/reset/stash/clean/
> checkout/switch/restore/amend) was run. All Phase 15 work remains uncommitted
> as modified/untracked files for the operator to review and commit if desired.

---

## 1. Executive summary

Phase 15 delivered the complete Purchases & Payables area end to end against the
verified Laravel API, frontend-only:

- **Suppliers** — list (search, active/status filter, pagination), create/edit
  dialog, deactivation with confirmation, and a detail page with the payable
  account, contacts, and outstanding bills.
- **Purchase Bills** — list with filters (search, status, supplier, date range,
  outstanding), draft create/edit with exact-decimal live line preview (goods
  priced at `unit_cost` posting to `expense_account_id`), immutable detail page
  with lines, tax, totals, journal link, and posting/deletion for drafts.
- **Supplier Payments** — list, draft create/edit with bill allocation
  (outstanding bills auto-loaded per supplier; allocations must total exactly
  the payment amount — no advances), posting and deletion for drafts, detail
  page with allocations.
- **Payables reports** — Supplier Statement, Payables (open bills as of a
  date), and Aged Payables, driven by the verified report contracts, with
  data-driven aging buckets.
- **Navigation** — real menu items with the exact backend permission keys for
  the three new modules and the three new reports; "Debit notes" remains a
  clearly-marked upcoming placeholder.
- **Tests** — validators, API clients, totals arithmetic, views, and navigation
  coverage (680 tests across 72 files).

All quality gates pass. Backend files, dependencies, and prior reports are
unchanged. Live integration was **not** performed and is not claimed.

---

## 2. Initial branch, HEAD, and Git status

- Repository root: `D:\Ram da\Accountance`
- Branch: `main`
- HEAD: `4fc9d5e` — "docs: record final Phase 13 commit hash and git status in the report"
- Initial `git status --short --branch` (before any Phase 15 edit):

```
## main...origin/main
 M accounting-app/frontend/src/lib/api/errors.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
 M accounting-app/frontend/src/lib/reports/api.test.ts
 M accounting-app/frontend/src/lib/reports/api.ts
 M accounting-app/frontend/src/lib/reports/validators.test.ts
 M accounting-app/frontend/src/lib/reports/validators.ts
?? accounting-app/docs/FRONTEND_PHASE_14_REPORT.md
?? accounting-app/frontend/src/app/(app)/{customers,sales-invoices,customer-receipts}/…
?? accounting-app/frontend/src/app/(app)/reports/{customer-statement,receivables,receivables-aging}/
?? accounting-app/frontend/src/components/{customers,sales-invoices,customer-receipts}/…
?? accounting-app/frontend/src/components/reports/{customer-statement,receivables,receivables-aging}-view{,.test}.tsx
?? accounting-app/frontend/src/lib/{customers,sales-invoices,customer-receipts}/…
?? accounting-app/frontend/src/lib/reports/{customer-statement-types,receivables-types,receivables-aging-types,use-*}.ts{,.test}
?? "prompt/frontend/Phase 14.md"
```

All Phase 14 files were untracked/modified as the operator left them; none were
touched beyond the Phase 15 additions to `nav` and the reports lib tests.

---

## 3. Repository-preservation confirmation

- No existing backend file, dependency, or prior report was modified.
- Our changes touch frontend sources and tests only.
- The Phase 14 report and all earlier reports remain untouched.
- The only new document created is this report.

---

## 4. Backend contract inspection (recorded before implementation)

The purchase-side contracts were inspected route by route before any code was
written (routes, controllers, requests, resources, policies, services, config).
Key confirmations:

- **Response envelope** `{ success, message?, data? }` / `{ success:false, message, errors? }`
  via `ApiResponse`; errors carry `Record<string, string[]>`.
- **Pagination** returns a flat array; `hasMore` is inferred from a full page
  because the backend sends no links/meta (`DEFAULT_PER_PAGE = 25`).
- **Permission keys (Phase 15):** `suppliers.view/create/update/deactivate`;
  `purchases.bills.view/create/update/post/delete`;
  `supplier.payments.view/create/update/post/delete`; reports use
  `accounting.reports.view`. Deactivation is the only lifecycle action on
  suppliers (no hard delete, no reactivate route).
- **Purchase bill status** reuses `TransactionStatus` — bills are
  `DRAFT|POSTED|PARTIALLY_PAID|PAID`; drafts only are editable/deletable; `PUT`
  cannot post and `POST /{id}/post` takes no body (posted → 409).
  **Supplier payments** are `DRAFT|POSTED` with the same draft-only rules.
- **Supplier payments allocate against outstanding bills**; the allocation sum
  must equal the payment amount exactly (no advances). Outstanding bills come
  from `purchase-bills?supplier_id=X&outstanding=1` and carry `balance_due`.
- **Bill store payload** has no `currency_id`/`exchange_rate` — bills are
  created in the base currency via the API. Lines use `unit_cost` and
  `expense_account_id`; the optional tax account must be an ASSET (input tax
  receivable).
- **Report contracts.** Supplier statement requires `supplier_id` (else 422);
  payables and payables-aging accept optional `supplier_id` and `as_of` (default
  today). Aging buckets are config-driven (`config/accounting.php:
  reports.aging_buckets`) and are rendered from the response, never
  hard-coded. Statement rows are `{date,type,reference,description,debit,
  credit,running_balance,…}` with `type` values `bill|payment|credit_note|
  debit_note`; payables rows carry a `supplier` object and `balance_due`; aging
  rows carry a `counterparty` and a `buckets` record.
- **Money/quantities** are decimal strings; the UI formats with
  `formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS })`. Decimal
  arithmetic (form previews, allocation sums) is exact string/BigInt math that
  mirrors `DocumentCalculator` / `SettlementService`; the backend recomputes and
  remains authoritative.

---

## 5. Files added and modified

### New modules (lib layer)
- `src/lib/suppliers/{types,validators,api,use-suppliers,use-supplier,use-supplier-options}.ts`
- `src/lib/purchase-bills/{types,validators,api,totals,use-purchase-bills,use-purchase-bill}.ts`
- `src/lib/supplier-payments/{types,validators,api,use-supplier-payments,use-supplier-payment,use-outstanding-bills}.ts`

### Reports lib (extended)
- `src/lib/reports/{payables-types,payables-aging-types,supplier-statement-types}.ts`
- `src/lib/reports/{api,validators}.ts` — added `fetchSupplierStatement`,
  `fetchPayables`, `fetchPayablesAging` and the matching
  `parseSupplierStatement`, `parsePayables`, `parsePayablesAging` parsers.
- `src/lib/reports/{use-payables,use-payables-aging,use-supplier-statement}.ts`
  (supplier statement stays `idle` until a supplier is chosen, mirroring the
  customer statement hook).

### Components
- `src/components/suppliers/{suppliers-view,supplier-detail-view,supplier-form-dialog}.tsx`
- `src/components/purchase-bills/{purchase-bills-view,purchase-bill-form,purchase-bill-detail-view}.tsx`
- `src/components/supplier-payments/{supplier-payments-view,supplier-payment-form,supplier-payment-detail-view}.tsx`
- `src/components/reports/{payables-view,payables-aging-view,supplier-statement-view}.tsx`

### Routes
- `src/app/(app)/suppliers/{page.tsx,[id]/page.tsx}`
- `src/app/(app)/purchase-bills/{page.tsx,new/page.tsx,[id]/page.tsx,[id]/edit/page.tsx}`
- `src/app/(app)/supplier-payments/{page.tsx,new/page.tsx,[id]/page.tsx,[id]/edit/page.tsx}`
- `src/app/(app)/reports/{payables,payables-aging,supplier-statement}/page.tsx`

### Modified
- `src/lib/navigation/nav.ts` — moved Suppliers, Purchase bills and Supplier
  payments from `upcoming` into real, permission-keyed items; added the three
  report links to Reports. "Debit notes" stays an upcoming placeholder.
- `src/lib/reports/{api,validators}.test.ts` — extended for the new reports.

### Form refactors (rule `react-hooks/set-state-in-effect`)
- `purchase-bill-form.tsx` and `supplier-payment-form.tsx` were built as a
  wrapper (loads the entity, gates on company/permission/status) plus an inner
  editor that seeds with lazy `useState(() => seed(entity))`, keyed by the
  wrapper so remounts reseed. `supplier-form-dialog.tsx` seeds identically. No
  `setState` in effects; the ES2017 target is respected (`BigInt(...)`, never
  `0n` literals).

---

## 6. Implementation details per module

### Suppliers
- `SuppliersView`: search + active filter, permission/company gating,
  pagination, `New supplier` dialog. Detail page shows the payable account,
  contact details, and actions that deep-link to the supplier's outstanding
  bills, payments, and statement. Deactivate runs with a confirmation dialog.
- Write validation mirrors `Store/UpdateSupplierRequest`: required code/name,
  code pattern `/^[A-Za-z0-9][A-Za-z0-9._-]*$/` (max 50), email, 2-letter
  country, required payable account.

### Purchase Bills
- `PurchaseBillEditor` recomputes the line preview client-side with exact
  decimal arithmetic in `totals.ts` (gross = qty × unit_cost, net = gross −
  discount, tax = net × rate / 100, total = net + tax) mirroring
  `DocumentCalculator`; line discount may not exceed gross. The backend remains
  authoritative.
- Draft-only edit/delete/post; immutable document view shows status badges,
  posted journal link, lines, tax and totals, and is reached from the payables
  report drill-downs.
- Outstanding-bill query support (`outstanding=1`) feeds the payment editor.

### Supplier Payments
- Payment editor loads outstanding bills per supplier
  (`use-outstanding-bills` via `purchase-bills?supplier_id=X&outstanding=1`)
  and validates all allocations total exactly the payment amount (duplicate-bill
  and positive-amount checks). Draft-only edit/delete/post. Detail view lists
  allocations with per-bill drill-downs.

### Reports
- Supplier statement: opening balance, movement rows with debit/credit and
  running balance (payable-positive), closing balance card, period label.
- Payables: as-of date, supplier filter, per-bill totals and age badge,
  drill-down to the bill.
- Aged Payables: buckets rendered from the response (labels/keys), per-supplier
  row and bucket totals.

---

## 7. Automation coverage

Existing Vitest / Testing Library setup was reused; no dependency was added.

New test files (all mocked at the API-client / hook boundary):
- `lib/suppliers/validators.test.ts` — parse list/detail, error casing, write rules.
- `lib/purchase-bills/validators.test.ts` — parse, ES2017-safe exact decimal
  helpers, write rules incl. discount ≤ gross.
- `lib/purchase-bills/totals.test.ts` — exact multiply/add/subtract/divide,
  line and bill totals.
- `lib/purchase-bills/api.test.ts` — proxy routes/verbs, filters, no-body post.
- `lib/supplier-payments/validators.test.ts` — parse, allocation-sum and
  duplicate-bill rules, `decimalsEqual`.
- `lib/supplier-payments/api.test.ts` — proxy routes/verbs, filters, no-body post.
- `components/supplier-payments/supplier-payments-view.test.tsx` — list, draft
  actions, empty and error states.
- `components/reports/{payables-view,payables-aging-view,supplier-statement-view}.test.tsx`
  — company/permission/empty/error/retry and data-render states.
- Extended `lib/reports/{api,validators}.test.ts` — proxy URLs/params, parity
  for statement/payables/aging parsers.
- Extended `lib/navigation/nav.test.ts` — purchases section, upcoming cleanup,
  and payables report links.

Coverage focus: valid/invalid API responses, list/search/filters, draft
create-update, server validation errors, permission-denied state, empty and
loading states, lifecycle actions, draft vs posted behavior, exact decimals,
allocation-sum enforcement, drill-down links, and data-driven aging buckets.

---

## 8. Quality-gate results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
| ---- | ------- | ------ |
| Tests | `npx vitest run` | **exit 0** — 72 files / 680 tests passed |
| TypeScript | `npx tsc --noEmit` | **exit 0** |
| ESLint | `npx eslint . --max-warnings=0` | **exit 0** |
| Build | `npm run build` | **exit 0** — all new routes emitted |

No package, lockfile, or lint rule was modified to pass.

---

## 9. Live integration status

Not performed. As in Phases 13–14, no running backend/database integration was
executed, and no claim of live API correctness is made. Verifying behavior
against the real backend remains a Phase 15 follow-up.

---

## 10. Known limitations and deferred items

- Accounts/suppliers pickers are loaded through the existing options/data hooks.
- Multi-currency bills and FX previews remain backend-authoritative only (the
  API stores bills in the base currency, and the UI mirrors that contract).
- Supplier deactivation does not cascade; suppliers with activity post an
  informational warning only.
- Statement/report filters are client-held; deep links keep the defaults.
- Live end-to-end testing is deferred (see §9).

---

## 11. Final Git status

All Phase 15 modifications/creations are uncommitted, as required by the brief:

```
## main...origin/main
 M accounting-app/frontend/src/lib/api/errors.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
 M accounting-app/frontend/src/lib/reports/api.test.ts
 M accounting-app/frontend/src/lib/reports/api.ts
 M accounting-app/frontend/src/lib/reports/validators.test.ts
 M accounting-app/frontend/src/lib/reports/validators.ts
?? accounting-app/docs/FRONTEND_PHASE_14_REPORT.md
?? accounting-app/frontend/src/app/(app)/{customers,sales-invoices,customer-receipts,
    suppliers,purchase-bills,supplier-payments}/…
?? accounting-app/frontend/src/app/(app)/reports/{customer-statement,receivables,receivables-aging,
    supplier-statement,payables,payables-aging}/
?? accounting-app/frontend/src/components/{customers,sales-invoices,customer-receipts,
    suppliers,purchase-bills,supplier-payments}/…
?? accounting-app/frontend/src/components/reports/{customer-statement,receivables,receivables-aging,
    supplier-statement,payables,payables-aging}-view{,.test}.tsx
?? accounting-app/frontend/src/lib/{customers,sales-invoices,customer-receipts,
    suppliers,purchase-bills,supplier-payments}/…
?? accounting-app/frontend/src/lib/reports/{*-types,use-payables,use-payables-aging,
    use-supplier-statement,use-receivables,use-receivables-aging,use-customer-statement}.ts{,.test}
?? accounting-app/docs/FRONTEND_PHASE_15_REPORT.md
?? "prompt/frontend/Phase 14.md"
?? "prompt/frontend/Phase 15.md"
```

---

## 12. Confirmation of preservation

- Backend files: unchanged.
- Frontend dependencies and lockfiles: unchanged.
- Prior phase reports and docs: unchanged (Phase 11/12/13/14 reports untouched).
- Quality gates: all green.