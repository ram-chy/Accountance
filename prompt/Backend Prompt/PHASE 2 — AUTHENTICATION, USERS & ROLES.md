# PHASE 2 — AUTHENTICATION, USERS & ROLES

## Project

Accounting Web Application

## Phase

Phase 2 — Authentication, Users & Roles

---

# 1. Objective

Implement the complete authentication and user-access foundation for the
Accounting Web Application.

This phase must provide:

- User registration
- Login
- Logout
- JWT authentication
- Current authenticated user
- Password hashing
- Password change
- Password recovery
- OTP/email verification where required by the approved authentication design
- Roles
- Permissions
- Authorization middleware/policies
- Secure protected API endpoints

This phase must establish the authorization foundation required by all future
modules.

Do not implement accounting modules in this phase.

---

# 2. Critical Working Rules

1. Read the Phase 1 implementation and `PHASE_1_REPORT.md` before making
   changes.
2. Do not assume Phase 1 was implemented exactly as planned.
3. Inspect the actual codebase.
4. Preserve the Phase 1 architecture.
5. Do not rewrite working infrastructure unnecessarily.
6. Do not install multiple authentication systems.
7. Use one clearly defined JWT authentication mechanism.
8. Do not store plaintext passwords.
9. Do not store plaintext password-reset secrets unnecessarily.
10. Do not expose JWT secrets through API responses.
11. Do not log passwords, OTPs, tokens, or secrets.
12. Do not run seeders without explicit permission.
13. Do not create fake business/accounting data.
14. Do not implement future business modules.
15. Do not modify unrelated functionality.
16. Use Laravel conventions wherever practical.
17. Keep the architecture simple.
18. Internet access is allowed when official documentation is required.
19. Verify security-sensitive implementation carefully before completion.

---

# 3. Authentication Architecture

The approved authentication architecture is:

```text
Next.js Frontend
       ↓
Laravel REST API
       ↓
JWT Authentication
       ↓
Authenticated User
       ↓
Authorization
       ↓
Protected API
```

JWT is used for API authentication.

Do not introduce:

- session-based authentication as the primary API authentication mechanism
- multiple competing token systems
- unnecessary OAuth infrastructure
- unnecessary third-party authentication providers

unless explicitly required later.

---

# 4. JWT Implementation

Use a mature Laravel-compatible JWT package.

Before installing a package:

1. Check the current Laravel 13 compatibility.
2. Check current official documentation.
3. Verify the package is actively maintained.
4. Use the package's recommended Laravel integration.
5. Configure it correctly for the project.

Do not invent a custom JWT implementation.

The JWT secret/signing configuration must come from environment configuration.

Never hard-code secrets.

---

# 5. User Model

Create the application's user foundation.

The user model should support at minimum:

```text
id
first_name
last_name
email
mobile_no
password
email_verified_at
is_active
remember_token
created_at
updated_at
```

Use appropriate nullable/required rules.

Do not add unnecessary user fields.

The database design must remain extensible for:

- roles
- permissions
- audit information
- company relationships

without prematurely implementing unrelated functionality.

---

# 6. User Registration

Implement secure user registration.

Required fields:

```text
first_name
last_name
email
mobile_no
password
password_confirmation
```

Validation requirements:

- first name required
- last name required
- valid email
- unique email
- mobile number validated appropriately
- password required
- password confirmation required
- password strength must meet the application's security requirements

Never return the user's password in an API response.

Do not automatically expose sensitive authentication information.

---

# 7. Password Security

Use Laravel's secure password hashing facilities.

Never:

```text
MD5
SHA1
plain text
custom reversible encryption
```

for password storage.

Use Laravel's supported password hashing mechanism.

Password fields must never appear in:

- API resources
- logs
- debug output
- exceptions
- audit logs

---

# 8. Login

Implement:

```text
POST /api/auth/login
```

The endpoint should authenticate the user and return the approved JWT response.

The response should provide only what the frontend needs.

For example:

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token": "...",
    "token_type": "Bearer",
    "user": {}
  }
}
```

Do not expose:

- password
- password hash
- JWT secret
- internal authentication configuration

---

# 9. Logout

Implement:

```text
POST /api/auth/logout
```

Invalidate/revoke the current JWT according to the selected JWT package's
recommended implementation.

A logout request must not invalidate every user's session/token unless
explicitly intended.

Return a consistent API response.

---

# 10. Current User

Implement:

```text
GET /api/auth/me
```

Return the authenticated user's safe profile information.

Example:

```json
{
  "success": true,
  "data": {
    "id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "mobile_no": "..."
  }
}
```

Never return:

- password
- password hash
- JWT secret
- sensitive internal fields

---

# 11. Authentication Middleware

Protect authenticated routes with JWT authentication middleware.

The architecture should clearly distinguish:

```text
Public API
```

from:

```text
Authenticated API
```

Example:

```text
Public
/api/auth/register
/api/auth/login

Protected
/api/auth/me
/api/auth/logout
```

Future modules must be protected by default.

Do not accidentally expose business endpoints publicly.

---

# 12. Email Verification

Implement email verification if required by the approved authentication
architecture.

Use Laravel's supported verification mechanisms where appropriate.

The system should distinguish between:

```text
Email verified
Email not verified
```

Do not create a custom verification mechanism when Laravel already provides a
suitable foundation.

Do not block registration unnecessarily unless the approved product requirements
require verification before login.

Document the chosen behavior.

---

# 13. Password Recovery

Implement secure password recovery.

Recommended flow:

```text
Forgot Password
       ↓
Email
       ↓
OTP / secure reset mechanism
       ↓
Verification
       ↓
New Password
```

If OTP is part of the approved design, implement it securely.

Requirements:

- OTP expiration
- limited verification attempts
- rate limiting
- invalid OTP handling
- password reset after successful verification
- old credentials cannot be used to bypass the process
- OTP must not be logged
- OTP must not be returned through unrelated API responses

Do not store OTPs as plaintext if the selected architecture allows secure
hashing.

Do not reveal whether an email exists in a way that unnecessarily enables
account enumeration.

Use a safe response such as:

```text
"If the account exists, password recovery instructions have been sent."
```

where appropriate.

---

# 14. Rate Limiting

Apply rate limiting to authentication-sensitive endpoints.

At minimum review:

```text
Login
Registration
Forgot Password
OTP Verification
Password Reset
```

The exact limits should be practical for normal users while reducing brute-force
and abuse risk.

Do not implement an unnecessarily complicated rate-limit system.

Use Laravel's supported rate-limiting mechanisms.

---

# 15. Roles

Create the initial application roles:

```text
Admin
Accountant
Manager
Staff
```

These roles must be designed around accounting application responsibilities.

Do not create dozens of roles.

---

# 16. Role Responsibilities

Initial conceptual permissions:

## Admin

Full system administration access.

Can:

- manage users
- manage roles/permissions
- manage company settings
- access accounting modules
- access reports
- manage system configuration

## Accountant

Can:

- manage accounting transactions
- manage customers/suppliers
- create financial documents
- manage payments
- access accounting reports
- perform accounting operations according to assigned permissions

## Manager

Can:

- access business information
- review transactions
- access reports
- perform management-level operations according to assigned permissions

## Staff

Limited operational access.

Staff should not automatically receive accounting administration privileges.

---

# 17. Permission System

Use a proper permission model rather than hard-coding role checks throughout
controllers.

The architecture should allow:

```text
Role
  ↓
Permissions
  ↓
User Authorization
```

Potential permission naming convention:

```text
users.view
users.create
users.update
users.delete

customers.view
customers.create
customers.update

reports.view

accounting.view
accounting.create
accounting.update
accounting.post
```

Do not create every future permission now.

Create the permission architecture and only the permissions necessary for
Phase 2.

Future modules can add their permissions in their respective phases.

---

# 18. Authorization

Authorization must happen server-side.

Never trust the frontend to enforce permissions.

For example:

```text
Frontend hides button
        ≠
Backend authorization
```

A user must not be able to call a protected endpoint manually simply because the
frontend hides the operation.

Use:

- middleware
- policies
- gates
- permission checks

as appropriate.

Keep authorization logic centralized and maintainable.

---

# 19. Admin Bootstrap

Because this is a new application, determine how the first administrator account
will be created.

Do not automatically create an admin user with a known password.

Do not run seeders automatically.

Preferred approach:

Provide a controlled development/setup mechanism or documented command that
requires explicit developer action.

For example:

```text
php artisan ...
```

The exact implementation must not create insecure default credentials.

Document how the first Admin is created.

---

# 20. API Routes

Organize routes clearly.

Expected authentication endpoints:

```text
POST   /api/auth/register
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me
POST   /api/auth/forgot-password
POST   /api/auth/verify-otp
POST   /api/auth/reset-password
POST   /api/auth/change-password
```

The exact password-recovery route design may differ depending on the implemented
mechanism.

Protect appropriate routes with JWT middleware.

Do not expose protected operations publicly.

---

# 21. Change Password

Authenticated users should be able to change their password.

Required:

```text
current_password
new_password
new_password_confirmation
```

Verify the current password before changing it.

Apply the same password-strength rules used during registration.

Consider token invalidation/revocation behavior after password changes and
document the chosen approach.

---

# 22. User Management API

Prepare authenticated user management for Admin use.

Potential endpoints:

```text
GET    /api/users
GET    /api/users/{id}
POST   /api/users
PUT    /api/users/{id}
DELETE /api/users/{id}
```

Only implement them if they are part of the approved Phase 2 scope.

Admin authorization must be enforced server-side.

Do not allow users to assign themselves Admin privileges.

Do not allow ordinary users to modify their own authorization level.

---

# 23. User Deletion

Do not blindly hard-delete users.

Before deleting a user, consider future accounting/audit relationships.

A user may later be associated with:

- created transactions
- approved transactions
- posted journals
- audit records

Therefore the architecture should support deactivation rather than destructive
deletion.

Prefer:

```text
is_active = false
```

for users who should no longer access the system.

If deletion is implemented, protect users who are referenced by important
records.

Document the behavior.

---

# 24. Database Design

Create the necessary migrations for:

```text
users
roles
permissions
role_user
permission_role
```

or an equivalent normalized design.

Use foreign keys.

Use indexes for:

- email
- role relationships
- permission relationships
- active status where useful

Do not create future accounting tables in this phase.

---

# 25. Authorization Data Integrity

Prevent:

- duplicate roles
- duplicate permissions
- duplicate role assignments
- duplicate permission assignments

Use database constraints where appropriate.

Do not rely only on frontend validation.

---

# 26. API Resources

Use API Resources or an equivalent controlled serialization approach for user
responses.

Never return the raw User model blindly.

Explicitly control exposed fields.

Example safe user representation:

```text
id
first_name
last_name
email
mobile_no
is_active
roles
created_at
updated_at
```

Do not expose:

```text
password
remember_token
password_reset_token
internal secrets
```

---

# 27. Security Requirements

Perform a security review covering:

### Authentication

- JWT secret is environment-based
- JWT validation works
- invalid tokens are rejected
- expired tokens are rejected
- logout invalidates the token where supported

### Passwords

- passwords are hashed
- passwords are never returned
- passwords are never logged

### Authorization

- protected endpoints require authentication
- role/permission checks happen server-side
- users cannot elevate their own privileges
- ordinary users cannot assign Admin

### Password Recovery

- reset flow is time-limited
- OTP/reset token is protected
- brute-force attempts are limited
- account enumeration is minimized
- secrets are not logged

### API

- validation is enforced
- mass assignment is controlled
- sensitive errors are not exposed

---

# 28. Testing

Create comprehensive tests for this phase.

## Registration

Test:

- successful registration
- duplicate email
- invalid email
- missing required fields
- password mismatch
- weak password
- validation errors

## Login

Test:

- successful login
- invalid email
- invalid password
- inactive user
- malformed credentials
- token generation

## JWT

Test:

- valid token
- invalid token
- expired token
- missing token
- logout/token invalidation

## Current User

Test:

- authenticated request
- unauthenticated request
- safe user response
- password is not exposed

## Password

Test:

- successful password change
- incorrect current password
- password mismatch
- weak password

## Password Recovery

Test:

- valid recovery request
- invalid OTP
- expired OTP
- successful reset
- failed verification
- rate limiting where practical

## Roles

Test:

- Admin authorization
- Accountant authorization
- Manager authorization
- Staff restrictions
- unauthorized role assignment

## Permissions

Test:

- permission granted
- permission denied
- direct API access without permission

---

# 29. API Security Testing

Explicitly test that a user cannot bypass authorization by directly calling the
API.

Example:

```text
Frontend:
Button hidden

Malicious request:
POST /api/...
```

The backend must still reject unauthorized operations.

---

# 30. No Seeders Without Permission

Do not run:

```text
php artisan db:seed
```

or equivalent seed commands without explicit permission.

Do not automatically create:

- Admin user
- demo users
- demo roles
- demo accounting data

If development setup requires seed data, document the command but do not execute
it without permission.

---

# 31. Do NOT Implement Yet

Do not implement:

```text
Company Management
Chart of Accounts
Accounting Engine
Journal
General Ledger
Trial Balance
Customers
Suppliers
Items
Sales
Purchases
Payments
Expenses
Banking
Tax
Multi-Currency
Fixed Assets
Reports
Dashboard
Graphs
Next.js Frontend
```

These belong to later phases.

---

# 32. Phase 2 Completion Report

Create:

```text
docs/reports/PHASE_2_REPORT.md
```

Include:

## 1. Summary

What was implemented.

## 2. Authentication

Document:

- registration
- login
- logout
- JWT
- current user
- password change
- password recovery

## 3. Authorization

Document:

- roles
- permissions
- policies/middleware
- authorization behavior

## 4. Database

List migrations and relationships.

## 5. Packages

List authentication/authorization packages installed and why.

## 6. Security Review

Document security checks.

## 7. Tests

Report:

```text
Total tests:
Passed:
Failed:
Skipped:
Assertions:
```

## 8. Issues

List unresolved issues.

## 9. Out of Scope

Confirm that future accounting modules were not implemented.

## 10. Status

Use exactly one:

```text
PASS
PASS WITH NOTES
BLOCKED
```

---

# 33. Final Acceptance Criteria

Phase 2 is complete only when:

- [ ] Registration works
- [ ] Login works
- [ ] JWT authentication works
- [ ] Logout works
- [ ] `/auth/me` works
- [ ] Password hashing is secure
- [ ] Password change works
- [ ] Password recovery works
- [ ] OTP/reset mechanism is protected
- [ ] Rate limiting is configured for sensitive endpoints
- [ ] Roles exist
- [ ] Permissions exist
- [ ] Server-side authorization works
- [ ] Admin cannot be assigned by unauthorized users
- [ ] User deactivation is supported
- [ ] Sensitive fields are never exposed
- [ ] Database constraints are correct
- [ ] Tests pass
- [ ] Security review is complete
- [ ] No unauthorized seeders were executed
- [ ] No fake accounting data was created
- [ ] No future accounting modules were implemented
- [ ] `PHASE_2_REPORT.md` exists
- [ ] Phase is ready for Phase 3

---

# 34. Next Phase

After Phase 2 passes verification, the next phase will be:

**PHASE 3 — Company & System Settings**

That phase will establish the company/accounting context required before
implementing the Chart of Accounts and Accounting Engine.
