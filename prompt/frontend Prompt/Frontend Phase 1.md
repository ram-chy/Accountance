# Accountance Accounting Web App

# Frontend Phase 1 — Foundation Implementation

## Objective

Implement Frontend Phase 1 using the architecture and verified findings from
Frontend Phase 0.

Phase 0 is complete. Read and follow its audit, blueprint, and report before
making changes.

The goal is a working, maintainable Next.js frontend foundation ready for
authentication and company-context integration in Phase 2.

**Implement Phase 1 only. Do not proceed to Phase 2 or build the accounting
dashboard.**

## 1. Mandatory Pre-Implementation Review

Before changing any files:

1. Inspect the current repository root and Git branch.
2. Inspect `git status --short` and preserve every existing user change,
   including uncommitted backend work.
3. Read `docs/FRONTEND_PHASE_0_AUDIT.md`, `docs/FRONTEND_BLUEPRINT.md`, and
   `docs/FRONTEND_PHASE_0_REPORT.md` if present.
4. Inspect the existing frontend files, package manifest, lockfile, installed
   framework versions, and scripts.
5. Review the backend API conventions documented in Phase 0.
6. Verify the existing Tailwind CSS version and follow its supported
   configuration conventions.
7. Check whether any components, styles, API clients, theme utilities, or shared
   types already exist.

If a documented path differs from the repository, locate the actual file and use
the existing structure.

Do not scaffold a duplicate application or replace working configuration just to
match a preferred template.

If a genuine blocker makes implementation unsafe, document it and ask for
clarification before making an architectural change.

## 2. Locked Technology Decisions

Follow the existing repository and Phase 0 blueprint.

Expected stack:

- Next.js with App Router.
- React.
- TypeScript in strict mode where compatible with the existing project.
- Tailwind CSS using the installed major version.
- CSS variables or equivalent design tokens for theme values.
- Recharts reserved for dashboard implementation in a later phase.
- ESLint and the existing supported formatting/testing tools.

Do not introduce a competing CSS framework, a second router, or unnecessary
state-management libraries.

Do not upgrade major dependencies unless required and explicitly justified.
Preserve the existing package manager and lockfile.

## 3. Application Structure

Implement or refine a small, clear structure consistent with Phase 0.

The architecture should support:

- App Router layouts and pages.
- Shared UI primitives.
- Design tokens and global styles.
- API client and response types.
- Shared error and pagination utilities.
- Date, currency, and decimal formatting utilities.
- Theme management.
- Feature-specific modules added in later phases.
- Unit/component tests if the repository already has a compatible test setup.

Avoid creating empty folders for every future feature. Introduce only the files
needed for a functional Phase 1 foundation.

Keep server and client components appropriately separated. Use client components
only when browser-side interaction or React state is required.

## 4. Application Entry Page

Create a simple, professional foundation page that confirms the frontend is
running.

The page should:

- Use the Accountance brand identity.
- Show a concise application title and a brief foundation-status message.
- Demonstrate the shared typography, surfaces, and button styles.
- Provide a working Light/Dark Mode switch.
- Remain responsive and accessible.

Do not implement login, registration, company switching, dashboard KPIs, charts,
financial tables, or accounting workflows in this phase.

Do not present hardcoded financial figures as real company data.

The entry page is a foundation preview, not the final application dashboard.

## 5. Design System

Implement reusable design tokens rather than scattering color literals across
components.

Use the following initial Light Mode palette, subject to accessible contrast
checks:

- Primary green: `#2E7D32`
- Deep green: `#1B5E20`
- Subtle green surface: `#E8F5E9`
- Application background: `#F7F9F7`
- Card background: `#FFFFFF`
- Border: `#E5EAE5`
- Primary text: `#26332B`
- Secondary text: `#647067`

Initial Dark Mode palette:

- Application background: `#111713`
- Card background: `#1B241D`
- Elevated surface and border: `#2A382D`
- Primary green accent: `#81C784`
- Primary text: `#E5EEE6`

Create semantic tokens for:

- Success.
- Warning.
- Error.
- Information.
- Disabled controls.
- Focus indicators.

Requirements:

- Use semantic theme variables for component colors.
- Avoid hardcoding theme-specific colors in every component.
- Keep the green accent restrained and professional.
- Maintain clear text contrast and visible focus states.
- Use consistent spacing, radius, border, and typography conventions.
- Avoid excessive gradients, shadows, animation, or decorative elements.

## 6. Theme Support

Implement working Light and Dark Mode.

Requirements:

1. Use the theme strategy recommended by Phase 0.
2. Support explicit user switching.
3. Prevent or minimize a visible theme flash on initial render where practical.
4. Avoid hydration mismatches.
5. Respect system preference if the documented strategy calls for it.
6. Keep all shared components consistent with the active theme.
7. Make the theme control keyboard accessible and provide an accessible name.
8. Use a suitable icon only if the existing dependencies support it; do not add
   a large icon library unnecessarily.

Do not introduce insecure storage or unrelated persistence mechanisms.

If a theme library is justified by Phase 0, use it consistently; otherwise
prefer a lightweight solution.

## 7. Reusable UI Components

Create a small, reusable component foundation where useful:

- Button with sensible variants and disabled/loading support if needed.
- Card or surface component.
- Input and label styling.
- Badge/status indicator.
- Loading indicator.
- Empty-state presentation.
- Error-state presentation.

Do not build a large component framework. Use native semantic elements where
sufficient.

Components must support keyboard navigation, visible focus indicators,
accessible labels, and appropriate disabled states.

Avoid unnecessary abstractions or duplicated styling logic.

## 8. API Client Foundation

Implement the API client based on the actual API contract discovered in Phase 0.

Requirements:

- Configure the API base URL using a documented public environment variable
  appropriate for browser requests, such as `NEXT_PUBLIC_API_BASE_URL`, if that
  matches the verified API setup.
- Add an `.env.example` containing placeholder values only.
- Do not commit credentials, private keys, JWTs, or real secrets.
- Keep API configuration centralized.
- Support JSON requests and responses where appropriate.
- Handle empty responses and non-JSON error responses safely.
- Normalize network errors and backend error responses into a predictable
  frontend error type.
- Preserve useful HTTP status codes and validation error details.
- Avoid logging tokens, credentials, or sensitive financial payloads.
- Make it possible to add authentication and company-context headers in Phase 2
  without rewriting the client.
- Do not invent authentication endpoints or assume token storage behavior.
- Do not automatically retry financial write operations.

If the existing backend contract supports pagination, implement a small adapter
that accepts the verified response shape. Where Phase 0 identified missing
`links` or `meta`, tolerate the confirmed legacy format without fabricating page
counts or totals.

Do not issue real accounting mutations from the foundation page.

Document the required environment variables and how to run the frontend locally.

## 9. Financial and Date Utilities

Add only small utilities justified by Phase 0.

### Financial values

- Treat API monetary values as decimal strings when supplied that way.
- Do not use JavaScript `Number` arithmetic to calculate authoritative monetary
  balances.
- Do not calculate profit, account balances, trial balance, or journal totals in
  the frontend.
- Use safe display formatting for known decimal strings.
- If accurate arithmetic is necessary for a reusable helper, use a justified
  decimal-safe approach rather than binary floating-point arithmetic.

### Dates

- Follow the backend date/time contract.
- Handle ISO date-only values separately from timestamps where necessary.
- Avoid unintended date shifts caused by parsing date-only values as UTC
  timestamps.
- Use explicit locale and timezone settings when required by the application
  contract.
- Do not assume the user's timezone or company's timezone is always the same.

Do not add unnecessary utility libraries for trivial tasks.

## 10. Responsive and Accessible UI

Ensure the foundation works on:

- Desktop.
- Tablet.
- Mobile.

Requirements:

- Semantic HTML.
- Keyboard-operable controls.
- Visible focus indicators.
- Accessible form labels.
- Appropriate contrast in both themes.
- No horizontal overflow at common viewport sizes.
- Respect reduced-motion preferences where animations are used.
- No color-only communication for status.

## 11. Testing and Validation

Use the project's existing scripts and test tools.

Run the available checks, such as:

- Lint.
- TypeScript type-check.
- Existing unit/component tests relevant to modified files.
- Production build.

Do not claim a check passed unless it was actually executed successfully.

If a check cannot run, record the exact reason and command.

Fix issues introduced by Phase 1 without modifying unrelated backend
functionality.

Do not run seeders, migrations, database resets, or destructive commands.

Do not commit, reset, stash, or discard changes.

## 12. Documentation Updates

Update only relevant documentation:

- `docs/FRONTEND_BLUEPRINT.md` with the implemented foundation, actual folder
  structure, design tokens, theme strategy, API client conventions, and
  next-phase integration points.
- A new `docs/FRONTEND_PHASE_1_REPORT.md` containing implementation details,
  changed files, dependency decisions, validation results, known limitations,
  and recommended next steps.

If the blueprint does not exist at that path, follow the actual repository
conventions and report the location used.

Keep documentation aligned with the code that actually exists. Do not document
unimplemented features as completed.

## 13. Explicit Scope Restrictions

Do not:

- Implement authentication screens or flows.
- Implement company selection.
- Build the graphical dashboard.
- Implement accounting modules or financial reports.
- Change Laravel accounting logic or database schema.
- Redesign the backend API.
- Add mock financial data to production components.
- Replace the package manager or lockfile without a compelling reason.
- Install unnecessary dependencies.
- Run seeders, migrations, or database reset commands.
- Overwrite Phase 0 documentation with unrelated content.
- Delete or discard existing user changes.
- Commit changes.
- Proceed to Frontend Phase 2.

## 14. Completion Criteria

Phase 1 is complete when:

- The frontend foundation runs using the repository's documented setup.
- The entry page renders successfully.
- Light and Dark Mode work.
- Shared design tokens and core UI components are implemented.
- API configuration and error handling are in place.
- Pagination compatibility follows the verified API contract.
- Financial and date utilities respect the documented backend formats.
- Responsive and accessibility basics are addressed.
- Applicable lint, type-check, test, and build commands have been executed and
  reported.
- Phase 0 architecture and backend behavior remain intact.
- `docs/FRONTEND_PHASE_1_REPORT.md` accurately records the result.

At the end, provide a concise report with:

1. Implemented features.
2. Important files created or changed.
3. Dependencies added, if any, and why.
4. Commands run and their results.
5. Known limitations or unresolved issues.
6. Exact commands to run the frontend locally.
7. Recommended next step: Frontend Phase 2 — Authentication and Company Context.

**Stop after Phase 1. Wait for review and approval before starting Phase 2.**
