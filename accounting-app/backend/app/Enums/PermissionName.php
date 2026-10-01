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

    case PeriodsView = 'accounting.periods.view';
    case PeriodsCreate = 'accounting.periods.create';
    case PeriodsUpdate = 'accounting.periods.update';
    case PeriodsClose = 'accounting.periods.close';

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

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
