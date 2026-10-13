# Accountance Frontend — Phase 13 Report

**Phase:** 13 — Complete Accounting Reporting Core & Phase 11 Remediation
**Scope:** Frontend only
**Date:** 2026-10-13
**Report path:** `accounting-app/docs/FRONTEND_PHASE_13_REPORT.md`

> **Commit note.** The Phase 13 brief states both "Git Commit: Prohibited" (§17
> "Do not commit changes") and §16 "No commit is created". The operator
> subsequently instructed that the finished work be committed and pushed to
> GitHub. The instruction was followed: the work is committed and pushed, and
> this deviation from the written brief is recorded here explicitly rather than
> silently. No other prohibited Git operation (pull/fetch/reset/stash/clean/
> checkout/switch/restore) was performed.

---

## 1. Executive summary

Phase 13 finished and stabilized the accounting reporting module:

- The **General Ledger** page, which previously rendered only a heading, now
  renders the real API response: account header, opening balance, dated
  movements with a running balance, foreign-currency provenance, period debit
  and credit totals, and the closing balance in both raw and normal-balance
  signed form.
- Two missing reports were added end to end — **Balance Sheet** and **Cash &
  Bank** — using the verified backend contracts, with validators, API clients,
  hooks, views, routes, navigation entries, and tests.
- All Phase 11 lint defects were fixed **without** disabling rules or weakening
  TypeScript: the 6 `no-explicit-any` errors, the `set-state-in-effect` error,
  and the `exhaustive-deps` warnings. `npm run lint` and
  `npx eslint . --max-warnings=0` both exit `0`.
- A latent P&L contract bug was found and fixed: the frontend type and parser
  required an `account_type` field that the backend report rows never return,
  which would have rejected every real P&L response.
- The stale `Ledgers` navigation placeholder was removed and replaced with
  working Balance Sheet and Cash & Bank links.
- Automated coverage for all five reports was added (validators, API, hooks,
  views, navigation).

All four quality gates pass. Backend files, dependencies, and prior reports are
unchanged. Live integration was **not** performed and is not claimed.

---

## 2. Initial branch, HEAD, and Git status

- Repository root: `D:\Development\Accountance`
- Branch: `main`
- HEAD: `b3b6e30` — "Frontend Phases 8-10: financial years & periods, bank
  reconciliation, and tax management"
- Initial `git status --porcelain` (before any Phase 13 edit):

```
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? accounting-app/frontend/src/app/(app)/reports/
?? accounting-app/frontend/src/components/reports/
?? accounting-app/frontend/src/lib/reports/
?? accounting-app/docs/FRONTEND_PHASE_11_REPORT.md
?? accounting-app/docs/FRONTEND_PHASE_12_REPORT.md
?? prompt/frontend/Phase 11.md
?? prompt/frontend/Phase 12.md
?? prompt/frontend/Phase 13.md
?? "D\357\200\272...PHASE_17_REPORT.md"   (pre-existing mangled-stem file)
```

The entire Phase 11 reports module (`lib/reports/`, `components/reports/`,
`app/(app)/reports/`) was untracked. Phase 11 work had never been committed.

---

## 3. Repository-preservation confirmation

- No file was deleted or overwritten except the intended reporting sources.
- The pre-existing mangled-stem file (`"D…PHASE_17_REPORT.md"`, containing a
  U+F03A code point) and the stray mangled directories were left untouched.
- `accounting-app/docs/FRONTEND_PHASE_11_REPORT.md` and
  `FRONTEND_PHASE_12_REPORT.md` were **not** modified.
- The only new document created is this report.
- No backend file, package manifest, lockfile, migration, or seeder was changed.

---

## 4. Files added and modified

### 4.1 Modified (relative to the Phase 11 working tree)

| File | Change |
| --- | --- |
| `frontend/src/lib/reports/validators.ts` | Rewritten to remove `any`; added Balance Sheet and Cash/Bank parsers; P&L row shape corrected |
| `frontend/src/lib/reports/profit-loss-types.ts` | Removed the non-existent `account_type` from `PLAccountRow` |
| `frontend/src/lib/reports/api.ts` | Added `fetchBalanceSheet`, `fetchCashBank` |
| `frontend/src/lib/reports/use-trial-balance.ts` | `exhaustive-deps` fix (destructure filter primitives) |
| `frontend/src/lib/reports/use-profit-loss.ts` | `exhaustive-deps` fix |
| `frontend/src/lib/reports/use-general-ledger.ts` | `exhaustive-deps` fix |
| `frontend/src/components/reports/general-ledger-view.tsx` | Full rewrite (real data, URL seeding, paginated picker, no effect) |
| `frontend/src/components/reports/profit-loss-view.tsx` | Inline row type corrected (dropped `account_type`) |
| `frontend/src/lib/navigation/nav.ts` | Added Balance Sheet and Cash & Bank; removed stale `Ledgers` |

### 4.2 Added

| File | Purpose |
| --- | --- |
| `frontend/src/lib/reports/balance-sheet-types.ts` | Balance Sheet response/filter/state types |
| `frontend/src/lib/reports/cash-bank-report-types.ts` | Cash/Bank filter/state types (aliases of GL shape) |
| `frontend/src/lib/reports/use-balance-sheet.ts` | Balance Sheet data hook |
| `frontend/src/lib/reports/use-cash-bank-report.ts` | Cash/Bank data hook |
| `frontend/src/components/reports/ledger-movements.tsx` | Shared ledger-shaped renderer (GL + Cash/Bank) |
| `frontend/src/components/reports/balance-sheet-view.tsx` | Balance Sheet view |
| `frontend/src/components/reports/cash-bank-report-view.tsx` | Cash & Bank view |
| `frontend/src/app/(app)/reports/balance-sheet/page.tsx` | `/reports/balance-sheet` route |
| `frontend/src/app/(app)/reports/cash-bank/page.tsx` | `/reports/cash-bank` route |
| `frontend/src/lib/reports/validators.test.ts` | Validator/parser tests |
| `frontend/src/lib/reports/api.test.ts` | Report API request-layer tests |
| `frontend/src/lib/reports/use-general-ledger.test.ts` | GL hook tests |
| `frontend/src/lib/reports/use-balance-sheet.test.ts` | Balance Sheet hook tests |
| `frontend/src/lib/reports/use-cash-bank-report.test.ts` | Cash/Bank hook tests |
| `frontend/src/components/reports/trial-balance-view.test.tsx` | Trial Balance view tests |
| `frontend/src/components/reports/profit-loss-view.test.tsx` | P&L view tests |
| `frontend/src/components/reports/general-ledger-view.test.tsx` | GL view tests |
| `frontend/src/components/reports/balance-sheet-view.test.tsx` | Balance Sheet view tests |
| `frontend/src/components/reports/cash-bank-report-view.test.tsx` | Cash/Bank view tests |
| `frontend/src/lib/navigation/nav.test.ts` | Navigation tests |

No package manifests or lockfiles were touched; no dependency was added.

---

## 5. Phase 11 lint defects found and fixed

Baseline (verified by running the command in Phase 12): `npm run lint` exited
`1` with **7 errors and 17 warnings**, all inside the Phase 11 reports files;
`npx tsc --noEmit` exited `0`.

| # | Defect | Rule | Location | Fix |
| --- | --- | --- | --- | --- |
| 1 | Six explicit `any` casts | `@typescript-eslint/no-explicit-any` | `lib/reports/validators.ts` (6 sites) | Rewritten with typed guard helpers (`isObject`, `isString`, `isDecimalLike`, …); the final value is cast to its response type only after full field validation |
| 2 | State update inside an effect | `react-hooks/set-state-in-effect` | `components/reports/general-ledger-view.tsx:42` | View rewritten; the URL is read **once** as lazy initial state. There is no effect that writes form state |
| 3 | Missing `filters` dependency | `react-hooks/exhaustive-deps` | `use-trial-balance.ts`, `use-profit-loss.ts`, `use-general-ledger.ts` | Filter primitives are destructured and the fetch argument is reconstructed from those primitives, so the effect no longer closes over the `filters` object |
| 4 | Unused variables | `@typescript-eslint/no-unused-vars` | `components/reports/general-ledger-view.tsx` (`setReloadToken`, `accounts`, `state`, `apply`) | All are now used by the completed view (Retry uses `setReloadToken`, the picker uses `accounts`, the body uses `state`, the form uses `apply`) |

No lint rule was disabled or suppressed, and no `eslint-disable` comment was
added. `react-hooks` linting remains fully enabled. The completed validators
also close the previously dead `code` branch by checking `isString(row.code)`.

**Latent bug additionally fixed (not a lint finding).** The P&L row type and
parser required `account_type`, but `JournalReportService::signedRowsForType()`
returns only `{ account_id, code, name, amount }`. Any real P&L response would
have failed validation and shown "invalid response". The field was removed from
the type, the parser, and the view's inline type.

---

## 6. General Ledger implementation details

Route: `/reports/general-ledger` → `components/reports/general-ledger-view.tsx`.

Verified against `GeneralLedgerReportService` (see §11 for method):

- **Response shape used:** `account { id, code, name, account_type,
  normal_balance, is_active }`, `period { from, to }`, `base_currency`,
  `opening_balance`, `rows[]`, `period_debits`, `period_credits`,
  `closing_balance`, `closing_balance_signed`.
- **Movement columns:** date, journal number, reference, description, debit,
  credit, running balance. When a row carries a foreign currency
  (`currency_id !== null`) the foreign debit/credit and the exchange rate are
  shown beneath the base amount, so currency provenance is not discarded.
- **Balances:** opening balance, period debit and credit totals, closing
  balance, and the normal-balance-signed closing balance. All values are printed
  verbatim from the backend; nothing is recomputed in the frontend.
- **Arithmetic is raw debit-positive** (per the service docblock): a debit adds
  and a credit subtracts regardless of account type. The view does not attempt to
  re-sign anything.
- **Account selection beyond 50 accounts:** the picker uses
  `useAccountOptions`, which walks pages (100 per page, defensive cap) with
  search, rather than a single page-1 request. A company with more than 50
  accounts can still select any of them. Covered by a view test that renders 60
  options.
- **No journal drill-down link.** Inspection confirmed there is **no journal
  detail route** (`app/(app)/journals/page.tsx` is the only journals route; no
  `[id]` route) and no consumer of a `journal_id` query parameter. Per the brief,
  a drill-down link is added only when the route and identifier match the backend
  contract, so the journal number is rendered as plain text.
- **URL seeding / drill-down target:** the view reads `account_id`, `from`, and
  `to` from the query string **once**, as initial state — no effect writes state
  back into the form, which removes the `set-state-in-effect` failure. The Trial
  Balance and P&L drill-downs land here with the account and period preserved.
- **States:** no company, permission denied, account list loading/error, no
  account selected, report loading, report error (with Retry), unavailable, empty
  movements, and ready. Filter changes re-key the hook, so stale rows are never
  shown against a new account or period.

---

## 7. Trial Balance and Profit & Loss changes

Both existing routes were kept: `/reports/trial-balance`,
`/reports/profit-loss`. They were inspected and corrected only where needed.

- **Trial Balance:** unchanged behavior; its rows and totals are rendered from
  the backend (`debit_total`, `credit_total`, `net_balance`, balanced/difference),
  and each account links to the General Ledger with the applied account and date
  range. Automated tests now cover rows/totals, the balanced indicator, the empty
  state, and drill-down link preservation.
- **Profit & Loss:** the inline account-row type and the shared validator were
  corrected to drop the non-existent `account_type` (§5). Revenue/expense
  sections, gross profit, net profit, and the profitability indicator render from
  the backend. Each account links to the General Ledger. Automated tests now
  cover both sections, the profit figures, and drill-down link preservation.
- **Dimension filtering was not implemented** — the brief defers it to
  Phase 19, and the frontend never sends a dimension filter, so the backend never
  returns the optional `dimension_filter`/`unassigned` fields.

No backend total is recomputed on the client.

---

## 8. Balance Sheet implementation and verified contract

Route: `/reports/balance-sheet` → `components/reports/balance-sheet-view.tsx`.

**Verified contract** (source inspection of `BalanceSheetReportService` and
`ReportController::balanceSheet`):

- The endpoint is **point-in-time**. The controller passes `toDate()`; the
  service ignores `from_date` for the section balances and uses it only to label
  the informational `current_period_result`.
- **The handoff's `as_of` parameter does not apply here.** The controller reads
  `from_date`/`from` and `to_date`/`to`; there is no `as_of` on this action. The
  client therefore sends `to` (the as-of date) and leaves `from` empty. This
  discrepancy between the handoff and the controller is called out deliberately.
- **Response shape used:** `assets { total, accounts[] }`,
  `liabilities { total, accounts[] }`,
  `equity { total, accounts[], retained_earnings }`,
  `totals { assets, liabilities, equity, liabilities_and_equity, is_balanced,
  difference }`, `current_period_result`, plus `period` and `base_currency`.
- Account rows are `{ account_id, code, name, amount }` (no `account_type`).
- `equity.total` already includes `retained_earnings`; the retained-earnings line
  is shown inside the Equity section, and **`current_period_result` is displayed
  as informational only** with an explicit note that it is not added to equity
  (adding it would double-count the period and break the equation).

The view exposes only the as-of date, renders the three sections and the totals,
shows the balanced/difference indicator, and never recomputes a total.

---

## 9. Cash/Bank implementation and verified contract

Route: `/reports/cash-bank` → `components/reports/cash-bank-report-view.tsx`.

**Verified contract** (source inspection of `CashBankReportService`,
`GeneralLedgerReportService`, and `ReportController::cashBank`):

- `CashBankReportService::generate()` delegates to
  `GeneralLedgerReportService::generate()`, so the response is the **general
  ledger shape verbatim**. The types are aliases of the GL types so the two
  cannot drift; the parser is a named `parseCashBank` that delegates to
  `parseGeneralLedger`.
- The `account_id` filter is **required** by the request (`companyAccountRule`) —
  an unselected account is a "select an account" prompt, not an error.
- The `from`/`to` range is the only other filter.
- The report is unrestricted on account type at the backend; the picker prefers
  the company's classified cash/bank accounts (`useCashBankAccountOptions`,
  gated on `accounting.cash_bank.view`). Every role that holds
  `accounting.reports.view` also holds `accounting.cash_bank.view`, but because
  they are separate grants the view **falls back** to the full account list
  (`accounts.view`) if a future role lacks the cash/bank permission, so the
  report cannot become unusable. Both pickers walk all pages.

The movements, balances, and running totals are rendered by the shared
`LedgerMovements` component, so the GL and Cash/Bank surfaces cannot diverge.

---

## 10. Navigation changes

`lib/navigation/nav.ts`:

- Added to the **Reports** section:
  - `{ href: "/reports/balance-sheet", label: "Balance Sheet", permission:
    "accounting.reports.view" }`
  - `{ href: "/reports/cash-bank", label: "Cash & Bank", permission:
    "accounting.reports.view" }`
- Removed the stale `upcoming: ["Ledgers"]` placeholder from the **Accounting**
  section — a General Ledger route has existed since Phase 11.

Both new links are permission-filtered on `accounting.reports.view`, consistent
with the existing report links. Covered by `lib/navigation/nav.test.ts`.

---

## 11. New test files and their coverage

Test stack: Vitest 3 + jsdom + Testing Library (unchanged). Baseline was **39
files / 409 tests**; Phase 13 adds **11 files / 78 tests**, for **50 files / 487
tests**.

| Test file | Coverage |
| --- | --- |
| `lib/reports/validators.test.ts` | TB / P&L / GL / Cash-Bank / Balance-Sheet parsers: valid payloads, missing/typed fields, non-decimal amounts, invalid dates, P&L rows **without** `account_type` |
| `lib/reports/api.test.ts` | Each fetch builds the exact proxy URL and query string, omits empty filters, uses GET, and forwards the abort signal |
| `lib/reports/use-general-ledger.test.ts` | Load+validate; no request without an account/company/permission; 403 → unavailable; invalid payload → error; abort of a superseded account request |
| `lib/reports/use-balance-sheet.test.ts` | Load+validate; permission/company gating; 403 → unavailable; invalid payload → error |
| `lib/reports/use-cash-bank-report.test.ts` | Same matrix for the Cash/Bank hook |
| `components/reports/trial-balance-view.test.tsx` | Company/permission states; rows, totals, balanced indicator; empty state; drill-down link + date preservation |
| `components/reports/profit-loss-view.test.tsx` | Company/permission states; sections and profit figures; drill-down links + date preservation; error state |
| `components/reports/general-ledger-view.test.tsx` | Company/permission/loading/no-account states; movements, running balance, closing balances; URL seeding; apply-from-picker; **60-option picker (beyond page 1)**; error + Retry |
| `components/reports/balance-sheet-view.test.tsx` | Company/permission states; three sections, retained earnings, balanced totals, current-period note; error + Retry; unavailable |
| `components/reports/cash-bank-report-view.test.tsx` | Company/permission states; no classified accounts; no-account prompt; movements; URL seeding; **account-list fallback** when the cash/bank permission is absent |
| `lib/navigation/nav.test.ts` | New Reports links present with the right permission; existing links intact; stale `Ledgers` gone |

Test data uses representative decimal strings (`0.0000`, `1250.2500`, signed
values) and asserts exact formatted output without introducing frontend
arithmetic.

---

## 12. Quality-gate results

All commands run from `accounting-app/frontend`.

| # | Command | Exit | Result |
| --- | --- | --- | --- |
| 1 | `npm run lint` | `0` | No errors, no warnings |
| 1b | `npx eslint . --max-warnings=0` | `0` | Zero warnings repo-wide (stricter check) |
| 2 | `npx tsc --noEmit` | `0` | TypeScript clean |
| 3 | `npm test` | `0` | 50 test files, 487 tests passed |
| 4 | `npm run build` | `0` | Production build succeeded; 25 routes, including the new `/reports/balance-sheet` and `/reports/cash-bank` |

The build's TypeScript pass also type-checks the new `.test.tsx` files, which is
why the gate ordering (test then build) mattered. No gate was left red, and no
gate was faked.

---

## 13. Live integration status

**Live integration was NOT performed.** There was no running backend and
database, and none was started, configured, migrated, or seeded (explicitly
out of scope). Everything above is verified by **source inspection**,
**automated (mocked) tests**, and a **production build** — never against a live
API.

Required Phase 21 runtime smoke tests:

1. Each of the five reports against real company data.
2. Account selection and the Trial Balance / P&L → General Ledger drill-down.
3. Date-boundary behavior (open-ended `from`, inclusive `to`, `from > to` → 422).
4. Empty result sets (new company, no posted lines).
5. Permission-denied responses per role (Admin / Accountant / Manager / Staff).
6. Company switching and cross-company account isolation (cross-company
   `account_id` → 404).
7. Exact monetary rendering against the real decimal strings.
8. Production BFF proxy behavior for the `accounting` route family.

---

## 14. Known limitations and deferred items

- **No live verification** (§13).
- **Dimension filtering** is deferred to Phase 19 and intentionally not
  implemented.
- The Balance Sheet exposes **only the as-of date**; `from_date` is not surfaced
  because the endpoint uses it solely to label the informational current-period
  result, and the brief forbids assuming a date-range filter for a point-in-time
  report. The service's `current_period_result` is shown as informational only.
- The Cash/Bank account picker prefers classified cash/bank accounts and only
  falls back to the full chart of accounts when the role lacks
  `accounting.cash_bank.view`.
- No journal drill-down link is rendered, because no journal detail route exists
  (§6).
- Bank reconciliation create/edit remains blocked by a backend contract gap (no
  exposed `bank_accounts.id` / reconciliation item id) — unrelated to and out of
  scope for Phase 13.

---

## 15. Remaining risks for Phase 14 and Phase 21

- **Contract drift is unverified at runtime.** The parsers are strict by design;
  if any live field differs in nullability or format, a report will show the
  error state rather than a wrong number. That is the intended failure mode, but
  it must be exercised in Phase 21 before release.
- The `reports.view ⇒ accounts.view` / `⇒ cash_bank.view` relationships hold for
  today's roles; the Cash/Bank fallback covers the inverse case, but the GL
  picker would show an account-list error for a hypothetical report-only role.
- The Phase 11 report's status/§1 remain internally inconsistent and it omits the
  real lint result; this Phase 13 report supersedes it as the accurate record.
- A scope conflict persists between the blueprint's §14 build order (fixed
  assets, currencies/FX, budgets, dimensions) and the navigation's `upcoming`
  list; unresolved and out of scope here.

---

## 16. Final Git status

After the Phase 13 work (before the operator-requested commit), the change set
consisted of the modified `nav.ts` plus the untracked reporting module, this
report, and the Phase 11/12/13 briefs. The pre-existing mangled-stem file and
stray directories remain untouched.

Per the operator's instruction the work was then committed and pushed to
`origin/main`. This is the documented deviation from the brief's
"No commit is created" / "Do not commit changes" clauses (§17).

---

## 17. Confirmation of preservation

- **Backend files:** unchanged — no file under `accounting-app/backend/` was
  edited.
- **Dependencies:** unchanged — no package manifest or lockfile was touched and
  no dependency was added or updated.
- **Previous reports:** `FRONTEND_PHASE_11_REPORT.md` and
  `FRONTEND_PHASE_12_REPORT.md` are byte-for-byte untouched. The only new
  document is `FRONTEND_PHASE_13_REPORT.md`.
- **User work:** the pre-existing mangled-stem file and stray directories were
  preserved.
- **Prohibited Git operations:** none performed (no pull/fetch/reset/stash/
  clean/checkout/switch/restore). Commit + push were performed **only** at the
  operator's explicit request, and are disclosed here.
