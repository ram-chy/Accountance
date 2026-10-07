<?php

namespace App\Services\Sales;

use App\Enums\DocumentNumberType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\Currency\DocumentCurrencyService;
use App\Services\Accounting\DocumentCalculator;
use App\Services\Accounting\DocumentNumberSequence;
use App\Services\Accounting\DocumentTaxContext;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales invoices: draft lifecycle and posting.
 *
 * Split in two on purpose. Everything a draft can do lives here; the single
 * irreversible act - creating and posting the accounting entry - lives in
 * SalesInvoicePostingService. That split is what the Phase 5 spec's "never post
 * from a controller" rule asks for structurally rather than by convention: a
 * caller holding only a SalesInvoiceService reference has no way to post.
 *
 * The posting path never writes journal lines itself. It describes the entry it
 * wants (which accounts, which amounts, which source) and hands it to
 * JournalService and JournalPostingService, which own structure validation,
 * balance, company ownership, account activity, period state and posted_at.
 */
class SalesInvoiceService
{
    public function __construct(
        private readonly DocumentNumberSequence $numbers,
        private readonly AccountingPeriodService $periods,
        private readonly DocumentCalculator $calculator,
        private readonly TransactionAccountResolver $accounts,
        private readonly CustomerService $customers,
        private readonly DocumentCurrencyService $currencies,
    ) {}

    /**
     * Create a draft invoice.
     *
     * Wrapped in a transaction because the invoice, its lines and its allocated
     * number are three writes, and the number in particular must not be consumed
     * by a document that then fails to save.
     *
     * @throws ValidationException
     */
    public function createDraft(Company $company, User $actor, array $data): SalesInvoice
    {
        $customer = $this->resolveCustomer($company, $data['customer_id']);

        return DB::transaction(function () use ($company, $actor, $data, $customer) {
            $number = $this->numbers->nextFor($company, DocumentNumberType::Invoice);

            $invoice = new SalesInvoice([
                'customer_id' => $customer->getKey(),
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
                'tax_account_id' => $data['tax_account_id'] ?? null,
            ]);

            $this->applyCurrency($company, $invoice, $data);

            /*
             * forceFill for the four server-owned columns: company_id from the
             * request context, invoice_number from the sequence, status from the
             * lifecycle, created_by from the token. None is fillable, so there is
             * no code path by which a client can set one.
             */
            $invoice->forceFill([
                'company_id' => $company->getKey(),
                'invoice_number' => $number,
                'status' => TransactionStatus::Draft->value,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->writeLines($company, $invoice, $data['lines'] ?? []);

            return $invoice->refresh();
        });
    }

    /**
     * Update a draft invoice.
     *
     * The status is re-read under a row lock rather than trusted from the routed
     * instance, for the reason JournalService::updateDraft does the same: a
     * pre-transaction check leaves a window in which a concurrent post commits
     * between the check and this write, and the update would then rewrite a
     * document that is already in the accounting record.
     *
     * @throws ValidationException
     */
    public function updateDraft(SalesInvoice $invoice, Company $company, array $data): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $company, $data) {
            $fresh = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            $customer = $fresh->customer;

            if (array_key_exists('customer_id', $data)) {
                $customer = $this->resolveCustomer($company, $data['customer_id']);
                $fresh->customer_id = $customer->getKey();
            }

            /*
             * Phase 8: re-dating a draft may not target a closed period. See
             * JournalService::updateDraft for why this is checked on the date
             * only, and why it is checked at all.
             */
            if (array_key_exists('invoice_date', $data)) {
                $this->periods->assertDateNotClosed($company, Carbon::parse($data['invoice_date']), 'invoice_date');
            }

            // Absent and explicit null are different requests; see the same
            // comment in CustomerReceiptService::updateDraft.
            foreach (['invoice_date', 'due_date', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fresh->{$field} = $data[$field];
                }
            }

            if (array_key_exists('tax_account_id', $data)) {
                $fresh->tax_account_id = $data['tax_account_id'];
            }

            /*
             * Re-dating and re-denominating together are resolved as one step, and
             * the rate is taken at the date the invoice will carry once this save
             * lands. Resolving them separately would let a draft be re-dated without
             * being re-priced, leaving a rate that belonged to a date the invoice no
             * longer has.
             */
            $this->applyCurrency($company, $fresh, $data);

            $fresh->save();

            if (array_key_exists('lines', $data)) {
                $this->writeLines($company, $fresh, $data['lines']);
            }

            return $fresh->refresh();
        });
    }

    /**
     * Delete a draft invoice.
     *
     * Destructive of the invoice row and its lines, and safe only because a
     * draft has no journal: there is nothing in the accounting record to lose,
     * and the FK on sales_invoice_lines cascades. The invoice number is not
     * returned to the sequence - an identifier issued to a document that once
     * existed must not be reissued.
     *
     * A posted invoice is refused here rather than relying on the route or the
     * caller, so the rule holds for any code path that reaches this service.
     *
     * @throws ValidationException
     */
    public function deleteDraft(SalesInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $fresh = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            $fresh->delete();
        });
    }

    /**
     * Resolve the invoice's transaction currency and snapshot the rate of its date.
     *
     * WHY A DRAFT CARRIES A RATE AT ALL
     *
     * Because a draft invoice's amounts are in its transaction currency, and an
     * amount in EUR beside a base-currency ledger is not a number anybody can act
     * on. The snapshot exists so the document can be read - shown, approved,
     * printed - in both currencies from the moment it is saved, and so a reviewer
     * sees the rate the invoice was priced at rather than the rate of the day they
     * happen to look at it.
     *
     * WHY POSTING RE-RESOLVES IT ANYWAY
     *
     * Because this snapshot is a preview, and the rate table is mutable history. A
     * rate row may be added, corrected or deactivated between drafting and posting,
     * and a draft is not an accounting fact - there is nothing to preserve about the
     * rate it was typed with. So SalesInvoicePostingService resolves the rate again
     * at posting time, writes that one to the header, to the lines and to the
     * journal together, and the number stops moving for good.
     *
     * WHY ONLY WHEN THE CURRENCY OR THE DATE CHANGES
     *
     * An edit that touches neither cannot change what the rate should be, and
     * re-resolving on every line edit would mean a merchant who deactivated one
     * rate row could no longer fix a typo in a description. The gate is on the two
     * inputs that can change the answer, not on how many times the form is saved.
     *
     * The method mutates the model and does not save; the caller decides when, so
     * that re-dating and re-denominating land in a single write.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function applyCurrency(Company $company, SalesInvoice $invoice, array $data): void
    {
        if (! array_key_exists('currency_id', $data) && ! array_key_exists('invoice_date', $data)) {
            return;
        }

        $currencyId = array_key_exists('currency_id', $data)
            ? $data['currency_id']
            : $invoice->currency_id;

        $context = $this->currencies->resolve(
            $company,
            $currencyId,
            (string) $invoice->invoice_date,
            'currency_id',
        );

        $invoice->currency_id = $context->currency?->getKey();
        $invoice->exchange_rate = $context->rateToPersist();
    }

    /**
     * Replace an invoice's lines and recompute its totals.
     *
     * Full replace rather than a diff, matching the journal edit rule: a partial
     * update could not distinguish "remove line 3" from "line 3 was never meant
     * to be sent", and a document whose totals are derived from its lines has to
     * be saved as a whole or not at all.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private function writeLines(Company $company, SalesInvoice $invoice, array $lines): void
    {
        /*
         * A sales context, so a line naming configured taxes is charged the rate
         * in force on the invoice's own date. The date is the invoice's, not
         * today's: a backdated invoice must be priced on the rate that applied
         * when it was dated.
         */
        $taxContext = new DocumentTaxContext(
            company: $company,
            date: $invoice->invoice_date->toDateString(),
            output: true,
        );

        $totals = $this->calculator->calculateDocument($lines, 'unit_price', $taxContext);

        $this->calculator->assertTaxAccountPresent(
            Money::of($totals['tax_total']),
            $invoice->tax_account_id
        );

        $revenueAccounts = $this->resolveLineAccounts($company, $lines);

        $invoice->forceFill([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ])->save();

        $invoice->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $calculated = $totals['lines'][$index];

            $invoice->lines()->create([
                'line_number' => $calculated['line_number'],
                'description' => $line['description'] ?? null,
                'quantity' => $calculated['quantity'],
                'unit_price' => $calculated['unit_price'],
                'discount' => $calculated['discount'],
                'tax_rate' => $calculated['tax_rate'],
                'tax_amount' => $calculated['tax_amount'],
                /*
                 * The configured tax this line was charged with, snapshotted. Null
                 * for a line carrying only a hand-entered rate, and for a line
                 * carrying several taxes - see DocumentCalculator::taxOn.
                 */
                'tax_id' => $calculated['tax_id'],
                'line_total' => $calculated['line_total'],
                'revenue_account_id' => $revenueAccounts[$index]->getKey(),
            ]);
        }
    }

    /**
     * Validate every line's revenue account, collecting all failures.
     *
     * One bad account in a 40-line invoice should not cost 40 submissions to
     * find, so each line is validated and the errors are gathered before anything
     * is thrown. The account resolver reports against the field name it was given
     * ("revenue_account_id"), which is right for a customer form and useless here
     * - the user needs to know *which line* is wrong, so each message is re-keyed
     * to lines.N.revenue_account_id.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, Account> line index => resolved account
     *
     * @throws ValidationException
     */
    private function resolveLineAccounts(Company $company, array $lines): array
    {
        $resolved = [];
        $errors = [];

        foreach (array_values($lines) as $index => $line) {
            $accountId = (int) ($line['revenue_account_id'] ?? 0);

            try {
                $resolved[$index] = $this->accounts->revenue($company, $accountId);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    $errors["lines.{$index}.revenue_account_id"] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @throws ValidationException
     */
    private function resolveCustomer(Company $company, int $customerId): Customer
    {
        /*
         * Company-scoped by id, so a customer from another tenant is
         * indistinguishable from one that does not exist. The message says so
         * rather than confirming the id is real elsewhere.
         */
        $customer = Customer::query()
            ->where('company_id', $company->getKey())
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'The selected customer does not belong to the active company.',
            ]);
        }

        $this->customers->assertUsableForInvoicing($customer);

        return $customer;
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(SalesInvoice $invoice): void
    {
        if (! $invoice->status->isDraft()) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice has been posted and is part of the accounting record. '
                    .'It cannot be edited or deleted; record a credit note instead.',
            ]);
        }
    }
}
