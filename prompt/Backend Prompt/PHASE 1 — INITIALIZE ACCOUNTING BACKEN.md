# PHASE 1 — INITIALIZE ACCOUNTING BACKEND PROJECT

## Project

Accounting Web Application

## Phase

Phase 1 — Backend Project Initialization

---

# 1. Objective

Initialize a completely new Laravel backend project for the Accounting Web
Application.

This is a **fresh project**.

There is no existing Laravel application to preserve.

The goal of this phase is to create a clean, stable Laravel API foundation that
will later support:

- Authentication
- Users and Roles
- Company Management
- Chart of Accounts
- Double-entry Accounting
- Journal and Posting Engine
- Customers
- Suppliers
- Sales
- Purchases
- Payments
- Expenses
- Cash and Bank
- Financial Reports
- Tax Engine
- Multi-Currency
- Dashboard APIs

Do **not** implement those business modules in this phase.

---

# 2. Critical Working Rules

OpenCode must follow these rules throughout the phase:

1. This is a brand-new project.
2. Do not assume existing application code exists.
3. Use the approved technology stack.
4. Do not over-engineer the initial project.
5. Do not install unnecessary packages.
6. Do not create future business modules prematurely.
7. Do not create fake accounting data.
8. Do not run seeders unless explicitly authorized.
9. Internet access is allowed when required for verifying official
   documentation.
10. Do not hurry.
11. Verify every important configuration after implementation.
12. Use Laravel conventions wherever practical.
13. Do not introduce unnecessary architectural patterns.
14. Keep the project easy for a single developer to maintain.
15. Do not make architectural decisions that conflict with the master project
    blueprint.

---

# 3. Approved Technology Stack

## Backend

Use:

- Laravel 13
- PHP 8.4+
- MySQL 8.4 LTS
- InnoDB
- REST API
- JWT authentication architecture
- PHPUnit/Pest according to the Laravel project setup

## Database

Use:

- MySQL
- InnoDB
- utf8mb4
- appropriate UTF-8 collation
- proper foreign keys
- proper indexes

Financial values must use:

```text
DECIMAL
```

Never use:

```text
FLOAT
DOUBLE
```

for monetary values.

---

# 4. Create the Laravel Project

Initialize a new Laravel 13 project using the official Laravel installation
method.

The project should be created as the backend application.

Recommended structure:

```text
accounting-app/
└── backend/
    └── Laravel application
```

If the parent directory does not exist, create it.

Do not create the Next.js frontend yet.

The frontend will be initialized separately in a later phase.

---

# 5. Laravel API Configuration

Configure the Laravel application as an API backend.

The backend will eventually serve:

```text
Next.js Frontend
        ↓
Laravel REST API
        ↓
MySQL
```

Use an appropriate API prefix.

Prefer:

```text
/api
```

If API versioning is part of the approved architecture, use:

```text
/api/v1
```

Do not create dozens of empty routes.

Only create the minimum API foundation required for this phase.

---

# 6. Environment Configuration

Configure:

```text
.env
.env.example
```

The `.env` file must contain local development configuration.

The `.env.example` file must contain safe placeholders and must never contain
real credentials.

Configure at minimum:

```text
APP_NAME="Accounting Web App"

APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

Use the actual Laravel-generated application key.

Do not commit secrets.

Verify `.gitignore` correctly excludes `.env`.

---

# 7. MySQL Database

Prepare the project for MySQL 8.4 LTS.

Use:

```text
ENGINE=InnoDB
```

where applicable.

Use:

```text
utf8mb4
```

for database/table character encoding.

Verify that Laravel can connect to MySQL.

Run the initial Laravel migration process.

Do not create accounting tables yet.

Do not create:

- accounts
- journals
- journal_lines
- invoices
- payments
- expenses
- tax tables
- fixed assets

Those belong to later phases.

---

# 8. Basic Laravel Structure

Keep the normal Laravel structure.

The application should eventually use:

```text
app/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
│
├── Models/
├── Services/
├── Policies/
└── Support/
```

Do not create empty abstractions just to make the folders look complete.

Create classes only when they are required.

---

# 9. API Health Endpoint

Create a simple health endpoint.

Preferred:

```text
GET /api/health
```

Response:

```json
{
  "success": true,
  "message": "API is healthy."
}
```

The endpoint must not expose:

- database credentials
- environment variables
- server paths
- secrets
- internal infrastructure details

A database health check may be implemented if useful, but do not expose
technical details in the response.

---

# 10. API Response Convention

For this fresh project, establish a simple consistent API response convention.

Successful response:

```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": {}
}
```

Error response:

```json
{
  "success": false,
  "message": "Something went wrong.",
  "errors": {}
}
```

Validation errors must be understandable to the future Next.js frontend.

Do not expose stack traces or internal exceptions in production responses.

Keep the response structure simple.

Do not build an elaborate response framework.

---

# 11. Exception Handling

Review Laravel's exception handling and ensure API requests receive appropriate
JSON responses.

Development may expose useful debugging information through Laravel's normal
development configuration.

Production responses must not expose:

- stack traces
- SQL statements
- filesystem paths
- credentials
- secrets

Do not replace Laravel's exception system with a custom framework unless there
is a clear requirement.

---

# 12. Database Migration Verification

After configuration:

1. Verify MySQL connectivity.
2. Run migrations.
3. Confirm migrations complete successfully.
4. Confirm Laravel can access the database.
5. Confirm rollback behavior where appropriate.
6. Run migrations again.

Do not run seeders.

No seed data is required in this phase.

---

# 13. Git Initialization

If the project is not already inside a Git repository:

Initialize Git.

Verify that `.gitignore` correctly excludes:

```text
.env
/vendor
/node_modules
```

and other appropriate generated/local files.

Do not create a remote repository.

Do not push anything to GitHub.

The user can do that separately.

---

# 14. Security Baseline

Perform a basic security review.

Verify:

- `.env` is ignored
- application secrets are not committed
- database credentials are not hard-coded
- API responses do not expose secrets
- production debug mode can be disabled
- password/authentication infrastructure is not duplicated
- sensitive values are not logged unnecessarily

Do not claim this is a complete security audit.

The complete security review will happen later.

---

# 15. Accounting Architecture Reminder

The entire project will eventually follow:

```text
Business Transaction
        ↓
Validation
        ↓
Business Calculation
        ↓
Accounting Mapping
        ↓
Journal
        ↓
Journal Lines
        ↓
Debit/Credit Validation
        ↓
POST
        ↓
General Ledger
        ↓
Trial Balance
        ↓
Financial Statements
```

The fundamental accounting rule will be:

```text
TOTAL DEBIT = TOTAL CREDIT
```

Do not implement the accounting engine in Phase 1.

This phase only establishes the Laravel foundation that will support it.

---

# 16. Future Architecture Constraints

The project must remain compatible with:

## Multi-company

The system will eventually support multiple companies.

Do not hard-code the application around a single company.

Company management belongs to a later phase.

## Multi-currency

The system will eventually support multiple currencies.

Do not implement currency conversion in Phase 1.

Do not make database choices that prevent future multi-currency support.

## Audit Trail

Financial operations will eventually require audit information.

Do not implement the complete audit system yet.

## Financial Periods

The system will eventually support:

- Fiscal years
- Open periods
- Closed periods
- Locked periods

Do not implement them yet.

---

# 17. Testing

Create and verify basic tests for the project foundation.

At minimum:

### Application

- Laravel application boots.
- Application configuration loads.

### Database

- MySQL connection works.
- Migrations run successfully.

### API

- `/api/health` returns HTTP 200.
- Health response follows the agreed response format.

### Error Handling

- Invalid API requests return JSON rather than an HTML error page where
  appropriate.

Run the complete test suite.

---

# 18. Do NOT Implement

The following are explicitly out of scope for Phase 1:

```text
Authentication
JWT Login
Registration
Password Recovery
Users
Roles
Permissions
Companies
Chart of Accounts
Account Types
Journal
Journal Lines
Posting Engine
General Ledger
Trial Balance
Profit & Loss
Balance Sheet
Cash Flow
Customers
Suppliers
Items
Sales Invoices
Purchase Bills
Customer Payments
Supplier Payments
Expenses
Cash Accounts
Bank Accounts
Bank Transfers
Tax Engine
GST
TDS
Multi-Currency Engine
Fixed Assets
Dashboard
Dashboard Graphs
PDF Reports
Next.js Frontend
```

Do not implement any of these unless explicitly instructed.

---

# 19. Frontend Direction

The frontend will be implemented later using:

- Next.js
- React
- TypeScript
- Tailwind CSS

The frontend design direction has already been decided.

## Light Mode

The interface should be:

- clean
- minimal
- calm
- easy on the eyes
- green-based
- primarily neutral surfaces
- green used as the primary brand/accent color

Avoid making the entire interface green.

Avoid excessive saturated colors.

## Dark Mode

The interface should be:

- professional
- dark neutral
- comfortable for extended use
- green-accented
- readable
- accessible

The complete frontend design system will be defined in:

```text
docs/FRONTEND_BLUEPRINT.md
```

Do not implement the frontend in Phase 1.

---

# 20. Dashboard Direction

The future dashboard will contain financial KPIs and graphs.

Examples:

```text
Revenue
Expenses
Net Profit
Accounts Receivable
Accounts Payable
Cash
Bank Balance
```

Potential graphs:

```text
Revenue Trend
Expense Trend
Revenue vs Expense
Cash/Bank Trend
Receivables vs Payables
Expense Breakdown
Top Customers
```

However, the dashboard must eventually obtain authoritative figures from backend
reporting APIs.

The frontend must never become the source of truth for accounting calculations.

Do not implement dashboard functionality in Phase 1.

---

# 21. Documentation

At the end of Phase 1, ensure the project is compatible with the master
documentation structure:

```text
docs/
├── BLUEPRINT.md
├── ARCHITECTURE.md
├── ACCOUNTING_RULES.md
├── DATABASE_BLUEPRINT.md
├── API_BLUEPRINT.md
├── FRONTEND_BLUEPRINT.md
└── DEVELOPMENT_RULES.md
```

If these documents have not yet been created, do not invent conflicting
specifications.

Report that they are pending.

---

# 22. Phase 1 Verification Checklist

Before completing Phase 1:

- [ ] Laravel 13 project created
- [ ] PHP version verified
- [ ] MySQL 8.4 connection verified
- [ ] `.env` configured
- [ ] `.env.example` created/verified
- [ ] `.env` excluded from Git
- [ ] Laravel application key generated
- [ ] API structure established
- [ ] `/api/health` works
- [ ] API returns JSON correctly
- [ ] Migrations work
- [ ] Migration rollback verified
- [ ] Git initialized if required
- [ ] No remote repository created
- [ ] No seeders executed
- [ ] No fake data created
- [ ] No accounting modules implemented
- [ ] No frontend implemented
- [ ] Basic security review completed
- [ ] Tests pass
- [ ] Project boots successfully from a clean environment

---

# 23. Required Phase Report

Create:

```text
docs/reports/PHASE_1_REPORT.md
```

The report must contain:

## Project Initialization

- Laravel version
- PHP version
- Database configuration
- Important packages installed

## Structure

- Project directory structure
- API structure

## Configuration

- Environment configuration
- Database configuration

## API

- Health endpoint
- Response convention

## Testing

Report:

- Tests executed
- Tests passed
- Tests failed
- Assertions where available

## Security

List security checks performed.

## Packages

List any additional packages installed and explain why each was necessary.

## Out of Scope

Confirm that future accounting modules were not implemented.

## Issues

List unresolved issues.

## Status

Use exactly one:

```text
PASS
PASS WITH NOTES
BLOCKED
```

Do not report `PASS` if a required verification fails.

---

# 24. Final Acceptance Criteria

Phase 1 is complete when:

1. A fresh Laravel 13 backend project exists.
2. The application starts successfully.
3. PHP compatibility is verified.
4. MySQL 8.4 connectivity works.
5. Laravel migrations work.
6. API foundation exists.
7. `/api/health` works.
8. Environment configuration is safe.
9. Git configuration is correct.
10. Tests pass.
11. No seeders were run without permission.
12. No fake accounting data was created.
13. No future business modules were implemented.
14. No frontend was implemented.
15. Security baseline was reviewed.
16. `docs/reports/PHASE_1_REPORT.md` exists.
17. The project is ready for **Phase 2 — Authentication + Users + Roles**.

---

# 25. Important Final Instruction

This is the first phase of a long-term accounting system.

Do not try to make the application "complete" in this phase.

The goal is a **clean, verified foundation**.

Prioritize:

```text
Correctness
+
Simplicity
+
Maintainability
+
Security
+
Future Accounting Integrity
```

Do not sacrifice the architecture for speed. Do not over-engineer the
architecture before it is needed.
