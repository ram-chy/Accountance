# Frontend Phase 7 — Cash & Banking UI Implementation Report

- **Date:** 2026-10-12
- **Scope:** Frontend Phase 7 only — the Cash & Banking module (classified
  account overview with per-account ledger balances and bank-detail lifecycle, and
  a transactions list with filters/pagination plus create/edit draft, detail,
  post and delete) built on the existing backend API. No other business screen.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked, per the brief. The backend
  was not modified (`git status --short -- accounting-app/backend` is empty).

## 1. Scope and completion status

| Requirement (Phase 7) | Status |
| --- | --- |
| Real cash/bank contract verified from source | Done (§3) |
| Cash & banking pages use the authenticated shell | Done — `/cash-bank`, `/cash-bank/transactions` |
| Requests go through the secure same-origin proxy | Done (`backendGet/Post/Put/Delete` → `/api/backend/*`) |
| Cash/bank responses runtime-validated | Done (`src/lib/cash-bank/validators.ts`) |
| List accurately reflects pagination capabilities | Done — flat array + `hasMore`, no fabricated totals (§7) |
| Transaction filters use supported behaviour | Done — `transaction_type`, `status`, `account_id`, `from`, `to`, `reference` (server-side) |
| Create/edit draft follows backend validation rules | Done (§6) |
| Posting workflow only where the backend supports it | Done — `POST /cash-bank-transactions/{id}/post`, immutable once posted (§8) |
| Type-appropriate account eligibility conveyed | Done — deposit/withdrawal/transfer pickers (§6) |
| Balance surfaced without inventing a currency | Done — per-account ledger read, labelled "Ledger balance", no total (§9) |
| Permissions gate UI without replacing backend auth | Done (§5) |
| Company switching cannot expose stale data | Done (§10) |
| Loading, error, empty, mutation states | Done |
| Tests cover core behaviour and security boundaries | Done (§12) |
| Lint, type-check, tests, build pass | Done (§13) |
| Phase 7 report complete | This document |
| Backend files untouched | Confirmed (§16) |
| No commits/resets/stash/migrations/seeders | Confirmed (§17) |

## 2. Initial Git status and pre-existing changes

Before work, `git status --short` at the repository root showed the Phase 1–6
frontend output uncommitted/untracked, plus the prompt files and one pre-existing
malformed-path untracked file (`D:DevelopmentAccountance…PHASE_17_REPORT.md`, a
fullwidth-colon name) which was left untouched.

No user work was discarded, reset, stashed or cleaned. Branch `main`, HEAD
`6f8bc9b`.

One Phase 6 artefact was corrected during Phase 7 (§18): the
`JournalSource` enum count.

## 3. Actual cash/bank API contract and source locations

Verified directly from source (not from prior reports):

| Fact | Source |
| --- | --- |
| `GET /api/cash-bank-accounts` (query `cash_bank_kind`, `per_page`) | `routes/api.php`, `CashBankAccountController::index` |
| `PUT /api/cash-bank-accounts/{account}` | `CashBankAccountController::update` |
| `DELETE /api/cash-bank-accounts/{account}/bank-details` | `CashBankAccountController::destroyBankDetails` |
| `POST /api/cash-bank-accounts/{account}/activate` | `CashBankAccountController::activate` |
| `POST /api/cash-bank-accounts/{account}/deactivate` | `CashBankAccountController::deactivate` |
| `GET /api/cash-bank-transactions` (query `transaction_type`, `status`, `account_id`, `from`, `to`, `reference`, `page`, `per_page`) | `CashBankTransactionController::index` |
| `POST /api/cash-bank-transactions/deposits` · `…/withdrawals` · `…/transfers` | `CashBankTransactionController::{storeDeposit,storeWithdrawal,storeTransfer}` |
| `GET/PUT/DELETE /api/cash-bank-transactions/{id}` | `CashBankTransactionController::{show,update,destroy}` |
| `POST /api/cash-bank-transactions/{id}/post` | `CashBankTransactionController::post` |
| `GET /api/accounting/accounts/{account}/balance` | `LedgerController::accountBalance` |
| Envelope `{success,message,data?}`; errors `{success:false,message,errors?}` | `app/Support/ApiResponse.php` |
| Resource fields | `app/Http/Resources/{CashBankAccount,CashBankTransaction}Resource.php`, `AccountResource` |
| Enums | `app/Enums/{CashBankKind,CashBankTransactionType,PaymentStatus,JournalSource}.php` |
| Validation | `app/Http/Requests/Transactions/{StoreCashBankTransaction,UpdateCashBankTransaction,UpdateCashBankAccount,CashBankTransactionFilter}Request.php`, `ValidatesTransactionAmounts.php` |
| Eligibility / posting | `app/Services/Accounting/CashBank/{CashBankTransactionService,CashBankPostingService}.php`, `TransactionAccountResolver.php` |
| Error keys / statuses | `tests/Feature/Accounting/*CashBank*.php`, `tests/Unit/CashBankRulesTest.php` |

### Contract table

| Action | Endpoint | Method | Permission | Payload / notes |
| --- | --- | --- | --- | --- |
| List accounts | `/api/cash-bank-accounts` | GET | `accounting.cash_bank.view` | `cash_bank_kind?`, `per_page?`; `data` is a flat array of resources |
| Update classification / bank details | `/api/cash-bank-accounts/{id}` | PUT | `accounting.cash_bank.update` | `{cash_bank_kind?, bank_account?:{account_name, bank_name, account_number?, branch?, bank_identifier?}}` |
| Remove bank details | `/api/cash-bank-accounts/{id}/bank-details` | DELETE | `accounting.cash_bank.update` | no body |
| Activate / deactivate bank details | `/api/cash-bank-accounts/{id}/activate` (or `…/deactivate`) | POST | `accounting.cash_bank.update` | no body |
| List transactions | `/api/cash-bank-transactions` | GET | `accounting.cash_bank.view` | `transaction_type?`, `status?`, `account_id?` (either side), `from?`, `to?`, `reference?`, `page?`, `per_page?` |
| View one | `/api/cash-bank-transactions/{id}` | GET | `accounting.cash_bank.view` | `data` = one transaction resource |
| Create deposit | `/api/cash-bank-transactions/deposits` | POST | `accounting.cash_bank.create` | `{transaction_date, amount, source_account_id, destination_account_id, reference?, notes?}`; 201 |
| Create withdrawal | `/api/cash-bank-transactions/withdrawals` | POST | `accounting.cash_bank.create` | same fields |
| Create transfer | `/api/cash-bank-transactions/transfers` | POST | `accounting.cash_bank.create` | same fields |
| Update draft | `/api/cash-bank-transactions/{id}` | PUT | `accounting.cash_bank.update` | same fields (`sometimes`/`nullable`); type is fixed |
| Post | `/api/cash-bank-transactions/{id}/post` | POST | `accounting.cash_bank.post` | no body; draft only |
| Delete draft | `/api/cash-bank-transactions/{id}` | DELETE | `accounting.cash_bank.delete` | drafts only |

### Key verified rules

- **Type is a route, not a field.** `storeDeposit`/`storeWithdrawal`/`storeTransfer`
  share one request class and one field set; `transaction_type` is deliberately
  absent from the payload. The frontend picks the endpoint from the chosen type and
  never sends the type.
- **Eligibility (service-enforced, re-checked at posting under a lock):**
  deposit → destination must be cash/bank; withdrawal → source must be cash/bank;
  transfer → both. Posting direction is always **Dr destination / Cr source**.
- **Status:** the shared `PaymentStatus` (`DRAFT | POSTED`). Only drafts are
  editable/deletable; posting is the single transition.
- **Amount:** `decimal:0,N` where N is `config('accounting.rounding.max_input_decimals')`
  and must be positive; mirrored client-side (≤4 dp, > 0).
- **Classification:** an account is cash/bank when `cash_bank_kind` is non-null.
  A `PUT` classifies an existing account; the service refuses contradictory moves
  (details cannot coexist with cash; accounting history keeps its classification).
- **Server-owned / never sent:** `id`, `transaction_number`, `status`, `journal_id`,
  `posted_at`, `posted_by`, `created_by`, timestamps, `company_id`.
- **Permissions:** `accounting.cash_bank.view/create/update/post/delete`
  (`config/authorization.php`). The balance read needs `accounting.ledger.view`.

## 4. Routes and methods implemented

- Route **`/cash-bank`** — `src/app/(app)/cash-bank/page.tsx`, a server component
  exporting `metadata` and rendering `<CashBankView/>`, behind the layout's session
  guard.
- Route **`/cash-bank/transactions`** —
  `src/app/(app)/cash-bank/transactions/page.tsx`, rendering
  `<CashBankTransactionsView/>` inside a `Suspense` boundary (the view reads the
  optional `?account_id` search param).
- Navigation: `src/lib/navigation/nav.ts` "Banking" now lists **Cash and bank**
  (`/cash-bank`) and **Transactions** (`/cash-bank/transactions`), both gated by
  `accounting.cash_bank.view`; `Reconciliations` remains an upcoming placeholder.
- HTTP: `backendGet` (lists, show, balance), `backendPut` (classification update,
  transaction update), `backendPost` (create ×3, post, activate/deactivate),
  `backendDelete` (transaction delete, bank-details delete) — all via
  `/api/backend/*`.

**Deviation:** the blueprint §2 tree suggested `banking/…` with a
`cash-bank/[id]` detail route. There is no per-account detail route; account
"detail" is the manage dialog plus the transactions list filtered by
`?account_id` (blueprint §15.11).

## 5. Permissions

| Permission | UI effect |
| --- | --- |
| `accounting.cash_bank.view` | Both pages reachable; lists requested. Without it the view shows "unavailable" and issues **no request** |
| `accounting.cash_bank.update` | "Manage" action on the overview; Edit on draft transactions |
| `accounting.cash_bank.create` | "New transaction" button (header + empty state) |
| `accounting.cash_bank.post` | Post action on drafts (detail dialog) |
| `accounting.cash_bank.delete` | Delete-draft action on drafts (detail dialog) |
| `accounting.ledger.view` | Whether the Ledger balance column is requested. Without it the column shows "—" and a badge explains why; no balance request is made |

Visibility is convenience only; the backend policy remains authoritative.

## 6. Create / edit fields

`transaction_type` (create only — a `<select>` that chooses the create endpoint;
read-only when editing), `transaction_date` (required, defaults to today),
`amount` (required, sanitised to digits and one dot), `source_account_id`,
`destination_account_id`, `reference?` (≤255), `notes?` (≤5000).

The account pickers reflect the type: deposit shows "From account" (all accounts)
and "Into cash/bank account"; withdrawal shows "From cash/bank account" and "Into
account" (all accounts); transfer shows cash/bank on both sides. The offset-side
picker uses `useAccountOptions` (all accounts); the cash/bank-side picker uses
`useCashBankAccountOptions` (classified accounts only). Account options are loaded
lazily while the dialog is open and disabled while loading/unavailable.

Validation mirrors `StoreCashBankTransactionRequest` + `ValidatesTransactionAmounts`
(`validateCashBankTransactionWrite`), plus the cross-field "source ≠ destination"
rule. The backend stays authoritative; its 422 `errors` are shown verbatim,
including per-field.

## 7. Pagination and list accuracy

Both lists use the shared flat-array adapter (`normalizeList`): `hasMore` is
inferred from a full page, because the backend returns a flat `data` array with no
`total`/`last_page` (Phase 0 finding P2-1). Accounts request a single generous page
(`per_page=100`); transactions are page-numbered (`per_page=25`, matching the
backend default) with Previous/Next "Page N". The mirrored-empty-final-page edge
case is handled with an explicit "No transactions on this page" + Previous, never a
false "no transactions". No counts or totals are fabricated.

## 8. Posting and lifecycle

- Post is offered only for `DRAFT` rows, confirmed in a dialog that warns it is
  permanent and requires an open period; the refusal (closed period, ineligible
  account) is surfaced from the backend.
- Delete is offered only for drafts; the bank-details remove is confirmed
  separately.
- Editing a posted transaction is not offered; the form dialog is only opened for
  drafts, and the type selector is disabled when editing.
- The overview's bank-detail lifecycle (activate / deactivate / remove) maps to the
  three sub-resource endpoints; the account dialog closes before the confirm dialog
  opens so the two overlays never stack.

## 9. Balances — decision and rationale

Balances are the sanctioned **per-account ledger read**
(`GET /api/accounting/accounts/{account}/balance`, perm `accounting.ledger.view`).
Each table row owns its own request via a small `BalanceCell` calling
`useAccountBalance`, and the figure is labelled **"Ledger balance"** — it is the
posted-journal position, not reconciled or available money.

**No company-wide cash total is shown.** The account and transaction resources
expose no currency, so summing balances across accounts could add currencies
together. Totals are left to the reports, which know the reporting currency.
Without `accounting.ledger.view` the column shows "—" and a badge explains why.

## 10. Company switching and stale data

Reads are keyed on the active company (`companyId`) inside each hook's run key and
abort the previous run via `AbortController`; loading / no-company / permission
states are derived from the run key during render rather than written in an effect
(satisfying `react-hooks/set-state-in-effect`). Switching companies therefore
starts a fresh run and cannot display another company's rows.

## 11. Files added/changed

- **Data layer** `src/lib/cash-bank/`: `types.ts`, `validators.ts`, `api.ts`,
  `use-cash-bank-accounts.ts` (list + options hook), `use-cash-bank-transactions.ts`
  (list hook), `use-account-balance.ts` (per-account balance hook), plus
  `validators.test.ts`, `api.test.ts`.
- **Feature UI** `src/components/cash-bank/`: `cash-bank-view.tsx`,
  `cash-bank-accounts-table.tsx`, `cash-bank-account-dialog.tsx`,
  `cash-bank-account-actions-dialog.tsx`, `cash-bank-list-skeleton.tsx`,
  `cash-bank-transactions-view.tsx`, `cash-bank-transactions-table.tsx`,
  `cash-bank-transaction-form-dialog.tsx`, `cash-bank-transaction-detail-dialog.tsx`,
  `cash-bank-transaction-confirm-dialog.tsx`, plus `cash-bank-view.test.tsx` and
  `cash-bank-transactions-view.test.tsx`.
- **Routes** `src/app/(app)/cash-bank/page.tsx`,
  `src/app/(app)/cash-bank/transactions/page.tsx`.
- **Navigation** `src/lib/navigation/nav.ts`.
- **Refactor** moved `use-account-options.ts` (+ its test) from
  `src/lib/journals/` to `src/lib/accounts/` so the cash/bank offset picker does
  not import another feature's hook; import paths updated in
  `src/components/journals/journal-form-dialog.tsx` and the matching test mock.

## 12. Tests

New tests (Vitest + Testing Library):

- `src/lib/cash-bank/validators.test.ts` — account/transaction/balance narrowing,
  invalid-row skipping, unknown enums rejected, write validation (date, amount,
  same-account, bank block).
- `src/lib/cash-bank/api.test.ts` — proxy routing, filter serialisation, abort
  signal, one endpoint per create type, sub-resource methods.
- `src/components/cash-bank/cash-bank-view.test.tsx` — renders accounts, ledger
  balance, empty/company/error states, permission-gated "Manage", opens the manage
  dialog.
- `src/components/cash-bank/cash-bank-transactions-view.test.tsx` — renders the two
  sides, empty/company/error states, permission-gated "New transaction", opens the
  create and detail dialogs.

The suite is **241 tests across 24 files** (20 files / 181 tests before Phase 7);
the new module adds **60 tests across 4 files**. Verified across repeated runs; no
deterministic failures.

## 13. Quality gates

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` | Pass, 0 errors, 0 warnings |
| Type-check | `npx tsc --noEmit` | Pass (exit 0) |
| Tests | `npm test` | Pass — 241/241 |
| Build | `npm run build` | Pass — `/cash-bank` and `/cash-bank/transactions` emitted |

No `typecheck` npm script exists (Phase 0 finding), so `npx tsc --noEmit` is used.

## 14. Live backend integration

**Not performed.** No PHP runtime/DB is available in this environment. Response
shapes are exercised by validator unit tests against fixtures taken from the
verified controllers, resources and services; the UI is exercised by component
tests with mocked data and hooks. Server-enforced behaviours (type eligibility,
draft-only edit/delete, posting immutability, period/account refusals, cross-company
404, `403` without the ledger permission) are honoured by the UI and were verified
by **reading the backend source and tests**, not by a live call.

## 15. Known limitations and deviations from the blueprint

- **Routes:** `/cash-bank` + `/cash-bank/transactions` rather than the blueprint's
  `banking/…` (blueprint §15.11). No `/cash-bank/[id]` detail route — the account
  detail is the manage dialog plus a filtered transactions list.
- **No company-wide cash total** and **no currency symbol** (§9); amounts are plain
  decimals, matching the journals decision.
- **No pagination metadata:** only Prev/Next "Page N" with `hasMore` (§7).
- **Flat-array edge case:** a full final page yields a mirrored empty next page;
  handled with an explicit empty-page state + Previous.
- **Account picker scaling:** the offset-account `<select>` walks pages to a
  20-page (≈2000 account) cap and the cash/bank picker to a 10-page cap; a larger
  chart would need a searchable picker (deferred).
- **No live integration** (§14).

## 16. Confirmation that the backend was not modified

`git status --short -- accounting-app/backend` is empty; no backend file was
edited. All Phase 7 changes live under `accounting-app/frontend` and
`accounting-app/docs`.

## 17. Confirmation that no commits, migrations, or seeders were performed

No `git commit`, `reset`, `stash`, `clean`, checkout or branch operation was run.
No migration or seeder was created or executed. All Phase 7 work remains
uncommitted/untracked.

## 18. Phase 6 correction — `JournalSource` enum

Phase 6's report (§13.4) stated `JOURNAL_SOURCE_VALUES` was "trimmed to the six
verified values". That was an under-count: the backend `JournalSource` enum has
**eleven** cases — `MANUAL, ADJUSTMENT, SALES_INVOICE, PURCHASE_BILL,
CUSTOMER_RECEIPT, SUPPLIER_PAYMENT, CASH_BANK_TRANSACTION, CREDIT_DEBIT_NOTE,
FIXED_ASSET, FIXED_ASSET_DEPRECIATION, FIXED_ASSET_DISPOSAL`. Phase 7 restored
`src/lib/journals/types.ts` to mirror all eleven; blueprint §15.12 records the
correction and the Phase 6 report carries a pointer to this section. No runtime
behaviour changed (`sourceLabel` already handled unknown values gracefully).

## 19. Recommended next steps

1. Review and approve this report before the next phase (brief stop point).
2. Backend follow-ups (not frontend scope): return paginator `meta`/`links` (P2-1)
   so `hasMore` and totals become exact.
3. When a later phase adopts a query cache/forms stack, migrate the cash/bank
   module and add company-aware keys/invalidation.
