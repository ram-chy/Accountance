# BACKEND RELEASE REMEDIATION REPORT

**Report date:** 2026-10-16
**Role:** Senior Laravel architect / accounting-system engineer / application security reviewer / QA engineer
**Repository:** `D:\Development\Accountance` (backend at `accounting-app/backend`, frontend at `accounting-app/frontend`)
**Branch:** `main`, remote `origin` = `https://github.com/ram-chy/Accountance.git`
**HEAD at start:** `f39b838` ("Phase 21 Running")
**Scope:** Backend remediation of the four backend/system issues recorded in
`accounting-app/docs/report/frontend/FRONTEND_PHASE_21_REPORT.md`. No new
modules, no accounting-logic changes, no frontend changes.

---

## 1. Executive summary

All four findings from the Phase 21 report were re-verified against source (none
was taken on trust) and all four are confirmed and resolved. Verification
surfaced a fifth, **blocking** defect that was not on the original list: two
stale duplicate migrations make `php artisan migrate` and `php artisan
migrate:fresh` fail on any environment, which also prevented the entire backend
test suite from running. They have been removed.

| # | Finding | Verdict | Resolution |
|---|---|---|---|
| A | Password-reset email links to the backend API | **Confirmed** | Link is now built from a configured `FRONTEND_URL`; fails loudly if unset. No header can influence it. |
| B | Non-`application/json` unauthenticated requests return **500 + stack trace** instead of 401 JSON | **Confirmed (live)** | Root cause identified precisely; fixed in `bootstrap/app.php`. Now 401 JSON for every `Accept`. |
| C | Permission synchronisation is not part of any deployment step | **Confirmed** | Behaviour pinned down by tests; `docs/DEPLOYMENT.md` documents the required operator action. |
| D | Self-registered user cannot reach a first company | **Confirmed** | Existing Admin-provisioning model documented and covered by end-to-end tests; no permission was widened. |
| — | **Two stale duplicate migrations block all migrations** *(new)* | **Confirmed (blocking)** | Superseded files removed; migrations now run clean. |

**Key root cause (B) — the one most worth recording:**
Laravel's `ApplicationBuilder::withMiddleware()` installs a default
`redirectGuestsTo(fn () => route('login'))`. `Illuminate\Auth\Middleware\Authenticate::unauthenticated()`
evaluates `$request->expectsJson() ? null : $this->redirectTo($request)`. For any
request without an `Accept: application/json` header this *eagerly* resolves
`route('login')`, which does not exist in this headless API, so a
`RouteNotFoundException` is thrown **from inside the auth middleware — before the
`AuthenticationException` is even constructed**. The application's 401 render
callback therefore never runs, and the caller receives a 500 carrying a full
debug trace. Proven by stack trace at `ApplicationBuilder.php:291`
`route('login')`. **Fix: one line** — `$middleware->redirectGuestsTo(fn () => null);`.

| Check | Result |
|---|---|
| Focused remediation suites (A/B/C/D) | **44 passed, 0 failed — 172 assertions** |
| Full backend suite | **1268 passed, 0 failed, 0 skipped — 5646 assertions** (927.9 s) |
| Pint (`vendor/bin/pint --test`) | **Passed** (`laravel` preset; one empty-body style fix applied to a new exception class) |
| Routes | **216** (named route `password.reset` preserved) |
| Custom commands | **2** (`app:sync-roles`, `app:create-admin`) |
| Live check (B) | **401 JSON for `application/json`, `*/*`, `text/html` and no `Accept`** |
| **Final classification** | **READY WITH DOCUMENTED LIMITATIONS** |

---

## 2. Scope, baseline and method

### 2.1 Environment (verified)

| Item | Value | Evidence |
|---|---|---|
| PHP | 8.4.12 | `[source]` |
| Laravel Framework | 13.34.0 | `[source]` |
| MySQL | 8.4.3 | `[live]` |
| Test database | `accounting_test` (`phpunit.xml`) | `[source]` |
| Authorization | `spatie/laravel-permission` ^8.3 | `[source]` |
| JWT | `php-open-source-saver/jwt-auth` ^2.9 (guard `api`) | `[source]` |
| Formatters / static analysis | `laravel/pint` ^1.27 (default preset, no `pint.json`); **no** PHPStan/Psalm configured | `[source]` |

### 2.2 Method

Each finding was traced to source and, where behaviour depended on runtime
state, reproduced before a fix was written. Fixes were then re-verified by
focused regression tests and (for B) live HTTP requests. No test result in this
report is inferred; every number is command output.

Evidence labels: `[source]` = read from committed source; `[tests]` = pytest/
PHPUnit output; `[build]` = formatter/lint; `[live]` = real HTTP against a
running server; `[not verified]` = stated as unverified.

### 2.3 What was deliberately *not* changed

- No frontend file, dependency or package manifest was modified (the
  `accounting-app/frontend/src/proxy.ts` modification is pre-existing Phase 21
  work and is left untouched).
- No permission, policy or role was widened to make a test pass.
- No seeder, schema change or data migration was introduced.
- `composer.json`'s `setup` script was **not** modified to add `app:sync-roles`
  (see §6); the required operator action is documented instead, as the brief
  directs.

---

## 3. Finding verification

Each Phase 21 claim was checked against source before any fix was attempted.

| Claim in Phase 21 report | Verified? | Result |
|---|---|---|
| Reset email points at the backend API | `[source]` | **Yes.** Framework `ResetPassword` notification → `url(route('password.reset', …))` → `GET http://localhost:8000/api/auth/reset-password/<token>?email=…`. No frontend-origin configuration existed anywhere in the backend. |
| Non-JSON unauthenticated API requests return 500 | `[live]` | **Yes, pre-fix:** `Accept: */*`, `Accept: text/html` and *no* `Accept` all returned HTTP 500 with `Route [login] not defined` plus a debug trace; only `Accept: application/json` returned 401. |
| Permission sync may leave a deployment inconsistent | `[source]` | **Yes,** in the specific sense that **no deployment step runs it** (there are no deployment docs at all — `backend/README.md` is the stock Laravel README). The synchroniser itself is safe; see §6. |
| Self-registered user may have no accessible company; company requests return 409 | `[source]` `[tests]` | **Partly.** The no-company *authorization* gap is real. The status code is **403, not 409** — the 409s in this codebase are accounting-conflict responses (e.g. posting a journal twice), never company-context. Corrected here. |

---

## 4. Workstream A — password-reset link destination

### 4.1 Root cause `[source]`

`PasswordResetLinkController::store()` → `Password::sendResetLink()` → the
framework's `Illuminate\Auth\Notifications\ResetPassword` notification builds
`url(route('password.reset', ['token' => …, 'email' => …]))`. The named route
`password.reset` is this backend's API token probe, so the email contained the
**backend** origin. The destination was derived from request-time routing, which
is also the property that makes it header-influenceable in principle.

### 4.2 Fix `[source]`

| File | Change |
|---|---|
| `config/frontend.php` *(new)* | `'url' => env('FRONTEND_URL')` — the single origin for out-of-band links. |
| `app/Support/FrontendUrl.php` *(new)* | Builds `{FRONTEND_URL}/reset-password?token=…&email=…`. Validates the origin is an **absolute `http(s)` URL with a host**; rejects relative, scheme-relative (`//host`), `javascript:`, `ftp:` and host-less values. RFC 3986 encoding so `+` survives as `%2B` (never as a space). |
| `app/Exceptions/FrontendUrlNotConfiguredException.php` *(new)* | The fail-safe signal — a deployment fault, left to surface as a server error rather than being mapped onto one of the API's 4xx envelopes. |
| `app/Providers/AppServiceProvider.php` | Registers `ResetPassword::createUrlUsing(...)` so the link points at the SPA. |
| `app/Http/Controllers/Api/PasswordResetLinkController.php` | Calls `FrontendUrl::assertConfigured()` **before** the broker. |
| `.env.example` | Documents `FRONTEND_URL=http://localhost:3000` (placeholder, not hardcoded logic). |

**Why the check is in the controller.** The broker only builds a URL for an
address that *exists*. If validation happened inside the URL builder only, a
misconfigured deployment would answer **500 for a known address and 200 for an
unknown one** — leaking account existence. Validating before the broker lookup
makes the failure uniform, so account enumeration is impossible even in the
misconfigured state.

**No trusted-header influence.** The origin is read from configuration only;
`Host` and `X-Forwarded-Host` are never consulted, so a spoofed header cannot
retarget an emailed link.

**The API token probe is preserved.** `GET /api/auth/reset-password/{token}` is
the endpoint the SPA calls to decide whether to show the "choose a new password"
form (`routes/api.php`, `->where('token', '[A-Za-z0-9]+')`). It was kept
unchanged; only the *emailed* link moved to the frontend.

### 4.3 Evidence

`[tests]` `tests/Feature/Auth/PasswordResetNotificationTest.php` — **18 tests**
covering: link targets `{FRONTEND_URL}/reset-password` and contains no `/api/`;
token + email round-trip; `+`-addressed email encoded as `%2B`; spoofed
`Host`/`X-Forwarded-Host` cannot change the destination; known and unknown
emails return byte-identical generic bodies; missing origin throws; missing
origin fails **uniformly** for known vs unknown email; unusable origins rejected
(5 cases); origin with a sub-path accepted; `password.reset` route still
registered; token probe accepts a valid token / rejects a stale one with 400;
no-account disclosure; and single-use semantics (a consumed token is rejected).

`[not verified]` End-to-end live delivery of an actual reset email was **not**
performed — it would require creating a persistent user and sending mail, which
the brief restricts. The link construction is fully covered by tests.

---

## 5. Workstream B — consistent JSON authentication errors

### 5.1 Root cause `[source]` `[live]`

Reproduced first, then traced to the exact throwing frame:

```
#1 ApplicationBuilder.php(291): route('login')
#2 ApplicationBuilder::withMiddleware():289 closure
#3 Authenticate.php(117): call_user_func(Object(Closure), …)
#4 Authenticate.php(104): Authenticate->redirectTo($request)
#5 Authenticate.php(87):  Authenticate->unauthenticated($request, $guards)
```

`bootstrap/app.php` already registered **12** render callbacks including
`AuthenticationException → 401 envelope`, and `shouldRenderJsonWhen` already
treats `api/*` as JSON. None of it could help, because the
`AuthenticationException` was never created: for a non-JSON `Accept` the
middleware resolved `route('login')` eagerly as a constructor argument, and that
lookup threw a `RouteNotFoundException` first. `RouteNotFoundException` is not
one of the mapped exceptions, and with `APP_DEBUG=true` the catch-all returns
`null` deliberately — so Laravel's default 500 renderer produced a response
containing `Route [login] not defined`, vendor paths and environment values.

**Before → after (live, `php artisan serve`, 127.0.0.1:8000), `GET /api/auth/me`:**

| `Accept` | Before | After |
|---|---|---|
| `application/json` | 401 `{"success":false,"message":"Unauthenticated."}` | 401 (unchanged) |
| `*/*` | **500 + `Route [login] not defined` debug trace** | **401 `{"success":false,"message":"Unauthenticated."}`** |
| `text/html` | **500 + trace** | **401 `{"success":false,"message":"Unauthenticated."}`** |
| *(none)* | **500 + trace** | **401 `{"success":false,"message":"Unauthenticated."}`** |

The same was confirmed for a company-scoped endpoint (`GET /api/journals`). `[live]`

### 5.2 Fix `[source]`

`bootstrap/app.php`, inside `withMiddleware`:

```php
$middleware->redirectGuestsTo(fn () => null);
```

This overrides the framework default installed by `ApplicationBuilder`
(`redirectGuestsTo` → `Authenticate::redirectUsing` **and**
`AuthenticationException::redirectUsing`). `redirectTo()` now returns `null`, so
the `AuthenticationException` is constructed normally and reaches the existing
401 render callback. Because this is a headless API with no browser login page,
nothing depends on a guest redirect.

### 5.3 Evidence

`[tests]` `tests/Feature/Api/ApiErrorHandlingTest.php` — **10 tests**, part of the
44-test focused run (**172 assertions** total), including a `@dataProvider` covering
`application/json`, `*/*`, `text/html` and **no `Accept` header at all**, across
`/api/auth/me`, `/api/companies` and `/api/journals`, asserting status 401,
`content-type: application/json`, the exact envelope, plus an explicit
leak check that the body contains none of `<html`, `Route [login]`,
`RouteNotFoundException`, `vendor`, `D:\` or `/app/`.

Pre-existing behaviour was left intact: JSON 404, 405, 422 and 500 envelopes, and
the deliberate 500-on-unexpected-error-with-debug-on.

---

## 6. Workstream C — permission synchronisation & deployment safety

### 6.1 Behaviour established `[source]` `[tests]`

`app:sync-roles` (`RolePermissionSynchroniser::sync()`) **does**:

- create any permission declared in `config/authorization.php` that is missing;
- create any missing role;
- for a role that is new, **or** that is missing a declared permission, replace
  that role's permission set with the config set (`Admin` → `*` resolves to all).

It **does not**:

- delete permission or role records;
- alter **user** role assignments;
- remove **direct** user-level permission grants;
- touch a role that is already complete (second run is a genuine no-op).

**Declarative caveat (important):** when a role *is* re-synced, `syncPermissions()`
replaces the set, so a permission added to that role **outside** config is
dropped. `config/authorization.php` must be treated as the single source of truth
for role permissions. This is now stated in `docs/DEPLOYMENT.md`.

The registration flow's `ensureBaselineRoles()` creates the four roles only and
grants **no** permissions — it exists so a fresh database can still assign
`Staff`, and it does not substitute for `app:sync-roles`.

### 6.2 What was changed `[source]`

The brief permits either integrating the command into automation or documenting
the operator action. **Documentation was chosen**, because:

- there is **no deployment automation in this repository** to integrate with
  (`composer setup` is local-development bootstrap: install, copy `.env`,
  key:generate, migrate — and it is a `composer.json` manifest, which is out of
  scope for this phase);
- adding a data-write step to a shared script without an owner decision is an
  infrastructure decision the brief reserves.

**New `accounting-app/docs/DEPLOYMENT.md`** covers: required environment
variables (including `FRONTEND_URL` and `APP_DEBUG=false` for production), the
first-install sequence, **the required permission-synchronisation step with its
exact semantics and declarative caveat**, `app:create-admin`, the
`FRONTEND_URL` fail-safe policy, the user-onboarding model, and a release
checklist that includes `migrate` zero-pending and a 401 smoke test.

### 6.3 Evidence

`[tests]` `tests/Feature/Security/RolePermissionSynchroniserTest.php` — **9
tests**: creates every declared permission and role; second sync is a no-op;
the command's second run reports "already up to date"; user role assignments
preserved; direct user permissions preserved; permission records never deleted;
a role's set **is** replaced by config when a sync runs (the caveat);
`Admin` resolves to every declared permission; `ensureBaselineRoles()` creates
roles while granting zero permissions.

---

## 7. Workstream D — first-company onboarding

### 7.1 Root cause `[source]`

Public registration creates the user and assigns the role declared for it —
`Staff`. `config/authorization.php` gives `Staff` exactly one permission:

```php
'Staff' => ['companies.view'],
```

`POST /api/companies` → `StoreCompanyRequest::authorize()` → `CompanyPolicy::create()`
→ requires `companies.create` → **403**. Confirmed in
`tests/Feature/Auth/FirstCompanyOnboardingTest.php`.

Also corrected: a user with no company receives **403** with the message
*"No company is associated with your account, or the selected company is
inactive."* from `ResolveCompanyContext` — a clean, predictable refusal, **not**
a crash and **not** a 409.

### 7.2 Decision `[source]` `[tests]`

The existing product model already has a complete, safe onboarding path, and it
is administrative rather than self-service:

1. `php artisan app:create-admin` provisions the first administrator (interactive,
   no default credentials — it calls `ensureBaselineRoles()` itself);
2. the administrator creates the company;
3. the administrator adds the registered user by id
   (`POST /api/companies/{id}/members`), with `is_default: true` to give the
   user an immediately usable context — or the member switches to it
   (`POST /api/companies/{id}/switch`).

**No permission was widened.** Adding self-service first-company creation would
require granting `Staff` (or all registrants) `companies.create`, which changes
the authorization model — a **product-owner decision**, deliberately not taken
here. Recorded as the one open product question.

**`[tests]`** `tests/Feature/Auth/FirstCompanyOnboardingTest.php` — **7 tests**:
registration grants only `Staff` (never Admin/Manager); a self-registered user
cannot create a company; a user with no company gets the clear 403; end-to-end
Admin provisioning + member access; member can switch default and the default
persists to a later header-less request; a non-member is refused 403 both by
header and by switch; an unknown company id is 404.

---

## 8. Additional issue discovered: stale duplicate migrations

### 8.1 Finding `[source]` `[live]`

The Phase 14 FX refactor created `2026_10_09_170000_create_currencies_table.php`
and `2026_10_09_170100_create_exchange_rates_table.php`, but the **older**
`2026_10_03_121646_create_currencies_table.php` and
`2026_10_03_121658_create_exchange_rates_table.php` were left in the tree. Both
were still committed at `f39b838`.

Evidence:

- `php artisan migrate:status` on the development database reports **both old
  files as `Pending`** and only the Phase 14 pair as `Ran`; the `migrations`
  table contains no record of the old pair — so they have never run anywhere.
- The old `exchange_rates` migration calls
  `$table->unique(['company_id','from_currency_id','to_currency_id','effective_date'])`
  with no explicit name, which MySQL expands to
  `exchange_rates_company_id_from_currency_id_to_currency_id_effective_date_unique`
  — **79 characters**, over MySQL's 64-character identifier limit, so it fails
  with `SQLSTATE[42000] … Identifier name … is too long`.

### 8.2 Impact `[tests]`

- **Every** `migrate:fresh` failed → the entire backend test suite could not run
  (first observed as 15 hard errors in `RegistrationTest`).
- `php artisan migrate` would also fail on any existing deployment: the old pair
  is still pending, and the old `currencies` table already exists.

This is a **production deployment blocker**, not just a test inconvenience.

### 8.3 Resolution and risk analysis `[source]`

Both stale files were **removed**. Per the brief, a migration-affecting change
required an explicit risk assessment before applying:

| Risk | Assessment |
|---|---|
| Does any environment's schema change? | **No.** Both are `Pending` everywhere and created nothing; `migrate:status` proves it. |
| Do the replacements already own these tables? | **Yes.** `2026_10_09_170000` / `2026_10_09_170100` create both tables; the dev `migrations` table records only those. |
| Could a stale `migrations` row remain? | Only in an environment that had successfully run the old files. **None found** (they cannot succeed on MySQL at all). Residual risk is limited to `migrate:rollback` on such an environment; the project is MySQL-only. |
| Does anything else depend on them? | No — nothing reads these files; the schema is owned by the Phase 14 pair. |

After removal, `migrate:fresh` (invoked by `RefreshDatabase`) runs clean and the
full suite executes.

---

## 9. Verification results

| Check | Result | Evidence |
|---|---|---|
| Focused remediation suites (A, B, C, D) | **44 passed, 0 failed — 172 assertions** | `[tests]` |
| Full backend suite | **1268 passed, 0 failed, 0 skipped — 5646 assertions** (927.9 s) | `[tests]` |
| Pint (`vendor/bin/pint --test`) | **Passed** (`laravel` preset; one empty-body style fix applied to a new exception class) | `[build]` |
| Focused suites re-run **after** the Pint fix | **44 passed, 0 failed** | `[tests]` |
| `migrate` / `migrate:fresh` | **Clean — stale blockers removed** | `[tests]` |
| Routes | **216**; `password.reset` and `auth.password.reset` both present | `[live]` |
| Commands | `app:sync-roles`, `app:create-admin` both registered | `[live]` |
| Unauthenticated API errors (4 `Accept` variants × 3 endpoints) | **401 JSON envelope, no leak** | `[live]` `[tests]` |
| Diff reviewed, git status understood | **Yes** | `[source]` |

New and modified files:

```
+ accounting-app/backend/app/Exceptions/FrontendUrlNotConfiguredException.php   (new)
+ accounting-app/backend/app/Support/FrontendUrl.php                             (new)
+ accounting-app/backend/config/frontend.php                                     (new)
+ accounting-app/backend/tests/Feature/Auth/FirstCompanyOnboardingTest.php       (new)
+ accounting-app/backend/tests/Feature/Auth/PasswordResetNotificationTest.php    (new)
+ accounting-app/backend/tests/Feature/Security/RolePermissionSynchroniserTest.php (new)
+ accounting-app/docs/DEPLOYMENT.md                                              (new)
+ accounting-app/docs/report/backend/BACKEND_RELEASE_REMEDIATION_REPORT.md       (new)
~ accounting-app/backend/.env.example
~ accounting-app/backend/app/Http/Controllers/Api/PasswordResetLinkController.php
~ accounting-app/backend/app/Providers/AppServiceProvider.php
~ accounting-app/backend/bootstrap/app.php
~ accounting-app/backend/tests/Feature/Api/ApiErrorHandlingTest.php
- accounting-app/backend/database/migrations/2026_10_03_121646_create_currencies_table.php
- accounting-app/backend/database/migrations/2026_10_03_121658_create_exchange_rates_table.php
```

Pre-existing Phase 21 frontend artifacts (`proxy.ts`, `proxy.test.ts`,
`pagination.test.ts`, `frontend/AGENTS.md`, `FRONTEND_PHASE_21_REPORT.md`) were
left unmodified by this phase.

---

## 10. Risks, limitations and operator prerequisites

### 10.1 Operator prerequisites (must be done at deploy)

1. Set **`FRONTEND_URL`** to the real public SPA origin before enabling password
   recovery. If it is unset or not an absolute `http(s)` URL, forgot-password
   requests fail with 500 by design rather than emailing a broken link. `[source]`
2. Run **`php artisan app:sync-roles`** after any change to
   `config/authorization.php`; it is idempotent and safe to repeat. Role
   permissions are declarative — do not hand-grant role permissions outside
   config. `[source]`
3. Ensure **`APP_DEBUG=false`** in production. With debug on, genuinely unexpected
   exceptions still render traces (by design, for local development); the
   401/403/404/405/422 envelopes are unaffected. `[source]`
4. Treat `config/authorization.php` as the single source of truth for role
   permissions. `[tests]`

### 10.2 Limitations of this phase

- **No live end-to-end password-reset email was sent** and **no persistent test
  user or company was created**, per the brief. Workstream A is verified by
  `[tests]` only; Workstream B is verified `[live]`.
- **No new PHPStan/Psalm run** — the project configures neither; only Pint
  (`laravel` preset) is available as a static check. Reported honestly rather
  than substituting a different tool. `[not verified]`
- The **Phase 21 "409" claim is corrected to 403** (§3); if the Phase 21 report
  records a 409 anywhere in its frontend narrative, the frontend report is the
  artefact to reconcile.
- Self-service first-company creation remains **open as a product decision**
  (§7.2) — no permission was widened.
- Two tracked 600 KB files `accounting-app/backend/accounting` and
  `accounting-app/backend/accounting_test` predate this phase, are tracked and
  unmodified, and were deliberately not touched; they look like database dumps
  committed earlier and are worth an owner decision. `[source]`

---

## 11. Final classification and next actions

### 11.1 Classification

**READY WITH DOCUMENTED LIMITATIONS**

Rationale: the four recorded findings are resolved with regression coverage; a
blocking migration defect was found and fixed; no API contract, authorization
boundary or accounting logic was changed or weakened. Remaining items are
operator configuration (`FRONTEND_URL`, `app:sync-roles`, `APP_DEBUG=false`) and
one recorded product decision — none is an unverified code defect.

### 11.2 Next actions

1. **Operator:** set `FRONTEND_URL`, run `app:sync-roles`, confirm
   `APP_DEBUG=false` — all documented in `accounting-app/docs/DEPLOYMENT.md`.
2. **Owner:** decide whether self-service first-company creation should ever exist
   (§7.2). Today's answer is "no, Admin-provisions" and that is tested.
3. **Owner:** decide whether the tracked `accounting` / `accounting_test` dump
   files in `backend/` belong in the repository (§10.2).
4. **Next phase:** the backend remediation phase is complete; no further backend
   changes are queued from this report.
