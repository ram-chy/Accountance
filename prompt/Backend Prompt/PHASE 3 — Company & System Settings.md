# PHASE 3 — Company & System Settings

## Objective

Implement the **Company & System Settings foundation** for the Accounting Web
Application.

The application must become **company-context ready** so that future accounting,
customers, suppliers, invoices, purchases, payments, expenses, banking, tax, and
reporting data can be associated with the correct company.

Keep the implementation simple and production-oriented.

Do not implement accounting modules in this phase.

---

# 1. Project Context

This is a fresh Laravel 13 REST API backend.

Current architecture:

- Laravel 13
- PHP 8.4+
- MySQL 8.4 LTS
- REST API
- JWT authentication
- React/Next.js frontend will be implemented later
- Accrual accounting
- Double-entry accounting
- Multi-company architecture-ready
- Multi-currency architecture-ready
- Server-side authorization
- Auditability
- Posted financial records will eventually be immutable

Phase 2 is already complete:

- Authentication
- JWT
- Users
- Roles
- Permissions
- Password recovery
- Authorization foundation

Do not redo Phase 2 unless a genuine dependency or security defect is
discovered.

---

# 2. Phase Boundary

This phase implements only:

- Companies
- User/company association
- Company context
- Company settings
- Basic system preferences
- Company-level authorization
- Company API
- Settings API
- Tests
- Security review
- Phase report

Do NOT implement:

- Chart of Accounts
- Journals
- Journal Lines
- General Ledger
- Trial Balance
- Financial Statements
- Customers
- Suppliers
- Items
- Sales
- Purchases
- Payments
- Expenses
- Banking
- Tax Engine
- Fixed Assets
- Multi-currency transaction processing
- Dashboard
- Reports
- PDF generation
- Frontend

---

# 3. First Step — Inspect Existing Project

Before changing anything:

1. Inspect the existing Laravel project.
2. Read the existing documentation.
3. Read the Phase 2 implementation and report.
4. Inspect:
   - User model
   - authentication implementation
   - roles
   - permissions
   - middleware
   - API route structure
   - exception handling
   - response conventions
   - migrations
   - tests

5. Do not rewrite working authentication code.

Use the existing architecture where appropriate.

Do not introduce a second authentication or authorization system.

---

# 4. Company Model

Create a `companies` table.

Recommended fields:

```text
id
name
legal_name
registration_number nullable
tax_number nullable
email nullable
phone nullable
website nullable
address_line_1 nullable
address_line_2 nullable
city nullable
state nullable
postal_code nullable
country_code
currency_id nullable
timezone
date_format
is_active
created_at
updated_at
```

Use appropriate Laravel/MySQL types.

Requirements:

- `name` required
- `legal_name` nullable
- `country_code` should use a suitable standard representation
- `timezone` should store an IANA timezone identifier
- `is_active` defaults to true
- avoid unnecessary company fields
- use indexes where appropriate

Do not store monetary values in this table.

---

# 5. Company/User Relationship

The system must support users belonging to companies.

Design this carefully because the application is intended to be **multi-company
ready**.

Preferred architecture:

```text
users
  |
  | many-to-many
  |
companies
```

Create a pivot table such as:

```text
company_user
```

Recommended fields:

```text
id
company_id
user_id
is_default
created_at
updated_at
```

Requirements:

- foreign keys
- appropriate unique constraint
- prevent duplicate user/company associations
- only one default company per user should be allowed logically
- company membership must be validated server-side

Do not assume every user belongs to exactly one company.

Do not introduce complicated tenant infrastructure at this stage.

---

# 6. Company Context

Introduce a simple, centralized company-context mechanism.

The authenticated request must be able to determine the active company.

The implementation should make future business modules able to do something
conceptually like:

```php
$companyId = $companyContext->id();
```

or an equivalent clean abstraction.

Requirements:

- active company must belong to the authenticated user
- inactive companies cannot be selected
- unauthenticated users cannot access company context
- invalid company IDs must be rejected
- users must not be able to access another user's company
- company context must not rely on frontend trust

Avoid global/static state that can leak between requests.

Prefer request-scoped/context-safe implementation.

---

# 7. Company Selection

Implement an API mechanism for selecting the active company.

For example:

```http
GET /api/companies
```

Returns companies available to the authenticated user.

Implement a suitable endpoint for changing the active company.

For example:

```http
POST /api/companies/{company}/switch
```

The exact route design may be adjusted if the existing API conventions suggest a
better structure.

Requirements:

- user can only switch to a company they belong to
- company must be active
- unauthorized company access returns an appropriate response
- switching company must not modify another user's membership
- active company selection should be deterministic

Consider whether active company should be stored:

1. in the user's session/token context, or
2. explicitly supplied through a request mechanism.

Choose the simplest secure approach compatible with the existing JWT
implementation.

Document the decision.

Do not put sensitive company authorization logic in frontend code.

---

# 8. Default Company

Support a user's default company.

Rules:

- user may have multiple companies
- only one company should be the user's default
- default company must belong to the user
- inactive company cannot remain the active/default company
- if a default company is deactivated, handle the state safely
- changing the default company must validate membership

Do not automatically create fake companies.

---

# 9. Company CRUD

Implement company management according to the existing role/permission system.

At minimum support:

```text
Create Company
View Companies
View Company
Update Company
Activate/Deactivate Company
```

Do not implement destructive company deletion unless there is a strong
architectural reason.

Because future accounting records will reference companies, prefer:

```text
is_active = false
```

over hard deletion.

If deletion is implemented at all, protect referenced companies and return a
clear user-facing error.

---

# 10. Company Authorization

Company-level access must be enforced server-side.

Example:

A user belonging to:

```text
Company A
Company B
```

must never be able to request:

```text
Company C
```

simply by changing an ID in the URL.

Every company-scoped operation must verify:

```text
Authenticated User
        ↓
Company Membership
        ↓
Company Active Status
        ↓
Role/Permission
        ↓
Allowed Action
```

Do not rely only on:

```text
role middleware
```

because role authorization alone does not establish company membership.

---

# 11. Company Settings

Create a clean settings foundation.

Avoid creating dozens of individual columns unless they are genuinely needed.

A practical design may use:

```text
company_settings
```

with fields such as:

```text
id
company_id
setting_key
setting_value
created_at
updated_at
```

However, evaluate whether structured columns are more appropriate for strongly
typed settings.

Do not blindly use a JSON blob for everything.

Settings should support future configuration such as:

```text
invoice numbering
quotation numbering
default payment terms
default tax settings
financial year configuration
document preferences
notification preferences
```

Do not implement those future modules yet.

For this phase, implement only the basic settings required by the current system
foundation.

---

# 12. Basic System Preferences

Support company-level preferences needed by later phases.

At minimum consider:

### Locale

```text
country_code
timezone
date_format
```

### Currency foundation

A company may have a default currency reference.

If the existing project does not yet contain a currency table, do NOT build the
complete currency system in this phase.

Instead:

- create a clean nullable `currency_id` relationship only if architecturally
  appropriate, OR
- document that currency configuration will be completed in the dedicated Fiscal
  Period & Currency phase.

Do not duplicate the future currency engine here.

---

# 13. Validation

Create proper Form Requests / validation classes.

Validate:

- company name
- legal name
- email
- phone
- website
- country code
- timezone
- date format
- company membership
- active/inactive state
- default company rules

Do not trust frontend validation.

All important validation must happen server-side.

---

# 14. API Design

Follow the API response convention established in Phase 1/2.

Provide endpoints appropriate to the implementation.

Expected conceptual API:

```text
GET    /api/companies
POST   /api/companies
GET    /api/companies/{company}
PUT    /api/companies/{company}
POST   /api/companies/{company}/activate
POST   /api/companies/{company}/deactivate
POST   /api/companies/{company}/switch
GET    /api/company
GET    /api/company/settings
PUT    /api/company/settings
```

Do not blindly create duplicate endpoints.

Use REST conventions where appropriate.

Document the final routes in the phase report.

---

# 15. API Resources

Use Laravel API Resources or the project's established response approach.

Do not expose:

- passwords
- password hashes
- internal authorization details unnecessarily
- sensitive system fields

Company responses should contain only appropriate public/application data.

---

# 16. Database Integrity

Use foreign keys and appropriate indexes.

Important relationships:

```text
company_user.company_id → companies.id
company_user.user_id → users.id
```

If settings are implemented:

```text
company_settings.company_id → companies.id
```

Prevent orphan records.

Use unique constraints where appropriate.

Use appropriate `ON DELETE` behavior.

Do not cascade-delete important future accounting data accidentally.

---

# 17. Security Requirements

Perform a security review specifically for:

### Authorization

Test that:

- User A cannot access User B's company
- User cannot switch to an unrelated company
- inactive companies cannot be accessed as active context
- role permissions are enforced
- direct ID manipulation does not bypass membership

### Mass Assignment

Protect sensitive fields using:

```text
fillable
```

or equivalent safe mechanisms.

Do not allow users to assign:

```text
company ownership
membership
role
permission
is_active
```

through uncontrolled request payloads.

### IDOR Protection

Explicitly test for insecure direct object references.

For example:

```http
GET /api/companies/999
```

must not expose a company merely because the ID exists.

### Authentication

Company endpoints require authenticated users.

---

# 18. Role Rules

Use the roles created in Phase 2.

Do not create a second role system.

Use the existing permission architecture.

A reasonable initial policy is:

### Admin

Full company management.

### Manager

Company information/settings management only where explicitly permitted.

### Accountant

Access to company information needed for accounting work.

### Staff

Only company information required for normal operational work.

Do not grant broad permissions simply because a role exists.

If exact permissions are uncertain, inspect the existing Phase 2 permission
model and implement the minimum necessary permissions.

Document the final permission mapping.

---

# 19. Model Relationships

Implement appropriate relationships.

Examples:

```php
User::companies()
Company::users()
Company::settings()
```

If applicable:

```php
Company::defaultCurrency()
```

Keep models clean.

Do not put large business workflows inside models.

---

# 20. Service Layer

If the existing architecture uses services, create a focused service such as:

```text
CompanyService
CompanyContext
CompanySettingsService
```

Do not create unnecessary abstraction layers.

Responsibilities should remain clear:

### CompanyService

- create company
- update company
- activate/deactivate
- membership-related operations

### CompanyContext

- resolve active company
- validate membership
- provide current company

### CompanySettingsService

- retrieve settings
- update settings
- validate settings

Keep the implementation simple.

---

# 21. Events / Observability

Do not add an event system unless genuinely useful.

If appropriate, company creation/deactivation can later become auditable events.

Do not implement a complete audit-log system yet.

That belongs to the dedicated audit/control phase.

---

# 22. Testing Requirements

Create comprehensive tests.

At minimum test:

### Company

- authenticated user can create company
- unauthenticated user cannot create company
- company validation works
- authorized user can view company
- authorized user can update company
- unauthorized user cannot access company
- company can be deactivated
- inactive company cannot be selected

### Membership

- user can belong to multiple companies
- duplicate membership is prevented
- user cannot access unrelated company
- membership validation works

### Default Company

- default company can be selected
- only one default company exists per user
- default company must belong to user
- inactive company cannot become default

### Company Context

- valid active company resolves correctly
- unrelated company is rejected
- inactive company is rejected
- missing company context behaves correctly

### Settings

- settings can be retrieved
- authorized user can update settings
- unauthorized user cannot update settings
- invalid settings are rejected

### Security

Test IDOR scenarios explicitly.

Example:

```text
User A → Company A
User B → Company B

User A requests Company B
→ must be rejected
```

---

# 23. Database Tests

Verify:

- migrations run successfully
- foreign keys work
- unique constraints work
- rollback works
- fresh migration works

Do not use production data.

Do not create seeders.

---

# 24. No Seeders Without Permission

Do NOT run or create seeders unless explicitly instructed.

Do not create:

- demo companies
- demo users
- demo settings
- fake accounting data

Tests may use factories where appropriate.

---

# 25. Documentation

Update documentation only where necessary.

Create:

```text
docs/reports/PHASE_3_REPORT.md
```

The report must contain:

## 1. Phase Overview

## 2. Implemented Features

## 3. Database Changes

## 4. API Endpoints

## 5. Authorization Rules

## 6. Company Context Design

## 7. Settings Design

## 8. Security Review

## 9. Tests Executed

Include exact results:

```text
Tests: X passed
Assertions: Y
```

## 10. Known Limitations

## 11. Architectural Decisions

## 12. Files Changed

## 13. Phase Status

Use exactly one:

```text
PASS
PASS WITH NOTES
BLOCKED
```

---

# 26. Git

If Git is already initialized:

- inspect current status
- do not destroy existing history
- do not reset unrelated changes
- do not push

If Git is not initialized and project rules allow it:

```bash
git init
```

Do not create a remote repository.

Do not push anything.

---

# 27. Code Quality

Follow Laravel conventions.

Requirements:

- clean naming
- strict validation
- meaningful classes
- no duplicated authorization logic where a reusable policy/middleware is
  appropriate
- no dead code
- no debug statements
- no unnecessary dependencies
- no unrelated refactoring

Do not rewrite working Phase 1/2 code simply for stylistic reasons.

---

# 28. Important Architectural Rule

Do not prematurely implement future accounting requirements.

This phase must prepare the application for:

```text
Company
   ↓
Accounting
   ↓
Transactions
   ↓
Journal
   ↓
Ledger
   ↓
Reports
```

But only the **Company layer** is being implemented now.

Do not create fake accounting tables merely because future phases will need
them.

---

# 29. Final Verification

Before declaring the phase complete:

Run the appropriate checks, including:

```bash
php artisan migrate:fresh
php artisan test
```

Only run `migrate:fresh` if doing so is safe for the current development
environment.

Do not destroy user data without explicit permission.

Also verify:

```bash
php artisan route:list
php artisan config:clear
php artisan cache:clear
```

Use only commands appropriate to the existing environment.

If frontend does not exist yet, do not create it.

---

# 30. Definition of Done

Phase 3 is complete only when:

- [ ] Companies implemented
- [ ] User/company membership implemented
- [ ] Company context implemented
- [ ] Default company supported
- [ ] Company activation/deactivation implemented
- [ ] Company authorization implemented
- [ ] Basic company settings implemented
- [ ] Validation implemented
- [ ] API endpoints implemented
- [ ] Policies/permissions integrated with Phase 2
- [ ] IDOR protection tested
- [ ] Database constraints tested
- [ ] Authentication integration verified
- [ ] No seeders created/run without permission
- [ ] No future accounting modules implemented
- [ ] Tests pass
- [ ] Security review completed
- [ ] `docs/reports/PHASE_3_REPORT.md` created
- [ ] Final phase status recorded

---

# 31. STOP CONDITION

After completing Phase 3:

**STOP.**

Do not start Phase 4 automatically.

Do not implement Chart of Accounts.

Do not implement journals.

Do not implement accounting transactions.

Wait for explicit approval to continue to:

**PHASE 4 — Accounting Foundation**
