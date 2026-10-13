# Accountance Frontend — Phase 16: Credit Notes & Debit Notes

**Project:** Accountance Accounting Web App  
**Phase:** 16  
**Scope:** Frontend only  
**Primary Modules:** Sales Credit Notes, Purchase Debit Notes, Document
Allocations, Related Financial Reports  
**Backend Changes:** Strictly prohibited  
**Git Commit/Push:** Prohibited unless explicitly authorized separately  
**Phase Report:** `accounting-app/docs/FRONTEND_PHASE_16_REPORT.md`

---

## 1. Objective

Implement Credit Notes and Debit Notes in the Accountance frontend using the
existing Laravel API and established Next.js architecture.

The objective is to add the supported financial adjustment workflows for sales
and purchases while preserving accounting integrity and consistency with
existing invoices, receipts, purchase bills, supplier payments, and reports.

The phase should cover the following areas where supported by the actual
backend:

1. Sales Credit Notes.
2. Purchase Debit Notes.
3. Credit/debit note creation and draft editing.
4. Source-document association.
5. Supported invoice/bill allocation or adjustment workflows.
6. Posting and draft deletion.
7. Document details and financial summaries.
8. Related customer/supplier balances and reports.
9. Navigation and permission handling.

**Important:** Do not assume that sales credit notes and purchase debit notes
share identical contracts, fields, posting rules, allocation rules, or lifecycle
transitions. Inspect the backend implementation and tests before designing
either workflow.

Do not proceed to Phase 17.

---

## 2. Mandatory Repository Preservation

Phase 15 reports that the frontend contains substantial uncommitted work from
Phases 14 and 15, including suppliers, purchase bills, supplier payments, and
their reports.

Treat all existing modifications and untracked files as user-owned work.

### 2.1 Inspect the repository before editing

Record:

- Repository root.
- Current branch and HEAD.
- `git status --short --branch`.
- `git diff --stat`.
- Existing tracked modifications.
- Existing untracked files.
- Phase 14 and Phase 15 reports and prompts.
- Existing customer, sales invoice, customer receipt, supplier, purchase bill,
  supplier payment, and reporting implementations.
- Relevant backend contracts for credit and debit notes.

Do not assume the working tree remains identical to the Phase 15 report. Inspect
its actual current state.

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
- `git commit --amend`

Do not change branches, discard existing changes, delete unrelated files, or
perform automatic repository cleanup.

If the repository is in an unexpected state, preserve all work and report any
blocker.

### 2.3 File boundaries

- Do not modify anything under `accounting-app/backend/`.
- Do not change backend routes, controllers, services, models, policies,
  permissions, migrations, or tests.
- Do not run migrations or seeders.
- Do not install, remove, or upgrade dependencies.
- Do not modify package manifests or lockfiles.
- Do not delete or weaken existing tests.
- Do not overwrite earlier phase reports or prompts.
- Do not refactor unrelated modules.
- Do not modify existing Phase 14/15 workflows unless a minimal, necessary
  integration change is required.

Create:

`accounting-app/docs/FRONTEND_PHASE_16_REPORT.md`

If this report already exists, preserve it and choose a new filename rather than
overwriting it.

---

## 3. Inspect the Actual Backend Contracts First

Before implementing the frontend, inspect the backend routes, request
validation, services, resources, policies, models, and tests for credit and
debit notes.

Inspect at least:

- `backend/routes/api.php` and any related route files.
- Sales credit note routes and controllers.
- Purchase debit note routes and controllers.
- Store/update request classes.
- Document calculation and tax services.
- Posting services and journal creation.
- Customer and supplier allocation/settlement services.
- Invoice and purchase bill lifecycle handling.
- Customer and supplier statements.
- Receivables, payables, and aging report implementations.
- Permission registration and policies.
- Existing backend feature tests.
- Any API handoff or accounting documentation relevant to these workflows.

Use actual repository paths.

### 3.1 Build a contract inventory

Before writing UI code, document the verified contract for each document type:

| Contract area     | What must be verified                                                                                               |
| ----------------- | ------------------------------------------------------------------------------------------------------------------- |
| Endpoints         | Exact list, create, detail, update, delete, post, allocation, and lifecycle routes                                  |
| Permissions       | Exact permission keys for view, create, update, post, delete, and any special actions                               |
| Document identity | Server-generated number versus user-supplied reference                                                              |
| Source documents  | Whether the note must reference an invoice/bill or can be created independently                                     |
| Lines             | Supported line fields and whether account, product, quantity, unit price/cost, discount, or description is required |
| Tax               | Tax selection, rate snapshots, tax account handling, rounding, and historical behavior                              |
| Amounts           | Exact calculation and decimal precision                                                                             |
| Allocations       | How notes are applied to invoices/bills or balances                                                                 |
| Status            | Actual lifecycle states and permitted transitions                                                                   |
| Posting           | Exact endpoint, payload, and server-side accounting effects                                                         |
| Deletion          | Whether only drafts may be deleted                                                                                  |
| Pagination        | Real query parameters and response structure                                                                        |
| Reports           | How notes appear in statements, aging, and outstanding balances                                                     |
| Company scope     | Company-context behavior and cross-company identifier handling                                                      |

Record exact HTTP methods, paths, query parameters, request bodies, response
shapes, and permissions.

Do not invent routes or payload fields. If a capability is missing, document the
limitation instead of simulating it.

---

## 4. Preserve the Existing Architecture

Use the existing:

- Next.js App Router.
- React and strict TypeScript.
- Tailwind CSS v4.
- Same-origin Next.js BFF.
- Encrypted HttpOnly session cookie.
- Server-held Laravel JWT.
- Server-generated `Authorization` and `X-Company-Id` headers.
- API helpers, error normalization, runtime validators, hooks, and formatting
  utilities.
- Existing UI primitives and modal/form conventions.
- Vitest and React Testing Library.
- Permission-aware navigation.
- Existing Light/Dark themes.

Inspect the implementations from Phases 14 and 15 before creating new modules.

Prefer consistent feature-local structure, reusing existing utilities when
appropriate. Do not create a new architectural pattern solely for credit/debit
notes.

### Security requirements

- Never expose JWTs to browser JavaScript.
- Never call the Laravel API directly from browser components.
- Use the established BFF for every request.
- Never trust a client-supplied company ID for authorization.
- Do not bypass proxy allow-lists.
- Do not leak internal exceptions or sensitive backend data.
- Do not weaken existing session or company-isolation behavior.
- Ensure stale data from a previous company is not displayed after a company
  switch.

If a required backend endpoint is not allowed by the existing BFF, inspect
whether the proxy architecture already supports the path safely. Do not change
backend code or bypass proxy restrictions. Document any frontend proxy
limitation that cannot be resolved within scope.

---

## 5. Proposed Routes

Use these route names if consistent with the actual API and existing frontend
conventions:

### Sales Credit Notes

- `/credit-notes`
- `/credit-notes/new`
- `/credit-notes/[id]`
- `/credit-notes/[id]/edit`

### Purchase Debit Notes

- `/debit-notes`
- `/debit-notes/new`
- `/debit-notes/[id]`
- `/debit-notes/[id]/edit`

Adjust route names only where necessary to avoid ambiguity or match established
conventions. Record the final route mapping in the Phase 16 report.

Do not add duplicate routes if equivalent pages already exist.

---

## 6. Sales Credit Notes

Inspect the actual sales credit note API and implement only its supported
functionality.

### 6.1 List page

Support verified filters, potentially including:

- Search.
- Customer.
- Credit note status.
- Source sales invoice.
- Document date range.
- Reference number.
- Pagination.

Display available fields such as:

- Credit note number.
- Customer.
- Source invoice/reference.
- Credit note date.
- Currency.
- Total amount.
- Amount applied or allocated.
- Remaining credit, if supplied.
- Status.

Do not invent a remaining-credit field if the API does not provide it.

Use the backend's actual pagination contract. Do not invent total counts,
`links`, or `meta`.

### 6.2 Create and edit

Inspect the actual credit note request validators and calculation services.

The form may include the following only if supported:

- Customer.
- Original sales invoice.
- Credit note date.
- Reason or description.
- Reference.
- Lines.
- Revenue or other supported account mappings.
- Quantity.
- Unit price.
- Discount.
- Tax.
- Notes.

Determine whether the credit note:

- Must reference an existing invoice.
- Can be issued independently.
- Can contain lines different from the original invoice.
- Supports full or partial credits.
- Supports quantity-based or amount-based credits.
- Can exceed the remaining invoice amount.
- Can be allocated to one or multiple invoices.
- Can leave an unapplied customer credit balance.

Do not assume any of these behaviors.

If a source invoice is required, load eligible invoices using the actual
endpoint and filters. Respect customer association and server-reported
eligibility.

If the backend enforces remaining-credit or source-document limits, provide
early client feedback where safe, but preserve backend validation as
authoritative.

### 6.3 Tax and totals

Follow the backend's actual credit note calculation rules.

Inspect whether a credit note:

- Reverses original invoice line tax snapshots.
- Uses the current tax configuration.
- Accepts explicit tax IDs.
- Uses a tax-exclusive or tax-inclusive calculation.
- Supports tax-only adjustments.
- Supports discounts or rounding adjustments.

Do not silently substitute a current tax rate for a historical invoice rate.

If a local preview is appropriate, use exact decimal arithmetic with existing
utilities and mirror verified backend behavior. The preview is advisory; the
backend remains authoritative.

### 6.4 Detail page

Render the server-returned information:

- Credit note identity and status.
- Customer.
- Source invoice, if applicable.
- Date and reference.
- Lines.
- Tax breakdown.
- Subtotal and total.
- Amount applied and remaining credit, if provided.
- Allocation history, if available.
- Posting/journal reference, if supplied.

Show only lifecycle actions supported by the backend and permitted to the
current user.

### 6.5 Lifecycle

Follow the actual credit note lifecycle.

For supported actions:

- Draft edit.
- Draft deletion.
- Posting.
- Cancellation or reversal, if the backend explicitly supports it.
- Application/allocation, if the backend explicitly supports it.

Posted financial documents must respect backend immutability.

Never simulate posting by changing local state. Wait for the server response and
refresh the relevant detail and lists.

---

## 7. Purchase Debit Notes

Inspect the purchase debit note contract independently from the sales credit
note contract.

A purchase debit note may represent a supplier balance adjustment or another
purchase-side adjustment. Do not assume its accounting meaning, source-document
requirements, or signs without reading the backend implementation.

### 7.1 List page

Implement verified filters, potentially including:

- Search.
- Supplier.
- Debit note status.
- Source purchase bill.
- Document date range.
- Reference.
- Pagination.

Display only supported fields such as:

- Debit note number.
- Supplier.
- Source bill.
- Date.
- Currency.
- Total.
- Amount applied.
- Remaining debit/adjustment amount.
- Status.

Do not infer status or balances from a client-side calculation.

### 7.2 Create and edit

Inspect the actual debit note request validators, services, and calculation
rules.

Potential fields, only if supported:

- Supplier.
- Original purchase bill.
- Debit note date.
- Reason.
- Reference.
- Lines.
- Expense or other account mapping.
- Quantity.
- Unit cost.
- Discount.
- Tax.
- Notes.

Determine whether the note:

- Requires an existing purchase bill.
- Can be created independently.
- Supports full or partial adjustments.
- Is limited by the source bill's remaining amount.
- Can be applied to one or multiple bills.
- Can create an unapplied supplier debit/credit balance.
- Has separate tax handling for recoverable input tax.

Do not copy the sales credit note form and simply rename its labels.

### 7.3 Tax and totals

Follow the purchase-side accounting contract.

Verify the meaning of each supported tax field and account mapping. Do not
assume that purchase debit notes use the same tax accounts or posting signs as
sales credit notes.

Use exact decimal arithmetic for any local preview. Keep all accounting totals
and posting effects server-authoritative.

### 7.4 Detail and lifecycle

Render the actual server response and expose only supported actions.

Respect draft-only editing/deletion, posting rules, and immutability where
enforced.

Do not present a debit note as posted until the backend confirms it.

---

## 8. Allocation and Adjustment Workflows

This section is critical to accounting integrity.

Inspect the backend to establish how credit notes affect customer balances and
how debit notes affect supplier balances.

Do not assume credit/debit notes use the same allocation workflow as receipts or
supplier payments.

Verify whether the backend supports:

- Applying a credit note to its source invoice.
- Applying a credit note to another invoice.
- Applying a debit note to its source purchase bill.
- Applying a debit note to another bill.
- Partial application.
- Multiple applications.
- Unapplied balances.
- Automatic application at posting.
- Separate allocation endpoints.
- Reversing or removing allocations.
- Allocation restrictions after posting.

Implement only the operations supported by actual routes and services.

### Validation requirements

Where the API supports allocations:

- Respect document and source-document association.
- Respect eligible statuses.
- Respect the remaining applicable amount.
- Prevent duplicate allocations where prohibited.
- Preserve currency and exchange-rate constraints.
- Follow backend rules for partial or full application.
- Use exact decimal arithmetic for client-side validation.
- Display authoritative server-returned balances.
- Surface allocation errors without discarding the entire form unnecessarily.

If no separate allocation workflow exists and posting applies the adjustment
automatically, do not create a fake allocation UI.

If the backend exposes no allocation mechanism, document that limitation and
implement only the supported note workflow.

---

## 9. Integration With Existing Modules

Integrate the new document types with existing modules only where supported by
the backend contracts.

### Customer and sales invoice integration

- Add verified links between customers, sales invoices, and credit notes.
- Show credit notes on invoice detail only if the API exposes the relationship
  or provides a supported lookup.
- Show credit note effects in customer statements and receivables reports only
  through server-returned report data.
- Do not recompute invoice outstanding balances locally.

### Supplier and purchase bill integration

- Add verified links between suppliers, purchase bills, and debit notes.
- Show debit notes on bill detail only if supported by the API.
- Show debit note effects in supplier statements and payables reports through
  server-returned data.
- Do not recompute supplier outstanding balances locally.

### Report integration

Phase 15's statement contract indicated support for movement types including
`credit_note` and `debit_note`. Inspect the actual frontend validators and
renderers to determine whether these rows are already supported.

If the report parsers already accept these types, add regression tests and
preserve the existing behavior.

If they do not, make the smallest necessary frontend-only change to support the
verified contract.

Do not change report semantics or balance signs without backend evidence.

---

## 10. Navigation and Permissions

Inspect backend permission registration and existing navigation code.

Add the new navigation entries only when their corresponding pages and backend
capabilities are implemented.

Verify exact permission keys for each operation:

- View.
- Create.
- Update.
- Post.
- Delete.
- Allocation or application, if separately permissioned.

Do not guess permission names based on customer receipts, supplier payments, or
sales invoices.

Update navigation tests to cover:

- Visibility with the correct permission.
- Hidden navigation without permission.
- Existing navigation preserved.
- No duplicate items.
- Correct routes.

Navigation filtering is not authorization. The backend remains authoritative.

If an upcoming Debit Notes placeholder already exists, replace it with the real
page only after the relevant route is implemented. Do not remove unrelated
upcoming placeholders.

---

## 11. API Types, Runtime Validators, and Hooks

Follow the existing feature structure and naming conventions.

Create or extend feature modules for credit notes and debit notes, including the
equivalent of:

- Types.
- Runtime validators.
- API methods.
- Hooks.
- Form components.
- List views.
- Detail views.
- Tests.

Reuse established patterns from:

- `src/lib/sales-invoices/`
- `src/lib/customer-receipts/`
- `src/lib/purchase-bills/`
- `src/lib/supplier-payments/`
- `src/lib/reports/`

Verify exact paths in the current repository.

Requirements:

- Validate all untrusted API responses.
- Use strict TypeScript.
- Prefer `unknown` and explicit narrowing.
- Do not use `any`.
- Do not blindly cast responses.
- Respect the existing API response envelope.
- Respect actual pagination behavior.
- Handle `AbortSignal` where existing API patterns support it.
- Prevent stale responses from overwriting newer results.
- Respect company context and permissions.
- Normalize field-level validation errors using existing helpers.
- Do not introduce a new data-fetching or validation library.

Do not refactor existing shared modules unless a minimal, directly required
change is justified.

---

## 12. Money, Currency, Dates, and Accounting Integrity

### Money

- Keep API monetary values as decimal strings.
- Use existing formatting utilities.
- Never use JavaScript floating-point arithmetic for accounting calculations.
- Use existing exact-decimal utilities where appropriate.
- Do not make client-side totals authoritative.
- Do not infer a currency from a symbol when the API supplies a currency code.

### Tax

- Respect purchase and sales tax semantics separately.
- Preserve historical tax snapshots where the backend uses them.
- Do not substitute current tax rates for historical values.
- Do not invent tax mappings.

### Currency

- Follow the backend's actual currency and exchange-rate contract.
- Do not introduce multi-currency/FX features that are outside this phase.
- Preserve supplied currency fields and amounts.
- Do not silently convert amounts in the browser.

### Dates

- Use existing date utilities.
- Avoid timezone shifts for date-only values.
- Distinguish document date, source-document date, posting date, and allocation
  date when supplied.
- Do not invent posting timestamps.

### Document status

- Drafts must be clearly distinguished from posted documents.
- Posted documents must respect immutability.
- Do not infer posting success from a successful navigation or optimistic local
  state.
- Refresh relevant records after successful operations.

---

## 13. UI and UX Requirements

Follow the Accountance design system:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, clean layouts.
- Responsive desktop, tablet, and mobile behavior.
- Consistent tables, forms, badges, dialogs, spacing, and typography.
- Accessible labels, keyboard navigation, and focus behavior.
- Semantic status colors.

Every page must handle:

- Loading.
- Empty results.
- API failure.
- Validation errors.
- Permission denial.
- Missing or inaccessible document.
- Duplicate submission.
- Pending mutation.
- Confirmation for irreversible actions.

Use the existing form and dialog conventions. Preserve entered values when
validation fails where practical.

Do not add decorative charts or unrelated dashboard changes.

---

## 14. Automated Testing

Reuse the existing Vitest and React Testing Library setup.

Add meaningful tests for all implemented functionality.

### Credit note tests

- List response parsing.
- Detail response parsing.
- Search and filters.
- Creation and update payload validation.
- Source-invoice selection rules.
- Tax and total rendering.
- Draft-only edit/delete.
- Posting behavior.
- Allocation rules where supported.
- Permission handling.
- Empty/loading/error states.

### Debit note tests

- List and detail response parsing.
- Supplier/source-bill selection.
- Creation and update validation.
- Purchase-side tax behavior.
- Draft-only edit/delete.
- Posting behavior.
- Allocation rules where supported.
- Permission handling.
- Empty/loading/error states.

### Integration tests

- Customer and supplier detail links.
- Sales invoice and purchase bill links.
- Customer statement support for credit-note rows.
- Supplier statement support for debit-note rows.
- Existing receivables/payables reports remain functional.
- Navigation permission tests.
- Company-switch stale-data behavior.
- Safe handling of 404, 409, 422, and permission errors where relevant.

### Test discipline

- Mock at the established API-client or hook boundary.
- Do not remove existing tests.
- Do not weaken assertions.
- Do not claim mocked tests prove live backend integration.
- Test exact monetary behavior without floating-point approximations.

---

## 15. Quality Gates

Inspect `package.json` and the current frontend scripts before running checks.

Run from `accounting-app/frontend`:

1. `npm run lint`
2. `npx eslint . --max-warnings=0`
3. `npx tsc --noEmit`
4. `npx vitest run`
5. `npm run build`

Use the actual established equivalent if a script differs, and record the
command used.

Fix errors and warnings introduced by Phase 16.

Do not disable lint rules, weaken TypeScript, or remove tests to achieve a green
result.

Do not install or change dependencies.

If a command fails, investigate and fix in-scope issues. Report unresolved
failures accurately.

Do not run destructive commands or commands that discard existing work.

---

## 16. Live Integration

Phase 15 reports that live backend/database integration has not been performed.

Do not claim live integration has been tested unless it actually has.

Do not start or reconfigure the backend, modify databases, run migrations, or
seed data without explicit authorization.

If live integration is unavailable:

- Verify contracts through backend source inspection and tests.
- Complete frontend automated tests.
- Record live integration as not performed.
- List exact smoke tests for Phase 21.

Future live smoke tests should cover:

1. Sales credit note creation and validation.
2. Sales credit note posting.
3. Customer balance and invoice effects.
4. Purchase debit note creation and validation.
5. Purchase debit note posting.
6. Supplier balance and bill effects.
7. Allocation behavior, where supported.
8. Tax and rounding.
9. Statements and aging reports.
10. Company isolation.
11. Permission-denied responses.
12. Draft immutability rules after posting.

---

## 17. Phase 16 Report

Create:

`accounting-app/docs/FRONTEND_PHASE_16_REPORT.md`

Include:

1. Executive summary.
2. Initial branch, HEAD, and Git status.
3. Repository preservation confirmation.
4. Backend endpoint inventory.
5. Exact permissions verified.
6. Credit note implementation and routes.
7. Debit note implementation and routes.
8. Source-document association rules.
9. Tax and calculation behavior.
10. Allocation/application rules.
11. Customer/supplier and report integration.
12. Navigation changes.
13. Files added and modified.
14. Test files and test counts.
15. Exact lint, ESLint, TypeScript, test, and build results.
16. Live integration status.
17. Missing backend contracts and blocked functionality.
18. Known limitations and risks.
19. Final Git status.
20. Confirmation that backend files, dependencies, previous reports, and
    unrelated work were preserved.

Clearly distinguish:

- Verified by backend source inspection.
- Verified by automated tests.
- Verified by production build.
- Verified against a live backend.
- Not verified.

Do not claim a feature is complete if its required backend contract is missing.

---

## 18. Acceptance Criteria

Phase 16 is complete when all applicable criteria below are met.

### Sales Credit Notes

- Supported list, create, detail, and draft-edit workflows are implemented.
- Source invoice rules are respected.
- Tax and monetary fields match the real API.
- Posting and deletion follow backend lifecycle rules.
- Allocation/application exists only if supported.
- Customer balance effects are represented by backend-returned values.

### Purchase Debit Notes

- Supported list, create, detail, and draft-edit workflows are implemented.
- Source bill rules are respected.
- Purchase-side tax and monetary fields match the real API.
- Posting and deletion follow backend lifecycle rules.
- Allocation/application exists only if supported.
- Supplier balance effects are represented by backend-returned values.

### Integration

- Related customer, supplier, invoice, bill, and report links work where
  supported.
- Statement movement types are handled correctly.
- Existing Phase 14 and Phase 15 functionality remains intact.
- Permissions and company isolation are respected.

### Quality and preservation

- All required quality gates pass.
- New workflows have meaningful automated tests.
- No backend code or dependencies are modified.
- No earlier reports or unrelated user work are overwritten.
- No prohibited Git operations occur.
- No commit or push is performed.
- The Phase 16 report accurately records results and blockers.

If the backend lacks a required capability, document it explicitly and mark that
criterion blocked. Do not fabricate an endpoint or implement accounting behavior
in the frontend to compensate.

---

## 19. Stop Condition

After Phase 16:

- Stop implementation work.
- Do not start Phase 17.
- Do not implement fixed assets.
- Do not implement multi-currency/FX.
- Do not implement budgets or dimensions.
- Do not implement company administration.
- Do not perform unrelated refactoring.
- Do not commit or push.

Provide a concise final summary stating:

- Implemented modules and routes.
- Verified backend contracts.
- Allocation/application behavior supported.
- Test count and results.
- Lint, TypeScript, and build results.
- Known blockers.
- Phase 16 report path.
- Confirmation that existing work was preserved.

**Start with repository and backend contract inspection. Implement only the
functionality the existing backend supports. Stop at Phase 16.**
