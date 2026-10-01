# Phase 7 — Cash & Banking Implementation Report

## Summary
Phase 7 was implemented with strict reuse of the existing journal, ledger, company-isolation, authorization and Money infrastructure. No second accounting engine, no balance columns on operational documents, and no new read layer were introduced. The cash/bank identity is an optional `accounts.cash_bank_kind` (CASH|BANK) combined with a separate `bank_accounts` metadata table. Document numbering uses prefix `CBN-` with a new `DocumentNumberType::CashBankTransaction`. One new journal source (`JournalSource::CashBankTransaction`) is added and posting always produces exactly Dr destination / Cr source.

## Key Decisions (matching requirements)
- **Classification, not a separate entity:** A cash/bank account is an ordinary account in the chart of accounts with `cash_bank_kind`. There is no standalone "cash account" creation endpoint — classification is performed via `PUT /api/cash-bank-accounts/{account}` (and accepted on account create/update). Bank operational details live in `bank_accounts` (1:1 with account), with `is_active` distinct from `accounts.is_active`.
- **Posting rule uniform:** Dr destination_account_id / Cr source_account_id for Deposit, Withdrawal and Transfer. 
  - Deposit: destination must be cash/bank; source is any active offset account (explicitly chosen).
  - Withdrawal: source must be cash/bank; destination is any active offset account.
  - Transfer: both accounts must be cash/bank. Source and destination must differ.
- **No derived balances:** No stored balance/opening/running/debit/credit totals on `cash_bank_transactions`. The balance question is answered exclusively by the existing ledger/read paths (e.g. `/api/accounting/accounts/{id}/balance` and the existing reports). The transaction resource exposes no balance fields.
- **Immutability and lifecycle:** Drafts only can be edited/deleted. Posting creates a journal (via `JournalService`), posts it (via `JournalPostingService`), links `journal_id`, sets `status=POSTED`, stamps `posted_by/posted_at`, and is idempotent-protected under a row lock (double-post returns 409). 
- **Company isolation and numbering:** All queries are company-scoped; route model bindings for `transaction` and reuse of the existing `account` binding enforce tenant boundaries. `DocumentNumberSequence` remains per-company. Cash/bank transactions use `DocumentNumberType::CashBankTransaction` with prefix `CBN-`.
- **Authorization:** Permissions `accounting.cash_bank.view|create|update|post|delete`. Admin and Accountant receive all; Manager receives view only; Staff none. Policies registered for `CashBankTransaction`; cash/bank account configuration checks permissions directly (to avoid a second policy on the same `Account` model). 

## Implementation Details
### Database
- `accounts`: added nullable enum `cash_bank_kind` (CASH|BANK) via migration `2026_10_02_120000_add_cash_bank_kind_to_accounts_table.php`.
- `bank_accounts`: `company_id`, `account_id` (unique 1:1), `account_name`, `bank_name`, `account_number`, `branch`, `bank_identifier`, `is_active`, timestamps. Migration `2026_10_02_120100_create_bank_accounts_table.php`.
- `cash_bank_transactions`: `company_id`, `transaction_number` (unique per company), `transaction_type` (DEPOSIT/WITHDRAWAL/TRANSFER), `transaction_date`, `source_account_id`, `destination_account_id`, `amount` (DECIMAL 20,4, not cast to float), `status` (DRAFT/POSTED via `PaymentStatus`), `reference`, `notes`, `journal_id` (nullable FK), audit fields (`created_by`, `posted_by`, `posted_at`), timestamps. Named indexes to avoid MySQL 64-char truncation. Migration `2026_10_02_120200_create_cash_bank_transactions_table.php`.

### Enums
- `App\Enums\CashBankKind` (values CASH, BANK; `values()` helper).
- `App\Enums\CashBankTransactionType` with `requiresBothAccountsCashBank()`, `cashBankSide()` (destination for DEPOSIT, source for WITHDRAWAL, null for TRANSFER).
- Extended `JournalSource` with `CashBankTransaction`, `DocumentNumberType` with `CashBankTransaction` (`CBN-`), `PermissionName` with five cash_bank permissions; `config/authorization.php` updated with role grants.

### Models & Factories
- `BankAccount`, `CashBankTransaction` (with `amountMoney()`, `isInternalTransfer()`, casts for enums/dates; amount not decimal-cast). `Account` gains `cash_bank_kind` (cast) and `bankAccount()` relation; `CashBankTransaction` has relations to company, accounts, journal, creator/poster. Factories added/updated.

### Services
- `TransactionAccountResolver`: `cashBank()` enforces classified active cash/bank account; `offset()` enforces active account (no cash/bank requirement).
- `CashBank\CashBankAccountService`: classify/clear (with history/bank-details guards), save/delete/setActive bank details, list (filters by kind, excludes inactive bank rows).
- `CashBank\CashBankTransactionService`: create/update/delete drafts with row locks, account-pair resolution/validation, amount normalization via `Money`, numbers via `DocumentNumberSequence`. Type fixed by endpoint.
- `CashBank\CashBankPostingService`: transactional posting (lock-for-update), re-validates pair under lock (type-sensitive), creates journal via `JournalService` (source `CASH_BANK_TRANSACTION`, reference fallback to transaction number), posts via `JournalPostingService`, stamps document as POSTED. 
- `Reports\CashBankReportService` docblock corrected (clarifies asset constraint on transaction paths vs report generality).

### HTTP Layer
- Requests: `Store/Update/PostCashBankTransactionRequest`, `CashBankTransactionFilterRequest`, `UpdateCashBankAccountRequest`, `SetCashBankAccountActiveRequest` — validation is minimal and defers business rules to services (including cross-field `source!=destination`, company-scoped accounts, positive amounts, filter rules).
- Resources: `CashBankAccountResource`, `CashBankTransactionResource` (no balance fields).
- Controllers: `CashBankAccountController` (index, update, destroy bank details, activate/deactivate) with direct permission checks; `CashBankTransactionController` (index with filters including account_id matching either side, three create endpoints by type, show/update/delete/post). 
- Routes registered under flat paths: `/cash-bank-accounts` and `/cash-bank-transactions` (with type-specific subpaths and post endpoint). Policies/bindings registered in `AppServiceProvider`.

## Tests
Added 91 new tests (unit + feature), all passing. Full suite: **600 tests, 2870 assertions, 0 failures**.

> Correction (added with Phase 8): the original figure of 89 undercounted the new
> tests. It was evidently obtained by counting `#[Test]` attributes, which misses
> cases that a data provider expands into several. Running the seven Phase 7 files
> gives the true counts, 91 tests / 466 assertions:
>
> | File | `#[Test]` | Cases run |
> | --- | ---: | ---: |
> | `CashBankAccountTest` | 17 | 17 |
> | `CashBankMovementTest` | 18 | 22 |
> | `CashBankPostingTest` | 15 | 15 |
> | `CashBankAuthorizationTest` | 8 | 11 |
> | `CashBankCompanyIsolationTest` | 9 | 9 |
> | `CashBankLedgerTest` | 7 | 7 |
> | `Unit/CashBankRulesTest` | 6 | 10 |
> | **Total** | **80** | **91** |
>
> The full-suite figures of 600 tests / 2870 assertions were measured by running
> every test file except the six Phase 8 files as a set. The earlier figure of
> 2868 assertions was two short and is corrected here; the test count of 600 is
> unchanged.

New tests cover:
- `tests/Unit/CashBankRulesTest.php`: enum rule matrix and invariants.
- `tests/Feature/Accounting/CashBankAccountTest.php`: classification, bank details lifecycle, listing/filtering, resource shape (no balance), permission gating, generic account create/update integration.
- `tests/Feature/Accounting/CashBankMovementTest.php`: eligibility matrix per type (deposit/withdrawal/transfer), draft lifecycle, partial-edit revalidation, immutable type, amount exactness.
- `tests/Feature/Accounting/CashBankPostingTest.php`: posting produces exactly 2 lines Dr destination/Cr source for all three types, journal linkage/source/traceability, immutability, double-post (409), audit stamps not client-supplied, ledger effects visible via existing balance endpoint, period/eligibility revalidation at posting.
- `tests/Feature/Accounting/CashBankAuthorizationTest.php`: exact permission set per role (Admin/Accountant full, Manager view-only, Staff none), gating separation (create vs post), membership enforcement.
- `tests/Feature/Accounting/CashBankCompanyIsolationTest.php`: cross-company 404s via bindings, listing/account filters scoped, cannot create against foreign accounts, document numbers scoped per company, posting context isolation.
- `tests/Feature/Accounting/CashBankLedgerTest.php`: drafts invisible to ledger, postings accumulate, existing cash-bank report reads Phase 7 movements, transaction resource has no balance, listing filters/pagination/order.

## Verification

Commands run from `accounting-app/backend`:

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Accounting/CashBank*.php tests/Unit/CashBankRulesTest.php` | 91 tests, 466 assertions, 0 failures |
| `php artisan test tests/Feature/Accounting` | 261 tests, 1206 assertions, 0 failures |
| `php artisan test` | 600 tests, 2870 assertions, 0 failures |
| `./vendor/bin/pint --test` | passed |
| `php artisan route:list --path=cash-bank` | 14 routes, as expected |
| `php artisan migrate --database=mysql` / `migrate:rollback --step=N` | applied and rolled back cleanly in the testing database |

The financial-year accounting used above was re-run during Phase 8, which is why
the Phase 7 test counts in this report were corrected rather than left at the
attribute-derived figure of 89.
