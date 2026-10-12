# Frontend Phase 6 — Journal Entries UI Implementation Report

- **Date:** 2026-10-12
- **Scope:** Frontend Phase 6 only — the Journal Entries module (list, search,
  status/date filters, pagination, create/edit draft with a line editor,
  read-only detail, post, delete-draft) built on the existing backend API. No
  other business screen.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked, per the brief. The backend
  was not modified (`git status --short -- accounting-app/backend` is empty).

## 1. Scope and completion status

| Requirement (Phase 6) | Status |
| --- | --- |
| Real journal contract verified from source | Done (§3) |
| Journals page uses the authenticated shell | Done (`src/app/(app)/journals/page.tsx`) |
| Requests go through the secure same-origin proxy | Done (`backendGet/Post/Put/Delete` → `/api/backend/*`) |
| Journal responses runtime-validated | Done (`src/lib/journals/validators.ts`) |
| List accurately reflects pagination capabilities | Done — flat array + `hasMore`, no fabricated totals (§7) |
| Search + status/date filters use supported behaviour | Done — `search`, `status`, `from`, `to` (server-side) |
| Create/edit draft follows backend validation rules | Done (§6) |
| Posting workflow only where the backend supports it | Done — `POST /journals/{id}/post`, immutable once posted (§8) |
| Permissions gate UI without replacing backend auth | Done (§5) |
| Company switching cannot expose stale journal data | Done (§9) |
| Loading, error, empty, mutation states | Done |
| Tests cover core behaviour and security boundaries | Done (§12) |
| Lint, type-check, tests, build pass | Done (§13) |
| Phase 6 report complete | This document |
| Backend files untouched | Confirmed (§16) |
| No commits/resets/stash/migrations/seeders | Confirmed (§17) |

## 2. Initial Git status and pre-existing changes

Before work, `git status --short` at the repository root showed the Phase 2–5
frontend output uncommitted/untracked, plus the prompt files and one pre-existing
malformed-path untracked file (`D:DevelopmentAccountance…PHASE_17_REPORT.md`, a
fullwidth-colon name) which was left untouched.

No user work was discarded, reset, stashed or cleaned. Branch `main`, HEAD
`6f8bc9b`.

## 3. Actual journal API contract and source locations

Verified directly from source (not from prior reports):

| Fact | Source |
| --- | --- |
| `GET /api/journals` (query `search`, `status`, `from`, `to`, `page`, `per_page`) | `routes/api.php`, `JournalController::index` |
| `POST /api/journals` (201) | `JournalController::store` |
| `GET /api/journals/{journal}` | `JournalController::show` |
| `PUT /api/journals/{journal}` | `JournalController::update` |
| `DELETE /api/journals/{journal}` | `JournalController::destroy` |
| `POST /api/journals/{journal}/post` | `JournalController::post` |
| Envelope `{success,message,data?}`; errors `{success:false,message,errors?}` | `app/Support/ApiResponse.php` |
| Resource fields | `app/Http/Resources/{Journal,JournalLine}Resource.php` |
| Status/source enums | `app/Enums/{JournalStatus,JournalSource}.php` |
| Validation | `app/Http/Requests/Accounting/{StoreJournal,UpdateJournal}Request.php`, `ValidatesJournalLines.php` |
| Lifecycle / posting | `app/Services/Accounting/{JournalService,JournalPostingService}.php` |
| Error keys / statuses | `tests/Feature/Accounting/{JournalLifecycleTest,PostingTest}.php` |

### Contract table

| Action | Endpoint | Method | Permission | Payload / notes |
| --- | --- | --- | --- | --- |
| List journals | `/api/journals` | GET | `journals.view` | `search?`, `status?`, `from?`, `to?`, `page?`, `per_page?`; `data` is a flat array of journal resources, **each with its `lines` and `totals` loaded** |
| View one | `/api/journals/{id}` | GET | `journals.view` | `data` = one journal resource |
| Create draft | `/api/journals` | POST | `journals.create` | `{journal_date, description?, reference?, lines:[{account_id, description?, debit, credit}]}`; 201 |
| Update draft | `/api/journals/{id}` | PUT | `journals.update` | same fields, `sometimes`/`nullable`; **`lines` replaces the whole set** |
| Post | `/api/journals/{id}/post` | POST | `journals.post` | no body; 409 if already posted; 422 `journal_date` (no/closed period), `account_id` (inactive account), `lines` (imbalance) |
| Delete draft | `/api/journals/{id}` | DELETE | `journals.delete` | drafts only; posted journals cannot be deleted |

### Key verified rules

- **Status:** `JournalStatus` = `DRAFT | POSTED`. Only drafts are editable or
  deletable; posting is the single transition, performed under a row lock by
  `JournalPostingService` (the only writer of `POSTED`). Posted journals are
  immutable.
- **Source:** `JournalSource` = `MANUAL, ADJUSTMENT, SALES_INVOICE,
  PURCHASE_BILL, CUSTOMER_RECEIPT, SUPPLIER_PAYMENT`. Server-owned and read-only;
  the UI shows it as an informational badge. The frontend enum mirrors this list
  exactly (no invented values); an unrecognised value reads as manual.
- **Lines:** at least 2, at most `config('accounting.limits.max_lines_per_journal',
  500)`; each line requires `account_id` and **both** `debit` and `credit` as
  decimal strings (the unused side is `0`). Debit total must equal credit total.
- **Numbering / money:** `JNL-` + 6 digits; `DECIMAL(20,4)`.
- **Server-owned / never sent:** `id`, `journal_number`, `status`, `source_type`,
  `source_id`, `created_by`, `posted_by`, `posted_at`, timestamps, `company_id`.
- **Permissions:** `journals.view/create/update/delete/post`
  (`App\Enums\PermissionName`, `JournalPolicy`).

## 4. Routes and methods implemented

- Route: **`/journals`** inside the `(app)` route group
  (`src/app/(app)/journals/page.tsx`) — a server component exporting page
  `metadata` and rendering `<JournalsView/>`, behind the layout's session guard.
- Navigation: `src/lib/navigation/nav.ts` moves `journals` into the "Accounting"
  group with `permission: "journals.view"`; `upcoming` is now
  `["Ledgers", "Periods and controls"]`.
- HTTP: `backendGet` (list), `backendPost` (create, post),
  `backendPut` (update), `backendDelete` (delete) — all through `/api/backend/*`.

**Deviation:** the blueprint §2 tree had `accounting/journals/…`; the module
lives at `/journals`, matching the Phase 5 `/accounts` decision (blueprint §15.10).

## 5. Permissions

| Permission | UI effect |
| --- | --- |
| `journals.view` | Page reachable; list requested. Without it the view shows "unavailable" and issues **no request** |
| `journals.create` | "New journal" button (header + empty state) |
| `journals.update` | Edit action on draft rows / in the detail dialog |
| `journals.post` | Post action on draft rows in table and detail |
| `journals.delete` | Delete-draft action in the detail dialog / row actions |

Visibility is convenience only; the backend policy remains authoritative.

## 6. Create / edit fields

`journal_date` (required, defaults to today), `description?`, `reference?`, and a
dynamic `lines` editor (`account`, optional line description, debit, credit).

- Accounts are loaded from the verified accounts list (`GET /api/accounts`,
  `per_page=100`, walking pages, cap 20) and are **sorted by code**; inactive
  accounts are shown and labelled `(inactive)` because the backend is the
  authority that refuses them at post time.
- Typing an amount on one side clears the other, so a line is always one-sided;
  the payload still sends both sides (empty side `0`), matching the contract.
- Live debit/credit totals and a Balanced / Out-of-balance badge are computed
  with `sumDecimalStrings` (BigInt, never Float).
- Client validation mirrors the backend (date format, ≥2 lines, account
  required, exactly one side per line, ≤4 decimals, debit = credit) for immediate
  feedback; server `422` errors are mapped back per line (`lines.{i}.{field}`) and
  for the whole set (`lines`).
- A failed submit preserves the user's input; the dialog is remounted by the
  parent (`key`) when switching records or modes.

## 6a. Amount formatting decision

No currency symbol is invented: the session exposes no currency code
(`SessionCompany` carries only `settings.default_currency_id`), so amounts render
as plain decimals via `formatMoney(..., { decimals: MONEY_DISPLAY_DECIMALS })`.
Foreign-currency metadata is not in scope for this phase except that lines carry
a `currency_code` which the detail view can display.

## 7. Search, filtering, sorting, and pagination

- **Search:** one box → `search`, debounced **300 ms**; the timer is cleared on
  unmount and each run aborts the previous request.
- **Filters:** `status` (All / Draft / Posted), `from` and `to` date pickers. A
  "Clear" control resets all filters. Filtering is server-side. Any filter change
  resets the page to 1 in the same state update.
- **Sorting:** none exposed (no client-side re-sort).
- **Pagination:** the response is a **flat `data` array** with no
  `links`/`meta`/`total`. The UI requests `per_page = 25`, derives `hasMore` from
  a full page (`normalizeList`), and shows only **Previous / "Page N" / Next** —
  never a claimed total. When `hasMore` is true but the next page comes back
  empty, an explicit "No journals on this page" state with a Previous button is
  shown rather than a misleading "no journals".

## 8. Posting and deletion

- **Post:** `POST /journals/{id}/post` behind `journals.post`, with a confirmation
  that states posting is permanent and that the date must fall in an open period.
  The backend remains the source of truth for refusals: an already-posted journal
  (`409`), a date outside / in a closed period (`422 journal_date`), an inactive
  account (`422 account_id`) and an imbalance (`422 lines`) are surfaced verbatim.
- **Edit/delete of posted journals:** the UI only offers edit/delete/post actions
  on `DRAFT` rows; posted rows are read-only. A `422` refusal is still surfaced if
  it ever occurs.
- **Delete:** `DELETE /journals/{id}`, danger-styled confirmation, drafts only.
- Duplicate submissions are prevented (buttons disable while a request is pending);
  a successful write refreshes the list via a reload-token bump and toasts.

## 9. Company-switch isolation strategy

No query cache is used, so isolation is implemented directly:

- `useJournalList` / `useAccountOptions` key every run on the **active company
  id** and **abort** the in-flight request (one `AbortController` per run) before
  starting the next.
- State resets when the company/filters change; a resolved response from the
  previous company is discarded because its controller was aborted.
- Loading / "no active company" / missing-permission states are derived during
  render, so a previous company's rows can never be painted for the new company.
- A missing active company short-circuits to a "select a company" state and issues
  **no unscoped request**.
- Covered by hook tests: company-switch abort isolation, `403` → `unavailable`,
  and error mapping.

## 10. Dependencies added or reused

**No new dependencies.** Reused `backendGet/Post/Put/Delete`, the envelope/error
handling, `normalizeList`, the permission utilities, the design tokens, the
`Dialog` primitive (added in Phase 5) and the existing UI primitives (`Button`,
`Badge`, `Field`/`Input`/`FormField`/`FieldError`, `ErrorState`, `EmptyState`,
`Spinner`, sonner `toast`). Data fetching is hand-rolled hooks and forms are
native controlled components. TanStack Query, react-hook-form and zod were **not**
added (blueprint §15.3/§15.4 remain decided).

## 11. Files created / modified

**Added**

- `src/lib/journals/types.ts`, `validators.ts`, `api.ts`, `use-journal-list.ts`,
  `use-account-options.ts` (+ `validators.test.ts`, `api.test.ts`,
  `use-journal-list.test.ts`, `use-account-options.test.ts`)
- `src/components/journals/journals-view.tsx`, `journals-table.tsx`,
  `journal-form-dialog.tsx`, `journal-detail-dialog.tsx`,
  `journal-confirm-dialog.tsx`, `journals-list-skeleton.tsx`
  (+ `journals-view.test.tsx`)
- `src/app/(app)/journals/page.tsx`

**Modified**

- `src/lib/navigation/nav.ts` (Journals link under "Accounting")
- `src/lib/format/date.ts` (added `todayDateOnly()`)
- `docs/FRONTEND_BLUEPRINT.md` (§2 route/layout notes, §15.10 decision)

**Untouched**

- `accounting-app/backend/**`, all Phase 0–5 reports, `prompt/**`.

## 11a. Accessibility and UX notes

- Dialogs trap focus and are labelled by their title (Phase 5 `Dialog` primitive);
  the post confirmation is reachable by keyboard and its error is announced via
  `ErrorState`.
- Line rows keep stable internal keys generated by a module counter (never read
  from a ref during render, avoiding the `react-hooks/refs` rule); the keys never
  reach the DOM, so there is no hydration concern.
- Money/amounts use a monospace display class for alignment; filters and the
  responsive table reflow on narrow viewports.

## 12. Tests

Run from `accounting-app/frontend` (`npm test`, Vitest 3.2.7):

| Suite | Tests | Coverage |
| --- | --- | --- |
| `src/lib/journals/validators.test.ts` | 17 | line/totals/journal narrowing; invalid status; flat-list + `hasMore`; row-skipping; write validation (date, ≥2 lines, balance, one-sided, missing account, >4 decimals) |
| `src/lib/journals/api.test.ts` | 8 | list query mapping (search/status/from/to/page/per_page), abort signal, get/create/update/delete/post endpoints and methods |
| `src/lib/journals/use-account-options.test.ts` | 5 | multi-page walk + sort; single short page; disabled; no company; error |
| `src/lib/journals/use-journal-list.test.ts` | 6 | load states; `403` → unavailable; company-switch abort isolation; missing company issues no request; error mapping |
| `src/components/journals/journals-view.test.tsx` | 7 | render rows; empty; no company; create-button permission gating; error + retry; create dialog opens; post confirmation opens |
| **Phase 6 subtotal** | **43** | |

**Full suite:** **181 tests across 20 files** (up from 138/15 in Phase 5); all
prior tests still pass.

## 13. Lint, type-check, and production-build results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` (`eslint`) | **Pass**, exit 0 |
| Type-check | `npx tsc --noEmit` (TS 5.9.3) | **Pass**, 0 errors |
| Unit tests | `npm test` | **Pass**, 181/181 across 20 files |
| Build | `npm run build` (Next 16.4.0) | **Pass**; `/journals` present as a dynamic route (`ƒ /journals`) |

`package.json` has no `typecheck` script (blueprint §12 lists one); the equivalent
`npx tsc --noEmit` was run instead. Documentation-only mismatch; no script added.

## 13a. Lint issues found and fixed

During the gate the following were found and corrected before the final run:

1. `journal-form-dialog.tsx` — `react-hooks/refs` error: a `useState` initializer
   read a `useRef` during render. Replaced the ref with a module-level counter
   (`makeLineKey()`); removed the `useRef` import.
2. `journal-confirm-dialog.tsx` — unused `formatDateOnly` import removed.
3. `api.ts` — `JournalStatusValue` was imported but not used (the query `status`
   was typed as a literal union). Now typed `status?: JournalStatusValue`.
4. `types.ts` — `JOURNAL_SOURCE_VALUES` declared values absent from the backend
   `JournalSource` enum; trimmed to the six verified values.
   **Corrected in Phase 7:** the accurate count is **eleven** (see
   `docs/FRONTEND_PHASE_7_REPORT.md` and blueprint §15.12). Phase 6's "six" was an
   under-count; the enum now mirrors all eleven `JournalSource` cases.

## 14. Live backend integration

**Not performed.** No PHP runtime/DB is available in this environment. Response
shapes are exercised by validator unit tests against fixtures taken from the
verified controllers, resources and services; the UI is exercised by a component
test with mocked data and mocked hooks. Server-enforced behaviours (immutability
of posted journals, period/account/imbalance posting refusals, cross-company 404)
are honoured by the UI and were verified by **reading the backend source and
tests**, not by a live call.

## 15. Known limitations and deviations from the blueprint

- **Route/layout:** `/journals` with `src/lib/journals/` + `src/components/journals/`
  rather than the blueprint's `accounting/journals/…` (blueprint §15.10).
- **No pagination metadata:** numbers/totals are not shown (contract-accurate);
  only Prev/Next "Page N" with `hasMore`.
- **Flat-array edge case:** a full final page yields a mirrored empty next page;
  handled with an explicit empty-page state + Previous, not a false "no journals".
- **Account picker scaling:** the line account `<select>` loads every account up
  to a 20-page (≈2000 account) cap; a larger chart of accounts would need a
  searchable account picker (deferred).
- **Amount formatting:** plain decimals, no symbol (§6a).
- **No live integration** (§14).

## 16. Confirmation that the backend was not modified

`git status --short -- accounting-app/backend` is empty; no backend file was
edited. All Phase 6 changes live under `accounting-app/frontend` and
`accounting-app/docs`.

## 17. Confirmation that no commits, migrations, or seeders were performed

No `git commit`, `reset`, `stash`, `clean`, checkout or branch operation was run.
No migration or seeder was created or executed. All Phase 6 work remains
uncommitted/untracked.

## 18. Recommended next steps

1. Review and approve this report before Phase 7 (brief stop point).
2. Backend follow-ups (not frontend scope): return paginator `meta`/`links`
   (P2-1); expose a journal `source_type` for `CashBankTransaction` when Phase 7
   ships so the badge can label it.
3. When a later phase adopts a query cache/forms stack, migrate the journals
   module and add company-aware keys/invalidation.
