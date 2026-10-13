# Accountance Frontend — Phase 16 Report

**Phase:** 16 — Credit Notes & Debit Notes
**Scope:** Frontend only
**Date:** 2026-10-13
**Report path:** `accounting-app/docs/FRONTEND_PHASE_16_REPORT.md`

> **Git note.** The Phase 16 brief prohibits Git commit and push unless explicitly
> authorized by the operator (§2.2 "Prohibited Git operations", §19 "Stop
> Condition"). No commit was created, no push was performed, and no other
> prohibited Git operation (pull/fetch/reset/stash/clean/checkout/switch/restore/
> amend) was run. All Phase 16 work remains uncommitted as modified/untracked
> files for the operator to review and commit if desired.

---

## 1. Executive summary

Phase 16 delivered the Credit Notes & Debit Notes area end to end against the
verified Laravel API, frontend-only:

- **Sales Credit Notes** and **Purchase Debit Notes** — list, create, draft edit,
  immutable detail, posting and draft deletion, driven by the backend's single
  unified `credit-debit-notes` resource.
- **Source-document association** — notes must reference exactly one source
  document (a POSTED sales invoice for a sales note, a POSTED purchase bill for a
  purchase note); the source and note type are immutable after creation.
- **Adjustment visibility** — the form reads the backend's per-document
  `adjustable-lines` figures (document total, already adjusted, still adjustable,
  and per-line remaining quantity) and shows an advisory limit; it never
  recomputes an authoritative balance.
- **Report integration** — the Phase 15 statements already accepted
  `credit_note` / `debit_note` movement types; Phase 16 added regression tests
  without changing report semantics or signs.
- **Navigation** — real, permission-keyed "Credit notes" and "Debit notes" links
  replacing the old upcoming placeholders.
- **Tests** — API clients, validators, exact-decimal totals, list view, and
  statement-row regression tests (**725 tests across 76 files**).

All quality gates pass (lint, ESLint, TypeScript, Vitest, production build).
Backend files, dependencies, and prior reports are unchanged. Live integration
was **not** performed and is not claimed.

---

## 2. Initial branch, HEAD, and Git status

- Repository root: `D:\Ram da\Accountance`
- Branch: `main` (`main...origin/main`)
- HEAD: `4fc9d5e` — "docs: record final Phase 13 commit hash and git status in the report"
- Tracked modifications at inspection time (all Phase 14/15 work, untouched):

```
 M accounting-app/frontend/src/lib/api/errors.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
 M accounting-app/frontend/src/lib/reports/api.test.ts
 M accounting-app/frontend/src/lib/reports/api.ts
 M accounting-app/frontend/src/lib/reports/validators.test.ts
 M accounting-app/frontend/src/lib/reports/validators.ts
```

- Untracked: the Phase 14/15 modules and reports (customers, sales invoices,
  customer receipts, suppliers, purchase bills, supplier payments, reports),
  plus `prompt/frontend/Phase 14.md`, `Phase 15.md`, `Phase 16.md`, and
  `docs/FRONTEND_PHASE_14_REPORT.md` / `FRONTEND_PHASE_15_REPORT.md`.

The working tree was inspected fresh before editing rather than trusting the
Phase 15 report; it differed only by the additions Phase 14/15 had already
described. All pre-existing modifications were preserved.

---

## 3. Repository-preservation confirmation

- No file under `accounting-app/backend/` was modified.
- No dependency, `package.json`, or lockfile was changed; nothing was installed.
- No existing test was deleted or weakened; Phase 14/15 tests remain intact and
  green.
- Prior reports and prompts were not overwritten. The only new document is this
  report.
- Existing Phase 14/15 modules were not refactored. The only shared-file change
  was the minimal, necessary navigation entry for the two new routes, plus
  regression tests added to the existing reports validators test.

---

## 4. Backend endpoint inventory (verified by source inspection)

`accounting-app/backend/routes/api.php` — all under the company- and
auth-scoped API group:

| Method | Path | Controller action | Route name |
| ------ | ---- | ----------------- | ---------- |
| GET | `/credit-debit-notes` | `index` | `credit_debit_notes.index` |
| POST | `/credit-debit-notes` | `store` | `credit_debit_notes.store` |
| GET | `/credit-debit-notes/{creditDebitNote}` | `show` | `credit_debit_notes.show` |
| PUT | `/credit-debit-notes/{creditDebitNote}` | `update` | `credit_debit_notes.update` |
| DELETE | `/credit-debit-notes/{creditDebitNote}` | `destroy` | `credit_debit_notes.delete` |
| POST | `/credit-debit-notes/{creditDebitNote}/post` | `post` | `credit_debit_notes.post` |
| GET | `/sales-invoices/{invoice}/adjustable-lines` | `adjustableInvoiceLines` | `sales.invoices.adjustable-lines` |
| GET | `/purchase-bills/{bill}/adjustable-lines` | `adjustableBillLines` | `purchases.bills.adjustable-lines` |

There is **one** resource and one lifecycle for all four note types; the note
kind is the `note_type` filter/field, not a separate endpoint. The
`adjustable-lines` endpoints live on the invoice/bill prefix because the figure
is a property of the posted source document, and they check the invoice's/bill's
view permission rather than a note permission.

---

## 5. Exact permissions verified

`app/Enums/PermissionName.php:229–233`, enforced via `CreditDebitNotePolicy`:

| Ability | Permission key |
| ------- | -------------- |
| view | `accounting.credit_debit_note.view` |
| create | `accounting.credit_debit_note.create` |
| update | `accounting.credit_debit_note.update` |
| delete | `accounting.credit_debit_note.delete` |
| post | `accounting.credit_debit_note.post` |

These keys are shared by all four note types. There is no separate
allocation/application permission because there is no separate allocation
endpoint (see §10).

---

## 6. Credit note implementation and routes

Exposed route tree (thin Next.js App Router pages supplying the module's
`noteType` / `basePath` props):

- `/credit-notes` → `SALES_CREDIT_NOTE` (`CreditDebitNotesView`)
- `/credit-notes/new` → `CreditDebitNoteForm`
- `/credit-notes/[id]` → `CreditDebitNoteDetailView`
- `/credit-notes/[id]/edit` → `CreditDebitNoteForm` (draft only)

The list supports search, status, customer, and date-range filters plus
pagination. The detail view renders identity, status, counterparty, source
invoice link, note date, reference, reason, notes, line table (with the adjusted
source line), tax breakdown, subtotal/discount/tax/grand totals, posted
timestamp and journal id. Draft edit/delete/post are shown only when permitted.

## 7. Debit note implementation and routes

Mirror routes:

- `/debit-notes` → `PURCHASE_DEBIT_NOTE`
- `/debit-notes/new`, `/debit-notes/[id]`, `/debit-notes/[id]/edit`

The note module is parameterised rather than duplicated: `isSalesNote`,
`isCreditNote`, `noteSourceField`, and `noteSourceLineField` centralise the
sales/purchase differences (counterparty, source field, source-line field,
`unit_cost` vs `unit_price`, revenue vs expense account label), and the API
client maps a uniform `source_id` / `source_line_id` onto the concrete wire
field for the note's type.

**Only two of the four backend types are exposed.** `SALES_DEBIT_NOTE` and
`PURCHASE_CREDIT_NOTE` are fully backend-supported but are intentionally left
without routes in this phase, because the brief scoped the UI to sales credit
notes and purchase debit notes; the shared module would accept them by adding
route pages. This is recorded as a deliberate scope limitation (§18).

**Signatures note.** A credit note is a credit against a customer (reduces the
customer's balance); a debit note is a debit against a supplier (reduces the
amount owed to the supplier). The UI does not infer this from a client-side
calculation — it renders the backend's `is_credit` flag and posting effects.

---

## 8. Source-document association rules (verified by inspection)

- `StoreCreditDebitNoteRequest` requires **exactly one** of `sales_invoice_id` /
  `purchase_bill_id` and prohibits the other.
- `UpdateCreditDebitNoteRequest` has **no** rule for `note_type` or the source
  ids: the note type and source document are **immutable** after creation. The
  update payload therefore omits them; the editor locks the source select.
- Lines may optionally name a source line (`sales_invoice_line_id` /
  `purchase_bill_line_id`); a line with no source-line id is a document-level
  adjustment. The adjustment service only admits a line that still has
  `remaining_quantity > 0` (`is_adjustable`).
- The source picker loads **POSTED** sales invoices / purchase bills only — a
  draft has no journal, so the backend refuses it as a note source.
- `Company_id` is never sent from the browser; the BFF injects the
  `X-Company-Id` header from the session, and the resources omit `company_id`.

---

## 9. Tax and calculation behavior (verified by inspection)

- A note line carries `description, quantity, unit_price, discount, tax_rate,
  account_id` (and optional source line). `tax_rate` is a percentage snapshot
  supplied per line, not re-derived from current tax configuration; explicit
  `tax_ids` are accepted by the request but the module form previews via the
  rate field, mirroring the invoice form.
- Text calculation mirrors `DocumentCalculator`: gross = quantity × unit_price;
  net = gross − discount; tax = net × rate ÷ 100; line total = net + tax.
- The client preview uses exact string/BigInt arithmetic
  (`lib/credit-debit-notes/totals.ts`, reusing `sales-invoices/totals.ts`
  helpers); it is explicitly advisory, and a banner tells the user the server
  recalculates all totals on save.
- The note resource exposes **no currency fields** (`currency_id`,
  `exchange_rate`, or base-currency totals), so the UI shows no currency — it
  does not invent one.
- Money is kept as decimal strings and formatted with
  `formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS })`.

---

## 10. Allocation / application rules (verified by inspection)

- There is **no separate allocation endpoint**. Posting a note
  (`POST /credit-debit-notes/{id}/post`) writes the journal entries and applies
  the adjustment as part of the posting service. Consequently the UI contains
  **no fake allocation UI** — no applying a note to a different invoice/bill, no
  partial/multiple applications, no unapplied-balance UI.
- The backend enforces the adjustment limit against the posted source document
  and its already-posted notes; the frontend surfaces the server-computed
  `remaining_adjustable_amount` and `remaining_quantity` and shows a
  **non-blocking advisory** when the estimated total exceeds what is still
  adjustable. The server remains authoritative and may still reject the save.
- `GET .../adjustable-lines` returns `{ grand_total, net_adjustment,
  remaining_adjustable_amount, lines: [{ …, adjusted_quantity,
  remaining_quantity, is_adjustable }] }`; the parser normalises the bill's
  `unit_cost` to `unit_price` so a single line shape serves both families.

---

## 11. Customer/supplier and report integration

- **Reports (verified §9 of the brief).** Phase 15's statement parsers already
  accept `type: string` and the statement views already map `credit_note` /
  `debit_note` labels. Phase 16 therefore made **no** change to report semantics
  or signs and instead added two regression tests:
  - `lib/reports/validators.test.ts` — a `credit_note` row on the customer
    statement and a `debit_note` row on the supplier statement survive parsing
    with their `type` intact.
- **Drill-downs.** Note list/detail rows deep-link to their source invoice
  (`/sales-invoices/{id}`) and source bill (`/purchase-bills/{id}`).
- **Balance effects.** Customer/supplier balances and outstanding amounts are
  read only from server-returned report data; the UI never recomputes an
  invoice/bill outstanding balance locally.
- Showing the notes themselves on the invoice/bill detail page was **not**
  added: the invoice/bill resources expose no credit/debit-note relationship,
  and the brief forbids adding it without a supported lookup.

---

## 12. Navigation changes

`lib/navigation/nav.ts`:

- "Sales and receivables" now contains a real
  `{ href: "/credit-notes", label: "Credit notes", permission:
  "accounting.credit_debit_note.view" }` entry; the old "Credit notes" upcoming
  placeholder was removed.
- "Purchases and payables" now contains a real
  `{ href: "/debit-notes", label: "Debit notes", permission:
  "accounting.credit_debit_note.view" }` entry; the old "Debit notes" upcoming
  placeholder was removed.
- No other sections, placeholders, or links were changed by Phase 16.

`lib/navigation/nav.test.ts` was extended to assert the two new links, their
routes and permission keys, and that the two upcoming placeholders are gone,
with existing navigation assertions preserved. Navigation filtering is UI only;
the backend remains authoritative.

---

## 13. Files added and modified

### New module (lib layer)
- `src/lib/credit-debit-notes/types.ts`
- `src/lib/credit-debit-notes/api.ts` (`"use client"`)
- `src/lib/credit-debit-notes/validators.ts`
- `src/lib/credit-debit-notes/totals.ts`
- `src/lib/credit-debit-notes/use-credit-debit-notes.ts`
- `src/lib/credit-debit-notes/use-credit-debit-note.ts`
- `src/lib/credit-debit-notes/use-adjustable-lines.ts`
- `src/lib/credit-debit-notes/use-note-source-documents.ts`

### Components
- `src/components/credit-debit-notes/credit-debit-notes-view.tsx`
- `src/components/credit-debit-notes/credit-debit-note-form.tsx`
- `src/components/credit-debit-notes/credit-debit-note-detail-view.tsx`

### Routes
- `src/app/(app)/credit-notes/{page,new/page,[id]/page,[id]/edit/page}.tsx`
- `src/app/(app)/debit-notes/{page,new/page,[id]/page,[id]/edit/page}.tsx`

### Modified
- `src/lib/navigation/nav.ts` — real credit/debit note links.
- `src/lib/navigation/nav.test.ts` — link + placeholder tests.
- `src/lib/reports/validators.test.ts` — credit_note / debit_note row
  regression tests (no production change).

### Form architecture (rule `react-hooks/set-state-in-effect`)
`CreditDebitNoteForm` is a wrapper (loads the entity on edit, gates on
company/permission/status/type) plus an inner `CreditDebitNoteEditor` seeded
with lazy `useState(() => seedForm(...))`, remounted via a parent `key` so a
loaded note reseeds without a `setState`-in-effect. The ES2017 target is
respected; `BigInt(...)` is used, never `0n` literals.

---

## 14. Test files and test counts

New:
- `src/lib/credit-debit-notes/api.test.ts` — proxy paths/verbs; create sends
  `note_type` + source, update omits them, post has no body; adjustable-lines
  family selection. (10 tests)
- `src/lib/credit-debit-notes/validators.test.ts` — list/detail parsing,
  counterparty-code mapping (`customer_code` / `supplier_code`), invalid-row
  rejection, adjustable-source parsing (both/neither source ids), write rules
  incl. line limits and discount ≤ gross. (21 tests)
- `src/lib/credit-debit-notes/totals.test.ts` — exact decimal line/total math.
  (4 tests)
- `src/components/credit-debit-notes/credit-debit-notes-view.test.tsx` —
  company/permission gating, credit vs debit title, draft actions, empty and
  error/retry states. (6 tests)

Extended:
- `src/lib/reports/validators.test.ts` — two statement-row regression tests
  (credit_note, debit_note).
- `src/lib/navigation/nav.test.ts` — credit/debit note links and placeholder
  cleanup.

Full suite: **76 files / 725 tests, all passing.**

---

## 15. Quality-gate results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
| ---- | ------- | ------ |
| ESLint | `npx eslint . --max-warnings=0` | **exit 0** |
| TypeScript | `npx tsc --noEmit` | **exit 0** |
| Tests | `npx vitest run` | **exit 0** — 76 files / 725 tests passed |
| Build | `npm run build` | **exit 0** — `/credit-notes/**` and `/debit-notes/**` emitted |

`package.json` defines no separate `lint` script beyond `next lint`/ESLint, so
`npx eslint . --max-warnings=0` was used as the established equivalent. No
package, lockfile, or lint rule was modified to pass.

---

## 16. Live integration status

Not performed. No running backend/database integration was executed, no
migrations or seeders were run, and no claim of live API correctness is made.
Contracts were verified through backend source inspection and the existing
frontend automated tests only.

Suggested Phase 21 live smoke tests (from brief §16):

1. Sales credit note creation and validation.
2. Sales credit note posting.
3. Customer balance and invoice effects.
4. Purchase debit note creation and validation.
5. Purchase debit note posting.
6. Supplier balance and bill effects.
7. Adjustment behavior at posting, including over-limit rejection.
8. Tax and rounding.
9. Statements and aging reports.
10. Company isolation.
11. Permission-denied responses.
12. Draft immutability after posting.

---

## 17. Missing backend contracts and blocked functionality

- **No separate allocation/application endpoint.** Posting applies the
  adjustment automatically, so a multi-invoice allocation UI is neither
  available nor implemented. This is a design fact, not a defect.
- **No credit/debit-note relationship on invoice/bill resources.** Notes cannot
  be listed on an invoice/bill detail page, so that integration was not built.
- **No currency fields on the note resource.** Currency display was
  intentionally omitted rather than invented.

None of these blocked a Phase 16 acceptance criterion, because the brief marks
allocation/application as "only if supported" and forbids fabricating it.

---

## 18. Known limitations and risks

- **Two of four note types unexposed.** `SALES_DEBIT_NOTE` and
  `PURCHASE_CREDIT_NOTE` have no routes in this phase (deliberate scope). The
  lib/components already handle them; only route pages would be needed.
- **Advisory limit only.** The "exceeds remaining" warning is client-side and
  non-blocking; the server may still reject a save. This is intended.
- **Source picker is capped.** Posted source documents are walked up to 20 pages
  of 100; on edit, a note whose own source is outside that window is added back
  to the list so the current document is always selectable.
- **Statement filters are client-held;** deep links keep defaults (unchanged
  from Phase 15).
- **Live end-to-end testing deferred** (see §16).

---

## 19. Final Git status

All Phase 16 work is uncommitted, as required by the brief:

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
?? accounting-app/docs/FRONTEND_PHASE_15_REPORT.md
?? accounting-app/docs/FRONTEND_PHASE_16_REPORT.md
?? accounting-app/frontend/src/app/(app)/credit-notes/
?? accounting-app/frontend/src/app/(app)/debit-notes/
?? accounting-app/frontend/src/components/credit-debit-notes/
?? accounting-app/frontend/src/lib/credit-debit-notes/
   … (plus the untracked Phase 14/15 modules and prompts)
?? "prompt/frontend/Phase 16.md"
```

---

## 20. Confirmation of preservation

- Backend files, routes, controllers, services, policies, migrations, and tests:
  **unchanged**.
- Frontend dependencies and lockfiles: **unchanged**.
- Prior phase reports and prompts (Phases 11–15): **unchanged**.
- Existing Phase 14/15 modules and tests: **unchanged and green**.
- No prohibited Git operation was run; no commit or push was performed.
- Quality gates: lint, ESLint, TypeScript, Vitest (725 tests), and production
  build all pass.
