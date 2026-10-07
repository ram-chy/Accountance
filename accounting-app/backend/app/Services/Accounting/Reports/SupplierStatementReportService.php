<?php

namespace App\Services\Accounting\Reports;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\SupplierPayment;
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
 *
 * Phase 11 notes arrive through CounterpartyStatementReportService::noteEntries,
 * which needs nothing from this subclass beyond normalDirection() being `credit`:
 * that is what places a purchase credit note on the DEBIT side - reducing what we
 * owe - and a purchase debit note on the credit side. It is the mirror image of the
 * customer statement, and reading isCredit() as the statement direction instead
 * would double the payable of a bill its supplier had credited.
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
                'amount' => $payment->baseAmount(),
                'currency_code' => $payment->currency_id ? $payment->currency?->code : $company->currency?->code,
                'exchange_rate' => $payment->exchange_rate,
                'foreign_amount' => $payment->isForeignCurrency() ? $payment->amountMoney()->toDatabase() : null,
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
                'amount' => $bill->baseGrandTotal(),
                'currency_code' => $bill->currency_id ? $bill->currency?->code : $company->currency?->code,
                'exchange_rate' => $bill->exchange_rate,
                'foreign_amount' => $bill->isForeignCurrency() ? $bill->grandTotalAmount()->toDatabase() : null,
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
