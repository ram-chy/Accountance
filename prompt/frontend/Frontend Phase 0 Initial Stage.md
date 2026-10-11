# Accountance Accounting Web App — Frontend Phase 0: Initial Stage

## Objective

Prepare the existing Accountance Accounting Web App repository for frontend
development.

This is a discovery, verification, and planning phase. Inspect the current
project, verify the backend API contract, document frontend architecture
decisions, identify integration risks, and prepare a clear implementation plan
for Frontend Phase 1.

**Do not implement the authentication UI, dashboard, accounting pages, or a
complete frontend in this phase.**

## 1. Locked Project Architecture

Follow these established decisions unless the repository contains a genuine
contradiction that requires reporting before proceeding.

- Backend: Laravel 13 REST API.
- Database: MySQL 8.4 LTS.
- Frontend: Next.js with React, TypeScript, and Tailwind CSS.
- API-driven frontend: Laravel remains responsible for accounting logic,
  financial calculations, authorization, and data validation.
- Authentication: inspect the existing JWT authentication implementation; do not
  replace it or introduce another authentication system without an explicit
  decision.
- Accounting basis: accrual accounting.
- Accounting architecture: existing double-entry accounting engine remains the
  single source of truth.
- Company context: respect the existing company-selection and `X-Company-Id` API
  contract.
- Financial integrity: never calculate or persist an independent frontend
  version of accounting balances.
- Financial values: preserve decimal precision. Do not use JavaScript
  floating-point arithmetic for authoritative financial calculations.
- Themes: Light Mode and Dark Mode.
- Design direction: clean, minimal, professional, and comfortable for extended
  use.
- Brand palette: calm green with neutral slate/gray surfaces.
- Charts: use Recharts for interactive dashboard visualizations when dashboard
  implementation begins.
- Accessibility: semantic HTML, keyboard navigation, visible focus indicators,
  and suitable contrast.
- Responsive design: support desktop, tablet, and mobile layouts.

Avoid unnecessary dependencies, premature abstractions, and architectural
complexity.

## 2. Mandatory Repository Inspection

Before editing any file:

1. Identify the repository root and current Git branch.
2. Inspect the Git working tree. Preserve all existing user changes and
   uncommitted Phase 17/18 backend work.
3. Review the existing documentation, including `BLUEPRINT.md`, frontend
   documentation, API handoff documentation, and relevant architecture notes if
   present.
4. Inspect `composer.json`, Laravel routes, middleware, controllers, API
   Resources, request validation, authentication configuration, company context
   handling, authorization, and relevant tests.
5. Verify which API endpoints actually exist. Do not infer endpoints from the
   documentation alone.
6. Check whether a frontend application or partial frontend already exists.
7. Record the existing Node.js/package-manager setup if present.
8. Review pagination response shapes and error-response formats from the actual
   implementation.
9. Inspect how the backend represents decimal values, dates, timestamps,
   currencies, and nullable fields.
10. Identify any API contract gaps that could affect frontend development.

Do not overwrite existing files or scaffold a second frontend inside an existing
frontend project.

If `docs/FRONTEND_API_HANDOFF.md` is missing, do not create assumptions about
its contents. Reconstruct the relevant contract from the Laravel implementation
and document any uncertainty.

## 3. Verify the Backend Integration Contract

Document the following using evidence from the existing code.

### Authentication

- Login and logout endpoints, if implemented.
- Token format, expiration, refresh, and revocation behavior.
- Expected authentication headers.
- Current-user endpoint and available user fields.
- Password recovery endpoints, if implemented.
- Recommended secure token/session storage approach for the actual
  authentication mechanism.

Do not store sensitive tokens in localStorage by default. Evaluate the existing
JWT flow and document a secure approach compatible with the current backend. Do
not introduce a backend authentication redesign in this phase.

### Company Context and Permissions

- How the frontend obtains the user's companies.
- How a default company is determined.
- How the frontend sends `X-Company-Id`.
- What happens when company context is missing or invalid.
- How backend authorization errors are returned.
- How role and permission information is exposed to the frontend.

The frontend may use permissions to control navigation and presentation, but
backend authorization remains authoritative.

### API Conventions

Record:

- API base path and versioning, if any.
- Request and response JSON shapes.
- Validation error format.
- Authentication and authorization error formats.
- Not-found and conflict responses.
- Pagination formats.
- Date, timezone, currency, and decimal representations.

Inspect the actual implementation rather than assuming standard Laravel response
formats.

### Existing Accounting Endpoints

Inventory the implemented endpoints relevant to:

- Company and system settings.
- Chart of accounts.
- Journal listing, creation, and posting.
- Accounting periods and period closing.
- General ledger and account statements.
- Trial balance.
- Profit and loss.
- Balance sheet and other financial reports.
- Financial dimensions.
- Budgets and budget variance.

Only document endpoints that actually exist, marking incomplete or unverified
functionality clearly.

## 4. Important Pagination Compatibility Note

The latest backend audit identified that some paginated list endpoints may omit
standard Laravel pagination metadata such as `links` and `meta`.

Verify the current implementation.

Document the actual response formats and design the future frontend API client
to accommodate the confirmed contract. Where practical, plan a normalized
frontend pagination adapter that can support both the existing response and a
standard paginator response.

Do not silently assume pagination metadata exists. Do not redesign backend
responses or make unrelated backend changes during this phase.

## 5. Frontend Architecture Proposal

Prepare a practical architecture for the existing repository. Prefer a single
Next.js application using the App Router unless the existing repository provides
a strong reason otherwise.

The plan should cover:

- App Router and route-group organization.
- Public, authentication, and protected application routes.
- Shared application layout.
- Reusable UI components.
- Feature-oriented organization for accounting modules.
- API client and typed response handling.
- Authentication and company-context integration.
- Centralized error handling.
- Loading, empty, success, and failure states.
- Form validation strategy.
- Table, filtering, sorting, and pagination patterns.
- Chart integration.
- Theme management.
- Responsive layout and accessibility.
- Testing strategy.
- Environment variable conventions.
- Build, lint, type-check, and test commands.

Propose a structure that is easy to maintain. Do not create every future feature
folder or install dependencies prematurely.

## 6. Design System Specification

Document the initial design tokens for Light Mode and Dark Mode.

Suggested Light Mode tokens:

- Primary green: `#2E7D32`
- Deep green: `#1B5E20`
- Subtle green background: `#E8F5E9`
- App background: `#F7F9F7`
- Card background: `#FFFFFF`
- Border: `#E5EAE5`
- Main text: `#26332B`
- Secondary text: `#647067`

Suggested Dark Mode tokens:

- App background: `#111713`
- Card background: `#1B241D`
- Elevated surface/border: `#2A382D`
- Primary green accent: `#81C784`
- Main text: `#E5EEE6`

Define semantic tokens for success, warning, error, and informational states
separately from the brand palette.

Treat these values as initial design tokens to validate for contrast and
usability, not as permission to hardcode colors across future components.

Use CSS variables or another maintainable theme-token strategy compatible with
the installed Tailwind version. Verify the actual Tailwind version before
proposing version-specific configuration.

## 7. Dashboard Visualization Plan

Document the intended graphical dashboard without implementing it yet.

Plan for:

- Financial KPI cards.
- Income versus expenses chart.
- Cash flow trend.
- Expense breakdown.
- Profitability trend.
- Receivables and payables.
- Budget versus actual.
- Financial alerts.
- Company and date/fiscal-period filters.
- Drill-down navigation to relevant reports.

Distinguish accrual-based profit and loss from actual cash flow. Specify that
dashboard figures must come from existing backend APIs or explicitly identified
future API requirements.

Do not invent API endpoints or use permanent hardcoded sample values as
production data.

## 8. Security and Financial Integrity

Document safeguards for:

- Protected routes and session expiry.
- Cross-company data isolation.
- Permission-aware navigation.
- Server-side authorization.
- Safe handling of authentication tokens.
- Decimal and currency display.
- Date and timezone conversion.
- Preventing duplicate financial submissions.
- Handling failed or ambiguous posting requests.
- Avoiding optimistic updates for irreversible accounting operations unless the
  backend contract explicitly supports them.

The frontend must never independently post journal entries, determine
authoritative ledger balances, or bypass the backend posting engine.

## 9. Deliverables

Create or update only the necessary documentation after inspection.

Recommended deliverables:

1. `docs/FRONTEND_PHASE_0_AUDIT.md`
   - Repository findings.
   - Existing frontend/backend status.
   - Verified API contract.
   - Authentication and company-context details.
   - Pagination and error-response findings.
   - Integration risks and unresolved questions.

2. `docs/FRONTEND_BLUEPRINT.md`
   - Frontend architecture.
   - Proposed route and folder structure.
   - API client conventions.
   - Design tokens and theme approach.
   - Component strategy.
   - Testing and quality gates.
   - Graphical dashboard plan.
   - Ordered implementation roadmap.

3. `docs/FRONTEND_PHASE_0_REPORT.md`
   - Summary of inspection and documentation changes.
   - Files created or updated.
   - Commands/checks performed and their results.
   - Confirmed API limitations.
   - Decisions that need user approval.
   - Exact recommended next step.

If suitable documentation already exists, update it carefully instead of
creating duplicates. Do not overwrite useful project documentation.

## 10. Scope Restrictions

- Do not implement frontend authentication screens.
- Do not implement dashboard charts or accounting screens.
- Do not modify accounting business logic.
- Do not modify existing migrations, database schema, or backend API contracts.
- Do not introduce new authentication, state-management, or UI libraries without
  justification.
- Do not run database migrations, seeders, destructive commands, or data-reset
  commands.
- Do not commit, reset, stash, or discard user changes.
- Do not reformat unrelated backend files.
- Do not claim that an endpoint or feature exists without verifying it.
- Do not fix unrelated bugs in this phase; document them for review.

If a blocking issue prevents an accurate architecture decision, document the
issue and ask for approval rather than making a major architectural change.

## 11. Quality and Completion Criteria

Phase 0 is complete when:

- The repository and current working tree have been inspected.
- Existing frontend/backend architecture has been accurately documented.
- Relevant API endpoints and response contracts have been verified against code.
- Authentication and company-context integration are understood or explicitly
  flagged as unresolved.
- Pagination compatibility has been investigated.
- The frontend architecture and design system are documented.
- The graphical dashboard has an API-driven implementation plan.
- No existing user changes have been lost.
- No unrelated backend behavior has changed.
- The final report clearly separates verified facts, recommendations, and
  unresolved questions.

At the end, provide a concise Phase 0 report with findings, deliverables,
validation results, blockers, and the next recommended phase.

**Stop after Phase 0. Wait for review and approval before implementing Frontend
Phase 1.**
