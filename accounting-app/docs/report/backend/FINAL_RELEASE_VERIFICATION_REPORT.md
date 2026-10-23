# FINAL RELEASE VERIFICATION REPORT

**Latest revision:** 2026-10-17 (Pass 4 — staging readiness execution: access check; staging unavailable, operator checklist issued)
**Prior revisions:** 2026-10-17 (Pass 3 — target-environment migration readiness audit) ·
2026-10-17 (Pass 2 — fresh verification pass) ·
2026-10-17 (Pass 1 — finalized before the backend suite was re-run)
**Repository:** `D:\Development\Accountance`
**Branch / HEAD:** `main` @ `aecede2fa87fe3b69edf9870aeea0b4d2ae8e5e7`
("Backend release remediation: reset link, JSON auth errors, permission sync, onboarding")
**Purpose:** Controlled, verification-focused pass over the Accountance Accounting Web App
release candidate. This revision re-executes the gates Pass 1 could not, and corrects
statements that later evidence contradicted.

**Evidence legend** (every conclusion is tagged):
`[cmd]` a command executed during this run · `[source]` source inspection ·
`[prior]` recorded in a previous report earlier in this session ·
`[n/v]` NOT VERIFIED.

---

## 0. Why there is a Pass 2

Pass 1 (`[prior]`) was closed at the operator's request **before** the full backend
suite was re-run. It therefore recorded the full backend suite, the BFF
handler-level test, and local migration-history evidence as **NOT VERIFIED**. This
pass executes those gates and records the results. Pass-1 findings are preserved
below under **Appendix A** and are explicitly reconciled in §14 where new evidence
changes the conclusion. No prior finding has been deleted.

---

## 1. Executive summary

The working tree is the approved change set plus one new test file added by this
pass. `[cmd]`

- **Full backend suite (fresh): PASS** — `php artisan test` → exit `0`,
  **1269 passed / 0 failed, 5651 assertions**, ~1049 s. `[cmd]`
- **Test database safety (fresh): CONFIRMED DISPOSABLE** — the effective test
  connection is MySQL `accounting_test` on `127.0.0.1:3306`, freshly migrated to
  batch 1 with **0 users / 0 companies / 0 business rows**. `[cmd]`
- **BFF no-active-company regression coverage: ADDED AND PASSING** — a focused
  route-handler test now pins the `403` + JSON envelope + "no upstream fetch"
  contract (corrects Pass-1 §5.3). `[cmd]`
- **Frontend (fresh): PASS** — Vitest **111 files / 1089 tests** pass; `tsc --noEmit`
  exit `0`; `npm run lint` (ESLint, the project's supported linter) exit `0`. `[cmd]`
- **Migration history: partially verified, still BLOCKING for target
  environments** — local dev/test DBs are on the current schema, but per-environment
  (staging/production) history cannot be verified from this workstation. A renamed
  migration left a stale record in the local dev DB, demonstrating the exact risk. `[cmd]`/`[n/v]`
- **SQLite artefacts: re-confirmed as stale, synthetic, low-privacy-risk.** `[cmd]`

The release remains **CONDITIONAL GO**. The code-level gates now pass, but the
environment-specific prerequisites (per-environment migration history, production
configuration, real mail, role sync, first-admin provisioning, backup/restore) are
operator actions on systems this workstation cannot see, and none are verified here. `[n/v]`

## 2. Overall classification

### **CONDITIONAL GO**

Code verification is now complete and green (backend suite, frontend suite,
type-check, lint, BFF handler test). Promotion to `GO` remains blocked on
per-environment migration-history verification and the staging/production
prerequisites in §9, §12 and §13.

---

## 3. Current Git state

`[cmd]` Inspected during this pass:

- Branch `main`, HEAD `aecede2fa87fe3b69edf9870aeea0b4d2ae8e5e7`.
- **Working tree (uncommitted, nothing staged, nothing pushed):**

  Modified (pre-existing, approved — unchanged by this pass):
  - `accounting-app/backend/tests/Feature/Auth/PasswordResetNotificationTest.php`
  - `accounting-app/docs/FRONTEND_BLUEPRINT.md`
  - `accounting-app/docs/report/backend/BACKEND_RELEASE_REMEDIATION_REPORT.md`
  - `accounting-app/docs/report/frontend/FRONTEND_PHASE_21_REPORT.md`
  - `accounting-app/docs/report/frontend/FRONTEND_PHASE_3_REPORT.md`
  - `accounting-app/frontend/src/app/api/backend/[...path]/route.ts`

  Untracked — pre-existing reports:
  - `accounting-app/docs/report/backend/CONTROLLED_RELEASE_AUDIT_REPORT.md`
  - `accounting-app/docs/report/backend/RELEASE_FIXES_REPORT.md`
  - `accounting-app/docs/report/backend/FINAL_RELEASE_VERIFICATION_REPORT.md`

  **Untracked — added by this pass:**
  - `accounting-app/frontend/src/app/api/backend/[...path]/route.test.ts`

- The diff matches the approved release-fix claim: same six modified files, no
  deletions or renames, no unrelated changes. `[cmd]`
- Tracked SQLite files (`accounting-app/backend/accounting`,
  `accounting-app/backend/accounting_test`) are **unchanged vs. HEAD**
  (`git diff --stat HEAD` empty). `[cmd]`
- No staged changes; nothing committed, pushed, deployed, reset, restored,
  cleaned, or stashed at any point in this pass. `[cmd]`

**No unexpected user changes were found.** The working tree is byte-for-byte the
approved change set plus the one new test file listed above.

## 4. Backend full-suite results (fresh)

**Status: VERIFIED in this pass.** `[cmd]`

| Item | Value |
|---|---|
| Command | `php artisan test` (run from `accounting-app/backend`) |
| Exit code | **0** |
| Result | **passed** |
| Tests | **1269** |
| Passed | **1269** |
| Failed / errored / skipped | 0 / 0 / 0 |
| Assertions | **5651** |
| Duration | 1,049,020 ms (~1049 s, ≈17.5 min) |
| Raw summary | `{"tool":"phpunit","result":"passed","tests":1269,"passed":1269,"assertions":5651,"duration_ms":1049020}` |

This supersedes the Pass-1 `[n/v]`. The count differs from the historical
`1268 / 5646` (`[prior]`) by **+1 test / +5 assertions**, which is exactly the
working-tree `PasswordResetNotificationTest` regression test
(`test_a_missing_frontend_origin_returns_a_sanitised_body_when_debug_is_disabled`)
that is part of the approved change set. `[source]`

### 4.1 Test-database safety gate — CONFIRMED `[cmd]`

- **Configuration source:** `backend/phpunit.xml` overrides the environment —
  `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=accounting_test`,
  `DB_URL=""`, `CACHE_STORE=array`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`.
  There is **no** `.env.testing`, and **no config cache** (`bootstrap/cache/`
  contains only `packages.php` and `services.php`; no `config.php`). `[cmd]`
- **Effective connection at run time:** proved with a throwaway test that reported
  `mysql|mysql|accounting_test` (then deleted). `[cmd]`
- **Disposability:** after the suite, `accounting_test` is freshly migrated to
  **batch 1 with exactly 55 migrations**, and holds **0 users, 0 companies**, and
  **0 rows** in `journals`, `journal_lines`, `accounts`, `currencies`,
  `exchange_rates`, `accounting_periods`, `sessions` and `password_reset_tokens`.
  A separate dev DB `accounting` exists (MySQL, `127.0.0.1`, synthetic test data);
  the suite does **not** use it. `[cmd]`
- **Conclusion:** the suite ran against a disposable test database. The historical
  full-suite result is **not** being represented as fresh; the fresh run above is. `[cmd]`

## 5. Frontend verification and the BFF 403 contract (fresh)

### 5.1 Test / type-check / lint results

| Check | Command | Result |
|---|---|---|
| Unit tests (full) | `npx vitest run` | **111 files / 1089 tests passed**, exit 0 `[cmd]` |
| BFF route test (focused) | `npx vitest run "src/app/api/backend/[...path]/route.test.ts"` | **6 tests passed**, exit 0 `[cmd]` |
| Types | `npx tsc --noEmit` | **exit 0** `[cmd]` |
| Lint | `npm run lint` → `eslint` | **exit 0** `[cmd]` |

The full Vitest count rose from the Pass-1 `110 files / 1083 tests` (`[prior]`) to
`111 / 1089`, i.e. the single new route-test file plus its 6 tests. `[cmd]`

**Linter note:** the installed Next.js is **16.4.0**. `next lint` is not the
supported entry point here; `package.json` defines `"lint": "eslint"` (ESLint 9),
so that is the command run. `[source]`/`[cmd]`

### 5.2 BFF 403 contract `[source]`

`src/app/api/backend/[...path]/route.ts` returns **403** with the standard JSON
envelope (`jsonFailure("Select a company before making this request.", 403)`) when
a company-scoped route is called with no active company, and returns **before**
any upstream `fetch`. This matches Laravel's own company-context refusal
(`app/Http/Middleware/ResolveCompanyContext.php`, no-company ⇒ 403). `[source]`

### 5.3 Route-handler regression test — ADDED `[cmd]`

**Status: ADDED AND PASSING.** This corrects Pass-1 §5.3 (which recorded "NOT
ADDED"). See §14 for the reconciliation.

New file: `accounting-app/frontend/src/app/api/backend/[...path]/route.test.ts`
(6 tests). It exercises the exported `GET`/`POST` handlers directly with a real
`NextRequest`, mocks **only** `@/lib/auth/session` via `vi.mock` (no `next/headers`
or crypto mocking needed), and stubs global `fetch`:

1. No session ⇒ **401** envelope; `fetch` **not** called.
2. Signed-in, no active company, `POST /journals` ⇒ **403**, exact envelope
   `{success:false, message:"Select a company before making this request."}`,
   `fetch` **not** called, and no `set-cookie`/`www-authenticate` leak.
3. Same refusal for a company-scoped **GET** (`/accounts`).
4. With an active company ⇒ upstream `fetch` **is** called once, with
   `Authorization: Bearer …` and `X-Company-Id: 7`, and the upstream status passes
   through.
5. Non-company-scoped family (`/users`) is not gated on a company (no
   `X-Company-Id`) — proves the gate is specific, not a blanket block.
6. Upstream `401` still clears the BFF session (`clearSession` called once).

This satisfies the required assertions: 403 status, established JSON envelope,
rejection before upstream fetch, no sensitive leakage, and unchanged
authenticated/company-scoped behaviour.

## 6. Migration-history assessment

`[source]`/`[cmd]` — source review plus read-only inspection of the local DBs. No
migration was run against any shared/production DB; none was modified.

**The replacement.** The two Phase-14 files were deleted in HEAD (`aecede2`);
their replacements were added earlier in Phase 14 (`aa52bd4`):

| Old (deleted in HEAD) | Current replacement |
|---|---|
| `2026_10_03_121646_create_currencies_table.php` | `2026_10_09_170000_create_currencies_table.php` |
| `2026_10_03_121658_create_exchange_rates_table.php` | `2026_10_09_170100_create_exchange_rates_table.php` |

The old schemas were **company-scoped** (`currencies.company_id`, `is_base`;
cascade FKs). The replacements are a **redesign**: `currencies` is global
reference data (`UNIQUE(code)`, no `company_id`, no `is_base`); `exchange_rates`
stays company-scoped with `RESTRICT` FKs and audit columns. `[source]`

**What the migrator would see:**

- **Fresh database:** only the replacements exist → clean creation; no conflict.
  Verified in practice: `accounting_test` was `migrate:fresh`-ed by the suite to
  batch 1 with exactly the 55 on-disk migrations. `[cmd]`
- **Database that ran the old files:** its `migrations` table records
  `…_2026_10_03_121646…` / `…_121658…` (files no longer on disk → orphaned
  records), while both replacements are unrecorded ⇒ **pending**. `php artisan
  migrate` would then attempt `Schema::create('currencies')` on a database that
  already has a `currencies` table ⇒ **"table already exists"** failure, aborting
  the batch. Even if creation were skipped, the live schema would still be the
  old company-scoped shape while the application expects the new global shape ⇒
  runtime column/constraint mismatch. `[source]`
- **Partially-applied database:** fails at the first create statement; later
  currency migrations (`…_170300` onward) do not run. `[source]`
- **Risk classes:** duplicate-table (primary), missing/extra-column
  (`is_base`/`company_id`), and FK-shape mismatch. No data-conversion script is
  proposed here. `[source]`

**Local evidence gathered this pass** `[cmd]`:

- `accounting_test` (test DB): 55 migrations, batch 1, no orphans — current. `[cmd]`
- `accounting` (dev DB): 56 records, batches up to 8, and an **orphaned** record
  `2026_10_08_104345_create_budget_line_dimensions_table` sitting beside the
  current `2026_10_10_100200_create_budget_line_dimensions_table` (Phase 17,
  `bae1a4a`). The `2026_10_08_104345_*` file never existed in committed history —
  it was renamed before commit and the stale record was left in that local DB.
  This is the **same failure class** as the currency replacement, observed live. `[cmd]`

**Environment-specific migration history: NOT VERIFIED — blocking.** `[n/v]`
Whether staging/production hold records for the two removed currency migration
names (and/or the renamed budget migration) cannot be established from this
workstation. It must be checked per environment **before** running
`php artisan migrate`. See §11 for the read-only operator checklist.

## 7. Tracked SQLite files and repository hygiene

`[cmd]` Inspected read-only (opened with `SQLITE3_OPEN_READONLY`); nothing
deleted, untracked, or modified.

- **Files / type / size:** `accounting-app/backend/accounting` (602,112 bytes) and
  `accounting-app/backend/accounting_test` (589,824 bytes) are **SQLite 3.x**
  databases (header `SQLite format 3`). `[cmd]`
- **Git status:** both are **tracked**, added in Phase 9 (`4a8ba8a`) and never
  modified since; each matches HEAD exactly. `[cmd]`
- **Schema:** 38 tables each; `migrations` records 28 rows; **no `currencies` or
  `exchange_rates` tables** (they predate Phase 14). `[cmd]`
- **Contents (row counts only):** all business/PII tables are empty. In
  `accounting`, only `permissions` (67), `roles` (4), `role_has_permissions`
  (144) and `migrations` (28) are populated (243 rows total). In `accounting_test`,
  only `migrations` (28). No users, companies, journals, accounts, tokens,
  sessions, or password-reset rows. `[cmd]`
- **Sensitive data:** none observed. No personal data, credentials, password
  hashes, tokens or secrets were found, and no values were printed. `[cmd]`
- **Required?** No. The active connection is MySQL (`config/database.php` default
  `mysql`; `.env` `DB_CONNECTION=mysql`); the SQLite connection points at
  `database/database.sqlite`, not these files. They are orphaned Phase 9
  artefacts. `[source]`

**Classification: stale, synthetic, LOW privacy risk — repository-hygiene
concern, not disclosure.** Per the constraints, neither file was removed or
untracked; cleanup is deferred to a separately approved task. Byte-level assurance
of every page was not attempted, so this is a structural/row-count classification,
not a full content guarantee. `[n/v]` for byte-level assurance.

## 8. Contract and configuration consistency

`[source]`/`[cmd]`

- **Password-reset URL:** built by `App\Support\FrontendUrl` from
  `config('frontend.url')` as `{FRONTEND_URL}/reset-password?token=…&email=…`
  (RFC 3986 encoding via `http_build_query(..., PHP_QUERY_RFC3986)`), wired in
  `AppServiceProvider::configurePasswordResetLinks()` (`ResetPassword::createUrlUsing`).
  The SPA route `src/app/(public)/reset-password/page.tsx` reads `token`/`email`.
  **Consistent.** `[source]`
- **Missing `FRONTEND_URL` in production-like config:** `PasswordResetLinkController`
  calls `FrontendUrl::assertConfigured()` **before** consulting the broker, so the
  failure is identical for known and unknown addresses (no account-existence
  leak), and with `APP_DEBUG=false` it degrades to the generic 500 envelope. This
  is exactly what the approved regression test asserts. **Consistent.** `[source]`
- **Unauthenticated API requests:** `bootstrap/app.php` sets
  `redirectGuestsTo(fn () => null)` and renders `AuthenticationException` as the
  JSON `401` envelope. **Consistent.** `[source]`
- **Company-scoped request with no active company:** backend returns **403**
  (`ResolveCompanyContext` no-company branch); the BFF now returns **403** too
  (§5.2/§5.3). **Consistent.** `[source]`/`[cmd]`
- **Journal posting / immutability:** only `POST /journals/{journal}/post` exists
  (no `void` route). `JournalService::updateDraft`/`deleteDraft` refuse posted
  journals; documentation matches. **Consistent.** `[source]`
- **First-company onboarding / admin provisioning:** the documented model is
  `app:sync-roles` + `app:create-admin` (interactive, no default credentials);
  both commands exist and are registered. **Consistent.** `[source]`/`[cmd]`
- **Role/permission sync:** `app:sync-roles` syncs `config/authorization.php`;
  documentation matches. **Consistent.** `[source]`/`[cmd]`

## 9. Deployment and staging readiness

`[source]`/`[cmd]`/`[n/v]` — assessed on evidence, not on documentation claims.
No deployment, provisioning, or mail delivery was performed.

| # | Prerequisite | Status | Evidence |
|---|---|---|---|
| 1 | Per-environment migration history/schema verified | **NOT VERIFIED (blocking)** | §6; local DBs verified only |
| 2 | DB backup and restore procedure demonstrated | **NOT VERIFIED** | operator action |
| 3 | `APP_ENV` and `APP_DEBUG=false` for production | **NOT VERIFIED** | local `.env` is `local`/`true` |
| 4 | `FRONTEND_URL` + backend/BFF config confirmed | **NOT VERIFIED** | required; drives reset link |
| 5 | HTTPS + secure cookie settings confirmed | **NOT VERIFIED** | `secure` derived from `NODE_ENV` at runtime |
| 6 | `app:sync-roles` planned and verified | **NOT VERIFIED** (command exists) | §8 |
| 7 | First administrator provisioned securely | **NOT VERIFIED** (command exists) | §8 |
| 8 | Non-`log` mail transport configured | **NOT VERIFIED** | local `MAIL_MAILER=log` |
| 9 | Real password-reset email delivery tested | **NOT VERIFIED** | needs staging |
| 10 | Reset token single-use behaviour verified | **NOT VERIFIED** | needs staging |
| 11 | Staging smoke tests (auth, company, permissions, accounts, journals, periods, ledger, trial balance) | **NOT VERIFIED** | §10 checklist |
| 12 | Logs reviewed for sensitive-data exposure | **NOT VERIFIED** | operator action |

No secrets, credentials, cookies or PII were printed or inspected.

## 10. Ordered staging smoke-test checklist

`[source]` For a human operator in a controlled staging environment with synthetic
data only. Do not run against production.

1. Log in, log out, and let the session expire; confirm the expected redirect/401.
2. Confirm an unauthenticated API call receives the documented 401 JSON envelope.
3. Request a password reset; confirm the email is actually delivered (non-`log`).
4. Open the reset link; confirm the SPA loads the reset form.
5. Complete the reset; confirm success and that reusing the token is rejected.
6. Register a user; confirm the expected initial role.
7. As a user with no company, confirm the documented **403** (backend and BFF).
8. As admin, create a company.
9. As admin, add a member and set a default company.
10. Switch companies; confirm non-member access is rejected.
11. Trigger a permission-denied path; confirm 403.
12. Exercise chart-of-accounts operations with and without the permission.
13. Post a balanced journal; attempt an unbalanced one; confirm rejection.
14. Confirm a posted journal is immutable.
15. Exercise accounting-period closure behaviour.
16. Verify ledger, account-balance and trial-balance consistency from posted entries.
17. Navigate the frontend; confirm error handling and session behaviour.
18. Confirm production-like error responses are sanitized and logs are safe.

## 11. Read-only migration-history checklist for the operator

`[source]` Run per target environment (staging, then production) **before**
`php artisan migrate`. Read-only; exclude credentials from output.

1. Record current state:
   `php artisan migrate:status` — capture pending/ran counts.
2. Detect stale records for removed/renamed files (names only), e.g. against the
   `migrations` table:
   - any row for `2026_10_03_121646_create_currencies_table`
   - any row for `2026_10_03_121658_create_exchange_rates_table`
   - any row for `2026_10_08_104345_create_budget_line_dimensions_table`
3. Inspect the existing `currencies` shape (columns only, no data): does it have
   `company_id` / `is_base` (old) or `UNIQUE(code)` without `company_id` (new)?
4. If stale/orphan records are present, **stop** and plan a manual, reviewed
   reconciliation (do not insert fake migration rows, rename files, or run
   `migrate:fresh`). Back up first; rehearse restore.
5. Only after 1–4 are signed off, run `php artisan migrate --force` in staging.

> **Superseded in detail by §16.** The Pass-3 audit (§16) expands this into the
> full readiness procedure: exact structure-only queries (§16.3/§16.4), stop
> conditions (§16.5), backup/restore evidence (§16.6), staging configuration
> checks (§16.7) and the ordered operator checklist (§16.8). Use §16 for a
> staging deployment; §11 remains the short form.

## 12. Findings

### Critical
None identified.

### High
- **H1 — Per-environment migration history unverified.** `[n/v]` Blocking (§6, §11).
  A local dev DB already shows an orphaned renamed-migration record, confirming
  the risk is real.
- **H2 — Deployment/staging prerequisites unverified.** `[n/v]` §9 items 2–12 are
  operator actions (production config, HTTPS/cookies, mail, reset delivery,
  role sync, first admin, backup/restore, log review).

### Medium
- **M1 — Stale `App\Services\Accounting\Currencies\*` dead code.** `[cmd]` The
  namespace `App\Services\Accounting\Currencies` (`CurrencyService.php`,
  `ExchangeRateService.php`) still targets the **old** company-scoped schema
  (`company_id`, `is_base`), but nothing references it — the live path is
  `App\Services\Accounting\Currency\*` (matching the global schema), and the
  controller imports the live one. No runtime impact today; it is misleading and
  would break if invoked. Recommend removing in a separate approved task.
- **M2 — Tracked SQLite databases.** §7. Low privacy risk; hygiene concern.
- **M3 — BFF/no-company status history.** Pass 1 recorded "409 → 403" as a BFF-only
  change; it is aligned with the backend 403. Keep for history.

### Low
- **L1 — Renamed migration left an orphan record in the local dev DB** (Phase 17
  `budget_line_dimensions`). §6. Local only; covered by the H1 operator checklist.
- **L2 — Malformed reset token returns 404, not 400.** Unchanged from audit.
- **L3 — No route-level `error.tsx` in the frontend.** Unchanged from audit.
- **L4 — `accounting-app/.git.bak/` present (ignored).** Unchanged; must not be removed.

## 13. Checks that remain NOT VERIFIED

- Per-environment (staging/production) migration history and schema.
- Production/staging configuration, HTTPS, secure cookies, mail transport.
- Real password-reset email delivery and token single-use flow.
- Role-permission sync and first-admin provisioning in a real environment.
- Database backup/restore rehearsal.
- Log review for sensitive-data exposure.
- Byte-level assurance of the tracked SQLite files.

## 14. Reconciliation with Pass 1

Prior Pass-1 statements are preserved in Appendix A. Where new evidence changes a
conclusion, the correction is recorded here:

| Pass-1 statement | Pass-2 evidence | Reconciliation |
|---|---|---|
| Full backend suite **NOT VERIFIED** (§4) | `php artisan test` exit 0; 1269 passed / 5651 assertions `[cmd]` | **CORRECTED** → VERIFIED. Historical 1268/5646 is not treated as fresh; the +1/+5 delta is the approved regression test. |
| Test DB "57 tables, 0 users, 0 companies" `[prior]` | Effective connection `mysql\|accounting_test`; after fresh run: 55 migrations, batch 1, 0 users/0 companies `[cmd]` | **CONFIRMED** (and now positively tied to `accounting_test`). An earlier read that appeared to show 12 users in `accounting_test` was an artefact of Laravel connection memoisation reading the dev DB; corrected. |
| BFF handler test **NOT ADDED** — "requires broad mocking infrastructure" (§5.3) | Added with a single `vi.mock("@/lib/auth/session")`; 6 tests pass `[cmd]` | **CORRECTED** → the missing capability was overstated. The narrow mock is sufficient; the harness did not need `next/headers`/crypto mocking. |
| Frontend lint **NOT VERIFIED** (L1) | `npm run lint` (ESLint) exit 0 `[cmd]` | **CORRECTED** → VERIFIED. `next lint` is not supported on Next 16.4.0; `eslint` is. |
| Migration: local/test evidence **NOT VERIFIED** (§6) | `accounting_test` clean at batch 1; dev `accounting` shows an orphaned renamed-migration record `[cmd]` | **PARTIALLY ADDRESSED** → local evidence now exists; per-environment remains NOT VERIFIED and blocking. |
| SQLite files synthetic / low risk (§7) | Re-inspected read-only; 38 tables, business tables empty, no PII `[cmd]` | **CONFIRMED** (unchanged). |

## 15. Recommended next actions

1. **Verify per-environment migration history** using §11 **before** any
   `php artisan migrate`; reconcile any orphaned currency/budget migration records
   under review, with a tested backup/restore.
2. **Verify production configuration** (`APP_ENV`, `APP_DEBUG=false`,
   `FRONTEND_URL`, mail transport, HTTPS/secure cookies).
3. **Run `app:sync-roles`** per environment after deploy; **provision the first
   admin securely** (never bootstrap credentials in code).
4. **Rehearse a DB backup/restore**; confirm logging does not leak secrets/PII.
5. **Execute the §10 smoke checklist** against staging with synthetic data,
   including real reset-email delivery and token single-use.
6. **Open a separate approved task** for hygiene: remove the dead
   `App\Services\Accounting\Currencies\*` namespace; decide on the tracked SQLite
   files and `.git.bak/`.

---

## 16. Target-Environment Migration Readiness Audit (Pass 3)

**Scope:** prepare a safe **staging** deployment by auditing migration-history
compatibility and deployment prerequisites. This pass is **read-only**: no
migration, seeder, role sync, admin provisioning, or deployment command was run;
no database row, migration record, or migration file was created, changed, or
deleted; nothing was committed or pushed. `[cmd]`

**Evidence rule:** only checks actually executed on this workstation are marked
`VERIFIED`. Every staging/production item is marked **NOT VERIFIED** until an
authorized operator supplies results. No remote result is invented.

### 16.1 Local evidence actually available

`[cmd]` Read-only inspection of the repository and the two **local** MySQL DBs
(`127.0.0.1`), using `information_schema` structure queries and `COUNT(*)` only
(no row content).

| Check | `accounting` (local dev) | `accounting_test` (local test) |
|---|---|---|
| Migrations recorded | 56 | 55 |
| Max batch | 8 (incremental) | 1 (fresh) |
| `2026_10_03_121646_create_currencies_table` | absent | absent |
| `2026_10_03_121658_create_exchange_rates_table` | absent | absent |
| `2026_10_08_104345_create_budget_line_dimensions_table` (orphan) | **present** | absent |
| `2026_10_09_170000_create_currencies_table` | present | present |
| `2026_10_09_170100_create_exchange_rates_table` | present | present |
| `currencies.company_id` / `is_base` | absent | absent |
| `currencies` unique index | `currencies_code_unique(code)` | `currencies_code_unique(code)` |
| `exchange_rates` audit cols `created_by`/`updated_by` | present | present |
| `exchange_rates` FK from/to currency `ON DELETE` | `RESTRICT` | `RESTRICT` |

**Interpretation (local only):** both local databases carry the **current global**
currency schema and the **current** exchange-rate schema. The local dev DB is the
only place where an orphaned renamed-migration record was observed
(`2026_10_08_104345_create_budget_line_dimensions_table`, batch 7, sitting beside
the current `2026_10_10_100200_create_budget_line_dimensions_table`, batch 8).
That is a live example of the migration-history drift this audit guards against.
`[cmd]`

**Repository-level migration history:** across the entire committed history there
are exactly **two** deleted migration files and **zero** renames
(`git log --diff-filter=D` / `--diff-filter=R`). `[cmd]`

### 16.2 Migration names that may remain in an environment's `migrations` table

An environment's `migrations` table can reference a file that no longer exists on
disk (an *orphaned* record). Three names are relevant:

1. **Removed in HEAD (`aecede2`) — primary orphans:**
   - `2026_10_03_121646_create_currencies_table`
   - `2026_10_03_121658_create_exchange_rates_table`
2. **Renamed (never committed; observed only in the local dev DB):**
   - `2026_10_08_104345_create_budget_line_dimensions_table`
     → replaced by `2026_10_10_100200_create_budget_line_dimensions_table`
     (Phase 17, `bae1a4a`).

An orphaned currency record is the dangerous case: the replacement files
(`2026_10_09_170000`/`170100`) are then unrecorded ⇒ **pending**, and the migrator
would attempt `Schema::create('currencies')` on a table that already exists. An
orphaned budget record is a lesser case (the table already exists and the current
file is recorded), but it still signals history drift and must be accounted for.

### 16.3 Schema metadata that distinguishes old vs new currency schema

The two designs share the table name `currencies`, so table existence alone
proves nothing. Inspect **structure only**:

| Signal | Old (company-scoped) | Current (global reference) |
|---|---|---|
| Column `currencies.company_id` | **present** | absent |
| Column `currencies.is_base` | **present** | absent |
| Unique key on `currencies` | `(company_id, code)` | **`code`** only |
| Secondary index | `company_id`, `is_active` | `(is_active, code)` |
| FK | `currencies.company_id → companies` | none |
| `code` column type | `varchar(3)` | `char(3)` |

For `exchange_rates`:

| Signal | Old | Current |
|---|---|---|
| Audit columns `created_by`, `updated_by` | absent | **present** |
| FK `from_currency_id`/`to_currency_id` `ON DELETE` | `CASCADE` | **`RESTRICT`** |
| Named unique `exchange_rates_pair_day_unique` | absent (default name) | **present** |
| Named indexes `exchange_rates_resolution_index`, `exchange_rates_company_to_index` | absent | **present** |

**Decisive single check:** if `currencies` has a `company_id` **or** `is_base`
column, the environment is on the **old** schema and must be reconciled under
review before `migrate`; if it has neither and carries a unique key on `code`
alone, it is on the **current** schema.

### 16.4 How to capture evidence without exposing credentials or records

Rules of capture: run with `--no-interaction`; never print `.env`; never
`SELECT *` from business tables; capture migration **names** and schema
**structure** only; keep credentials in the client's own config (no `-p` on the
command line, no password in the transcript); store output in a file and paste
only names/counts.

Safe, read-only procedures (MySQL target):

- **Migration status (names + ran/pending only):**
  `php artisan migrate:status --no-interaction`
- **Orphan check (names only):**
  ```sql
  SELECT migration FROM migrations
  WHERE migration IN (
    '2026_10_03_121646_create_currencies_table',
    '2026_10_03_121658_create_exchange_rates_table',
    '2026_10_08_104345_create_budget_line_dimensions_table'
  );
  ```
- **Currency schema shape (structure only):**
  ```sql
  SELECT COLUMN_NAME, COLUMN_TYPE
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'currencies'
  ORDER BY ordinal_position;

  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'currencies' AND NON_UNIQUE = 0
  GROUP BY INDEX_NAME;
  ```
- **Exchange-rate shape (structure only):**
  ```sql
  SELECT COLUMN_NAME
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'exchange_rates'
  ORDER BY ordinal_position;

  SELECT rc.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, rc.DELETE_RULE
  FROM information_schema.referential_constraints rc
  JOIN information_schema.key_column_usage k
    ON k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
  WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = 'exchange_rates';
  ```

If any target environment uses SQLite instead of MySQL, the structure-only
equivalents are:
`SELECT name FROM sqlite_master WHERE type='table';`
`PRAGMA table_info('currencies');`
`PRAGMA index_list('currencies');`
`PRAGMA foreign_key_list('exchange_rates');`

These return column/index/FK **metadata** only; they cannot reveal business rows,
tokens, or credentials.

### 16.5 Stop conditions

**Stop and do not run `php artisan migrate` if any of the following is observed.**
Report the condition to the release owner; do not "fix" it by editing migration
files, inserting fake migration rows, renaming files, or running any destructive
command.

1. `migrations` contains a record for `2026_10_03_121646_create_currencies_table`
   or `2026_10_03_121658_create_exchange_rates_table` (orphaned currency record).
2. `migrations` contains `2026_10_08_104345_create_budget_line_dimensions_table`
   **and** `2026_10_10_100200_create_budget_line_dimensions_table` is not recorded.
3. `migrate:status` shows the replacement currency/fx migrations as **pending**
   while `currencies`/`exchange_rates` already exist (duplicate-table risk).
4. `currencies` exists with a `company_id` or `is_base` column (old schema).
5. `exchange_rates` exists with `ON DELETE CASCADE` on `from_currency_id`/
   `to_currency_id`, or without `created_by`/`updated_by` (old schema).
6. `migrate:status` shows a **partially applied** currency/fx set (some of
   `170000`–`170900` ran, others pending).
7. Any migration file on disk is missing while a DB records a different filename
   for the same logical migration (history drift not already catalogued here).
8. The environment's connection target cannot be positively identified, or a
   backup/restore has not been demonstrated for it.

### 16.6 Required backup-and-restore evidence (staging, before `migrate`)

Operator-supplied, per environment:

- A current **backup** taken immediately before the migration window, with its
  timestamp, size, and storage location recorded (no credentials in the record).
- A **restore rehearsal** onto a non-production copy, with:
  - the restored schema's `migrations` count and the currency-schema signal from
    §16.3,
  - confirmation that the restored app serves (smoke check as in §10),
  - elapsed restore time recorded.
- A **rollback plan**: restore-from-backup is the sanctioned path for a failed
  migration; there is no `migrate:rollback` shortcut assumed.
- Written confirmation that the backup was verified (not merely taken).

### 16.7 Staging configuration checks (before promotion)

Verify on the staging host (values reported as set/`true`/`false`, never printed):

- `APP_ENV=production`, `APP_DEBUG=false`.
- `APP_URL` and `FRONTEND_URL` are the real public origins (reset link uses
  `FRONTEND_URL`).
- A non-`log` mail transport (`MAIL_MAILER`) is configured.
- HTTPS termination and secure cookies (BFF `Secure` follows `NODE_ENV=production`).
- `API_BASE_URL` (BFF → Laravel) points at the staging backend.
- After the deploy: `php artisan app:sync-roles` run; first admin provisioned via
  `php artisan app:create-admin` (interactive).
- Logs reviewed for accidental secrets/PII.

### 16.8 Operator checklist (concise, ordered)

1. [ ] Take and **verify** a staging DB backup; rehearse restore on a copy. (§16.6)
2. [ ] Capture `php artisan migrate:status --no-interaction` (staging). (§16.4)
3. [ ] Run the orphan-name check from §16.4. If any name from §16.2 is returned →
   **STOP** (§16.5), report, and do not run `migrate`.
4. [ ] Capture the `currencies`/`exchange_rates` structure check from §16.4 and
   apply the §16.3 decisiveness test. If it reads **old** → **STOP**.
5. [ ] Confirm no partial currency/fx set (all of `170000`–`170900` either all
   recorded or all pending as a unit).
6. [ ] If and only if 1–5 are clean and signed off: run
   `php artisan migrate --force --no-interaction` in **staging**.
7. [ ] Run `php artisan app:sync-roles`; provision first admin via
   `php artisan app:create-admin`.
8. [ ] Execute the §10 smoke checklist with synthetic data, including real
   password-reset email delivery and token single-use.
9. [ ] Verify staging configuration per §16.7; review logs for secrets/PII.
10. [ ] Repeat 1–9 for production only after staging is signed off.

**Do not run** `migrate:fresh`, `migrate:refresh`, `db:wipe`, seeders, or any
destructive command in any environment. Do not edit migration files or insert
migration rows to reconcile history.

### 16.9 Release recommendation

**CONDITIONAL GO** (unchanged). The code-level gates are green and the local
schema/migration evidence is consistent with the current design. Promotion to
**GO** remains blocked because **no** target-environment item above is verified:
staging migration history and schema (task-critical), backup/restore evidence,
staging configuration, real reset-email delivery, role sync, and first-admin
provisioning are all **NOT VERIFIED** on this workstation. Complete §16.8 in
staging, attach the captured evidence, and re-evaluate.

---

## 17. Staging Readiness Execution (Pass 4)

**Timestamp of this pass:** `2026-10-17T17:50Z` (2026-10-17 23:20 IST).
**Mode:** read-only. No migration, seeder, role sync, admin provisioning, or
deploy command was run in any environment; no database row, migration record, or
migration file was changed; nothing was committed or pushed. `[cmd]`

### 17.1 Phase A — Access and safety determination

`[cmd]` Inventory performed without printing secrets: `.env` **key names** were
listed, and only non-secret identity values (`APP_ENV`, `DB_CONNECTION`,
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `MAIL_MAILER`) were read; every
`PASSWORD`/`SECRET`/`_KEY`/`TOKEN` value was redacted at the source.

| Discovery | Finding |
|---|---|
| Environment files present | Only `accounting-app/backend/.env` (git-ignored) and `*.env.example`. **No** `.env.staging`, no `.env.production`, no `frontend/.env`. |
| Active `.env` environment | `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000` |
| Active `.env` database | `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=accounting` |
| Active `.env` mail transport | `MAIL_MAILER=log` (no real delivery) |
| `FRONTEND_URL` in active `.env` | **absent** (present only in `.env.example`). Local-only note; see §17.6. |
| CI / deploy descriptors | **none** — no `docker-compose*`, `Dockerfile`, GitHub Actions / CI workflow, `Procfile`, or deploy script in the repo |
| Staging host in config/docs | **none** — `config/` defines standard drivers only; no staging origin appears in any doc |
| Cloud / deploy CLIs installed | **none** — `aws`, `gcloud`, `az`, `kubectl`, `terraform`, `ansible`, `forge`, `vapor` all absent |
| SSH / staging credentials | **none** — no `~/.ssh/config`; no staging-related environment variables in the shell |
| Git remotes | only `origin = github.com/ram-chy/Accountance.git` (source, not an app environment) |
| Reachable databases | only the **local** `127.0.0.1` MySQL (`accounting`, `accounting_test`) |

**Determination:** authorized **staging access is NOT available** and a staging
database connection **cannot be positively identified** from this workstation.
Per Phase A.4 the audit **stopped here**: no staging database command was issued,
and no remote result is reported. `[cmd]`

**Nearest reference environment (LOCAL — not staging, read-only, re-confirmed
`2026-10-17T17:50Z`):** the local dev DB `accounting` is unchanged from §16.1 —
56 migrations, max batch 8, current global `currencies` schema (no
`company_id`/`is_base`, `currencies_code_unique(code)`), current `exchange_rates`
schema (`created_by`/`updated_by`, `RESTRICT` FKs, named indexes), and the
orphaned `2026_10_08_104345_create_budget_line_dimensions_table` record. This is
**local drift evidence only** and says nothing about staging. `[cmd]`

**Identification commands proven to work (run on the staging host):**
`php artisan about --only=environment` (prints env name/debug/URL — no secrets) and
`php artisan migrate:status --no-interaction`. Both were executed locally
successfully; version in use is Laravel 13.34.0 / PHP 8.4.12. `[cmd]`

### 17.2 Phase B — Read-only staging checks (NOT VERIFIED)

Every Phase B item requires a staging host that is not reachable from this
workstation. None was executed against staging; each is **NOT VERIFIED**.

| # | Phase B check | Status | Reason |
|---|---|---|---|
| 1 | `php artisan migrate:status --no-interaction` (staging) | **NOT VERIFIED** | no staging target |
| 2a | record `2026_10_03_121646_create_currencies_table` | **NOT VERIFIED** | no staging target |
| 2b | record `2026_10_03_121658_create_exchange_rates_table` | **NOT VERIFIED** | no staging target |
| 2c | record `2026_10_08_104345_create_budget_line_dimensions_table` | **NOT VERIFIED** | no staging target |
| 3 | `currencies` / `exchange_rates` structure (§16.3) | **NOT VERIFIED** | no staging target |
| 4 | schema matches current migrations | **NOT VERIFIED** | no staging target |
| 5 | staging configuration as set/unset or true/false (§16.7) | **NOT VERIFIED** | no staging target |
| 6 | no business records read/exported | **N/A** | no staging access obtained, so none was read |

The exact queries for items 1–4 are already specified in §16.4 and are ready to
run by an authorized operator.

### 17.3 Phase C — Stop conditions

The §16.5 stop conditions are **not triggered**, because no staging access existed
to trigger them. They remain the mandatory pre-`migrate` gate. Migration history
was **not** reconciled, edited, or repaired in this pass. If, once staging access
is granted, any §16.5 condition appears, stop and escalate to a reviewed recovery
plan — do not reconcile history directly.

### 17.4 Phase D — Backup, restore, and release gates (NOT VERIFIED)

| Item | Status | Reason |
|---|---|---|
| Independently restorable staging backup exists | **NOT VERIFIED** | no staging access; no backup artefact or manifest available here |
| Restore rehearsed on a separate copy | **NOT VERIFIED** | no evidence obtainable from this workstation |
| Backup verified (not merely taken) | **NOT VERIFIED** | no evidence |
| Staging config (`APP_ENV=production`, `APP_DEBUG=false`, real mail, HTTPS/secure cookies, `API_BASE_URL`) | **NOT VERIFIED** | no staging host |
| Real password-reset email delivery | **NOT VERIFIED** | local `MAIL_MAILER=log`; needs staging |
| `app:sync-roles` run on staging | **NOT VERIFIED** | operator action post-deploy |
| First admin provisioned on staging | **NOT VERIFIED** | operator action post-deploy |

### 17.5 Remaining operator actions (exact)

The following are the only actions that can advance the release. They must be
performed by an authorized operator **with** staging access; none can be done from
this workstation.

1. **Grant access + identify the target.** Provide the staging host over an out-of-band
   channel and confirm it: run `php artisan about --only=environment`; it must report
   `Environment = production` (or the agreed staging value) with `Debug Mode .. OFF`.
   Then confirm identity: `php artisan tinker --execute="echo DB::connection()->getDatabaseName();"`
   (structure only) or the equivalent `SELECT DATABASE();`. Record host and DB name.
2. **Capture migration status:** `php artisan migrate:status --no-interaction` →
   save to a file; paste only names and ran/pending states.
3. **Run the orphan-name query** from §16.4. If any name from §16.2 is returned →
   **STOP** (§16.5), report, and do not run `migrate`.
4. **Run the structure queries** from §16.4 for `currencies` and `exchange_rates`
   and apply the §16.3 decisiveness test. Old schema (`company_id`/`is_base`, or
   `CASCADE` FKs / missing `created_by`/`updated_by`) → **STOP**.
5. **Confirm the currency/fx set is atomic:** all of `2026_10_09_170000`–`170900`
   either all recorded or all pending (no partial apply).
6. **Backup + restore rehearsal (§16.6):** take a staging backup, prove a restore
   on a separate copy (record the restored `migrations` count and the §16.3
   signal, and elapsed time). Capture the evidence.
7. **Only if 1–6 are clean and signed off:** run
   `php artisan migrate --force --no-interaction` in **staging**.
8. **Post-migrate:** `php artisan app:sync-roles`; then provision the first admin
   with `php artisan app:create-admin` (interactive; no default credentials).
9. **Smoke test (§10)** on staging with synthetic data, including a real
   password-reset email whose link begins with the configured `FRONTEND_URL`, and
   confirm reset-token single-use.
10. **Verify staging configuration (§16.7)** and review logs for accidental
    secrets/PII.
11. **Repeat for production** only after staging is signed off.

**Do not run** `migrate:fresh`, `migrate:refresh`, `db:wipe`, seeders, or any
destructive command. Do not edit migration files or insert migration rows to
reconcile history.

### 17.6 Local-only observations and local-readiness follow-up (not staging findings)

- The active local `.env` **had no `FRONTEND_URL`** at the start of this pass. Per
  `config/frontend.php`, the password-reset link is built from `FRONTEND_URL` and
  the request **fails loudly (HTTP 500) if it is missing**. That was a local gap,
  not a defect, and it has since been corrected (below). Staging must still set a
  real `FRONTEND_URL` (§16.7).
- Local `MAIL_MAILER=log`, so real reset-email delivery cannot be exercised here.

**Local-readiness follow-up — `2026-10-17T18:04Z`.** Two **git-ignored** config
files were created/updated (no source, migration, or DB change; no commit):
`accounting-app/frontend/.env.local` (copied from `.env.example`, per the example's
own instruction) and `accounting-app/backend/.env` (appended
`FRONTEND_URL=http://localhost:3000`).

Read-only/local smoke results `[cmd]`:

| Check | Result |
|---|---|
| `GET http://localhost:8000/api/auth/me` (backend) | `401` `{"success":false,"message":"Unauthenticated."}` |
| `POST /api/auth/forgot-password` (generic address, no account) | `200` — previously would have `500`ed without `FRONTEND_URL` |
| `config('frontend.url')` | resolves to a valid absolute `http(s)` URL |
| `GET http://localhost:3000/` | `307` (redirect); `GET /login` → `200` |
| `GET http://localhost:3000/api/backend/auth/me` (BFF → Laravel) | `401` JSON proxied from Laravel, proving `API_BASE_URL` in `.env.local` |
| Next.js boot log | `Environments: .env.local`, `✓ Ready in 3.5s` |
| `php artisan test --filter=PasswordResetNotificationTest` | **19 passed / 52 assertions** — confirms the emailed link is `FRONTEND_URL/reset-password?…` via `ResetPassword::createUrlUsing` → `FrontendUrl::to()` (not the API probe) |

No database write occurred (12 users / 4 companies unchanged); no secrets or PII
were printed; reset tokens and email addresses were redacted in every captured
output.

**Stale-log caveat:** `storage/logs/laravel.log` still contains email bodies from
**2026-10-16 15:55 / 16:08** whose reset link points at the backend API probe
(`…/api/auth/reset-password/{token}`). Those entries **predate** the current
`configurePasswordResetLinks()` behaviour in `AppServiceProvider` and are
historical artefacts — current behaviour is proven by the 19 passing tests above.
No new mail was logged during this follow-up.

### 17.7 Classification

**CONDITIONAL GO** (unchanged). No applicable staging gate was evidenced in this
pass because no staging environment is reachable from this workstation. The code
and local evidence remain green; promotion is blocked only on the operator actions
in §17.5. `[cmd]`

---

## Appendix A — Pass 1 record (historical, preserved)

The Pass-1 revision of this report (2026-10-17) is summarized here so its findings
are not lost. It recorded: the same working tree (six modified files, three
untracked reports); targeted backend evidence
(`PasswordResetNotificationTest.php` → 19 passed / 52 assertions, `[prior]`);
full frontend evidence (110 files / 1083 tests, `[prior]`); `tsc` exit 0; Pint
passed; 215 routes with both reset routes present; the BFF 403 source change;
migration-history **NOT VERIFIED**; SQLite files synthetic/low-risk; the
frontend/backend contract review; the staging prerequisites table; and the
**CONDITIONAL GO** classification. Its explicit "NOT VERIFIED" items were: the
full backend suite, frontend lint, the handler-level BFF test, per-environment
migration history, production/staging config and secrets handling, real reset
email delivery and single-use flow, role sync and first-admin provisioning,
backup/restore rehearsal, and byte-level SQLite assurance. All of those not
re-executed in Pass 2 remain NOT VERIFIED above; the ones executed in Pass 2 are
corrected in §14.

---

*No commit, push, deploy, destructive database operation, or migration was
performed, and no staging/production system was contacted. Pass 4 (this revision)
changed only this report — it added the staging readiness execution record in §17
after confirming no staging environment is reachable from this workstation, and
executed read-only local inspection; no source file, migration file, database row,
or migration record was modified. Pass 3 added the target-environment migration
readiness audit (§16). A local-readiness follow-up created/updated two
**git-ignored** config files (`frontend/.env.local`, and `FRONTEND_URL` in
`backend/.env`) and ran a local smoke test — recorded in §17.6; still no tracked
file, migration, or database row was changed. Pass 2 added one frontend test (`route.test.ts`). All
pre-existing working-tree changes remain intact; the tracked SQLite files and
`.git.bak/` were not modified or removed.*
