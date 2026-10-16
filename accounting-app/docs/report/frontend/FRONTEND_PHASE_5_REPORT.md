# Frontend Phase 5 — Chart of Accounts UI Implementation Report

- **Date:** 2026-10-12
- **Scope:** Frontend Phase 5 only — the Chart of Accounts module (list, search,
  filters, pagination, create, edit, activate/deactivate, delete) built on the
  existing backend API. No other business screen.
- **Status:** Complete. Lint, type-check, unit tests and production build pass.
- **Not committed:** all work is uncommitted/untracked, per the brief. The backend
  was not modified (`git status --short -- accounting-app/backend` is empty).

## 1. Scope and completion status

| Requirement (Phase 5 §17) | Status |
| --- | --- |
| Real backend contract verified | Done (§3) |
| Chart of Accounts page uses the authenticated shell | Done (`src/app/(app)/accounts/page.tsx`) |
| Requests go through the secure same-origin proxy | Done (`backendGet/Post/Put/Delete` → `/api/backend/*`) |
| Account responses runtime-validated | Done (`src/lib/accounts/validators.ts`) |
| List accurately reflects pagination capabilities | Done — flat array + `hasMore`, no fabricated totals (§7) |
| Search and filters use supported behaviour | Done — `search`, `account_type`, `is_active` (server-side) |
| Create/edit follow backend validation rules | Done (§6) |
| Activation/deactivation only if supported | Done — both verified and implemented |
| Permissions gate UI without replacing backend auth | Done (§5) |
| Company switching cannot expose stale account data | Done (§9) |
| Loading, error, empty, mutation states | Done |
| Tests cover core behaviour and security boundaries | Done (§12) |
| Lint, type-check, tests, build pass | Done (§13) |
| Phase 5 report complete | This document |
| Backend files untouched | Confirmed (§16) |
| No commits/resets/stash/migrations/seeders | Confirmed (§17) |

## 2. Initial Git status and pre-existing changes

Before work, `git status --short` at the repository root showed the Phase 2–4
frontend output uncommitted/untracked (modified `FRONTEND_BLUEPRINT.md`,
`.env.example`, `package.json`/lock, `layout.tsx`, `api/client.ts`; a deleted
`src/app/page.tsx`; many untracked `app/`, `components/`, `lib/` trees), plus the
prompt files. One pre-existing malformed-path untracked file
(`D:DevelopmentAccountance…PHASE_17_REPORT.md`, a fullwidth-colon name) was
present and left untouched.

No user work was discarded, reset, stashed or cleaned. Branch `main`, HEAD
`6f8bc9b`.

## 3. Actual account API contract and source locations

Verified directly from source (not from prior reports):

| Fact | Source |
| --- | --- |
| `GET /api/accounts` (query `search`, `account_type`, `is_active`, `page`, `per_page` default 50) | `routes/api.php:232-245`, `AccountController::index` |
| `POST /api/accounts` (201) | `AccountController::store` |
| `GET /api/accounts/{account}` | `AccountController::show` |
| `PUT /api/accounts/{account}` | `AccountController::update` |
| `DELETE /api/accounts/{account}` | `AccountController::destroy` |
| `POST /api/accounts/{account}/activate` | `AccountController::activate` |
| `POST /api/accounts/{account}/deactivate` | `AccountController::deactivate` |
| `GET /api/accounts/types` → `[{value, normal_balance}]` | `AccountController::types` |
| All routes behind `auth:api`, `auth.fresh`, `company.context`, `throttle:api`; model binding company-scoped | `routes/api.php:226`, `AppServiceProvider` |
| Envelope `{success,message,data?}`; errors `{success:false,message,errors?}` | `ApiResponse.php` |
| `AccountResource` fields | `AccountResource.php` |

### Contract table

| Operation | Endpoint | Method | Permission | Request / response shape |
| --- | --- | --- | --- | --- |
| List accounts | `/api/accounts` | GET | `accounts.view` | Query `search?`, `account_type?`, `is_active?`, `page?`, `per_page?`; `data` is a flat array of account resources |
| View account | `/api/accounts/{id}` | GET | `accounts.view` | `data` = one account resource |
| Account types | `/api/accounts/types` | GET | `accounts.view` | `data` = `[{value, normal_balance}]` |
| Create account | `/api/accounts` | POST | `accounts.create` | `{code, name, account_type, cash_bank_kind?, normal_balance?, description?}`; 201 |
| Update account | `/api/accounts/{id}` | PUT | `accounts.update` | same fields, all `sometimes`/`nullable`; omitted fields preserved |
| Activate | `/api/accounts/{id}/activate` | POST | `accounts.activate` | no body; `data` = updated account |
| Deactivate | `/api/accounts/{id}/deactivate` | POST | `accounts.deactivate` | no body; 422 for system accounts; `data` = updated account |
| Delete | `/api/accounts/{id}` | DELETE | `accounts.delete` | no body; 422 when journal history or sub-accounts |

### Contract details verified

- **ID format:** integer.
- **Code / name:** `code` `max:50`, regex `^[A-Za-z0-9._\- ]+$`, unique per
  company; `name` `max:255`, unique per company (`StoreAccountRequest`,
  `UpdateAccountRequest`, DB unique indexes, `AccountService::throwIfDuplicate`).
- **Type / subtype:** `account_type` ∈ ASSET|LIABILITY|EQUITY|REVENUE|EXPENSE;
  `cash_bank_kind` ∈ CASH|BANK or null.
- **Normal balance:** stored value `normal_balance` ∈ DEBIT|CREDIT **may be
  null**. `AccountResource` exposes both the persisted override
  (`normal_balance`, nullable) and the *effective* value plus `is_contra`
  (override present and different from the type default).
- **Contra representation:** `is_contra` boolean + `normal_balance` (the
  effective balance) — `Account::isContra()` / `normalBalance()`.
- **Active status:** boolean `is_active`.
- **Server-generated / immutable:** `id`, `is_system`, `created_at`, `updated_at`,
  `has_journal_history`, `is_cash_bank_account`. `is_active` is not
  mass-assignable (create is always active); it changes only via the lifecycle
  endpoints. `company_id` is never sent by the client.
- **Editable-after-use:** the backend does not block field edits by "used" status
  except cash/bank reclassification rules (an account with bank details cannot
  become cash; an account with accounting history keeps its kind —
  `CashBankAccountService`). The UI exposes the editable fields and lets the
  backend refuse; refusals surface as field/general errors.
- **Deletion constraints:** refused (422) when the account has any journal line
  or children (`AccountService::delete`).
- **Filters / sort:** `search` (LIKE on name OR code), `account_type`,
  `is_active`; fixed `orderBy('code')`.
- **Pagination:** see §7.
- **Exact permissions:** `accounts.view`, `accounts.create`, `accounts.update`,
  `accounts.activate`, `accounts.deactivate`, `accounts.delete`
  (`App\Enums\PermissionName`, `AccountPolicy`).

### One backend limitation found

`AccountService::update` merges code/name/description with
`array_filter(..., fn ($v) => $v !== null)`, so sending `description: null` does
**not** clear an existing description (unlike `TaxService`, which supports
clearing). The form sends `null` when the description is emptied (the contract's
intent via `nullable`); the clear simply does not persist server-side. No
frontend workaround was invented (sending `""` would diverge from the contract).
Documented as a known limitation — **no backend change made**.

## 4. Routes and methods implemented

- Route: **`/accounts`** inside the `(app)` route group
  (`src/app/(app)/accounts/page.tsx`) — a server component exporting page
  `metadata` and rendering `<AccountsView/>`, which runs behind the layout's
  existing session guard.
- Navigation: `src/lib/navigation/nav.ts` gains
  `{ href: "/accounts", label: "Chart of accounts", permission: "accounts.view" }`
  in the "Accounting" group; the old placeholder moved out of `upcoming`.
- HTTP methods used: `backendGet` (list, types), `backendPost` (create,
  activate, deactivate), `backendPut` (update), `backendDelete` (delete). All go
  through `/api/backend/*`; the browser never calls Laravel directly and never
  sees the JWT or `X-Company-Id`.

**Deviation:** the blueprint §2 tree suggested `accounting/accounts/…`. The phase
brief preferred `/accounts`, which was used. Noted in §15.

## 5. Permission names and their verified purpose

| Permission | Purpose | UI effect |
| --- | --- | --- |
| `accounts.view` | List/read accounts and account types | Page + nav link; **list is not fetched without it** (hook reports `unavailable`) |
| `accounts.create` | Create an account | "New account" button + create form |
| `accounts.update` | Edit an account | "Edit" row action |
| `accounts.activate` | Activate an inactive account | "Activate" row action |
| `accounts.deactivate` | Deactivate an active account | "Deactivate" row action |
| `accounts.delete` | Delete an unused account | "Delete" row action (disabled when `has_journal_history`) |

Role context (`config/authorization.php`): `Admin` is `['*']`; `Accountant`
includes view/create/update/activate/deactivate **but not** `accounts.delete`.
UI visibility is a convenience only — the backend policy remains authoritative;
a hidden button is never treated as a security control.

## 6. Fields supported for create and edit

Form fields: `code`, `name`, `account_type`, `cash_bank_kind` (optional, blank =
none), `normal_balance` (optional contra override; blank = type default),
`description`.

- The type→normal-balance rule is shown live from the **verified** types endpoint
  (`GET /api/accounts/types`), so the form never hard-codes the mapping; a local
  `TYPE_NORMAL_BALANCE` map is used only as a fallback before the types call
  resolves.
- **`parent_id` is intentionally omitted.** With an incomplete first-page list a
  parent picker would be misleading; the field is preserved on update because the
  backend request is `sometimes`.
- Server-owned fields (`id`, `is_active`, `is_system`, `company_id`, timestamps,
  `is_cash_bank_account`, `has_journal_history`) are never submitted.
- Client validation mirrors the verified rules (code regex/length, name length,
  required type) for immediate feedback; server `422` field errors are mapped
  back onto the matching controls, and general errors are shown without leaking
  internals.

## 7. Search, filtering, sorting, and pagination behaviour

- **Search:** single text box → `search` query param, debounced **300 ms**; the
  debounce timer is cleared on unmount and every run aborts the previous request,
  so a stale response cannot overwrite a newer search.
- **Filters:** `account_type` select and a three-state `is_active` select
  (All / Active / Inactive → omit / `true` / `false`). A "Clear" control resets
  all filters. Filtering is **server-side**, so it always applies to the whole
  company dataset — never a client-side filter over one page.
- **Sorting:** none exposed; the backend always orders by `code`.
- **Pagination:** the list response is a **flat `data` array** with no
  `links`/`meta`/`total`/`last_page` (verified: `AccountCrudTest` asserts
  `data.0.code`; the paginator meta does not survive `ApiResponse`). The UI
  therefore:
  - requests `per_page = 50`;
  - uses `normalizeList` to adapt the flat array (and a future paginator shape),
    deriving `hasMore` from a full page (`items.length === perPage`);
  - shows **Previous / "Page N" / Next** only, and **never** claims a total;
  - handles the `hasMore` edge case (exactly `per_page` results) with an
    explicit "No accounts on this page" state and a Previous button so an empty
    next page is not mistaken for "no accounts exist".
- **Last-page caveat:** because exact totals are unavailable, the list cannot
  display "showing X of Y"; it communicates position by page number and never
  implies completeness beyond what the contract supports.

## 8. Activation/deactivation/deletion capabilities and restrictions

- **Activate / Deactivate:** `POST /api/accounts/{id}/activate|deactivate`,
  each behind its own permission and a confirmation dialog that names the account
  (code + name). Success refreshes the list (via a version bump) and toasts;
  failure preserves the list and shows the error inline.
- **Deactivate is disabled for system accounts** (`is_system`) with an
  explanatory title, matching the backend's 422.
- **Delete:** `DELETE /api/accounts/{id}`, behind `accounts.delete`, with a
  danger-styled confirmation. The row's Delete action is **disabled when
  `has_journal_history`** (in-use accounts). The "has sub-accounts" restriction
  is backend-only (the list does not carry a child count); the resulting `422`
  message is surfaced accurately. No frontend-only "delete" that merely hides a
  row exists.
- Duplicate submissions are prevented (buttons disable while a request is
  pending).

## 9. Company-switch isolation strategy

No query cache was introduced, so isolation is implemented directly:

- `useAccountList` / `useAccountTypes` key every run on the **active company id**
  and **abort the in-flight request** (one `AbortController` per run) before
  starting the next.
- State is reset when the company (or filters) change; a resolved response from
  the previous company is discarded because its controller was aborted.
- Loading / "no active company" / missing-permission states are **derived during
  render** from the current run key, so a previous company's rows can never be
  painted for the new company.
- A missing active company short-circuits to a "select a company" state and
  issues **no unscoped request**.
- Covered by the hook tests: company-switch abort isolation, `403` →
  `unavailable`, and error mapping.

## 10. Dependencies added or reused

**No new dependencies.** Reused `backendGet/Post/Put/Delete`, the envelope/error
handling, `normalizeList`, the permission utilities, the design tokens, and the
existing UI primitives (`Button`, `Badge`, `Field`/`Input`/`Label`/`HelperText`/
`FieldError`, `ErrorState`, `EmptyState`, `Spinner`, sonner `toast`). One new
primitive was added — an accessible `dialog.tsx` — because no modal existed and
dialogs are required for forms and confirmations. Data fetching is hand-rolled
hooks and forms are native controlled components, consistent with
`use-dashboard-data.ts`/`login-form.tsx`. TanStack Query, react-hook-form and zod
were **not** added (see §11/§15).

## 11. TanStack Query / forms evaluation (Workstream H)

- **TanStack Query:** not adopted. The module needs a single list with filters
  and explicit refresh after mutations, all of which the existing hand-rolled +
  `AbortController` approach already provides correctly, including the company
  isolation the brief requires. Adding a cache would add a dependency and a new
  invalidation surface for no present benefit. Decision recorded in §15.
- **Forms:** native controlled components were sufficient for six scalar fields;
  react-hook-form + zod would duplicate the validators the codebase already
  maintains by hand. Not adopted.
- Writes are never auto-retried; reads are not retried unboundedly.

## 12. Tests and actual test counts

Run with `npm test` (Vitest). **Total: 138 tests / 15 files** (was 97 / 11).

New in Phase 5 (41 tests / 4 files):

| File | Tests | Coverage |
| --- | --- | --- |
| `src/lib/accounts/validators.test.ts` | 19 | valid row; missing required fields; malformed id/type; nullable normal balance; contra flags; invalid status; flat-list + future paginator shape; row-skipping |
| `src/lib/accounts/api.test.ts` | 9 | endpoints/methods/query params/payloads; 401/403/404/409/422; empty/malformed responses; no direct Laravel calls |
| `src/lib/accounts/use-account-list.test.ts` | 6 | load states; 403 → unavailable; company-switch abort isolation; missing company issues no request; error mapping |
| `src/components/accounts/accounts-view.test.tsx` | 7 | loading/success/empty/error; search + filters; permission-gated actions; create/edit validation & field errors; confirmation flow |

Existing suite: `src/lib/accounts/{validators,api,use-account-list}.test.ts` count
above; all prior 97 tests still pass. These are **unit/component tests against
mocked responses**; they are not a substitute for live integration (§14).

## 13. Lint, type-check, and production-build results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` (`eslint`) | **Pass**, exit 0 |
| Type-check | `npx tsc --noEmit` (TS 5.9.3) | **Pass**, 0 errors |
| Unit tests | `npm test` (Vitest 3.2.7) | **Pass**, 138/138 across 15 files |
| Build | `npm run build` (Next 16.4.0) | **Pass**; `/accounts` present as a dynamic route (`ƒ /accounts`) |

`package.json` has no `typecheck` script (blueprint §12 lists one); the
equivalent `npx tsc --noEmit` was run instead. This is a documentation-only
mismatch; no script was added to avoid unrelated changes.

## 14. Live backend integration

**Not performed.** No PHP runtime/DB is available in this environment, so no
request was made against a live Laravel instance. Response shapes are exercised
by validator unit tests against fixtures taken from the verified controllers,
resources and services; the UI is exercised by a component test with mocked data
and mocked hooks. Server-enforced behaviours (unique code/name, system-account
deactivation, delete constraints, cross-company 404) are honoured by the UI but
were verified by **reading the backend source and tests**, not by a live call.

## 15. Known limitations and deviations from the blueprint

- **Route:** `/accounts` (brief-preferred) rather than the blueprint's
  `accounting/accounts/…`.
- **Feature layout:** implemented as `src/lib/accounts/*` + `src/components/accounts/*`
  (matching the actual Phase 2–4 layout) rather than the blueprint §2
  `src/features/accounts/*` sketch.
- **No pagination metadata (P2-1):** numbers/totals are not shown because the API
  does not return them; only Prev/Next "Page N" with `hasMore`. This is the
  contract-accurate behaviour, not a deficiency to work around client-side.
- **`parent_id` not editable in the UI** (see §6) — a deliberate omission, not a
  missing feature.
- **Description cannot be cleared** through the API (backend limitation, §3); the
  form still sends the contract-correct `null`.
- **`has sub-accounts` delete restriction** can only be discovered from the
  backend's `422` (no child count in the list); the message is surfaced verbatim.
- **Data/forms dependencies:** TanStack Query, react-hook-form and zod
  deliberately not adopted (§11); blueprint open decisions §15.3/§15.4 are marked
  decided accordingly.

## 16. Confirmation that the backend was not modified

`git status --short -- accounting-app/backend` is empty; no backend file was
edited. All Phase 5 changes live under `accounting-app/frontend` and
`accounting-app/docs`.

## 17. Confirmation that no commits, migrations, or seeders were performed

No `git commit`, `reset`, `stash`, `clean`, checkout or branch operation was run.
No migration or seeder was created or executed. All Phase 5 work remains
uncommitted/untracked.

## 18. Files created / modified

**Added**

- `src/lib/accounts/types.ts`, `api.ts`, `validators.ts`, `use-account-list.ts`
  (+ `validators.test.ts`, `api.test.ts`, `use-account-list.test.ts`)
- `src/components/accounts/accounts-view.tsx`, `accounts-table.tsx`,
  `account-form-dialog.tsx`, `confirm-action-dialog.tsx`,
  `accounts-list-skeleton.tsx` (+ `accounts-view.test.tsx`)
- `src/components/ui/dialog.tsx` (accessible modal primitive)
- `src/app/(app)/accounts/page.tsx`
- `docs/FRONTEND_PHASE_5_REPORT.md` (this report)

**Modified**

- `src/lib/navigation/nav.ts` (Chart of accounts link)
- `docs/FRONTEND_BLUEPRINT.md` (§2 route/layout note, §12/§15 decisions)

**Untouched**

- `accounting-app/backend/**`, all Phase 0–4 reports, `FRONTEND_API_HANDOFF.md`
  (read only), `prompt/**`.

## 19. Recommended next steps

1. Review and approve this report before Phase 6 (brief §19 stop point).
2. Backend follow-ups (not frontend scope): return paginator `meta`/`links`
   (P2-1) so exact counts can be shown; allow clearing `description` on update.
3. When a later phase adopts a query cache/forms stack, migrate the accounts
   module and add company-aware keys/invalidation.
