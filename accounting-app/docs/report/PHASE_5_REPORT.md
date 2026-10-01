# Phase 5 Report — Transactional Accounting

## 1. Phase Objectives

Phase 5 turns the accounting foundation of Phase 4 into a transactional system:
company-scoped customers and suppliers, sales invoices and purchase bills with
line-level tax and discount arithmetic, customer receipts and supplier payments
that settle those documents through explicit allocations, and a journal produced
by every posting operation.

Delivered:

- `customers` and `suppliers` master records with an explicit, validated control
  account and deactivation instead of deletion.
- `sales_invoices` / `sales_invoice_lines` and `purchase_bills` /
  `purchase_bill_lines` with server-computed totals and a
  `DRAFT → POSTED → PARTIALLY_PAID/PAID` lifecycle.
- `customer_receipts` / `customer_receipt_allocations` and `supplier_payments` /
  `supplier_payment_allocations`, where allocations are the *only* source of
  settlement figures.
- A per-company, per-type sequential document numbering service issuing
  `INV-`, `BILL-`, `RCPT-` and `PAY-` numbers.
- Centralised account validation (`TransactionAccountResolver`) so no controller
  or service invents its own ownership/type rules.
- Every posting path goes through the Phase 4 `JournalService` /
  `JournalPostingService`. No second ledger exists.
- 30 new accounting permissions and a four-role matrix extension.
- **140 new tests, 785 assertions, all passing**; the full suite is
  **443 tests, 1862 assertions, all passing** with zero regressions against the
  Phase 1–4 baseline.

Explicitly **not** in scope: currency conversion, credit notes, purchase
returns, an unallocated-advance (on-account) payment flow, stored account
balances, and reopening a closed accounting period.

## 2. Files Changed

### Migrations added (10)

| Migration | Creates |
|---|---|
| `2026_10_01_110000_create_customers_table.php` | `customers` |
| `2026_10_01_110100_create_suppliers_table.php` | `suppliers` |
| `2026_10_01_110200_create_sales_invoices_table.php` | `document_number_sequences`, `sales_invoices` |
| `2026_10_01_110300_create_sales_invoice_lines_table.php` | `sales_invoice_lines` |
| `2026_10_01_110400_create_purchase_bills_table.php` | `purchase_bills` |
| `2026_10_01_110500_create_purchase_bill_lines_table.php` | `purchase_bill_lines` |
| `2026_10_01_110600_create_customer_receipts_table.php` | `customer_receipts` |
| `2026_10_01_110700_create_customer_receipt_allocations_table.php` | `customer_receipt_allocations` |
| `2026_10_01_110800_create_supplier_payments_table.php` | `supplier_payments` |
| `2026_10_01_110900_create_supplier_payment_allocations_table.php` | `supplier_payment_allocations` |

### Enums added (3)

`app/Enums/TransactionStatus.php`, `app/Enums/DocumentNumberType.php`,
`app/Enums/PaymentStatus.php`.

### Models added (7)

`Customer`, `Supplier`, `SalesInvoice`, `SalesInvoiceLine`, `PurchaseBill`,
`PurchaseBillLine`, `CustomerReceipt`, `CustomerReceiptAllocation`,
`SupplierPayment`, `SupplierPaymentAllocation`.

### Services added (11)

`app/Services/Accounting/`: `DocumentCalculator`, `DocumentNumberSequence`,
`TransactionAccountResolver`, `SettlementService`, `PaymentAllocationService`.
`app/Services/Sales/`: `CustomerService`, `SalesInvoiceService`,
`SalesInvoicePostingService`, `CustomerReceiptService`,
`CustomerReceiptPostingService`.
`app/Services/Purchasing/`: `SupplierService`, `PurchaseBillService`,
`PurchaseBillPostingService`, `SupplierPaymentService`,
`SupplierPaymentPostingService`.

### Support changed (1)

`app/Support/Money.php` — added `times()` (exact decimal multiply) and
`percentageOf()` (exact basis-point tax), plus `scale()`.

### Policies added (6)

`CustomerPolicy`, `SupplierPolicy`, `SalesInvoicePolicy`, `PurchaseBillPolicy`,
`CustomerReceiptPolicy`, `SupplierPaymentPolicy`.

### HTTP layer added

- Controllers: `Api/Sales/{Customer,SalesInvoice,CustomerReceipt}Controller.php`,
  `Api/Purchasing/{Supplier,PurchaseBill,SupplierPayment}Controller.php`.
- Requests: `app/Http/Requests/{Customers,Suppliers,Transactions}/*` — 15
  form-request classes.
- Resources: `CustomerResource`, `SupplierResource`, `SalesInvoiceResource`,
  `SalesInvoiceLineResource`, `PurchaseBillResource`,
  `PurchaseBillLineResource`, `CustomerReceiptResource`,
  `CustomerReceiptAllocationResource`, `SupplierPaymentResource`,
  `SupplierPaymentAllocationResource`.
- `routes/api.php` — 34 new routes.
- `config/authorization.php` — 30 new permissions, three extended role lists.
- `app/Providers/AppServiceProvider.php` — company-scoped route-model binding
  for `customer`, `supplier`, `invoice`, `bill`, `receipt`, `payment`.

### Factories added (11)

`CustomerFactory`, `SupplierFactory`, `SalesInvoiceFactory`,
`SalesInvoiceLineFactory`, `PurchaseBillFactory`, `PurchaseBillLineFactory`,
`CustomerReceiptFactory`, `CustomerReceiptAllocationFactory`,
`SupplierPaymentFactory`, `SupplierPaymentAllocationFactory`, and the
`accountsByCompany` cache on the shared `TestCase`.

### Tests added (8 files, 140 tests)

`CustomerTest` (18), `SalesInvoiceTest` (29), `PurchaseBillTest` (16),
`CustomerReceiptTest` (20), `SupplierPaymentTest` (18), `SupplierTest` (13),
`TransactionAuthorizationTest` (9 methods / 15 executed), and
`TransactionIntegrityTest` (11).

## 3. Migrations

`migrate:fresh`, `migrate:rollback` and `migrate` were all executed against the
testing database and completed without error (rollback removes all 10 Phase 5
tables, re-apply recreates them).

Two migration amendments were made during Phase 5:

- `sales_invoice_lines.description` and `purchase_bill_lines.description` were
  changed from `NOT NULL` to `nullable()`. The brief permits a line with no
  description; a non-null column silently rejected a valid request with a
  database error instead of a validation error.
- `document_number_sequences` was added in the same migration as
  `sales_invoices` rather than its own migration, because it cannot exist
  without the document table that uses it and splitting it would create a
  migration that references a later table.

## 4. Database Schema

### customers / suppliers

`UNIQUE (company_id, customer_code)` — two companies may both hold `C-0001`;
one company may not hold two. `name` is **deliberately not unique**: the brief
allows duplicate names and a unique index would invent a business rule.
`receivable_account_id` / `payable_account_id` are `NOT NULL` with
`restrictOnDelete`, so an account in use cannot be deleted out from under a
master record. `is_active` is absent from the model's fillable and is only ever
moved by the service layer.

### document_number_sequences

`UNIQUE (company_id, document_type)` with a `last_number` counter. One row per
company per document type; the service increments it inside the creating
transaction with `lockForUpdate()` so two concurrent documents cannot receive
the same number.

### sales_invoices / purchase_bills

`UNIQUE (company_id, invoice_number)` and `UNIQUE (company_id, bill_number)`.
Totals are `DECIMAL(20,4)`: `subtotal`, `discount_total`, `tax_total`,
`grand_total`. These are stored because they are the document's own contractual
figures and are recomputed inside the same transaction that writes the lines.

`paid_total` and `balance_due` are **not columns**. They are a pure function of
the allocation rows and are derived by `SettlementService`. Storing them would
create a second copy of a financial figure that could disagree with the payments
it claims to summarise — the duplicated ledger the Phase 4 report refused to
build. `status` is `DRAFT | POSTED | PARTIALLY_PAID | PAID`; the `PARTIALLY_PAID`
and `PAID` values are derived from allocations and written only as a
denormalised status for querying, never as a money column.

`tax_account_id` is `NULL` when `tax_total` is zero and **required** when
`tax_total` is non-zero. `journal_id` is `NULL` until posting and
`ON DELETE SET NULL`. `posted_by` / `posted_at` are the audit record, taken
from the authenticated user, never the payload. Indexes:
`(company_id, invoice_date)`, `(company_id, status)`,
`(company_id, customer_id, status)`, `journal_id`.

### sales_invoice_lines / purchase_bill_lines

`UNIQUE (invoice_id, line_number)` and `UNIQUE (bill_id, line_number)`. All
amounts are `DECIMAL(20,4)`. `revenue_account_id` / `expense_account_id` are
`NOT NULL` with `restrictOnDelete`; `tax_account_id` is per-line nullable
because tax can be charged at document level instead. `line_total` is stored
alongside the inputs — it is a derived figure the API returns, recomputed on
every write inside the same transaction as the inputs.

### customer_receipts / supplier_payments

`UNIQUE (company_id, receipt_number)` / `UNIQUE (company_id, payment_number)`.
`amount` is `DECIMAL(20,4) NOT NULL` — the cash figure. `customer_id` /
`supplier_id` use `restrictOnDelete`. `payment_account_id` is `NOT NULL` with
`restrictOnDelete`; the database, not only the service, refuses a receipt with
no cash account.

### customer_receipt_allocations / supplier_payment_allocations

`UNIQUE (customer_receipt_id, sales_invoice_id)` and
`UNIQUE (supplier_payment_id, purchase_bill_id)`, named
`receipt_invoice_alloc_unique` and `payment_bill_alloc_unique`. This is what
makes a double allocation a database error rather than a double credit. There is
**no `company_id`** on these tables by design: the payment is the only
company-scoped row they hang off, and a redundant copy could disagree with it.
Cross-company isolation for allocations is therefore a *service* responsibility
and is tested at both the HTTP and the raw-model level.

`ON DELETE CASCADE` from the payment: deleting a draft payment removes its
allocations. `ON DELETE RESTRICT` from the invoice/bill: an invoice that has
been allocated against cannot be deleted out from under a payment.

## 5. Models

All ten models:

- Declare `protected $guarded = []` with an explicit `$fillable` allow-list, so
  `company_id`, `is_active`, `status`, `paid_total`-style columns and
  `journal_id` are never mass-assignable from a request.
- Carry a `CompanyScope` global scope (the Phase 3 tenancy mechanism) so an
  unscoped query cannot read another tenant's rows.
- Use `DECIMAL` casts, never `float`. Amounts are cast through `Money`.

`SalesInvoice` and `PurchaseBill` additionally expose derived
`paidTotalAmount()` and `balanceDueAmount()` accessors returning `Money`, which
delegate to `SettlementService`, plus a `scopeWithOutstandingBalance()` query
scope that joins the allocation tables rather than reading a stored column.

## 6. Services

### DocumentCalculator

`calculateLine()` returns the authoritative `line_subtotal`, `discount_amount`,
`tax_amount` and `line_total` for a line; `calculateDocument()` folds lines into
`subtotal`, `discount_total`, `tax_total` and `grand_total`; and
`assertTaxAccountPresent()` enforces the tax-account-when-tax rule. Every
arithmetic step uses `Money::times()` and `Money::percentageOf()`, both of which
operate on integer minor units. No float ever touches a financial value.

`calculateLine()` accepts a `priceField` parameter so the same calculator serves
invoices (which send `unit_price`) and bills (which send `unit_cost`) without
duplicating the arithmetic. The caller names the field explicitly rather than
the calculator sniffing the array, so a payload missing the price column fails
loudly instead of defaulting to a wrong field.

### DocumentNumberSequence

`nextFor(Company, DocumentNumberType)` returns the next formatted number
(`INV-000001`, `BILL-000001`, `RCPT-000001`, `PAY-000001`) using the company's
configured prefix, incrementing `document_number_sequences.last_number` under
`lockForUpdate()`. Number allocation happens inside the same transaction that
inserts the document, so a rolled-back document does not burn a number.

### TransactionAccountResolver

The single place that decides whether an account may be used for a role. Roles:

| Role | Required `AccountType` |
|---|---|
| `receivable` | `ASSET` |
| `payment` | `ASSET` |
| `input_tax` | `ASSET` |
| `payable` | `LIABILITY` |
| `output_tax` | `LIABILITY` |
| `revenue` | `REVENUE` |
| `expense` | `EXPENSE` |

Every resolution checks, in order: the account exists, it belongs to the active
company, it is active, and its type matches the role. Failures raise
`ValidationException` with a 422, keyed to the *client's* field name.

The resolver maintains a per-request account cache keyed by account id, so an
invoice with 40 lines resolving `revenue` does not issue 40 identical queries.
This cache is what fixed a duplicate-`accounts`-row bug during testing: a naive
`firstOrCreate` inside the resolver created a second row for the same fixed code.

`ROLE_FIELDS` maps internal role names to the field the client sent. The input
tax role is internally `input_tax_account_id` (a bill calls it input tax, an
invoice calls it output tax) but both are reported to the client as
`tax_account_id`, matching the request payload. `ROLE_ARTICLES` supplies the
correct article/plural per role so error messages read naturally.

### SettlementService

Reads allocations to produce `paid_total`, `balance_due`, outstanding figures
and customer/supplier control-account balances. `attachFiguresForInvoices()` and
`attachFiguresForBills()` batch these for list endpoints to avoid an N+1 query
per row. `statusForInvoice()` / `statusForBill()` derive `PARTIALLY_PAID` /
`PAID` from the comparison of allocated against grand total, never from a
stored money field.

### PaymentAllocationService

Shared by receipts and payments. `replaceInvoiceAllocations()` and
`replaceBillAllocations()` delete-then-insert within the caller's transaction,
loading the target invoices/bills under `lockForUpdate()` so two concurrent
allocations against the same invoice serialise. They validate that every
referenced document exists, belongs to the *same* company, belongs to the *same*
customer/supplier, is `POSTED`, and is not already fully allocated.

`assertFullyAllocated()` enforces the current rule that a payment's allocation
total must equal its `amount` exactly. No advance/on-account flow exists yet.

Error keys are indexed: a failure on the second allocation reports
`allocations.1.sales_invoice_id`, not a bare `sales_invoice_id`, so a client can
highlight the offending row.

`refreshInvoiceStatuses()` / `refreshBillStatuses()` accept `iterable` rather
than `array`, because every caller passes an Eloquent Collection. The `array`
hint was a real bug found by `CustomerReceiptTest`.

### Document lifecycle services

`SalesInvoiceService`, `PurchaseBillService`, `CustomerReceiptService` and
`SupplierPaymentService` share a shape: `create()`, `update()`, `delete()`,
`post()`. `update()` runs in a transaction that re-locks the document row, so
an edit cannot interleave with a payment. `delete()` refuses a posted document
with a 422. All four services assign `company_id` from `CompanyContext` via
`forceFill()` — the field is absent from `$fillable`, so a client cannot set it.

Partial updates use `array_key_exists()` rather than a truthiness check, so
sending `"notes": null` actually clears the note. The truthiness version silently
ignored the request.

### Posting services

`SalesInvoicePostingService`, `PurchaseBillPostingService`,
`CustomerReceiptPostingService` and `SupplierPaymentPostingService` each build a
`JournalService` draft and hand it to `JournalPostingService::post()`. None of
them touches `journals` or `journal_lines` directly, and none sets
`JournalStatus::POSTED` itself. This is the single-writer property Phase 4
established, and it is preserved.

## 7. API Endpoints

34 routes under `/api`. All are permission-gated and company-scoped.

### Customers

| Method | Path | Permission |
|---|---|---|
| GET | `/api/customers` | `customers.view` |
| POST | `/api/customers` | `customers.create` |
| GET | `/api/customers/{customer}` | `customers.view` |
| PUT | `/api/customers/{customer}` | `customers.update` |
| POST | `/api/customers/{customer}/deactivate` | `customers.deactivate` |

There is no `DELETE /api/customers/{id}`. A customer with invoices must not be
removable; deactivation is the only lifecycle exit, and `TransactionIntegrityTest`
asserts the hard-delete route does not exist (405).

### Suppliers

Identical shape at `/api/suppliers` with `suppliers.*` permissions, and the same
`POST /api/suppliers/{supplier}/deactivate` instead of a delete.

### Sales invoices

| Method | Path | Permission |
|---|---|---|
| GET | `/api/sales-invoices` | `sales.invoices.view` |
| POST | `/api/sales-invoices` | `sales.invoices.create` |
| GET | `/api/sales-invoices/{invoice}` | `sales.invoices.view` |
| PUT | `/api/sales-invoices/{invoice}` | `sales.invoices.update` |
| DELETE | `/api/sales-invoices/{invoice}` | `sales.invoices.delete` |
| POST | `/api/sales-invoices/{invoice}/post` | `sales.invoices.post` |

### Purchase bills

`/api/purchase-bills` with `purchases.bills.*`, including
`POST /api/purchase-bills/{bill}/post`.

### Customer receipts

`/api/customer-receipts` with `customer.receipts.*`, including
`POST /api/customer-receipts/{receipt}/post`. The `allocations` array is part of
the create/update payload, so a receipt and its allocations are one atomic
write.

### Supplier payments

`/api/supplier-payments` with `supplier.payments.*`, including
`POST /api/supplier-payments/{payment}/post`.

Route-model binding is company-scoped: `{invoice}` resolves
`SalesInvoice::forCurrentCompany()->findOrFail($id)`, so a valid id belonging to
another tenant produces a 404, never a 200 with foreign data.

## 8. Authorization Matrix

30 new permissions, all defined in `config/authorization.php` and synchronised
into the database by `RolePermissionSynchroniser`. No second authorization
system was introduced — the Phase 2 permission architecture is reused.

| Permission group | Admin | Accountant | Manager | Staff |
|---|---|---|---|---|
| `customers.*` (4) | all | all | `view` | — |
| `suppliers.*` (4) | all | all | `view` | — |
| `sales.invoices.*` (5) | all | all | `view` | — |
| `purchases.bills.*` (5) | all | all | `view` | — |
| `customer.receipts.*` (5) | all | all | `view` | — |
| `supplier.payments.*` (5) | all | all | `view` | — |

Rationale: posting a document writes to the ledger, which is an accounting
decision, so it is restricted to Admin and Accountant. A Manager reads documents
and the ledger but cannot change a financial record. Staff has no transactional
access at all.

Enforcement is three-layered: permission middleware on the route, a policy method
per action, and a company-membership check in `CompanyContext`. A user who is
not a member of the active company receives 403 regardless of permission.

`TransactionAuthorizationTest` walks every endpoint for all four roles and
asserts the exact expected status code, so the table above is enforced by test
rather than by documentation.

## 9. Accounting Rules

**Money.** Every amount is `DECIMAL(20,4)`. In PHP every amount is a `Money`
value object holding an integer count of minor units (4-decimal fixed point).
`Money::of()` rejects a value with more than 4 decimal places; the tolerant
`Money::ofTolerant()` is used only for parsing user input, which is then
rounded half-up once, explicitly, at the boundary.

**Line arithmetic** (identical for invoice lines and bill lines):

```
line_subtotal = quantity * unit_price
discount_amount = line_subtotal * discount / 100      (exact, per line)
line_total     = line_subtotal - discount_amount
tax_amount     = (line_total) * tax_rate / 100         (exact, per line)
```

Tax is computed on the *discounted* line total, not the gross, which is the
correct treatment and is asserted by a test using a fractional rate
(10% of `0.0999` = `0.0099`).

**Document totals:**

```
subtotal       = sum(line_subtotal)
discount_total = sum(discount_amount)
tax_total      = sum(tax_amount)
grand_total    = subtotal - discount_total + tax_total
```

Rounding happens once, per line, at four decimals. Document totals are sums of
already-rounded line figures, so the document total is always exactly the sum of
what the client sees on its lines — no reconciliation gap.

**Settlement.**

```
paid_total   = sum(posted allocation amounts)
balance_due  = grand_total - paid_total
```

Both derived. `status` becomes `PARTIALLY_PAID` when
`0 < paid_total < grand_total` and `PAID` when `paid_total >= grand_total`. An
allocation may not exceed the invoice's outstanding balance.

**Tax accounts.** Required whenever `tax_total > 0`, optional otherwise. Output
tax must be a `LIABILITY`; input tax must be an `ASSET` (it is recoverable, and
booking it as a liability would overstate what the company owes the tax
authority).

**Posting rules.** A document must be `DRAFT` to post. Posting requires at least
one line, a resolved receivable/payable account, and a tax account when tax is
present. A posted document is immutable: no update, no delete, no re-post. The
accounting period containing the document date must be open, checked through
`AccountingPeriodService` exactly as Phase 4 does for journals.

**Required entries:**

| Transaction | Debit | Credit |
|---|---|---|
| Invoice | Customer receivable | Revenue + output tax |
| Bill | Expense + input tax | Supplier payable |
| Receipt | Payment (cash/bank) account | Customer receivable |
| Payment | Supplier payable | Payment (cash/bank) account |

A receipt or payment posts **one aggregate** credit/debit line against the
customer/supplier control account, not one line per allocation. Twenty
allocations still produce a two-line journal. This is the correct entry — the
receipt is one cash movement — and the per-invoice detail lives in the
allocation table. `CustomerReceiptTest` and `SupplierPaymentTest` both assert
the two-line shape explicitly.

**Debits must equal credits.** Every posting service asserts this through
`AccountingRules::netMovement()` before handing the journal to
`JournalPostingService`, and Phase 4's posting engine re-asserts it. A
transaction that would not balance is never written.

## 10. Transaction → Journal Mappings

| Model | Journal source | Lines |
|---|---|---|
| `SalesInvoice` | `JournalSource::SALES_INVOICE` | 1 debit (receivable, grand_total) / 1 credit (revenue, subtotal − discount_total) + 1 credit (output tax, tax_total) when tax > 0 |
| `PurchaseBill` | `JournalSource::PURCHASE_BILL` | 1 debit (expense, subtotal − discount_total) + 1 debit (input tax, tax_total) when tax > 0 / 1 credit (payable, grand_total) |
| `CustomerReceipt` | `JournalSource::CUSTOMER_RECEIPT` | 1 debit (payment account, amount) / 1 credit (customer receivable, amount) |
| `SupplierPayment` | `JournalSource::SUPPLIER_PAYMENT` | 1 debit (supplier payable, amount) / 1 credit (payment account, amount) |

The customer's `receivable_account_id` (not a global default) is the credit side
of a receipt, so two customers on different clearing accounts produce different
receipt journals. `journal_id` is set on the document in the same transaction
that posts the journal, and `posted_by` / `posted_at` are recorded from the
authenticated user.

## 11. Payment Allocation Design

An allocation is a `(payment, document, amount)` triple. It is the only record
that money moved against a specific document, and every settlement figure is
read from it.

**Why no `paid_total` column.** A stored total is a cache of an aggregate. The
moment an allocation is written outside the document row's transaction, the
cache and the allocations disagree, and the discrepancy is a financial error
that is invisible to every other check. Deriving the figure makes disagreement
impossible by construction. The cost is a join on read, which
`SettlementService` batches to avoid N+1.

**Why no `company_id` on allocation tables.** The payment row is the company
anchor. A second `company_id` would be a denormalised copy that could itself
disagree with the payment. Isolation is enforced in `PaymentAllocationService`,
which loads the target documents through a company-scoped query before writing
and rejects anything belonging elsewhere with a 422. `TransactionIntegrityTest`
covers this twice: once through the HTTP endpoint, once at the raw-model level
with a factory-built foreign document.

**Atomicity.** A receipt's allocations arrive in the same request as its header.
`CustomerReceiptService::create()` writes the receipt, writes the allocations,
and commits — or writes neither. There is no window in which a receipt exists
with no allocations and no way to make that state except a crash mid-transaction,
which rolls back.

**Locking.** `replaceInvoiceAllocations()` loads the target invoices with
`lockForUpdate()` inside the caller's transaction. Two concurrent receipts
against the same invoice serialise; the second one sees the first one's
allocation and is rejected for exceeding the outstanding balance. The same
pattern guards `update()` on all four document types.

**Status propagation.** After an allocation is written, the affected documents'
statuses are recomputed from the allocation set — never incremented. A
`refreshInvoiceStatuses(iterable)` call handles the batch case where one payment
touches several invoices.

## 12. Company Isolation

- Every Phase 5 table carries `company_id` with an FK, except the allocation
  tables (see above) and `document_number_sequences` (which is keyed by company).
- `CompanyScope` global scope on all ten models: an unscoped `Customer::all()`
  cannot return another tenant's rows.
- Route-model binding is company-scoped, so a cross-tenant id in a URL yields 404.
- `CompanyContext` is the only source of `company_id`; it is `forceFill()`ed and
  is absent from every `$fillable`, so it cannot arrive from a payload.
- `TransactionAccountResolver` re-checks `company_id` on every account even
  though the FK already guarantees the row exists, because "exists" is not
  "belongs to this tenant".
- Document numbers are unique per `(company_id, type)`, so `INV-000001` can
  exist in two companies and only one.
- `TransactionAuthorizationTest` asserts that a non-member of the active company
  is refused even when they hold the permission globally.

## 13. Validation

15 form-request classes under `app/Http/Requests/{Customers,Suppliers,Transactions}`.

**Money fields** are validated with a shared rule from
`app/Http/Requests/Transactions/ValidatesTransactionAmounts.php`. Input is
`decimal:0,10` — ten decimal places, from `config('accounting.rounding.max_input_decimals')`,
well past the point where extra digits carry meaning — and is then converted once
at the boundary by `Money::ofTolerant()`, which rounds half-up exactly once.
`Money::of()` then rejects any value that still does not fit the four-decimal
scale, so precision is never silently dropped a second time. The one-sided rules
(`gt:0`, `min:0`) are applied to the *rounded* value, so an input of `0.00001`
rounds to `0.0000` and is then rejected as a zero-value line instead of becoming
a real entry.

**Cross-field rules** are enforced with `withValidator()` after validation
passes, because they need computed values:

- `grand_total` is never accepted from the client. It is recomputed from the
  lines on every write. The same applies to `subtotal`, `tax_total`,
  `line_total` and `amount` on receipts/payments.
- A receipt's `amount` must equal the sum of its `allocations[].amount`.
- Every allocation must name a document that exists, is posted, belongs to the
  same company, belongs to the same customer/supplier, and has sufficient
  outstanding balance.
- `tax_account_id` is required when `tax_total > 0` and forbidden as a
  liability/asset mismatch depending on document direction.
- `lines.*.discount` and `lines.*.tax_rate` are `min:0` at the request layer.
  A discount larger than the line gross is refused by `DocumentCalculator`,
  which reports it on `lines.N.discount`, because a negative revenue line is a
  sales return rather than a discount.

**Two validator bugs found and fixed during Phase 5:**

1. `StoreCustomerReceiptRequest` and `StoreSupplierPaymentRequest` guarded the
   cross-field sum with
   `if (! is_array($allocations) || ! is_array($allocations['amount']))`. The
   second operand indexes a *list*, and a list has no `'amount'` key — so
   `is_array(null)` was false and the sum check never ran for a valid payload.
   Corrected to test only `! is_array($allocations)`.
2. `UpdateCustomerReceiptRequest` and `UpdateSupplierPaymentRequest` built their
   nested `allocations.*.amount` rules as
   `Rule::decimal(0, 10)->decimal(...)` on an array-shaped value, which throws
   `BadMethodCallException` for `validateDecimal` on an array. Corrected by
   prefixing `['sometimes']` to the shared `positiveMoneyRule()` result so the
   rules apply to a scalar and the array wrapper is applied once at the
   `allocations` level.

## 14. Concurrency Protection

What is implemented:

- `DB::transaction()` wraps every create, update, delete and post in the four
  document services, and the two master-data services.
- `lockForUpdate()` on the document row at the start of `update()`, `delete()`
  and `post()`, so an edit cannot interleave with a payment or another edit.
- `lockForUpdate()` on target invoices/bills inside
  `PaymentAllocationService::replace*Allocations()`, so two concurrent payments
  against the same document serialise and the second is validated against the
  first's committed allocation.
- `lockForUpdate()` on `document_number_sequences` inside
  `DocumentNumberSequence::nextFor()`, so two concurrent documents cannot
  receive the same number.
- The database backstop: `UNIQUE (company_id, document_number)`,
  `UNIQUE (document, line_number)`, `UNIQUE (payment, document)` on allocations,
  and `UNIQUE (company_id, customer_code)`. Even if every application lock were
  removed, these make double-allocation, duplicate numbering and duplicate line
  numbers a `QueryException` rather than corrupt data.
- Allocation replacement is delete-then-insert inside the caller's transaction,
  so a failed reallocation leaves the original allocations intact.

What was **not** tested: the locking is exercised only by sequential tests. No
test spawns two concurrent requests, no multi-process or barrier-based race test
was written, and no deadlock or lock-wait behaviour was measured. The database
uniqueness constraints are tested directly (see `TransactionIntegrityTest`), but
the `lockForUpdate()` behaviour under genuine concurrency is **not** covered. This
is stated here rather than claimed as verified.

## 15. Test Coverage

140 new tests across 8 files.

| File | Tests | Assertions | Focus |
|---|---|---|---|
| `CustomerTest` | 18 | 61 | CRUD, code uniqueness, deactivation vs delete, receivable account validation, cross-company |
| `SalesInvoiceTest` | 29 | 168 | Line arithmetic, tax/discount rounding, status lifecycle, immutability after posting, journal shape, balance, cross-company, tenant-scoped binding |
| `PurchaseBillTest` | 16 | 94 | As above for bills, plus input-tax-vs-output-tax type rules |
| `CustomerReceiptTest` | 20 | 176 | Allocation exactness, over-allocation refusal, cross-document and cross-company refusal, aggregate journal, status propagation, partial-then-full settlement |
| `SupplierPaymentTest` | 18 | 152 | Mirror of the receipt suite on the payable side |
| `SupplierTest` | 13 | 46 | Mirror of the customer suite on the payable side |
| `TransactionAuthorizationTest` | 9 methods / 15 executed | 69 | Every endpoint × every role, post/deactivate restrictions, non-member refusal |
| `TransactionIntegrityTest` | 11 | 19 | Raw database constraints: unique numbers per company, same number legal in two companies, unique allocation, unique line number, cross-company journal reference, restricted deletes |

`TransactionIntegrityTest` writes directly through Eloquent and asserts a
`QueryException` via a new `assertDatabaseIntegrityViolation()` helper on
`TestCase`. It bypasses the HTTP layer on purpose: the point is to prove the
*database* refuses the write, not that the API does.

Deliberate gap: there is no test that a receipt may *not* be deleted while
allocations exist, nor one for a `QueryException` on deleting an account in use.
The FKs exist; they are not yet covered by an assertion.

## 16. Tests Run and Exact Result

```
php artisan test tests/Feature/Transactions
Tests:      140
Assertions: 785
Result:     passed (0 failures, 0 errors, 0 skipped)

php artisan test tests/Feature/Accounting
Tests:      109
Assertions: 482
Result:     passed

php artisan test
Tests:      443
Assertions: 1862
Result:     passed (0 failures, 0 errors, 0 skipped)
```

443 total = 140 Phase 5 + 109 Phase 4 accounting + 194 Phase 1–3 (auth,
company, API, unit). The pre-Phase-5 baseline of 303 tests still passes, so
there is no accounting regression.

Additional verification run:

```
php artisan migrate:fresh --env=testing --force   → OK
php artisan migrate:rollback --env=testing --force → OK
php artisan migrate --env=testing --force           → OK
./vendor/bin/pint --test                           → passed
php artisan route:list --path=api                   → 34 transaction routes present
```

`./vendor/bin/pint` was run once in fix mode over the Phase 5 files and
`--test` afterwards is clean. Pint was not run in fix mode over any Phase 1–4
file.

## 17. Issues Discovered

Found by the Phase 5 tests, not by inspection.

1. **Line `description` was `NOT NULL`.** A valid request omitting `description`
   produced a raw `QueryException` (500) instead of a 422. Fixed by making the
   column nullable in both line migrations.
2. **Input-tax account errors keyed to the wrong field.** A bill posting with a
   `LIABILITY` account as input tax returned the error on
   `input_tax_account_id`, a field the client never sent. The client sends
   `tax_account_id` for both directions. Fixed with `ROLE_FIELDS` aliasing in
   `TransactionAccountResolver`.
3. **Receipt/payment sum check never ran.** The `is_array($allocations['amount'])`
   condition in two store requests was always false, so a receipt whose
   allocations did not sum to `amount` was accepted. Fixed.
4. **Nested update rules threw `BadMethodCallException`.** A decimal rule was
   applied to an array-shaped `allocations` value. Fixed by prefixing
   `['sometimes']`.
5. **Explicit `null` in a partial update was ignored.** Four services used a
   truthiness check, so `"notes": null` was silently dropped. Fixed with
   `array_key_exists()`.
6. **Non-existent eager-load relation names.** `CustomerReceiptController` and
   `SupplierPaymentController` eager-loaded `allocations.invoice` /
   `allocations.bill`; the actual relations are `salesInvoice` and
   `purchaseBill`, so the API returned allocations with null documents. Fixed on
   both the controller and resource sides.
7. **`refreshInvoiceStatuses(array $invoices)` type error.** Callers pass
   Eloquent Collections, producing a `TypeError`. Fixed to `iterable`.
8. **Allocation validation errors were not indexed.** A failure on the second
   allocation reported a bare `sales_invoice_id` key, which a client cannot map
   to a row. Fixed by re-keying to `allocations.N.sales_invoice_id`.
9. **The account resolver created duplicate account rows.** An internal
   `firstOrCreate` on a fixed code collided with `UNIQUE (company_id, code)`,
   turning a legitimate request into a 500. Fixed with a per-request cache; the
   resolver now only ever reads.
10. **Test factories duplicated account codes.** `CustomerFactory` creates
    account `1100` and `makeTransactionAccounts()` creates the same code for the
    same company, so any test that used both hit a duplicate-key error. Two
    tests failed for this reason. Fixed in the tests by creating the account set
    first and passing `receivable_account_id` explicitly.
11. **A generated supplier-numbering test asserted the wrong thing.** The
    numbering is per-company and sequential, which a test cannot assert without
    reaching into sequence internals. Replaced with a hard-delete-returns-405
    test, which tests a real requirement.

## 18. Issues Fixed

All 11 above are fixed and covered by a passing test. Items 1, 2, 3, 4, 5, 6, 7
and 8 are production-code fixes; 9 is a production-code fix; 10 and 11 are
test-code corrections.

Pint formatting was applied to the Phase 5 files (line endings, import order,
unused imports, `fully_qualified_strict_types`, brace position, unary operator
spacing). The suite was re-run after formatting and passed.

## 19. Known Limitations

- **Concurrency is not tested.** See §14. Locks are implemented; race behaviour
  is not verified by any test.
- **No unallocated advance / on-account payments.** A receipt must allocate its
  full amount. An overpayment is impossible and an advance is impossible. This
  is the brief's current rule, but real AR workflows need it.
- **No credit notes or returns.** An invoice with a posted receipt cannot be
  credited; the only correction is a new document.
- **Single currency.** Every amount is company-company currency; no FX
  translation or rate table exists.
- **`tax_rate` has no upper bound.** The request rule is `min:0`, so
  `tax_rate: 1000` is accepted and produces a mathematically consistent
  document with a ten-times tax figure. There is no test for a rate above 100.
  Adding `max:100` is a one-line change but is not made here because some
  jurisdictions do use rates expressed differently and the correct bound is a
  business decision, not an arithmetic one.
- **Line-level tax accounts cannot be used together with a document-level tax
  account** in a single document without the calculator summing both. The
  arithmetic supports it, but there is no test for a mixed document.
- **Two `Tax`-direction tests rely on type rules only.** Whether a given account
  is the *right* liability is an accounting judgement the application cannot
  make; it only enforces `LIABILITY` vs `ASSET`.
- **Allocation FK coverage is incomplete.** `restrictOnDelete` from invoice/bill
  to allocations exists but is not asserted by a test.

## 20. Outstanding Work

- Concurrency tests (two-process or barrier-based) for allocation, numbering and
  document update.
- Foreign-key tests for "delete an invoice that has an allocation" and "delete
  an account in use by a customer/supplier".
- Advance/on-account payments, if the brief is extended.
- Credit notes, purchase returns and FX.
- A `DRAFT → VOID` transition for a document that must be abandoned without
  deletion. Currently a draft is simply deleted.

## 21. Requirements Traceability

| Requirement | Implementation | Test |
|---|---|---|
| Company-scoped customers and suppliers | `CustomerService`, `SupplierService`, `CompanyScope` | `CustomerTest`, `SupplierTest` |
| Explicit control account, validated per write | `receivable_account_id` / `payable_account_id` + `TransactionAccountResolver` | `CustomerTest`, `SupplierTest` |
| No hard delete for masters | `deactivate` endpoint only, no DELETE route | `SupplierTest` (405 assertion) |
| Per-company sequential document numbers | `DocumentNumberSequence`, `document_number_sequences` | `SalesInvoiceTest`, `PurchaseBillTest` |
| Server-computed totals only | `DocumentCalculator`, client totals never trusted | `SalesInvoiceTest`, `PurchaseBillTest` |
| Exact 4-decimal arithmetic, no floats | `Money::times()`, `Money::percentageOf()`, `DECIMAL(20,4)` | `SalesInvoiceTest` (fractional tax case) |
| Discount before tax | `DocumentCalculator::calculateLine()` | `SalesInvoiceTest` |
| Tax account required when tax present | `DocumentCalculator::assertTaxAccountPresent()` | `SalesInvoiceTest`, `PurchaseBillTest` |
| `DRAFT → POSTED` only via journal path | `JournalService` + `JournalPostingService` in all four posting services | `SalesInvoiceTest`, `PurchaseBillTest`, `CustomerReceiptTest`, `SupplierPaymentTest` |
| Debits equal credits | `AccountingRules::netMovement()` + Phase 4 posting engine | Phase 4 `PostingTest` + all four suites |
| Posted documents immutable | `update()` / `delete()` refuse a posted document | `SalesInvoiceTest`, `PurchaseBillTest` |
| Draft deletable | `delete()` allowed while `DRAFT` | `SalesInvoiceTest`, `PurchaseBillTest` |
| `paid_total` / `balance_due` derived from allocations | `SettlementService`, no columns | `CustomerReceiptTest`, `SupplierPaymentTest` |
| Allocations must sum to payment amount | `PaymentAllocationService::assertFullyAllocated()` | `CustomerReceiptTest`, `SupplierPaymentTest` |
| Over-allocation refused | `PaymentAllocationService` outstanding check | `CustomerReceiptTest`, `SupplierPaymentTest` |
| No duplicate allocation | `UNIQUE (payment, document)` + service check | `TransactionIntegrityTest`, both suites |
| Cross-company document refused | `TransactionAccountResolver` + `PaymentAllocationService` + scoped binding | `TransactionIntegrityTest`, all four suites |
| Aggregate receipt/payment entry | `CustomerReceiptPostingService`, `SupplierPaymentPostingService` | `CustomerReceiptTest`, `SupplierPaymentTest` |
| Customer/supplier control account used on payment | `SalesInvoice`/`PurchaseBill` relations on allocations | `CustomerReceiptTest`, `SupplierPaymentTest` |
| Posting restricted to Admin/Accountant | `config/authorization.php` + six policies | `TransactionAuthorizationTest` |
| Manager read-only on documents | Same | `TransactionAuthorizationTest` |
| Staff no transactional access | Same | `TransactionAuthorizationTest` |
| Non-member refused | `CompanyContext` membership check | `TransactionAuthorizationTest` |
| No second ledger | All posting via `JournalPostingService` | Code review + all four suites |
| No client-supplied `company_id` / `journal_id` | Absent from every `$fillable`; `forceFill()` from context | `SalesInvoiceTest`, `PurchaseBillTest` |
| Amounts are not floats | `DECIMAL(20,4)` + `Money` casts | Whole suite |
| Concurrency protection implemented | `lockForUpdate()` in 3 services | **Not tested** — see §14 |
