<?php

return [
    'guard' => 'api',

    'permissions' => [
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'roles.view',
        'companies.view',
        'companies.create',
        'companies.update',
        'companies.delete',
        'companies.settings.view',
        'companies.settings.update',

        'accounts.view',
        'accounts.create',
        'accounts.update',
        'accounts.activate',
        'accounts.deactivate',
        'accounts.delete',

        'journals.view',
        'journals.create',
        'journals.update',
        'journals.delete',
        'journals.post',

        'accounting.periods.view',
        'accounting.periods.create',
        'accounting.periods.update',
        'accounting.periods.close',
        'accounting.periods.reopen',

        'accounting.ledger.view',

        // Phase 5
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.deactivate',
        'suppliers.view',
        'suppliers.create',
        'suppliers.update',
        'suppliers.deactivate',
        'sales.invoices.view',
        'sales.invoices.create',
        'sales.invoices.update',
        'sales.invoices.post',
        'sales.invoices.delete',
        'purchases.bills.view',
        'purchases.bills.create',
        'purchases.bills.update',
        'purchases.bills.post',
        'purchases.bills.delete',
        'customer.receipts.view',
        'customer.receipts.create',
        'customer.receipts.update',
        'customer.receipts.post',
        'customer.receipts.delete',
        'supplier.payments.view',
        'supplier.payments.create',
        'supplier.payments.update',
        'supplier.payments.post',
        'supplier.payments.delete',

        'accounting.reports.view',

        // Phase 7
        'accounting.cash_bank.view',
        'accounting.cash_bank.create',
        'accounting.cash_bank.update',
        'accounting.cash_bank.post',
        'accounting.cash_bank.delete',

        // Phase 9
        'accounting.bank_reconciliation.view',
        'accounting.bank_reconciliation.create',
        'accounting.bank_reconciliation.update',
        'accounting.bank_reconciliation.complete',
        'accounting.bank_reconciliation.reopen',

        // Phase 10
        'accounting.tax.view',
        'accounting.tax.create',
        'accounting.tax.update',
        'accounting.tax.delete',
        'accounting.tax.calculate',
        'accounting.tax.report.view',
    ],

    'roles' => [
        'Admin' => ['*'],

        'Accountant' => [
            'users.view',
            'companies.view',
            'accounts.view',
            'accounts.create',
            'accounts.update',
            'accounts.deactivate',
            'accounts.activate',
            'journals.view',
            'journals.create',
            'journals.update',
            'journals.delete',
            'journals.post',
            'accounting.periods.view',
            'accounting.periods.create',
            'accounting.periods.update',
            'accounting.ledger.view',
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.deactivate',
            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'suppliers.deactivate',
            'sales.invoices.view',
            'sales.invoices.create',
            'sales.invoices.update',
            'sales.invoices.post',
            'sales.invoices.delete',
            'purchases.bills.view',
            'purchases.bills.create',
            'purchases.bills.update',
            'purchases.bills.post',
            'purchases.bills.delete',
            'customer.receipts.view',
            'customer.receipts.create',
            'customer.receipts.update',
            'customer.receipts.post',
            'customer.receipts.delete',
            'supplier.payments.view',
            'supplier.payments.create',
            'supplier.payments.update',
            'supplier.payments.post',
            'supplier.payments.delete',
            'accounting.reports.view',
            'accounting.cash_bank.view',
            'accounting.cash_bank.create',
            'accounting.cash_bank.update',
            'accounting.cash_bank.post',
            'accounting.cash_bank.delete',
            'accounting.bank_reconciliation.view',
            'accounting.bank_reconciliation.create',
            'accounting.bank_reconciliation.update',
            'accounting.bank_reconciliation.complete',
            'accounting.bank_reconciliation.reopen',
            'accounting.tax.view',
            'accounting.tax.create',
            'accounting.tax.update',
            'accounting.tax.delete',
            'accounting.tax.calculate',
            'accounting.tax.report.view',
        ],

        /*
        | Manager stays read-only, exactly as it is for every other accounting
        | module. It holds no .create/.post/.update permission anywhere in this
        | matrix, and Cash & Banking does not become the first exception.
        |
        | The Phase 7 brief's role table shows Manager with create and post
        | rights; that conflicts with how this project already defines the role,
        | and the brief itself defers to the existing model ("Role expectations
        | should follow the existing accounting role model"). The existing model
        | wins. Recorded as a deliberate deviation in PHASE_7_REPORT.md.
        |
        | Phase 10's brief asks for Manager to have "read/report access according to
        | existing project authorization conventions; configuration write access
        | should be explicitly justified". Those are the existing conventions, so
        | Manager gets tax view, calculate and report access and no configuration
        | write access. Justification for not giving it: changing a tax rate
        | changes what every future invoice will collect, which is a configuration
        | decision with the same weight as changing an account's type - and Manager
        | holds neither. It is a permission that can be granted deliberately later
        | without any code change.
        */
        'Manager' => [
            'users.view',
            'companies.view',
            'companies.update',
            'companies.settings.view',
            'companies.settings.update',
            'accounts.view',
            'journals.view',
            'accounting.periods.view',
            'accounting.ledger.view',
            'customers.view',
            'suppliers.view',
            'sales.invoices.view',
            'purchases.bills.view',
            'customer.receipts.view',
            'supplier.payments.view',
            'accounting.reports.view',
            'accounting.cash_bank.view',
            'accounting.bank_reconciliation.view',
            'accounting.bank_reconciliation.create',
            'accounting.bank_reconciliation.update',
            'accounting.bank_reconciliation.complete',
            'accounting.tax.view',
            'accounting.tax.calculate',
            'accounting.tax.report.view',
        ],

        /*
        | Staff gains nothing in Phase 10.
        |
        | `calculate` was considered for Staff on the basis that quoting a tax to a
        | customer is part of taking an order. It is not granted, because the
        | calculation endpoint accepts an amount and returns a figure computed from
        | company configuration, which means it discloses the company's effective
        | tax rate - and Staff already cannot read a tax record to learn that.
        | Granting the capability would hand out the same information through the
        | calculation path while denying it through the configuration path.
        */
        'Staff' => [
            'companies.view',
        ],
    ],
];
