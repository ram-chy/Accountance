# Accountance Frontend — Phase 14: Sales & Receivables

**Project:** Accountance Accounting Web App  
**Phase:** 14  
**Scope:** Frontend only  
**Primary Modules:** Customers, Sales Invoices, Customer Receipts, Customer
Statements, Receivables & Aging Reports  
**Backend Changes:** Strictly prohibited  
**Git Commit/Push:** Prohibited unless explicitly authorized separately  
**Phase Report:** `accounting-app/docs/FRONTEND_PHASE_14_REPORT.md`

---

## 1. Objective

Implement the Sales & Receivables frontend using the existing Laravel accounting
API.

The goal is to introduce the customer-facing sales workflow while preserving the
accounting foundation and reporting behavior completed in previous phases.

Implement the following areas:

1. Customer management.
2. Sales invoice management.
3. Customer receipt and partial-payment management.
4. Customer statements.
5. Accounts receivable and aging reports.

Use the existing backend implementation and its actual API contracts. This phase
must not create or modify backend functionality.

**Important:** This is an accounting application. Do not treat invoices and
receipts as simple CRUD records. Inspect the backend lifecycle, posting rules,
balances, tax behavior, and payment-allocation model before implementing the
user interface.

Do not proceed to Phase 15.

---

## 2. Mandatory Repository Preservation

Phase 13 was reported as committed and pushed to `origin/main` in commit
`8fbea2b`. Verify the current repository state before making changes; do not
assume that the report describes the current state perfectly.

### 2.1 Initial inspection

From the repository root, inspect and record:

- Current branch and HEAD.
- `git status --short --branch`.
- `git diff --stat`.
- Tracked modifications.
- Untracked files.
- The current Sales & Receivables backend implementation.
- Existing frontend routes, navigation, permissions, API client, BFF proxy,
  hooks, forms, dialogs, tables, and tests.

Inspect the existing phase documentation, especially:

- `docs/BLUEPRINT.md`
- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_API_HANDOFF.md`
- `docs/FRONTEND_PHASE_11_REPORT.md`
- `docs/FRONTEND_PHASE_12_REPORT.md`
- `docs/FRONTEND_PHASE_13_REPORT.md`

Use the actual repository paths if they differ.

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

Do not change branches, discard work, delete unrelated files, overwrite prior
phase reports, or attempt automatic repository cleanup.

If the repository has unexpected modifications or the working tree cannot be
safely preserved, stop and report the exact blocker.

### 2.3 File boundaries

- Do not modify `accounting-app/backend/`.
- Do not modify backend routes, controllers, services, models, permissions,
  policies, migrations, or tests.
- Do not run migrations or seeders.
- Do not install, remove, or upgrade dependencies.
- Do not modify package manifests or lockfiles.
- Do not remove existing tests.
- Do not overwrite prior phase reports.
- Do not rewrite the frontend architecture.
- Preserve all unrelated tracked and untracked work.

Frontend source files, frontend tests, navigation, and the new Phase 14 report
may be added or modified when necessary for this phase.

Create:

`accounting-app/docs/FRONTEND_PHASE_14_REPORT.md`

If this file already exists, inspect it and preserve its content. Use an
alternative filename if necessary rather than overwriting user work.

---

## 3. Inspect the Backend Contracts Before Implementing

Do not begin by guessing endpoint names, payloads, response structures, status
values, or permission names.

Inspect the current backend source, including:

- `backend/routes/api.php`
- Relevant route files loaded by the API route registration.
- Controllers for customers, invoices, receipts, statements, receivables, and
  aging.
- Request validation classes.
- Services and domain actions.
- Models, relationships, casts, and relevant scopes.
- Policies and permission middleware.
- API resources and response wrappers.
- Relevant backend tests.
- Existing accounting documentation.

Identify every actual route and record:

| Area               | Required contract information                                                                      |
| ------------------ | -------------------------------------------------------------------------------------------------- |
| Customers          | List, search, filters, detail, create, update, activation/deactivation, delete if supported        |
| Sales invoices     | List, detail, creation, update, permitted deletion, lifecycle transitions, posting/finalization    |
| Invoice lines      | Item/account selection, quantities, prices, discounts, taxes, descriptions, validation             |
| Customer receipts  | List, detail, creation, permitted editing/deletion, posting, cancellation or reversal if supported |
| Receipt allocation | How one receipt is allocated to one or multiple invoices; how partial payments are represented     |
| Customer statement | Endpoint, date parameters, opening balance, movement rows, running balance, totals                 |
| Receivables        | Endpoint, supported filters, totals, customer balances                                             |
| Aging              | Endpoint, aging buckets, as-of date, customer-level or invoice-level rows                          |
| Permissions        | Exact permission keys and backend authorization requirements                                       |
| Pagination         | Actual query parameters and response metadata                                                      |
| Money and dates    | Decimal precision, currency fields, date formats, timezone semantics                               |
| Lifecycle          | Draft, posted, paid, partially paid, overdue, cancelled, voided, or other actual statuses          |

These are inspection categories, not assumptions that every endpoint or
operation exists.

For each required operation, document the actual endpoint, HTTP method, payload,
response shape, permission, and lifecycle constraints.

If an endpoint is missing, incomplete, or incompatible with the requested UI,
**do not modify the backend**. Document the limitation, implement only what the
available contract supports, and mark the affected acceptance criterion as
blocked.

---

## 4. Preserve the Existing Frontend Architecture

The current architecture is:

- Next.js App Router.
- React with strict TypeScript.
- Tailwind CSS v4.
- Laravel REST API.
- Same-origin Next.js BFF proxy.
- Server-held JWT inside an encrypted HttpOnly cookie.
- Server-generated `Authorization` and `X-Company-Id` headers.
- Existing API helpers, hooks, UI primitives, permission utilities, formatters,
  and tests.

Follow existing implementation patterns instead of introducing another
architecture.

### Security requirements

- Never expose the JWT to browser JavaScript.
- Never call the Laravel backend directly from browser components.
- Route API calls through the existing same-origin BFF.
- Never trust a browser-supplied company ID for authorization.
- Do not bypass the BFF route allow-list.
- Preserve request cancellation and error normalization patterns.
- Respect company membership and server-side permissions.
- Do not cache one company's data in a way that can be displayed after switching
  companies.
- Do not use `dangerouslySetInnerHTML` to render customer-controlled content.
- Do not expose internal backend exceptions or sensitive response details to
  users.

Do not weaken authentication, authorization, company isolation, or API
validation to make a screen work.

---

## 5. Design and User Experience

Use the existing Accountance design system.

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, clean layouts.
- Consistent spacing, typography, and responsive behavior.
- Semantic colors for statuses, warnings, and errors.
- Existing button, input, select, table, dialog, badge, and feedback components.
- Keyboard-accessible forms and dialogs.
- Accessible labels and actionable error messages.

Do not introduce new UI libraries or visual frameworks.

Every page must support:

- Initial loading.
- Empty state.
- API error.
- Permission denied.
- Validation error.
- Successful operation feedback.
- Disabled controls while operations are pending.
- Confirmation for destructive or irreversible actions.
- Responsive behavior at desktop, tablet, and mobile widths.

Never display success feedback before the server confirms the operation.

---

## 6. Customer Management

Implement customer management only to the extent supported by the actual API.

Suggested route family:

- `/customers`
- `/customers/new`
- `/customers/[id]`

Adapt route naming to existing frontend conventions and verified navigation
structure.

### 6.1 Customer list

Implement supported features such as:

- Search by customer name or code.
- Pagination.
- Supported status filters.
- Customer code.
- Customer name.
- Contact information.
- Tax registration information, where applicable.
- Outstanding receivables, only if provided by the API or an existing supported
  report.
- Active/inactive status.
- Row actions permitted by the backend.

Do not fabricate a customer balance by summing incomplete invoice data.

Honor the actual pagination contract. If the API returns a flat array, do not
invent page counts or total records.

### 6.2 Create and edit

Use the actual request validator to determine the fields.

Potential fields may include:

- Customer code.
- Customer name.
- Contact person.
- Email.
- Phone.
- Billing address.
- Shipping address.
- Tax identification.
- Payment terms.
- Currency.
- Opening balance, if genuinely supported.

These are candidates for inspection, not a mandate to invent fields.

Requirements:

- Validate required fields in the UI.
- Preserve optional and nullable semantics.
- Show server validation messages beside the relevant inputs when possible.
- Keep server-owned fields out of writable payloads.
- Do not submit unsupported fields.
- Prevent duplicate submissions.
- Re-fetch or update the customer list after a successful change using
  established patterns.

### 6.3 Customer detail

Display the fields actually returned by the API.

If supported, provide navigation to:

- Customer invoices.
- Customer receipts.
- Customer statement.
- Receivables and aging information.

Do not build a fake activity timeline from incomplete data.

### 6.4 Lifecycle and deletion

Only expose activation, deactivation, or deletion if the backend supports the
operation and the current user has permission.

If the backend rejects deletion of a customer referenced by financial records,
display the server's safe explanation and preserve the record.

Never hide backend errors or suggest that a failed deletion succeeded.

---

## 7. Sales Invoice Management

Implement the sales invoice workflow using the existing backend contract.

Suggested route family:

- `/sales-invoices`
- `/sales-invoices/new`
- `/sales-invoices/[id]`

Use existing naming conventions if the project already defines another route
structure.

### 7.1 Invoice list

Support only filters accepted by the backend, potentially including:

- Search.
- Customer.
- Invoice status.
- Invoice number.
- Invoice date range.
- Due date range.
- Pagination.

Display available values such as:

- Invoice number.
- Customer.
- Invoice date.
- Due date.
- Currency.
- Invoice total.
- Amount paid.
- Outstanding amount.
- Status.

Do not calculate the outstanding amount or payment status in the frontend unless
the API contract explicitly requires a presentation-only transformation based on
authoritative supplied values. Prefer backend-calculated balances and status
fields.

### 7.2 Invoice creation and editing

Inspect the actual invoice request validator, service, tax rules, and line
model.

Build a form with only supported fields. Depending on the verified contract,
this may include:

- Customer.
- Invoice date.
- Due date.
- Reference.
- Description or notes.
- Currency and exchange rate, if supported.
- Invoice lines.
- Quantity and unit price.
- Discount fields.
- Tax selection.
- Line descriptions.

Do not assume these fields all exist.

For invoice lines:

- Support adding and removing lines when the backend permits it.
- Require valid account or item identifiers as applicable.
- Validate positive quantities where required.
- Preserve decimal precision.
- Prevent invalid numeric input.
- Follow backend discount and tax semantics.
- Display line totals only according to the established API behavior.
- Do not invent a line-calculation formula that conflicts with backend rounding.
- Do not treat browser-computed totals as authoritative.

Use exact decimal strings for monetary values. Avoid converting money to
JavaScript `Number` for calculations.

### 7.3 Tax integration

Reuse the tax-management API and types from Phase 10 where applicable.

Inspect the sales invoice implementation to determine:

- Whether taxes are selected per line or per invoice.
- Whether tax rates are effective-dated.
- Whether invoice tax amounts are snapshotted.
- Whether inclusive and exclusive tax are supported.
- Whether the backend owns all tax calculations.
- Whether the invoice form needs a tax calculator preview.

Do not silently reuse the standalone tax calculator as if it were an invoice
tax-posting engine.

Do not recalculate invoice tax on the client if the backend owns the
calculation.

### 7.4 Invoice detail

Render the server-returned invoice details and line items.

Where supported, show:

- Invoice number and status.
- Customer.
- Dates.
- Reference.
- Currency.
- Lines and descriptions.
- Tax breakdown.
- Subtotal, tax total, and invoice total.
- Amount paid and outstanding balance.
- Linked receipts.
- Journal/posting reference, if supplied.

Do not invent journal numbers or claim that an invoice has been posted when the
server reports a draft status.

### 7.5 Lifecycle actions

Expose only backend-supported actions, such as:

- Edit draft.
- Delete draft.
- Post/finalize invoice.
- Cancel or reverse invoice, if supported.

Inspect exact status transitions and permissions.

Posted financial documents must not be presented as freely editable if the
backend enforces immutability.

Before irreversible actions:

1. Explain the action clearly.
2. Require explicit confirmation.
3. Prevent duplicate requests.
4. Display the server result.
5. Refresh the detail and related lists.

Do not implement a frontend-only state transition that pretends the backend has
posted or cancelled an invoice.

---

## 8. Customer Receipts and Partial Payments

Suggested route family:

- `/customer-receipts`
- `/customer-receipts/new`
- `/customer-receipts/[id]`

Inspect the backend receipt and allocation model before building the forms.

### 8.1 Receipt list

Render supported values, potentially including:

- Receipt number.
- Receipt date.
- Customer.
- Payment method.
- Reference.
- Currency.
- Amount.
- Allocation status.
- Lifecycle status.

Use only real API fields and filters.

### 8.2 Receipt creation

Support the actual backend fields. These may include:

- Customer.
- Receipt date.
- Payment method.
- Reference.
- Cash/bank account.
- Currency.
- Exchange rate.
- Amount received.
- Notes.

Do not assume every payment method is supported.

Use the existing cash/bank account API for account selection only when the
backend receipt contract requires it and the user's permissions allow access.

### 8.3 Partial payment allocation

Partial payments are a core requirement.

Inspect whether the backend allows a receipt to be:

- Allocated to one invoice.
- Allocated across multiple invoices.
- Partially allocated.
- Saved before allocation.
- Edited or reallocated after creation.
- Posted before or after allocation.

Implement only the supported workflow.

When invoice allocation is supported, show:

- Invoice number.
- Invoice date and due date, if supplied.
- Original invoice total.
- Previously paid amount, if supplied.
- Outstanding amount.
- Amount to allocate.
- Remaining unallocated receipt amount, if supported.

Validation requirements:

- Do not allow negative allocation amounts unless explicitly supported.
- Prevent allocation beyond available receipt or invoice balances when the
  backend contract requires this.
- Respect currency restrictions and conversion rules.
- Preserve monetary precision.
- Do not calculate authoritative outstanding balances from the browser.
- Do not submit allocations in a format that differs from the backend contract.
- Show server validation errors without losing the user's form state
  unnecessarily.

If allocation is performed through a dedicated endpoint or resource, follow that
design instead of inventing nested payloads.

### 8.4 Receipt detail and lifecycle

Display receipt details and allocation information returned by the backend.

Expose only supported actions such as:

- Edit draft.
- Post.
- Delete draft.
- Cancel or reverse, if implemented.

Do not assume posted receipts can be edited or deleted.

Never imply that a receipt has reduced an invoice balance before the backend
confirms the applicable posting/allocation operation.

---

## 9. Customer Statement

Suggested route:

`/reports/customer-statement`

Inspect the exact statement endpoint and response structure.

Implement only supported filters, potentially:

- Customer.
- From date.
- To date.

Display backend-provided statement fields such as:

- Customer identity.
- Reporting period.
- Opening balance.
- Transaction date.
- Document number.
- Description.
- Debit.
- Credit.
- Running balance.
- Closing balance.

The actual contract determines the final fields and sign convention.

Requirements:

- Use backend-provided running balances and totals.
- Do not reconstruct the customer's ledger from invoices and receipts.
- Preserve debit/credit semantics.
- Show the selected customer and period.
- Handle an empty statement correctly.
- Preserve filters when navigating from a customer detail page.
- Use exact decimal strings.
- Include currency information when supplied.
- Do not claim the statement is a legal or tax document unless the backend and
  product requirements establish that.

---

## 10. Accounts Receivable and Aging Reports

Suggested route family:

- `/reports/receivables`
- `/reports/receivables-aging`

Adapt the route names to existing report conventions and verified API contracts.

### 10.1 Receivables report

Display the backend's supported receivable data, potentially including:

- Customer.
- Invoice number.
- Invoice date.
- Due date.
- Invoice total.
- Amount paid.
- Outstanding balance.
- Days overdue.
- Currency.

Determine whether the backend report is customer-level, invoice-level, or both.
Do not force it into a different model.

Support only actual filters, such as customer, as-of date, status, or date
range, if present in the backend.

### 10.2 Aging report

Inspect the exact aging service and its bucket definitions.

Render the actual backend buckets. Do not assume standard bucket boundaries
without verifying the implementation.

For each row, display the values supplied by the API, which may include:

- Customer or invoice.
- Current/not-due amount.
- Aging bucket amounts.
- Total outstanding.
- As-of date.

Use the exact labels and boundaries from the backend.

Do not recompute overdue days or aging buckets in the browser.

### 10.3 Drill-down

Where the report includes an invoice identifier and a verified invoice detail
route, allow navigation to the invoice.

Preserve useful report filters when navigating back.

Do not generate links for missing or mismatched identifiers.

---

## 11. Navigation and Permissions

Update the existing navigation file additively.

Inspect `src/lib/navigation/nav.ts` and the backend permission definitions.

Add links for implemented modules only:

- Customers.
- Sales Invoices.
- Customer Receipts.
- Customer Statement.
- Receivables.
- Receivables Aging.

Use exact backend permission keys. Do not invent permission strings.

Follow existing role and navigation conventions. Hide inaccessible navigation
entries as the existing UI does, but never treat hidden links as authorization.

For each page and action:

- Verify the required permission.
- Respect company context.
- Preserve server-side authorization as authoritative.
- Show a clear access-denied state when the API returns a permission failure.
- Do not assume that customer-view permission grants invoice-posting permission.

If permissions are split between viewing, creating, updating, posting, deleting,
or reporting, reflect those distinctions in the UI.

---

## 12. API Client, Types, Validators, and Hooks

Organize new files according to the existing project structure.

Before creating a new helper, inspect whether an equivalent already exists.

For every supported endpoint:

1. Define strict request and response types.
2. Add runtime response validation where established patterns require it.
3. Add an API method using the existing client.
4. Forward abort signals where supported.
5. Add hooks using existing request lifecycle patterns.
6. Implement loading, error, empty, and unavailable states.
7. Ensure company switching and filter changes cannot leave stale data visible.

Use `unknown` for untrusted input and narrow it safely.

Do not use:

- `any`.
- Unvalidated response casts.
- Fabricated response metadata.
- Client-owned authorization fields.
- Generic fallbacks that hide malformed server responses.
- Broad exception swallowing.

If an endpoint returns a flat array without pagination metadata, honor that
actual contract.

---

## 13. Monetary and Date Handling

This application uses financial data. Precision is mandatory.

### Monetary values

- Keep monetary values as decimal strings at API boundaries.
- Reuse the existing money-formatting helpers.
- Never use floating-point arithmetic to calculate invoice totals, tax,
  allocations, balances, or aging.
- Use backend totals as authoritative.
- Do not infer a currency from a symbol when the API provides a currency code.
- Preserve zero and negative values where the accounting contract allows them.
- Do not assume every document uses the company's base currency.

### Dates

- Follow the existing date utilities.
- Distinguish invoice date, due date, receipt date, posting date, and report
  as-of date.
- Do not shift date-only values through unintended timezone conversions.
- Validate supported date formats.
- Preserve date boundaries in filters.
- Display server-returned lifecycle and posting dates without inventing values.

### Accounting integrity

- Draft invoices and receipts must not be represented as posted.
- Payment allocations must follow the server's rules.
- Do not recalculate posted balances in the frontend.
- Do not introduce journal creation directly from browser code.
- Do not expose editing controls for immutable posted documents unless the
  backend explicitly supports a valid transition.

---

## 14. Automated Testing

Inspect the existing Vitest and React Testing Library setup. Do not install new
testing dependencies.

Add meaningful tests for each implemented module.

### Customer tests

- Valid and invalid API responses.
- List, search, and supported filters.
- Create and update.
- Server validation errors.
- Permission-denied state.
- Empty and loading states.
- Lifecycle actions, if supported.

### Sales invoice tests

- List and detail response parsing.
- Draft creation and editing.
- Required fields.
- Line addition and removal.
- Tax selection and displayed totals, according to the real contract.
- Draft versus posted behavior.
- Posting/deletion confirmation, if supported.
- Immutable-document behavior.
- API errors and validation errors.

### Receipt tests

- Receipt list and detail.
- Creation and supported lifecycle actions.
- Partial allocations.
- Multiple allocations if the backend supports them.
- Allocation bounds and currency restrictions.
- Server validation errors.
- Draft versus posted behavior.

### Statement and report tests

- Opening and closing balances.
- Movement rows.
- Debit and credit columns.
- Running balances.
- Aging bucket values and labels.
- Filters and date boundaries.
- Drill-down links.
- Empty and error states.
- Exact decimal formatting.

### Navigation and security-oriented UI tests

- Correct permission keys.
- Links hidden or disabled according to established patterns.
- Company-context changes do not show stale data.
- No request is made when a required identifier is missing.
- Cross-company errors are surfaced safely.

Mock at the existing API client boundary.

Do not claim that mocked tests establish live API correctness.

---

## 15. Quality Gates

Inspect the actual package scripts first.

Run the following from `accounting-app/frontend` where supported:

1. `npm run lint`
2. `npx eslint . --max-warnings=0`
3. `npx tsc --noEmit`
4. `npm test`
5. `npm run build`

Do not install or update packages.

Fix all Phase 14 lint errors and warnings introduced by the new code. Do not
disable lint rules or weaken TypeScript to pass.

Record the exact command, exit code, and result.

If a gate fails, investigate and fix in-scope issues. Do not claim success when
any required gate remains red.

If a command would modify dependencies, lockfiles, or unrelated generated files,
stop and explain before proceeding.

---

## 16. Live Integration

Phase 13 reported that live backend integration had not yet been performed.

Do not claim that this phase verifies real API integration unless a running
backend and database are available and integration tests are actually performed.

Do not start, configure, migrate, or seed the backend without explicit
authorization.

If live integration is unavailable:

- Complete source-level contract inspection.
- Complete mocked automated tests.
- Record live integration as not performed.
- List exact runtime smoke tests required in Phase 21.

The future live smoke tests must include:

- Customer CRUD and company isolation.
- Invoice draft creation and posting.
- Tax and currency behavior.
- Partial payment allocation.
- Receipt posting and invoice balance updates.
- Customer statement balances.
- Receivables and aging totals.
- Permission-denied responses.
- Company switching.
- Invalid IDs and cross-company IDs.
- Date-boundary behavior.
- Exact monetary rendering.

---

## 17. Phase 14 Report

Create:

`accounting-app/docs/FRONTEND_PHASE_14_REPORT.md`

Include:

1. Executive summary.
2. Initial branch, HEAD, and Git status.
3. Repository preservation confirmation.
4. Actual backend endpoint inventory.
5. Exact permissions verified.
6. Customer management implementation.
7. Sales invoice implementation and lifecycle.
8. Receipt and partial-payment allocation implementation.
9. Customer statement implementation.
10. Receivables and aging report implementation.
11. Files added and modified.
12. Test files and coverage.
13. Exact quality-gate results.
14. Live integration status.
15. Missing or incompatible backend contracts.
16. Known limitations.
17. Remaining risks for Phase 15 and Phase 21.
18. Final Git status.
19. Confirmation that no backend files, dependencies, previous reports, or
    unrelated user work were modified.

Clearly distinguish:

- Verified by backend source inspection.
- Verified by automated tests.
- Verified by production build.
- Verified against a live backend.
- Not verified.

Do not overstate completion.

---

## 18. Acceptance Criteria

Phase 14 is complete only when the following applicable criteria are satisfied.

### Customer management

- Supported customer list, search, detail, create, and update operations work.
- Unsupported lifecycle actions are not exposed.
- API validation and permission errors are handled.
- Company isolation is preserved.

### Sales invoices

- Invoice list, detail, and supported draft workflows work.
- Fields and line items match the actual API contract.
- Tax behavior matches backend rules.
- Posting and immutability rules are respected.
- Financial totals are not recomputed incorrectly in the browser.

### Customer receipts

- Supported receipt creation and lifecycle work.
- Partial payment allocation is implemented if supported by the backend.
- Allocation rules and currency constraints are respected.
- Posted receipt behavior follows the backend contract.

### Reporting

- Customer statements render backend-provided movements and balances.
- Receivables and aging reports use verified response structures.
- Aging buckets and totals are not recomputed in the frontend.
- Filters and drill-downs work where supported.

### Quality and preservation

- All required quality gates pass.
- New tests cover the implemented workflows.
- No backend files or dependencies are modified.
- No prior reports or unrelated work are overwritten.
- No prohibited Git operations are performed.
- No commit or push is performed.
- The Phase 14 report accurately records results and limitations.

If an API capability does not exist, do not fabricate it or modify the backend
to satisfy the acceptance criteria. Document the blocker and mark the affected
criterion as blocked.

---

## 19. Stop Condition

After Phase 14:

- Stop implementation work.
- Do not start Phase 15.
- Do not implement supplier management, purchase bills, supplier payments, or
  payables.
- Do not implement credit/debit notes.
- Do not implement fixed assets, foreign exchange, budgets, dimensions, or
  company administration.
- Do not implement unrelated features.
- Do not commit or push.

Provide a concise final summary containing:

- Implemented modules and routes.
- Verified backend contracts.
- Partial-payment support status.
- Test coverage.
- Lint, TypeScript, test, and build results.
- Known blockers.
- Report path.
- Confirmation that existing work was preserved.

**Begin with repository and contract inspection. Implement only what the
existing backend supports. Stop at Phase 14.**
