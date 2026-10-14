# PHASE 14.1 — MULTI-CURRENCY ACCOUNTING INTEGRATION

## Laravel 13 Accounting Backend

### Continuation of Phase 14 — Multi-Currency & Foreign Exchange Accounting

---

# 1. ROLE

You are working as a senior Laravel backend/accounting-engine developer on an
existing production-oriented accounting application.

This is **not a greenfield implementation**.

You are continuing **Phase 14 — Multi-Currency & Foreign Exchange Accounting**
from a partially completed implementation.

The previous Phase 14 execution report explicitly states that approximately 40%
of the phase is complete.

Your job is to:

1. Inspect the repository first.
2. Verify the existing Phase 14 implementation.
3. Preserve all correct existing work.
4. Implement only the outstanding Phase 14 functionality.
5. Do not redesign working architecture without a concrete correctness reason.
6. Do not create a second accounting/journal engine.
7. Do not introduce unnecessary abstractions.
8. Maintain all accounting, company-isolation, authorization, audit, exact-money
   and fiscal-period conventions established in previous phases.

---

# 2. CRITICAL BASELINE

The previous Phase 14 execution report is the baseline for this continuation.

The following work is already implemented and MUST NOT be rebuilt unnecessarily.

## Already implemented

### Database

Ten Phase 14 migrations already exist and have been verified through:

```text
migrate
migrate:rollback --step=10
migrate
```

The migrations cover:

- currencies
- exchange rates
- company FX settings
- company base currency
- account currency restrictions
- journal-line FX metadata
- document currency metadata
- document-line base tax
- payment FX metadata
- cash/bank FX metadata

Do not recreate these migrations.

Do not create duplicate columns.

Do not create duplicate tables.

---

## Existing domain infrastructure

The following already exists and must be inspected and reused:

- `App\Support\Money`
- `App\Support\Rate`
- `Currency`
- `ExchangeRate`
- `CurrencyService`
- `ExchangeRateService`
- `DocumentCurrencyService`
- `TransactionCurrency`
- `RealizedFxService`
- `RealizedFxResult`
- `Account::acceptsCurrency()`
- `company_fx_settings`
- Phase 14 journal-line FX columns
- Phase 14 document FX columns
- Phase 14 payment FX columns
- Phase 14 cash/bank FX columns

Do not replace these with parallel implementations.

---

# 3. PREVIOUS VERIFIED TEST BASELINE

The previous Phase 14 report recorded:

```text
109 new Phase 14 tests
174 Phase 14 assertions

Full suite:
1056 tests
4804 assertions
0 failures
0 errors
```

The previous baseline before Phase 14 was:

```text
947 tests
4624 assertions
```

Before modifying anything:

1. Run the existing test suite.
2. Confirm the actual repository state.
3. Confirm the current number of tests/assertions.
4. Report any discrepancy between the repository and the previous report.
5. Do not assume the report is more authoritative than the source code.

---

# 4. IMPORTANT ACCOUNTING ARCHITECTURE

## 4.1 Base ledger remains authoritative

The existing ledger architecture MUST remain:

```text
journal_lines.debit
journal_lines.credit
```

These remain the authoritative ledger amounts.

They are always expressed in the company's base currency.

Phase 14 foreign-currency fields are provenance and transaction metadata:

```text
currency_id
foreign_debit
foreign_credit
exchange_rate
```

Do NOT create:

- a second foreign ledger
- a second journal engine
- foreign balance columns
- duplicate ledger tables
- stored account balances
- parallel posting engines

---

# 5. MONEY RULES

All accounting arithmetic MUST use the existing exact decimal infrastructure.

Use:

```text
App\Support\Money
App\Support\Rate
```

Do NOT use floating-point arithmetic for:

- accounting amounts
- exchange rates
- tax calculations
- FX gain/loss
- journal balancing
- settlement calculations
- report calculations

A float may only be used where it is strictly presentation-only and already
accepted by the existing architecture.

---

# 6. EXCHANGE RATE RULES

The existing rate model and service are authoritative.

Rules:

1. Rate direction:

```text
rate = units of TO currency per 1 unit of FROM currency
```

2. Conversion is always:

```text
foreign_amount × rate
```

3. Never divide to perform conversion.

4. Never automatically invert a rate.

5. Never invent a missing rate.

6. Never default a missing foreign rate to `1`.

7. Rate resolution is:

```text
latest effective_from <= document/transaction date
```

8. Resolution MUST use the actual document/transaction date.

9. Never silently resolve against today's date.

10. A rate that has priced a document must remain historically consistent.

Reuse `ExchangeRateService`.

Do not implement another rate-resolution algorithm.

---

# 7. PHASE 14 OUTSTANDING WORK

The previous report identified the following remaining work:

1. Journal FX integration.
2. Document FX integration.
3. Tax FX integration.
4. Settlement integration.
5. Realized FX posting.
6. Cash/bank FX integration.
7. Reporting integration.
8. Accounting controls.
9. Authorization.
10. API surface.
11. Base-currency safety.
12. Audit expansion.
13. Concurrency verification.
14. Final Phase 14 verification.

Implement these in dependency order.

---

# 8. STEP 0 — REPOSITORY AUDIT

Before coding, inspect:

```text
app/
database/
routes/
tests/
prompt/
docs/
```

Specifically locate:

- `JournalService`
- `JournalPostingService`
- journal creation/update logic
- document services
- invoice services
- purchase bill services
- credit/debit note services
- tax calculation services
- payment services
- allocation services
- cash/bank transaction services
- reporting services
- permission definitions
- authorization services
- audit services
- accounting control services, if any
- company settings services
- company context implementation

Compare actual implementation against the previous Phase 14 report.

Create an internal checklist before modifying code.

Do not rewrite correct existing code merely because it differs stylistically
from this prompt.

---

# 9. STEP 1 — JOURNAL FX INTEGRATION

This is the highest-priority item.

The previous Phase 14 report states:

> Journal FX columns exist and constraints are tested, but JournalService and
> JournalPostingService do not populate them.

Implement the missing integration.

---

## 9.1 Journal creation

A journal line may optionally represent a foreign-currency amount.

For a foreign transaction, the line must contain enough information to
reconstruct:

```text
foreign amount
transaction currency
exchange rate
base amount
```

Example:

```text
Foreign amount: 1000 EUR
Rate:           1.10 USD/EUR
Base amount:    1100 USD
```

The stored journal line must therefore preserve:

```text
currency_id = EUR
foreign_debit = 1000
exchange_rate = 1.10
debit = 1100
```

or the corresponding credit representation.

---

## 9.2 Base-currency journal lines

If the transaction currency is the company's base currency:

```text
currency_id = NULL
foreign_debit = NULL
foreign_credit = NULL
exchange_rate = NULL
```

Do NOT store:

```text
exchange_rate = 1
```

for a base-currency transaction.

The existing architecture deliberately distinguishes:

```text
base currency
```

from:

```text
foreign currency at rate 1
```

Preserve this distinction.

---

## 9.3 Journal validation

Validate:

- currency exists
- currency is active
- company can use the currency
- account accepts the currency
- foreign debit/credit one-sidedness
- exchange rate consistency
- base amount consistency
- transaction date/rate resolution
- company isolation

Never trust a client-provided base amount when the system can derive it.

---

## 9.4 Server-owned accounting values

Where the existing architecture treats accounting values as server-owned,
continue doing so.

Do not allow clients to manipulate:

- posted journal amounts
- calculated base amounts
- FX gain/loss amounts
- account/company ownership
- journal posting state
- historical rate identity

---

# 10. STEP 2 — JOURNAL POSTING

Integrate FX metadata into the existing posting engine.

Do not create a second posting path.

The existing transition must remain:

```text
DRAFT → POSTED
```

Posted journals remain immutable.

When a foreign-currency journal is posted:

1. Validate the accounting period.
2. Validate FX data.
3. Validate currency/account compatibility.
4. Validate base/foreign mathematical consistency.
5. Preserve the original FX snapshot.
6. Post through the existing journal engine.

No posted journal may later be repriced because an exchange-rate table changed.

---

# 11. STEP 3 — DOCUMENT CURRENCY INTEGRATION

Integrate the existing FX schema into:

- SalesInvoice
- PurchaseBill
- CreditDebitNote
- corresponding document-line models
- their create/update services

The previous report states that the columns already exist but are not populated.

Implement the actual document workflow.

---

## 11.1 Document creation

A document must resolve its currency explicitly.

For example:

```text
document currency = EUR
company base      = USD
document date     = 2026-10-01
```

Resolve the rate effective on:

```text
2026-10-01
```

not today's rate.

---

## 11.2 Snapshot rule

When the document is created:

```text
currency_id
exchange_rate
base_subtotal
base_tax
base_grand_total
```

must represent the rate and calculated base values applicable to that document.

Later changes to the exchange-rate table must not silently recalculate the
posted document.

---

## 11.3 Draft vs posted behavior

Follow the existing document lifecycle.

Draft documents may be recalculated only according to the existing application
rules.

Once the document is posted/finalized under the application's existing
semantics:

- its accounting meaning is fixed
- its FX snapshot must remain stable
- later rate changes must not rewrite historical accounting

Do not invent a new document lifecycle.

---

# 12. STEP 4 — DOCUMENT LINE CONVERSION

For every foreign-currency document line:

```text
foreign line amount
×
document exchange rate
=
base line amount
```

Use exact decimal arithmetic.

Do not independently resolve a new rate for every line if the document itself
has already established its authoritative transaction rate.

All lines belonging to one document must use the document's applicable FX
snapshot unless the existing accounting design explicitly requires otherwise.

---

# 13. STEP 5 — TAX FX INTEGRATION

The previous report states:

```text
base_tax_amount
```

already exists but is unused.

Integrate it with the existing tax engine.

Do NOT create a second tax engine.

The existing tax calculation remains authoritative.

For every taxable line:

```text
foreign taxable amount
→ existing tax calculation
→ foreign tax amount
→ base tax amount
```

The base tax amount must use the document's authoritative FX rate.

---

## 13.1 Tax report compatibility

Update tax reporting so that existing tax reports continue to operate correctly.

Where a report needs base tax amounts for accounting/reporting purposes, use:

```text
base_tax_amount
```

rather than reconverting using today's rate.

Historical tax values must never change because an exchange rate changed later.

---

# 14. STEP 6 — PAYMENT / SETTLEMENT INTEGRATION

Integrate:

- CustomerReceipt
- SupplierPayment
- PaymentAllocation
- corresponding settlement services

The previous report states that the FX columns already exist but are not
populated.

---

# 15. SETTLEMENT CURRENCY RULE

A settlement allocation must obey the documented currency rule.

For the first implementation:

```text
document currency == settlement currency
```

must be enforced for ordinary allocation.

Do not silently convert one foreign currency directly into another during
allocation.

Cross-currency settlement is outside this phase unless the existing architecture
explicitly supports it.

If incompatible currencies are supplied:

- reject validation
- do not create a partial allocation
- do not create an unbalanced journal

---

# 16. SETTLEMENT FX SNAPSHOT

When a foreign-currency payment/receipt is created:

store:

```text
currency_id
exchange_rate
base_amount
```

according to the actual settlement date.

Do not use:

- today's rate for backdated settlements
- invoice creation rate for the settlement
- arbitrary client-provided rate without validation

The settlement has its own economic date and therefore its own applicable rate.

---

# 17. REALIZED FX POSTING

The existing:

```text
RealizedFxService
RealizedFxResult
```

already calculate realized FX correctly.

Do not replace them.

Integrate them into the settlement posting flow.

---

## 17.1 Accounting rule

For a foreign receivable:

```text
carrying base value
```

must be cleared from the receivable.

The difference between:

```text
settlement base value
```

and:

```text
carrying base value
```

must go to:

```text
FX Gain
```

or:

```text
FX Loss
```

---

## 17.2 Example

Invoice:

```text
1,000 EUR
Original rate: 1 EUR = 1.65 USD
Carrying value: $1,650
```

Settlement:

```text
1,000 EUR
Settlement rate: 1 EUR = 1.70 USD
Settlement value: $1,700
```

Journal:

```text
Debit  Bank              $1,700
Credit Receivable        $1,650
Credit FX Gain              $50
```

The receivable is cleared at:

```text
$1,650
```

not:

```text
$1,700
```

---

## 17.3 Loss example

If the settlement base value is lower:

```text
Debit  Bank
Debit  FX Loss
Credit Receivable
```

The signs must be verified through reconstructed journal entries.

Do not test only the numeric difference.

---

# 18. FX ACCOUNT CONFIGURATION

Use the existing:

```text
company_fx_settings
```

configuration.

If FX gain/loss accounts are missing:

```text
RealizedFxService
```

must refuse the foreign settlement.

Do not:

- silently skip FX
- post the difference to the receivable
- hardcode an account
- invent an account

---

# 19. PARTIAL PAYMENTS

Support partial settlement correctly.

Example:

```text
Invoice:
1,000 EUR

First payment:
400 EUR

Second payment:
600 EUR
```

Each settlement must calculate realized FX against the carrying value
represented by the allocated portion.

Do not calculate the second payment as though the full invoice were still
outstanding at its original full carrying value.

Test:

- partial payment
- multiple partial payments
- final payment
- gain
- loss
- zero FX difference

---

# 20. STEP 7 — CASH/BANK FX INTEGRATION

Integrate the existing FX fields into cash/bank transactions.

The previous report states:

```text
currency_id
exchange_rate
base_amount
```

already exist.

Implement the actual service-level behavior.

Do not implement cross-currency cash/bank transfers.

Those remain deferred.

---

# 21. STEP 8 — REPORTING INTEGRATION

Update the existing reports without replacing their architecture.

At minimum inspect and integrate:

- General Ledger
- Journal listing/detail
- Account Statement
- Trial Balance
- customer/supplier transaction reporting
- payment/settlement reports
- tax reporting
- cash/bank transaction reports

Reports must distinguish:

```text
transaction currency
foreign amount
exchange rate
base amount
```

where applicable.

---

## 21.1 Base-currency disclosure

Reports that show accounting balances must clearly communicate that ledger
balances are expressed in:

```text
company base currency
```

Do not allow a foreign amount to be mistaken for a base ledger amount.

---

## 21.2 Historical reporting

Reports for historical transactions must use the stored transaction/document FX
snapshot.

Never recalculate historical posted values using today's exchange rate.

---

# 22. ACCOUNT STATEMENT

Where foreign movements are available, expose enough information to understand:

```text
date
reference
currency
foreign debit
foreign credit
exchange rate
base debit
base credit
```

The base ledger remains authoritative.

Foreign values are explanatory/provenance values.

---

# 23. TRIAL BALANCE

Trial balance must remain balanced in base currency.

Foreign transaction metadata must not create a second trial balance.

The existing debit/credit totals remain authoritative.

Add currency context only where appropriate.

---

# 24. STEP 9 — ACCOUNTING CONTROLS

The previous report states:

```text
AccountingControlService
```

does not yet exist.

Implement it only if required by the existing Phase 13/14 architecture.

First inspect the repository for any later-created control infrastructure.

Do not blindly assume it is absent.

The control layer should cover at minimum:

- missing company base currency
- unsafe base-currency change
- foreign transaction without valid rate
- foreign settlement without FX accounts
- incompatible account/document currency
- invalid historical FX state

Controls must detect problems.

Do not auto-repair financial data.

---

# 25. BASE-CURRENCY CHANGE SAFETY

A company base currency change is financially dangerous.

Do not allow a base-currency change that would silently reinterpret existing
posted accounting data.

Before allowing any change, inspect existing accounting/document history.

If existing posted financial data prevents a safe change:

```text
reject the change
```

with a clear validation/business-rule error.

Do not rewrite historical journal lines.

Do not automatically convert historical accounting data.

Do not silently migrate the ledger to a new base currency.

---

# 26. STEP 10 — AUTHORIZATION

Implement the Phase 14 permissions using the project's existing permission
architecture.

Planned permissions:

```text
accounting.currency.*
accounting.exchange_rate.*
accounting.fx.update
accounting.controls.view
```

and existing:

```text
companies.settings.update
```

for base-currency configuration.

Do not invent a new authorization system.

Inspect:

- `PermissionName`
- role definitions
- permission middleware
- policies
- existing authorization tests

Then integrate consistently.

---

# 27. AUTHORIZATION PRINCIPLES

Currency master is global reference data and therefore has higher sensitivity.

Exchange rates affect every financial calculation for the company.

Base currency changes are especially sensitive.

Controls are read-only diagnostic functionality unless the existing application
explicitly provides administrative remediation.

Do not give ordinary users unrestricted FX configuration permissions.

Use the existing role/permission conventions rather than hardcoding role names
in business logic.

---

# 28. STEP 11 — API SURFACE

Implement the HTTP layer according to the existing Laravel API conventions.

This includes, where appropriate:

- controllers
- form requests
- resources
- routes
- policies
- validation
- authorization

Do not expose internal service methods directly.

---

## Currency API

Support the existing lifecycle:

```text
create
read
update
activate/deactivate
```

Do NOT create a currency delete endpoint.

---

## Exchange-rate API

Support:

```text
create
read
update where historically safe
```

and preserve historical immutability rules.

A rate already used to price a document must not be casually modified.

Use validation errors rather than HTTP 500 for business-rule violations.

---

# 29. COMPANY ISOLATION

Every company-scoped operation MUST remain company-scoped.

Verify:

- exchange rates
- company FX settings
- journals
- documents
- payments
- settlements
- reports
- controls

Do not trust:

```text
company_id
```

from request payloads.

Use the existing:

```text
CompanyContext
```

and scoped route-binding conventions.

Cross-company IDs must not expose another company's data.

Where the application's existing convention requires it, cross-company resources
should resolve as:

```text
404
```

rather than leaking existence through authorization/business errors.

---

# 30. AUDIT INTEGRATION

Extend the existing audit system for newly introduced FX behavior.

Audit at minimum:

- currency creation
- currency update
- currency activation/deactivation
- exchange-rate creation
- exchange-rate updates where allowed
- FX setting changes
- base-currency changes or rejected sensitive attempts where the existing audit
  convention requires it
- realized FX posting

Do not trust user-supplied audit identity.

Use the authenticated user from the existing audit infrastructure.

---

# 31. AUDIT SECRET SCRUBBING

The previous Phase 14 report identifies secret scrubbing as an outstanding gap.

Before implementing it, inspect the existing audit serialization architecture.

If the existing global audit system can safely be improved without changing
unrelated behavior, implement the required secret/cookie/session scrubbing.

Do not create an FX-only secret-scrubbing implementation.

The security rule should apply consistently to the audit system.

---

# 32. CONCURRENCY

The previous implementation correctly relies on the database unique constraint
for:

```text
(company_id, from_currency_id, to_currency_id, effective_from/day)
```

Do not replace this with an application-only existence check.

Add a meaningful concurrency test where practical.

The test should demonstrate that concurrent duplicate rate creation results in:

- one successful record
- one rejected duplicate
- no duplicate authoritative rate

The unique database constraint remains the final authority.

---

# 33. VALIDATION AND ERROR HANDLING

Business-rule failures must become appropriate validation/domain exceptions.

Do not allow expected financial validation failures to become:

```text
500 Internal Server Error
```

Examples:

- missing FX rate
- inactive currency
- duplicate rate
- incompatible account currency
- missing FX accounts
- unsafe base-currency change
- cross-currency allocation
- invalid historical modification

Use the application's established exception and API error conventions.

---

# 34. TESTING REQUIREMENTS

Every implemented feature requires focused tests.

At minimum add tests for:

## Journal

- foreign debit
- foreign credit
- base conversion
- missing rate
- wrong rate date
- account currency compatibility
- immutable posted FX
- invalid FX metadata
- base-currency journal normalization

## Documents

- foreign invoice
- foreign purchase bill
- foreign credit/debit note
- document snapshot
- backdated document
- historical rate preservation
- base totals

## Tax

- foreign taxable line
- base tax amount
- historical tax reporting

## Settlement

- same-currency allocation
- incompatible currency rejection
- partial settlement
- full settlement
- settlement at gain
- settlement at loss
- zero FX difference
- missing FX accounts
- historical settlement date

## Realized FX

Assert reconstructed journal entries.

Do NOT merely assert:

```text
difference == 50
```

Assert the complete accounting result:

```text
Debit/credit account
Amount
Currency
Base amount
FX account
Clearing amount
```

## Cash/bank

- foreign transaction
- correct snapshot
- correct base amount
- backdated rate

## Reports

- base totals
- foreign metadata
- historical snapshot
- trial balance remains balanced

## Authorization

Test each new permission.

## Company isolation

Test cross-company access.

## Base currency

Test unsafe changes.

## Concurrency

Test duplicate rate creation under contention where the project's test
infrastructure supports it.

---

# 35. REGRESSION TESTING

After implementation:

```text
php artisan test
```

must pass.

Also run:

```text
./vendor/bin/pint --test
```

or the repository's established formatting verification command.

If frontend exists but is outside this backend phase, do not modify it unless
explicitly required.

---

# 36. NO SEEDERS

Do NOT create or run seeders.

The previous Phase 14 deliberately established:

```text
No default currencies.
No automatic financial master-data seeders.
```

Preserve this decision.

Do not insert:

```text
USD
EUR
INR
```

or any other currency automatically.

---

# 37. NO FRONTEND

This phase continuation is backend-focused.

Do not implement Next.js pages, dashboards, charts or frontend UI.

Only implement API resources/contracts required by the backend architecture.

---

# 38. DEFERRED FUNCTIONALITY

Do NOT implement the following unless the repository proves that they have
already been introduced elsewhere:

### Unrealized FX revaluation

Deferred.

### Cross-currency cash/bank transfers

Deferred.

### Foreign bank reconciliation

Deferred.

### Fixed-asset FX depreciation

Deferred.

### Automatic reciprocal rate generation

Not allowed.

### Parallel foreign ledger

Not allowed.

---

# 39. POSTED DATA IMMUTABILITY

This is a critical accounting invariant.

Do not modify posted accounting meaning because:

- an exchange rate changed
- a currency was deactivated
- a company setting changed
- a new tax rate was added
- a report was regenerated

Posted documents and journals retain their historical accounting values.

---

# 40. DO NOT OVERENGINEER

Prefer the existing architecture.

Do not introduce:

- event-sourcing
- CQRS
- repositories for every model
- unnecessary interfaces
- generic FX frameworks
- new money engines
- new journal engines
- new authorization systems
- new reporting frameworks

unless the repository already uses those patterns.

The goal is a correct accounting implementation, not architectural novelty.

---

# 41. IMPLEMENTATION ORDER

Follow this order unless repository dependencies require a minor adjustment:

```text
1. Repository audit
2. Existing test baseline
3. Journal FX integration
4. Journal posting integration
5. Document currency integration
6. Document-line base conversion
7. Tax integration
8. Payment/settlement integration
9. Realized FX posting
10. Partial settlement handling
11. Cash/bank FX integration
12. Reporting integration
13. Accounting controls
14. Base-currency safety
15. Authorization
16. API layer
17. Audit expansion
18. Concurrency verification
19. Focused tests
20. Full regression suite
21. Formatting verification
22. Final Phase 14 report
```

Do not jump directly to API work while the accounting engine remains incomplete.

---

# 42. HARD STOP CONDITIONS

Stop and report instead of guessing if you discover:

1. The existing Phase 14 schema differs materially from this report.
2. A previous phase's accounting invariant conflicts with this continuation.
3. The existing journal architecture cannot represent the required FX
   information without changing a previously established invariant.
4. A posted document would need mutation to implement realized FX.
5. Existing business behavior contradicts the documented Phase 14 decisions.
6. A migration would destroy or reinterpret existing financial data.
7. The implementation would require a second ledger.
8. A safe base-currency migration cannot be established.
9. Existing tests reveal an unexplained regression.
10. The repository has moved significantly beyond the previous Phase 14 report.

Do not "fix" uncertainty by inventing behavior.

---

# 43. REQUIRED VERIFICATION

Before declaring completion, verify:

### Database

```text
migrate
rollback
migrate
```

where safe and consistent with the project's testing environment.

### Tests

Full suite must pass.

### Formatting

Pint must pass.

### Accounting

Verify:

```text
foreign amount × rate = base amount
```

for all supported flows.

### Journal

Verify all posted journals remain balanced.

### FX

Verify:

```text
settlement base
-
carrying base
=
realized FX
```

with correct gain/loss signs.

### Isolation

Verify company A cannot use company B's:

- rates
- FX settings
- accounts
- documents
- settlements

### Authorization

Verify unauthorized users cannot:

- create currencies
- modify exchange rates
- change sensitive FX settings
- perform privileged FX operations

according to the established permission matrix.

---

# 44. FINAL ACCEPTANCE CRITERIA

Phase 14 continuation is complete only when:

- [ ] Journal FX integration works.
- [ ] Foreign journal lines preserve transaction currency and FX provenance.
- [ ] Base ledger remains authoritative.
- [ ] Foreign document creation works.
- [ ] Document FX rates are date-correct.
- [ ] Document base totals are stored correctly.
- [ ] Tax base amounts are populated.
- [ ] Settlement allocation enforces currency compatibility.
- [ ] Settlement FX is date-correct.
- [ ] Partial settlements work.
- [ ] Realized FX journals are generated correctly.
- [ ] FX gain/loss signs are correct.
- [ ] Receivable/payable carrying value is cleared correctly.
- [ ] Cash/bank FX transactions work.
- [ ] Reports expose appropriate FX information.
- [ ] Trial balance remains base-currency authoritative.
- [ ] Accounting controls exist where required.
- [ ] Unsafe base-currency changes are rejected.
- [ ] Authorization is enforced.
- [ ] API routes/resources/requests follow existing conventions.
- [ ] Company isolation is verified.
- [ ] Audit integration is complete.
- [ ] Concurrency behavior is verified.
- [ ] No seeders were created or executed.
- [ ] No frontend work was introduced.
- [ ] Deferred functionality remains deferred.
- [ ] Full test suite passes.
- [ ] Pint passes.
- [ ] No unexplained regression exists.

---

# 45. FINAL REPORT

Create/update:

```text
docs/reports/PHASE_14_REPORT.md
```

following the repository's actual report convention.

The report MUST clearly distinguish:

## Implemented

What was actually delivered.

## Existing from Previous Phase 14

What was already present and reused.

## Tests

Include:

```text
Phase 14 new tests
Phase 14 new assertions
Full suite test count
Full suite assertion count
Failures
Errors
```

## Migrations

Record migration verification.

## Security

Record:

- authorization
- company isolation
- tampering protection
- immutability
- audit behavior

## Accounting Verification

Document representative examples for:

- foreign invoice
- foreign payment
- realized gain
- realized loss
- partial settlement

## Deferred

Explicitly retain:

- unrealized FX
- cross-currency cash/bank transfers
- foreign bank reconciliation
- fixed-asset FX depreciation

unless the repository's actual scope has changed.

## Known Limitations

Do not hide remaining limitations.

## Final Status

Use:

```text
PASS
PASS WITH NOTES
BLOCKED
```

only if the actual acceptance criteria justify it.

If the phase remains incomplete, state that honestly rather than claiming PASS.

---

# 46. FINAL EXECUTION RULE

Do not rush.

The order of priority is:

```text
Accounting correctness
> Data integrity
> Historical immutability
> Company isolation
> Authorization
> Auditability
> Test coverage
> API completeness
> Code convenience
```

Never sacrifice accounting correctness to make a test pass.

Never modify existing financial meaning merely to simplify implementation.

Never silently invent an exchange rate.

Never use floating-point arithmetic for accounting calculations.

Never create a second journal engine.

Never create a second foreign ledger.

Never seed financial master data.

Inspect first. Implement incrementally. Test after each logical integration.
Then run the complete regression suite before reporting completion.
