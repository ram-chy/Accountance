# RELEASE FIXES REPORT

**Report date:** 2026-10-17
**Scope:** Implementation of the three fix sets approved after the
`CONTROLLED_RELEASE_AUDIT_REPORT.md` (read-only) audit.
**Repository:** `D:\Development\Accountance`
**Baseline:** branch `main` @ `aecede2fa87fe3b69edf9870aeea0b4d2ae8e5e7`
(unmodified at the start of this work)
**Related artefacts:**

- `accounting-app/docs/report/backend/CONTROLLED_RELEASE_AUDIT_REPORT.md` (the audit)
- `accounting-app/docs/report/backend/BACKEND_RELEASE_REMEDIATION_REPORT.md` (the remediation under review)

> **Note on the audit's read-only rule.** The audit phase allowed only the
> creation of the audit report. The operator subsequently approved implementing
> fixes; this report is the record of that work.

---

## 1. Executive summary

Three fix sets were approved and implemented:

| # | Fix set | Category | Status |
|---|---|---|---|
| 1 | Close test-coverage gaps | Backend test | **Done — verified** |
| 2 | Align the BFF no-company status to the backend (409 → 403) | Frontend code | **Done — verified** |
| 3 | Reconcile the 403/409 and stale documentation | Documentation | **Done** |

All changes are local, in the working tree, and **not committed**.

All applicable gates pass: the targeted backend test file (**19 passed, 52
assertions**), the full frontend Vitest suite (**110 files / 1083 tests**),
Pint, and TypeScript.

The two fix sets **not** selected by the operator — `app:create-admin`
hardening and repository-hygiene cleanup (tracked SQLite dumps and
`accounting-app/.git.bak/`) — were deliberately left untouched.

---

## 2. Authorisation and exclusions

- **Approved (and implemented here):** (1) close test-coverage gaps,
  (2) reconcile 403/409 and stale docs, (3) align BFF 409 to backend 403.
- **Not approved / not done:** `app:create-admin` hardening; untracking the
  SQLite files `backend/accounting` and `backend/accounting_test`; removing
  `accounting-app/.git.bak/`.
- **Out of scope (operator/environment actions, not performed):**
  production/staging configuration, per-environment migration-history
  verification, `app:sync-roles`, `app:create-admin`, real email delivery.
- **No git write operations** (no commit, push, rebase, reset, tag) were run.

---

## 3. Change set

Six files were modified; no file was deleted or renamed.

### 3.1 Fix set 1 — backend regression test (coverage gap M4)

**File:** `accounting-app/backend/tests/Feature/Auth/PasswordResetNotificationTest.php`
**Change:** added a test method (line **185**), +29 lines.

The audit found that the misconfigured-`FRONTEND_URL` failure path was only
asserted for its HTTP status, and only with `APP_DEBUG=true` in the test
environment — so the production redaction was not pinned. The new test sets
**both** `frontend.url => null` and `app.debug => false` (line **193**) and
asserts the exact sanitized production envelope for a known and an unknown
address:

```php
public function test_a_missing_frontend_origin_returns_a_sanitised_body_when_debug_is_disabled(): void
{
    Notification::fake();

    config(['frontend.url' => null, 'app.debug' => false]);

    $user = User::factory()->create(['email' => 'user@example.com']);

    $responses = [
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]),
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']),
    ];

    foreach ($responses as $response) {
        $response
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'An unexpected error occurred.',
            ]);
    }

    Notification::assertNothingSent();
}
```

This closes the audit's Medium finding **M4** for the `FrontendUrlNotConfiguredException`
path: with debug disabled, the deployment fault degrades to the generic 500
envelope and leaks neither the exception class, its message, nor a stack trace —
identically for known and unknown addresses.

### 3.2 Fix set 2 — BFF no-company status aligned to 403

**File:** `accounting-app/frontend/src/app/api/backend/[...path]/route.ts`
**Change:** the company-scoped "no active company" response changed
**409 → 403** (status literal now at line **82**); the explanatory comment
(round line **76**) was updated. 3 lines changed.

```ts
// Company-scoped routes must never be forwarded without an explicit active
// company: the backend would otherwise fall back to the user's default and a
// write could land against the wrong company. Answer 403 so this refusal is
// consistent with Laravel's own company-context refusal (403) rather than
// inventing a status the backend never uses for this case.
if (validation.requiresCompany && session.companyId === undefined) {
  return jsonFailure(
    "Select a company before making this request.",
    403,
  );
}
```

**Why this is safe:**

- The backend returns **403** for the same logical condition
  (`ResolveCompanyContext.php`: no company → 403; foreign company → 403;
  unknown id → 404). The BFF previously masked it with a status the backend
  never uses for company context.
- No frontend code branched on a `409`. A repository-wide search for `409`
  in `src` returns only: this handler (now 403) and comments describing
  document-lifecycle conflicts (posted note/invoice/bill/receipt/payment). The
  no-company UI state is decided client-side (`activeCompany === null`) and
  never depends on this status.
- No existing test asserted the BFF `409`; nothing needed updating.

### 3.3 Fix set 3 — documentation reconciliation

| File | Change |
|---|---|
| `accounting-app/docs/FRONTEND_BLUEPRINT.md` | Line **341**: the shipping design doc now states company-scoped families fail with **403** (matching Laravel), not 409. |
| `accounting-app/docs/report/frontend/FRONTEND_PHASE_3_REPORT.md` | Line **37**: corrected the "verified backend fact" that wrongly attributed a `409` to `ResolveCompanyContext.php` (now 403, with a note that the BFF mirrors it). Lines **74-77**: handler step 4 changed `409` → `403`. |
| `accounting-app/docs/report/frontend/FRONTEND_PHASE_21_REPORT.md` | Added a **Post-release corrections (2026-10-17)** block (line **12**) recording that F-2 and F-3 are resolved by `aecede2` and that the 409 narrative is superseded (backend = 403; the 409 was BFF-only and is now 403). Fixed the non-existent `POST /journals/{id}/void` in the §5 inventory (line **138**) and §11 workflow list (line **449**). |
| `accounting-app/docs/report/backend/BACKEND_RELEASE_REMEDIATION_REPORT.md` | Corrected the two route-count entries `216 → 215` (lines **48**, **397**) and added a dated correction note (line **53**). The note deliberately does not claim the audit re-ran that report's suites, because it did not. |
| `accounting-app/docs/report/backend/CONTROLLED_RELEASE_AUDIT_REPORT.md` | Corrected a factual misattribution: the "216 routes" claim lives in the backend remediation report, not the Phase 21 report. |

Historical phase reports keep their narratives; corrections are additive or
scoped to the specific inaccurate claim.

---

## 4. Verification evidence

Every command below was executed on 2026-10-17 and is reproducible. Backend
tests use the project's own configured test database (`accounting_test`,
MySQL).

| # | Command | Result |
|---|---|---|
| 1 | `DB_DATABASE=accounting_test php artisan tinker --execute="…counts…"` | `db=accounting_test tables=57 users=0 companies=0` — confirmed the test DB holds no user data **before** refreshing it |
| 2 | `php artisan test tests/Feature/Auth/PasswordResetNotificationTest.php` | **19 passed, 0 failed, 52 assertions** (39.1 s) — previously 18 tests; the new test is included and passes |
| 3 | `npx vitest run` (frontend) | **110 files / 1083 tests passed** (41 s) |
| 4 | `vendor/bin/pint --test` (backend) | **passed** |
| 5 | `npx tsc --noEmit` (frontend) | **exit 0** |

The formatter run (3) covered the new test file, which is PHP.

---

## 5. Checks not performed and known limitations

- **Full backend suite not re-run.** Only the directly affected test file was
  executed. The broader suite was not run because it is lengthy and the audit
  already recorded it green; the change is additive (one new test) and cannot
  alter other tests.
- **No handler-level frontend test for the 403.** The proxy route handler has no
  unit-test harness in this project (only the pure `proxy-policy.ts` is tested);
  adding one would require new mocking infrastructure. The change is a status
  literal and is covered by `tsc` and the existing suite, but the exact
  handler-level status is not pinned by a test. Recommended follow-up.
- **No live/browser verification** of the changed BFF path.
- **No production/staging verification** of anything in §6 below.

---

## 6. Remaining operator prerequisites (unchanged by these fixes)

These were open after the audit and are **not** addressed by this work:

1. **Per-environment migration history** for the two deleted migrations must be verified before deploy.
2. **Production configuration:** `APP_ENV=production`, `APP_DEBUG=false`, `FRONTEND_URL` set to the real SPA origin, non-`log` mail transport.
3. **`php artisan app:sync-roles`** per environment (a fresh DB has roles with zero permissions).
4. **Secure first-admin provisioning** (`app:create-admin`).
5. **Real password-reset email delivery** verified in a controlled staging environment.

Two owner decisions also remain open: whether self-service first-company
creation should ever exist, and whether the tracked SQLite databases belong in
the repository (the second was offered as a fix and not selected).

---

## 7. Git state after this work

Uncommitted working tree (no commits, pushes or destructive operations):

```
 M accounting-app/backend/tests/Feature/Auth/PasswordResetNotificationTest.php
 M accounting-app/docs/FRONTEND_BLUEPRINT.md
 M accounting-app/docs/report/backend/BACKEND_RELEASE_REMEDIATION_REPORT.md
 M accounting-app/docs/report/frontend/FRONTEND_PHASE_21_REPORT.md
 M accounting-app/docs/report/frontend/FRONTEND_PHASE_3_REPORT.md
 M accounting-app/frontend/src/app/api/backend/[...path]/route.ts
?? accounting-app/docs/report/backend/CONTROLLED_RELEASE_AUDIT_REPORT.md
?? accounting-app/docs/report/backend/RELEASE_FIXES_REPORT.md
```

Git reported line-ending (LF→CRLF) notices for the edited files during `diff`;
these are Windows checkout-normalisation notices, not content changes.

---

## 8. Audit findings addressed

| Audit item | Status after fixes |
|---|---|
| **M4** — production sanitized-error path not asserted (FrontendUrl path) | **Closed** by the new test |
| **M1** — 403/409 contract divergence between backend and BFF | **Closed** — BFF now returns 403 |
| **L3** — stale Phase 21 statements (F-2/F-3) and inventory inaccuracies | **Closed** — corrections block + inline fixes |
| **L1** — route count drift (216 vs 215) | **Closed** — backend report corrected to 215 |
| L2 — malformed reset token → 404 (not 400) | Not addressed (not selected) |
| M2 — tracked SQLite databases | Not addressed (not selected) |
| M3 — `app:create-admin` admin-count / non-interactive confirmation | Not addressed (not selected) |
| L4 — frontend has no route-level `error.tsx` | Not addressed (not selected) |
| L5 — `accounting-app/.git.bak/` present | Not addressed (not selected) |

*End of report. Changes are staged only in the working tree, pending operator
review and commit.*
