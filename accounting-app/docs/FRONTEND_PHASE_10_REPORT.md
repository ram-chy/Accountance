# Frontend Phase 10 — Tax Management & Tax Reporting UI

- **Date:** 2026-10-12
- **Scope:** Frontend Phase 10 only — tax master list/detail, tax create/edit,
  activate/deactivate/delete, the effective-dated tax-rate lifecycle, the tax
  account-mapping panel, the read-only tax calculator, and the two tax reports.
  Built on the verified backend API. No other business screen.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked. No backend file was
  modified (pre-existing untracked backend files from the earlier pull remain — §16).

## 1. Executive summary and completion status

| Requirement (Phase 10) | Status |
| --- | --- |
| Real tax contract verified from source | Done (§4) |
| Screens use the authenticated shell | Done — `/taxes`, `/taxes/[id]`, `/tax-calculator`, `/tax-reports` |
| Requests go through the secure same-origin proxy | Done (`backendGet/Post/Put/Delete` → `/api/backend/accounting/*`) |
| Responses runtime-validated | Done (`src/lib/taxes/validators.ts`) |
| Tax list/detail accurately reflect the API | Done — unpaginated, `active_only` only (§6) |
| Tax create/edit | Done — full field set, no server-owned fields sent (§6) |
| Tax activate / deactivate / delete | Done — confirmed, backend refusals surfaced verbatim (§7) |
| Tax-rate lifecycle | Done — create/edit/activate/deactivate/delete (§8) |
| Tax account mapping | Done — output→liability, input→asset, full-replacement PUT (§9) |
| Read-only tax calculator | Done — `POST accounting/tax/calculate`, persists nothing (§10) |
| Tax reports (summary + by-tax) | Done — one date window, both endpoints (§11) |
| Permissions gate UI without replacing backend auth | Done (§5) |
| Company switching cannot expose stale data | Done (§12) |
| Loading, error, empty, not-found, not-configured states | Done |
| Accessible dialog behavior | Done — shared `Dialog` (focus trap, Escape) |
| Tests cover core behaviour and security boundaries | Done (§13) |
| Lint, type-check, tests, build pass | Done (§14) |
| Phase 10 report complete | This document |
| Backend files untouched | Confirmed (§16) |
| No commits/resets/stash/migrations/seeders | Confirmed (§17) |

## 2. Initial Git status and pre-existing user work

Branch **`main`**. No `git pull`, checkout, reset, stash or clean was performed.

`git status --short` before/after implementation shows:

- **Tracked, modified (1):** `accounting-app/frontend/src/lib/navigation/nav.ts`.
  Already modified by Phases 8–9; Phase 10 added three nav entries
  **additively** — the Phase 8/9 entries were preserved, not reverted.
- **Untracked — Phase 8/9 frontend** (financial-years, periods,
  bank-reconciliations modules, pages, docs) — preserved untouched.
- **Untracked — Phase 10 frontend** (the files in §12).
- **Untracked — prompts/docs** — preserved untouched.
- **Untracked — pre-existing backend** (see §16) — not touched.

## 3. Repository-preservation risks discovered before implementation

- The only tracked file Phase 10 needed to change was `nav.ts`, which carried
  uncommitted Phase 8/9 modifications. It was edited additively; no prior change
  was lost.
- No conflicting, unowned or ambiguous tracked changes were found. No risky Git
  operation was needed, so none was taken.
- The pre-existing untracked backend files are unrelated to this phase and were
  left exactly as found.

## 4. Backend contract verified from actual source

Verified by reading the source (not prior reports):

| Fact | Source |
| --- | --- |
| Routes `/api/accounting/taxes…`, `/api/accounting/tax/calculate`, `/api/accounting/tax-reports…` | `routes/api.php` (~L655–729) |
| Handlers | `app/Http/Controllers/Api/Accounting/TaxController.php`, `…/TaxReportController.php` |
| Resource fields | `app/Http/Resources/{TaxResource,TaxRateResource,TaxAccountMappingResource,TaxCalculationResource}.php` |
| Enums (`TaxType`, `TaxCalculationBasis`) | `app/Enums/TaxType.php`, `app/Enums/TaxCalculationBasis.php` |
| Write request rules | `app/Http/Requests/Accounting/StoreTaxRequest.php`, `…/UpdateTaxRequest.php`, `…/Tax/{CalculateTaxRequest,StoreTaxRateRequest,UpdateTaxRateRequest,UpdateTaxAccountMappingRequest}.php`, `…/Reports/{ReportRequest,TaxReportRequest}.php` |
| Policy abilities | `app/Policies/TaxPolicy.php` |
| Rate resolution / calculation / mapping / deletion rules | `app/Services/Accounting/Tax/{TaxService,TaxRateService,TaxCalculationService,TaxRuleResolver,TaxAccountMappingService}.php` |
| Report figures | `app/Services/Accounting/Reports/TaxReportService.php` |
| Company-scoped route bindings | `app/Providers/AppServiceProvider.php` |
| Route-binding middleware group | `routes/api.php` (`auth:api`, `auth.fresh`, `company.context`, `throttle:api`) |

### Contract table

| Action | Endpoint | Method | Permission |
| --- | --- | --- | --- |
| List | `/api/accounting/taxes` | GET | `accounting.tax.view` |
| Create | `/api/accounting/taxes` | POST | `accounting.tax.create` |
| Detail | `/api/accounting/taxes/{tax}` | GET | `accounting.tax.view` |
| Update | `/api/accounting/taxes/{tax}` | PUT | `accounting.tax.update` |
| Delete | `/api/accounting/taxes/{tax}` | DELETE | `accounting.tax.delete` |
| Activate | `/api/accounting/taxes/{tax}/activate` | POST | `accounting.tax.update` |
| Deactivate | `/api/accounting/taxes/{tax}/deactivate` | POST | `accounting.tax.update` |
| Rates | `/api/accounting/taxes/{tax}/rates` | GET | `accounting.tax.view` |
| Create rate | `/api/accounting/taxes/{tax}/rates` | POST | `accounting.tax.create` |
| Update rate | `/api/accounting/taxes/{tax}/rates/{rate}` | PUT | `accounting.tax.update` |
| Delete rate | `/api/accounting/taxes/{tax}/rates/{rate}` | DELETE | `accounting.tax.delete` |
| Activate rate | `/api/accounting/taxes/{tax}/rates/{rate}/activate` | POST | `accounting.tax.update` |
| Deactivate rate | `/api/accounting/taxes/{tax}/rates/{rate}/deactivate` | POST | `accounting.tax.update` |
| Mapping show | `/api/accounting/taxes/{tax}/account-mapping` | GET | `accounting.tax.view` |
| Mapping update | `/api/accounting/taxes/{tax}/account-mapping` | PUT | `accounting.tax.update` |
| Calculate | `/api/accounting/tax/calculate` | POST | `accounting.tax.calculate` |
| Summary report | `/api/accounting/tax-reports/summary` | GET | `accounting.tax.report.view` |
| By-tax report | `/api/accounting/tax-reports/by-tax` | GET | `accounting.tax.report.view` |

### Key verified rules

- **Company isolation is route-bound.** `{tax}` and `{rate}` resolve within the
  authenticated company, so a foreign id 404s before the controller runs. The
  browser never sends `company_id`; the proxy supplies the context. A calculation
  body naming a foreign tax id comes back as a 422, not a leak.
- **`POST /accounting/tax/calculate` writes nothing.** `tax_ids` absent and `[]`
  are the same ("no taxes" → untaxed result, not a 422); `basis` defaults to the
  first named tax's configured value.
- **Enum safety.** A tax whose `tax_type`/`calculation_basis` is not a known value
  is treated as "not safe to render" and the row is skipped — never coerced.
- **All money/rates are decimal strings** (never JSON numbers). The frontend never
  round-trips them through `Number`.
- **Server-owned / never sent:** `id`, `company_id`, `is_active`, the derived
  `applies_to_*` flags, `current_rate`, audit columns, timestamps, and the
  calculation result.
- **Reports are derived from posted documents' own line snapshots**, so a rate
  changed since cannot restate a past period. A since-deleted tax leaves a row
  with `tax_id` but null code/name/type.

## 5. Permissions and authorization behaviour

| Permission | UI effect |
| --- | --- |
| `accounting.tax.view` | List, detail, rates and mapping reads; without it the screens show "unavailable" and issue **no request** |
| `accounting.tax.create` | "New tax" (header/list) and "New rate" (detail) |
| `accounting.tax.update` | Edit tax; Activate/Deactivate tax; edit rate; Activate/Deactivate rate; save the account mapping |
| `accounting.tax.delete` | Delete tax; Delete rate |
| `accounting.tax.calculate` | The calculator screen; without it the view is "unavailable" and issues no request |
| `accounting.tax.report.view` | The reports screen; without it the view is "unavailable" and issues no request |
| `accounts.view` | Optional — enables the mapping pickers; without it the mapping is read-only and **no account request is made** |

Visibility is convenience only; the backend policy re-authorises every request.
Notably the delete **tax** action is gated on the dedicated `accounting.tax.delete`
permission (the policy's `delete` ability), while delete **rate** maps to the same
policy's `destroyRate` which requires `accounting.tax.update`.

## 6. Tax list, create/edit

- Route `/taxes` renders `TaxesView`. The only supported filter is **Show**
  (`All taxes` / `Active taxes only`, sent as `?active_only=1`); the index is
  **not paginated**, so there is no page state — matching the contract.
- Columns: code, name (+ type/basis sub-label), applies-to side, current rate,
  status badges (active/inactive; a "No current rate" warning when
  `current_rate` is null), and a **Manage** link to the detail. Responsive: table
  on `md+`, stacked cards below.
- Create/edit uses one dialog (`TaxFormDialog`) whose fields mirror
  `StoreTaxRequest`/`UpdateTaxRequest` exactly — code, name, optional description,
  `tax_type` (OUTPUT/INPUT/BOTH), `calculation_basis` (EXCLUSIVE/INCLUSIVE).
  Client validation mirrors the request's shape; the backend re-validates and its
  field-level 422 is shown in place. The parent remounts the dialog via `key` so
  records never leak into one another.

## 7. Tax lifecycle and deletion

- **Activate/Deactivate** (`accounting.tax.update`) and **Delete**
  (`accounting.tax.delete`) each go through a confirmation dialog
  (`TaxActionDialog`) that states the consequence. The frontend does not
  pre-empt the server: a delete the backend refuses (referenced by a posted
  document or a reachable rate) surfaces that message verbatim. Deletion
  navigates back to the list.

## 8. Tax-rate lifecycle

- The detail screen loads `GET …/{tax}/rates` and renders the effective-dated
  history (`TaxRatesTable`): rate, effective-from, effective-to (or
  "Open-ended"), is-active and is-open-ended badges.
- Create/edit (`TaxRateFormDialog`): percentage, effective-from, optional
  effective-to (empty = open-ended). The rate is entered and sent as a decimal
  string exactly as typed — never parsed to a float. Overlap with the existing
  history is **not** pre-checked; the backend owns that rule (inside a
  transaction) and its 422 is shown verbatim.
- Activate/deactivate/delete (`TaxRateActionDialog`), with the delete refusal
  (a rate a posted document used) shown verbatim.

## 9. Tax account mapping

- The panel (`TaxMappingPanel`) reads the mapping embedded in the tax detail and
  writes with a **full-replacement PUT**: both sides are always submitted, so
  clearing one side sends `null` rather than leaving the previous value.
- Output tax maps to a **liability** account and input tax to an **asset**; the
  pickers filter to those account types. Account loading is gated on
  `accounts.view`; without it the mapping is shown read-only from the tax payload
  and no account request is made.

## 10. Read-only tax calculator

- Route `/tax-calculator` renders `TaxCalculatorView`. It posts amount, date,
  optional basis override and a chosen tax set to
  `POST /accounting/tax/calculate`, which persists nothing.
- An empty selection is legitimate ("does this figure already include tax?") and
  the button is always available; the backend returns the amount untaxed.
- The result (taxable amount, total tax, gross amount, and one line per
  component) is rendered exactly as returned; the frontend derives and rounds
  nothing. The tax picker lists active taxes and depends on `accounting.tax.view`.

## 11. Tax reports

- Route `/tax-reports` renders `TaxReportsView`. Both the **summary** and
  **by-tax** endpoints are fetched together for one inclusive `from`/`to` window
  and resolved as a single state.
- The summary card shows the totals (sales taxable, output tax, purchase taxable,
  input tax, net tax, unattributed tax). The by-tax table adds one row per tax
  with the same columns and a totals footer, and shows the base-currency code
  when present. A row whose tax was deleted keeps its figures and is labelled
  `Tax #id` rather than dropped.

## 12. Files added and changed

- **Data layer** `src/lib/taxes/`: `types.ts`, `validators.ts`, `api.ts`,
  `use-taxes.ts` (list), `use-tax.ts` (detail), `use-tax-rates.ts` (rate history),
  `use-tax-report.ts` (both reports), `use-tax-account-options.ts` (mapping
  pickers), plus `validators.test.ts`, `api.test.ts`.
- **Feature UI** `src/components/taxes/`:
  `taxes-view.tsx`, `taxes-table.tsx`, `taxes-list-skeleton.tsx`,
  `tax-form-dialog.tsx`, `tax-action-dialog.tsx`, `tax-detail-view.tsx`,
  `tax-detail-skeleton.tsx`, `tax-rate-form-dialog.tsx`,
  `tax-rate-action-dialog.tsx`, `tax-rates-table.tsx`, `tax-mapping-panel.tsx`,
  `tax-calculator-view.tsx`, `tax-reports-view.tsx`, plus
  `taxes-view.test.tsx`, `tax-calculator-view.test.tsx`, `tax-reports-view.test.tsx`.
- **Routes** `src/app/(app)/taxes/page.tsx`,
  `src/app/(app)/taxes/[id]/page.tsx` (parses the id and calls `notFound()` for a
  non-numeric segment), `src/app/(app)/tax-calculator/page.tsx`,
  `src/app/(app)/tax-reports/page.tsx`.
- **Navigation** `src/lib/navigation/nav.ts` — **Accounting** now lists **Taxes**
  (`/taxes`, `accounting.tax.view`) and **Tax calculator** (`/tax-calculator`,
  `accounting.tax.calculate`); **Reports** now lists **Tax reports**
  (`/tax-reports`, `accounting.tax.report.view`), moved out of `upcoming`.
- **Docs** `docs/FRONTEND_PHASE_10_REPORT.md` (this report).

No change was made to `FRONTEND_BLUEPRINT.md` or `FRONTEND_API_HANDOFF.md`: the
handoff already documents the tax routes, and no verified contract fact changed.

## 13. Tests

New tests (Vitest + Testing Library), **54 tests across 5 files**:

- `src/lib/taxes/validators.test.ts` — tax narrowing (required identity, unknown
  enum rejection, null current rate, embedded mapping), list narrowing/row
  skipping, `OrThrow`, rate narrowing/open-ended/list, calculation narrowing with
  malformed-component dropping, report narrowing (summary and by-tax with base
  currency), and the tax/rate/calculation/report-range validators.
- `src/lib/taxes/api.test.ts` — proxy routing, `active_only` query, abort-signal
  propagation, detail, rates, mapping show, tax writes (create/update/delete),
  activates/deactivates (tax and rate), rate writes, mapping full-replacement PUT,
  the singular calculate route, and both report routes.
- `src/components/taxes/taxes-view.test.tsx` — rows + detail links, empty /
  no-company / permission / error states, and the create action gating.
- `src/components/taxes/tax-calculator-view.test.tsx` — picker rendering,
  permission and no-company states, and a full calculate round-trip asserting the
  POST body and the rendered result.
- `src/components/taxes/tax-reports-view.test.tsx` — summary + by-tax rendering,
  the deleted-tax label, permission / no-company / error states.

No existing test was removed or weakened.

## 14. Quality-gate results

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` | Pass — 0 errors, 0 warnings |
| Type-check | `npx tsc --noEmit` | Pass (exit 0) |
| Tests | `npm test` (`vitest run`) | Pass — **409/409 across 39 files** (was 355/34 after Phase 9; +54 tests / +5 files) |
| Build | `npm run build` | Pass — `/taxes`, `/taxes/[id]`, `/tax-calculator`, `/tax-reports` emitted |

There is no `typecheck` npm script, so `npx tsc --noEmit` is used. Dependencies
were already installed; no dependency or script was changed.

## 15. Live integration status

**Not performed.** No PHP runtime/DB is available in this environment. Response
shapes are exercised by validator tests against fixtures derived from the actual
resources/services, and the UI by component tests with mocked data, hooks and
API. Server-enforced behaviour (cross-company 404, `403` without the permission,
rate-overlap rejection, the zero-rate calculation 422, delete refusals) is
honoured by the UI and was established by **reading the backend source and its
feature tests**, not by a live call. No claim of live verification of company
isolation, permissions, calculation or financial reporting is made.

## 16. Known limitations and deviations from the blueprint

- **Routes:** top-level `/taxes` (and `/[id]`), `/tax-calculator` and
  `/tax-reports` rather than the blueprint's nesting, consistent with Phases 5–9.
- **Tax list filter:** only `active_only` (the sole verified filter). No search or
  pagination was added, because the endpoint supports neither.
- **Rate overlap** is not pre-checked client-side; the backend rejects it and its
  422 is shown verbatim (§8).
- **Mapping pickers** depend on `accounts.view`; without it the mapping degrades
  to read-only (§9).
- **Calculator tax picker** depends on `accounting.tax.view`; a caller with only
  `calculate` can still run an untaxed calculation (§10).
- No live integration (§15).

## 17. Confirmation that the backend was not modified, and no destructive Git/migration operations

No backend file was edited. `git status --short -- accounting-app/backend` shows
only **pre-existing untracked** backend files (an Audit controller/resource/
policy, `Resources/Accounting/`, the Currencies service, and the
currencies/exchange-rates migrations) that arrived with the earlier pull and were
not created, edited or used by Phase 10. All Phase 10 changes live under
`accounting-app/frontend` and `accounting-app/docs`.

No `git pull`, `reset`, `stash`, `clean`, checkout or branch operation was run. No
migration or seeder was created or executed. All Phase 10 work remains
uncommitted/untracked; no pre-existing user work was discarded.

## 18. Recommended next steps

1. Review and approve this report before the next phase (brief stop point —
   Phase 10 is complete; Phase 11 is **not** started).
2. Optional backend follow-ups (not frontend scope): expose a tax search/pagination
   filter if the list grows, and surface the rate-overlap constraint as a
   structured field error if the current message is not sufficient.
