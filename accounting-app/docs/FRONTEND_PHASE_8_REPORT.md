# Frontend Phase 8 — Financial Years, Accounting Periods & Period Closing UI

- **Date:** 2026-10-12
- **Scope:** Frontend Phase 8 only — the financial-year management screen
  (list/create/edit, generate monthly periods, finalize a year) and the
  accounting-period screen (list/filter, create/edit, read-only closing review,
  close and reopen), built on the verified backend API. No other business screen.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked, per the brief. No backend
  file was modified (only pre-existing untracked backend files from the pull remain
  — see §17).

## 1. Scope and completion status

| Requirement (Phase 8) | Status |
| --- | --- |
| Real financial-year / period contract verified from source | Done (§3) |
| Both screens use the authenticated shell | Done — `/financial-years`, `/periods` |
| Requests go through the secure same-origin proxy | Done (`backendGet/Post/Put` → `/api/backend/accounting/*`) |
| Responses runtime-validated | Done (`src/lib/financial-years/validators.ts`, `src/lib/periods/validators.ts`) |
| List accurately reflects pagination capabilities | Done — flat array + `hasMore`, no fabricated totals (§7) |
| Create/edit follows backend validation rules | Done (§6) |
| Period generation reachable and idempotent-safe | Done — `POST …/periods/generate`, message reports the year's totals (§8) |
| Year finalize gated on the backend's `can_close` | Done (§8) |
| Closing review rendered read-only before a close | Done — `GET …/closing-check`, blocking/warning findings (§9) |
| Close requires explicit confirmation | Done — two-step confirm in the review dialog (§9) |
| Reopen offered only where the backend supports it | Done — `POST …/reopen`, own permission (§10) |
| Posting nuance conveyed without re-implementing rules | Done — `accepts_postings` drives the "Year closed" badge (§6) |
| Permissions gate UI without replacing backend auth | Done (§5) |
| Company switching cannot expose stale data | Done (§11) |
| Loading, error, empty, mutation states | Done |
| Tests cover core behaviour and security boundaries | Done (§13) |
| Lint, type-check, tests, build pass | Done (§14) |
| Phase 8 report complete | This document |
| Backend files untouched | Confirmed (§17) |
| No commits/resets/stash/migrations/seeders | Confirmed (§18) |

## 2. Initial Git status and pre-existing changes

`git pull` was run first (fast-forward `2ce4c37..19e5f7d`, 9 commits, 356 files)
per the brief. Local tracked changes and conflicting untracked files were
discarded/deleted to allow the pull; a set of unrelated untracked files remains
(Audit `Resources/`, `Services/Accounting/Currencies/`, currency migrations, a
`prompt/PHASE 14…` file). None were part of this phase. Branch `main`.

## 3. Actual API contract and source locations

Verified directly from source (not from prior reports):

| Fact | Source |
| --- | --- |
| `GET/POST /api/accounting/financial-years` | `routes/api.php`, `FinancialYearController::{index,store}` |
| `GET/PUT /api/accounting/financial-years/{financialYear}` | `FinancialYearController::{show,update}` |
| `POST /api/accounting/financial-years/{financialYear}/periods/generate` | `FinancialYearController::generatePeriods` |
| `POST /api/accounting/financial-years/{financialYear}/close` | `FinancialYearController::close` |
| `GET/POST /api/accounting/periods` | `AccountingPeriodController::{index,store}` |
| `GET/PUT /api/accounting/periods/{period}` | `AccountingPeriodController::{show,update}` |
| `GET /api/accounting/periods/{period}/closing-check` | `AccountingPeriodController::closingCheck` |
| `POST /api/accounting/periods/{period}/close` · `…/reopen` | `AccountingPeriodController::{close,reopen}` |
| Envelope `{success,message,data?}`; errors `{success:false,message,errors?}` | `app/Support/ApiResponse.php` |
| Year resource fields | `app/Http/Resources/FinancialYearResource.php` |
| Period resource fields | `app/Http/Resources/AccountingPeriodResource.php` |
| Closing-check payload | `app/Services/Accounting/PeriodClosingCheckService.php::review` |
| Finding shape | `app/Services/Accounting/Controls/ControlFinding.php::toArray` |
| Generation / close rules | `app/Services/Accounting/FinancialYearService.php` |
| Validation / error keys | `app/Http/Requests/Accounting/{StoreFinancialYear,UpdateFinancialYear,StorePeriod,UpdatePeriod}Request.php` |
| Route bindings (company-scoped) | `app/Providers/AppServiceProvider.php` (~L255–275) |

### Contract table

| Action | Endpoint | Method | Permission |
| --- | --- | --- | --- |
| List years | `/api/accounting/financial-years` | GET | `accounting.periods.view` |
| Create year | `/api/accounting/financial-years` | POST | `accounting.periods.create` |
| View year | `/api/accounting/financial-years/{id}` | GET | `accounting.periods.view` |
| Update year | `/api/accounting/financial-years/{id}` | PUT | `accounting.periods.update` |
| Generate periods | `/api/accounting/financial-years/{id}/periods/generate` | POST | `accounting.periods.create` |
| Finalize year | `/api/accounting/financial-years/{id}/close` | POST | `accounting.periods.close` |
| List periods | `/api/accounting/periods` | GET | `accounting.periods.view` |
| Create period | `/api/accounting/periods` | POST | `accounting.periods.create` |
| Update period | `/api/accounting/periods/{id}` | PUT | `accounting.periods.update` |
| Closing review | `/api/accounting/periods/{id}/closing-check` | GET | `accounting.periods.view` |
| Close period | `/api/accounting/periods/{id}/close` | POST | `accounting.periods.close` |
| Reopen period | `/api/accounting/periods/{id}/reopen` | POST | `accounting.periods.reopen` |

### Key verified rules

- **Company isolation is route-bound.** `period`/`financialYear` bindings resolve
  within the authenticated company, so a foreign id 404s before the controller
  runs. The browser never sends `company_id`; the proxy supplies the context.
- **`financial_year_id` is create-only for periods.** `StorePeriodRequest` accepts
  it (company-scoped, optional); `UpdatePeriodRequest` has no such field, so a
  period cannot be moved between years through the API.
- **Dates:** `date_format:Y-m-d`; the year end must be `after_or_equal` the start.
  The service additionally rejects overlapping year ranges and any year shrink
  that would leave a period outside the new range.
- **`can_close` is backend-stated:** open year, ≥1 period, and zero open periods.
  The frontend reads it rather than recomputing it, so the finalize button cannot
  disagree with the close guard.
- **Closing a year writes no journal** (retained earnings are derived). Finalize is
  refused while a critical control is failing.
- **`accepts_postings` (period)** folds the period's own status *and* its year's:
  an OPEN period inside a CLOSED year does not accept postings, which the table
  surfaces as a "Year closed" badge.
- **Generation is idempotent and additive** — existing months (by `Y-m`) are left
  untouched and nothing outside the year's own dates is created. The response
  reports the year's totals *after* the call, not the delta.
- **Closing review is read-only** and rides on the view permission; closing the
  periods is the substantive act.
- **Server-owned / never sent:** `id`, `company_id`, `status`, counts, `can_close`,
  cycle audit fields (`closed_by/at`, `reopened_by/at`), timestamps.

## 4. Routes and methods implemented

- Route **`/financial-years`** — `src/app/(app)/financial-years/page.tsx`, a
  server component exporting `metadata` and rendering `<FinancialYearsView/>`
  behind the layout's session guard.
- Route **`/periods`** — `src/app/(app)/periods/page.tsx`, rendering
  `<PeriodsView/>`.
- Navigation: `src/lib/navigation/nav.ts` "Accounting" now lists **Chart of
  accounts**, **Journals**, **Financial years** (`/financial-years`) and
  **Accounting periods** (`/periods`), the last two gated by
  `accounting.periods.view`. `Upcoming` is reduced to `["Ledgers"]`.
- HTTP: `backendGet` (both lists, closing-check), `backendPost` (create year,
  create period, generate, finalize, close, reopen), `backendPut` (update year,
  update period) — all via `/api/backend/accounting/*`.

**Deviation:** the blueprint's tree nested these under `accounting/…`. The
project already abandoned that nesting for `/accounts`, `/journals` and
`/cash-bank` in Phases 5–7, so Phase 8 uses the consistent top-level
`/financial-years` and `/periods`.

## 5. Permissions

| Permission | UI effect |
| --- | --- |
| `accounting.periods.view` | Both screens reachable; lists and the closing review requested. Without it the view shows "unavailable" and issues **no request** |
| `accounting.periods.create` | "New financial year" / "New period" buttons; "Generate periods" row action |
| `accounting.periods.update` | Edit actions (only for OPEN years/periods) |
| `accounting.periods.close` | "Finalize" year action; "Close period" in the review dialog |
| `accounting.periods.reopen` | "Reopen" action on CLOSED periods |

Financial years intentionally share the periods permission set (the backend gates
year routes on `AccountingPeriodPolicy`'s year methods). Visibility is
convenience only; the backend policy remains authoritative.

## 6. Create / edit fields

**Financial year:** `name` (required, ≤255), `start_date`, `end_date`
(`date_format:Y-m-d`, end ≥ start). Validation mirrors
`StoreFinancialYearRequest`; the backend's duplicate-name and overlap 422s are
shown verbatim.

**Period:** `name` (required, ≤255), `start_date`, `end_date`, and — on create
only — `financial_year_id` (a `<select>` populated from `useFinancialYearOptions`
with a "Derive from company calendar" default that omits the field). Editing
omits `financial_year_id` because `UpdatePeriodRequest` does not accept it.

Server-owned fields are never sent. A closed year's and a closed period's edit
actions are hidden; the backend refuses both regardless.

## 7. Pagination and list accuracy

Both lists use the shared flat-array adapter (`normalizeList`): `hasMore` is
inferred from a full page because the backend paginates but the `{success,data}`
envelope drops the paginator meta (Phase 0 finding P2-1). Both request
`per_page=50` (the backend default) with Previous/Next "Page N". A mirrored-empty
final page shows an explicit "No … on this page" + Previous, never a false "no
records". No counts or totals are fabricated.

## 8. Generation and finalization

- **Generate periods** is offered for OPEN years (with `create`). The confirmation
  explains that generation is safe to run more than once and additive; on success
  a toast reports the year's resulting period count (`parseGeneratePeriodsResult`),
  falling back to a generic message if the body is unparseable.
- **Finalize** is offered for OPEN years (with `close`) but is disabled — with a
  title stating the reason — until the backend's `can_close` is true: no periods
  ("Generate the year's accounting periods before finalizing."), or N periods
  still open ("Close all N open period(s) before finalizing."). The confirmation
  states that the year close writes no journal and is permanent; the backend's
  control-failure refusal is shown verbatim.

## 9. Closing review and closing a period

The review dialog runs `useClosingCheck` (read-only) when opened and renders:
an eligibility badge (`Ready to close` / `Not ready to close` / `Already closed`),
the PASS/WARNING/FAIL summary, the backend's `required_action` sentence, and the
blocking and warning findings (each with control code, severity badge, description
and, where present, financial date and resource). Unknown findings are skipped
rather than blanking the decision.

Closing is offered only when `eligible` and the caller holds `close`, and is
confirmed in a **second step** inside the dialog (a danger-styled
"Confirm close period") because it changes what posting the period's dates
accepts. If the backend refuses, its message is shown and the review is
re-fetched so the report reflects the state the backend just judged.

## 10. Reopening a period

Reopen is a distinct, separately-authorised action shown only for CLOSED periods
with `accounting.periods.reopen`, in its own confirmation dialog. The copy states
that only future postings are affected (journal history is untouched) and that a
period inside a closed year cannot be reopened until the year is reopened; the
backend's refusal is surfaced verbatim.

## 11. Company switching and stale data

Reads are keyed on the active company inside each hook's run key and abort the
previous run via `AbortController`; loading / no-company / permission states are
derived from the run key during render rather than written in an effect
(satisfying `react-hooks/set-state-in-effect`). Switching companies therefore
starts a fresh run and cannot display another company's rows. The closing-check
hook is reset per period id as well.

## 12. Files added/changed

- **Data layer** `src/lib/financial-years/`: `types.ts`, `validators.ts`, `api.ts`,
  `use-financial-years.ts` (list + options hook), plus `validators.test.ts`,
  `api.test.ts`.
- **Data layer** `src/lib/periods/`: `types.ts`, `validators.ts`, `api.ts`,
  `use-periods.ts` (list hook), `use-closing-check.ts`, plus `validators.test.ts`,
  `api.test.ts`.
- **Feature UI** `src/components/financial-years/`: `financial-years-view.tsx`,
  `financial-years-table.tsx`, `financial-year-form-dialog.tsx`,
  `financial-year-action-dialog.tsx` (generate + finalize),
  `financial-years-list-skeleton.tsx`, plus `financial-years-view.test.tsx`.
- **Feature UI** `src/components/periods/`: `periods-view.tsx`, `periods-table.tsx`,
  `period-form-dialog.tsx`, `closing-check-dialog.tsx`,
  `period-reopen-dialog.tsx`, `periods-list-skeleton.tsx`, plus
  `periods-view.test.tsx`.
- **Routes** `src/app/(app)/financial-years/page.tsx`,
  `src/app/(app)/periods/page.tsx`.
- **Navigation** `src/lib/navigation/nav.ts`.

## 13. Tests

New tests (Vitest + Testing Library):

- `src/lib/financial-years/validators.test.ts` — year narrowing, required
  derived flags/counts, invalid-row skipping, generate-response narrowing, write
  validation (name, dates, reversed range).
- `src/lib/financial-years/api.test.ts` — proxy routing, filter serialisation,
  abort signal, create/update, generate and close sub-resources.
- `src/lib/periods/validators.test.ts` — period narrowing, malformed embedded
  year → null, unattached period, closing-check decision parsing with unknown
  findings skipped, write validation (dates, FY id, update-without-FY).
- `src/lib/periods/api.test.ts` — proxy routing, all filters, abort signal, show,
  closing-check, create/update, close/reopen sub-resources.
- `src/components/financial-years/financial-years-view.test.tsx` — renders years,
  empty/company/error states, permission-gated create, opens create & generate
  dialogs, finalize disabled with reason until `can_close`.
- `src/components/periods/periods-view.test.tsx` — renders periods, empty/company/
  error states, permission-gated create, opens create dialog, opening the review
  shows the ready verdict and a close affordance, no close when ineligible, reopen
  gated by permission.

The suite is **310 tests across 30 files** (241 tests / 24 files before Phase 8);
the new modules add **69 tests across 6 files**. No deterministic failures.

## 14. Quality gates

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` | Pass, 0 errors, 0 warnings |
| Type-check | `npx tsc --noEmit` | Pass (exit 0, after `next build` generated route types) |
| Tests | `npm test` | Pass — 310/310 |
| Build | `npm run build` | Pass — `/financial-years` and `/periods` emitted |

There is no `typecheck` npm script (Phase 0 finding), so `npx tsc --noEmit` is
used. `npm install` was required first (dependencies were not installed in the
fresh checkout).

## 15. Live backend integration

**Not performed.** No PHP runtime/DB is available in this environment. Response
shapes are exercised by validator unit tests against fixtures taken from the
verified controllers, resources and services; the UI is exercised by component
tests with mocked data and hooks. Server-enforced behaviours (overlap rejection,
year-shrink protection, idempotent generation, control-gated finalize/close,
reopen refusals, cross-company 404, `403` without the permission) are honoured by
the UI and were verified by **reading the backend source**, not by a live call.

## 16. Known limitations and deviations from the blueprint

- **Routes:** top-level `/financial-years` and `/periods` rather than the
  blueprint's `accounting/…` nesting (§4).
- **No pagination metadata:** only Prev/Next "Page N" with `hasMore` (§7).
- **Flat-array edge case:** a full final page yields a mirrored empty next page;
  handled with an explicit empty-page state + Previous.
- **Financial-year selector scaling:** `useFinancialYearOptions` walks pages to a
  20-page (≈2000-year) cap; a company will not realistically approach this.
- **No live integration** (§15).

## 17. Confirmation that the backend was not modified

No backend file was edited. `git status --short -- accounting-app/backend` shows
only **pre-existing untracked** backend files that arrived with the initial pull
(an Audit controller/resource/policy, `Resources/Accounting/`, the Currencies
service, and the currencies/exchange-rates migrations). None were created,
edited or used by Phase 8. All Phase 8 changes live under
`accounting-app/frontend` and `accounting-app/docs`.

## 18. Confirmation that no commits, migrations, or seeders were performed

No `git commit`, `reset`, `stash`, `clean`, checkout or branch operation was run.
No migration or seeder was created or executed. All Phase 8 work remains
uncommitted/untracked.

## 19. Recommended next steps

1. Review and approve this report before the next phase (brief stop point).
2. Backend follow-ups (not frontend scope): return paginator `meta`/`links` (P2-1)
   so `hasMore` and totals become exact.
3. When a later phase adopts a query cache/forms stack, migrate these modules and
   add company-aware keys/invalidation.
