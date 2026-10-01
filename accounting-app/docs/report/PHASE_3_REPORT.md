# Phase 3 Report — Company & System Settings

## 1. Phase Objectives

Phase 3 introduces the company domain: companies, memberships, a request-scoped
company context, and per-company system settings. It establishes the tenancy
boundary every later accounting module will depend on.

Delivered:

- Companies table with profile, address, locale and lifecycle fields.
- Membership pivot with a single default company per user.
- Company context resolution via the `X-Company-Id` header with default fallback.
- Typed company settings (invoice/quotation prefixes, payment terms, currency, fiscal year start).
- Membership-plus-permission authorization on every company endpoint.
- 159 tests, 496 assertions, all passing.

Explicitly **not** in scope: any accounting module (invoices, bills, ledger,
accounts). No seeders were created.

## 2. Database Migrations

| Migration | Purpose |
|---|---|
| `2026_09_30_090000_create_companies_table.php` | `companies` |
| `2026_09_30_090100_create_company_user_table.php` | `company_user` |
| `2026_09_30_090200_create_company_settings_table.php` | `company_settings` |

### companies

Profile and locale columns. `currency_id` is a reserved unsigned bigint with
**no foreign key** — the `currencies` table is out of scope for this phase, so
the column exists to be populated later without a migration.

`is_active` defaults to `true` and is indexed. Records are never hard-deleted;
the `forceDelete()` cascade behaviour below only applies if a future maintenance
command explicitly removes a row.

### company_user

The pivot carries `is_default` and enforces, in the database:

- `UNIQUE (company_id, user_id)` — no duplicate membership.
- A generated column `default_for_user` (`user_id` when `is_default`, else
  `NULL`) with a unique index — one default company per user.

The generated column is the only way to express this in MySQL without a trigger
or application-level locking. `storedAs` was tried first and rejected: MySQL
refuses a stored generated column in a table with foreign keys. `virtualAs` works
because the index is computed on read.

### company_settings

One row per company (`UNIQUE (company_id)`), FK to `companies` with
`ON DELETE CASCADE`. Typed columns rather than a key/value JSON blob, so invalid
settings are rejected by the database rather than by convention.
`default_currency_id` is again FK-less for the reason above.

### Verified

`migrate:fresh`, `migrate:rollback` and `migrate` all run clean.

## 3. Model Design

### Company

Relationships: `users()` (belongsToMany), `settings()` (hasOne).
Helpers: `hasMember()`, `isDefaultFor()`.

Two decisions worth flagging:

- **`is_active` is excluded from `$fillable`.** Flipping it has side effects
  (it moves every affected user's default company and clears defaults for users
  left with none), so it is not a plain attribute edit. Only
  `CompanyService::deactivate()`/`activate()` may change it, via `forceFill()`.
  While filling it would let any caller bypass both the side effects and the
  `companies.delete` permission.
- **`country_code` is normalised by the model.** An attribute mutator uppercases
  and trims on set. Doing this in the form request left the value correct only
  on the HTTP path, so a factory, console command or future seeder could store
  `us` alongside `US` and break equality and `LIKE` filters against a `char(2)`
  column.

### User

`companies()` (belongsToMany), `defaultCompany()` (hasOne, filtered to active
companies), `scopeMemberOfCompany()`. `defaultCompany()` excludes inactive
companies so a deactivated company can never become a default.

### CompanySetting

belongsTo `Company`, with integer casts on the numeric settings.

## 4. Company Context

`CompanyContext` resolves the active company per request:

1. `X-Company-Id` header when present.
2. The user's default company otherwise.

The header is a **hint, never an authorisation**. Every resolved company is
checked for membership and `is_active` before it is returned, so a caller cannot
reach a company by putting someone else's id in the header.

Two bugs found and fixed while testing this:

- **`HeaderUtils::combine()` is not "join repeated values".** It parses a list of
  `name=value` strings into an assoc array, so the header value `"42"` was
  parsed as name `'4'`, value `'2'` — silently turning one company id into a
  different one. Replaced with `headers->get()`.
- **The request was injected in the constructor**, which can be resolved before
  the current request is bound. The service now reads the request from the
  container on demand and memoises the resolved company **against the identity
  of the request it came from**, so a cached value can never be carried into a
  different request. `scoped()` is still bound, but is no longer the only guard.

Parsing is deliberately strict: a locale-independent `/^[0-9]+$/` plus a `> 0`
check, rejecting signs, spaces and non-ASCII digits.

## 5. Authorization

### The central finding

`CompanyPolicy` requires **membership AND permission**. A role grants nothing
on its own; the membership row decides whether that grant applies to a given
company.

This was initially broken in a way worth recording, because the failure was
silent rather than loud. Form requests called:

```php
$this->user()?->can('companies.update', $company);
```

Spatie registers each permission as a Gate ability in its own right, so
`Gate::check('companies.update', $company)` was answered straight from the
permission table and **`CompanyPolicy` never ran** — the membership check was
skipped entirely. A Manager could add a user to a company they did not belong
to, and the test failed with `201` instead of `403`.

The ability passed to `can()` must be the **policy method name**
(`update`), not the permission name (`companies.update`). Confirmed directly:

```php
$gate->forUser($manager)->check('update', $company);          // false (policy ran)
$gate->forUser($manager)->check('companies.update', $company); // true  (policy bypassed)
```

`StoreCompanyRequest` now uses `can('create', Company::class)` for the same
reason. **Any future Form request in this codebase must pass a policy ability.**

### Permission mapping

Roles hold **global** capabilities. Spatie teams mode stays disabled on purpose:
per-company authorisation is `CompanyPolicy`'s job, and enabling teams later
would require migrating `role_has_permissions` to carry a `team_id` and would
make every permission lookup company-dependent for the whole application.

| Permission | Admin | Manager | Accountant | Staff |
|---|:---:|:---:|:---:|:---:|
| `companies.view` | ✓ | ✓ | ✓ | ✓ |
| `companies.create` | ✓ | | | |
| `companies.update` | ✓ | ✓ | | |
| `companies.delete` | ✓ | | | |
| `companies.settings.view` | ✓ | ✓ | | |
| `companies.settings.update` | ✓ | ✓ | | |

Rationale:

- **`companies.view` for everyone** who works in a company, so `Staff` can read
  the company it operates in and nothing else. No broad grants by role.
- **Create and delete are Admin-only.** A company is a top-level boundary; a
  Manager must not be able to create one or retire one. Deactivation is a
  `companies.delete` action because it is the destructive end of the lifecycle.
- **Settings are Admin + Manager**, per the brief's "company information/settings
  management where explicitly permitted".
- **Accountant and Staff get no administrative rights at all.** Accountant has
  `users.view` + `companies.view` from Phase 2 plus `companies.view`.

This is the minimum set that satisfies the brief. No permission was added
speculatively.

### Mass assignment

`is_active` is not fillable (§3). `role`, `user_id` and `permission` are not
company fields and are ignored by validation. `currency_id` is fillable but
excluded from validation and hidden from responses.

`is_default` on the member-add endpoint is restricted to callers holding
`companies.delete`. Setting it silently changes the target user's active company
on their next request, so a Manager is refused with `403` rather than having the
flag silently dropped — a dropped flag would leave the caller believing it had
been applied.

## 6. API Routes

All routes require authentication and a fresh token.

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/api/companies` | membership-scoped, searchable, filters on `is_active` |
| POST | `/api/companies` | Admin only |
| GET | `/api/companies/{company}` | members |
| PUT | `/api/companies/{company}` | members with `companies.update` |
| POST | `/api/companies/{company}/activate` | Admin only |
| POST | `/api/companies/{company}/deactivate` | Admin only |
| POST | `/api/companies/{company}/switch` | any member |
| POST | `/api/companies/{company}/members` | membership management |
| DELETE | `/api/companies/{company}/members/{user}` | membership management |
| GET | `/api/company` | current context |
| GET | `/api/company/settings` | context company |
| PUT | `/api/company/settings` | context company, no id in payload |

The settings routes deliberately take **no company id**. The company comes from
the resolved context, so there is nothing in the payload to tamper with; a
forged `company_id` in the body is ignored.

## 7. Service Layer

`CompanyService` owns the rules that do not belong in a model: create (company +
membership + settings in one transaction), deactivate (moves affected users'
defaults, clearing them for users with no remaining active company), attach,
detach, makeDefault, and membership assertions. Business workflows stay out of
the models.

Constraint violations are translated to validation errors. `Rule::unique` is a
`SELECT` followed by an `INSERT`, so two concurrent creates of the same name can
both pass it; the unique index is the real guarantee and the driver error is
mapped back to the same `422` the request-level check produces, instead of
surfacing as a `500`.

## 8. Validation

Shared rules via a `ValidatesCompany` trait. Company name unique; country code
against an explicit ISO alpha-2 list (`ResourceBundle::getLocales()` returns
locales like `en_US`, not country codes); timezone against
`DateTimeZone::listIdentifiers()`; date format against a whitelist.

Payment terms must be `0..365`, fiscal year start `1..12`, prefixes max 20
characters.

## 9. Test Coverage

159 tests, 496 assertions, all passing. Phase 2 contributed 35; Phase 3 adds 124.

| File | Tests | Focus |
|---|---:|---|
| `CompanyCrudTest` | 28 | create/read/update, lifecycle, validation |
| `CompanyMembershipTest` | 17 | attach, detach, duplicate prevention, defaults |
| `CompanyContextTest` | 20 | header resolution, default fallback, isolation |
| `CompanySettingsTest` | 18 | typed settings, scoping, per-role rights |
| `CompanySecurityTest` | 26 | the §17 review, IDOR, mass assignment |
| `CompanyConstraintTest` | 15 | FKs, unique constraints, cascades |

Notable cases: an Admin cannot reach another Admin's company (proving membership,
not permission, is the gate); a forged `company_id` in a settings payload writes
only to the context company; context does not leak between requests; a
non-existent user id returns `422` rather than `500`; two users may share a
default company but one user may not have two.

## 10. Issues Found and Fixed

Bugs found by tests, in order:

1. **`CompanyPolicy` bypassed by Form requests** — the serious one; membership
   was not being checked at all (§5).
2. **`HeaderUtils::combine()` corrupted the company header**, mapping one company
   id onto another (§4).
3. **Context memoisation could carry across requests** — now keyed on request
   identity (§4).
4. **Company names were not unique in the database**, only in the request layer,
   leaving a race (§2).
5. **`country_code` was only normalised on the HTTP path**, so `'us'` and `'US'`
   could coexist in a `char(2)` column (§3).
6. **`is_active` was fillable**, allowing any caller to flip it and bypass both
   its side effects and its permission (§3).
7. **A non-existent user id produced a `500`.** `User::find()` returned null and
   was passed to `hasMember(User $user)`, raising a `TypeError`. Validator
   after-hooks run even when other rules already failed (§8).
8. **`/api/companies` returned `settings: null`** while every other endpoint
   embedded settings, and would have been an N+1. Fixed with `with('settings')`.
9. **`CountryCode` used `ResourceBundle::getLocales()`**, which returns locales
   rather than country codes (§8).

## 11. Security Review

Covered in §5 and tested in `CompanySecurityTest`. Outstanding items, all
deliberate deferrals rather than oversights:

- **`CORS` is still `*`.** This must be restricted to the real frontend origin
  before any authenticated deployment. Inherited from Phase 2 and the single
  highest-risk item in this report.
- **`currency_id` is FK-less by design** until `currencies` exists. It is
  excluded from validation and hidden from responses, so nothing can reference a
  nonexistent currency yet.
- **No rate limit specific to company switching.** The existing `api` limiter
  applies; a per-company stricter limit would be reasonable but is a tuning
  decision, not a correctness one.
- **`defaultFor` ordering is name-based** when several companies are listed.
  Deterministic, but a future phase may want explicit recency ordering.

No password, token, or hash material appears in any response body; asserted in
tests.

## 12. Requirements Traceability

| Requirement | Status |
|---|---|
| Company entity with profile/address/locale | Done |
| Membership, many companies per user | Done |
| One default company per user | Enforced by generated unique column |
| Company context with header + fallback | Done |
| Typed settings, one row per company | Done |
| Membership + permission authorization | Done |
| No hard delete, deactivate instead | Done |
| Validation, no trust in client | Done |
| Unique constraints | Done, incl. race backstop |
| FKs and cascade behaviour | Done |
| No seeders | Done |
| No accounting modules | Done |
| CORS restricted | **Deferred — must fix before production** |

## 13. Outstanding Work

1. Restrict CORS to the actual frontend origin.
2. Add the `currencies` table and FK `companies.currency_id` and
   `company_settings.default_currency_id` to it.
3. Build the accounting modules on this tenancy boundary.
4. Consider recency ordering for `defaultFor`.
