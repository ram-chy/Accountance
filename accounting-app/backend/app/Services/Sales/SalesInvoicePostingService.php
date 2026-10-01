<?php

namespace App\Services\Sales;

use App\Enums\JournalSource;
use App\Enums\TransactionStatus;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posting a sales invoice: the bridge from a business document to the ledger.
 *
 * The entry, per the spec's section 9:
 *
 *   Dr Accounts Receivable   grand_total
 *       Cr Revenue           sum of line net amounts, one line per revenue account
 *       Cr Tax Payable       tax_total, when non-zero
 *
 * Revenue is credited per distinct revenue account rather than as a single
 * total, so the ledger can answer "how much of this invoice was product X?"
 * without a report having to re-read the document. Lines sharing an account are
 * merged, because two credit lines on the same account add nothing a reader
 * would not have to sum.
 *
 * What this service deliberately does not do:
 *
 *  - It does not write journal_lines. It hands JournalService a description of
 *    the entry and that service decides whether the result is structurally valid.
 *  - It does not set journals.status. JournalPostingService is the only writer,
 *    which is what keeps "posted journals are immutable" checkable by reading
 *    two files rather than auditing every write in one.
 *  - It does not re-check balance, period state or account activity. Those
 *    belong to the posting engine; duplicating them here would be two places to
 *    keep in step and one more chance for them to disagree.
 *
 * What it owns: which accounts, which amounts, which source, and the
 * invoice-to-journal linkage.
 */
class SalesInvoicePostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
    ) {}

    /**
     * Post a draft invoice and its accounting entry, atomically.
     *
     * The order follows the spec's section 18 exactly: lock, verify eligibility,
     * validate the customer, validate the accounts, recalculate totals, create
     * the journal, post it, then record the link and the new status. Every step
     * is inside one transaction, so a failure at any point leaves the invoice a
     * draft with no journal rather than a posted invoice with no entry.
     *
     * @throws ValidationException
     * @throws ConflictException when the invoice is already posted
     */
    public function post(SalesInvoice $invoice, User $actor): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            // 1. Lock the invoice, so a concurrent post cannot interleave.
            $fresh = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Eligibility. Conflict, not validation: a second post of an
            //    already-posted invoice is a caller whose request was valid and
            //    simply lost a race, so it gets the same 409 the controller's
            //    early check returns rather than a 422 about its input.
            if ($fresh->status->isPosted()) {
                throw new ConflictException(
                    message: 'This invoice is already posted. '
                        .'Record a credit note instead of posting it again.',
                    errors: [
                        'invoice' => ['This invoice is already posted. '
                            .'Record a credit note instead of posting it again.'],
                    ],
                );
            }

            // 3. Customer: must exist in this company and still be active.
            $customer = $fresh->customer;

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'customer_id' => 'This invoice has no valid customer.',
                ]);
            }

            if (! $customer->is_active) {
                /*
                 * Refused even though the customer is deactivated *after* the
                 * invoice was drafted. A draft is not an accounting fact, so
                 * there is nothing to preserve: the user can reactivate the
                 * customer, or move the invoice to an active one. Posted
                 * invoices are never re-checked - they are already in the record.
                 */
                throw ValidationException::withMessages([
                    'customer_id' => "Customer [{$customer->customer_code}] is inactive. "
                        .'An invoice cannot be posted for an inactive customer.',
                ]);
            }

            // 4. Accounts, revalidated at posting time rather than trusted from
            //    draft time. An account that was active when the draft was saved
            //    may since have been deactivated, and the entry is being made now.
            $receivable = $this->accounts->receivable($fresh->company, $customer->receivable_account_id);

            $lines = $fresh->lines()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'This invoice has no lines and cannot produce a balanced entry.',
                ]);
            }

            $revenueByAccount = $this->revenueByAccount($fresh, $lines);

            /*
             * 5. Totals. Recalculated from the persisted lines rather than read
             *    from the invoice row, so a total that was somehow wrong at draft
             *    time cannot produce a journal that disagrees with the lines. The
             *    recalculated value is written back below.
             */
            $totals = $this->recalculateTotals($fresh, $lines);

            $grandTotal = Money::of($totals['grand_total']);

            if (! $grandTotal->isPositive()) {
                throw ValidationException::withMessages([
                    'grand_total' => 'An invoice must total more than zero to be posted.',
                ]);
            }

            /*
             * The tax account is resolved *after* the totals, from the recomputed
             * tax total rather than the stored one. The order matters in both
             * directions: an invoice whose stored tax_total was stale at zero
             * while its lines now charge tax would skip the tax account
             * entirely and produce an entry missing the tax credit, and one whose
             * stored tax_total was stale at a non-zero while its lines charge none
             * would demand a tax account the entry does not need. Asking about the
             * number the entry is actually going to book is the only version of
             * this question with a defensible answer.
             */
            $taxAccount = $this->resolveTaxAccount($fresh, $totals['tax_total']);

            // 6 & 7. Create the draft journal and its lines.
            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $fresh->invoice_date->toDateString(),
                    'description' => "Invoice {$fresh->invoice_number}",
                    'reference' => $fresh->invoice_number,
                    'source_type' => JournalSource::SalesInvoice->value,

                    /*
                     * source_id is the invoice's own id, which exists before the
                     * journal does. Writing the invoice id rather than the
                     * journal id is what makes the pair
                     * (source_type, source_id) a resolvable pointer back to the
                     * document from the ledger side.
                     */
                    'source_id' => $fresh->getKey(),
                    'lines' => $this->journalLines($receivable, $revenueByAccount, $taxAccount, $grandTotal, $totals['tax_total']),
                ],
            );

            // 8. Post through the single existing writer.
            $this->posting->post($journal, $actor, 'invoice_date');

            /*
             * 9 & 10. The link and the status, in one statement so there is no
             * window in which the invoice is POSTED without its journal_id. The
             * whole thing is inside the transaction anyway, so the window would
             * not be visible from outside - but a single statement means the
             * invariant also holds for anyone reading the code.
             */
            $fresh->forceFill([
                'status' => TransactionStatus::Posted->value,
                'journal_id' => $journal->getKey(),
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Net revenue per distinct revenue account.
     *
     * "Net" rather than line_total: a line's tax belongs to the tax account, not
     * to revenue, so crediting the tax portion to the revenue account would
     * inflate revenue by the amount of tax collected. Grouped by account so two
     * lines on the same account become one credit.
     *
     * @param  Collection<int, SalesInvoiceLine>  $lines
     * @return array<int, Money> account id => net amount
     */
    private function revenueByAccount(SalesInvoice $invoice, $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $accountId = $line->revenue_account_id;

            // net = quantity x unit price - discount = line_total - tax
            $net = Money::of($line->line_total)->minus(Money::of($line->tax_amount));

            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($net);
        }

        /*
         * Validate every distinct account before the journal exists, so a bad
         * account is reported as a 422 rather than as a failed posting.
         *
         * resolveAll() rather than resolveMany(): a keyed map of role => id
         * cannot hold ten lines that all credit revenue. Feeding the ids through
         * one repeated role key would keep only the last one and silently skip
         * validating the other nine, which is exactly the bug this call shape is
         * prone to. The return value is discarded - the amounts are already
         * grouped above - but issuing the query here is the point, since it is
         * what proves each account is active, owned and a REVENUE account.
         */
        $this->accounts->resolveAll($invoice->company, 'revenue_account_id', array_keys($totals));

        return $totals;
    }

    /**
     * The tax liability account, if the invoice actually charges tax.
     *
     * The tax total is passed in rather than read from the invoice row, because
     * the caller has just recomputed it from the lines and it is that recomputed
     * figure the journal will book.
     *
     * @throws ValidationException
     */
    private function resolveTaxAccount(SalesInvoice $invoice, string $taxTotal): ?Account
    {
        if (Money::of($taxTotal)->isZero()) {
            return null;
        }

        if ($invoice->tax_account_id === null) {
            throw ValidationException::withMessages([
                'tax_account_id' => 'This invoice charges tax, so a tax account is required to record where that tax is owed.',
            ]);
        }

        return $this->accounts->tax($invoice->company, $invoice->tax_account_id);
    }

    /**
     * Describe the journal entry.
     *
     * Built as a description rather than written directly, and the same builder
     * feeds a GET /preview so a user can see the entry before committing to it.
     *
     * @param  array<int, Money>  $revenueByAccount
     * @return array<int, array<string, mixed>>
     */
    private function journalLines(
        Account $receivable,
        array $revenueByAccount,
        ?Account $taxAccount,
        Money $grandTotal,
        string $taxTotal
    ): array {
        $lines = [];

        $lines[] = [
            'account_id' => $receivable->getKey(),
            'description' => 'Accounts Receivable',
            'debit' => $grandTotal->toDatabase(),
            'credit' => '0',
        ];

        foreach ($revenueByAccount as $accountId => $amount) {
            $lines[] = [
                'account_id' => $accountId,
                'description' => 'Sales revenue',
                'debit' => '0',
                'credit' => $amount->toDatabase(),
            ];
        }

        if ($taxAccount !== null && Money::of($taxTotal)->isPositive()) {
            $lines[] = [
                'account_id' => $taxAccount->getKey(),
                'description' => 'Tax payable',
                'debit' => '0',
                'credit' => $taxTotal,
            ];
        }

        return $lines;
    }

    /**
     * Recompute a stored invoice's totals from its persisted lines.
     *
     * @param  Collection<int, SalesInvoiceLine>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string}
     */
    private function recalculateTotals(SalesInvoice $invoice, $lines): array
    {
        $subtotal = Money::zero();
        $discount = Money::zero();
        $tax = Money::zero();

        foreach ($lines as $line) {
            $gross = Money::of($line->quantity)->times(Money::of($line->unit_price));
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
