<?php

namespace App\Services\Purchasing;

use App\Enums\JournalSource;
use App\Enums\TransactionStatus;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\JournalLine;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use App\Models\User;
use App\Services\Accounting\Currency\DocumentCurrencyService;
use App\Services\Accounting\Currency\TransactionCurrency;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posting a purchase bill.
 *
 * The entry, per the spec's section 11:
 *
 *   Dr Expense / Purchase   sum of line net amounts, one line per expense account
 *   Dr Input Tax            tax_total, when non-zero
 *       Cr Accounts Payable grand_total
 *
 * Input tax is debited rather than credited, and this is the line most likely
 * to be got wrong: tax recovered from a supplier is a receivable from the tax
 * authority, so the same tax_total that a sales invoice credits to a liability
 * is debited to an asset here. The resolver enforces that the account supplied
 * is an ASSET, so a bill pointed at the sales tax liability account is rejected
 * at validation rather than producing a credit where a debit belongs.
 *
 * Same structural rules as SalesInvoicePostingService: it describes an entry,
 * it does not write lines, and it does not set journal status.
 */
class PurchaseBillPostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
        private readonly DocumentCurrencyService $currencies,
    ) {}

    /**
     * @throws ValidationException
     * @throws ConflictException
     */
    public function post(PurchaseBill $bill, User $actor): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $actor) {
            $fresh = PurchaseBill::query()
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isPosted()) {
                throw new ConflictException(
                    message: 'This bill is already posted. '
                        .'Record a debit note instead of posting it again.',
                    errors: [
                        'bill' => ['This bill is already posted. '
                            .'Record a debit note instead of posting it again.'],
                    ],
                );
            }

            $supplier = $fresh->supplier;

            if ($supplier === null) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'This bill has no valid supplier.',
                ]);
            }

            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_id' => "Supplier [{$supplier->supplier_code}] is inactive. "
                        .'A bill cannot be posted for an inactive supplier.',
                ]);
            }

            $payable = $this->accounts->payable($fresh->company, $supplier->payable_account_id);

            $lines = $fresh->lines()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'This bill has no lines and cannot produce a balanced entry.',
                ]);
            }

            $expenseByAccount = $this->expenseByAccount($fresh, $lines);

            $totals = $this->recalculateTotals($fresh, $lines);

            $taxTotal = Money::of($totals['tax_total']);
            $grandTotal = Money::of($totals['grand_total']);

            if (! $grandTotal->isPositive()) {
                throw ValidationException::withMessages([
                    'grand_total' => 'A bill must total more than zero to be posted.',
                ]);
            }

            /*
             * Asked about the recomputed tax total, not the stored column. A bill
             * whose stored tax_total was stale would otherwise either lose its
             * input tax debit or be refused for an account its entry does not use.
             */
            $inputTaxAccount = $taxTotal->isPositive()
                ? $this->resolveInputTaxAccount($fresh)
                : null;

            /*
             * The currency context, resolved at posting rather than read off the
             * draft. See SalesInvoicePostingService for why: the draft's rate is a
             * preview of a mutable rate table, and this is the last moment the bill
             * can be priced. One resolved rate is written to the header, the lines and
             * the journal together.
             */
            $transaction = $this->currencies->resolve(
                $fresh->company,
                $fresh->currency_id,
                Carbon::parse($fresh->bill_date)->toDateString(),
                'currency_id',
            );

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => Carbon::parse($fresh->bill_date)->toDateString(),
                    'description' => "Bill {$fresh->bill_number}",
                    'reference' => $fresh->bill_number,
                    'source_type' => JournalSource::PurchaseBill->value,
                    'source_id' => $fresh->getKey(),
                    'lines' => $this->journalLines($transaction, $expenseByAccount, $inputTaxAccount, $payable, $grandTotal, $taxTotal),
                ],
            );

            $this->posting->post($journal, $actor, 'bill_date');

            /*
             * The bill's base figures, read back out of the journal rather than
             * converted a second time, for the reason given in
             * DocumentCurrencyService::baseAmountBooked.
             */
            $booked = $journal->lines()->get();

            $baseGrandTotal = $this->currencies->baseAmountBooked($booked, (int) $payable->getKey());

            $baseTaxTotal = $inputTaxAccount === null
                ? Money::zero()
                : $this->currencies->baseAmountBooked($booked, (int) $inputTaxAccount->getKey());

            $this->writeBaseTaxAmounts($fresh, $transaction, $booked, $inputTaxAccount);

            $fresh->forceFill([
                'status' => TransactionStatus::Posted->value,
                'journal_id' => $journal->getKey(),
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'currency_id' => $transaction->currency?->getKey(),
                'exchange_rate' => $transaction->rateToPersist(),
                'base_grand_total' => $baseGrandTotal?->toDatabase(),
                'base_tax_total' => $baseTaxTotal->toDatabase(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Record each bill line's input tax in base currency, from the posted journal.
     *
     * The distribution rule - every line but the last converted at the document rate,
     * the last taking the remainder - is the same one SalesInvoicePostingService
     * applies, and for the same reason: a journal line holds one foreign amount and
     * one rate, so the tax debit on the journal is a single conversion of the
     * document's tax total, and the per-line figures that must add up to it cannot
     * each be an independent conversion.
     *
     * @param  Collection<int, JournalLine>  $booked
     */
    private function writeBaseTaxAmounts(
        PurchaseBill $bill,
        TransactionCurrency $transaction,
        Collection $booked,
        ?Account $inputTaxAccount
    ): void {
        $bookedTax = $inputTaxAccount === null
            ? null
            : $this->currencies->baseAmountBooked($booked, (int) $inputTaxAccount->getKey());

        if ($bookedTax === null) {
            return;
        }

        $taxable = $bill->lines()
            ->where('tax_amount', '>', 0)
            ->orderBy('line_number')
            ->get();

        if ($taxable->isEmpty()) {
            return;
        }

        $remaining = $bookedTax;
        $lastIndex = $taxable->count() - 1;

        foreach ($taxable as $position => $line) {
            $share = $position === $lastIndex
                ? $remaining
                : $this->currencies->toBase(Money::of($line->tax_amount), $transaction);

            if ($share->greaterThan($remaining)) {
                $share = $remaining;
            }

            $line->forceFill(['base_tax_amount' => $share->toDatabase()])->save();

            $remaining = $remaining->minus($share);
        }
    }

    /**
     * Net expense per distinct expense account.
     *
     * @param  Collection<int, PurchaseBillLine>  $lines
     * @return array<int, Money>
     */
    private function expenseByAccount(PurchaseBill $bill, Collection $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $net = Money::of($line->line_total)->minus(Money::of($line->tax_amount));
            $accountId = $line->expense_account_id;

            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($net);
        }

        /*
         * resolveAll(), not resolveMany(): a keyed role => id map cannot hold
         * many expense accounts at once, and passing ten ids under one repeated
         * key would validate only the last and quietly leave the other nine
         * unchecked - they would still reach the journal.
         */
        $this->accounts->resolveAll($bill->company, 'expense_account_id', array_keys($totals));

        return $totals;
    }

    /**
     * @throws ValidationException
     */
    private function resolveInputTaxAccount(PurchaseBill $bill): Account
    {
        if ($bill->tax_account_id === null) {
            throw ValidationException::withMessages([
                'tax_account_id' => 'This bill recovers tax, so an input tax account is required to record where that tax is held.',
            ]);
        }

        return $this->accounts->inputTax($bill->company, $bill->tax_account_id);
    }

    /**
     * Describe the journal entry, in the document's own currency.
     *
     * As on the sales side, no base amount appears here: JournalService derives it
     * from the bill's rate, so there is one conversion in the system rather than one
     * per posting path. Amounts are grouped by account in the foreign currency
     * before conversion, so the arithmetic a reader checks by hand is the arithmetic
     * that was performed.
     *
     * @param  array<int, Money>  $expenseByAccount
     * @return array<int, array<string, mixed>>
     */
    private function journalLines(
        TransactionCurrency $transaction,
        array $expenseByAccount,
        ?Account $inputTaxAccount,
        Account $payable,
        Money $grandTotal,
        Money $taxTotal
    ): array {
        $lines = [];

        foreach ($expenseByAccount as $accountId => $amount) {
            $lines[] = $this->currencies->journalLine(
                $transaction,
                (int) $accountId,
                'Expense / purchases',
                $amount,
                true
            );
        }

        if ($inputTaxAccount !== null) {
            $lines[] = $this->currencies->journalLine(
                $transaction,
                (int) $inputTaxAccount->getKey(),
                'Input tax',
                $taxTotal,
                true
            );
        }

        $lines[] = $this->currencies->journalLine(
            $transaction,
            (int) $payable->getKey(),
            'Accounts Payable',
            $grandTotal,
            false
        );

        return $lines;
    }

    /**
     * Recompute stored totals from the persisted lines.
     *
     * @param  Collection<int, PurchaseBillLine>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string}
     */
    private function recalculateTotals(PurchaseBill $bill, Collection $lines): array
    {
        $subtotal = Money::zero();
        $discount = Money::zero();
        $tax = Money::zero();

        foreach ($lines as $line) {
            $gross = Money::of($line->quantity)->times(Money::of($line->unit_cost));
            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus(Money::of($line->discount));
            $tax = $tax->plus(Money::of($line->tax_amount));
        }

        return [
            'subtotal' => $subtotal->toDatabase(),
            'discount_total' => $discount->toDatabase(),
            'tax_total' => $tax->toDatabase(),
            'grand_total' => $subtotal->minus($discount)->plus($tax)->toDatabase(),
        ];
    }
}
