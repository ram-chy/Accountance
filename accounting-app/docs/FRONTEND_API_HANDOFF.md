# Frontend API Handoff

**Backend:** Laravel 13 / PHP 8.4 REST API (JWT)
**Audience:** the Next.js / React / TypeScript frontend team
**Source of truth:** the actual routes in `routes/api.php`, requests, resources and
`config/authorization.php` at the time of Phase 18. Derived from the
implementation, not from a specification.

> Phase 18 audit status: **PASS WITH NOTES**. See
> `docs/report/PHASE_18_REPORT.md`. The single material frontend-affecting note is
> the pagination behaviour in §7 (pagination metadata is not returned).

---

## 1. Base URL and conventions

- All routes are under the `/api` prefix, e.g. `GET /api/accounts`.
- Requests and responses are JSON. Send `Accept: application/json`.
- Dates are `YYYY-MM-DD`. Money is returned as **strings** with up to 4 decimal
  places (never JSON numbers) so no precision is lost in transit.
- Every response carries an `X-Request-Id` header (a UUID, or an echo of a valid
  `X-Request-Id` you send). Quote it in bug reports; it correlates to audit rows.

---

## 2. Authentication flow and token handling

Public endpoints (no token), under `throttle:auth`:

| Method | Path | Purpose | Rate limit |
|---|---|---|---|
| POST | `/api/auth/register` | Register a user | `throttle:register` |
| POST | `/api/auth/login` | Obtain a JWT | `throttle:login` |
| POST | `/api/auth/forgot-password` | Email a reset link | `throttle:forgot-password` |
| POST | `/api/auth/reset-password` | Reset with a token | `throttle:reset-password` |
| GET | `/api/auth/reset-password/{token}` | Check whether a reset token is still valid | `throttle:reset-password` |
| GET | `/api/auth/email/verify/{id}/{hash}` | Email verification link (signed, opened from mail) | `throttle:verify-email` |
| GET | `/api/health` | Health / DB availability | — |

Authenticated endpoints, under `auth:api` + `auth.fresh`:

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/auth/me` | Current user |
| POST | `/api/auth/logout` | Revoke the current token |
| PUT | `/api/auth/password` | Change password |
| POST | `/api/auth/email/verification-notification` | Resend verification email |

**Login request:**

```json
{ "email": "user@example.com", "password": "secret" }
```

**Login response (200):**

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token": "<jwt>",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": {
      "id": 1,
      "first_name": "Ada",
      "last_name": "Lovelace",
      "full_name": "Ada Lovelace",
      "email": "user@example.com",
      "mobile_no": null,
      "email_verified": true,
      "is_active": true,
      "roles": [ { "name": "Accountant" } ],
      "last_login_at": "2026-10-11T09:00:00+00:00",
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-10-11T09:00:00+00:00"
    }
  }
}
```

**Token handling rules:**

- Send `Authorization: Bearer <token>` on every authenticated request.
- `expires_in` is seconds (`jwt.ttl` default **3600 s**, signalled HS256). The
  token is invalid after expiry.
- There is **no refresh-token endpoint**. When a token expires the API returns
  `401` with `{"success":false,"message":"Your token has expired. Please sign in again."}`;
  send the user back to login.
- `auth.fresh` re-checks account status and password version on **every** request.
  Deactivating a user or changing a password invalidates existing tokens
  immediately (the next request returns 401). Design for a sudden 401 on any call.
- Failed logins are deliberately indistinguishable (unknown email, wrong password
  and deactivated account all return the same 401), so do not build UI that
  tries to explain *why* login failed.
- Any 401 should clear stored credentials and route to login.

---

## 3. User and company context selection

A user belongs to one or more companies (memberships). Most business endpoints
operate on an **active company** resolved from a header, not from the URL or body.

- Send `X-Company-Id: <companyId>` on every company-scoped request.
- If omitted, the backend falls back to the caller's default company; if the
  header names a company the caller is not a member of, or an inactive company,
  the request is rejected (never silently ignored).
- A foreign-company resource id in the path returns **404** (not 403), by design,
  to avoid confirming that another company's id exists.

Company and context routes:

| Method | Path | Purpose | Capability |
|---|---|---|---|
| GET | `/api/companies` | Caller's own companies | membership |
| POST | `/api/companies` | Create a company | `companies.create` |
| GET | `/api/companies/{company}` | Read one | membership + `companies.view` |
| PUT | `/api/companies/{company}` | Update | `companies.update` |
| POST | `/api/companies/{company}/activate` | Activate | `companies.update` |
| POST | `/api/companies/{company}/deactivate` | Deactivate (replaces delete) | `companies.update` |
| POST | `/api/companies/{company}/switch` | Set caller's default company | membership |
| POST | `/api/companies/{company}/members` | Add member | `companies.update` |
| DELETE | `/api/companies/{company}/members/{user}` | Remove member | `companies.update` |
| GET | `/api/company` | The active company | membership |
| GET | `/api/company/settings` | Read settings | `companies.settings.view` |
| PUT | `/api/company/settings` | Update settings | `companies.settings.update` |
| PUT | `/api/company/settings/base-currency` | Change base currency (audited; may be refused) | `companies.settings.update` |

**Recommended client flow:** after login, `GET /api/companies`; if exactly one,
call `POST /api/companies/{id}/switch` (or simply send that id as `X-Company-Id`).
Store the chosen id and attach it to every subsequent request. `POST .../switch`
returns the company and **echoes the `X-Company-Id` to use**:

```json
// response headers include: X-Company-Id: 7
{ "success": true, "message": "Active company switched successfully.", "data": { "...": "..." } }
```

---

## 4. Response and error conventions

Documented once in `app/Support/ApiResponse.php` and `bootstrap/app.php`.

**Success envelope:**

```json
{ "success": true, "message": "Accounts retrieved successfully.", "data": { } }
```

`data` is omitted when there is nothing to return (e.g. logout).

**Error envelope:**

```json
{ "success": false, "message": "The given data was invalid.", "errors": { "email": ["The email field is required."] } }
```

**Status codes:**

| Status | Meaning | Body |
|---|---|---|
| 200 / 201 | Success | success envelope |
| 401 | Unauthenticated / token expired, invalid or blacklisted | `{success:false, message}` |
| 403 | Authenticated but not permitted | `{success:false, message}` |
| 404 | Not found, **or cross-company id** | `{success:false, message:"Resource not found."}` |
| 405 | Wrong HTTP method | `{success:false, message}` |
| 422 | Validation failed | `{success:false, message, errors}` |
| 429 | Rate limited | `{success:false, message}` + `Retry-After` |
| 500 | Unexpected (production) | `{success:false, message:"An unexpected error occurred."}` |
| 503 | Health: DB unavailable | `{success:false, message, errors}` |

Validation errors are keyed by field; nested fields use dot/bracket-style keys,
e.g. `lines.1.dimensions.0.dimension_id` and `lines.1.quantity`.

---

## 5. Validation conventions (important for forms)

- **Server-owned fields are not inputs.** Company id, document status, journal
  number, invoice/bill numbers, computed totals, `journal_id`, `created_by`,
  `posted_by`, `posted_at` are **not present** in the request rules — sending them
  is ignored, not honoured. Never build a form that posts them.
- Money inputs must be **decimal strings** (e.g. `"1234.5600"`, or a number with
  at most `accounting.rounding.max_input_decimals` decimals). Values with extra
  decimals are rounded half-up rather than rejected; a value that rounds to zero
  on a required side is rejected.
- Journal/invoice line amounts are one-sided: a line has a debit **or** a credit,
  never both, never negative.
- Journal debits must equal credits; the server sums the lines itself and ignores
  any client total.
- Past dates (backdating) are allowed on journals and documents; whether a date is
  *postable* is decided by the accounting period at posting time.
- `exists`-style checks are company-scoped; an id from another company is reported
  as not belonging to the active company (same shape as "does not exist").

---

## 6. Request/response examples (from the implementation)

### 6.1 Create a draft journal — `POST /api/journals`

Capability: `journals.create`. Headers: `Authorization`, `X-Company-Id`.

```json
{
  "journal_date": "2026-10-11",
  "description": "Monthly accrual",
  "reference": "REF-001",
  "lines": [
    { "account_id": 10, "description": "Rent", "debit": "1000.0000", "credit": "0.0000",
      "dimensions": [ { "dimension_id": 3, "value_id": 21 } ] },
    { "account_id": 20, "description": "Payable", "debit": "0.0000", "credit": "1000.0000" }
  ]
}
```

Journal line dimensions (Phase 17) are optional. Each entry is
`{ "dimension_id": <int>, "value_id": <int> }`; at most one value per dimension
type per line. The response (via `JournalLineResource`) echoes
`"dimensions": [ { "dimension_id": 3, "value_id": 21 } ]`. A posted journal's
dimensions cannot be changed.

### 6.2 Create a draft sales invoice — `POST /api/sales-invoices`

Capability: `sales.invoices.create`.

```json
{
  "customer_id": 5,
  "invoice_date": "2026-10-11",
  "due_date": "2026-11-10",
  "notes": "Thanks",
  "tax_account_id": 33,
  "lines": [
    { "description": "Widget", "quantity": "2.0000", "unit_price": "50.0000",
      "discount": "0.0000", "tax_rate": "15.0000", "revenue_account_id": 40 }
  ]
}
```

Response money fields (`subtotal`, `discount_total`, `tax_total`, `grand_total`)
are strings. `paid_total` / `balance_due` / `is_overdue` / `days_overdue` are
present only when the controller attached settlement figures (list/show), and are
**absent** on create.

### 6.3 Post a document — `POST /api/{documents}/{id}/post`

Capability: the module's `.post` permission (`sales.invoices.post`, etc.).
**No request body.** Posting is irreversible; a posted document is immutable.

### 6.4 Tax calculation — `POST /api/accounting/tax/calculate`

Capability: `accounting.tax.calculate`. Writes nothing.

```json
{ "amount": "100.00", "date": "2026-10-11", "tax_ids": [1, 2], "basis": "EXCLUSIVE" }
```

`tax_ids` optional (empty = no tax); `basis` optional (`EXCLUSIVE` | `INCLUSIVE`).

### 6.5 Create a company dimension and value — `POST /api/accounting/dimensions`

Capability: `accounting.dimensions.create`.

```json
{ "type": "COST_CENTER", "code": "CC", "name": "Cost Center" }
```

Then `POST /api/accounting/dimensions/{dimension}/values`:

```json
{ "code": "CC-100", "name": "Engineering" }
```

`type` is a controlled vocabulary (`FinancialDimensionType`). Values are always
addressed under their dimension; there is no `/dimensions/values/{id}` route.

---

## 7. Pagination, sorting, and filtering

- **List endpoints** accept `per_page` (validated `1..100` where a filter request
  exists; some default to 50). Query parameters you already sent are preserved.
- **Pagination metadata is NOT returned (Phase 18 note P2-1).** The response is:

  ```json
  { "success": true, "message": "Accounts retrieved successfully.", "data": [ { "id": 1 }, { "id": 2 } ] }
  ```

  `data` is a flat array of the current page **only** — there are no `links`,
  `meta`, `total`, `last_page` or `current_page` keys. Until the backend response
  envelope is revised (a pending product decision), treat `per_page` as a page
  size and detect the last page by receiving fewer than `per_page` items. Do not
  build a page-number control that depends on `last_page`.
- **Sorting** is fixed per endpoint (e.g. accounts are ordered by `code`); there
  is no generic `sort` parameter.
- **Common filters** (exact names vary by endpoint; representative):
  - Accounts: `search`, `account_type`, `is_active`.
  - Dimensions: `type`, `is_active`, `search`, `per_page`.
  - Budgets: `financial_year_id`, `status`, `search`, `per_page`.
  - Cash/bank transactions: `transaction_type`, `status`, `account_id`, `from`,
    `to`, `reference`, `per_page`.
- `is_active` is three-state where offered: absent = all, `true` = active,
  `false` = inactive.

---

## 8. Report filters and date semantics

All Phase 6 reports use `accounting.reports.view`. They are read-only and derive
from **posted** journal entries only.

**Date window (inclusive, date-only):** `from_date` / `to_date`
(aliases `from` / `to` are accepted). `from_date` must be `<= to_date`.

**Point-in-time reports:** `as_of` (defaults to today) is used by
receivables/payables and aging, distinct from `to_date`.

**Company-scoped ids:** `account_id`, `customer_id`, `supplier_id` are validated
against the active company.

| Report | Path | Key params |
|---|---|---|
| Trial Balance | `GET /api/accounting/reports/trial-balance` | `from_date`, `to_date` |
| General Ledger | `GET /api/accounting/reports/general-ledger` | `account_id` (required), `from_date`, `to_date` |
| Profit & Loss | `GET /api/accounting/reports/profit-loss` | `from_date`, `to_date`, `dimension_id`, `dimension_value_id` |
| Balance Sheet | `GET /api/accounting/reports/balance-sheet` | `as_of` |
| Customer Statement | `GET /api/accounting/reports/customer-statement` | `customer_id` (required), `from_date`, `to_date` |
| Supplier Statement | `GET /api/accounting/reports/supplier-statement` | `supplier_id` (required), `from_date`, `to_date` |
| Receivables | `GET /api/accounting/reports/receivables` | `as_of` |
| Payables | `GET /api/accounting/reports/payables` | `as_of` |
| Receivables Aging | `GET /api/accounting/reports/receivables-aging` | `as_of` |
| Payables Aging | `GET /api/accounting/reports/payables-aging` | `as_of` |
| Cash/Bank activity | `GET /api/accounting/reports/cash-bank` | `from_date`, `to_date`, account filters |

`GET /api/accounting/trial-balance` and
`GET /api/accounting/accounts/{account}/balance` are the Phase 4 ledger reads
(`accounting.ledger.view`) and use `from` / `to` / `as_of`.

Tax reports (`accounting.tax.report.view`): `GET /api/accounting/tax-reports/summary`
and `GET /api/accounting/tax-reports/by-tax`.

---

## 9. Currency and base-currency presentation

- Each company has a **base currency** (multi-currency Phase 14).
- Documents and report totals are in the company **base currency**. Journal
  `debit`/`credit` are always base currency.
- Journal lines may carry foreign-currency *provenance*:
  `currency_id`, `currency_code`, `exchange_rate`, `foreign_debit`,
  `foreign_credit`. A `null` `currency_id` means the line is base-currency and the
  foreign fields are `null`.
- Multi-currency configuration routes:
  - `GET /api/accounting/currencies` and `POST/GET/PUT` + `activate`/`deactivate`
    (`accounting.currency.*`; creation is a system-level capability, not granted
    to the Accountant).
  - `GET /api/accounting/exchange-rates` and `POST/GET/PUT` + `activate`/`deactivate`
    (`accounting.exchange_rate.*`); rates are company-scoped and dated.
  - `GET` / `PUT /api/accounting/fx-settings` (`accounting.fx.update` for writes).
  - `GET /api/accounting/controls` (`accounting.controls.view`) — read-only FX /
    currency integrity report.
- Changing the company base currency is a separate, audited endpoint
  (`PUT /api/company/settings/base-currency`) and may be refused; it reinterprets
  every stored base amount.
- Present amounts as returned (strings, up to 4 dp). Do not reformat via floats.

---

## 10. Budget and dimension-filtering support

### Budgets (Phase 16)

| Method | Path | Capability |
|---|---|---|
| GET | `/api/accounting/budgets` | `accounting.budgets.view` |
| POST | `/api/accounting/budgets` | `accounting.budgets.create` |
| GET | `/api/accounting/budgets/{budget}` | `accounting.budgets.view` |
| PUT | `/api/accounting/budgets/{budget}` | `accounting.budgets.update` |
| DELETE | `/api/accounting/budgets/{budget}` | `accounting.budgets.delete` |
| POST | `/api/accounting/budgets/{budget}/approve` | `accounting.budgets.approve` |
| POST | `/api/accounting/budgets/{budget}/revise` | `accounting.budgets.create` |
| GET | `/api/accounting/budgets/{budget}/variance` | `accounting.budgets.view` |
| POST | `/api/accounting/budgets/{budget}/lines` | `accounting.budgets.update` |
| PUT | `/api/accounting/budgets/{budget}/lines/{line}` | `accounting.budgets.update` |
| DELETE | `/api/accounting/budgets/{budget}/lines/{line}` | `accounting.budgets.update` |

- A budget is created as a `DRAFT`; `status` is **not** a client field.
- `approve` finalizes and makes it immutable; a change is a new version via
  `revise` (which copies line dimensions to the new draft).
- Budget line create/update accepts optional `dimensions` in the same shape as
  journal lines. `BudgetLineResource` echoes
  `"dimensions": [ { "dimension_id": 3, "value_id": 21 } ]`.

### Budget variance — `GET /api/accounting/budgets/{budget}/variance`

Optional dimension filter: `dimension_id`, `dimension_value_id` (validated
together — `dimension_value_id` must belong to `dimension_id`). Response shape:

```json
{
  "budget": { "...": "..." },
  "period": { "...": "..." },
  "summary": {
    "revenue":  { "budget": "…", "actual": "…", "variance": "…" },
    "expenses": { "budget": "…", "actual": "…", "variance": "…" },
    "net":      { "budget": "…", "actual": "…", "variance": "…" },
    "counts":   { "favourable": 3, "...": "..." }
  },
  "lines": [ { "...": "..." } ],
  "dimension_filter": { "dimension_id": 3, "dimension_value_id": 21 }
}
```

`dimension_filter` is present **only** when a filter was supplied. Variance =
actual − budget; favourability is direction-aware by account normal side (revenue
over-plan is favourable, expense over-plan is unfavourable).

### Profit & Loss dimension filter — `GET /api/accounting/reports/profit-loss`

Accepts `dimension_id` and/or `dimension_value_id`. When active, the response adds:

```json
{
  "...": "existing P&L keys unchanged...",
  "dimension_filter": { "dimension_id": 3, "dimension_value_id": 21 },
  "unassigned": { "revenue": "…", "expenses": "…", "net": "…" }
}
```

Reconciliation contract: `unfiltered = filtered + unassigned`. `dimension_id`
alone means "all values of that dimension". Unfiltered responses are unchanged
(no new keys), so existing screens keep working.

### Dimensions (Phase 17)

| Method | Path | Capability |
|---|---|---|
| GET | `/api/accounting/dimensions` | `accounting.dimensions.view` |
| POST | `/api/accounting/dimensions` | `accounting.dimensions.create` |
| GET | `/api/accounting/dimensions/{dimension}` | `accounting.dimensions.view` |
| PUT | `/api/accounting/dimensions/{dimension}` | `accounting.dimensions.update` |
| DELETE | `/api/accounting/dimensions/{dimension}` | `accounting.dimensions.delete` |
| POST | `/api/accounting/dimensions/{dimension}/activate` | `accounting.dimensions.update` |
| POST | `/api/accounting/dimensions/{dimension}/deactivate` | `accounting.dimensions.update` |
| GET | `/api/accounting/dimensions/{dimension}/values` | `accounting.dimensions.view` |
| POST | `/api/accounting/dimensions/{dimension}/values` | `accounting.dimensions.create` |
| GET | `/api/accounting/dimensions/{dimension}/values/{value}` | `accounting.dimensions.view` |
| PUT | `/api/accounting/dimensions/{dimension}/values/{value}` | `accounting.dimensions.update` |
| DELETE | `/api/accounting/dimensions/{dimension}/values/{value}` | `accounting.dimensions.delete` |
| POST | `/api/accounting/dimensions/{dimension}/values/{value}/activate` | `accounting.dimensions.update` |
| POST | `/api/accounting/dimensions/{dimension}/values/{value}/deactivate` | `accounting.dimensions.update` |

---

## 11. Route inventory by module

Capabilities are the values in `app/Enums/PermissionName.php`. "Membership" means
company membership via `CompanyPolicy`. All routes except the public auth/health
group require `Authorization: Bearer`; all company-scoped routes require
`X-Company-Id`.

### Users — `users.*`

| Method | Path | Capability |
|---|---|---|
| GET | `/api/users` | `users.view` |
| POST | `/api/users` | `users.create` |
| GET | `/api/users/{user}` | `users.view` |
| PUT | `/api/users/{user}` | `users.update` |
| DELETE | `/api/users/{user}` | `users.delete` |

### Chart of accounts — `accounts.*`

| Method | Path | Capability |
|---|---|---|
| GET | `/api/accounts/types` | `accounts.view` |
| GET | `/api/accounts` | `accounts.view` |
| POST | `/api/accounts` | `accounts.create` |
| GET | `/api/accounts/{account}` | `accounts.view` |
| PUT | `/api/accounts/{account}` | `accounts.update` |
| DELETE | `/api/accounts/{account}` | `accounts.delete` |
| POST | `/api/accounts/{account}/activate` | `accounts.activate` |
| POST | `/api/accounts/{account}/deactivate` | `accounts.deactivate` |

### Periods & financial years

| Method | Path | Capability |
|---|---|---|
| GET/POST | `/api/accounting/periods` | `accounting.periods.view` / `.create` |
| GET/PUT | `/api/accounting/periods/{period}` | `accounting.periods.view` / `.update` |
| GET | `/api/accounting/periods/{period}/closing-check` | `accounting.periods.view` |
| POST | `/api/accounting/periods/{period}/close` | `accounting.periods.close` |
| POST | `/api/accounting/periods/{period}/reopen` | `accounting.periods.reopen` |
| GET/POST | `/api/accounting/financial-years` | `accounting.periods.view` / `.create` |
| GET/PUT | `/api/accounting/financial-years/{financialYear}` | `accounting.periods.view` / `.update` |
| POST | `/api/accounting/financial-years/{financialYear}/periods/generate` | `accounting.periods.create` |
| POST | `/api/accounting/financial-years/{financialYear}/close` | `accounting.periods.close` |

### Journals & ledger

| Method | Path | Capability |
|---|---|---|
| GET | `/api/journals` | `journals.view` |
| POST | `/api/journals` | `journals.create` |
| GET | `/api/journals/{journal}` | `journals.view` |
| PUT | `/api/journals/{journal}` | `journals.update` |
| DELETE | `/api/journals/{journal}` | `journals.delete` |
| POST | `/api/journals/{journal}/post` | `journals.post` |
| GET | `/api/accounting/trial-balance` | `accounting.ledger.view` |
| GET | `/api/accounting/accounts/{account}/balance` | `accounting.ledger.view` |

### Reports — `accounting.reports.view`

All `GET` under `/api/accounting/reports/*`: `trial-balance`, `general-ledger`,
`profit-loss`, `balance-sheet`, `customer-statement`, `supplier-statement`,
`receivables`, `payables`, `receivables-aging`, `payables-aging`, `cash-bank`.

### Customers & suppliers

| Method | Path | Capability |
|---|---|---|
| GET/POST | `/api/customers` | `customers.view` / `.create` |
| GET/PUT | `/api/customers/{customer}` | `customers.view` / `.update` |
| POST | `/api/customers/{customer}/deactivate` | `customers.deactivate` |
| GET/POST | `/api/suppliers` | `suppliers.view` / `.create` |
| GET/PUT | `/api/suppliers/{supplier}` | `suppliers.view` / `.update` |
| POST | `/api/suppliers/{supplier}/deactivate` | `suppliers.deactivate` |

(No customer/supplier DELETE — lifecycle ends at deactivation.)

### Sales invoices / purchase bills

Pattern for both `sales-invoices` and `purchase-bills`:
`GET`/`POST` (`.view`/`.create`), `GET`/`PUT`/`DELETE` by id
(`.view`/`.update`/`.delete`), `POST /{id}/post` (`.post`), and
`GET /{id}/adjustable-lines` (`.view`) which reports how much is still adjustable.

Capabilities: `sales.invoices.*` and `purchases.bills.*` (view/create/update/post/delete).

### Credit & debit notes — `credit_debit_notes.*`

`GET`/`POST` `/api/credit-debit-notes`; `GET`/`PUT`/`DELETE` `/{creditDebitNote}`;
`POST /{creditDebitNote}/post`. One resource for all four adjustment types;
filter by `note_type` on index. Capabilities:
`accounting.credit_debit_note.view/create/update/delete/post`.

### Customer receipts / supplier payments

Same shape for `customer-receipts` and `supplier-payments`: `GET`/`POST`,
`GET`/`PUT`/`DELETE` by id, `POST /{id}/post`. Capabilities
`customer.receipts.*` and `supplier.payments.*`.

### Cash & banking

| Method | Path | Capability |
|---|---|---|
| GET | `/api/cash-bank-accounts` | `accounting.cash_bank.view` |
| PUT | `/api/cash-bank-accounts/{account}` | `accounting.cash_bank.update` |
| DELETE | `/api/cash-bank-accounts/{account}/bank-details` | `accounting.cash_bank.update` |
| POST | `/api/cash-bank-accounts/{account}/activate` | `accounting.cash_bank.update` |
| POST | `/api/cash-bank-accounts/{account}/deactivate` | `accounting.cash_bank.update` |
| GET | `/api/cash-bank-transactions` | `accounting.cash_bank.view` |
| POST | `/api/cash-bank-transactions/deposits` | `accounting.cash_bank.create` |
| POST | `/api/cash-bank-transactions/withdrawals` | `accounting.cash_bank.create` |
| POST | `/api/cash-bank-transactions/transfers` | `accounting.cash_bank.create` |
| GET/PUT/DELETE | `/api/cash-bank-transactions/{transaction}` | `...view`/`.update`/`.delete` |
| POST | `/api/cash-bank-transactions/{transaction}/post` | `accounting.cash_bank.post` |

A cash/bank account is an account plus a classification; the account itself is
created via `/api/accounts`.

### Bank reconciliation — `accounting.bank_reconciliation.*`

`GET`/`POST` `/api/bank-reconciliations`; `GET`/`PUT`/`DELETE` `/{reconciliation}`;
`GET /{reconciliation}/movements`; `POST /{reconciliation}/items`;
`DELETE /{reconciliation}/items/{item}`; `POST /{reconciliation}/complete`;
`POST /{reconciliation}/reopen`.

### Tax — `accounting.tax.*` and `accounting.tax.report.view`

`/api/accounting/taxes` (view/create/update/delete, `activate`/`deactivate`),
`/{tax}/rates` (view/create/update/delete + activate/deactivate),
`/{tax}/account-mapping` (view/update), `POST /api/accounting/tax/calculate`
(`accounting.tax.calculate`), and `GET /api/accounting/tax-reports/summary|by-tax`
(`accounting.tax.report.view`).

### Fixed assets — `accounting.fixed_asset.*`

`/api/accounting/fixed-asset-categories` (view/create/update/delete,
activate/deactivate); `/api/accounting/fixed-assets`:
`GET /register` and `GET /depreciation-report` (static, before `/{id}`),
`GET`/`POST /cash`/`POST /supplier-credit`, `GET`/`PUT`/`DELETE` `/{fixedAsset}`,
`POST /{fixedAsset}/capitalize`, `GET /{fixedAsset}/depreciation-schedule`,
`POST /{fixedAsset}/depreciate`, `POST /{fixedAsset}/dispose`.

### Multi-currency — `accounting.currency.*`, `accounting.exchange_rate.*`

See §9.

### Budgets & dimensions

See §10.

---

## 12. Role summary (authoritative matrix)

From `config/authorization.php`:

- **Admin** — `*` (everything).
- **Accountant** — full CRUD across accounts, journals, customers/suppliers,
  invoices/bills, receipts/payments, notes, cash/bank, reconciliations, fixed
  assets, dimensions; **budgets view/create/update/delete but not approve**;
  reports/ledger/periods/tax reads; controls view. **No** `accounting.currency.create`
  or currency activate/deactivate (global master data).
- **Manager** — read-only across modules, plus `bank_reconciliation.*` actions,
  **`accounting.budgets.approve`**, `companies.update`, `companies.settings.*`.
  No create/post/update on any transactional document. **Dimensions: view only.**
- **Staff** — `companies.view` only.

Do not hard-code role names for authorization; drive UI from the capabilities the
user actually holds (the API is the authority and returns 403 when a capability is
missing). User roles are returned by `GET /api/auth/me`.

---

## 13. Limitations and deferred capabilities

1. **Pagination metadata absent (P2-1).** See §7. Design list screens accordingly
   until the response envelope is revised.
2. **No refresh token.** Re-authenticate on 401.
3. **No public audit-log API.** Audit records are written internally; there is no
   endpoint to read them.
4. **Dimension filtering is P&L and Budget Variance only.** Trial Balance,
   General Ledger and Balance Sheet are **not** dimension-filterable (deliberately
   deferred in Phase 17).
5. **Deferred by design:** comparative-period reporting, cash-flow statements,
   consolidation, unrealized FX revaluation, country-specific tax filing /
   e-invoicing, PDF generation endpoints. Do not assume these exist.
6. **Documents are base-currency in the implemented scope.** Foreign-currency
   provenance is exposed on journal lines; present document totals in base
   currency.
7. **Immutability:** posted documents/journals cannot be edited or deleted; drafts
   can. Lifecycles are separate paths (`PUT` edits, `POST /post` posts).
8. **Cross-company ids return 404**, not 403.

---

For the audit verdict, findings and evidence, see
`docs/report/PHASE_18_REPORT.md`.
