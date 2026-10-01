<?php

namespace App\Services\Accounting\Reports;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Supplier account statement.
 *
 * The mirror of the customer statement with the sides reversed: bills are
 * credits (we owe more), payments are debits (we owe less), and the running
 * balance is payable-positive. Presenting it payable-positive matters - a
 * supplier statement that showed amounts owed as negative would read as though
 * the supplier owed the company.
 */
class SupplierStatementReportService extends CounterpartyStatementReportService
{
    protected function debitEntries(Company $company, Customer|Supplier $counterparty): Collection
    {
        return SupplierPayment::query()
            ->where('company_id', $company->getKey())
            ->where('supplier_id', $counterparty->getKey())
            ->where('status', PaymentStatus::Posted->value)
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierPayment $payment): array => [
                'date' => Carbon::parse($payment->payment_date),
                'id' => $payment->getKey(),
                'direction' => 'debit',
                'type' => 'payment',
                'reference' => $payment->payment_number,
                'description' => $payment->reference ?? $payment->notes,
                'amount' => Money::of($payment->amount),
            ]);
    }

    protected function creditEntries(Company $company, Customer|Supplier $counterparty): Collection
    {
        return PurchaseBill::query()
            ->where('company_id', $company->getKey())
            ->where('supplier_id', $counterparty->getKey())
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseBill $bill): array => [
                'date' => Carbon::parse($bill->bill_date),
                'id' => $bill->getKey(),
                'direction' => 'credit',
                'type' => 'bill',
                'reference' => $bill->bill_number,
                'description' => $bill->notes,
                'amount' => Money::of($bill->grand_total),
            ]);
    }

    protected function counterpartyKey(): string
    {
        return 'supplier';
    }

    protected function normalDirection(): string
    {
        return 'credit';
    }

    protected function summary(Customer|Supplier $counterparty): array
    {
        return [
            'id' => $counterparty->getKey(),
            'code' => $counterparty->supplier_code,
            'name' => $counterparty->name,
        ];
    }
}
