# Frontend Phase 20 Report — Company Administration & Authentication Completion

> **Git note.** The Phase 20 brief prohibits commits and pushes (§2.2, §15). No
> commit was created, no push was performed, and no prohibited Git operation was
> run. All Phase 20 work remains **uncommitted** as modified and untracked files
> for the operator to review and commit if desired.

---

## 1. Executive summary and completion status

Phase 20 completes the frontend company-administration and authentication
workflows that the backend already supports, without inventing endpoints or
duplicating earlier phases.

Delivered:

- **Authentication completion**: forgot-password, reset-password (token check +
  consumption), change-password (session re-seal), and resend-email-verification
  — each behind a new same-origin BFF route so the browser never calls Laravel
  directly.
- **Company administration**: an active-company settings screen (profile,
  numbering/terms/defaults), a company create screen, company
  deactivate/reactivate, and member add/remove.
- **User administration**: list/search/filter, create, edit, and (soft)
  deactivate.
- **Profile & security**: a read-only profile view and a security screen hosting
  the change-password form.
- **Navigation & login**: real Company and Account navigation sections and a
  "Forgot your password?" link on the login form.

Status: **implemented and verified by the five quality gates.** Live
backend integration was **not** performed (see §11).

## 2. Initial branch, HEAD, and Git status

| Item | Value |
| --- | --- |
| Branch | `main` |
| HEAD | `1e5eae0dbe1d4d559c0b1cf0ded6e18ae64afe15` |
| HEAD subject | `docs: organize phase reports into docs/report/frontend and docs/report/backend` |
| Working tree at Phase 20 start | **clean** |

Read-only Git commands only were used (`git status --short`, `git branch
--show-current`, `git rev-parse HEAD`, `git log`).

## 3. Pre-existing modifications and untracked files before implementation

The Phase 20 brief (§2.1) describes the tree as containing uncommitted Phases
17–19 work. Inspection showed that work had **already been committed** by the
operator before Phase 20 began:

- `9239ca2 feat(frontend): Phase 19 - Budgets & Dimensions`
- `1e5eae0 docs: organize phase reports into docs/report/frontend and docs/report/backend`

Consequently, at the start of Phase 20 there were **no pre-existing uncommitted
modifications, untracked files, or deletions**. In particular:

- The untracked fixed-asset, currency, exchange-rate, FX-settings, budget and
  dimension files referenced in the Phase 19 report are now tracked/committed.
- The previously deleted prompt file is committed as deleted and was left
  **untouched**.
- The `prompt/frontend Prompt/Phase 20.md` brief is tracked and committed.

No pre-existing file was overwritten, deleted, renamed, restored or discarded.

## 4. Backend endpoints, payloads, response shapes, and permissions verified

All of the following were read from backend source (read-only) before the
corresponding UI was written.

### Authentication

| Method | Path | Auth | Notes |
| --- | --- | --- | --- |
| POST | `/auth/forgot-password` | public | Generic success regardless of account existence. |
| GET | `/auth/reset-password/{token}?email=` | public | Token usable check; same 400 for unknown address and stale token. Token constraint `[A-Za-z0-9]+`. |
| POST | `/auth/reset-password` | public | Single-use token; 400 on stale token. |
| GET | `/auth/me` | bearer | Session snapshot. |
| POST | `/auth/logout` | bearer | Token blacklist. |
| PUT | `/auth/password` | bearer | Returns **new** token + `expires_in`; old token blacklisted. |
| POST | `/auth/email/verification-notification` | bearer | Idempotent resend. |

Password policy (`App\Rules\StrongPassword` + `config/security.password_min_length`,
default 8): min length, a lowercase letter, an uppercase letter, a digit, and a
symbol.

### Companies (global authenticated `companies` family)

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/companies` | `companies.view` |
| POST | `/companies` | `companies.create` |
| GET | `/companies/{id}` | `companies.view` |
| PUT | `/companies/{id}` | `companies.update` |
| POST | `/companies/{id}/activate` | `companies.delete` |
| POST | `/companies/{id}/deactivate` | `companies.delete` |
| POST | `/companies/{id}/members` | `companies.update` (body `user_id`) |
| DELETE | `/companies/{id}/members/{user}` | `companies.update` |
| POST | `/companies/{id}/switch` | BFF-only (`/api/session/company`) |

Create/update rules (`ValidatesCompany` trait): `name` (required, 1–150,
unique on update), `country_code` (required, `size:2`, `CountryCode` rule),
`timezone` (required, `Timezone` rule), `date_format` (required, in
`DateFormats::ALL`); optional legal_name/registration_number/tax_number/email
(`email:rfc`)/phone/website (`http`,`https`)/address fields. `CompanyResource`
exposes `id, name, legal_name, registration_number, tax_number, email, phone,
website, address{line_1,line_2,city,state,postal_code,country_code}, timezone,
date_format, is_active, is_default, settings`. **No base/functional currency
field is exposed.**

### Company context (company-scoped `company` family)

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/company` | membership |
| GET | `/company/settings` | `companies.settings.view` |
| PUT | `/company/settings` | `companies.settings.update` |
| PUT | `/company/settings/base-currency` | `companies.settings.update` |

`UpdateCompanySettingsRequest`: `invoice_number_prefix` (nullable, max 20,
`/^[A-Za-z0-9\-\/]*$/`), `quotation_number_prefix` (same),
`default_payment_terms_days` (0–365), `default_currency_id` (nullable, min 1),
`default_fiscal_year_start_month` (1–12). Only submitted keys are sent;
`CompanySettingsService` uses `fill`, so `null` clears.

### Users (global authenticated `users` family)

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/users` | `users.view` |
| POST | `/users` | `users.create` |
| GET | `/users/{id}` | `users.view` |
| PUT | `/users/{id}` | `users.update` |
| DELETE | `/users/{id}` | `users.delete` (soft deactivate) |

`GET /users` returns a **flat array** with **no paginator metadata** (query:
`search`, `role`, `is_active`, `per_page`). `StoreUserRequest`: first_name,
last_name, email, mobile_no, password, roles[]. `UpdateUserRequest`: the same
plus `is_active`, with `password` `sometimes|nullable` and roles/self-change
restrictions. `UserResource` omits `roles[].permissions` on the index.

### Authorization matrix (verified from `config/authorization.php`)

- **Staff**: `companies.view`.
- **Accountant**: + `users.view`.
- **Manager**: + `companies.update`, `companies.settings.view`,
  `companies.settings.update`, `users.view`.
- **Admin**: `*` (adds `companies.create`, `companies.delete`,
  `users.create`, `users.update`, `users.delete`, `roles.view`).

## 5. Features implemented and routes added or modified

### New Next.js routes (App Router)

| Route | Purpose |
| --- | --- |
| `/forgot-password` | Request a reset email (public). |
| `/reset-password` | Choose a new password after verifying the token (public). |
| `/settings/profile` | Read-only account profile. |
| `/settings/security` | Change password + resend verification. |
| `/settings/company` | Active-company profile, settings and lifecycle. |
| `/settings/company/members` | Add/remove company members. |
| `/settings/users` | User administration. |
| `/companies/new` | Create a company. |

### New BFF routes (`/api/session/*`)

| Route | Purpose |
| --- | --- |
| `POST /api/session/forgot-password` | Generic reset request; 422 only for a malformed address; 502 generic on upstream failure. |
| `POST /api/session/reset-password` | Hand-validates token/email/password+confirmation, relays the broker result; **no session** is created. |
| `GET /api/session/reset-password/[token]` | Token-usable check; `await context.params`; token regex `[A-Za-z0-9]+`; relays 400. |
| `PUT /api/session/password` | Authenticated change; re-seals the cookie with the replacement token (same active company) and clears the session on 401. |
| `POST /api/session/verify-email` | Authenticated resend; clears the session on 401. |

### Modified files

- `src/lib/auth/laravel.ts` — added `QueryParams` import, `AuthTokenResult`
  type, a `query` option on `laravelFetch`, and the server-to-server calls
  `forgotPasswordRequest`, `validateResetTokenRequest`, `resetPasswordRequest`,
  `changePasswordRequest`, `sendEmailVerificationRequest`.
- `src/lib/auth/client.ts` — added `requestPasswordReset`,
  `validateResetToken`, `resetPassword`, `changePassword`,
  `resendVerificationEmail` (all via the BFF).
- `src/lib/navigation/nav.ts` — replaced the placeholder Company section with
  real items and added an Account section.
- `src/lib/navigation/nav.test.ts` — added 3 cases for the new sections.
- `src/app/(public)/login/login-form.tsx` — added the "Forgot your password?"
  link.

### Feature behaviour notes

- **Company create** sends `is_default` and, when the "Work in this company"
  toggle is on, calls `switchCompany(newId)` then navigates to
  `/settings/company`.
- **Member add/remove** pick a user from the global users list (there is no
  member-listing endpoint); the server enforces membership and both 422
  ("already belongs") and 404 ("does not belong") are surfaced. Removal is
  confirmed.
- **Company deactivate/reactivate** is Admin-only (`companies.delete`) and
  confirmed; after success the session is refreshed.
- **User edit** locks roles and account status when editing the signed-in user
  (the backend refuses self role changes and self-deactivation).
- **Read-only fallbacks**: when the caller lacks the write capability the
  company profile/settings render as read-only detail lists.

## 6. Existing features inspected and intentionally left unchanged

- Existing session BFF routes (`/api/session/login`, `logout`, `me`,
  `company`) and `/api/backend/[...path]` proxy — reused unchanged.
- Existing proxy allowlist (`source/lib/api/proxy-policy.ts`) — unchanged; the
  new auth flows added BFF routes rather than widening the allowlist.
- Existing dashboard and all Phase 14–19 module screens — unchanged.
- `POST /companies/{id}/set-default` — **not** wired up: it is not registered in
  `routes/api.php` (dead route). Switching uses `/api/session/company`.
- `PUT /company/settings/base-currency` — **not** wired up (the current base
  currency cannot be read back; see §12).
- No roles/permissions management UI (no roles endpoint).
- No public self-registration screen (see §12).

## 7. API clients, validators, hooks, and reusable components added or modified

### `src/lib/company/*`

- `types.ts` — re-exports the session `Company`/`CompanyAddress`/`CompanySettings`
  shapes; `DATE_FORMATS`, `DEFAULT_DATE_FORMAT`, `COUNTRY_CODES` (249 codes),
  `DEFAULT_COUNTRY_CODE`, `CompanyProfilePayload`, `CompanyCreatePayload`,
  `CompanySettingsUpdatePayload`, `CompanySettingsState`.
- `validators.ts` — `CompanyDataError`, `MAX_PREFIX_LENGTH`, `PREFIX_PATTERN`,
  `MAX/MIN_PAYMENT_TERMS_DAYS`, `MIN/MAX_FISCAL_MONTH`,
  `parseCompanySettings(OrThrow)`, `parseCompany(OrThrow)`,
  `validateCompanyProfile`, `validateCompanySettings`.
- `api.ts` — `fetchCompanySettings`, `updateCompanySettings`, `fetchCompany`,
  `updateCompany`, `createCompany`, `deactivateCompany`, `activateCompany`,
  `addCompanyMember`, `removeCompanyMember`.
- `use-company-settings.ts` — `useCompanySettings({companyId, enabled,
  reloadToken})` with an `AbortController` per run and 403 → "unavailable".

### `src/lib/users/*`

- `types.ts` — `UserRole`, `User`, `FIXED_ROLES` (Admin/Accountant/Manager/Staff),
  `isFixedRole`, `UserListFilters`, `UserListState` (`hasMore`),
  `UserCreatePayload`, `UserUpdatePayload`.
- `validators.ts` — `UsersDataError`, `MOBILE_PATTERN`,
  `parseUser(OrThrow)`, `parseUserList`, `toUserQuery` (`per_page: 100`),
  `validateUserCreate`, `validateUserUpdate`.
- `api.ts` — `fetchUsers`, `fetchUser`, `createUser`, `updateUser`,
  `deactivateUser`.
- `use-user-list.ts` — `useUserList({enabled, filters, reloadToken})`.

### `src/lib/auth/password.ts` (new)

`PASSWORD_MIN_LENGTH = 8`, `PASSWORD_POLICY_HINT`, `validatePasswordStrength`
(mirror of the backend rule).

### Components

- `components/company/*`: `company-form-fields.tsx` (shared create/edit field
  set + payload mapping), `company-profile-form.tsx`, `company-settings-form.tsx`,
  `company-settings-skeleton.tsx`, `company-lifecycle-dialog.tsx`,
  `company-view.tsx`, `company-members-view.tsx`, `company-create-form.tsx`.
- `components/users/*`: `users-view.tsx`, `user-form-dialog.tsx`,
  `users-list-skeleton.tsx`.
- `components/settings/*`: `profile-view.tsx`, `security-view.tsx`,
  `change-password-form.tsx`.
- `components/auth/public-shell.tsx` — shared chrome for the public
  forgot/reset screens (added instead of a `(public)/layout.tsx`, leaving the
  existing login page untouched).

## 8. Authentication and company-context security considerations

- The browser never calls Laravel directly. Every call goes through the
  same-origin BFF; `Authorization` and `X-Company-Id` are injected server-side.
- The JWT stays in an encrypted httpOnly cookie throughout. No token is exposed
  to client JavaScript; the change-password response token is sealed back into
  the cookie server-side.
- Forgot-password and reset-password are handled without creating a session, so
  a stolen reset link can never become a live session.
- Forgot-password returns an identical generic outcome for existing and
  non-existent accounts (the BFF does not reintroduce the distinction, and a
  transient upstream failure is reported generically).
- Reset-token errors are 400 for both "unknown address" and "stale token", and
  the UI does not distinguish them.
- Change-password refreshes the session cookie with the replacement token,
  which carries the new password-version claim; the previous token is
  blacklisted. A 401 clears the session.
- All writes are capability-gated in the UI and re-authorised by the backend;
  UI gating only decides what is rendered.

## 9. Tests added and test-suite totals

New test files (11) — **98 new tests**:

| File | Tests |
| --- | --- |
| `src/lib/auth/password.test.ts` | 7 |
| `src/lib/company/api.test.ts` | 9 |
| `src/lib/company/validators.test.ts` | 22 |
| `src/lib/users/api.test.ts` | 8 |
| `src/lib/users/validators.test.ts` | 21 |
| `src/components/company/company-view.test.tsx` | 8 |
| `src/components/company/company-members-view.test.tsx` | 5 |
| `src/components/company/company-create-form.test.tsx` | 4 |
| `src/components/users/users-view.test.tsx` | 8 |
| `src/components/settings/change-password-form.test.tsx` | 4 |
| `src/app/(public)/forgot-password/forgot-password-form.test.tsx` | 2 |

Plus `src/lib/navigation/nav.test.ts` extended with 3 cases.

**Test-suite totals: 108 files, 1058 tests — all passing.**

## 10. Results of all five quality gates

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` | ✅ pass (0 problems) |
| Lint (strict) | `npx eslint . --max-warnings=0` | ✅ pass (no output) |
| Types | `npx tsc --noEmit` | ✅ pass (no output) |
| Tests | `npx vitest run` | ✅ 108 files, 1058 tests passing |
| Build | `npm run build` | ✅ pass (production build; new routes emitted) |

One real defect was found and fixed during the gates: `validateRoles` was called
with a stray third argument in `lib/users/validators.ts`; both call sites were
corrected. Two lint issues introduced this phase (a `setState`-in-effect in the
reset form and an unescaped apostrophe in the members view) were also fixed; the
reset form now passes an `AbortSignal` and only updates state from callbacks.

## 11. Live integration status

Distinguishing the kinds of verification explicitly:

- **Source inspection**: performed (backend routes, controllers, Form Requests,
  resources, policies, services, enums, config).
- **Automated tests**: performed (1058 passing, incl. 98 new).
- **Build verification**: performed (`npm run build` succeeded).
- **Live integration** (running the app against a live backend): **not
  performed.** No server was started and no request reached a running Laravel
  instance. Nothing in this report should be read as a claim of live
  end-to-end success.

## 12. Unsupported features and backend contract gaps

Documented as gaps — never faked:

1. **No company member list.** Only add (`POST .../members`) and remove
   (`DELETE .../members/{user}`) exist. The members screen states this and
   offers only those two operations.
2. **Base/functional currency cannot be read.** `PUT
   /company/settings/base-currency` exists but `CompanyResource` exposes no way
   to read the current base currency, so no base-currency editor was built.
   `default_currency_id` is shown only as "Default document currency" with
   helper text clarifying it is **not** the functional base currency.
3. **Users index has no pagination metadata.** The flat-array response is read
   at `per_page: 100`; the UI surfaces "more may exist" instead of a page count.
4. **No roles endpoint.** `roles.view` has no served route, so roles are the
   fixed `RoleName` set (mirrored as `FIXED_ROLES`) and there is no role
   management UI.
5. **No public registration.** A self-registered user would receive the fixed
   `Staff` role and no company, while `companies.create` is Admin-only, so a
   registration screen would be unusable. Deliberately omitted.
6. **`set-default` is a dead route.** `POST /companies/{id}/set-default` is not
   registered; switching uses `/api/session/company`.
7. **Self-service profile edit is unsupported.** Only `PUT /auth/password` is
   self-service; editing other profile fields needs `users.update`. The profile
   screen is therefore read-only.

## 13. Known limitations and recommended follow-up work

- **Reactivation of the active company** is reachable in the UI only when the
  active company is inactive, which the company-context model normally prevents.
  A full company-list/switcher screen would make reactivation broadly reachable.
- **Member picker depends on `users.view`.** A role that could manage members
  without `users.view` would be unable to populate the picker; today Manager and
  Admin hold both.
- **Change-password session re-seal** uses the backend `expires_in` with a
  3600s fallback; a shorter-lived token response is handled but the cookie
  lifetime is refreshed from the response only.
- Recommended follow-up: a company-list screen (selection + reactivation), a
  read endpoint for the functional base currency, a company member-list
  endpoint, and a roles/permissions read endpoint — each would close one of the
  gaps above.

## 14. Final `git status --short`

```
 M accounting-app/frontend/src/app/(public)/login/login-form.tsx
 M accounting-app/frontend/src/lib/auth/client.ts
 M accounting-app/frontend/src/lib/auth/laravel.ts
 M accounting-app/frontend/src/lib/navigation/nav.test.ts
 M accounting-app/frontend/src/lib/navigation/nav.ts
?? accounting-app/frontend/src/app/(app)/companies/new/page.tsx
?? accounting-app/frontend/src/app/(app)/settings/company/members/page.tsx
?? accounting-app/frontend/src/app/(app)/settings/company/page.tsx
?? accounting-app/frontend/src/app/(app)/settings/profile/page.tsx
?? accounting-app/frontend/src/app/(app)/settings/security/page.tsx
?? accounting-app/frontend/src/app/(app)/settings/users/page.tsx
?? accounting-app/frontend/src/app/(public)/forgot-password/forgot-password-form.test.tsx
?? accounting-app/frontend/src/app/(public)/forgot-password/forgot-password-form.tsx
?? accounting-app/frontend/src/app/(public)/forgot-password/page.tsx
?? accounting-app/frontend/src/app/(public)/reset-password/page.tsx
?? accounting-app/frontend/src/app/(public)/reset-password/reset-password-form.tsx
?? accounting-app/frontend/src/app/api/session/forgot-password/route.ts
?? accounting-app/frontend/src/app/api/session/password/route.ts
?? accounting-app/frontend/src/app/api/session/reset-password/[token]/route.ts
?? accounting-app/frontend/src/app/api/session/reset-password/route.ts
?? accounting-app/frontend/src/app/api/session/verify-email/route.ts
?? accounting-app/frontend/src/components/auth/public-shell.tsx
?? accounting-app/frontend/src/components/company/company-create-form.test.tsx
?? accounting-app/frontend/src/components/company/company-create-form.tsx
?? accounting-app/frontend/src/components/company/company-form-fields.tsx
?? accounting-app/frontend/src/components/company/company-lifecycle-dialog.tsx
?? accounting-app/frontend/src/components/company/company-members-view.test.tsx
?? accounting-app/frontend/src/components/company/company-members-view.tsx
?? accounting-app/frontend/src/components/company/company-profile-form.tsx
?? accounting-app/frontend/src/components/company/company-settings-form.tsx
?? accounting-app/frontend/src/components/company/company-settings-skeleton.tsx
?? accounting-app/frontend/src/components/company/company-view.test.tsx
?? accounting-app/frontend/src/components/company/company-view.tsx
?? accounting-app/frontend/src/components/settings/change-password-form.test.tsx
?? accounting-app/frontend/src/components/settings/change-password-form.tsx
?? accounting-app/frontend/src/components/settings/profile-view.tsx
?? accounting-app/frontend/src/components/settings/security-view.tsx
?? accounting-app/frontend/src/components/users/user-form-dialog.tsx
?? accounting-app/frontend/src/components/users/users-list-skeleton.tsx
?? accounting-app/frontend/src/components/users/users-view.test.tsx
?? accounting-app/frontend/src/components/users/users-view.tsx
?? accounting-app/frontend/src/lib/auth/password.test.ts
?? accounting-app/frontend/src/lib/auth/password.ts
?? accounting-app/frontend/src/lib/company/api.test.ts
?? accounting-app/frontend/src/lib/company/api.ts
?? accounting-app/frontend/src/lib/company/types.ts
?? accounting-app/frontend/src/lib/company/use-company-settings.ts
?? accounting-app/frontend/src/lib/company/validators.test.ts
?? accounting-app/frontend/src/lib/company/validators.ts
?? accounting-app/frontend/src/lib/users/api.test.ts
?? accounting-app/frontend/src/lib/users/api.ts
?? accounting-app/frontend/src/lib/users/types.ts
?? accounting-app/frontend/src/lib/users/use-user-list.ts
?? accounting-app/frontend/src/lib/users/validators.test.ts
?? accounting-app/frontend/src/lib/users/validators.ts
```

No files were deleted and no backend files were touched. This report file is the
only additional artifact (below the frontend tree, under `docs/`):

```
?? accounting-app/docs/report/frontend/FRONTEND_PHASE_20_REPORT.md
```

## 15. Confirmation

Confirmed:

- **No backend file** (`accounting-app/backend/**`) was created, modified, or
  deleted. All backend inspection was read-only.
- **No dependency, `package.json`, or lockfile** change was made; nothing was
  installed or removed.
- **No commit, push, or other prohibited Git operation** was performed (no
  `reset`, `clean`, `stash`, `checkout`, `switch`, `restore`, `pull`, `fetch`,
  `rebase`).
- **No migrations, seeders, or database operations** were run.
- **No earlier-phase report** was overwritten or modified.
- All pre-existing work was preserved; no file was deleted or renamed.
- All Phase 20 changes remain **uncommitted**.
- **Live integration was not performed** unless stated otherwise; the
  verification performed was source inspection, automated tests, and a
  production build.

**Stop condition met.** Phase 20 is complete; Phase 21 was not started.
