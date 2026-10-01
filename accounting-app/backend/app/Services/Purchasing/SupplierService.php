<?php

namespace App\Services\Purchasing;

use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\Supplier;
use App\Services\Accounting\TransactionAccountResolver;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Supplier master data.
 *
 * The mirror of CustomerService and deliberately the same shape. A customer and
 * a supplier differ in which side of the entry they sit on and which account
 * they carry, not in any of the lifecycle rules - both are company-scoped
 * masters with a per-company code, a control account, deactivation instead of
 * deletion, and historical documents that must stay readable.
 *
 * The duplication is worth it here. A shared "counterparty" abstraction would
 * have to express "control account must be an asset" and "control account must
 * be a liability" as parameters, at which point it is a thin wrapper over
 * TransactionAccountResolver with an extra indirection, and the two services
 * would each be shorter but no clearer.
 */
class SupplierService
{
    public function __construct(private readonly TransactionAccountResolver $accounts) {}

    /**
     * @throws ValidationException
     */
    public function create(Company $company, array $data): Supplier
    {
        $this->accounts->payable($company, $data['payable_account_id']);

        $supplier = new Supplier([
            'supplier_code' => $data['supplier_code'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'tax_identifier' => $data['tax_identifier'] ?? null,
            'payable_account_id' => $data['payable_account_id'],
        ]);

        $supplier->company_id = $company->getKey();
        $supplier->forceFill(['is_active' => true]);

        try {
            $supplier->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw ValidationException::withMessages([
                    'supplier_code' => 'This supplier code is already in use for the selected company.',
                ]);
            }

            throw $e;
        }

        return $supplier;
    }

    /**
     * @throws ValidationException
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        if (array_key_exists('payable_account_id', $data)) {
            $this->accounts->payable($supplier->company, $data['payable_account_id']);
        }

        /*
         * Only keys that were actually sent are written, tested with
         * array_key_exists rather than a null filter.
         *
         * A null filter would read as "ignore nulls" and be exactly backwards:
         * it would make {"email": null} a no-op, so a client asking to clear an
         * address would get a 200 and the old value would stay on file. Absent
         * means "leave this alone" (a partial update); present-and-null means
         * "clear this". The two are different requests and only one of them
         * should be ignored.
         */
        $fields = [
            'name',
            'supplier_code',
            'email',
            'phone',
            'address',
            'city',
            'state',
            'country_code',
            'tax_identifier',
            'payable_account_id',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $supplier->{$field} = $data[$field];
            }
        }

        try {
            $supplier->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw ValidationException::withMessages([
                    'supplier_code' => 'This supplier code is already in use for the selected company.',
                ]);
            }

            throw $e;
        }

        return $supplier->refresh();
    }

    /**
     * Deactivate a supplier.
     *
     * Stops new bills being raised but leaves every existing bill readable and
     * resolvable. There is no delete: the bills FK restricts deletion anyway, and
     * a supplier whose bills exist is part of the audit trail.
     *
     * Refused if the supplier has any non-draft bill, for the same reason as
     * CustomerService::deactivate: a posted bill is money this company owes, and
     * an inactive supplier is excluded from the payables report, so the balance
     * would become unreportable against anyone. Drafts do not count, because a
     * draft is not an accounting fact.
     *
     * @throws ValidationException
     */
    public function deactivate(Supplier $supplier): Supplier
    {
        if (! $supplier->is_active) {
            throw ValidationException::withMessages([
                'supplier' => "Supplier [{$supplier->supplier_code}] is already inactive.",
            ]);
        }

        $hasPostedBills = $supplier->bills()
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->exists();

        if ($hasPostedBills) {
            throw ValidationException::withMessages([
                'supplier' => 'This supplier has posted bills and cannot be deactivated. '
                    .'Outstanding payables must be settled first.',
            ]);
        }

        $supplier->forceFill(['is_active' => false])->save();

        return $supplier->refresh();
    }

    public function activate(Supplier $supplier): Supplier
    {
        $supplier->forceFill(['is_active' => true])->save();

        return $supplier->refresh();
    }

    /**
     * Assert the supplier may be used for a new document.
     *
     * @throws ValidationException
     */
    public function assertUsableForBilling(Supplier $supplier): void
    {
        if (! $supplier->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => "Supplier [{$supplier->supplier_code}] is inactive and cannot be billed.",
            ]);
        }
    }
}
