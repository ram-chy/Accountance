# CONTROLLED RELEASE AUDIT REPORT

**Report date:** 2026-10-17
**Audit type:** Read-only release verification (no development phase)
**Repository:** `D:\Development\Accountance` (backend `accounting-app/backend`, frontend `accounting-app/frontend`)
**Branch / HEAD at audit:** `main` @ `aecede2fa87fe3b69edf9870aeea0b4d2ae8e5e7`
**Working tree:** clean (tracked files unmodified; only ignored artifacts present)
**Subject of verification:** `accounting-app/docs/report/backend/BACKEND_RELEASE_REMEDIATION_REPORT.md` (classification: *READY WITH DOCUMENTED LIMITATIONS*)

> **Read-only guarantee.** No application file was created, modified, deleted or
> renamed. No commit, push, rebase, reset, tag or forced operation was executed.
> No migration, seeder, `migrate:fresh`, database reset, role sync, admin
> creation, or any mutating database command was run. No environment variable,
> permission, role or database row was changed. No secret, credential, token or
> personal value is reproduced in this report.

---

## 1. Executive summary

The repository is at the remediation commit `aecede2` ("Backend release
remediation: reset link, JSON auth errors, permission sync, onboarding"), which
is exactly one commit ahead of the state described by the frontend Phase 21
report (`f39b838`). The remediation diff matches the remediation report's own
file list precisely (verified with `git show --stat`), and the working tree is
clean.

**The four remediation workstreams are structurally present and internally
consistent in source.** Their dedicated regression tests exist and are
well-targeted, but **this audit did not execute the backend test suite**:
every affected feature test uses `RefreshDatabase`, which runs `migrate:fresh`
against the configured MySQL database `accounting_test`. That is explicitly
prohibited by the audit safety rules, and the test database is a persistent
local MySQL instance rather than an in-memory one, so the rule applies. Test
evidence is therefore recorded as **REPORTED, NOT INDEPENDENTLY RE-RUN**.

The audit confirms several facts independently by safe commands:

- `vendor/bin/pint --test` → **passed**.
- `php artisan route:list` → **215** routes; named routes `password.reset`
  (`GET api/auth/reset-password/{token}`) and `auth.password.reset`
  (`POST api/auth/reset-password`) are both present.
- `npx tsc --noEmit` (frontend) → **exit 0**.
- The remediation commit removed exactly the two duplicate migrations and added
  no migration, matching the report.
- The local `.env` has **`APP_DEBUG=true`, no `FRONTEND_URL` set, and
  `MAIL_MAILER=log`** — i.e. the running local environment cannot exercise the
  production password-reset or sanitized-error paths as configured.

**Two frontend-facing 409/403 items and one environment item are the material
findings.** The Phase 21 report's F-2 (reset link → API) and F-3 (500 on
non-JSON unauthenticated requests) are **stale**: both were fixed by `aecede2`,
which post-dates the Phase 21 report. The remaining live divergence is that the
**frontend BFF returns `409` for "no active company" while the backend returns
`403`**; the frontend UI does not depend on `409` anywhere, so this is a contract
and documentation inconsistency, not a runtime defect.

Two release-relevant risks can only be closed outside this audit: **per-
environment migration history** (unverifiable without production/shared access)
and **real email delivery + production configuration**. Both are marked
**NOT VERIFIED**.

---

## 2. Overall release classification

### **CONDITIONAL GO**

The code and configuration required for a release are present and coherent, and
no new code defect was found. Release may proceed **only after** the conditions
in §11–§13 are satisfied, the foremost being:

1. every target environment's migration history is inspected and reconciled
   before deploying (the two deleted migrations);
2. production configuration is correct (`APP_ENV=production`, `APP_DEBUG=false`,
   `FRONTEND_URL` set to the real SPA origin, non-`log` mail transport);
3. `php artisan app:sync-roles` and secure first-admin provisioning are performed
   per environment;
4. a real password-reset email is delivered and opened in a controlled staging
   environment.

It is **not GO** because migration history and live email delivery are unverified,
and the local environment's own `.env` is misconfigured relative to production.
It is **not NO-GO** because no source-level defect was found that blocks release
once those conditions are met.

---

## 3. Repository state and diff summary

| Item | Value |
|---|---|
| Branch | `main` |
| HEAD | `aecede2fa87fe3b69edf9870aeea0b4d2ae8e5e7` — "Backend release remediation: reset link, JSON auth errors, permission sync, onboarding" |
| Previous commit | `514514c` "Phase 21: frontend auth proxy, pagination tests and phase report" |
| Tracked modifications | **none** |
| Untracked (non-ignored) files | **none** |
| Ignored artifacts present | `backend/.env`, `backend/vendor/`, `backend/.phpunit.result.cache`, `backend/bootstrap/cache/*.php`, `backend/storage/**`, `backend/phase15-full.log`, `frontend/.next/`, `frontend/node_modules/`, `frontend/tsconfig.tsbuildinfo`, `frontend/next-env.d.ts`, **and `accounting-app/.git.bak/`** |
| Diff vs. remediation report's list | **Exact match** (`git show --stat aecede2`: 15 files, +1486/−71) |

The `aecede2` diff contains only: the four new backend support/test files, the
four modified backend files (`bootstrap/app.php`, `PasswordResetLinkController`,
`AppServiceProvider`, `.env.example`), the two **deleted** migrations,
`docs/DEPLOYMENT.md`, and the remediation report. No unrelated user changes were
found.

**Distinction of state:**

- The remediation report was authored at a working tree that included the Phase
  21 frontend artifacts as uncommitted files; those were later committed in
  `514514c` before `aecede2`. The repository state the report describes and the
  current state differ only by that commit ordering — the **content is
  consistent**.
- `accounting-app/.git.bak/` is an ignored Git backup directory. It is neither
  tracked nor part of the release; noted for repository hygiene only.

---

## 4. Findings by workstream

### Workstream A — Password-reset URL

**Status: present and internally consistent (source-verified).**

- **Link target.** `app/Providers/AppServiceProvider.php:99-104` registers
  `ResetPassword::createUrlUsing(...)` → `FrontendUrl::to('/reset-password', [...])`.
  `app/Support/FrontendUrl.php:25-37` builds
  `{origin}/reset-password?token=…&email=…`. The SPA page exists at
  `frontend/src/app/(public)/reset-password/page.tsx` and reads
  `searchParams.token` / `searchParams.email`, matching the link exactly.
- **Encoding.** `FrontendUrl.php:33` uses
  `http_build_query($query, '', '&', PHP_QUERY_RFC3986)`. This is not literally
  `rawurlencode`, but RFC 3986 mode is the correct equivalent: `+` becomes `%2B`
  and `@` becomes `%40`. Test coverage asserts
  `email=user%2Bledger%40example.com`.
- **No trusted-header influence.** `FrontendUrl::origin()` reads only
  `config('frontend.url')` (`config/frontend.php:24` → `env('FRONTEND_URL')`). No
  reference to `Host`/`X-Forwarded-Host` exists in `app/`.
- **Fail-safe.** `FrontendUrl::origin()` throws
  `App\Exceptions\FrontendUrlNotConfiguredException` (a `RuntimeException`,
  `app/Exceptions/FrontendUrlNotConfiguredException.php`) for empty or
  non-absolute-`http(s)` values (`FrontendUrl.php:49-53, 71-84`). It is **not**
  an `HttpExceptionInterface`, so it is not mapped to a dedicated envelope; it
  falls through to the catch-all renderer (§B below), which returns 500.
- **Uniform failure.** `PasswordResetLinkController::store()` calls
  `FrontendUrl::assertConfigured()` **before** `Password::sendResetLink()`
  (`app/Http/Controllers/Api/PasswordResetLinkController.php:29`), so a
  misconfigured deployment fails identically for known and unknown addresses.
- **Token probe preserved.** `routes/api.php` registers
  `GET api/auth/reset-password/{token}` named `password.reset`; confirmed live in
  `route:list`.
- **Enumeration protections.** The controller discards the broker result and
  always returns the same message (`:36-40`); the token probe returns one 400
  body for unknown-address and stale-token cases.

**Gap found (not a report dispute):** the enumeration-safety test
(`tests/Feature/Auth/PasswordResetNotificationTest.php:165-183`) only asserts
HTTP 500 for both known and unknown addresses; it does not assert the response
*body*. Because the test environment loads `.env` with `APP_DEBUG=true` (the test
suite does not override `APP_DEBUG`), the body under test is the framework debug
output, not the production sanitized envelope. The production-redaction guarantee
for this exception rests on the catch-all renderer's debug-off branch, which no
test exercises for this path.

**Residual risk (documented, not proven to be a defect):** the broker only sends
mail for a known address. If the mail transport throws, a known address could
surface a 500 while an unknown one returns 200 — a potential existence oracle
under a broken mailer. Tests use `Notification::fake()` and so do not cover this.

---

### Workstream B — API authentication error handling

**Status: present and internally consistent (source-verified; the live matrix was
not re-run by this audit but the mechanism is unambiguous).**

- **Mechanism.** `bootstrap/app.php:46` sets
  `$middleware->redirectGuestsTo(fn () => null)`, which neutralises the framework
  default guest redirect to the non-existent `login` route. `bootstrap/app.php:64-66`
  forces JSON rendering for `api/*`. `bootstrap/app.php:80-86` maps
  `AuthenticationException` to the 401 envelope
  `{"success":false,"message":"Unauthenticated."}`.
- **Accept-header independence.** Because `shouldRenderJsonWhen` returns true for
  every `api/*` request, the response is the same 401 JSON envelope for
  `application/json`, `*/*`, `text/html`, and no `Accept` header. The regression
  test (`tests/Feature/Api/ApiErrorHandlingTest.php:81-111`) covers all four
  variants across `/api/auth/me`, `/api/companies`, `/api/journals`, and a leak
  check (`:117-128`).
- **Debug behaviour (the precise truth).** Handled exception classes render
  fixed envelopes irrespective of `APP_DEBUG` (except that 4xx `HttpException`
  and `AuthorizationException` deliberately surface their own message). The
  **catch-all `Throwable` renderer** (`bootstrap/app.php:194-203`) returns `null`
  when `APP_DEBUG=true`, causing Laravel's default renderer to emit class,
  message, file/line and stack trace. With `APP_DEBUG=false` it returns a generic
  500 `{"success":false,"message":"An unexpected error occurred."}`. Therefore the
  "no stack trace" property **depends entirely on `APP_DEBUG=false` in
  production**.
- **Coverage gap.** Only `test_unexpected_errors_do_not_leak_details_when_debug_is_disabled`
  (`:60-76`) exercises the debug-off path; no test runs the catch-all with
  `APP_DEBUG=true` to assert there is *no* leak, and the default test environment
  has debug on.

---

### Workstream C — Role and permission synchronization

**Status: present and internally consistent (source-verified).**

- **Source of truth.** `config/authorization.php` (permission list and role→
  permission matrix, including `'Staff' => ['companies.view']` at lines 433-434).
  There is no seeder; the DB is derived.
- **Idempotency.** `app/Services/RolePermissionSynchroniser.php:29-39` creates
  missing permissions only; `:43-65` re-syncs a role only when it is new or has a
  missing declared permission (`array_diff` at line 60; guard at line 62). A
  second run is a genuine no-op.
- **Manual role permissions.** When a role *is* re-synced, `syncPermissions()`
  (`:63`) **replaces** the role's set, so undeclared grants to that role are
  dropped. An unchanged role is left alone. This "declarative caveat" is
  documented at `docs/DEPLOYMENT.md:76-79`.
- **User assignments / direct grants.** The synchroniser touches only the
  `permissions`/`roles` tables and the role-permission pivot; it never modifies
  user role assignments or direct user permission grants.
- **First-install / subsequent deployment commands.** `docs/DEPLOYMENT.md` §2
  (first install) and §3/§6 (run after any `config/authorization.php` change;
  idempotent) are correct and explicit.
- **`app:create-admin`.** Interactive; prompts for any missing value; **always**
  prompts for password confirmation (even when `--password` is passed), so it is
  not non-interactive-friendly. Validation `unique:users,email` prevents a
  duplicate for the same address, but there is **no guard on the number of
  administrators** — repeated runs with distinct emails create additional Admin
  accounts. It calls `ensureBaselineRoles()` (roles only, no permissions), so an
  Admin created before `app:sync-roles` has no permissions until the sync runs.

---

### Workstream D — First-company onboarding

**Status: present and internally consistent (source-verified).**

- **Workflow.** Registration assigns only `Staff`; `Staff` lacks
  `companies.create`; company creation therefore requires an Admin/Manager;
  membership is added via `POST /api/companies/{company}/members` (optionally
  `is_default`), with switching via `POST /api/companies/{company}/switch`.
- **No-company status — the 403/409 question.** The backend returns **403** with
  the message *"No company is associated with your account, or the selected
  company is inactive."* from `app/Http/Middleware/ResolveCompanyContext.php:54-59`.
  A header naming an existing non-member company returns **403**; an unknown id
  returns **404** (`:42-51`). There is **no 409 for company context anywhere in
  the backend**; the backend's 409s are accounting-conflict responses. The
  remediation report's correction to 403 is correct.
- **`/api/companies` (index)** is not company-scoped and returns 200 with an
  empty list for a company-less user; **`/api/auth/me`** always returns 200.
- **The 409 in the wild is the frontend BFF.** `frontend/src/app/api/backend/[...path]/route.ts:77-82`
  returns `409 "Select a company before making this request."` when a
  company-scoped family is requested with no active company in the session,
  *before* forwarding upstream. A `grep` of frontend `src` shows this is the only
  emitted 409; all other matches are comments about document-lifecycle conflicts.
  **No frontend code branches on a 409**, so the UI does not depend on it.
- **Non-member access is blocked** by CompanyPolicy (membership-first),
  `ResolveCompanyContext`, route-model binding scoped to the active company
  (404), and `CompanyService` (returns null / 422).

---

## 5. Verification evidence and commands executed

All commands were read-only or non-mutating.

| # | Command | Outcome |
|---|---|---|
| 1 | `git status --porcelain=v1 --branch`, `git rev-parse HEAD`, `git log -5 --oneline` | `main` @ `aecede2`; clean tracked tree |
| 2 | `git status --ignored --porcelain=v1` | only ignored artifacts (`.env`, `vendor/`, `.next/`, `node_modules/`, caches, `.git.bak/`) |
| 3 | `git show --stat --oneline aecede2` | 15 files, +1486/−71; matches report exactly |
| 4 | `git log --diff-filter=D` for `*currencies*`/`*exchange*` (delegated, read-only) | both `2026_10_03_*` files added in `b3b6e30`, deleted in `aecede2` |
| 5 | `php -v` | PHP 8.4.12 |
| 6 | `vendor/bin/pint --test` | **passed** |
| 7 | `php artisan route:list` (+ `--json`) | **215** routes; `password.reset` and `auth.password.reset` present |
| 8 | `npx tsc --noEmit` (frontend) | **exit 0** |
| 9 | `git ls-files` / `file` / read-only SQLite schema+row-count queries (delegated) | two SQLite DBs tracked; all PII tables 0 rows |
| 10 | `grep` of `.env`/`.env.example` for safe keys (no secret values) | local `.env`: `APP_DEBUG=true`, `MAIL_MAILER=log`, **no `FRONTEND_URL`** |

**Source files read directly** (not inferred): `bootstrap/app.php`,
`app/Support/FrontendUrl.php`, `config/frontend.php`,
`app/Http/Controllers/Api/PasswordResetLinkController.php`,
`app/Http/Middleware/ResolveCompanyContext.php`,
`app/Services/RolePermissionSynchroniser.php`,
`tests/Feature/Api/ApiErrorHandlingTest.php`,
`tests/Feature/Auth/PasswordResetNotificationTest.php`, `phpunit.xml`,
`docs/DEPLOYMENT.md`, `docs/report/frontend/FRONTEND_PHASE_21_REPORT.md`,
`frontend/src/app/api/backend/[...path]/route.ts`.

---

## 6. Checks not performed and reasons

| Not performed | Reason |
|---|---|
| Backend feature suites (password reset, API errors, role sync, onboarding) | Every affected test uses `RefreshDatabase`, which executes `migrate:fresh` against MySQL `accounting_test`. Rule 7 forbids `migrate:fresh`; the configured connection is a persistent MySQL database, not in-memory, so it is not confirmed disposable. Recorded as **NOT VERIFIED**. |
| Any `migrate`, `migrate:status`, `db:*` command | Prohibited without a confirmed disposable/test connection; production/shared access unavailable. |
| `app:sync-roles` / `app:create-admin` execution | Rule 9 forbids against shared/production; not run anywhere. |
| Live HTTP probing of unauthenticated endpoints (the B vs. Accept matrix) | Would require a running server and (for the misconfiguration path) writing test data; not performed. Mechanism verified by source instead. |
| Real password-reset email delivery / SMTP | Requires real mail delivery and a persistent user; `MAIL_MAILER=log` locally. **NOT VERIFIED**; not claimed. |
| Frontend `vitest run` full suite | Frontend is unchanged since the Phase 21 gate run (tree clean); §8 of the brief says not to re-run a lengthy suite when state has not changed materially. `tsc` was run as an independent spot check. Reported as prior evidence only. |
| Production configuration, HTTPS, secure cookies, monitoring | No deployed environment available. |
| Browser visual/accessibility pass | No tooling; out of scope. |

---

## 7. Migration-history risk assessment

**Risk: UNRESOLVED for remote environments; LOW for the local environment.**

Facts established from source and Git (safe, read-only):

- The two reported files
  (`2026_10_03_121646_create_currencies_table.php`,
  `2026_10_03_121658_create_exchange_rates_table.php`) existed in history
  (added `b3b6e30`) and were **deleted in `aecede2`** (the remediation commit).
- The replacement pair (`2026_10_09_170000_create_currencies_table.php`,
  `2026_10_09_170100_create_exchange_rates_table.php`) creates both tables and is
  the current schema owner. No later migration alters either table; the only
  structural dependency is a foreign key on `companies.currency_id` → `currencies`.
- The old and new pairs are **not schema-equivalent** (the old `currencies` was
  company-scoped with an `is_base` column; the new one is global with
  `UNIQUE(code)`; the old `exchange_rates` used cascading currency FKs, the new
  one uses `RESTRICT` plus audit columns). They are a redesign, not duplicates.
- Because the old filenames sort before the new ones, any environment in which
  both coexisted would have failed (`table already exists`, or the reported
  79-character index-identifier failure on MySQL). The remediation report states
  the old pair was `Pending` everywhere and had never run.
- The two tracked SQLite databases' `migrations` tables record 28 rows ending at
  `2026_10_04_140100_create_bank_reconciliation_items_table`; **neither deleted
  filename appears**, consistent with "never ran".

**Why it remains unresolved:** the safety rules forbid connecting to production
or shared databases, so the `migrations` tables of the real deployment
environment(s) could not be inspected. Deleting a migration file is safe for a
fresh, unrun database but can break an environment that already recorded those
migrations (e.g. a later `migrate:rollback` would fail to find the file/class).

**Mandatory pre-deployment action:** for **each** target environment, query its
`migrations` table and confirm that `2026_10_03_121646_create_currencies_table` and
`2026_10_03_121658_create_exchange_rates_table` are **absent** before deploying.
If that cannot be verified, treat the migration risk as unresolved and do not
deploy. Never infer safety from a locally passing fresh migration.

---

## 8. Database-dump privacy assessment

**Files:** `accounting-app/backend/accounting` and `accounting-app/backend/accounting_test`.
Both are **tracked** in Git and unmodified.

**Finding: they are not MySQL dumps; they are stale, near-empty SQLite databases.
Privacy risk: LOW.**

Evidence (structure and counts only; no values printed):

- Both files are **SQLite 3.x databases**, roughly 602 KB and 590 KB. The
  application's live and test connections are MySQL (`DB_CONNECTION=mysql`;
  test DB `accounting_test` in `phpunit.xml`), so these are not the production
  artifact format.
- Each contains 39 tables (including framework tables and `sqlite_sequence`).
- **Every user, business and personal-data table has 0 rows**: `users`, `sessions`,
  `password_reset_tokens`, `companies`, `customers`, `suppliers`, `accounts`,
  `journals`, `journal_lines`, invoices/bills/receipts/payments, cash/bank, etc.
- The only populated tables are framework metadata:
  `permissions` (67), `roles` (4), `role_has_permissions` (144), and `migrations`
  (28). These are role/permission *names* and a migration ledger — not personal
  or financial records.
- The schema is stale (ends at the Phase 9/early migration set): it lacks
  `taxes`, `fixed_assets`, `audit_logs`, `currencies`/`exchange_rates`, `budgets`,
  dimensions, etc., so it predates the current schema.
- Both were added as binaries in commit `4a8ba8a` ("Phase 9: Bank Reconciliation…")
  and never modified since.

**Conclusion:** **cannot be shown to contain real customer, personal or
accounting data — all such tables are empty.** The residual concerns are
repository hygiene and stale artifacts, not data exposure. The remediation
report flagged these for an owner decision; this audit concurs that the decision
is a hygiene one. No file was deleted or history rewritten.

---

## 9. Frontend/backend contract consistency findings

**Status: consistent except for one status-code/contract divergence and stale
Phase 21 statements.**

**Consistent (verified):**

- Reset flow: link params (`token`, `email` as query params) and path
  (`/reset-password`) match the SPA page and BFF handlers exactly.
- Error envelope: backend emits `{success:false,message,errors?}`
  (`ApiResponse`), matching what the BFF and clients parse.
- Company context: frontend sends `X-Company-Id`, matching
  `CompanyContext::HEADER`. Client-supplied values are dropped; the BFF injects
  the server-side active company.
- Auth-failure handling: the BFF clears the session on upstream `401` and passes
  other statuses/body through unchanged.

**Divergence (the material one):**

- **Backend** returns **403** for "no company / inaccessible company"
  (`ResolveCompanyContext.php:54-59`).
- **Frontend BFF** returns **409** for "no active company in session"
  (`frontend/src/app/api/backend/[...path]/route.ts:77-82`), *before* forwarding.
- **Impact:** none at runtime for normal UI use, because the UI decides the
  no-company state client-side (`activeCompany === null` → per-view "No company
  selected" empty state) and no frontend code branches on `409`. However, an API
  consumer sees two different statuses for related conditions, and the Phase 21
  report's 409 narrative is misleading. The remediation report already corrects
  this and names the frontend report as the artefact to reconcile. **This is a
  documentation/contract issue, not a code defect.** Recommended fix is a
  separate, narrowly scoped documentation change (or BFF status alignment), not
  part of this audit.

**Stale Phase 21 statements (fixed by `aecede2`, which post-dates the report):**

- F-2 "reset email links to the API" — **no longer true**; the link now targets
  `FRONTEND_URL/reset-password`.
- F-3 "non-JSON unauthenticated requests return a 500 trace" — **no longer true**;
  all Accept variants return the 401 JSON envelope (source-verified; the live
  matrix was not re-run).
- Minor report inaccuracies noted: a non-existent `void` journal endpoint is
  listed in the Phase 21 inventory, and the backend remediation report cites
  **216** routes where the current count is **215**.

---

## 10. Prioritized risks

### Critical
- **None demonstrated in source.** No defect was found that would corrupt
  accounting data or bypass authorization.

### High
- **H1 — Migration history unverifiable per environment.** Deleting the two
  migrations is safe only where they never ran. Must be checked per environment
  (§7). If unverifiable → unresolved.
- **H2 — `APP_DEBUG` dependence.** The debug-off sanitized 500 envelope is the
  only thing preventing stack-trace leakage for unhandled exceptions (including
  `FrontendUrlNotConfiguredException`). Production **must** set
  `APP_DEBUG=false`. The local `.env` currently has `APP_DEBUG=true`.
- **H3 — `FRONTEND_URL` unset in the local environment and untested in
  production.** The local `.env` has no `FRONTEND_URL`, so local
  forgot-password requests fail by design (500). Production correctness is
  unverified; only a real staging run can confirm it.
- **H4 — No live password-reset email has ever been delivered.** The emailed-link
  journey is unproven end to end (`MAIL_MAILER=log`).

### Medium
- **M1 — 403/409 contract divergence** between backend and BFF (§9); no UI
  impact, documentation reconciliation needed.
- **M2 — Two tracked SQLite databases** committed to the repository (hygiene;
  all PII tables empty) (§8).
- **M3 — `app:create-admin` has no cap on the number of admins** and always
  prompts for password confirmation (not automation-friendly). Repeated runs with
  distinct emails create extra Admin accounts.
- **M4 — The production sanitized-error path is not asserted by any test** (both
  the catch-all and the `FrontendUrlNotConfiguredException` case are exercised
  only with `APP_DEBUG=true` in the test environment).

### Low
- **L1 — Route count drift** (backend remediation report says 216; measured 215).
- **L2 — Malformed (non-alphanumeric) reset tokens produce 404**, not the 400
  used for stale-but-alphanumeric tokens (route constraint
  `[A-Za-z0-9]+`). Cosmetic API-shape inconsistency.
- **L3 — Stale Phase 21 report statements** (F-2/F-3) and minor inventory
  inaccuracies.
- **L4 — Frontend has no route-level `error.tsx`/`loading.tsx`** (pre-existing,
  non-blocking; noted by Phase 21).
- **L5 — `accounting-app/.git.bak/`** present in the working tree (ignored).

---

## 11. Environment-specific deployment prerequisites

For **every** target environment (documented by variable/command name only; no
actual values reproduced):

**Backend environment**
- `APP_ENV=production`, `APP_DEBUG=false`.
- `APP_KEY` and `JWT_SECRET` present and stable.
- `APP_URL` = the backend's public origin.
- `FRONTEND_URL` = the real public SPA origin, absolute `http(s)://host`
  (no inappropriate trailing path assumptions). Missing/malformed → password
  recovery returns 500 by design.
- `DB_*` = MySQL 8 with a verified backup/restore.
- A real mail transport (not `log`) with a confirmed working sender.
- HTTPS termination; secure cookies; production logging without secrets.

**Per-environment commands (run by an operator, not this audit)**
- `php artisan migrate --force` — confirm zero pending, **after** the migration-
  history check (§7).
- `php artisan app:sync-roles` — required on first install and after any
  `config/authorization.php` change; idempotent.
- `php artisan app:create-admin` — secure first-admin provisioning (interactive;
  strong password; no shared credentials).
- `php artisan config:cache` (and `route:cache` if applicable).

**Frontend environment**
- `API_BASE_URL` = backend `/api` origin reachable **server-to-server**.
- `SESSION_COOKIE_SECRET` ≥ 16 chars, high entropy, required in production
  (fails closed otherwise).
- `NODE_ENV=production`; HTTPS termination.

**Cross-cutting**
- **Migration-history review first** (§7); if it cannot be verified, do not
  deploy.

---

## 12. Ordered staging smoke-test checklist

Execute in a controlled staging environment with **synthetic data only**. Do not
run against production without explicit approval.

1. **Login / logout.** Sign in via the UI; confirm the session cookie is
   HttpOnly and (in a production-like build) `Secure`. Log out; confirm the
   session is cleared and protected routes redirect to login.
2. **`/api/auth/me` and token behaviour.** Authenticated call returns the current
   user; expired/invalid token → 401 JSON envelope and session cleared.
3. **Password reset end to end.** Request a reset for a synthetic user; confirm
   the email arrives; confirm the link begins with `FRONTEND_URL` and opens
   `/reset-password?token=…&email=…`; complete the reset; confirm the token is
   single-use (second use rejected); confirm the old password no longer works and
   existing sessions are invalidated.
4. **Missing-company behaviour.** Sign in as a user with no company; confirm a
   clear no-company state (not a crash, not a stack trace).
5. **Administrator creates a company.** As Admin, create a company; confirm it
   becomes the active context.
6. **Add a member and assign a default company.** Add a registered user by id,
   optionally `is_default: true`; confirm the member immediately has a usable
   context.
7. **Company switching / non-member rejection.** A member switches companies
   successfully; a non-member attempt is refused (403 for an existing company,
   404 for an unknown id).
8. **Permission-denied behaviour.** Attempt an action the role lacks; confirm a
   clean 403 and a non-breaking UI state.
9. **Chart of accounts read/write permissions.** Confirm permitted roles can
   read/write accounts and denied roles cannot.
10. **Journal lifecycle.** Create a draft journal; post a balanced journal
    successfully; attempt to post an unbalanced journal and confirm rejection;
    confirm a posted journal is immutable (edit/delete refused).
11. **Accounting-period closure.** Close a period; confirm posting into a closed
    period is refused.
12. **Ledger / account balance / trial balance.** Using posted entries, confirm
    the ledger, account balances and trial balance are mutually consistent and
    that debits equal credits.
13. **Frontend navigation and error presentation.** Navigate the main sections;
    confirm loading/empty/error states render cleanly and no raw server error or
    stack trace is shown to the user.
14. **Production-like logging.** Confirm server logs contain no stack traces or
    secrets in client responses, and that responses carry no sensitive headers.

---

## 13. Explicit production blockers and unresolved decisions

**Production blockers (must be closed before a production release):**
1. Per-environment migration-history verification for the two deleted migrations,
   or an explicit owner decision accepting the risk (§7). **Unresolved.**
2. Production config correctness: `APP_ENV=production`, `APP_DEBUG=false`,
   `FRONTEND_URL` set to the real SPA origin, non-`log` mail transport (§11).
3. Real password-reset email delivery verified in staging (never yet performed).
4. `app:sync-roles` executed per environment (a fresh DB has roles with zero
   permissions; every policy check fails until it runs).
5. Secure first-admin provisioning and credential handling.

**Unresolved decisions (owner):**
- Whether self-service first-company creation should ever exist, or whether
  Admin-provisioning remains the model (the remediation report records this as
  the one open product question). No permission was widened.
- Whether the tracked SQLite databases (`accounting`, `accounting_test`) belong
  in the repository.
- Whether to reconcile the BFF `409` with the backend `403` (alignment or
  documentation-only).

---

## 14. Recommended next actions

### Required before staging
- Set staging `FRONTEND_URL`, `APP_ENV`, a real mail transport, and
  `APP_DEBUG=false`; verify `app:sync-roles` and first-admin provisioning on the
  staging database.
- Inspect the staging `migrations` table for the two deleted filenames (§7).

### Required before production
- Complete the migration-history check for production (or obtain a documented
  owner acceptance of the risk).
- Verify a real password-reset email end to end (link origin, `/reset-password`
  path, `+`-encoded addresses, single-use token).
- Confirm production `APP_DEBUG=false` and run the §12 smoke-test checklist.
- Confirm verified database backups before any schema change.

### Optional improvements
- **Documentation:** update the Phase 21 report (or add an addendum) to mark F-2
  and F-3 resolved by `aecede2`, and correct the 409 narrative to state the
  backend returns 403 and the 409 originates in the BFF. Correct the `void`
  journal inventory entry and the 216→215 route count.
- **Tests:** add a test asserting the sanitized 500 body with `APP_DEBUG=true`
  *disabled* explicitly, and one asserting the production (debug-off) body for
  the `FrontendUrlNotConfiguredException` path, so the redaction guarantee is
  pinned rather than inferred.
- **Hardening:** consider guarding `app:create-admin` against creating an
  unintended number of administrators, and making password confirmation
  skippable under an explicit flag for automation.
- **Hygiene:** decide on the tracked SQLite databases and remove the ignored
  `.git.bak/` directory.
- **Robustness:** document or align the BFF `409` vs backend `403` no-company
  status.

---

## 15. Statement of verification boundaries

- This audit is a **local, read-only** verification. A passing local formatter,
  route listing, or type-check does **not** establish production configuration,
  email delivery, migration history, permission seeding, or onboarding
  correctness.
- The backend regression suites and any database-touching command were **not**
  executed, by design, because they would run `migrate:fresh` against a
  persistent MySQL database. Their prior results are recorded as **reported, not
  independently re-run**.
- Migration history for any environment other than the ones inspected remains
  **NOT VERIFIED**; the migration risk is therefore **unresolved** pending the
  per-environment check in §7.
- No claim of production readiness is made. The classification is **CONDITIONAL
  GO** subject to §11–§13.

*End of audit. No fixes were implemented; implementation should proceed only
under a separate, narrowly scoped approved task.*
