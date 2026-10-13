# Accountance Frontend — Phase 15: Purchases & Payables

**Project:** Accountance Accounting Web App  
**Phase:** 15  
**Scope:** Frontend only  
**Primary Modules:** Suppliers, Purchase Bills, Supplier Payments, Supplier
Statements, Accounts Payable & Aging  
**Backend Changes:** Strictly prohibited  
**Git Commit/Push:** Prohibited unless explicitly authorized separately  
**Phase Report:** `accounting-app/docs/FRONTEND_PHASE_15_REPORT.md`

---

## 1. Objective

Implement the Purchases & Payables frontend using the existing Laravel
accounting API and the established Next.js frontend architecture.

Deliver the following modules:

1. Supplier management.
2. Purchase bill management.
3. Supplier payments and payment allocations.
4. Supplier statements.
5. Accounts payable reports.
6. Accounts payable aging reports.

Follow the existing Sales & Receivables implementation where the underlying
workflows are genuinely equivalent. Do not blindly copy customer and sales
invoice code: inspect the purchase-side backend contracts and account for
supplier-specific lifecycle, tax, allocation, and accounting behavior.

**This phase is frontend only. Do not modify backend code and do not proceed to
Phase 16.**

## 2. Mandatory Repository Preservation

Phase 14 reports that substantial Sales & Receivables work remains uncommitted.
Treat every existing modification and untracked file as user-owned work.

### 2.1 Inspect before editing

At the repository root, record:

- Current branch and HEAD.
- `git status --short --branch`.
- `git diff --stat`.
- Existing tracked modifications and untracked files.
- Phase 14 report and prompt.
- Existing customer, invoice, receipt, and report modules.
- Existing supplier, purchase bill, supplier payment, and payables backend
  contracts.

Do not assume the current working tree still exactly matches the Phase 14
report.

### 2.2 Prohibited Git operations

Do not run:

- `git pull`
- `git fetch`
- `git reset`
- `git stash`
- `git clean`
- `git checkout`
- `git switch`
- `git restore` to discard changes
- `git commit`
- `git push`

Do not change branches, discard work, delete unrelated files, overwrite previous
reports, or perform automatic repository cleanup.

If the working tree contains unexpected changes, preserve them. If safe
implementation is impossible, stop and explain the exact blocker.

### 2.3 File boundaries

- Do not modify anything under `accounting-app/backend/`.
- Do not modify backend routes, controllers, services, models, policies,
  permissions, migrations, or tests.
- Do not run migrations or seeders.
- Do not install, remove, or upgrade dependencies.
- Do not modify package manifests or lockfiles.
- Do not delete existing tests.
- Do not overwrite previous phase reports.
- Do not refactor unrelated features.
- Preserve all existing uncommitted and untracked work.

Frontend source, frontend tests, navigation, and the Phase 15 report may be
added or modified only as necessary for this phase.

Create:

`accounting-app/docs/FRONTEND_PHASE_15_REPORT.md`

If that file already exists, inspect it and preserve its content. Use an
alternative filename if necessary rather than overwriting existing work.

---

## 3. Inspect and Verify Backend Contracts

Do not assume that supplier workflows mirror customer workflows or that
endpoints, permissions, and request payloads have matching names.

Inspect the actual source for:

- `backend/routes/api.php`
- Related route registration files.
- Supplier controllers, request validators, services, resources, models,
  relationships, and policies.
- Purchase bill controllers, request validators, services, resources, models,
  relationships, and policies.
- Supplier payment and allocation controllers, services, requests, models, and
  policies.
- Supplier statement, payables, and aging report implementations.
- Accounting posting, tax, currency, and payment-allocation rules.
- Relevant backend tests and API handoff documentation.

Use the actual repository paths.

Before implementation, create an endpoint/contract inventory covering:

| Area                | Contract information to verify                                                                        |
| ------------------- | ----------------------------------------------------------------------------------------------------- |
| Suppliers           | List, search, pagination, detail, create, update, activation/deactivation, deletion if supported      |
| Purchase bills      | List, detail, creation, draft update, permitted deletion, posting, cancellation/reversal if supported |
| Bill lines          | Supported fields, account/item selection, quantities, unit costs, discounts, tax, descriptions        |
| Supplier payments   | List, detail, creation, draft update, deletion, posting, cancellation/reversal if supported           |
| Payment allocations | One-to-one or multiple allocations, partial payment rules, allocation updates, allocation endpoints   |
| Supplier statement  | Endpoint, parameters, opening balance, movements, running balance, closing balance                    |
| Accounts payable    | Supported filters, row granularity, totals, balances, as-of date                                      |
| Payables aging      | Bucket configuration, labels, keys, as-of semantics, row granularity, totals                          |
| Permissions         | Exact view/create/update/post/delete/report permission keys                                           |
| Pagination          | Actual parameters and response metadata                                                               |
| Money and dates     | Decimal precision, currency fields, exchange-rate behavior, date formats                              |
| Lifecycle           | Actual draft, posted, partially paid, paid, overdue, cancelled, or other statuses                     |

For each implemented endpoint, record:

- HTTP method and path.
- Query parameters.
- Request payload.
- Response payload.
- Permission.
- Lifecycle constraints.
- Company-scoping behavior.
- Validation and error response format.

Do not invent endpoints, payload fields, permissions, status values, or response
metadata.

If a required operation is not supported by the backend, do not implement a fake
operation or change the backend. Document the limitation and mark the affected
criterion as blocked.

---

## 4. Preserve the Existing Frontend Architecture

Use the existing:

- Next.js App Router.
- React and strict TypeScript.
- Tailwind CSS v4.
- Same-origin Next.js BFF.
- Server-held JWT in an encrypted HttpOnly cookie.
- Server-generated `Authorization` and `X-Company-Id` headers.
- API helpers, hooks, validators, formatters, permission utilities, and UI
  primitives.
- Vitest and React Testing Library setup.
- Light/Dark theme and navigation conventions.

Inspect the Phase 14 customer, sales invoice, receipt, and reporting
implementations before creating equivalent purchase-side modules.

Reuse existing helpers where appropriate, but avoid creating unsafe shared
abstractions or changing working Phase 14 behavior merely to reduce duplication.

### Security requirements

- Never expose the JWT to browser JavaScript.
- Never call the Laravel API directly from browser components.
- Route all requests through the existing BFF.
- Never trust a browser-supplied company ID for authorization.
- Do not bypass the existing proxy allow-list.
- Preserve server-side authorization and company isolation.
- Prevent stale data from one company appearing after a company switch.
- Do not expose internal exceptions or sensitive backend details.
- Do not use `dangerouslySetInnerHTML` to display supplier or document text.

---

## 5. UI and UX Requirements

Follow the existing Accountance design system:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, clean layouts.
- Responsive desktop, tablet, and mobile behavior.
- Consistent tables, forms, dialogs, badges, spacing, and typography.
- Semantic colors for statuses, warnings, and errors.
- Keyboard-accessible interactions.
- Accessible form labels and validation messages.

Every page must provide appropriate:

- Loading state.
- Empty state.
- Error state.
- Permission-denied state.
- Server validation feedback.
- Pending-operation state.
- Success feedback after server confirmation.
- Confirmation for destructive or irreversible actions.

Do not introduce a new UI framework or component library.

---

## 6. Supplier Management

Suggested route family:

- `/suppliers`
- `/suppliers/new`
- `/suppliers/[id]`

Adapt the structure to the existing route conventions and verified supplier API.

### 6.1 Supplier list

Implement supported capabilities such as:

- Search by supplier code or name.
- Pagination.
- Status filters.
- Contact information.
- Tax registration details.
- Payables balance, only if supplied by a supported endpoint.
- Active/inactive status.
- Row actions permitted by the backend.

Do not invent record counts or pagination metadata.

### 6.2 Create and edit

Inspect the actual supplier request validators.

Fields may include supplier code, supplier name, contact person, email, phone,
addresses, tax identification, payment terms, currency, or payable account, but
use only fields the backend accepts.

Requirements:

- Validate required and optional fields according to the real contract.
- Respect nullable fields.
- Keep server-owned fields out of write payloads.
- Display field-specific backend errors.
- Prevent duplicate submissions.
- Refresh the relevant data after successful writes.
- Do not fabricate opening balances or supplier balances.

### 6.3 Supplier detail

Display the verified supplier information and, where supported, provide
navigation to:

- Purchase bills.
- Supplier payments.
- Supplier statement.
- Accounts payable and aging.

Do not construct a transaction history by guessing from incomplete data.

### 6.4 Lifecycle and deletion

Only expose activation, deactivation, or deletion when the backend supports it
and the user has the relevant permission.

If a supplier cannot be deleted because financial records reference it, display
the safe server-provided explanation and preserve the record.

---

## 7. Purchase Bill Management

Suggested route family:

- `/purchase-bills`
- `/purchase-bills/new`
- `/purchase-bills/[id]`
- `/purchase-bills/[id]/edit`

Verify route naming against existing conventions.

### 7.1 Purchase bill list

Support only backend-supported filters, potentially including:

- Search.
- Supplier.
- Bill status.
- Supplier invoice/reference number.
- Bill date range.
- Due date range.
- Pagination.

Display available fields such as:

- Internal bill number.
- Supplier.
- Supplier reference.
- Bill date.
- Due date.
- Currency.
- Total.
- Amount paid.
- Outstanding balance.
- Status.

Do not compute authoritative outstanding balances or payment status from
incomplete client data.

### 7.2 Bill creation and editing

Inspect the actual purchase bill request, line model, tax handling, and
accounting service.

Build a form using only verified fields. Potential fields include:

- Supplier.
- Supplier invoice/reference number.
- Bill date.
- Due date.
- Description or notes.
- Currency and exchange rate, if supported.
- Bill lines.
- Account or item.
- Quantity and unit cost.
- Discounts.
- Tax selection.
- Line descriptions.

Do not assume that purchase bills use the same line schema as sales invoices.

Requirements:

- Support adding/removing lines only where permitted.
- Validate required account/item identifiers.
- Preserve decimal precision.
- Respect backend discount, tax, and rounding semantics.
- Do not submit unsupported fields.
- Preserve user-entered data when a server validation error occurs.
- Use the existing tax module where applicable.
- Do not use client-computed amounts as the authoritative accounting total.

### 7.3 Tax behavior

Inspect whether purchase bills support:

- Input tax.
- Recoverable versus non-recoverable tax.
- Tax-inclusive or tax-exclusive pricing.
- Tax snapshots on document lines.
- Effective-dated tax rates.
- Tax account mapping.
- Tax adjustments during posting.

Implement only the behavior supported by the backend.

Do not assume the sales invoice tax model is identical to the purchase bill tax
model.

### 7.4 Bill detail

Render the server-returned bill information and line items.

Where supplied, display:

- Bill number and lifecycle status.
- Supplier and supplier reference.
- Dates.
- Currency.
- Line descriptions.
- Quantity and unit cost.
- Discounts and tax.
- Subtotal and total.
- Amount paid and outstanding balance.
- Linked supplier payments or allocations.
- Posting/journal reference.

Do not invent posting references or present a draft as posted.

### 7.5 Bill lifecycle

Expose only backend-supported actions such as:

- Edit draft.
- Delete draft.
- Post/finalize bill.
- Cancel or reverse, if supported.

Follow exact backend status transitions and permissions.

Posted financial documents must not appear freely editable if the backend
enforces immutability.

Before an irreversible operation:

1. Explain the action.
2. Require confirmation.
3. Prevent duplicate requests.
4. Wait for server confirmation.
5. Refresh the detail and related lists.

Never simulate a posting or cancellation solely by changing local frontend
state.

---

## 8. Supplier Payments and Payment Allocations

Suggested route family:

- `/supplier-payments`
- `/supplier-payments/new`
- `/supplier-payments/[id]`
- `/supplier-payments/[id]/edit`

Inspect the actual supplier payment implementation before designing the editor.

### 8.1 Payment list

Render available fields such as:

- Payment number.
- Payment date.
- Supplier.
- Payment method.
- Reference.
- Cash/bank account.
- Currency.
- Amount.
- Allocation status.
- Lifecycle status.

Use only real response fields and supported filters.

### 8.2 Payment creation

Potential fields include supplier, payment date, payment method, bank/cash
account, reference, currency, exchange rate, amount, and notes. Include only
fields supported by the verified contract.

Use the existing cash/bank APIs and selectors where required, while respecting
permissions.

Do not invent payment methods or account types.

### 8.3 Allocation workflow

Partial supplier payments are a core requirement where supported by the backend.

Inspect whether a payment can be:

- Allocated to one bill.
- Allocated across multiple bills.
- Partially allocated.
- Created before allocation.
- Edited or reallocated after creation.
- Posted before or after allocation.

Implement only the supported workflow.

If allocation is supported, display the actual supplied values, which may
include:

- Supplier bill number.
- Supplier reference.
- Bill date and due date.
- Original bill total.
- Previously paid amount.
- Outstanding amount.
- Amount allocated.
- Remaining unallocated payment amount.

Validation requirements:

- Follow backend rules for allocation totals.
- Prevent negative allocations unless explicitly supported.
- Respect bill outstanding balances.
- Respect payment amount limits.
- Respect currency and exchange-rate constraints.
- Preserve decimal precision.
- Do not calculate authoritative balances from browser data.
- Display server validation errors.
- Preserve entered values where practical after a rejected request.

If the backend requires payment allocations to equal the full payment amount,
enforce that rule in the form and clearly explain it. If it permits advances or
unallocated balances, follow that actual contract instead.

Do not assume supplier payment allocation rules match customer receipt rules.

### 8.4 Payment detail and lifecycle

Display the payment data and allocations returned by the backend.

Expose only supported actions such as draft editing, posting, deletion,
cancellation, or reversal.

Do not assume posted payments can be modified or deleted.

Do not imply that a payment has reduced a bill's outstanding balance until the
backend confirms the relevant operation.

---

## 9. Supplier Statement

Suggested route:

`/reports/supplier-statement`

Inspect the actual statement endpoint and response contract.

Implement supported filters, potentially including:

- Supplier.
- From date.
- To date.

Display backend-provided information such as:

- Supplier identity.
- Reporting period.
- Opening balance.
- Transaction date.
- Document number/reference.
- Description.
- Debit.
- Credit.
- Running balance.
- Closing balance.
- Currency.

Use the actual contract and sign convention.

Requirements:

- Use backend-provided running balances and totals.
- Do not reconstruct the supplier ledger by summing bills and payments in the
  browser.
- Preserve debit/credit semantics.
- Support an empty statement.
- Preserve filters when navigating from a supplier detail page where
  appropriate.
- Use exact decimal strings.
- Do not claim the statement is a legal or tax document unless explicitly
  established by the product and backend.

---

## 10. Accounts Payable Report

Suggested route:

`/reports/payables`

Inspect the actual endpoint, report service, request validator, and response
shape.

Determine whether the report returns:

- Bill-level rows.
- Supplier-level balances.
- Both.
- Open bills only.
- A point-in-time snapshot.
- A date range.

Implement only supported filters, such as supplier, as-of date, status, or date
range.

Render the actual backend values, potentially including:

- Supplier.
- Bill number.
- Supplier reference.
- Bill date.
- Due date.
- Bill total.
- Amount paid.
- Outstanding balance.
- Days overdue.
- Currency.
- Summary totals.

Do not assume that all these fields exist.

Do not recompute outstanding balances or aging values in the frontend.

---

## 11. Accounts Payable Aging Report

Suggested route:

`/reports/payables-aging`

Inspect the actual aging report implementation and bucket configuration.

Do not hard-code conventional aging buckets unless the backend explicitly
defines them.

Render the exact bucket keys, labels, boundaries, and totals returned by the
backend. If the service derives bucket configuration from backend configuration,
the frontend must remain data-driven.

Where supported, display:

- Supplier or bill.
- As-of date.
- Not-yet-due amount.
- Aging bucket amounts.
- Total outstanding.

Requirements:

- Preserve the backend's bucket definitions.
- Do not recompute days overdue.
- Do not calculate aging totals in the browser.
- Handle empty results.
- Use exact monetary formatting.
- Provide verified drill-downs to supplier or bill detail.
- Preserve useful filters when navigating back.

If no payables aging endpoint exists, do not build a fake aging report from
invoice lists. Record the missing backend contract and mark the feature blocked.

---

## 12. Navigation and Permissions

Inspect:

- `src/lib/navigation/nav.ts`
- Backend permission registration and policies.
- Phase 14 navigation and permission tests.

Add navigation entries for implemented modules:

- Suppliers.
- Purchase Bills.
- Supplier Payments.
- Supplier Statement.
- Accounts Payable.
- Accounts Payable Aging.

Use the exact permission keys found in the backend.

Do not assume customer permissions correspond to supplier permissions, or sales
invoice permissions correspond to purchase bill permissions.

Reflect permission distinctions for:

- View.
- Create.
- Update.
- Post.
- Delete.
- Reporting.

Navigation filtering is a UX feature, not an authorization boundary. The backend
remains authoritative.

Show clear permission-denied states where appropriate.

---

## 13. API Types, Validators, Hooks, and Error Handling

Follow the existing feature organization used by Phase 14.

Before creating shared utilities, inspect whether an equivalent already exists.

For each supported endpoint:

1. Define strict request and response types.
2. Validate untrusted response data using the existing runtime-validation
   approach.
3. Add API methods through the established client.
4. Forward abort signals where supported.
5. Add hooks following established request patterns.
6. Handle loading, error, empty, and unavailable states.
7. Prevent stale results from appearing after filter changes or company
   switches.

Use `unknown` and explicit narrowing for untrusted input.

Do not use:

- `any`.
- Unvalidated response casts.
- Invented pagination metadata.
- Browser-supplied company IDs as authorization.
- Broad exception swallowing.
- New dependencies without explicit authorization.

Respect the actual API response envelope and error format.

If an endpoint returns a flat array, do not invent `meta`, `links`, total
counts, or page numbers.

---

## 14. Monetary, Tax, Currency, and Date Correctness

### Monetary values

- Keep money values as decimal strings at API boundaries.
- Reuse existing money-formatting utilities.
- Do not use JavaScript floating-point arithmetic for accounting calculations.
- Do not recalculate authoritative bill/payment totals.
- Preserve zero and negative values where supported.
- Do not infer currency from a symbol when the API supplies a currency code.

### Taxes

- Follow purchase-side tax semantics, not assumptions derived from sales.
- Use the existing tax module where appropriate.
- Respect the backend's effective-date and snapshot rules.
- Do not silently substitute current tax rates for historical document rates.

### Currency

- Use only supported currencies and exchange-rate behavior.
- Do not implement foreign-currency revaluation or other deferred FX
  functionality in this phase.
- Preserve foreign amount and exchange-rate fields where supplied.

### Dates

- Use established date utilities.
- Distinguish bill date, due date, payment date, posting date, and as-of date.
- Avoid unintended timezone shifts for date-only values.
- Preserve supported date boundaries.
- Do not invent dates or posting timestamps.

### Accounting integrity

- Draft documents must not be represented as posted.
- Payment allocations must follow server rules.
- Posted documents must respect immutability.
- Never create journals directly from browser code.
- Backend totals, balances, tax results, and aging buckets remain authoritative.

---

## 15. Automated Testing

Reuse the existing Vitest and React Testing Library setup. Do not install
testing dependencies.

Add meaningful tests for every implemented module.

### Supplier tests

- Response parsing.
- List, search, filters, and pagination.
- Create/update.
- Server validation errors.
- Permission-denied state.
- Empty/loading/error states.
- Lifecycle actions where supported.

### Purchase bill tests

- List/detail parsing.
- Draft creation and editing.
- Line creation/removal.
- Supplier and account/item selection.
- Tax presentation.
- Exact monetary formatting.
- Draft versus posted behavior.
- Posting and deletion confirmation.
- Server validation errors.
- Immutable posted-document behavior.

### Supplier payment tests

- List/detail parsing.
- Payment creation and lifecycle.
- Partial allocation.
- Multiple allocations where supported.
- Allocation limits and required total rules.
- Currency restrictions.
- Draft versus posted behavior.
- Error and permission states.

### Reporting tests

- Supplier statement opening and closing balances.
- Movement rows and running balances.
- Payables totals.
- Data-driven aging buckets.
- Filters and date boundaries.
- Drill-down links.
- Empty/error/loading states.
- Exact decimal rendering.

### Navigation and security-oriented UI tests

- Exact permission keys.
- Permission-filtered links.
- No request when required identifiers are missing.
- Stale data does not survive company changes.
- Cross-company errors are surfaced safely.

Mock at the established API client or hook boundary.

Do not remove existing tests to simplify implementation. Do not claim that
mocked tests prove live integration.

---

## 16. Quality Gates

Inspect `package.json` and the actual scripts before running checks.

Run from `accounting-app/frontend`:

1. `npm run lint`
2. `npx eslint . --max-warnings=0`
3. `npx tsc --noEmit`
4. `npm test` or the established full Vitest command
5. `npm run build`

Do not install, remove, or upgrade dependencies.

Fix all lint errors and warnings introduced by Phase 15. Do not disable lint
rules or weaken TypeScript.

Record exact commands, exit codes, and results.

If a quality gate fails, investigate and fix in-scope problems. Do not report
success for a gate that remains red.

If a command would modify package manifests, lockfiles, or unrelated generated
files, stop and explain before proceeding.

---

## 17. Live Integration Status

Phase 14 reports that live backend integration has not yet been performed.

Do not claim live API verification unless the frontend has actually been
exercised against a running backend and database.

Do not start, configure, migrate, or seed the backend without explicit
authorization.

If live integration is unavailable:

- Complete source-level contract inspection.
- Complete mocked automated tests.
- Mark live integration as not performed.
- Record the exact smoke tests needed for Phase 21.

Future runtime verification must cover:

1. Supplier CRUD and company isolation.
2. Purchase bill creation and posting.
3. Purchase tax behavior.
4. Supplier payment creation and allocation.
5. Partial payments across multiple bills where supported.
6. Bill outstanding balances after payment posting.
7. Supplier statement totals.
8. Payables and aging report totals.
9. Permission-denied responses.
10. Company switching and cross-company identifiers.
11. Date-boundary behavior.
12. Exact monetary rendering.
13. BFF proxy behavior for every new endpoint.

---

## 18. Phase 15 Report

Create:

`accounting-app/docs/FRONTEND_PHASE_15_REPORT.md`

Include:

1. Executive summary.
2. Initial branch, HEAD, and Git status.
3. Repository preservation confirmation.
4. Actual backend endpoint inventory.
5. Exact permission keys verified.
6. Supplier implementation.
7. Purchase bill implementation and lifecycle.
8. Purchase tax behavior and limitations.
9. Supplier payment implementation.
10. Allocation rules verified.
11. Supplier statement implementation.
12. Payables report implementation.
13. Aging report implementation and bucket source.
14. Files added and modified.
15. Test files and coverage.
16. Exact quality-gate results.
17. Live integration status.
18. Missing or incompatible backend contracts.
19. Known limitations.
20. Remaining risks for Phase 16 and Phase 21.
21. Final Git status.
22. Confirmation that no backend files, dependencies, previous reports, or
    unrelated user work were changed.

Distinguish clearly between:

- Verified by source inspection.
- Verified by automated tests.
- Verified by production build.
- Verified against a live backend.
- Not verified.

Do not overstate completion.

---

## 19. Acceptance Criteria

Phase 15 is complete only when the applicable criteria are satisfied.

### Suppliers

- Supported list, search, detail, create, and update workflows work.
- Unsupported lifecycle actions are not exposed.
- Validation and permission errors are handled.
- Company isolation is preserved.

### Purchase bills

- List, detail, and supported draft workflows work.
- Form fields match the actual backend contract.
- Tax behavior follows purchase-side rules.
- Posting and immutability rules are respected.
- Financial totals are not incorrectly recomputed in the browser.

### Supplier payments

- Supported payment creation and lifecycle work.
- Payment allocation follows actual backend rules.
- Partial payments work where supported.
- Allocation limits, currency constraints, and total rules are respected.
- Posted-payment behavior follows the backend contract.

### Reports

- Supplier statements use backend-provided balances.
- Payables reports use verified response structures.
- Aging buckets and totals come from the backend.
- Filters and drill-downs work where supported.

### Quality and preservation

- All required quality gates pass.
- New tests cover implemented workflows.
- No backend files or dependencies are modified.
- No prior reports or unrelated user work are overwritten.
- No prohibited Git operations are performed.
- No commit or push is performed.
- The Phase 15 report accurately documents results and limitations.

If a backend capability is missing, do not fabricate it or implement it in the
backend. Document the blocker and mark the affected acceptance criterion as
blocked.

---

## 20. Stop Condition

After Phase 15:

- Stop implementation work.
- Do not start Phase 16.
- Do not implement credit/debit notes.
- Do not implement fixed assets.
- Do not implement multi-currency/FX features.
- Do not implement budgets or dimensions.
- Do not implement company administration.
- Do not perform unrelated refactoring.
- Do not commit or push.

Provide a concise final summary stating:

- Implemented modules and routes.
- Verified backend contracts.
- Payment allocation capabilities.
- Test coverage.
- Lint, TypeScript, test, and build results.
- Known blockers.
- Phase 15 report path.
- Confirmation that existing work was preserved.

**Begin with repository and backend contract inspection. Implement only what the
existing backend supports. Stop at Phase 15.**
