# Phase 18 — Multi-Currency & Foreign Exchange (FX)

## 1. Role and Objective

You are working on **Accountance**, an international-ready, multi-company
accounting web application.

Your task is to implement **Frontend Phase 18: Multi-Currency & Foreign Exchange
(FX)** in the existing Next.js frontend, integrating with the existing Laravel
API.

This is a **frontend-only phase**. The backend has already been implemented in
earlier phases. Inspect its real routes, controllers, requests, resources,
policies, services, and tests to determine the exact supported functionality.

Do not assume that a feature exists merely because it is listed in this prompt.
Implement only functionality supported by the existing backend API.

The objectives are to deliver a consistent, secure, accessible, tested currency
and FX experience without changing established backend contracts or disturbing
completed frontend phases.

## 2. Mandatory Rules

### 2.1 Preserve Existing Work

Before making changes:

1. Inspect the repository root, Git branch, HEAD, and complete working-tree
   status.
2. Read the master documentation and relevant frontend/backend blueprints.
3. Read `docs/FRONTEND_PHASE_12_REPORT.md` through
   `docs/FRONTEND_PHASE_17_REPORT.md` where present, plus relevant Phase 18
   planning documents.
4. Inspect the Phase 17 fixed-assets implementation and existing
   currency-related backend/frontend work.
5. Identify modified, staged, untracked, ignored, and pre-existing files that
   must be preserved.
6. Review the existing API proxy, authentication/session handling, company
   context, permissions, navigation, formatting utilities, tests, and
   established coding conventions.

**Do not discard, overwrite, reset, or clean up existing work.** Do not assume
that an untracked file is disposable.

Strictly prohibited unless separately and explicitly authorized by the user:

- `git reset`, `git clean`, `git stash`, destructive checkout/restore
  operations, or equivalent destructive commands.
- Pulling, fetching, switching branches, rebasing, or other Git operations that
  could disturb the current repository state.
- Committing or pushing changes.
- Modifying backend source code, routes, migrations, seeders, database schemas,
  or backend tests.
- Running migrations, seeders, or commands that mutate a database.
- Adding, removing, or upgrading dependencies, package manifests, or lockfiles.
- Rewriting completed frontend phases or their reports.

If a requirement cannot be implemented using the current backend contract,
document the limitation rather than changing the backend or inventing a
workaround.

### 2.2 Inspect Before Implementing

Use the actual source code as the authority.

Inspect, as applicable:

- Currency models and migrations.
- Currency routes and controllers.
- Currency validation requests and API resources.
- Exchange-rate models, routes, services, and validation.
- Company settings and base-currency behavior.
- Foreign-exchange conversion endpoints.
- FX gain/loss or revaluation endpoints, if present.
- Currency-related reporting endpoints.
- Existing sales invoices, purchase bills, customer receipts, supplier payments,
  credit/debit notes, fixed assets, cash/bank transactions, and financial
  reports where they expose currency information.
- Policies and permission definitions.
- Tests that establish API response shapes, decimal precision, exchange-rate
  direction, effective-date behavior, and error handling.

Record the verified contract before building UI.

Do not invent endpoints, query parameters, payload properties, permissions,
response fields, or supported workflows. Do not silently infer that an endpoint
supports a mutation simply because a related read endpoint exists.

## 3. Existing Architecture — Must Be Preserved

The existing application uses:

- Laravel REST API backend.
- Next.js App Router frontend.
- React and strict TypeScript.
- Tailwind CSS v4.
- MySQL backend.
- A same-origin Next.js Backend-for-Frontend (BFF) proxy.
- JWT authentication held server-side in an encrypted HttpOnly cookie.
- Server-generated authorization headers and server-side company context.
- Existing hand-written API wrappers, runtime validators, hooks, and
  `AbortController` patterns.
- Existing reusable UI components and formatting utilities.
- Existing Vitest tests and project scripts.

Follow the repository's actual current conventions. Do not introduce a second
API client architecture or a new data-fetching library.

### Security requirements

- All browser-originated backend requests must use the existing same-origin BFF
  proxy.
- Never expose JWTs, encryption keys, or other server secrets to client-side
  code.
- Never let the client choose or override the trusted company context.
- Never call Laravel directly from browser components.
- Respect the existing route allowlist and proxy security controls.
- Do not weaken authentication, authorization, request validation, or
  response-header filtering.
- Use `cache: "no-store"` for sensitive accounting data where required by
  existing conventions.
- Do not log access tokens, secrets, or sensitive financial payloads.
- Treat backend authorization as authoritative; frontend permission checks only
  control presentation and interaction.

## 4. Product and Accounting Requirements

Accountance is intended to support international double-entry accounting.

Currency and FX features must preserve these principles:

- Company isolation.
- Correct currency identification and display.
- Explicit exchange-rate direction.
- Effective-date-aware rate selection where supported.
- Exact decimal handling for monetary values and rates.
- No binary floating-point arithmetic for financial calculations.
- No client-side replacement of authoritative backend accounting calculations.
- Historical transactions and reports must not silently change because a
  currency rate was later updated.
- Existing posted journal immutability must remain respected.
- Clear differentiation between a transaction currency, company/base currency,
  and any reporting currency when the backend exposes these concepts.
- Explicit treatment of missing rates and unsupported conversions.
- Clear presentation of backend validation and authorization errors.

Do not implement a new accounting or FX calculation engine in the frontend.

## 5. Phase Scope

Implement the features below **only when the existing backend supports them**.

### A. Currency Management

Inspect and implement the available currency-management capabilities, which may
include:

- Currency list and detail.
- Search, filters, pagination, or active/inactive filtering only if supported by
  the API.
- Currency code, name, symbol, decimal precision, and status where those fields
  exist.
- Create and edit forms only if supported.
- Activation/deactivation only if supported.
- Read-only currency reference data if the API does not permit maintenance.

Requirements:

1. Use the backend's canonical currency code and identifier.
2. Do not assume all currencies have two decimal places.
3. Display currency symbols and codes consistently.
4. Do not invent a currency list or silently hard-code unsupported currency
   definitions.
5. Do not allow unsupported currency mutations.
6. Handle duplicate currency codes, invalid precision, in-use currencies, and
   other backend validation errors gracefully.

### B. Company Base Currency and Currency Settings

Inspect the existing company-settings contract to determine whether base
currency can be viewed or changed.

If supported:

- Display the company's configured base currency clearly.
- Use the existing company settings workflow and permissions.
- Require explicit confirmation before a consequential base-currency change if
  the backend permits one.
- Explain any backend-returned restrictions or validation errors.
- Preserve the existing company-switching behavior and server-side company
  context.

If base currency is immutable after company creation or no supported update
endpoint exists, present the value read-only. Do not simulate a successful
update or introduce a new endpoint.

Do not assume that changing the base currency automatically converts historical
balances or posted transactions.

### C. Exchange-Rate Management

Inspect the exchange-rate API and implement its actual supported lifecycle.

Potential UI capabilities, only where verified:

- Rate list and detail.
- Filtering by source currency, target currency, and effective date.
- Create and edit rates.
- Activation, deactivation, or deletion where explicitly supported.
- Effective-from/effective-to dates where present.
- Rate history or latest-rate views where available.

The interface must make rate direction unambiguous. For example, if the API
defines a rate as target-currency units per one source-currency unit, display
that exact convention in the UI. Do not assume this convention without checking
the backend.

Requirements:

1. Display source currency, target currency, rate, effective date, and status
   only when present in the API contract.
2. Preserve rates as decimal strings throughout the API, validation, state, and
   submission layers.
3. Never pass exchange rates through JavaScript `Number` for financial
   calculations.
4. Follow the backend's precision and validation rules.
5. Do not create client-side overlap rules, date rules, or uniqueness rules that
   contradict the backend.
6. If the API does not expose rate history, do not fabricate it.
7. Handle missing, expired, duplicate, or invalid rates using backend responses.
8. Make it clear when a rate is manually entered, if that distinction exists in
   the data model.
9. Do not invent a third-party market-rate feed or automatic rate-update
   integration.

### D. Currency Conversion Tool

If a supported conversion endpoint exists, create a small conversion interface
using the real endpoint.

Possible inputs, subject to the verified API contract:

- Source currency.
- Target currency.
- Amount.
- Effective date or conversion date.
- Rate selection, if supported.

Show the result and any returned rate/provenance details that the backend
actually supplies.

Requirements:

- Use decimal strings for amounts and rates.
- Use exact decimal arithmetic only for non-authoritative previews where
  appropriate.
- Prefer the backend's conversion result for the authoritative displayed result.
- Clearly distinguish an indicative preview from an accounting-posting value.
- Do not invent an endpoint if conversion is only performed internally by other
  backend services.
- Do not silently fall back to a fabricated rate when no applicable rate exists.
- Validate same-currency conversion according to the real API contract.
- Display useful validation errors for unsupported currency pairs, missing
  rates, invalid dates, and invalid amounts.

If no conversion endpoint exists, implement only supported rate
lookup/management features and document the missing capability.

### E. Existing Transaction Modules

Inspect the existing frontend and backend contracts for:

- Sales invoices.
- Purchase bills.
- Customer receipts.
- Supplier payments.
- Credit notes and debit notes.
- Cash/bank transactions.
- Fixed assets and related depreciation/disposal flows.
- Journal entries and journal lines.

Determine exactly which modules expose transaction currency, base-currency
amount, exchange rate, or FX difference fields.

Only add currency-related fields or presentation where the API contract supports
them and where they belong in the current phase.

Rules:

- Do not redesign existing transaction forms.
- Do not change posting behavior.
- Do not make posted transactions editable.
- Do not alter Phase 14–17 workflows unrelated to currency.
- Do not send guessed currency fields in create/update requests.
- Do not imply that a module supports multi-currency merely because its amount
  fields are decimal strings.
- Do not retrofit missing backend capabilities through frontend-only
  assumptions.

If a module lacks sufficient API support, document the limitation and leave it
unchanged.

### F. FX Gain/Loss and Revaluation

First establish whether the backend supports any of the following:

- Realized FX gain/loss.
- Unrealized FX gain/loss.
- Foreign-currency monetary balance revaluation.
- Revaluation preview or calculation.
- Revaluation posting.
- FX adjustment reports.

If supported, implement only the existing workflow, respecting permissions,
validation, accounting-period controls, and journal immutability.

If unsupported:

- Do not invent a revaluation workflow.
- Do not generate FX journals in the browser.
- Do not calculate authoritative accounting gain/loss values client-side.
- Do not add fake buttons or disabled UI that implies the feature exists.
- Record the gap and the exact backend contract that would be required in a
  future backend phase.

### G. Currency-Aware Reporting

Inspect the existing reporting endpoints and response contracts.

Where supported, ensure reports clearly distinguish:

- Transaction/original currency amounts.
- Base-currency amounts.
- Exchange rates.
- Reporting currency.
- FX gain/loss amounts.

Only show values actually returned by the backend.

Do not silently convert historical report values using the latest rate. Do not
calculate an accounting balance from mixed currencies unless the backend
explicitly provides the correct normalized value.

Review existing reporting pages for any verified currency fields that should be
presented more clearly. Keep changes minimal and focused.

Potentially relevant reports include:

- Trial Balance.
- General Ledger.
- Profit & Loss.
- Balance Sheet.
- Cash & Bank.
- Customer Statement.
- Receivables and Receivables Aging.
- Supplier Statement.
- Payables and Payables Aging.
- Fixed Asset reports.
- Budget reports.

Do not modify unrelated reports merely to increase the scope of the phase.

## 6. UX and Visual Design

Preserve the existing Accountance visual system:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, readable layouts.
- Green as the primary brand accent, not an excessive background color.
- Semantic colors reserved for status, warnings, errors, and informational
  states.
- Responsive desktop and mobile layouts.
- Accessible form labels, keyboard navigation, focus indicators, and dialog
  behavior.
- Consistent spacing, typography, tables, filters, page headers, loading states,
  empty states, and error states.

Use existing components and tokens. Do not create a parallel design system.

Currency tables and forms must remain readable when currency codes, names, or
numeric rates are long. Avoid overly wide layouts on smaller screens.

Display monetary values using existing formatting utilities wherever
appropriate. Inspect the utilities before adding any new formatter.

## 7. API, Types, and Validation

For each supported endpoint:

1. Create or update strongly typed API contracts.
2. Add runtime response validation consistent with existing project patterns.
3. Add API wrappers that use the existing BFF path.
4. Add hooks using existing cancellation and loading/error patterns.
5. Handle the actual response envelope and pagination format.
6. Handle validation errors using existing error utilities.
7. Respect the existing permission model.
8. Use decimal strings for financial values and rates.

Do not use `any` to bypass type errors. Do not introduce unsafe casts without a
documented, justified reason.

Do not assume Laravel paginated responses always contain `links` or `meta`.
Inspect the real response shape and follow the existing pagination conventions.

Do not create fallback objects that make missing required API fields look valid.

## 8. Navigation and Permissions

Inspect the current navigation and permission matrix before adding navigation
items.

- Add only routes backed by real functionality.
- Use existing permission identifiers exactly as defined by the backend.
- Hide actions when the current user lacks the relevant permission.
- Keep route protection and backend authorization intact.
- Avoid duplicating navigation entries or changing existing route ordering
  unnecessarily.
- If the backend exposes read-only currency data to a broader role, follow the
  actual authorization policy.

Do not invent permission identifiers.

## 9. Suggested Route Structure

These are organizational suggestions, **not required routes**. Use the existing
conventions and create only routes justified by actual API capabilities:

- `/currencies`
- `/exchange-rates`
- `/currency-converter`
- `/fx-revaluation`
- Relevant currency-aware report enhancements

If a feature is unsupported, do not create a placeholder route for it.

Before choosing final routes, inspect the existing App Router structure to avoid
conflicts or duplication.

## 10. Testing Requirements

Add focused tests for every meaningful feature implemented.

### API and validation tests

Test:

- Valid response parsing.
- Malformed or missing required response fields.
- API envelope handling.
- Empty results.
- Actual pagination behavior where applicable.
- API error and validation-error handling.
- Missing permissions.
- Failed requests and cancellation behavior where testable.

### Currency-management tests

Where supported, test:

- Currency list and filters.
- Create/edit validation.
- Active/inactive state transitions.
- Duplicate or invalid currency handling.
- Read-only behavior when mutations are unsupported.

### Exchange-rate tests

Where supported, test:

- Explicit source/target rate direction.
- Rate decimal precision.
- Effective-date behavior.
- Missing applicable rate.
- Invalid or duplicate rates.
- Rate creation/editing and lifecycle restrictions.

### Conversion tests

Where supported, test:

- Valid conversion requests.
- Returned rate and amount display.
- Missing-rate errors.
- Invalid amounts and dates.
- Same-currency behavior.
- Decimal precision without unsafe floating-point arithmetic.

### Security and company isolation

Test the frontend's behavior around:

- Same-origin BFF paths.
- Permission-gated actions.
- No client-supplied trusted company context.
- No JWT or secret exposure.
- No unauthorized backend route assumptions.

Do not claim to have tested backend company isolation through the frontend
unless an actual integration test verifies it.

### Regression tests

- Run the full existing test suite.
- Ensure Phase 13 reporting, Phase 14 sales/receivables, Phase 15
  purchases/payables, Phase 16 credit/debit notes, and Phase 17 fixed-assets
  tests remain green.
- Do not weaken or delete existing tests to make the suite pass.

## 11. Quality Gates

Run from the actual frontend project directory, using the scripts already
present:

1. `npm run lint`
2. `npx eslint . --max-warnings=0`
3. `npx tsc --noEmit`
4. `npx vitest run`
5. `npm run build`

If a command does not exist, inspect `package.json` and report the available
equivalent rather than changing package scripts unnecessarily.

Fix issues introduced by this phase. Do not broadly refactor unrelated code to
eliminate pre-existing issues without authorization.

Report the actual exit status and relevant test counts for each command. Never
claim a check passed unless it actually ran successfully.

## 12. Live Integration

Do not run migrations, seeders, or database-changing operations.

Do not assume a live backend is available. If a safe, already-configured
development environment exists, use it only for non-destructive read-only
verification that does not expose secrets or modify data.

Do not create financial transactions or post journals against a live or shared
environment without explicit authorization.

Clearly distinguish:

- Automated tests.
- Static/type/build validation.
- Read-only live API verification.
- Browser end-to-end testing.

If live integration is not performed, state so explicitly.

## 13. Required Documentation

Create:

`accounting-app/docs/FRONTEND_PHASE_18_REPORT.md`

Follow the format and level of detail of the previous frontend phase reports.

The report must include:

1. Phase summary and completion status.
2. Initial Git branch, HEAD, and working-tree status.
3. Existing user changes identified before implementation.
4. Verified backend endpoints, payloads, response shapes, permissions, and
   relevant source files.
5. Implemented routes, components, API wrappers, validators, hooks, navigation,
   and tests.
6. Features intentionally not implemented because the backend does not support
   them.
7. Any API contract ambiguities or backend gaps discovered.
8. Decimal precision and exchange-rate-direction decisions.
9. Security and company-context considerations.
10. Quality-gate results with real command outcomes and test counts.
11. Live integration status and exact scope of any verification.
12. Known limitations and recommended future work.
13. Final Git status, including modified and untracked files.
14. Confirmation that no prohibited backend, dependency, database, or Git
    operations were performed.

Do not overwrite or rewrite earlier phase reports.

## 14. Completion Criteria

Phase 18 is complete only when:

- The existing repository and user work have been preserved.
- The actual backend contract has been inspected and documented.
- Every implemented feature is backed by a verified API capability.
- Currency and exchange-rate precision are handled safely.
- Rate direction and effective-date semantics are explicit.
- Company context and permissions follow the existing security model.
- Unsupported features are clearly documented rather than simulated.
- Focused tests and regression tests pass.
- Lint, strict ESLint, TypeScript, Vitest, and build gates have been run and
  accurately reported.
- `docs/FRONTEND_PHASE_18_REPORT.md` is complete.
- No backend changes, dependency changes, migrations, seeders, destructive Git
  operations, commits, or pushes have occurred.

## 15. Final Instructions

Work methodically. Inspect first, implement only verified capabilities, test
thoroughly, and document the result.

Do not rush to create every suggested route. A smaller, accurate implementation
is preferable to a broad implementation based on guessed API contracts.

**Stop after Phase 18. Do not begin Phase 19. Do not commit or push.**

Return a concise summary of completed work, the verified test/build results, any
unsupported backend capabilities, and the report path.
