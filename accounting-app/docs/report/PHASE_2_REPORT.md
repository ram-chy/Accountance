# Phase 2 Report — Authentication, Users & Roles

**Project:** Accounting Web Application
**Phase:** Phase 2 — Authentication + Users + Roles
**Status:** PASS WITH NOTES
**Date:** 2026-09-30

---

## Phase Objectives

Phase 2 establishes the security foundation every later module depends on: JWT
authentication, the user record, and a role/permission system. Companies arrive
in Phase 3.

Delivered:

- JWT authentication via `php-open-source-saver/jwt-auth` on a dedicated `api` guard.
- Registration, login, logout, current user, password change, email verification, password reset.
- User CRUD restricted to Admin, enforced server-side by policy.
- Four roles (Admin, Manager, Accountant, Staff) with global permissions via `spatie/laravel-permission`.
- Token invalidation that takes effect immediately rather than at expiry.

## Architecture Decisions

These were choices, not defaults. Each is recorded because a later phase could
reasonably have gone the other way.

| Decision | Choice | Rationale |
|---|---|---|
| Auth driver | JWT, `api` guard | Stateless, matches the frontend's need to scale horizontally |
| Permission library | `spatie/laravel-permission` | Explicit, auditable role→permission mapping in config |
| Password reset | Laravel's built-in broker | No reason to hand-roll token generation and storage |
| Unverified login | **Not** gated | The brief did not require it; gating would lock out users whose mail is misconfigured |
| User management | Admin only | Matches the role intent; Staff cannot self-register into roles |
| Spatie teams | **Disabled** | Team scoping would make every permission lookup company-dependent for the whole app. Company authorisation is `CompanyPolicy`'s job (Phase 3) |

`spatie/laravel-permission` **v8.3.0** and `php-open-source-saver/jwt-auth`
**v2.9.3** were installed. Composer reported no known advisories for the set.

## Token Freshness

Deactivating a user or changing their password must take effect **immediately**,
not whenever the token happens to expire. Laravel's JWT guard has no built-in
mechanism for this, so `EnsureTokenIsFresh` re-checks on every authenticated
request:

1. **Account active** — an inactive account is refused (`403`) and the token invalidated.
2. **Password-version claim** — every token carries `pv`, a 32-char SHA-256 prefix of the
   stored password hash. If the stored hash has changed, the token is stale (`401`)
   and blacklisted.

Deriving `pv` from the password hash means a password change invalidates old
tokens with no extra column, no token-version table, and no extra state to keep
consistent. The alternative — a `token_version` integer bumped on each change —
needs a migration and a second write on every password operation for the same
result.

One bug was found here: the middleware originally read the token from the
`JWTAuth` facade, which returns `null` because the token was already consumed by
the `auth:api` guard. Corrected to read the payload through `Auth::guard('api')`,
verified to be a `JWTGuard`.

## Database

### users

Redefined from the stock table. `email` unique, `is_active` indexed and
defaulting to `true`, plus `first_name`, `last_name`, `mobile_no`,
`email_verified_at`, `last_login_at`. The stock `sessions` and
`password_reset_tokens` tables are retained.

`password` and `remember_token` are in the model's `$hidden`, so they cannot
appear in a serialised response even if a future resource forgets to exclude
them.

### permission tables

`spatie/laravel-permission`'s migration, unmodified. Guard is set to `api` in
`config/authorization.php`; if it did not match the guard in `config/auth.php`,
every permission lookup would silently fail to find the user.

## Roles and Permissions

Roles and permissions live in `config/authorization.php` and are applied to the
database by `php artisan app:sync-roles`. The command is **idempotent** — it
reports "already up to date" when re-run, and is the intended way to apply
config changes. Roles are never created implicitly per request.

Admin is mapped to `'*'`, which grants every permission present at sync time.
This avoids a global `Gate::before` hook that would silently override future
gates — a hook like that tends to outlive its original purpose and quietly
weaken every authorisation check added afterwards.

```php
Admin     => ['*']
Manager   => ['users.view']
Accountant=> ['users.view']
Staff     => []
```

Company permissions were added in Phase 3; see that report for the final mapping.

`php artisan app:create-admin` creates the first Admin. It prompts for the
details interactively and **has no default password** — a command that
fabricates a known credential is a liability, not a convenience.

## API

| Method | Endpoint | Access |
|---|---|---|
| POST | `/api/auth/register` | public, `throttle:register` |
| POST | `/api/auth/login` | public, `throttle:login` |
| POST | `/api/auth/logout` | authenticated |
| GET | `/api/auth/me` | authenticated |
| PUT | `/api/auth/password` | authenticated |
| POST | `/api/auth/forgot-password` | public |
| POST | `/api/auth/reset-password` | public |
| GET | `/api/auth/reset-password/{token}` | public, reports token validity |
| GET | `/api/auth/email/verify/{id}/{hash}` | public, **signed** URL |
| POST | `/api/auth/email/verification-notification` | authenticated |
| GET/POST/PUT/DELETE | `/api/users`, `/api/users/{user}` | `users.*` permission |

### Headless verification and reset

Laravel's built-in notifications build links against the `verification.verify`
and `password.reset` route names. This backend renders no HTML, so those routes
are served as:

- **Email verification** — a public **signed** GET route. The signature plus the
  id/hash pair is the authorisation; a bearer token cannot be attached to a link
  opened from a mail client. Leaving it public is correct *because* it is signed.
- **Password reset** — a public GET that reports whether the token is still
  valid, and the POST that actually sets the new password.

### Rate limiting

Named limiters in `AppServiceProvider`, applied per-route rather than by one
global value:

| Limiter | Limit |
|---|---|
| `api` | 120/min per user, else IP |
| `auth` (coarse) | 30/min per IP |
| `register` | 5/min per IP |
| `login` | 5/min per IP **and** 5/min per account |
| `forgot-password` | 5/min per IP, **1/min** per account |
| `reset-password` | 5/min per IP |
| `change-password` | 5/min per user |
| `verify-email` | 2/min per user |

Login and forgot-password are limited **per account as well as per IP**, keyed on
a SHA-256 of the normalised email. Per-IP alone is defeated by a botnet or by
anyone behind a shared NAT; the account limit is what actually stops credential
stuffing against one user. The email is hashed so addresses do not appear in
limiter keys.

## Testing

159 tests / 496 assertions across the project at the end of Phase 3. Phase 2
itself contributed 35 tests / 158 assertions, of which `RegistrationTest` is 15
covering registration, duplicate email, validation, role assignment and token
issuance.

Verified manually beyond the suite: `migrate:fresh`, `app:sync-roles` (both
first run and re-run), `app:create-admin`, and `route:list --path=api`.

## Security Review

Checks performed:

1. `.env` excluded from Git; `APP_KEY` present only in `.env`, empty in `.env.example`.
2. No credentials hard-coded in `app/`, `routes/` or `config/` — all flow through `env()` into config.
3. No password, JWT, or reset token is logged. The application emits no custom log statements.
4. Production exception responses are sanitised — the catch-all renderer returns a generic `500`
   whenever `APP_DEBUG` is off, emitting no message, SQL, path, or stack trace.
5. `password` and `remember_token` are `$hidden` on the model.
6. Passwords are hashed by Laravel's default bcrypt. `password` is in the model's `$fillable`
   because registration and password change need to set it, but those writes go through
   `RegisterRequest` / `ChangePasswordRequest`, which validate the value before it is ever
   mass-assigned; `StoreUserRequest` and `UpdateUserRequest` never accept a password field.
7. Password strength enforced by `App\Rules\StrongPassword`: minimum length (configurable via
   `config/security.php`), plus at least one lowercase letter, uppercase letter, digit and symbol.
   Uses `\p{Ll}`/`\p{Lu}` with the `u` modifier so non-Latin scripts are handled correctly.
   Passwords are never trimmed, since trimming silently weakens a passphrase.
8. Email uniqueness enforced by a database unique index, not only by validation.
9. Inactive accounts are refused on every authenticated request and their tokens invalidated.
10. Password changes and resets invalidate existing tokens immediately.
11. User-management endpoints require permissions enforced in `UserPolicy` **and** in each
    `FormRequest::authorize()`, so validation cannot be reached without authorisation.
12. Login failures do not distinguish "unknown email" from "wrong password".
13. User deletion is modelled as deactivation (`is_active = false`), never a hard delete, because
    later phases will reference users from journals and audit records. See the finding below on
    the activation path.
14. An Admin cannot deactivate their own account (`403`), which prevents the last usable
    credential from being removed by the account that holds it.
15. No policy method consults input supplied by the caller about the caller's own privileges —
    a user can never grant themselves a permission.

**Finding — account activation bypasses the lifecycle permission.** `UpdateUserRequest`
validates `is_active` and `UserController::update` applies it, so an account can be reactivated
through `PUT /api/users/{user}` under `users.update` alone. The dedicated
`DELETE /api/users/{user}` endpoint correctly requires `users.delete`, which means the two paths
disagree about who controls the flag: deactivation needs `users.delete`, activation needs only
`users.update`. An account deactivated for cause can be switched back on by any Admin holding
`users.update`, without the `users.delete` grant the deactivation itself demanded.

This is not exploitable by a non-Admin — `users.update` is Admin-only — so it is an inconsistency
in authorisation rather than a privilege escalation. It was left as-is at the end of Phase 3 and
is the recommended fix for a later pass: drop `is_active` from `UpdateUserRequest::rules()` and
route both directions of the flag through endpoints gated on `users.delete`.
14. No Git remote is configured; nothing has been pushed.
15. Composer reports no known security advisories for the installed set.

### Outstanding

1. **CORS is `allowed_origins => ['*']`.** This is the single highest-risk item in this
   report and it is **still open as of the end of Phase 3**. With JWT auth in place, any
   origin can read authenticated responses from a browser. It must be locked to the actual
   frontend origin before any deployment. Deferred from Phase 1 and carried forward twice;
   no `config/cors.php` was written because the frontend origin has not been decided and
   inventing one would be worse than the visible gap.
2. Local development uses MySQL `root` with an empty password (the Laragon default). A
   least-privilege user is needed before any shared or non-local environment.
3. `php artisan app:create-admin` is interactive with no default password. Safe, but it means
   the first Admin cannot be created non-interactively in an automated deploy.

## Out of Scope — Confirmation

Not implemented, as required:

- Companies, company membership, company context → **Phase 3**
- All accounting modules (chart of accounts, journals, ledger, trial balance, P&L, balance
  sheet, cash flow, customers, suppliers, items, invoices, bills, payments, expenses, cash
  and bank accounts, transfers, tax/GST/TDS, multi-currency, fixed assets, dashboard,
  PDF reports) → later phases
- Next.js frontend

Additional confirmations:

- No seeders were created or executed; `DatabaseSeeder` remains empty.
- No fake business data of any kind.
- No `FLOAT`/`DOUBLE` columns; future money columns must use `DECIMAL`.
- No second role system. `UserPolicy` and `CompanyPolicy` delegate to Spatie rather than
  reimplementing permission checks.

## Issues Found and Fixed

1. **`JWTAuth` facade returns `null` for a consumed token.** `EnsureTokenIsFresh` was reading the
   payload through the facade and could never validate. Corrected to `Auth::guard('api')` with a
   `JWTGuard` instance check.
2. **`invalidateAllFor()` does not exist** on the JWT guard. The password-change flow called it and
   would have thrown. Replaced with the `pv` claim mechanism described above.
3. **AppServiceProvider was overwritten during a fix**, losing its namespace and the JWT/permission
   registrations. Restored in full.
4. **Test role setup ran per test and leaked state.** Replaced with a lazy once-per-test-instance
   sync, so tests no longer depend on execution order.
5. **`UserController` pagination was unclamped**, letting a client request an unbounded result
   set. Clamped to a fixed maximum.
6. **The reset-password GET route matched a token Laravel never issues** (characters outside the
   pattern). Widened.
7. **Email verification was initially authenticated**, which cannot work for a link opened from a
   mail client. Moved to a public signed route.

## Status

```text
PASS WITH NOTES
```

All required verifications for Phase 2 succeeded: JWT auth runs end to end on the `api` guard,
the full auth lifecycle is implemented, roles and permissions are enforced server-side,
inactive accounts and stale tokens are refused immediately, rate limits are applied per endpoint,
35 tests and 158 assertions passed at phase close, and Pint reported no style issues.

The one substantive note is **CORS**, which remains wildcard with authenticated endpoints
exposed. It must be fixed before deployment.
