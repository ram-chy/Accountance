# Accountance Frontend — Phase 11: Financial Reports & Reporting Enhancements

## 1. Objective

Implement **Frontend Phase 11 only** for the Accountance Accounting Web App.

The objective is to strengthen the financial reporting experience using the
existing Laravel API and Next.js frontend architecture.

The work must prioritize accurate, readable, company-scoped accounting reports
and avoid duplicating functionality already delivered in earlier phases.

Potential report areas to inspect include:

- Profit & Loss.
- Trial Balance.
- General Ledger and account statements.
- Budget Variance.
- Tax Summary and Tax-by-Tax reports.
- Cash and bank ledger reporting.
- Reporting filters, drill-down links, and navigation between existing reports.

**Do not automatically implement every item in this list.** Inspect the current
source, blueprint, handoff, and reports first. Select the highest-value report
gaps that are supported by the existing backend and have not already been
implemented.

This is a frontend-only phase. Do not modify Laravel backend files, database
schema, migrations, seeders, or accounting rules.

Stop after Phase 11. Do not start Phase 12.

---

## 2. Critical Repository Preservation Rules

All work from previous phases is uncommitted or untracked. The Phase 10 report
records existing frontend changes and pre-existing untracked backend files.

Preserve the current repository exactly except for intentional Phase 11 changes.

Before modifying anything:

1. Confirm the repository root and current branch.
2. Run `git status --short`.
3. Run `git diff --stat` and inspect relevant diffs.
4. Identify existing untracked files and prior phase reports.
5. Read the current Phase 10 report and the reporting-related source files.
6. Record the initial repository state in the Phase 11 report.

### Absolute restrictions

- Do not run `git pull` or `git fetch`.
- Do not run `git reset`, `git stash`, or `git clean`.
- Do not checkout or switch branches.
- Do not commit changes.
- Do not delete, discard, overwrite, or restore files to resolve conflicts.
- Do not remove pre-existing untracked files.
- Do not overwrite previous phase reports.
- Do not modify backend files.
- Do not run migrations or seeders.

If a required file is missing or the repository state is ambiguous, stop the
risky operation and document the issue. Do not attempt automatic recovery by
overwriting existing files.

---

## 3. Mandatory Inspection Before Implementation

Read the current versions of:

- `docs/FRONTEND_BLUEPRINT.md`
- `docs/FRONTEND_API_HANDOFF.md`
- `docs/FRONTEND_PHASE_4_REPORT.md`, if present.
- `docs/FRONTEND_PHASE_5_REPORT.md`, if present.
- `docs/FRONTEND_PHASE_6_REPORT.md`, if present.
- `docs/FRONTEND_PHASE_7_REPORT.md`, if present.
- `docs/FRONTEND_PHASE_8_REPORT.md`, if present.
- `docs/FRONTEND_PHASE_9_REPORT.md`, if present.
- `docs/FRONTEND_PHASE_10_REPORT.md`
- Relevant backend reporting tests and source.

Inspect the actual current implementation of:

- Dashboard financial summaries and charts.
- Trial Balance.
- Profit & Loss.
- Budget Variance.
- Tax reports.
- Account ledger balance and statement views.
- Journal list and detail.
- Fiscal years and accounting periods.
- Report filters and date utilities.
- Permission checks and navigation.
- API helpers, runtime validators, and company-aware hooks.
- Existing chart, table, export, and drill-down components.

Do not assume that a report mentioned in an earlier plan is missing or
incomplete. Verify its current implementation.

### Backend source inspection

Inspect the relevant Laravel source:

- `routes/api.php`
- Reporting controllers and services.
- API resources and response schemas.
- Form requests and report filter validation.
- Permission definitions and route middleware.
- Relevant accounting feature tests.
- Any documented pagination or aggregation limitations.

Produce a concise contract checklist before implementation, listing each chosen
report, endpoint, permission, filters, response shape, and known limitations.

---

## 4. Select the Phase 11 Scope

After inspection, identify report functionality that is:

1. Supported by existing backend endpoints.
2. Not already implemented adequately.
3. Useful for routine accounting work.
4. Implementable without backend changes.
5. Consistent with the established architecture.

Write the proposed scope into the Phase 11 report before coding.

Prioritize correctness and completion over the number of screens.

If the existing reporting experience is already complete, improve only
meaningful gaps such as filter consistency, accessible data presentation, safe
drill-down navigation, or error handling. Do not invent new reports simply to
fill a phase.

Do not introduce new backend requirements as part of this frontend phase.

---

## 5. Reporting Routes and Navigation

Follow existing route conventions.

Reuse the existing routes for reports already implemented. Add a new route only
when the selected capability genuinely requires one and the backend supports it.

Use the existing Reports navigation group and actual permission checks.

Do not:

- Rename existing report routes.
- Move existing pages without a compelling, documented reason.
- Duplicate Tax Reports from Phase 10.
- Duplicate the dashboard's existing financial summary charts.
- Reorganize the application's entire navigation.

Maintain compatibility with existing deep links.

---

## 6. Report Contract and Data Accuracy

For every selected report, verify:

- Exact endpoint and HTTP method.
- Required permissions.
- Supported query parameters.
- Date-range semantics.
- Company and fiscal-period scoping.
- Response shape.
- Monetary precision.
- Sorting and pagination behaviour.
- Whether the report is based on posted journals, document snapshots, or another
  defined data source.
- How empty results and invalid date ranges are handled.

Use backend-returned totals as authoritative.

Do not infer accounting semantics from field names alone.

### Double-entry and accounting integrity

- Do not calculate or persist accounting balances in the frontend.
- Do not substitute draft journals for posted ledger activity.
- Do not combine reports that use different date semantics without explaining
  the distinction.
- Do not assume debit and credit signs without inspecting the backend
  implementation.
- Do not use floating-point arithmetic for financial totals.
- Do not combine amounts from different currencies.
- Do not invent opening balances, closing balances, or report totals.
- Do not reinterpret a backend validation failure as a successful empty report.

Where the API returns exact decimal strings, preserve them and use existing safe
formatting utilities.

---

## 7. Reporting Filters

Improve filter consistency across the selected reports without imposing
unsupported parameters on the API.

Possible filters include:

- Start and end dates.
- Financial year.
- Accounting period.
- Account.
- Report grouping or breakdown.
- Other filters explicitly supported by the backend.

For each filter:

1. Confirm the accepted parameter name and format.
2. Confirm whether the backend treats the date range as inclusive or exclusive.
3. Confirm whether a filter applies to posting date, transaction date, or
   another date.
4. Validate the user input before submitting.
5. Preserve valid filter values when appropriate.
6. Provide a clear reset action.
7. Prevent obsolete responses from replacing newer results.

Do not add a filter merely because it would be useful if the backend cannot
apply it.

Do not silently change the meaning of an existing report filter.

---

## 8. Drill-Down Navigation

Where existing routes and APIs support a safe, useful drill-down, connect report
rows to their relevant source screens.

Potential destinations include:

- Chart of Accounts.
- Journal detail.
- Cash and bank transaction detail.
- Accounting period detail.
- Tax detail or Tax Reports.

Before adding a link, verify that:

- The target route exists.
- The identifier is available in the report response.
- The destination validates the identifier.
- The destination rechecks company context and permissions.
- The link does not expose sensitive information through query parameters.

Do not invent a drill-down API or derive an identifier that the backend does not
provide.

If the report endpoint lacks the data required for reliable drill-down, retain a
read-only row and document the limitation.

---

## 9. Charts and Tables

Reuse the existing design system and chart components.

Provide the clearest appropriate presentation for the selected report:

- Responsive tables for detailed records.
- Summary figures where the backend supplies them.
- Charts only when the returned data supports a meaningful visual
  representation.
- Accessible labels, legends, and table equivalents for chart information.
- Clear empty states and units.
- Horizontal overflow handling for wide tables.

Do not add a chart merely for decoration.

Do not duplicate a table's values into a chart using a different calculation.

Do not display misleading totals when the API does not return them.

Preserve professional Dark Mode and the calm green-based Light Mode.

---

## 10. Export and Print

Inspect existing export or print capabilities before implementing anything new.

If a verified existing frontend utility or supported backend endpoint can
provide an export, determine whether it can be safely reused.

Do not invent a server export endpoint.

Do not claim that a browser-generated CSV is an official accounting export if it
omits fields, pagination results, or report metadata.

If implementing client-side CSV is justified and all report data is available:

- Escape delimiters, quotes, and line breaks correctly.
- Prevent spreadsheet-formula injection for untrusted cell values.
- Include report dates and relevant metadata.
- Preserve decimal text without converting it through floating-point arithmetic.
- Clearly distinguish displayed rows from complete report data when the API is
  paginated or capped.

If safe export cannot be implemented with the current contract, document it
rather than building a misleading workaround.

---

## 11. Permissions and Company Isolation

Verify the actual permission required for every selected report and each
drill-down destination.

Ensure:

- Navigation uses existing permission rules.
- Users without view permission do not trigger protected report requests.
- All browser requests go through the existing secure same-origin BFF proxy.
- No JWT is exposed to browser JavaScript.
- The BFF supplies the server-held authorization token and active company
  context.
- Company switches clear or invalidate report state.
- Requests from the previous company cannot update the new company's visible
  report.
- Existing permission errors are displayed according to the current API
  conventions.
- No `company_id` is fabricated or sent from the browser unless the verified
  contract explicitly requires it.

Do not use role-name checks in place of backend permissions.

Do not assume a successful report read grants permission to open its underlying
source record.

---

## 12. Data Layer and Runtime Validation

Follow the existing frontend architecture.

Reuse existing API methods, hooks, formatting utilities, and runtime validation
patterns.

For new or materially changed report endpoints, implement:

- Accurate response types.
- Runtime validators.
- API methods with the correct query parameters.
- Abort-signal support.
- Company-aware data loading.
- Safe loading, error, and empty states.
- Focused tests.

Unknown enum values or malformed report responses must be handled safely.

Do not silently convert invalid amounts to zero or invalid dates to the current
date.

Do not introduce TanStack Query, React Hook Form, Zod, or other new dependencies
without a compelling and documented reason.

Do not refactor unrelated modules.

---

## 13. Accessibility and UX

Follow the existing Accountance design:

- Calm green-based Light Mode.
- Professional Dark Mode.
- Minimal, readable reporting layouts.
- Responsive tables and charts.
- Keyboard-accessible filters and dialogs.
- Visible focus indicators.
- Accessible labels and chart alternatives.
- Consistent date and decimal formatting.
- Loading, error, retry, and empty states.

Do not display fabricated data or misleading placeholder totals in production
UI.

---

## 14. Testing Requirements

Use the existing Vitest and React Testing Library setup.

Test only actual supported behaviour, including:

### Data layer

- Valid and malformed report responses.
- Exact query-parameter serialization.
- Inclusive/exclusive date-range handling where relevant.
- Decimal-string preservation.
- Pagination or flat-array behaviour.
- Abort-signal propagation.
- Error handling, including `401`, `403`, `404`, and `422` where applicable.

### UI

- Report loading, populated, empty, and error states.
- Filter validation and reset.
- Filter changes refresh the correct report.
- Permission denial causes no protected request.
- Company switching does not show stale results.
- Drill-down links point to valid existing routes.
- Charts and tables remain consistent.
- Export safety if export is implemented.
- Accessible filter controls and dialogs.

Do not remove or weaken existing tests to make the suite pass.

Do not add tests that merely assert implementation details without verifying
meaningful behaviour.

---

## 15. Live Integration

If a configured Laravel backend and database are available, use the existing
approved environment for safe integration checks.

Do not change backend files or run migrations/seeders to make integration
possible.

If live integration is unavailable, state that clearly. Use fixtures derived
from actual backend source and tests, and distinguish source inspection from
runtime verification.

Do not claim that report figures, permissions, company isolation, or accounting
accuracy were live-tested unless they actually were.

---

## 16. Documentation

Create:

`docs/FRONTEND_PHASE_11_REPORT.md`

Include:

1. Executive summary and completion status.
2. Initial Git status and pre-existing changes.
3. Reports inspected and existing functionality reused.
4. Chosen Phase 11 scope and why it was selected.
5. Verified backend contract for each chosen report.
6. Filters and date semantics.
7. Accounting data source and monetary precision rules.
8. Drill-down behaviour.
9. Export/print behaviour, if implemented.
10. Permissions and company isolation.
11. Files added and modified.
12. Tests and quality-gate results.
13. Live integration status.
14. Known limitations and deferred work.
15. Confirmation that backend files were untouched.
16. Confirmation that no destructive Git operations, commits, migrations, or
    seeders were performed.

Update `FRONTEND_BLUEPRINT.md` or `FRONTEND_API_HANDOFF.md` only for verified,
meaningful decisions or contract discrepancies. Preserve prior reports and
document any disagreement rather than replacing earlier work.

---

## 17. Quality Gates

Run from the actual frontend directory:

```bash
npm run lint
npx tsc --noEmit
npm test
npm run build
```

Report the actual exit status and test counts.

If dependencies are missing, inspect the existing package configuration and
lockfile before installing anything. Avoid unnecessary dependency changes.

Fix Phase 11 regressions without globally disabling lint rules, weakening
TypeScript, or removing tests.

---

## 18. Strict Scope Boundaries

### Included

- Inspection of existing reporting functionality.
- A clearly defined set of supported reporting improvements.
- Accurate filters and report presentation.
- Safe drill-down links where supported.
- Export/print only when safely supported.
- Runtime validation, permissions, company isolation, accessibility, and tests.

### Excluded

- Laravel backend modifications.
- Database changes, migrations, and seeders.
- New accounting or reporting business rules.
- Recalculation or persistence of ledger balances.
- Duplicate implementations of existing Tax Reports.
- Unsupported export endpoints.
- Unrelated dashboard or navigation redesign.
- Unrelated refactoring.
- New dependencies without justification.
- Destructive Git operations.
- Starting Phase 12.

If no meaningful report gap can be safely implemented with the existing backend,
stop and document the findings rather than inventing a feature.

---

## 19. Final Verification and Stop Condition

Before finishing:

1. Run all quality gates.
2. Review the final diff for unrelated changes.
3. Verify the Phase 11 report.
4. Confirm that no backend files were modified.
5. Confirm that pre-existing Phases 8–10 changes remain preserved.
6. Run `git status --short`.
7. Run `git status --short -- accounting-app/backend`, adjusting paths to the
   actual repository layout.
8. Separate pre-existing changes from Phase 11 modifications in the report.

Do not commit anything.

**Phase 11 is complete only when the selected, backend-supported reporting
improvements are implemented, tests and quality gates pass, the report is
written, and existing user work remains preserved.**

Stop after Phase 11. Do not start Phase 12.

Provide a concise completion summary and point me to
`docs/FRONTEND_PHASE_11_REPORT.md` for review before the next phase.
