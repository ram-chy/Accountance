# Phase 4 Report — Accounting Foundation

## 1. Phase Objectives

Phase 4 builds the accounting foundation on top of the tenancy boundary
delivered in Phase 3: company-scoped accounts, double-entry journals with a
posting lifecycle, accounting periods, and ledger reports derived from posted
entries.

Delivered:

- `accounts`, `accounting_periods`, `journals`, `journal_number_sequences` and
  `journal_lines` tables with database-level financial integrity.
- Chart of accounts CRUD, activation/deactivation and contra-account support.
- Draft journal lifecycle with server-issued sequential journal numbers.
- A single-writer posting engine that is the only path from `DRAFT` to
  `POSTED`, with posted journals immutable.
- Accounting periods with overlap prevention and one-way closing.
- Derived ledger reads: account balance, account statement and trial balance.
  No balances are stored.
- Sixteen accounting permissions and a four-role matrix layered on the Phase 2
  permission architecture.
- **303 tests, 1055 assertions, all passing** (109 accounting feature tests,
  482 assertions, plus the complete Phase 1–3 suite unchanged).

Explicitly **not** in scope: any transactional module (invoices, bills,
payments, payroll), currency conversion, budgets, default chart-of-accounts
seeding, a stored ledger-balance table, and reopening a closed period.

## 2. Database Migrations

| Migration | Purpose |
|---|---|
| `2026_09_30_100000_create_accounts_table.php` | `accounts` |
| `2026_09_30_100100_create_accounting_periods_table.php` | `accounting_periods` |
| `2026_09_30_100200_create_journals_table.php` | `journal_number_sequences`, `journals` |
| `2026_09_30_100300_create_journal_lines_table.php` | `journal_lines` |

### accounts

One chart of accounts per company. `UNIQUE (company_id, code)` and
`UNIQUE (company_id, name)` mean two companies may both have account `1000`
while one company cannot. Indexed on `(company_id, account_type)` and
`(company_id, is_active)` because every list is company-scoped and filtered.

`normal_balance` is nullable and **explicit**. A null means "follow the account
type"; a stored value inverts the account, which is how contra accounts
(accumulated depreciation, sales returns, allowance for doubtful debts) are
represented. Inferring contra status from the account name would be a rule no
accountant accepts, so the inversion is data.

`parent_id` is a self-referencing FK with `ON DELETE SET NULL`, which keeps
category deletion from orphaning child accounts.

### accounting_periods

`UNIQUE (company_id, name)`, `start_date`/`end_date` and `status`
(`OPEN`/`CLOSED`). Overlap is prevented by a transactional range check in
`AccountingPeriodService` rather than by a database exclusion constraint,
because MySQL has no such feature and an application check inside the same
transaction that inserts the row is the portable equivalent. The range is
indexed on `(company_id, start_date, end_date)` because that is exactly the
lookup the overlap check performs.

### journals

`UNIQUE (company_id, journal_number)`. The number is the human-facing
identifier; the primary key remains a separate auto-increment column. Indexed on
`(company_id, journal_date)` for listing and
`(company_id, status, journal_date)` for the posted-only ledger join.

`source_type`/`source_id` are present and indexed but nullable and unwritten.
Phase 4 is entirely manual; the columns exist so a future generated document
(invoice, bill) can point back at the journal it produced without a migration.
Nothing in this phase can set them.

### journal_lines

No `company_id`. Company scope is inherited through `journals.company_id`, and a
duplicated column would be a second value that could disagree with the first.
Every ledger query joins through `journals`.

`debit` and `credit` are `DECIMAL(20,4)` — 16 integer digits, four decimal
places, covering every ISO 4217 currency with room to spare. `line_number` is
`UNIQUE (journal_id, line_number)`. `account_id` is `ON DELETE RESTRICT`: an
account that has been posted against cannot be removed out from under the
ledger.

### Verified constraints

Laravel's Blueprint has no `check()` method, and a bare `DB::statement()` after
`Schema::create()` leaves a window where a concurrent write can slip an invalid
row in. `App\Support\Database\SchemaCheck::add()` therefore emits named
constraints from inside the migration, immediately after the columns exist.

| Constraint | Expression |
|---|---|
| `accounts_account_type_check` | `account_type IN ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE')` |
| `accounts_normal_balance_check` | `normal_balance IS NULL OR normal_balance IN ('DEBIT','CREDIT')` |
| `accounting_periods_date_order_check` | `start_date <= end_date` |
| `accounting_periods_status_check` | `status IN ('OPEN','CLOSED')` |
| `journals_status_check` | `status IN ('DRAFT','POSTED')` |
| `journals_posted_fields_check` | `status <> 'POSTED' OR (posted_by IS NOT NULL AND posted_at IS NOT NULL)` |
| `journal_lines_debit_non_negative_check` | `debit >= 0` |
| `journal_lines_credit_non_negative_check` | `credit >= 0` |
| `journal_lines_one_sided_check` | `NOT (debit > 0 AND credit > 0)` |

The helper detects the server version and skips the `ALTER` when the engine
parses but ignores `CHECK` (MySQL < 8.0.16) or when the driver is not MySQL,
rather than failing the migration or pretending SQLite has parity. Every rule
above is independently enforced at the application layer as well, so the
constraints are a second line of defence rather than the only one. Tests run
against MySQL, so the constraints themselves are exercised, not assumed.

`migrate`, `migrate:rollback` and `migrate:fresh` all run clean.

## 3. Model Design

### Account

`account_type` and `normal_balance` are backed by `AccountType` and
`NormalBalance` enums. `normalBalance()` resolves the effective side through
`App\Services\Accounting\AccountingRules` rather than restating the mapping, and
`isContra()` reports an account whose stored side opposes its type.

`deactivate()`/`activate()` flip `is_active`; `is_active` is not mass-assignable: a new account is active by definition.
`is_system` marks an account shipped with the application rather than created by
a user. Phase 4 ships no system accounts — the flag exists for a later module —
but the guard is in place now: `AccountController::deactivate()` refuses with
`422` for a system account, because it belongs to the module that owns it and
must not be taken out of service by hand. The check lives in the controller
because it is a policy question about ownership, not an accounting rule.
Deactivation is the only transition that matters, so a system account can never
become inactive through the API at all.

`isDeletable()` is false once any journal line references the account, and
`AccountService::delete()` enforces it with a validation error rather than
relying on the `RESTRICT` foreign key, so the client receives a message instead
of an integrity error.

### AccountingPeriod

`status` is a `PeriodStatus` enum. `contains(Carbon $date)` is the only way
other code asks whether a date falls in a period, so an off-by-one boundary
cannot be reimplemented differently by the posting engine and the period
service. `isOpenFor(Carbon $date)` combines the two.

### Journal

`status` is a `JournalStatus` enum; `source_type` is a `JournalSource` enum
(`MANUAL` today). `totalDebit()`/`totalCredit()`/`isBalanced()` sum the lines
through `Money`, and `totalDebit`/`total_credit`/`is_balanced` are computed in
the API resource from those methods — never stored, never client-supplied.

`JournalLine` exposes decimal-safe accessors. `debit` and `credit` are cast to
the four-decimal string form so a JSON response cannot reformat `10.0000` into
`10` or `10.0` in one place and not the other.

### Server-owned columns are not fillable

`company_id`, `journal_number`, `status`, `posted_by`, `posted_at`,
`created_by` and `journal_lines.journal_id` are absent from `$fillable` on
every model. `JournalService` constructs the record and assigns those columns
with `forceFill()` from the server's own values. This is deliberate: the client
must not be able to write its own company, its own journal number, its own
posting timestamp, or re-parent a line onto another journal.

## 4. Company Scoping

Accounting endpoints sit behind the Phase 3 `company.context` middleware, so
the active company is resolved exactly as it is for companies and users, and a
body, query or path value cannot override it.

Route binding is scoped. `App\Providers\AppServiceProvider::bindAccountingModelsToActiveCompany()`
overrides the implicit bindings for `{account}`, `{journal}` and `{period}`, each
constrained to the active company's id. An id belonging to another company
therefore fails to resolve and produces a `404`, not a `403` — the response does
not confirm that the record exists.

Every service takes the company from `CompanyContext`, never from the caller,
and `LedgerService` filters on the joined `journals.company_id`. There is no
query in this phase that reads accounting rows without a company predicate.

## 5. Authorization

The Phase 2 architecture is reused unchanged: Gate abilities, policies for the
model resources, ability strings checked by FormRequests, and
`PermissionName` as the single list of names. No second authorization system was
introduced, no role was added, and no existing role mapping was rewritten —
accounting permissions were appended to the roles that already existed, and
`Admin` picks them up through the wildcard it already held.

### Permissions

| Area | Permissions |
|---|---|
| Accounts | `accounts.view`, `.create`, `.update`, `.activate`, `.deactivate`, `.delete` |
| Journals | `journals.view`, `.create`, `.update`, `.delete`, `.post` |
| Periods | `accounting.periods.view`, `.create`, `.update`, `.close` |
| Ledger | `accounting.ledger.view` |

`accounts.delete` is not in the brief's list but is needed for a coherent
account lifecycle; it is granted to `Admin` only.

### Role matrix

| | Admin | Accountant | Manager | Staff |
|---|---|---|---|---|
| accounts.view | ✔ | ✔ | ✔ | |
| accounts.create / update | ✔ | ✔ | | |
| accounts.activate / deactivate | ✔ | ✔ | | |
| accounts.delete | ✔ | | | |
| journals.view | ✔ | ✔ | ✔ | |
| journals.create / update / delete / post | ✔ | ✔ | | |
| accounting.periods.view | ✔ | ✔ | ✔ | |
| accounting.periods.create / update | ✔ | ✔ | | |
| accounting.periods.close | ✔ | | | |
| accounting.ledger.view | ✔ | ✔ | ✔ | |

Two deliberate omissions from `Accountant`, both argued in
`config/authorization.php`:

- **`accounting.periods.close`** — closing a period is one-way and locks out
  later postings. That belongs to whoever owns the period, not to day-to-day
  bookkeeping.
- **`accounts.delete`** — an account with history cannot be deleted at all, and
  an unused one is rare enough that deactivation covers it. Withholding delete
  means a chart-of-accounts mistake cannot be made permanent.

`Manager` is read-only over accounting: it sees the company's financial position
and can change none of it. `Staff` keeps `companies.view` only.

### Ledger reads take the ledger permission

`LedgerController` authorises `accounting.ledger.view` rather than falling
through to the account's own `view` policy. `accounts.view` answers "may this
user see the chart of accounts"; the balance endpoints answer "may this user see
what those accounts are worth". Falling through would silently hand the
financial position to anyone granted the chart of accounts. There is no
`LedgerPolicy` because there is no `Ledger` model to protect — the permission is
checked directly at the one place it is needed.

`AccountController::types()` is gated on `accounts.view`, not on an unlisted
permission, so it cannot become an unauthenticated enumeration of account types.

## 6. API Routes

| Method | URI | Name |
|---|---|---|
| GET | `api/accounts` | `accounts.index` |
| POST | `api/accounts` | `accounts.store` |
| GET | `api/accounts/types` | `accounts.types` |
| GET | `api/accounts/{account}` | `accounts.show` |
| PUT | `api/accounts/{account}` | `accounts.update` |
| DELETE | `api/accounts/{account}` | `accounts.destroy` |
| POST | `api/accounts/{account}/activate` | `accounts.activate` |
| POST | `api/accounts/{account}/deactivate` | `accounts.deactivate` |
| GET | `api/accounting/accounts/{account}/balance` | `accounting.accounts.balance` |
| GET | `api/accounting/trial-balance` | `accounting.trial-balance` |
| GET | `api/accounting/periods` | `accounting.periods.index` |
| POST | `api/accounting/periods` | `accounting.periods.store` |
| GET | `api/accounting/periods/{period}` | `accounting.periods.show` |
| PUT | `api/accounting/periods/{period}` | `accounting.periods.update` |
| POST | `api/accounting/periods/{period}/close` | `accounting.periods.close` |
| GET | `api/journals` | `journals.index` |
| POST | `api/journals` | `journals.store` |
| GET | `api/journals/{journal}` | `journals.show` |
| PUT | `api/journals/{journal}` | `journals.update` |
| DELETE | `api/journals/{journal}` | `journals.destroy` |
| POST | `api/journals/{journal}/post` | `journals.post` |

`/api/accounts` is the chart of accounts; `/api/accounting/accounts/{account}/balance`
is a ledger read. The split is deliberate: account maintenance and financial
reporting are separately permissioned surfaces, and one controller holding both
would force one permission for two different questions.

There is no period reopen route. Closing is one-way by design.

> **Superseded by Phase 8.** Phase 8 adds `POST /api/accounting/periods/{period}/reopen`
> under a new `accounting.periods.reopen` permission held by Admin only. The rest of
> this report describes Phase 4 as delivered and is left unaltered. See
> `PHASE_8_REPORT.md`.

## 7. Service Layer

### AccountService

CRUD plus activation, deactivation and deletion, all company-scoped. Duplicate
codes and names are detected from the underlying unique indexes and reported as
a `ValidationException` on the offending field, so the client gets
"Account code already exists for this company" instead of an integrity error.
Duplicate handling is narrowed to the known unique-constraint names — a blanket
translation of every `QueryException` would have reported "duplicate code" for an
unrelated failure.

Deletion is refused in two cases, each with a validation message rather than a
foreign-key error: an account referenced by a journal line cannot be deleted
("deactivate it instead"), and an account with children cannot be deleted,
because the `SET NULL` on `parent_id` would otherwise silently re-parent them
and restructure the chart as a side effect of a delete. Accounts are hard
deleted; there is no soft delete, so "deleted" is unambiguous.

### AccountingPeriodService

Creation and update check that the new range does not overlap an existing period
in the same company, inside the same transaction that writes the row. `close()`
flips `OPEN` → `CLOSED` and is a one-way transition: there is no reopen, and
closing an already-closed period is rejected with `422` rather than silently
succeeding, so a client that retries a close learns that it has nothing left to
do.

> **Superseded by Phase 8:** `reopen()` now exists, as does close-audit
> (`closed_by`/`closed_at`), and a period belongs to a `FinancialYear`.

Adjacent periods do not overlap — `end_date` of one may equal `start_date` of the
next — and a period may be narrowed but not widened into another.

`create()` and `update()` construct the model and `forceFill()` `company_id` and
`status`. Leaving them fillable would have silently dropped the company on
insert, which is precisely the class of bug this phase cannot tolerate.

### JournalService

Draft creation, listing, retrieval, update and deletion. It assigns the journal
number through `JournalNumberSequence` and never accepts `company_id`,
`journal_number`, `status` or `created_by` from the client.

Update and delete both lock the journal row with `lockForUpdate()` and re-check
that it is still `DRAFT` **inside** the transaction, via a private
`lockDraftForEditing()`. A check performed before the transaction opens is
useless: a concurrent post between the check and the write would be silently
overwritten. An already-posted journal therefore yields a `409`, not a `422` or
a lost update.

Lines are replaced wholesale inside the transaction (`replaceLines()`), with
each new line constructed and `journal_id` assigned by the server. `line_number`
is reassigned from the submission order.

### JournalNumberSequence

Allocates `JNL-000001`, `JNL-000002`, … per company. The counter lives in its
own `journal_number_sequences` table with a unique `company_id`, and allocation
runs inside a transaction using `lockForUpdate()` so two concurrent drafts
cannot receive the same number.

The sequence is never rewound. Deleting the highest-numbered draft does not
return its number to the pool, because a reused number would leave two different
documents sharing an identifier in the audit trail.

### JournalPostingService

The only code in the application that produces a `POSTED` journal. Not a
controller, not a future business module, not a queued job — the `POSTED` status
has exactly one writer, which is what makes "posted journals are immutable" an
architectural fact rather than a convention.

`post()` performs the following inside one transaction that holds an exclusive
lock on the journal row:

1. **Re-read the journal under `lockForUpdate()`.** Two concurrent POSTs for the
   same journal: the first takes the lock and proceeds, the second blocks until
   the first commits, then reads the committed `POSTED` row and is rejected at
   step 2. Without the lock both transactions would read `DRAFT`, both would
   validate and both would write. `UNIQUE (company_id, journal_number)` does not
   catch that, because both writes target the same row.
2. **Status: still `DRAFT`.** Otherwise `ConflictException` (`409`). Posting is
   idempotent in effect, never in state: the journal was already posted when the
   lock was acquired, which is a state conflict, not invalid input. The
   controller's early check returns the same `409` for the sequential case, so
   the answer does not depend on whether the client happened to be concurrent.
3. **Reload the lines under the lock**, rather than trusting the caller's
   relation, so validation sees committed state.
4. **Structure:** at least two lines, each one-sided and non-zero.
5. **Balance:** total debit equals total credit, in exact decimal.
6. **Accounts** must exist in *this* journal's company and be active. A line
   naming an id from another company fails here as well as at draft time,
   because the rows can have been tampered with in between.
7. **Period:** the accounting date must land in an `OPEN` period.
8. **Write** — `status`, `posted_by` and `posted_at` in one statement, so there
   is no window in which `status` is `POSTED` but `posted_at` is unset.

Steps 4 and 5 run through `JournalService::assertStructureValid()`, the same
code that validates a draft submission, so a journal cannot pass one check on
the way in and a different one on the way to posting.

`posted_by` comes from the authenticated user object, never from the request. An
audit trail recording whoever the client *said* posted the entry is worthless.

### LedgerService

Read-only by construction. Balances are always derived by aggregating posted
journal lines joined to `journals`, filtered by company and optionally by date
range. There is no write method and no balances table, so a second source of
truth cannot be introduced by this class.

`trialBalance()` returns per-account balances plus the totals and an explicit
`is_balanced` flag with the difference. If that flag ever reads false, the ledger
is corrupt; it is surfaced in the response rather than swallowed and reported as
a normal trial balance.

### AccountingRules

The single place normal-balance and sign arithmetic is defined. `AccountType`
encodes "Asset/Expense → Debit, Liability/Equity/Revenue → Credit", and
`AccountingRules::signedBalance()` converts raw debit-positive movement into an
amount on the account's normal side. No controller or report restates the rule;
a negative balance is reported with its sign rather than hidden by taking a
magnitude.

## 8. Validation and Money

### Money

`App\Support\Money` is a BCMath-backed value object. `total`/`scale` live in
`config/accounting.php` so the value object and the `DECIMAL(20,4)` columns agree
and the scale can be reviewed in one place. Float and double are never used for
money: `0.1 + 0.2` is not `0.3` in binary floating point, and a ledger that
cannot add `0.1` to `0.2` is not a ledger. Changing the scale later requires a
migration across every monetary column, and that is intentional — it must never
happen silently.

### Journal lines

`ValidatesJournalLines` is shared by the store and update requests:

- `lines` is required, an array, `min:2` and `max:500`
  (`config('accounting.limits.max_lines_per_journal')`). A payload with thousands
  of lines would otherwise hold a transaction open for an unbounded time.
- Each `account_id` must exist, scoped to the active company.
- Each `debit`/`credit` must be a decimal with 0–`max_input_decimals` places.
  The rule is `decimal`, never `numeric`: PHP would coerce a float and quietly
  lose precision on exactly the values that matter.

Cross-line rules run in `withValidator()` so every problem in a submission is
reported at once instead of one per submit: no negative amount, no line with
both a debit and a credit, no line with zero on both sides, and the journal
totals balanced.

The balance is summed by the server from the submitted lines. Any client-supplied
total or `is_balanced` flag is ignored entirely — a client that computed its
totals correctly proves nothing, and one that did not must not be able to talk
the server into posting an unbalanced entry.

### Rounding

Incoming amounts are rounded to the stored scale rather than rejected, half-up.
A client sending `0.00001` means "a very small amount", and rejecting the whole
journal over the fifth decimal place is worse for the user than rounding to the
precision the system can store.

Tolerance has a limit. `max_input_decimals` (10) bounds how much extra precision
a request may submit; otherwise "accept extra precision" becomes "accept a
thousand-digit fraction" and the rounding step has to handle whatever arrives.
Ten places is well beyond any real currency rate.

The rounded value is re-checked against the one-sided and non-zero rules
afterwards, so `0.00001` rounds to `0.0000` and is then rejected as a zero-value
line rather than silently becoming a real entry.

## 9. Test Coverage

Full suite: **303 tests, 1055 assertions, all passing.** Unit suite: 39 tests,
75 assertions. Accounting feature tests: 109 tests, 482 assertions.

| File | Tests | Assertions | Covers |
|---|---|---|---|
| `AccountCrudTest` | 16 | 61 | CRUD, per-company code/name uniqueness, mass-assignment refusal, the five fundamental types, activation lifecycle, delete guard, listing filters and the history flag |
| `JournalLifecycleTest` | 12 | 68 | Draft create/read/update/delete, per-company numbering, no reuse after delete, listing filters, cross-company account reference, dating outside a period |
| `JournalValidationTest` | 15 | 39 | Line rules, exact balance difference, precision and half-up rounding, round-to-zero rejection, service-level refusal without writes, and MySQL constraint enforcement |
| `PostingTest` | 11 | 57 | Happy path, server-assigned `posted_by`, edit/delete immutability, duplicate post (409), missing/closed period, inactive account, imbalance introduced after drafting, no line duplication, cross-company post |
| `AccountingPeriodTest` | 15 | 43 | CRUD, overlap and adjacency, narrowing vs widening, name uniqueness, closing, closed periods not editable, permissions, per-company isolation |
| `LedgerReportTest` | 15 | 121 | Debit/credit normal columns, contra sign, over-balanced negatives, draft exclusion, trial-balance footing and placement, company isolation, date ranges, running statements, ledger permission |
| `CompanyIsolationTest` | 10 | 30 | Cross-company read/write/borrow attempts on every accounting resource, non-member refusal, and the per-company code-uniqueness control case |
| `AccountingAuthorizationTest` | 15 | 63 | Full role matrix over HTTP, unauthenticated access, Manager read-only, Staff excluded |
| `MoneyTest` | — | — | BCMath arithmetic, rounding, tolerance, overflow |
| `AccountingRulesTest` | — | — | Normal-balance mapping, contra accounts, signed balances |

Authorization is asserted over HTTP rather than per policy method. A policy can
pass its own unit test and still be unreachable — a route with no middleware, a
controller that forgets to call `authorize()`, a FormRequest whose `authorize()`
asks the wrong question. Driving the role matrix through real requests makes it
a statement about the application rather than about classes.

`LedgerReportTest` proves the ledger permission is genuinely distinct: a user
granted `accounts.view` alone can list accounts and is still refused both
balance endpoints. Refusing a user who holds neither permission would prove
nothing — that request would fail even if the controller fell through to the
account's own policy.

`JournalValidationTest` asserts the MySQL `CHECK` constraints are actually
present and enforcing, by writing an invalid row directly through the query
builder and requiring the database to refuse it.

## 10. Issues Found and Fixed

Issues found while building and testing this phase, all fixed:

- **`CompanyContext::company()` did not exist.** Every accounting request and
  controller called it; the class exposes `get()`, `getOrFail()`, `id()` and
  `has()`. Replaced with `getOrFail()` throughout — a request with no resolvable
  company must fail loudly rather than continue with `null`.
- **`self->cases()` instead of `self::cases()`** in `JournalSource`,
  `JournalStatus`, `NormalBalance` and `PeriodStatus`. Every `values()` helper
  fataled on first use.
- **`JournalService` relied on mass assignment for server-owned fields.**
  `company_id`, `journal_number`, `status` and `created_by` are not fillable, so
  they were silently dropped and drafts were created with no company. Now
  constructed explicitly and assigned with `forceFill()`. `JournalLine.journal_id`
  had the same defect.
- **Draft update/delete checked status before opening the transaction**, leaving
  a race against a concurrent post. Both now lock the row and re-check inside
  the transaction.
- **Duplicate posting returned `422`**, which is the wrong class of answer for a
  state conflict. Added `App\Exceptions\ConflictException`, rendering `409`.
- **`AccountingPeriodService` created and updated periods without
  `company_id`/`status`** for the same mass-assignment reason — periods were
  being written unscoped.
- **`AccountService` translated every `QueryException` into "duplicate account
  code"**, so an unrelated integrity failure reported a misleading message.
  Narrowed to the known unique-constraint names.
- **Account history filtering included accounts with no journal lines**; the
  flag now means "referenced by a journal line".
- **`AccountCrudTest` asserted the wrong role could delete an account** and used
  a code that was already taken in the company. The test was wrong, not the
  code.
- **`LedgerReportTest` asserted an over-balanced trial balance would still read
  balanced.** Replaced with tests for both genuinely unbalanced cases.
- **`JournalLineFactory` defaulted to `debit = 0, credit = 0`**, a line the
  validator rejects. It now defaults to a valid `100.0000` debit line.
- **JWT authentication state leaked between requests inside a test.** See below.

### The JWT test-harness defect

`php-open-source-saver/jwt-auth` memoises the token it parsed in
`JWT::$token`, and `setRequest()` does not clear it. The instance is a container
singleton, so within a single test — where the application is reused across
requests, unlike production — the *first* token sent authenticated every later
request. `AuthManager` likewise memoises guards, and `JWTGuard` memoises the
user it resolved.

Any test that switched actor mid-way was therefore silently continuing as the
original user. An authorization test built this way does not fail loudly; it
asserts about the wrong person. Found while writing `AccountingAuthorizationTest`,
where an Admin's request to create a journal returned `403` because the guard
still held the Manager from the previous request.

Fixed in `Tests\TestCase::actingAsJwt()`, which now forgets the `tymon.jwt` and
`tymon.jwt.auth` instances, clears the facade's resolved instance and forgets the
guards before issuing the next token. The facade is cleared as well so
`JWTAuth::getToken()` in a logout handler cannot return a token from an earlier
request.

The full Phase 1–3 suite passes unchanged after the fix, so no existing test was
relying on the leaked identity.

## 11. Security Review

- **Company isolation.** Every accounting query carries a company predicate, and
  route binding returns `404` for another company's ids. `CompanyIsolationTest`
  covers read, write, delete, post, balance and period access across two
  companies, including borrowing an id inside a valid company.
- **No privilege from the payload.** `company_id`, `journal_number`, `status`,
  `posted_by`, `posted_at` and line `journal_id` are not mass assignable.
- **Posting is server-decided.** Balance, account ownership, account activity and
  period status are all recomputed at post time; nothing is trusted from the
  draft.
- **Posted journals are immutable.** Enforced by `JournalPostingService` being
  the only writer, by the row lock on update/delete, by
  `journals_posted_fields_check`, and by the absence of any update endpoint that
  can alter a posted journal.
- **No stored balances.** A compromised balance cannot be corrected by writing
  to it; every figure is derived from posted lines.
- **Unauthenticated access** to every accounting endpoint returns `401`.
- **System accounts cannot be deactivated by hand** (`422`), so a module-owned
  account can never be taken out of service through the API.
- **Constraint defence in depth.** Every `CHECK` constraint has an
  application-level counterpart, so a project on a server that ignores `CHECK`
  still behaves correctly.

## 12. Requirements Traceability

| Requirement | Implementation |
|---|---|
| Company-scoped chart of accounts | `accounts`, `AccountService`, `CompanyContext` |
| Unique codes/names per company | `UNIQUE (company_id, code)`, `UNIQUE (company_id, name)` |
| Normal balance derived from type, contra supported | `AccountType::normalBalance()`, `AccountingRules`, nullable `accounts.normal_balance` |
| Double-entry journals | `journal_lines`, `ValidatesJournalLines::assertBalanced()` |
| Sequential non-reusable journal numbers | `JournalNumberSequence`, `journal_number_sequences` |
| Draft → posted lifecycle | `JournalService`, `JournalPostingService`, `JournalStatus` |
| Posted journals immutable | Single-writer service, row locking, `journals_posted_fields_check` |
| Posting requires balanced entry, active accounts, open period | `JournalPostingService::post()` steps 4–7 |
| Server-assigned `posted_by`/`posted_at` | Forced from the authenticated user, never the payload |
| Accounting periods with overlap prevention and closing | `AccountingPeriodService` |
| Derived ledger, no balance table | `LedgerService`, read-only |
| `DECIMAL(20,4)` / BCMath | `config/accounting.php`, `App\Support\Money` |
| Permission architecture reused | `PermissionName`, policies, Gate abilities, FormRequests |
| Company isolation (`404` cross-company) | Scoped route binding + `CompanyIsolationTest` |
| No default chart seeding, no balance table, no period reopen | — |

## 13. Outstanding Work

Deliberately out of scope for this phase:

- No transactional modules (invoices, bills, payments, payroll). `journals`
  already carries `source_type`/`source_id` for them.
- No currency conversion, budgets, or multi-currency reporting.
- No default chart-of-accounts seeding.
- No period reopening, and no period-end auto-close.
  - Period reopening was delivered in Phase 8 (`accounting.periods.reopen`, Admin
    only). Period-end auto-close remains unimplemented: nothing in this system
    advances a calendar on its own, so closing stays an explicit human act.
- `journal_lines` has no `company_id` by design; any future denormalisation
  would reintroduce the possibility of a company mismatch.
- The transaction-safety guarantee for posting is enforced with
  `lockForUpdate()`. It is verified by sequential test cases (including the
  duplicate-post `409`); a genuinely concurrent two-connection test is not yet
  written.

Carried forward from Phase 3, unchanged and still open:

- Wildcard CORS origin in development.
- Activation/deactivation permission asymmetry in the user module.
- `User::$fillable` still contains `is_active`.
