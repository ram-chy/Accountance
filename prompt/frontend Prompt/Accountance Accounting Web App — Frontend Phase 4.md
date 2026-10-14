# Accountance Accounting Web App — Frontend Phase 4

## Dashboard Foundation, Overview Widgets & Reporting UI Infrastructure

You are working on the existing **Accountance Accounting Web App**, an
international-ready double-entry accounting system.

Your task is to implement **Frontend Phase 4** by following the existing master
documentation, frontend blueprint, backend API contracts, and architecture
established in the previous phases.

Work carefully. Inspect first, reuse existing code, avoid unnecessary changes,
and verify the implementation before reporting completion.

---

## 1. Project Context

### Technology stack

- Next.js 16 with App Router
- React 19
- TypeScript
- Tailwind CSS v4
- Laravel API backend
- MySQL database managed by Laravel
- Existing authentication and company context
- Existing same-origin API proxy with server-held JWT
- Existing permission-aware authorization and protected routes

### Previously completed phases

**Phase 0 — Project Foundation**

- Initial frontend structure and development conventions.

**Phase 1 — Design System and Utilities**

- Green-slate design tokens.
- Light and dark theme foundations.
- API client foundations.
- Precision-safe money and date utilities.

**Phase 2 — Authentication and Company Context**

- Secure HttpOnly encrypted JWT cookie architecture.
- Authentication state and company context.
- Permission handling and protected routes.

**Phase 3 — Application Shell and API Proxy**

- Authenticated application layout.
- Same-origin API proxy forwarding the server-held JWT.
- Validated `X-Company-Id` forwarding.
- Session-expiration handling.
- Company-switch cache invalidation.
- Permission-aware navigation and related tests.

Before implementation, verify the actual repository and completion status. Treat
the list above as expected context, not permission to overwrite or recreate
existing functionality.

---

## 2. Phase 4 Objective

Build a professional, responsive accounting dashboard that provides a reliable
overview of the currently selected company and its accounting activity.

The dashboard must use real backend data wherever supported. It must not
fabricate financial figures, transaction history, balances, or reporting
results.

This phase establishes the dashboard and reusable reporting UI infrastructure.
It does not implement the individual accounting transaction modules.

## 3. Mandatory Repository Inspection

Before editing files:

1. Inspect the repository structure, `package.json`, installed dependencies, and
   TypeScript configuration.
2. Read the existing master blueprint and frontend blueprint.
3. Inspect the existing application shell, sidebar, header, theme
   implementation, API client, proxy routes, authentication utilities, company
   context, permission utilities, and shared components.
4. Review Laravel API routes, relevant controllers, resources, policies, and
   response formats for available dashboard and accounting endpoints.
5. Inspect existing tests and conventions.
6. Identify which components and utilities can be reused.
7. Create a concise implementation plan based on the actual codebase.

Do not:

- Reinitialize the Next.js project.
- Replace the established frontend architecture.
- Reimplement completed authentication or proxy functionality.
- Install a dependency without confirming that it is needed.
- Assume an endpoint exists because it would be useful.
- modify unrelated files or Laravel backend behavior merely to simplify frontend
  work.

If the backend does not expose a required reporting endpoint, document the gap
and build only the appropriate frontend integration boundary. Do not return
fabricated financial data as a substitute.

---

## 4. Dashboard Page

Implement the authenticated dashboard at the existing dashboard route, following
the project's routing conventions.

The page should contain the following sections.

### A. Page heading

Display:

- Page title: Dashboard
- A concise description of the accounting overview
- The currently selected company context, using existing company-context data
- An optional reporting-period selector only if its behavior can be implemented
  correctly with the available backend contracts

The layout must remain compact, readable, and professional.

### B. Financial overview cards

Create reusable summary cards for the following metrics, but display each metric
only when its data source is supported:

1. Cash and bank balance
2. Accounts receivable
3. Accounts payable
4. Income for the selected reporting period
5. Expenses for the selected reporting period
6. Net profit or loss, only when the backend provides a valid calculation or the
   documented accounting contract supports it

Each card should support:

- A descriptive label
- A properly formatted monetary amount
- An optional comparison or trend indicator, only when valid comparison data
  exists
- Loading state
- Empty state
- Error state
- Accessible semantics

Financial values must use the existing precision-safe money utilities and the
selected company's currency configuration.

Do not use JavaScript floating-point arithmetic to calculate authoritative
monetary totals.

Do not invent a default currency when the company currency is unknown. Use the
project's established fallback or a clearly defined unavailable state.

### C. Financial charts

Create a reusable chart section for available reporting data.

Preferred visualizations:

- Income versus expenses over time
- A monthly financial trend
- Expense breakdown by category, if supported by the API

Requirements:

- Use the existing charting dependency if one is already installed.
- If no charting library exists, evaluate the smallest appropriate dependency
  before adding one.
- Charts must consume actual API data.
- Display appropriate empty, loading, and error states.
- Provide readable axes, legends, tooltips, and accessible labels.
- Format dates and currency using existing utilities.
- Avoid misleading visualizations when data is incomplete or unavailable.
- Ensure charts work in both light and dark modes.
- Avoid excessive animation and decorative gradients.

If a chart's data source does not exist, do not populate it with fake data.
Provide a clear empty or unavailable state and document the missing API
requirement.

### D. Recent accounting activity

Add a reusable recent-activity section only if a compatible endpoint exists.

Potential activity types include:

- Posted journal entries
- Invoices
- Payments
- Other accounting transactions exposed by the backend

Display only fields supplied by the actual API, such as:

- Transaction reference
- Transaction date
- Description
- Transaction type
- Status
- Amount, where applicable

Requirements:

- Respect company isolation.
- Use actual server-provided identifiers and statuses.
- Format dates and amounts consistently.
- Handle empty results and API failures.
- Do not expose information the current user is not authorized to access.

Do not build invoice, payment, or journal management screens in this phase.

---

## 5. Reusable Dashboard Components

Create or extend shared components according to the existing component
architecture.

Potential components:

- `DashboardPageHeader`
- `FinancialSummaryCard`
- `FinancialSummaryGrid`
- `FinancialChartCard`
- `IncomeExpenseChart`
- `RecentActivityTable`
- `DashboardLoadingState`
- `DashboardEmptyState`
- `DashboardErrorState`
- `ReportingPeriodSelector`, if supported

These names are suggestions, not mandatory file paths. Reuse existing components
when suitable.

Avoid creating abstractions for one-off elements without a clear reuse benefit.

Keep business logic, API integration, data formatting, and presentation
separated according to existing project conventions.

---

## 6. API Integration and Data Integrity

Use the established same-origin API client and secure proxy architecture.

All protected dashboard requests must follow the existing authentication and
company-context model.

Requirements:

1. Do not expose the JWT or any other authentication secret to client-side
   JavaScript.
2. Do not bypass the same-origin proxy to call the Laravel backend directly from
   the browser.
3. Do not accept a client-supplied company identifier as proof of authorization.
4. Preserve the established validated `X-Company-Id` behavior.
5. Use actual backend response types and validate untrusted response data where
   appropriate.
6. Normalize API errors using existing error-handling conventions.
7. Handle unauthorized and expired-session responses consistently with Phase 3.
8. Prevent stale company data from appearing after a company switch.
9. Prevent race conditions when the user changes the reporting period or company
   context.
10. Do not calculate authoritative accounting balances from incomplete dashboard
    data.

Follow existing caching and request-deduplication conventions. Use appropriate
cache invalidation when company context or reporting parameters change.

If dashboard aggregation endpoints are missing, identify the exact endpoint and
response contract required in the final report. Do not silently introduce a new
backend architecture.

---

## 7. Design System and Visual Requirements

Maintain the established Accountance visual identity.

### Light mode

- Calm green primary accent.
- Clean white or neutral-slate surfaces.
- Subtle borders and restrained shadows.
- Readable typography and generous spacing.
- Clear visual hierarchy without excessive decoration.

### Dark mode

- Professional dark-slate surfaces.
- Readable text and financial values.
- Consistent borders and component contrast.
- Correct chart colors and tooltip readability.

### Both modes

- Follow existing design tokens rather than introducing arbitrary colors.
- Reserve semantic red, amber, and informational colors for their intended
  meanings.
- Avoid using green as a universal success indicator when it would obscure the
  actual meaning.
- Avoid oversized headings, excessive rounded cards, gradients, and unnecessary
  animations.
- Keep the dashboard compact enough for practical daily accounting work.

Do not introduce a second theme system or modify the established global design
tokens unnecessarily.

---

## 8. Responsive Layout and Accessibility

Support:

- Desktop
- Laptop
- Tablet
- Mobile

Requirements:

- Summary cards adapt to available width.
- Charts resize without clipping.
- Tables have an intentional mobile presentation, such as horizontal scrolling
  or a compact list.
- Navigation and header behavior remain consistent with Phase 3.
- Keyboard navigation works for interactive controls.
- Focus states are visible.
- Buttons and selectors have accessible names.
- Color is not the only indicator of status.
- Loading and error messages are accessible.
- Respect reduced-motion preferences.

Do not change the existing application shell unless a specific dashboard
integration issue requires a minimal, justified adjustment.

---

## 9. State Management and Performance

Follow existing project conventions. Do not introduce a new state-management
library without a demonstrated need.

Requirements:

- Avoid duplicate API requests.
- Avoid unnecessary client-side rendering.
- Use server components by default where practical.
- Introduce client components only where interactivity requires them.
- Keep chart-specific rendering isolated.
- Prevent stale results from overwriting newer reporting-period selections.
- Keep data fetching and formatting logic reusable.
- Avoid loading the entire transaction history merely to calculate summary
  metrics.
- Use pagination or backend aggregation when the available API supports it.

Do not add global state solely for dashboard-specific values unless existing
architecture already calls for it.

---

## 10. Testing Requirements

Add or update tests following the repository's existing testing framework.

At minimum, cover the applicable cases:

### Dashboard rendering

- Authenticated user can load the dashboard.
- Summary cards render correctly with valid API responses.
- Missing optional reporting data does not crash the page.
- Empty activity results render the correct empty state.

### Data formatting

- Currency uses the configured company currency.
- Dates use existing date utilities.
- Large amounts and decimal precision are formatted correctly.
- Missing values are handled safely.
- Negative values and zero values display correctly.

### API and authorization behavior

- API failures display an appropriate error state.
- Expired-session behavior follows Phase 3.
- Company switching does not leave stale dashboard data visible.
- Unsupported or malformed response data is handled safely.
- No protected request exposes authentication credentials to the browser.

### User interface

- Light and dark modes remain usable.
- Responsive layouts do not introduce major overflow.
- Interactive controls have accessible names and keyboard behavior.

Do not weaken or delete existing tests to make the new tests pass.

---

## 11. Verification

Run the appropriate project checks, including:

1. TypeScript type checking.
2. ESLint or the repository's established lint command.
3. Relevant automated tests.
4. Production build.
5. Any existing formatting or static-analysis checks relevant to the changed
   files.

Resolve issues introduced by this phase.

Do not claim a check passed unless it was actually executed and passed. If an
existing unrelated failure prevents verification, document the precise failure.

Do not run database migrations, seeders, destructive commands, or unrelated
backend modifications without explicit authorization.

---

## 12. Scope Boundaries

This phase covers the dashboard foundation and its supported reporting
integrations.

Do not implement:

- Chart of Accounts management
- Journal creation or posting forms
- Invoice management
- Payment collection
- Expense management
- Accounts receivable or payable transaction workflows
- Fiscal-period closing
- Budget management
- New authentication flows
- A replacement application shell
- Fake API endpoints or mock financial totals presented as real data

These areas belong to their appropriate future phases unless they already exist
and are required solely to display legitimate dashboard data.

---

## 13. Definition of Done

Phase 4 is complete when:

- The dashboard integrates with the existing authenticated application shell.
- Supported summary metrics use real backend data.
- Available charts and activity sections use actual reporting responses.
- Missing backend capabilities are documented instead of simulated.
- Currency and date formatting follow existing utilities.
- Company switching cannot leave misleading stale figures on screen.
- Loading, empty, error, and unauthorized states are handled.
- Light mode, dark mode, responsive behavior, and accessibility are addressed.
- Tests, type checking, linting, and the production build have been verified as
  applicable.
- Existing functionality and architecture remain intact.
- No unrelated modules or backend behavior have been modified.

---

## 14. Final Report

When finished, provide a concise but complete implementation report containing:

1. **Status:** Complete, partially complete, or blocked.
2. **Repository findings:** Existing architecture and components reused.
3. **Implemented features:** Dashboard sections and interactions.
4. **Files changed:** New and modified files with their purposes.
5. **API integrations:** Endpoints actually used and their response contracts.
6. **Missing backend capabilities:** Exact endpoints or data fields still
   needed.
7. **Security:** How authentication and company isolation were preserved.
8. **Tests and verification:** Actual commands executed and their results.
9. **Known limitations:** Any remaining issues or incomplete requirements.
10. **Next phase recommendation:** The next logical frontend implementation
    phase based on the actual project roadmap.

Begin by inspecting the repository and its documentation. Then implement Phase 4
incrementally, run the required checks, and report the verified results.

**Priority:** Correctness, accounting data integrity, security, reuse of the
existing architecture, maintainability, and a clean professional user
experience. Do not rush or expand the scope unnecessarily.
