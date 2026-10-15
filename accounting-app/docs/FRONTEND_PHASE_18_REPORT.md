# Accountance Frontend — Phase 18 Report

**Phase:** 18 — Multi-Currency & Foreign Exchange (FX)
**Scope:** Frontend only
**Date:** 2026-10-15
**Report path:** `accounting-app/docs/FRONTEND_PHASE_18_REPORT.md`

> **Git note.** The Phase 18 brief prohibits Git commit and push unless explicitly
> authorized by the operator (§2.2 "Prohibited Git operations", §20 "Stop
> Condition"). No commit was created, no push was performed, and no other
> prohibited Git operation (pull/fetch/reset/stash/clean/checkout/switch/restore/
> amend) was run. All Phase 18 work remains uncommitted as modified and untracked
> files for the operator to review and commit if desired.

> **Verification legend used throughout.**
> **[source]** verified by reading the Laravel backend source.
> **[tests]** verified by the frontend automated test suite.
> **[build]** verified by a successful production build (routes emitted).
> **[live]** verified against a running backend/database — **none of Phase 18 was
> verified this way.**

---

## 1. Executive summary

Phase 18 delivered the Multi-Currency & Foreign Exchange area end to end against
the verified Laravel API, frontend-only:

- **Currency master** (`/currencies`, `/currencies/[id]`) — list, create, edit,
  and activate/deactivate against the global `accounting.currencies` resource.
  Currencies are global reference data, so the UI adds no company scoping and
  offers no delete (the lifecycle ends at deactivation). The only index filter the
  backend accepts (`active_only`) is the only one presented. [source][tests][build]
- **Exchange rates** (`/exchange-rates`, `/exchange-rates/[id]`) — list with the
  verified filters (`from_currency_id`, `to_currency_id`, `active_only`), create,
  edit, and activate/deactivate. The direction convention
  (`rate` = units of the *to* currency per **one** unit of the *from* currency) is
  stated explicitly, and the rate is rendered as the exact decimal string the
  backend sends, never as a JSON number. The pair is immutable on edit, matching
  `UpdateExchangeRateRequest`. [source][tests][build]
- **Currency settings** (`/currency-settings`) — the one company-level currency
  setting the backend exposes: the realised FX gain/loss account pair
  (`GET`/`PUT /accounting/fx-settings`). Both accounts are written together or
  cleared together, matching the backend's paired rule; the gain picker offers
  only revenue accounts and the loss picker only expense accounts. [source][tests][build]
- **Navigation** — a real, permission-keyed "Multi-currency" section (Currencies,
  Exchange rates, Currency settings) replacing nothing and inventing nothing.
  [source][tests]
- **Tests** — API clients, validators, and component tests for all three
  surfaces. The full suite is **92 files / 880 tests, all passing**; Phase 18 added
  **9 test files (80 tests)** and extended the navigation test with 2 assertions.

**Scope boundary (important).** The brief's sections E (transaction modules that
carry a currency/rate) and G (currency-aware reporting) were **already implemented
in earlier phases** (journals, ledger movements, the base-currency blocks on trial
balance / balance sheet / P&L / customer & supplier statements / receivables &
payables & aging, and the dashboard's currency parsing — Phases 13, 14 and 17).
Phase 18 does **not** modify or duplicate them; §14 records that finding.

All quality gates pass (ESLint, TypeScript, Vitest, production build). No backend
file, dependency, lockfile, or prior report was changed. Live integration was
**not** performed and is not claimed.

---

## 2. Initial branch, HEAD, and Git status

- Repository root: `D:\Development\Accountance`
- Branch: `main` (`main...origin/main`)
- HEAD: `4cb1614` — "feat(frontend): Phase 17 fixed assets foundation (types and validators)"

`git status --short --branch` at start of Phase 18 (Phase 17 work still uncommitted
from the previous task, as expected):

```
## main...origin/main
 M accounting-app/frontend/src/lib/fixed-assets/validators.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? accounting-app/docs/FRONTEND_PHASE_17_REPORT.md
?? accounting-app/frontend/src/app/(app)/fixed-assets/
?? accounting-app/frontend/src/components/fixed-asset-categories/
?? accounting-app/frontend/src/components/fixed-assets/
?? accounting-app/frontend/src/lib/fixed-asset-categories/…
?? accounting-app/frontend/src/lib/fixed-assets/api.ts
?? accounting-app/frontend/src/lib/fixed-assets/api.test.ts
?? accounting-app/frontend/src/lib/fixed-assets/use-*.ts
?? accounting-app/frontend/src/lib/fixed-assets/validators.test.ts
?? "prompt/frontend Prompt/Phase 1"
?? "prompt/frontend Prompt/Phase 18.md"
```

The working tree was inspected afresh rather than trusting the Phase 17 report.
The only pre-existing tracked modifications at Phase 18 start were the Phase 17
foundation files (`fixed-assets/validators.ts`, `nav.ts`, `nav.test.ts`).

`"prompt/frontend Prompt/Phase 1"` is an unrelated pre-existing untracked file. It
was **not** touched, deleted, or cleaned up. `"prompt/frontend Prompt/Phase 18.md"`
is the brief itself.

`.gitignore`d vendor/build artefacts (`node_modules`, `.next`, `vendor`) are not
listed.

---

## 3. Repository-preservation confirmation

- No file under `accounting-app/backend/` was modified. [source: `git status`]
- No dependency, `package.json`, or lockfile was changed; nothing was installed.
- No migration or seeder was run.
- No existing test was deleted or weakened. The only pre-existing test file
  changed is `nav.test.ts`, and only to add the new Multi-currency assertions
  (existing assertions preserved).
- No completed phase's source was altered. The only shared-code additions are the
  new navigation section in `nav.ts` and a new additive shared hook
  `lib/accounts/use-account-type-options.ts` (no existing file edited to create it;
  see §8 for why it exists).
- Prior phase reports and prompts were not overwritten. The only new document is
  this report.
- No prohibited Git operation was run.

---

## 4. Verified backend endpoint inventory

From `accounting-app/backend/routes/api.php` (Phase 14 multi-currency), all inside
the `auth:api, auth.fresh, company.context, throttle:api` group under
`/accounting`. [source]

### Currencies (global — no company id in any request)

| Method | Path | Action | Route name |
| ------ | ---- | ------ | ---------- |
| GET | `/accounting/currencies` | index (filter `active_only`) | `accounting.currencies.index` |
| POST | `/accounting/currencies` | store (201) | `accounting.currencies.store` |
| GET | `/accounting/currencies/{currency}` | show | `accounting.currencies.show` |
| PUT | `/accounting/currencies/{currency}` | update | `accounting.currencies.update` |
| POST | `/accounting/currencies/{currency}/activate` | activate | `accounting.currencies.activate` |
| POST | `/accounting/currencies/{currency}/deactivate` | deactivate | `accounting.currencies.deactivate` |

There is **no `DELETE`**: "a currency that a posted document or a rate referenced
must stay readable forever, so the lifecycle ends at deactivation."
[source: `CurrencyController`, `CurrencyPolicy`]

`CurrencyResource` shape [source: `CurrencyResource.php`]:
`id, code, name, symbol, decimal_precision, is_active, created_at, updated_at`.

### Exchange rates (company-scoped — company comes from context)

| Method | Path | Action | Route name |
| ------ | ---- | ------ | ---------- |
| GET | `/accounting/exchange-rates` | index (filters `from_currency_id`, `to_currency_id`, `active_only`) | `accounting.exchange-rates.index` |
| POST | `/accounting/exchange-rates` | store (201) | `accounting.exchange-rates.store` |
| GET | `/accounting/exchange-rates/{exchangeRate}` | show | `accounting.exchange-rates.show` |
| PUT | `/accounting/exchange-rates/{exchangeRate}` | update | `accounting.exchange-rates.update` |
| POST | `/accounting/exchange-rates/{exchangeRate}/activate` | activate | `accounting.exchange-rates.activate` |
| POST | `/accounting/exchange-rates/{exchangeRate}/deactivate` | deactivate | `accounting.exchange-rates.deactivate` |

There is **no `DELETE`** and no date filter. The index is ordered
`effective_date` desc, then `id` desc. [source]

`ExchangeRateResource` shape [source: `ExchangeRateResource.php`]:
`id, from_currency_id, to_currency_id, from_currency` (code, whenLoaded),
`to_currency` (code, whenLoaded), `pair` (e.g. `USD/INR`, whenLoaded),
`effective_date, rate` (decimal **string**), `is_active, source, created_by,
updated_by, created_at, updated_at`.

### FX settings (company-scoped singleton — no id)

| Method | Path | Action | Route name |
| ------ | ---- | ------ | ---------- |
| GET | `/accounting/fx-settings` | show | `accounting.fx-settings.show` |
| PUT | `/accounting/fx-settings` | update | `accounting.fx-settings.update` |

`CompanyFxSettingResource` shape [source: `CompanyFxSettingResource.php`]:
`company_id, realized_gain_account_id, realized_loss_account_id, is_configured`
(derived: true unless both ids are null), `realized_gain_account`,
`realized_loss_account` (`{id, code, name} | null`, whenLoaded), `updated_at`.

### Base currency

| Method | Path | Action | Route name |
| ------ | ---- | ------ | ---------- |
| PUT | `/company/settings/base-currency` | update base currency | `company.settings.base-currency` |

**There is no GET that reads the company's functional/base currency.** See §14
for why no base-currency UI was built. [source]

### Request bodies and rules (verified) [source]

- `StoreCurrencyRequest`: `code` required, max 3, regex `^[A-Za-z]{3}$/`; `name`
  required, max 100; `symbol` nullable, max 10; `decimal_precision` optional
  integer 0–9. `CurrencyService` normalises the code to uppercase, defaults the
  precision to 2, and enforces the ISO-realism range **0–4** (its own rule, not the
  request's `max:9`). `UpdateCurrencyRequest`: PUT, all fields optional; **no
  `is_active`** (activation is a separate audited route).
- `StoreExchangeRateRequest`: `from_currency_id` required int; `to_currency_id`
  required int; `rate` required `> 0`; `effective_date` required `Y-m-d`; `source`
  nullable max 100. `UpdateExchangeRateRequest`: pair **absent**; `rate`,
  `effective_date`, `source` optional (partial).
- `UpdateCompanyFxSettingRequest`: both fields **required to be present** and
  nullable — clearing sends both as null. The service refuses a half-set pair,
  requires the gain account to be `REVENUE` and the loss account to be `EXPENSE`,
  and requires both to belong to the company.

### Service rules surfaced by the UI (verified) [source: `ExchangeRateService`]

- One row per currency pair per effective date (a duplicate date is refused).
- A self-pair (`from == to`) is allowed only at rate 1.
- A rate may be corrected only while it has **not** priced a document; the client
  cannot detect that state (there is no `priced` flag), so the backend's 422 is
  surfaced verbatim rather than guessed at.
- A rate may reference only an **active** currency.

---

## 5. Exact permission keys

From `accounting-app/backend/config/authorization.php` and
`PermissionName.php`. [source]

| Capability | Permission key | Held by |
| ---------- | -------------- | ------- |
| Currency list/detail (read) | `accounting.currency.view` | Admin, Accountant, Manager |
| Currency create | `accounting.currency.create` | Admin |
| Currency edit | `accounting.currency.update` | Admin |
| Currency activate | `accounting.currency.activate` | Admin |
| Currency deactivate | `accounting.currency.deactivate` | Admin |
| Exchange rate read | `accounting.exchange_rate.view` | Admin, Accountant, Manager |
| Exchange rate create | `accounting.exchange_rate.create` | Admin, Accountant |
| Exchange rate update + activate/deactivate | `accounting.exchange_rate.update` | Admin, Accountant |
| FX settings read **and** write | `accounting.fx.update` | Admin, Accountant |

Notes:
- Currency **writes** are held by Admin alone: a currency is global, so creating
  one changes the vocabulary every tenant may bill in.
- Exchange-rate **activate/deactivate ride on `accounting.exchange_rate.update`**;
  there is no separate activate/deactivate grant. The UI gates those controls on
  `update` accordingly.
- FX settings have **no separate read ability**: `view` and `update` are both
  `accounting.fx.update`. The screen is therefore gated on that one key.
- Manager is read-only for currencies and rates; the UI hides every write control
  for that role and the backend re-checks every call.

---

## 6. Currency master implementation

**Routes:** `/currencies` (list) and `/currencies/[id]` (detail).

- **List** — `useCurrencyList` reads `GET /accounting/currencies`, filtered only by
  the one supported filter (`active_only`, presented as "All currencies / Active
  currencies only"). The list is unpaginated by contract (ordered by `code`), so
  there is no page state. Rows link to the detail route, and write controls (Edit,
  Activate/Deactivate) are permission-gated per row. The create button is gated on
  `accounting.currency.create`.
- **Detail** — `useCurrency` reads `GET /accounting/currencies/{id}`; a 404 renders
  the not-found boundary (currencies are global, so a 404 simply means the id does
  not exist). Edit and the lifecycle transition live here too.
- **Form** — `code` (upper-cased as typed, exactly three letters), `name`,
  `symbol` (optional) and `decimal_precision` (0–4). `is_active` is deliberately
  absent: a new currency is active by definition and the lifecycle is a separate
  audited route, not a form field. Server-owned columns are never sent.
- **Lifecycle** — a confirmation dialog for activate/deactivate; there is no
  Delete. A successful write bumps a reload token so the list/detail re-reads
  authoritative state.

---

## 7. Exchange-rate implementation

**Routes:** `/exchange-rates` (list) and `/exchange-rates/[id]` (detail).

- **List** — `useExchangeRateList` reads `GET /accounting/exchange-rates` with the
  verified filters: a from-currency picker, a to-currency picker and
  "All rates / Active rates only". There is no effective-date filter (the endpoint
  has none) and the list is unpaginated by contract. A "Clear filters" action
  appears only while a filter is active.
- **Direction** — the table and detail spell the convention out in full
  (`1 USD = 83.5000000000 INR`) rather than showing a bare pair, because the whole
  contract rests on "units of the to currency per one unit of the from currency".
  The rate is a decimal **string** and is rendered verbatim; the browser never does
  arithmetic on it. [source: `Rate` value object; `ExchangeRateResource`]
- **Form** — create takes the from/to pair, a positive decimal rate (up to 10
  places), an effective date and an optional source. Edit shows the pair read-only
  and changes only value/date/source, matching `UpdateExchangeRateRequest`. A
  refusal from the backend (a rate that has priced a document; a duplicate
  pair-and-date; a self-pair other than 1) is surfaced verbatim.
- **Lifecycle** — activate/deactivate (gated on `accounting.exchange_rate.update`);
  no Delete. There is no `priced` flag, so the UI does **not** attempt to
  pre-disable editing; it relies on the backend refusal and shows it.

---

## 8. FX settings (Currency settings) implementation

**Route:** `/currency-settings`.

- `useFxSetting` reads the singleton `GET /accounting/fx-settings`; there is no id
  and no not-found case (the row always exists for a company).
- `FxSettingsForm` writes `PUT /accounting/fx-settings`, always sending **both**
  account ids together; "Clear configuration" sends both as null. This mirrors the
  `UpdateCompanyFxSettingRequest` "both present" rule and the service's paired
  refusal, so a half-set state is not constructible in the UI.
- The gain picker offers only `REVENUE` accounts and the loss picker only
  `EXPENSE` accounts — the types the service requires. An inactive account is
  still listed (with a suffix) when it is the currently configured one, so the form
  always reflects the stored setting.
- Both reading and writing require `accounting.fx.update`, so the whole screen is
  gated on that one capability.

**Why a new shared hook exists.** The FX pickers must be filtered by account type,
and the shared `useAccountOptions` deliberately drops `account_type`. Rather than
import the tax-specific `useTaxAccountOptions` into the FX feature (a cross-feature
dependency) or edit completed-phase code, Phase 18 adds a small **additive** shared
hook, `lib/accounts/use-account-type-options.ts`, which returns each account's
`account_type` while walking the flat, total-less account pages exactly as the
existing hooks do. No existing file was modified to create it.

---

## 9. Route inventory

| Route | Page | Purpose |
| ----- | ---- | ------- |
| `/currencies` | `app/(app)/currencies/page.tsx` | Currency master list |
| `/currencies/[id]` | `app/(app)/currencies/[id]/page.tsx` | Currency detail |
| `/exchange-rates` | `app/(app)/exchange-rates/page.tsx` | Exchange-rate list |
| `/exchange-rates/[id]` | `app/(app)/exchange-rates/[id]/page.tsx` | Exchange-rate detail |
| `/currency-settings` | `app/(app)/currency-settings/page.tsx` | Company FX settings |

All five are emitted by the production build (§12). The dynamic `[id]` pages parse
the segment and call `notFound()` for a non-numeric id, so a bad URL renders the
not-found boundary rather than issuing a doomed request.

---

## 10. Files added and modified

### New — lib layer (`src/lib/`)

- `currencies/types.ts`, `currencies/validators.ts`, `currencies/api.ts`,
  `currencies/use-currencies.ts`, `currencies/use-currency.ts`,
  `currencies/use-currency-options.ts`
- `exchange-rates/types.ts`, `exchange-rates/validators.ts`,
  `exchange-rates/api.ts`, `exchange-rates/use-exchange-rates.ts`,
  `exchange-rates/use-exchange-rate.ts`
- `fx-settings/types.ts`, `fx-settings/validators.ts`, `fx-settings/api.ts`,
  `fx-settings/use-fx-setting.ts`
- `accounts/use-account-type-options.ts` (additive shared hook — see §8)

### New — components (`src/components/`)

- `currencies/currencies-view.tsx`, `currencies/currencies-table.tsx`,
  `currencies/currencies-list-skeleton.tsx`,
  `currencies/currency-form-dialog.tsx`, `currencies/currency-action-dialog.tsx`,
  `currencies/currency-detail-view.tsx`
- `exchange-rates/exchange-rates-view.tsx`,
  `exchange-rates/exchange-rates-table.tsx`,
  `exchange-rates/exchange-rates-list-skeleton.tsx`,
  `exchange-rates/exchange-rate-form-dialog.tsx`,
  `exchange-rates/exchange-rate-action-dialog.tsx`,
  `exchange-rates/exchange-rate-detail-view.tsx`
- `fx-settings/fx-settings-view.tsx`, `fx-settings/fx-settings-form.tsx`,
  `fx-settings/fx-settings-skeleton.tsx`

### New — routes (`src/app/(app)/`)

- `currencies/page.tsx`, `currencies/[id]/page.tsx`
- `exchange-rates/page.tsx`, `exchange-rates/[id]/page.tsx`
- `currency-settings/page.tsx`

### New — tests

- `lib/currencies/api.test.ts`, `lib/currencies/validators.test.ts`
- `lib/exchange-rates/api.test.ts`, `lib/exchange-rates/validators.test.ts`
- `lib/fx-settings/api.test.ts`, `lib/fx-settings/validators.test.ts`
- `components/currencies/currencies-view.test.tsx`
- `components/exchange-rates/exchange-rates-view.test.tsx`
- `components/fx-settings/fx-settings-view.test.tsx`

### Modified

- `src/lib/navigation/nav.ts` — added the "Multi-currency" section (3 items),
  placed after "Fixed assets"; no existing section altered.
- `src/lib/navigation/nav.test.ts` — added 2 tests for the new section.

### Not modified

- No `accounting-app/backend/**` file. No `package.json`/lockfile. No prior report
  or prompt.

---

## 11. Test files and total test count

Phase 18 added **9 test files / 80 tests** and extended one existing test file
with 2 tests.

| Test file | Tests |
| --------- | ----- |
| `lib/currencies/api.test.ts` | 8 |
| `lib/currencies/validators.test.ts` | 17 |
| `lib/exchange-rates/api.test.ts` | 7 |
| `lib/exchange-rates/validators.test.ts` | 20 |
| `lib/fx-settings/api.test.ts` | 4 |
| `lib/fx-settings/validators.test.ts` | 7 |
| `components/currencies/currencies-view.test.tsx` | 6 |
| `components/exchange-rates/exchange-rates-view.test.tsx` | 6 |
| `components/fx-settings/fx-settings-view.test.tsx` | 5 |
| `lib/navigation/nav.test.ts` (extended) | +2 (now 14) |

What the tests cover:
- **API clients** route through the same-origin proxy, map filters to the verified
  query strings (including omitting a false `active_only`), send the exact POST/PUT
  bodies (and, for rates, a PUT body that never carries the pair), pass abort
  signals, and use bodyless POST sub-resources for activate/deactivate. FX settings
  send both account ids together and clear by sending both null.
- **Validators** filter invalid rows rather than fabricating them, keep the rate as
  a string, reject a non-positive or malformed rate (while accepting a small
  fractional rate such as `0.000062`), reject malformed dates, enforce the
  three-letter currency code and precision range, and enforce the FX pair-together
  rule.
- **Component views** render rows with the correct detail links, show the
  empty/company/permission/error states, and reveal write controls only under the
  right permission.

**Full suite:** 92 files / 880 tests, all passing (was 83 files / 798 tests after
Phase 17).

---

## 12. Exact lint, ESLint, TypeScript, test, and build results

Run from `accounting-app/frontend`:

| Gate | Command | Result |
| ---- | ------- | ------ |
| ESLint | `npm run lint` | **0 problems** (0 errors, 0 warnings) |
| TypeScript | `npx tsc --noEmit` | **0 errors** |
| Tests | `npm test` | **92 files / 880 tests passed** |
| Build | `npm run build` | **Succeeded** |

Routes emitted by the build (excerpt):

```
├ ƒ /currencies
├   /currencies/[id]
│ └ ◐ /currencies/[id]
├ ƒ /currency-settings
├ ƒ /exchange-rates
├   /exchange-rates/[id]
│ └ ◐ /exchange-rates/[id]
```

No ESLint rule was disabled and no TypeScript check was weakened to pass a gate.

---

## 13. Live integration status

**Not performed.** No running backend or database was exercised; every claim above
is marked **[source]** (read from the backend) or **[tests]**/**[build]** (frontend
automation). Live integration ([live]) is **not** claimed for any part of Phase 18.

---

## 14. Missing backend contracts, scope boundaries, and blocked functionality

Honest accounting of what the backend does and does not expose, so nothing here is
misrepresented as built.

1. **Base currency has no read endpoint (gap).** Changing the company's functional
   currency is supported (`PUT /company/settings/base-currency`, permission
   `companies.settings.update`), but **no GET returns the company's base currency**.
   `CompanySettingResource.default_currency_id` is a *different* field, and no
   company-settings frontend workflow exists (nav marks "Company settings"
   upcoming). A blind read-back or a fabricated current value would be misleading,
   so **no base-currency UI was built**. This is the correct behaviour under the
   brief ("do not assume a feature exists because the prompt lists it"). [source]

2. **No currency-conversion endpoint (gap).** There is no route that converts an
   amount between currencies; the exchange-rate surface records rates only. No
   conversion calculator was built. [source]

3. **No FX revaluation endpoint (gap).** There is no route that revalues open
   foreign-currency balances and posts the difference. The FX settings screen
   configures where such gains/losses *would* post, but revaluation itself is not a
   callable operation. No revaluation UI was built. [source]

4. **Sections E and G were already implemented (scope boundary).** The brief's
   transaction modules (journals carrying `currency_code`/`exchange_rate`/
   `foreign_debit`/`foreign_credit`, ledger movements) and currency-aware reporting
   (`base_currency` blocks on trial balance, balance sheet, P&L, customer/supplier
   statements, receivables/payables/aging; dashboard currency parsing) were built in
   Phases 13/14/17. Phase 18 neither duplicates nor modifies them. [source]

5. **`/accounting/controls` was not built (recommended, out of scope).** The
   read-only currency/FX integrity report (`accounting.controls.view`) exposes the
   base-currency code and FX configuration gaps. It is a *diagnostic* surface, not
   part of the Phase 18 build set, and is left as a recommended future addition. It
   is the one place a base-currency *code* is currently readable. [source]

6. **No `priced` flag on a rate (limitation).** The service refuses an edit to a
   rate that has priced a document, but the resource does not say whether a rate
   has. The UI cannot pre-disable the edit; it relies on, and surfaces, the
   backend's 422. This is honest rather than guessed. [source]

---

## 15. Known limitations and risks

- **Unverified against a live server.** All behaviour is derived from reading the
  backend and from frontend unit/component tests. Field-level response drift would
  be caught by the validators (rows are dropped/rejected rather than mis-rendered),
  but end-to-end wiring is unconfirmed. [live absent]
- **Decimal strings are never coerced.** Rates and precision are displayed
  verbatim; the client performs no arithmetic on a rate, by design, so a malformed
  decimal from the backend is shown as-is rather than silently rounded.
- **Self-pair and duplicate-date rules are not pre-checked client-side.** They are
  enforced by the service and surfaced verbatim; the client validators only catch
  obviously-invalid input (missing/non-positive rate, malformed date). This keeps a
  single authority for the rule rather than a second, drifting copy.
- **Currency writes are Admin-only by the role matrix.** On an Accountant or
  Manager session the currency surface is effectively read-only; this is the
  backend's intent, not a frontend omission.

---

## 16. Final Git status

At the end of Phase 18 (Phase 17's uncommitted work is still present from the
previous task; the Phase 18 additions are the `currencies`, `exchange-rates`,
`fx-settings`, `currency-settings` and `use-account-type-options` entries plus the
two modified nav files):

```
## main...origin/main
 M accounting-app/frontend/src/lib/fixed-assets/validators.ts   (Phase 17)
 M accounting-app/frontend/src/lib/navigation/nav.test.ts        (+ Multi-currency tests)
 M accounting-app/frontend/src/lib/navigation/nav.ts             (+ Multi-currency section)
?? accounting-app/docs/FRONTEND_PHASE_17_REPORT.md              (Phase 17)
?? accounting-app/docs/FRONTEND_PHASE_18_REPORT.md              (this report)
?? accounting-app/frontend/src/app/(app)/currencies/
?? accounting-app/frontend/src/app/(app)/currency-settings/
?? accounting-app/frontend/src/app/(app)/exchange-rates/
?? accounting-app/frontend/src/app/(app)/fixed-assets/          (Phase 17)
?? accounting-app/frontend/src/components/currencies/
?? accounting-app/frontend/src/components/exchange-rates/
?? accounting-app/frontend/src/components/fixed-asset-categories/ (Phase 17)
?? accounting-app/frontend/src/components/fixed-assets/         (Phase 17)
?? accounting-app/frontend/src/components/fx-settings/
?? accounting-app/frontend/src/lib/accounts/use-account-type-options.ts
?? accounting-app/frontend/src/lib/currencies/
?? accounting-app/frontend/src/lib/exchange-rates/
?? accounting-app/frontend/src/lib/fixed-asset-categories/      (Phase 17)
?? accounting-app/frontend/src/lib/fixed-assets/…               (Phase 17)
?? accounting-app/frontend/src/lib/fx-settings/
?? "prompt/frontend Prompt/Phase 1"                             (pre-existing)
?? "prompt/frontend Prompt/Phase 18.md"                         (the brief)
```

No commit, no push, no other prohibited Git operation was performed.

---

## 17. Confirmation of preservation

- The backend and its migrations/seeders are untouched.
- Completed phases (accounts, journals, taxes, sales/purchases, banking, reports,
  fixed assets, and the Phase 13/14 currency presentation) are unaltered. The only
  shared files changed are `nav.ts`/`nav.test.ts` (additive) and the new additive
  `use-account-type-options.ts`.
- No dependency, lockfile, environment file, or prior report was modified.
- Every quality gate passes, and no gate was weakened to make it pass.
- No claim of live verification is made.

Phase 18 is delivered as a smaller, accurate implementation of exactly what the
existing backend supports, with every unsupported capability named as a gap rather
than faked.
