# Accountance Frontend — Phase 14 Report

**Phase:** 14 — Sales & Receivables (Customers, Sales Invoices, Customer Receipts, Receivables Reports)
**Scope:** Frontend only
**Date:** 2026-10-13
**Report path:** `accounting-app/docs/FRONTEND_PHASE_14_REPORT.md`

> **Git note.** The Phase 14 brief prohibits Git commit and push unless
> explicitly authorized by the operator (§17 "Git Commit: Prohibited" /
> §18 "Git Push: Prohibited"). No commit was created, no push was performed,
> and no other prohibited Git operation (pull/fetch/reset/stash/clean/
> checkout/switch/restore/amend) was run. All Phase 14 work remains uncommitted
> as modified/untracked files for the operator to review and commit if desired.

---

## 1. Executive summary

Phase 14 delivered the complete Sales & Receivables area end to end against the
verified Laravel API, frontend-only:

- **Customers** — list (search, active/status filter, pagination), create/edit
  dialog, deactivation with confirmation, and a detail page with totals and
  outstanding invoices.
- **Sales Invoices** — list with filters (search, status, customer, date range),
  draft create/edit with exact-decimal live line preview, detail page showing
  lines, tax, totals, allocations, and posting/deletion for drafts.
- **Customer Receipts** — list, draft create/edit with invoice allocation
  (outstanding invoices auto-loaded per customer), posting and deletion for
  drafts, detail page with allocations and journal link.
- **Receivables reports** — Customer Statement, Receivables (open invoices as
  of a date), and Receivables Aging, all driven by the verified report
  contracts, with data-driven aging buckets.
- **Navigation** — real menu items with the exact backend permission keys for
  the three new modules and three new reports.
- **Tests** — validators, API clients, hooks, views, and navigation coverage
  for every new module (580 tests across 62 files).

All quality gates pass. Backend files, dependencies, and prior reports are
unchanged. Live integration was **not** performed and is not claimed.

---

## 2. Initial branch, HEAD, and Git status

- Repository root: `D:\Ram da\Accountance`
- Branch: `main`
- HEAD: `4fc9d5e` — "docs: record final Phase 13 commit hash and git status in the report"
- Initial `git status --porcelain` (before any Phase 14 edit):

```
 M accounting-app/frontend/src/lib/api/errors.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? accounting-app/frontend/src/app/(app)/reports/
?? accounting-app/frontend/src/components/reports/
?? accounting-app/frontend/src/lib/reports/
?? prompt/frontend/Phase 14.md
```

The entire customers, sales-invoices, customer-receipts modules and the three
new reports were untracked.

---

## 3. Repository-preservation confirmation

- No existing backend file, dependency, or prior report was modified.
- Our changes touch frontend sources and tests only.
- `accounting-app/docs/FRONTEND_PHASE_11/12/13_REPORT.md` remain untouched.
- The only new document created is this report.

---

## 4. Backend contract inspection (recorded before implementation)

Inspection of the backend source (routes, controllers, requests, resources,
services, policies, config) confirmed:

- **Response envelope** `{ success, message?, data? }` / `{ success:false, message, errors? }`
  via `ApiResponse`; errors carry `Record<string, string[]>`.
- **Pagination** returns a flat array; `hasMore` is inferred from a full page
  (`normalizeList`, `DEFAULT_PER_PAGE = 25`) because the backend sends no links/meta.
- **Company scope** is server-side via `X-Company-Id`; the frontend never sends `company_id`.
- **Lifecycle.** Invoices: `DRAFT|POSTED|PARTIALLY_PAID|PAID`; only drafts are
  editable/deletable; `PUT` cannot post and `POST /{id}/post` takes no body
  (posted → 409). Receipts: `DRAFT|POSTED`, same draft-only mutation rules.
  Customers support deactivation only (no hard delete).
- **Receipt allocations** must total exactly the receipt amount (no advances).
  Outstanding invoices come from `sales-invoices?customer_id=X&outstanding=1`
  which returns `balance_due`.
- **Report contracts.** Customer statement requires `customer_id` (else 422);
  receivables and aging accept optional `customer_id` and `as_of`. Aging buckets
  are config-driven (`config/accounting.php: reports.aging_buckets`) and are
  rendered from the response, never hard-coded. Statement rows are
  `{date,type,reference,description,debit,credit,running_balance,…}`;
  receivables rows always carry a `customer` object and `balance_due`;
  aging rows carry a `counterparty` and a `buckets` record.
- **Money/quantities** are decimal strings; the UI formats with
  `formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS })`.

---

## 5. Files added and modified

### New modules (lib layer)
- `src/lib/customers/{types,validators,api,use-customers,use-customer,use-customer-options}.ts`
- `src/lib/sales-invoices/{types,validators,api,totals,use-sales-invoices,use-sales-invoice}.ts`
- `src/lib/customer-receipts/{types,validators,api,use-customer-receipts,use-customer-receipt,use-outstanding-invoices}.ts`
- `src/lib/reports/{customer-statement-types,receivables-types,receivables-aging-types}.ts`

### Reports API/validators/hooks (extended)
- `src/lib/reports/{api,validators}.ts` — added `fetchCustomerStatement`,
  `fetchReceivables`, `fetchReceivablesAging` and the matching parsers plus the
  shared `validateCounterpartySummary`.
- `src/lib/reports/{use-customer-statement,use-receivables,use-receivables-aging}.ts`

### Components
- `src/components/customers/{customers-view,customer-detail-view,customer-form-dialog}.tsx`
- `src/components/sales-invoices/{sales-invoices-view,sales-invoice-form,sales-invoice-detail-view}.tsx`
- `src/components/customer-receipts/{customer-receipts-view,customer-receipt-form,customer-receipt-detail-view}.tsx`
- `src/components/reports/{customer-statement-view,receivables-view,receivables-aging-view}.tsx`

### Routes
- `src/app/(app)/customers/{page.tsx,[id]/page.tsx}`
- `src/app/(app)/sales-invoices/{page.tsx,new/page.tsx,[id]/page.tsx,[id]/edit/page.tsx}`
- `src/app/(app)/customer-receipts/{page.tsx,new/page.tsx,[id]/page.tsx,[id]/edit/page.tsx}`
- `src/app/(app)/reports/{customer-statement,receivables,receivables-aging}/page.tsx`

### Modified
- `src/lib/navigation/nav.ts` and `nav.test.ts` — Sales & Receivables section
  and three new report links with permission keys.
- `src/lib/api/errors.ts` — added `firstFieldErrors(errors) → Record<string,string>`
  (takes the first message per field) and applied it at the three form
  `setErrors` sites.
- `src/lib/reports/{api,validators}.test.ts` — extended with the new reports.

### Form refactors (rule `react-hooks/set-state-in-effect`)
- `sales-invoice-form.tsx` and `customer-receipt-form.tsx` split into a wrapper
  (loads the entity, gates on company/permission/status) plus an inner editor
  that seeds with lazy `useState(() => seed(entity))`, keyed by the wrapper so
  remounts reseed. `customer-form-dialog.tsx` seeds identically (parent mounts
  the dialog conditionally). No `setState` in effects.
- Fixed the ES2017 target constraint: `BigInt(0)` instead of `0n` in
  `lib/sales-invoices/totals.ts`.

---

## 6. Manipulation details per module

### Customers
- `CustomersView`: search + status filter, permission/company gating, pagination,
  draft-only lifecycle actions, `New customer` dialog. Detail page shows
  receivable account, contacts, outstanding invoices, and deactivate with
  confirmation dialog.
- Write validation mirrors `Store/UpdateCustomerRequest`: required code/name,
  code pattern, email, 2-letter country, required receivable account.

### Sales Invoices
- `SalesInvoiceEditor` recomputes the line preview client-side with exact
  decimal arithmetic rewritten in `totals.ts` (gross = qty×price, net =
  gross−discount, tax = net×rate/100, total = net+tax) mirroring
  `DocumentCalculator`; the backend remains authoritative.
- Draft-only edit/delete; posting via `POST /{id}/post`; immutable document
  view shows status badges, posted journal link, payment allocations.
- Outstanding-invoice query support (`outstanding=1`).

### Customer Receipts
- Receipt editor loads outstanding invoices per customer
  (`use-outstanding-invoices`) and validates all allocations total exactly the
  receipt amount (duplicate-invoice and bounds checks). Draft-only
  edit/delete/post. Detail view lists allocations with per-invoice figures.

### Reports
- Statement: opening balance, movement rows with debit/credit and running
  balance, closing balance card, period label.
- Receivables: as-of date, customer filter, per-invoice totals and age badge,
  drill-down to the invoice.
- Aging: `buckets` rendered from the response (labels/keys), per-customer row
  and bucket totals.

---

## 7. Automation coverage

Existing Vitest / Testing Library setup was reused; no dependency was added.

New test files (all mocked at the API-client / hook boundary):
- `lib/customers/validators.test.ts` — parse list/detail, error casing, write rules.
- `lib/sales-invoices/{api,totals}.test.ts` — proxy routes/verbs, filters,
  exact-decimal line and invoice totals.
- `lib/customer-receipts/{validators,api}.test.ts` — parse, allocation-sum rule,
  lifecycle verbs, no-body post.
- `lib/reports/{use-customer-statement,use-receivables,use-receivables-aging}.test.ts`
  — loading, idle/company/permission gating, 403→unavailable, invalid payloads.
- Extended `lib/reports/{api,validators}.test.ts` — proxy URLs/params,
  parity for statement/receivables/aging parsers.
- `components/reports/{receivables-view,receivables-aging-view,customer-statement-view}.test.tsx`
  — company/ permission/ empty/ error/ retry and data-render states.
- `components/customer-receipts/customer-receipts-view.test.tsx` — list, draft
  actions, empty and error states.
- Extended `lib/navigation/nav.test.ts` — permission-keyed nav links.

Coverage focus: valid/invalid API responses, list/search/filters, create-update,
server validation errors, permission-denied state, empty and loading states,
lifecycle actions, draft vs posted behavior, exact decimals, drill-down links,
and data-driven aging buckets.

---

## 8. Quality-gate results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
| ---- | ------- | ------ |
| Tests | `npx vitest run` | **exit 0** — 62 files / 580 tests passed |
| TypeScript | `npx tsc --noEmit` | **exit 0** |
| ESLint | `npx eslint . --max-warnings=0` | **exit 0** |
| Build | `npm run build` | **exit 0** — all new routes emitted |

No package, lockfile, or lint rule was modified to pass.

---

## 9. Live integration status

Not performed. As in Phase 13, no running backend/database integration was
executed, and no claim of live API correctness is made. Verifying behavior
against the real backend remains a Phase 14 follow-up.

---

## 10. Known limitations and deferred items

- Backend workspaces: account/tax pickers are loaded through the existing
  options/data hooks; multi-currency invoice lines and FX previews remain
  backend-authoritative only.
- Customer deactivation does not cascade; customers with activity post an
  informational warning only.
- Statement/report filters are client-held; deep links keep the defaults.
- Live end-to-end testing is deferred (see §9).

---

## 11. Final Git status

All Phase 14 modifications/creations are uncommitted, as required by the brief:

```
 M accounting-app/frontend/src/lib/api/errors.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
 M accounting-app/frontend/src/lib/reports/api.test.ts
 M accounting-app/frontend/src/lib/reports/api.ts
 M accounting-app/frontend/src/lib/reports/validators.test.ts
 M accounting-app/frontend/src/lib/reports/validators.ts
?? accounting-app/frontend/src/app/(app)/{customers,sales-invoices,customer-receipts}/…
?? accounting-app/frontend/src/app/(app)/reports/{customer-statement,receivables,receivables-aging}/
?? accounting-app/frontend/src/components/{customers,sales-invoices,customer-receipts}/…
?? accounting-app/frontend/src/components/reports/{customer-statement,receivables,receivables-aging}-view{,.test}.tsx
?? accounting-app/frontend/src/lib/{customers,sales-invoices,customer-receipts}/…
?? accounting-app/frontend/src/lib/reports/{customer-statement-types,receivables-types,receivables-aging-types,use-*}.ts{,.test}
?? accounting-app/docs/FRONTEND_PHASE_14_REPORT.md
?? prompt/frontend/Phase 14.md
```

---

## 12. Confirmation of preservation

- Backend files: unchanged.
- Frontend dependencies and lockfiles: unchanged.
- Prior phase reports and docs: unchanged.
- Quality gates: all green.