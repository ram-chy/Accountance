<?php

namespace App\Services\Accounting\Reports;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Customer account statement.
 *
 * Invoices are debits (the customer owes more), receipts are credits (they owe
 * less), and the running balance is receivable-positive. Drafts of either kind
 * are excluded: a draft has no accounting entry, so including one would put a
 * number on the statement that is not in the ledger.
 *
 * Phase 11 notes arrive through CounterpartyStatementReportService::noteEntries,
 * which needs nothing from this subclass beyond normalDirection() being `debit`:
 * that is what puts a sales credit note on the credit side and a sales debit note
 * on the debit side, with the running balance falling and rising accordingly.
 */
class CustomerStatementReportService extends CounterpartyStatementReportService
{
    protected function debitEntries(Company $company, Customer|Supplier $counterparty): Collection
    {
        return SalesInvoice::query()
            ->where('company_id', $company->getKey())
            ->where('customer_id', $counterparty->getKey())
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SalesInvoice $invoice): array => [
                'date' => Carbon::parse($invoice->invoice_date),
                'id' => $invoice->getKey(),
                'direction' => 'debit',
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'description' => $invoice->notes,
                'amount' => $invoice->baseGrandTotal(),
                'currency_code' => $invoice->currency_id ? $invoice->currency?->code : $company->currency?->code,
                'exchange_rate' => $invoice->exchange_rate,
                'foreign_amount' => $invoice->isForeignCurrency() ? $invoice->grandTotalAmount()->toDatabase() : null,
            ]);
    }

    protected function creditEntries(Company $company, Customer|Supplier $counterparty): Collection
    {
        return CustomerReceipt::query()
            ->where('company_id', $company->getKey())
            ->where('customer_id', $counterparty->getKey())
            ->where('status', PaymentStatus::Posted->value)
            ->orderBy('receipt_date')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomerReceipt $receipt): array => [
                'date' => Carbon::parse($receipt->receipt_date),
                'id' => $receipt->getKey(),
                'direction' => 'credit',
                'type' => 'receipt',
                'reference' => $receipt->receipt_number,
                'description' => $receipt->reference ?? $receipt->notes,
                'amount' => $receipt->baseAmount(),
                'currency_code' => $receipt->currency_id ? $receipt->currency?->code : $company->currency?->code,
                'exchange_rate' => $receipt->exchange_rate,
                'foreign_amount' => $receipt->isForeignCurrency() ? $receipt->amountMoney()->toDatabase() : null,
            ]);
    }

    protected function counterpartyKey(): string
    {
        return 'customer';
    }

    protected function normalDirection(): string
    {
        return 'debit';
    }

    protected function summary(Customer|Supplier $counterparty): array
    {
        return [
            'id' => $counterparty->getKey(),
            'code' => $counterparty->customer_code,
            'name' => $counterparty->name,
        ];
    }
}
