# Frontend Phase 9 — Bank Reconciliation UI

- **Date:** 2026-10-12
- **Scope:** Frontend Phase 9 only — a bank-reconciliation list, a reconciliation
  detail screen with ledger-movement review, clearing/removing movements, and the
  completion/reopen (plus draft delete) lifecycle, built on the verified backend
  API. No other business screen.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked. No backend file was
  modified (pre-existing untracked backend files from the earlier pull remain — §16).

> **Note.** Two verified contract gaps force this phase to be **read + lifecycle
> only** (no reconciliation create/edit, no bank-account filter, and item removal
> only for movements cleared in the current session). These are documented in
> §7, §9, §15, and §15.1. They were surfaced before implementation and accepted as
> known limitations rather than worked around by inventing an identifier.

## 1. Executive summary and completion status

| Requirement (Phase 9) | Status |
| --- | --- |
| Real reconciliation contract verified from source | Done (§4) |
| Screens use the authenticated shell | Done — `/bank-reconciliations`, `/bank-reconciliations/[id]` |
| Requests go through the secure same-origin proxy | Done (`backendGet/Post/Delete` → `/api/backend/bank-reconciliations/*`) |
| Responses runtime-validated | Done (`src/lib/bank-reconciliations/validators.ts`) |
| List accurately reflects pagination capabilities | Done — flat array + `hasMore`, no fabricated totals (§6) |
| List filters limited to what the backend enforces | Done — status + period range (§6) |
| Detail shows backend balances/difference verbatim | Done — nothing recomputed (§8) |
| Movement review with cleared/uncleared state | Done (§7) |
| Clearing a movement reaches `POST …/items` | Done, id captured from the response (§7) |
| Removing a movement supported where the API allows | Partial — only session-cleared items (§7, §15.2) |
| Completion requires explicit confirmation | Done — dialog restates figures (§9) |
| Completion rules not re-implemented | Done — backend 422 surfaced verbatim (§9) |
| Reopen offered only where the backend supports it | Done — `POST …/reopen`, own permission (§9) |
| Permissions gate UI without replacing backend auth | Done (§5) |
| Company switching cannot expose stale data | Done (§10) |
| Loading, error, empty, not-found, mutation states | Done |
| Accessible dialog behavior | Done — shared `Dialog` (focus trap, Escape) |
| Tests cover core behaviour and security boundaries | Done (§12) |
| Lint, type-check, tests, build pass | Done (§13) |
| Create/edit reconciliation | **Not implemented** — identifier gap (§15.1) |
| Phase 9 report complete | This document |
| Backend files untouched | Confirmed (§16) |
| No commits/resets/stash/migrations/seeders | Confirmed (§17) |

## 2. Initial Git status and pre-existing user work

Branch **`main`**, at `19e5f7d` ("Frontend Phases 2-7…"). No `git pull`,
checkout, reset, stash or clean was performed in Phase 9 (none was required).

`git status --short` before/after implementation shows:

- **Tracked, modified (1):** `accounting-app/frontend/src/lib/navigation/nav.ts`.
  This file was already modified by Phase 8 (its report, §2, records this); Phase 9
  added one more `items` entry to the same file. The earlier modification was
  preserved, not reverted.
- **Untracked — Phase 8 frontend** (`src/app/(app)/financial-years`, `…/periods`;
  `src/components/{financial-years,periods}`; `src/lib/{financial-years,periods}`;
  `docs/FRONTEND_PHASE_8_REPORT.md`) — preserved untouched.
- **Untracked — Phase 9 frontend** (the files in §11).
- **Untracked — prompts/docs:** `prompt/frontend/Phase 9.md`,
  `prompt/frontend/Phase8.md`, `prompt/PHASE 14-Currency & Foreign Exchange
  Accounting` — preserved untouched.
- **Untracked — pre-existing backend** (see §16) — not touched.

## 3. Repository-preservation risks discovered before implementation

- The only tracked file Phase 9 needed to change was `nav.ts`, which carried an
  uncommitted Phase 8 modification. It was edited **additively** (one item moved
  from `upcoming` to `items`); no Phase 8 change was lost.
- No conflicting, unowned, or ambiguous tracked changes were found. No risky Git
  operation was needed, so none was taken.
- The pre-existing untracked backend files are unrelated to this phase and were
  left exactly as found.

## 4. Backend contract verified from actual source

Verified by reading the source (not prior reports):

| Fact | Source |
| --- | --- |
| Routes `/api/bank-reconciliations…` and permission middleware | `routes/api.php` (~L609–645) |
| Handlers | `app/Http/Controllers/Api/Accounting/BankReconciliationController.php` |
| List/detail/movement resource fields | `app/Http/Resources/BankReconciliationResource.php`, `…/BankReconciliationMovementResource.php` |
| Status enum (`DRAFT`, `IN_PROGRESS`, `RECONCILED`) | `app/Enums/BankReconciliationStatus.php` |
| Create/update/filter/item request rules | `app/Http/Requests/Accounting/Reconciliation/*` |
| Move selection / eligibility | `app/Services/Accounting/Reconciliation/BankReconciliationMovementService.php` |
| Balance/difference arithmetic | `app/Services/Accounting/Reconciliation/BankReconciliationCalculator.php` |
| Completion / reopen rules | `…/BankReconciliationCompletionService.php`, `…/BankReconciliationReopenService.php` |
| Company-scoped route bindings | `app/Providers/AppServiceProvider.php` (~L290–291) |
| Route-binding middleware group | `routes/api.php` (`auth:api`, `auth.fresh`, `company.context`, `throttle:api`) |

### Contract table

| Action | Endpoint | Method | Permission |
| --- | --- | --- | --- |
| List | `/api/bank-reconciliations` | GET | `accounting.bank_reconciliation.view` |
| Create | `/api/bank-reconciliations` | POST | `accounting.bank_reconciliation.create` |
| Detail | `/api/bank-reconciliations/{id}` | GET | `accounting.bank_reconciliation.view` |
| Update | `/api/bank-reconciliations/{id}` | PUT | `accounting.bank_reconciliation.update` |
| Delete (draft) | `/api/bank-reconciliations/{id}` | DELETE | `accounting.bank_reconciliation.update` |
| Movements | `/api/bank-reconciliations/{id}/movements` | GET | `accounting.bank_reconciliation.view` |
| Clear item | `/api/bank-reconciliations/{id}/items` | POST | `accounting.bank_reconciliation.update` |
| Remove item | `/api/bank-reconciliations/{id}/items/{item}` | DELETE | `accounting.bank_reconciliation.update` |
| Complete | `/api/bank-reconciliations/{id}/complete` | POST | `accounting.bank_reconciliation.complete` |
| Reopen | `/api/bank-reconciliations/{id}/reopen` | POST | `accounting.bank_reconciliation.reopen` |

### Key verified rules

- **Company isolation is route-bound.** `{reconciliation}` and `{item}` resolve
  within the authenticated company, so a foreign id 404s before the controller
  runs. The browser never sends `company_id`; the proxy supplies the context.
- **`status` is a three-state lifecycle:** `DRAFT`, `IN_PROGRESS`, `RECONCILED`.
  An unrecognised value is treated as "not safe to render" by the validator
  (the row is skipped), so an unknown state can never be shown as open.
- **Filter whitelist:** `bank_account_id` (`exists` company-scoped), `status`,
  `from_date`/`to_date` (`Y-m-d`, on the reconciliation period), `per_page` 1–100.
- **Server-owned / never sent by the frontend:** `id`, `company_id`, status,
  every derived balance/count, cycle audit fields (`completed_by/at`,
  `reopened_by/at`), timestamps.
- **`bank_account_id` is not exposed by any read endpoint** (§15.1).

## 5. Permissions and authorization behaviour

| Permission | UI effect |
| --- | --- |
| `accounting.bank_reconciliation.view` | Both screens reachable; list, detail and movements requested. Without it the view shows "unavailable" and issues **no request** |
| `accounting.bank_reconciliation.update` | "Clear" on an uncleared movement; "Remove" on a session-cleared movement; "Delete" on a `DRAFT` reconciliation |
| `accounting.bank_reconciliation.complete` | "Complete" action (when not already reconciled) |
| `accounting.bank_reconciliation.reopen` | "Reopen" action on a `RECONCILED` reconciliation |
| `accounts.view` | Optional — labels rows with the ledger account code/name; without it rows fall back to `Account #id` and no account request is made |

Visibility is convenience only; the backend policy re-authorises every request.
`GET` reads are gated on `view`; the item and delete writes are gated on `update`
(the delete route authorises with the policy's `update` ability, not a separate
`delete` permission).

## 6. List screen, filters and pagination

- Route `/bank-reconciliations` renders `BankReconciliationsView`.
- Filters: **Status** (`All statuses`, Draft, In progress, Reconciled) and a
  **Period from / Period to** date range. Clearing resets the page to 1 in the
  same state update.
- Columns: bank account (ledger account label), period range, statement closing,
  reconciled balance, difference, cleared/total movements, status badge, and a
  link to the detail. Responsive: a table on `md+`, stacked cards below.
- Pagination uses the shared flat-array adapter (`normalizeList`): `hasMore` is
  inferred from a full page; `per_page=25`. A mirrored-empty final page shows an
  explicit "No reconciliations on this page" + Previous, never a false "none".
- **The `bank_account_id` filter is deliberately omitted** (§15.1).

## 7. Movement selection and reconciliation-item behaviour

The detail screen loads `GET …/movements`, which returns the account's ledger
lines within the reconciliation period — each a journal line, not an item —
flagged `cleared` (already an item of *this* reconciliation). Lines already
cleared into an earlier settlement are excluded by the backend. Nothing is
recomputed; `debit`, `credit` and `direction` are shown verbatim.

- **Clear** (`update`, editable status): `POST …/items { journal_line_id }`. The
  response `{ "id": <item id> }` is validated for its id, which is retained in a
  per-screen map keyed by `journal_line_id`. A refused clear (ineligible line,
  wrong account/period) surfaces the backend's 422 message above the table.
- **Remove** (`update`, editable status): `DELETE …/items/{item}`, offered only
  when the item id is known — i.e. for movements cleared in this session. A
  one-line note explains that cleared movements without a Remove action were
  cleared in an earlier session and are not removable from the UI, because the
  server exposes no item identifier (§15.2).
- Both actions are disabled while any item write is pending, preventing
  duplicate submits; success refreshes the detail and the movements.

## 8. Balance/difference definitions and arithmetic conventions

The screen displays the backend's own figures; **no reconciliation arithmetic is
performed in the browser**. From `BankReconciliationCalculator`:

```
reconciled_balance = ledger_closing − uncleared_debits + uncleared_credits
difference         = statement_closing − reconciled_balance
```

- All money is a decimal **string** formatted with `formatMoney` /
  `MONEY_DISPLAY_DECIMALS` (no `Number` round-trip); missing figures render as
  `—`.
- `difference` non-zero is used only as a **warning hint** (via a string-based
  `isZeroDecimalString`, never a float) in the summary card and the completion
  dialog. It is never treated as the completion decision (§9).
- `previous_reconciled_closing` and `opening_balance_difference` are shown only
  when present; a non-zero opening difference is flagged with a "Check" badge as
  a warning, never an error.

## 9. Completion / reopen lifecycle

- **Complete** is offered when the reconciliation is not `RECONCILED` and the
  caller holds `complete`. Its confirmation restates statement closing,
  reconciled balance and difference. When the difference is non-zero it shows a
  warning but still allows submission: whether completion is permitted is the
  backend's decision (`BankReconciliationCompletionService` refuses a non-zero
  difference with a 422 on `difference`), so the UI surfaces that authority
  rather than re-implementing the rule. A refusal is shown verbatim; success
  refreshes the record.
- **Reopen** is offered only on a `RECONCILED` record with `reopen`, in its own
  dialog. The copy states that the record returns to the in-progress state so its
  movements can be adjusted and that journal history is untouched; a refusal is
  shown verbatim.
- **Delete** is offered only for a `DRAFT` record with `update`, with a
  permanence warning; on success the user is returned to the list. (This is the
  one lifecycle action beyond the two in the original plan; it uses an existing
  supported endpoint.)

## 10. Company isolation and request lifecycle

Reads are keyed on the active company inside each hook's run key and abort the
previous run via `AbortController`; loading / no-company / permission states are
derived from the run key during render rather than written in an effect. Every
state write is guarded by `signal.aborted`. Switching companies therefore starts
a fresh run and cannot display another company's rows. The detail and movements
hooks are additionally keyed on the reconciliation id, so navigating between
records cannot land a stale one. A 404 (foreign company, deleted record, bad id)
becomes a distinct `not-found` state with a link back to the list.

## 11. Files added and changed

- **Data layer** `src/lib/bank-reconciliations/`: `types.ts`, `validators.ts`,
  `api.ts`, `use-bank-reconciliations.ts` (list), `use-bank-reconciliation.ts`
  (detail), `use-bank-reconciliation-movements.ts`, plus `validators.test.ts`,
  `api.test.ts`.
- **Feature UI** `src/components/bank-reconciliations/`:
  `bank-reconciliations-view.tsx`, `bank-reconciliations-table.tsx`,
  `bank-reconciliations-list-skeleton.tsx`, `reconciliation-detail-view.tsx`,
  `reconciliation-summary.tsx`, `reconciliation-movements-table.tsx`,
  `reconciliation-complete-dialog.tsx`, `reconciliation-reopen-dialog.tsx`,
  `reconciliation-delete-dialog.tsx`, plus `bank-reconciliations-view.test.tsx`,
  `reconciliation-detail-view.test.tsx`.
- **Routes** `src/app/(app)/bank-reconciliations/page.tsx`,
  `src/app/(app)/bank-reconciliations/[id]/page.tsx` (the `[id]` page parses the
  id and calls `notFound()` for a non-numeric segment).
- **Navigation** `src/lib/navigation/nav.ts` — "Banking" now lists
  **Reconciliations** (`/bank-reconciliations`, `accounting.bank_reconciliation.view`);
  `upcoming` is removed from that section.
- **Docs** `docs/FRONTEND_PHASE_9_REPORT.md` (this report).

No change was made to `FRONTEND_BLUEPRINT.md` or `FRONTEND_API_HANDOFF.md`: the
handoff already documents the reconciliation routes (§11), and no verified
contract fact changed.

## 12. Tests

New tests (Vitest + Testing Library), **45 tests across 4 files**:

- `src/lib/bank-reconciliations/validators.test.ts` — reconciliation narrowing,
  required identity fields, monetary/timestamp degradation, `is_reconciled`
  defaulting, list narrowing/`hasMore`/invalid-row skipping, `OrThrow`, movement
  narrowing, movements envelope + error, item-id parsing, add-item validation
  (line id, notes length), `isZeroDecimalString`.
- `src/lib/bank-reconciliations/api.test.ts` — proxy routing, filter/query
  serialisation, abort-signal propagation, detail, movements, add (POST body),
  remove (DELETE), complete, reopen, delete.
- `src/components/bank-reconciliations/bank-reconciliations-view.test.tsx` —
  renders rows with the resolved account label and status badges, empty /
  no-company / permission / error states, row links to the detail route.
- `src/components/bank-reconciliations/reconciliation-detail-view.test.tsx` —
  authoritative figures and account label, Clear offered / Remove withheld for
  an untracked cleared line + the explanatory note, clearing through the API,
  Complete gated by permission and opening its confirmation, Reopen for a
  reconciled record, Delete for a draft, and the not-found state.

No existing test was removed or weakened.

## 13. Quality-gate results

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` | Pass — 0 errors, 0 warnings |
| Type-check | `npx tsc --noEmit` | Pass (exit 0) |
| Tests | `npm test` (`vitest run`) | Pass — **355/355 across 34 files** (was 310/30 after Phase 8; +45 tests / +4 files) |
| Build | `npm run build` | Pass — `/bank-reconciliations` and `/bank-reconciliations/[id]` emitted |

There is no `typecheck` npm script, so `npx tsc --noEmit` is used. Dependencies
were already installed (Phase 8); no dependency or script was changed.

## 14. Live integration status

**Not performed.** No PHP runtime/DB is available in this environment. Response
shapes are exercised by validator tests against fixtures derived from the actual
resources/services, and the UI by component tests with mocked data, hooks and
API. Server-enforced behaviour (cross-company 404, `403` without the permission,
the zero-difference completion guard, `already_reconciled` movement exclusion,
item eligibility) is honoured by the UI and was established by **reading the
backend source and its feature tests**, not by a live call. No claim of live
verification of company isolation, permissions, completion rules, or financial
reconciliation is made.

## 15. Known limitations and deviations from the blueprint

### 15.1 No reconciliation create/edit, and no bank-account filter

`POST`/`PUT /bank-reconciliations` require `bank_account_id` = `bank_accounts.id`,
but **no read endpoint exposes that identifier**: `GET /api/cash-bank-accounts`
returns the ledger account `id` (with nested bank details) and `AccountResource`
has no such field; only an existing `BankReconciliationResource.bank_account_id`
carries it. The endpoint's `bank_account_id` filter has the same problem and
cannot be populated. Consequently this phase implements the **list, detail,
movements and lifecycle only**; create/edit and the bank-account filter are
omitted and noted instead of guessed. Rows are labelled with the ledger account
(resolved from the shared accounts list), which is the meaningful identifier the
API does expose.

### 15.2 Removing an existing reconciliation item

`DELETE …/items/{item}` needs the `bank_reconciliation_items.id`, but no read
endpoint lists items and `BankReconciliationMovementResource` omits the item id —
only the `POST …/items` response returns it. Removal is therefore supported only
for movements cleared in the current session (id captured from the add response);
pre-existing cleared movements are shown without a Remove action and with an
explanatory note. A safe general fix is a backend change (expose the item id, or a
GET items endpoint), which is out of scope for this frontend phase.

### 15.3 Other

- **Routes:** top-level `/bank-reconciliations` (and `/[id]`) rather than the
  blueprint's `accounting/…` nesting, consistent with Phases 5–8.
- **Completion hint, not rule:** a non-zero difference warns but still submits;
  the backend is authoritative (§9).
- **Account labels** depend on `accounts.view`; without it rows degrade to
  `Account #id` (§5).
- No pagination metadata — Prev/Next with `hasMore` only (§6).
- No live integration (§14).

## 16. Confirmation that the backend was not modified

No backend file was edited. `git status --short -- accounting-app/backend` shows
only **pre-existing untracked** backend files (an Audit controller/resource/
policy, `Resources/Accounting/`, the Currencies service, and the
currencies/exchange-rates migrations) that arrived with the earlier pull and were
not created, edited or used by Phase 9. All Phase 9 changes live under
`accounting-app/frontend` and `accounting-app/docs`.

## 17. Confirmation that no destructive Git/migration operations were performed

No `git pull`, `commit`, `add`, `reset`, `stash`, `clean`, checkout or branch
operation was run. No migration or seeder was created or executed. All Phase 9
work remains uncommitted/untracked; no pre-existing user work was discarded.

## 18. Recommended next steps

1. Review and approve this report before the next phase (brief stop point —
   Phase 9 is complete; Phase 10 is **not** started).
2. Backend follow-ups (not frontend scope) to unlock the omitted UI:
   expose `bank_accounts.id` (or accept `account_id`) so create/edit and the
   bank-account filter can be built; expose the reconciliation item id (or a GET
   items route) so any cleared movement can be removed.
