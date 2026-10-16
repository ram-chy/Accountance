# Deployment & Onboarding Guide

**Applies to:** `accounting-app/backend` (Laravel 13 / PHP 8.4 / MySQL 8)
**Audience:** the operator or engineer performing a first install or a release.

This guide exists because two things the application needs are **not** performed
by migrations: reference-data synchronisation for roles/permissions, and the
creation of the first administrator. Both are deliberate operator actions.

---

## 1. Environment variables that must be set

Beyond the standard database and `APP_*` values (see `.env.example`), the
following have deployment-visible behaviour.

| Variable | Required | Purpose / failure behaviour |
|---|---|---|
| `APP_KEY` | Yes | 32-byte key. `php artisan key:generate`. Changing it invalidates encrypted payloads. |
| `APP_DEBUG` | Yes | **Must be `false` in production.** With `true`, unexpected exceptions render a debug page containing stack traces, file paths and env values. |
| `APP_URL` | Yes | The backend's own origin. Used for backend-generated URLs (e.g. the email-verification link). |
| `FRONTEND_URL` | Yes | Absolute origin of the public SPA, used for the **password-reset email link** and any other out-of-band link. Must be a full `http(s)://host` URL. **Password recovery refuses to build a link (and requests fail) if it is missing or malformed** — see §4. Never derived from an inbound request header. |
| `JWT_SECRET` | Yes | `php artisan jwt:secret`. Rotating it invalidates every issued JWT. |
| `DB_*` | Yes | MySQL connection. `DB_DATABASE` for tests is set in `phpunit.xml` (`accounting_test`). |

---

## 2. First install (fresh database)

```bash
composer install
cp .env.example .env          # then edit DB_*, APP_URL and FRONTEND_URL
php artisan key:generate
php artisan jwt:secret

php artisan migrate --force   # schema only; creates no roles or permissions

php artisan app:sync-roles    # REQUIRED — see §3
php artisan app:create-admin  # REQUIRED — creates the first administrator
```

`composer setup` performs the first four steps for local development only; it
does **not** run `app:sync-roles` or `app:create-admin` and must not be treated
as a production deploy.

---

## 3. Permission synchronisation (`app:sync-roles`) — **required**

The permission and role matrix lives in `config/authorization.php`. It is applied
to the database by `php artisan app:sync-roles`; there is **no seeder for this
data by design** (the default `DatabaseSeeder` is intentionally empty).

Run it:

- once on a fresh install, immediately after `migrate`;
- after **every deploy** that changes `config/authorization.php`;
- it is safe to run repeatedly and safe to run when nothing has changed.

What the command **does**:

- creates any permission declared in config that is missing from the table;
- creates any role declared in config that is missing;
- for a role that is newly created, or that is missing a permission the config
  grants it, replaces the role's permission set with the config set
  (`Admin` → `*` resolves to *all* permissions).

What the command **does not** do (verified by the test suite):

- it never **deletes** permission or role records;
- it never changes **user** role assignments;
- it never removes **direct** (user-level) permission grants;
- it leaves an unchanged role alone — a role is only re-synced when it is new or
  a declared permission is missing.

> **Declarative caveat.** When a role *is* re-synced, its permission set is
> replaced by the config, so any permission added to that role *outside* config
> is dropped. Treat `config/authorization.php` as the single source of truth for
> role permissions; do not hand-grant role permissions in production.

Use `php artisan app:sync-roles --dry-run` to see intended changes without
writing.

The registration flow calls a narrower `ensureBaselineRoles()` so a fresh
database can assign the `Staff` role, but that creates roles only and never
grants permissions — the full synchronisation is still required.

---

## 4. Password recovery and `FRONTEND_URL`

Password-reset emails are built by `App\Support\FrontendUrl` from
`config('frontend.url')` (`FRONTEND_URL`). The link opens the SPA route:

```
{FRONTEND_URL}/reset-password?token=<token>&email=<url-encoded email>
```

- The origin is taken **only** from configuration. It is never taken from the
  `Host` or `X-Forwarded-Host` header, so a spoofed header cannot redirect a
  user's reset link to an attacker-controlled host.
- The token and email are RFC 3986 URL-encoded.
- **Fail-safe:** if `FRONTEND_URL` is unset or is not an absolute `http(s)` URL,
  the forgot-password request fails loudly (HTTP 500) instead of emailing a
  broken link. The check runs *before* the account is looked up, so a
  misconfigured deployment cannot be used to discover whether an address is
  registered. Fix the configuration, then retry.

The backend also exposes `GET /api/auth/reset-password/{token}` — an API token
probe the SPA calls to decide whether to show the "choose a new password" form.
It is a public endpoint and is intentionally retained.

---

## 5. User onboarding model

Public self-registration (`POST /api/auth/register`) always grants **`Staff`**,
which holds only `companies.view`. A registered user therefore **cannot create a
company**, and company-scoped endpoints answer a clear `403` until the user
belongs to a company.

Provisioning is administrative:

1. Onboard an administrator with `php artisan app:create-admin` (interactive; no
   default credentials).
2. The administrator creates the company (`POST /api/companies`).
3. The administrator adds the registered user to the company by id
   (`POST /api/companies/{company}/members`), optionally with `is_default: true`
   so the user has a working context immediately. The member can also switch to
   it themselves (`POST /api/companies/{company}/switch`).

Self-service first-company creation is **deliberately not offered**; enabling it
would weaken the authorization model and is a product-owner decision, not a
deployment setting.

---

## 6. Release checklist

1. `php artisan migrate --force` — confirm **zero pending** before serving.
2. `php artisan app:sync-roles` — required when `config/authorization.php` changed.
3. Confirm `APP_DEBUG=false` and `FRONTEND_URL` is set to the real public origin.
4. `php artisan config:cache` (and `route:cache` if applicable).
5. Smoke-test: unauthenticated `GET /api/auth/me` must return `401` JSON with
   every `Accept` header; a password-reset request for a known address must
   deliver a link beginning with `FRONTEND_URL`.
