<?php

namespace App\Enums;

enum PermissionName: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';

    case RolesView = 'roles.view';

    /*
    | Company layer (Phase 3). Company access is a membership question and is
    | enforced separately by CompanyPolicy; these permissions only decide what
    | a member is allowed to *do* once inside the company.
    */
    case CompaniesView = 'companies.view';
    case CompaniesCreate = 'companies.create';
    case CompaniesUpdate = 'companies.update';
    case CompaniesDelete = 'companies.delete';
    case CompanySettingsView = 'companies.settings.view';
    case CompanySettingsUpdate = 'companies.settings.update';

    /*
    | Accounting layer (Phase 4).
    */
    case AccountsView = 'accounts.view';
    case AccountsCreate = 'accounts.create';
    case AccountsUpdate = 'accounts.update';
    case AccountsActivate = 'accounts.activate';
    case AccountsDeactivate = 'accounts.deactivate';
    case AccountsDelete = 'accounts.delete';

    case JournalsView = 'journals.view';
    case JournalsCreate = 'journals.create';
    case JournalsUpdate = 'journals.update';
    case JournalsDelete = 'journals.delete';
    case JournalsPost = 'journals.post';

    /*
    | Accounting periods and financial years (Phase 8 extended this block).
    |
    | Phase 8 considered adding a parallel accounting.financial_years.* set and
    | decided against it. A financial year is the parent grouping of periods: it
    | is viewed by the same people who view periods, its dates are maintained by
    | the same people who maintain period dates, and closing one is the same
    | accounting-control act as closing a period. A second namespace would grant
    | and withhold the same four capabilities twice, and the two sets would drift
    | - the failure mode Phase 6 avoided by collapsing eleven report permissions
    | into one. See PHASE_8_REPORT.md for the full reasoning.
    |
    | `reopen` is the one genuinely new capability: Phase 4 had no reopen at all,
    | and closing had been one-way. It is separate from `close` deliberately, so a
    | role that may close a period need not be able to undo one. Undoing an
    | accounting-control decision is a larger grant than making it.
    */
    case PeriodsView = 'accounting.periods.view';
    case PeriodsCreate = 'accounting.periods.create';
    case PeriodsUpdate = 'accounting.periods.update';
    case PeriodsClose = 'accounting.periods.close';
    case PeriodsReopen = 'accounting.periods.reopen';

    case LedgerView = 'accounting.ledger.view';

    // Phase 5 - Customers & Suppliers
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDeactivate = 'customers.deactivate';

    case SuppliersView = 'suppliers.view';
    case SuppliersCreate = 'suppliers.create';
    case SuppliersUpdate = 'suppliers.update';
    case SuppliersDeactivate = 'suppliers.deactivate';

    // Phase 5 - Sales
    case SalesInvoicesView = 'sales.invoices.view';
    case SalesInvoicesCreate = 'sales.invoices.create';
    case SalesInvoicesUpdate = 'sales.invoices.update';
    case SalesInvoicesPost = 'sales.invoices.post';
    case SalesInvoicesDelete = 'sales.invoices.delete';

    // Phase 5 - Purchases
    case PurchasesBillsView = 'purchases.bills.view';
    case PurchasesBillsCreate = 'purchases.bills.create';
    case PurchasesBillsUpdate = 'purchases.bills.update';
    case PurchasesBillsPost = 'purchases.bills.post';
    case PurchasesBillsDelete = 'purchases.bills.delete';

    /*
    | Phase 5 - Receipts & Payments.
    |
    | The spec's suggested matrix lists view/create/post only for these two. An
    | `update` grant is added because a draft receipt is editable in this
    | implementation, and a draft with a typo in its amount or allocation set
    | would otherwise be unfixable: the only alternative would be to delete and
    | recreate it, which burns a document number that must never be reused. An
    | invoice edits its draft, so a payment being unable to is the inconsistent
    | choice. Documented as an intentional difference in the Phase 5 report.
    */
    case CustomerReceiptsView = 'customer.receipts.view';
    case CustomerReceiptsCreate = 'customer.receipts.create';
    case CustomerReceiptsUpdate = 'customer.receipts.update';
    case CustomerReceiptsPost = 'customer.receipts.post';
    case CustomerReceiptsDelete = 'customer.receipts.delete';

    case SupplierPaymentsView = 'supplier.payments.view';
    case SupplierPaymentsCreate = 'supplier.payments.create';
    case SupplierPaymentsUpdate = 'supplier.payments.update';
    case SupplierPaymentsPost = 'supplier.payments.post';
    case SupplierPaymentsDelete = 'supplier.payments.delete';

    /*
    | Phase 6 - Reports.
    |
    | A single permission rather than the eleven the brief sketches. Every report
    | endpoint is a read over the same accounting truth and every one of them is
    | granted or withheld to the same set of roles, so eleven permission rows
    | would only add eleven ways for the role matrix to drift out of step. The
    | brief explicitly allows collapsing them ("Avoid unnecessary permissions if
    | the existing architecture supports a simpler reporting permission"), and
    | the phase 4 LedgerView precedent - one permission for every ledger read - is
    | exactly that simpler shape.
    */
    case ReportsView = 'accounting.reports.view';

    /*
    | Phase 7 - Cash & Banking.
    |
    | Three permissions, matching the brief's recommended minimum and the shape
    | the Phase 5 document types use. `update` and `delete` are added for the
    | same reason CustomerReceiptsUpdate/Delete exist: a draft cash/bank
    | transaction is editable, and without that it would be unfixable except by
    | delete-and-recreate, which burns a document number that must never be
    | reused.
    |
    | `post` is separate from `create` deliberately. Recording a draft moves no
    | money and is reversible; posting it writes to the permanent accounting
    | record. Those are different acts and a role that may prepare a movement
    | need not be able to commit it.
    |
    | This is NOT reporting access. `accounting.reports.view` grants read-only
    | visibility of posted entries and must not be used to mutate them; the brief
    | forbids that and the separation is what makes the two grants revocable
    | independently.
    */
    case CashBankView = 'accounting.cash_bank.view';
    case CashBankCreate = 'accounting.cash_bank.create';
    case CashBankUpdate = 'accounting.cash_bank.update';
    case CashBankPost = 'accounting.cash_bank.post';
    case CashBankDelete = 'accounting.cash_bank.delete';

    /*
    | Phase 9 - Bank Reconciliation.
    |
    | These match the brief's recommended permission set. `complete` is a
    | separate grant from `update` because asserting that the statement and the
    | ledger agree is an accounting control decision, and reopening that decision
    | is separated again so a role that may complete does not automatically
    | gain the ability to undo a completion.
    */
    case BankReconciliationView = 'accounting.bank_reconciliation.view';
    case BankReconciliationCreate = 'accounting.bank_reconciliation.create';
    case BankReconciliationUpdate = 'accounting.bank_reconciliation.update';
    case BankReconciliationComplete = 'accounting.bank_reconciliation.complete';
    case BankReconciliationReopen = 'accounting.bank_reconciliation.reopen';

    /*
    | Phase 10 - Tax Engine.
    |
    | Six capabilities matching the brief's recommended set, and the split between
    | them is the point rather than the names:
    |
    |   view / create / update / delete
    |       Configuration. `delete` is separate from `update` because deletion is
    |       the one irreversible act here - deactivation is the reversible
    |       alternative, so a role that can retire a tax need not be able to
    |       destroy one.
    |
    |   calculate
    |       POST /tax/calculate. Read-only and writes nothing, but it is not
    |       `view`: quoting a tax to a customer exercises the engine, and a role
    |       that may read a tax's configuration need not be allowed to compute with
    |       it. Separately granted so an operator can be given one without the
    |       other.
    |
    |   report.view
    |       Deliberately NOT folded into `view`. Phase 6 established that report
    |       access is its own capability, and tax reports aggregate posted tax
    |       across the whole company rather than one configured tax. A role that
    |       may open a single tax record should not thereby see every tax
    |       position the company has.
    */
    case TaxView = 'accounting.tax.view';
    case TaxCreate = 'accounting.tax.create';
    case TaxUpdate = 'accounting.tax.update';
    case TaxDelete = 'accounting.tax.delete';
    case TaxCalculate = 'accounting.tax.calculate';
    case TaxReportView = 'accounting.tax.report.view';

    /*
    | Phase 11 - Credit & Debit Notes.
    |
    | The same five-capability shape Phase 5 gave sales invoices and purchase
    | bills, and deliberately so: a note is a financial document in exactly the
    | same sense, with the same lifecycle and the same "posted means permanent"
    | property. Reusing a different shape here would mean a role that can post an
    | invoice cannot post a credit note against it, which is not a distinction
    | anybody makes in practice.
    |
    |   view    read notes, and read the adjustable-line figures that tell a user
    |           what may still be adjusted
    |   create  raise a new draft note
    |   update  edit a draft note
    |   delete  discard a draft note
    |   post    make the adjustment part of the accounting record
    |
    | `post` is separate from `update` for the same reason it is on every other
    | document: preparing an adjustment and committing it are different acts, and
    | the second one is irreversible.
    |
    | These are NOT riders on sales.invoices.* or purchases.bills.*. A note is a
    | distinct document with a distinct number, a distinct lifecycle and its own
    | audit trail, and a role granted invoice rights should have to be granted
    | note rights deliberately - the grant is the decision.
    */
    case CreditDebitNotesView = 'accounting.credit_debit_note.view';
    case CreditDebitNotesCreate = 'accounting.credit_debit_note.create';
    case CreditDebitNotesUpdate = 'accounting.credit_debit_note.update';
    case CreditDebitNotesDelete = 'accounting.credit_debit_note.delete';
    case CreditDebitNotesPost = 'accounting.credit_debit_note.post';

    /*
    | Phase 12 - Fixed Assets.
    |
    | Seven capabilities. The first four are the ordinary document shape Phase 5
    | gave invoices and bills, and the last three are the three acts a fixed asset
    | has that an invoice does not. They are separate grants because they are
    | separately consequential acts, not because they are three names for one:
    |
    |   view         read the asset, its depreciation schedule and the register
    |   create       record a draft asset. No accounting effect.
    |   update       edit a draft asset. The service refuses a capitalised asset.
    |   delete       discard a draft asset. Not a grant to remove a capitalised
    |                one - the service refuses those outright and there is no
    |                cancellation mechanism, because the application has none.
    |   capitalize   put the asset's cost into the ledger and start its life
    |   depreciate   charge one period's depreciation to the ledger
    |   dispose      remove the asset's cost from the ledger and book gain or loss
    |
    | `capitalize` is separate from `create` for the same reason `post` is on every
    | other document: creating a draft moves nothing, and a draft asset has no
    | carrying value. A role that may register that the company owns a van need not
    | be able to write 30,000 into the asset account.
    |
    | `capitalize` and `depreciate` are separate from each other because they are
    | annual-ish and monthly-recurring acts respectively, and the second happens
    | roughly a hundred and twenty more times per asset. A role trusted to do the
    | once is not thereby trusted with the hundred and twenty.
    |
    | `dispose` is separate again because it is the only act that *removes* cost
    | from the ledger and books a gain or a loss against the result - the one
    | operation in this module whose entry can move profit.
    |
    | There is deliberately NO report permission. Phase 6 collapsed eleven report
    | permissions into one because every report endpoint is a read over the same
    | accounting truth; Phase 10 added one back only because tax reports aggregate
    | tax across the whole company rather than one configured tax. The asset
    | register and the depreciation report are reads over this module's own
    | records joined to the same posted ledger, and `view` already covers reading
    | an asset and its schedule, which is the same information in a different
    | arrangement. Splitting it would add a second grant that has to be remembered
    | alongside the first for no additional capability.
    */
    case FixedAssetsView = 'accounting.fixed_asset.view';
    case FixedAssetsCreate = 'accounting.fixed_asset.create';
    case FixedAssetsUpdate = 'accounting.fixed_asset.update';
    case FixedAssetsDelete = 'accounting.fixed_asset.delete';
    case FixedAssetsCapitalize = 'accounting.fixed_asset.capitalize';
    case FixedAssetsDepreciate = 'accounting.fixed_asset.depreciate';
    case FixedAssetsDispose = 'accounting.fixed_asset.dispose';

    /*
    | Phase 14 - Multi-currency & Foreign Exchange.
    |
    | WHY CURRENCY IS SPLIT FROM EXCHANGE RATE
    |
    | A currency is GLOBAL reference data: creating one changes the vocabulary
    | every tenant may invoice in, so it is the most sensitive write in the
    | system and is deliberately withheld from the Accountant role. An exchange
    | rate is per-company and affects only that company's conversions, so the
    | Accountant who posts journals and settles foreign balances owns it.
    |
    | WHY `activate`/`deactivate` ARE THEIR OWN CURRENCY GRANTS
    |
    | Deactivation is the reversible alternative to deletion (a currency that a
    | posted document referenced must stay readable forever), and it removes the
    | currency from every tenant's pickers at once. That is a bigger act than
    | editing a name, so it is separated the same way accounts.activate is.
    |
    | WHY `accounting.fx.update`
    |
    | It configures the realised FX gain/loss account pair a company must set
    | before it can post a foreign settlement. It is an update to company
    | accounting configuration rather than company settings, so it does not ride
    | on `companies.settings.update`; the base currency, which DOES reinterpret
    | the whole ledger, still does.
    |
    | WHY `accounting.controls.view`
    |
    | The currency/FX integrity report is read-only and repairs nothing, but it
    | exposes a company's financial position and configuration gaps, so it is not
    | folded into the ordinary reporting permission.
    */
    case CurrencyView = 'accounting.currency.view';
    case CurrencyCreate = 'accounting.currency.create';
    case CurrencyUpdate = 'accounting.currency.update';
    case CurrencyActivate = 'accounting.currency.activate';
    case CurrencyDeactivate = 'accounting.currency.deactivate';

    case ExchangeRateView = 'accounting.exchange_rate.view';
    case ExchangeRateCreate = 'accounting.exchange_rate.create';
    case ExchangeRateUpdate = 'accounting.exchange_rate.update';

    case FxUpdate = 'accounting.fx.update';
    case ControlsView = 'accounting.controls.view';

    /*
    | Phase 16 - Budgeting & Budget Variance Analysis.
    |
    | Five capabilities, the same document shape Phase 5 gave invoices and bills
    | for everything but approval:
    |
    |   view     read budgets, their lines and the budget-vs-actual report
    |   create   raise a new draft budget or a revision
    |   update   edit a draft budget and its lines
    |   delete   discard a draft budget
    |   approve  finalize a draft, after which it is immutable
    |
    | `approve` is separate from `update` for the same reason `post` is separate
    | from `update` on every other document: preparing a plan and committing it
    | are different acts, and the second is the control the phase exists to add.
    | An approved budget cannot be edited at all - a change is a new version - so
    | the grant is the decision to accept a plan, not a right to alter one.
    |
    | These are deliberately NOT riders on `accounting.reports.view`: a budget is
    | a company's forward plan rather than a read of posted history, and a role
    | that may read the ledger need not thereby see what management intends to do
    | with it.
    */
    case BudgetsView = 'accounting.budgets.view';
    case BudgetsCreate = 'accounting.budgets.create';
    case BudgetsUpdate = 'accounting.budgets.update';
    case BudgetsDelete = 'accounting.budgets.delete';
    case BudgetsApprove = 'accounting.budgets.approve';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
