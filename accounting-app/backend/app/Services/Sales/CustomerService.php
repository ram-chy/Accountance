<?php

namespace App\Services\Sales;

use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Accounting\TransactionAccountResolver;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Customer master data.
 *
 * Thin by design: a customer carries no accounting totals. Its receivable
 * balance is the AR account's ledger balance from posted journals, and its
 * outstanding invoices are a query, not a column. There is nothing here that
 * needs a transaction.
 *
 * The receivable account is validated on every write rather than only on
 * creation, because an account can be deactivated later and a customer left
 * pointing at a dormant control account is a customer whose next invoice would
 * fail at posting time - with an error the user cannot act on from where they
 * are.
 */
class CustomerService
{
    public function __construct(private readonly TransactionAccountResolver $accounts) {}

    /**
     * @throws ValidationException
     */
    public function create(Company $company, array $data): Customer
    {
        $this->accounts->receivable($company, $data['receivable_account_id']);

        $customer = new Customer([
            'customer_code' => $data['customer_code'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'tax_identifier' => $data['tax_identifier'] ?? null,
            'receivable_account_id' => $data['receivable_account_id'],
        ]);

        $customer->company_id = $company->getKey();
        $customer->forceFill(['is_active' => true]);

        try {
            $customer->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw ValidationException::withMessages([
                    'customer_code' => 'This customer code is already in use for the selected company.',
                ]);
            }

            throw $e;
        }

        return $customer;
    }

    /**
     * @throws ValidationException
     */
    public function update(Customer $customer, array $data): Customer
    {
        if (array_key_exists('receivable_account_id', $data)) {
            $this->accounts->receivable($customer->company, $data['receivable_account_id']);
        }

        /*
         * Only keys that were actually sent are written.
         *
         * The temptation here is array_filter(..., fn ($v) => $v !== null), which
         * reads as "ignore nulls" and is exactly backwards: it makes a client
         * unable to clear a field. Sending {"email": null} is a deliberate request
         * to remove the email, and dropping it would leave the old address on
         * file with a 200 telling the user it was saved. Absent is different from
         * present-and-null - absent means "leave this alone", which is what a
         * partial update means - so the test is array_key_exists.
         */
        $nullable = [
            'email',
            'phone',
            'address',
            'city',
            'state',
            'country_code',
            'tax_identifier',
        ];

        foreach (array_merge($nullable, ['name', 'customer_code', 'receivable_account_id']) as $field) {
            if (array_key_exists($field, $data)) {
                $customer->{$field} = $data[$field];
            }
        }

        try {
            $customer->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw ValidationException::withMessages([
                    'customer_code' => 'This customer code is already in use for the selected company.',
                ]);
            }

            throw $e;
        }

        return $customer->refresh();
    }

    /**
     * Deactivate a customer.
     *
     * Deactivation stops new invoices being raised against this customer but
     * leaves every historical invoice readable. Hard deletion is not offered:
     * a customer with invoice history must remain resolvable so old documents
     * can still name who they were for.
     *
     * Refused outright if the customer has any non-draft invoice. This is the one
     * master-data change that would otherwise strand an accounting fact: a posted
     * invoice means money is owed by this customer, and an inactive customer is
     * excluded from the receivables report, so the balance would become
     * unreportable against anyone. Drafts are excluded from the test because a
     * draft is not a fact and can still be reassigned or deleted.
     *
     * @throws ValidationException
     */
    public function deactivate(Customer $customer): Customer
    {
        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer' => "Customer [{$customer->customer_code}] is already inactive.",
            ]);
        }

        $hasPostedInvoices = $customer->invoices()
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->exists();

        if ($hasPostedInvoices) {
            throw ValidationException::withMessages([
                'customer' => 'This customer has posted invoices and cannot be deactivated. '
                    .'Outstanding receivables must be settled first.',
            ]);
        }

        $customer->forceFill(['is_active' => false])->save();

        return $customer->refresh();
    }

    public function activate(Customer $customer): Customer
    {
        $customer->forceFill(['is_active' => true])->save();

        return $customer->refresh();
    }

    /**
     * Assert the customer may be used for a new document.
     *
     * @throws ValidationException
     */
    public function assertUsableForInvoicing(Customer $customer): void
    {
        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => "Customer [{$customer->customer_code}] is inactive and cannot be invoiced.",
            ]);
        }
    }
}
