<?php

namespace Tests\Feature\Currency;

use App\Enums\NoteType;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Journal;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\Notes\CreditDebitNotePostingService;
use App\Services\Accounting\Notes\CreditDebitNoteService;
use App\Services\Purchasing\PurchaseBillPostingService;
use App\Services\Purchasing\PurchaseBillService;
use App\Services\Sales\SalesInvoicePostingService;
use App\Services\Sales\SalesInvoiceService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Foreign currency documents: invoice, bill and credit note.
 *
 * WHAT IS WORTH TESTING HERE
 *
 * Not the multiplication - Rate's job, and covered where Rate is - but the three
 * agreements a multi-currency document has to hold, each of which has a different
 * failure that no single test would catch:
 *
 *  1. The document, its journal and its rate snapshot all say the same thing.
 *     A rate is resolved once at posting and written to the header, the lines and
 *     the journal together, so there is no window in which the document claims one
 *     price and the ledger booked another.
 *
 *  2. base_grand_total is the ledger's figure, not a second conversion. It is read
 *     back out of the journal rather than recomputed, which is the only way a
 *     document and its entry cannot disagree by a unit in the last place.
 *
 *  3. The per-line base_tax_amounts add up to base_tax_total exactly, because the
 *     tax report totals that column and would otherwise disagree with the ledger.
 *
 * And the regression that matters most: a base-currency document produces exactly
 * the entry, and exactly the row, it produced before any of this existed.
 */
class ForeignDocumentPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Currency $base;

    private Currency $usd;

    /** @var array<string, Account> */
    private array $accounts;

    /**
     * USD -> IDR. Not 1, and not a round number that a conversion could plausibly
     * produce by accident: a test that forgot to convert has to fail, not pass
     * quietly.
     */
    private const RATE = '2.5000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUserWithRole(RoleName::Accountant);

        $this->base = Currency::factory()->code('IDR')->create();
        $this->usd = Currency::factory()->code('USD')->create();

        $this->company = $this->createCompanyFor($this->user, ['currency_id' => $this->base->getKey()]);
        $this->accounts = $this->makeTransactionAccounts($this->company);

        $this->quoteRate('2026-01-01', self::RATE);
    }

    private function quoteRate(string $effectiveFrom, string $rate): ExchangeRate
    {
        return ExchangeRate::factory()
            ->for($this->company)
            ->create([
                'from_currency_id' => $this->usd->getKey(),
                'to_currency_id' => $this->base->getKey(),
                'effective_date' => $effectiveFrom,
                'rate' => $rate,
            ]);
    }

    private function customer(): Customer
    {
        return Customer::factory()->for($this->company)->create([
            'receivable_account_id' => $this->accounts['receivable']->getKey(),
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::factory()->for($this->company)->create([
            'payable_account_id' => $this->accounts['payable']->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer()->getKey(),
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'lines' => [
                [
                    'description' => 'Widget',
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ],
            ],
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | Sales invoice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_foreign_invoice_books_base_amounts_and_keeps_the_foreign_side(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload(['currency_id' => $this->usd->getKey()])
        );

        $this->assertSame($this->usd->getKey(), $invoice->currency_id);
        $this->assertSame(self::RATE, $invoice->exchange_rate);

        $posted = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        // 2 x 100.00 USD = 200.00 USD, converted at 2.5.
        $this->assertSame('200.0000', $posted->grand_total);
        $this->assertSame('500.0000', $posted->base_grand_total);
        $this->assertSame(self::RATE, $posted->exchange_rate);

        $lines = $posted->journal->lines;

        $receivable = $lines->firstWhere('account_id', $this->accounts['receivable']->getKey());

        $this->assertSame('500.0000', $receivable->debit);
        $this->assertSame('200.0000', $receivable->foreign_debit);
        $this->assertSame($this->usd->getKey(), $receivable->currency_id);
        $this->assertSame(self::RATE, $receivable->exchange_rate);

        $revenue = $lines->firstWhere('account_id', $this->accounts['revenue']->getKey());

        $this->assertSame('500.0000', $revenue->credit);
        $this->assertSame('200.0000', $revenue->foreign_credit);

        // The document's base figure is the ledger's figure, not a parallel one.
        $this->assertSame($receivable->debit, $posted->base_grand_total);
    }

    #[Test]
    public function a_foreign_invoices_base_tax_totals_the_ledger_and_its_lines(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload([
                'currency_id' => $this->usd->getKey(),
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
                'lines' => [
                    [
                        'description' => 'Widget',
                        'quantity' => '1',
                        'unit_price' => '100.00',
                        'discount' => '0',
                        'tax_rate' => '10',
                        'revenue_account_id' => $this->accounts['revenue']->getKey(),
                    ],
                    [
                        'description' => 'Gadget',
                        'quantity' => '1',
                        'unit_price' => '50.00',
                        'discount' => '0',
                        'tax_rate' => '10',
                        'revenue_account_id' => $this->accounts['revenue']->getKey(),
                    ],
                ],
            ])
        );

        $posted = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        // 10.00 + 5.00 = 15.00 USD of tax, 37.5000 IDR at 2.5.
        $this->assertSame('15.0000', $posted->tax_total);
        $this->assertSame('37.5000', $posted->base_tax_total);

        $taxLine = $posted->journal->lines
            ->firstWhere('account_id', $this->accounts['tax_payable']->getKey());

        $this->assertSame('37.5000', $taxLine->credit);
        $this->assertSame($posted->base_tax_total, $taxLine->credit);

        /*
         * The point of base_tax_amount: the parts must sum to the whole exactly, or
         * the tax report - which totals this column - disagrees with the ledger.
         */
        $sum = $posted->lines->reduce(
            fn ($carry, $line) => $carry->plus(Money::of($line->base_tax_amount)),
            Money::zero()
        );

        $this->assertSame('37.5000', $sum->toDatabase());
        $this->assertSame($posted->base_tax_total, $sum->toDatabase());

        // 10.00 USD of tax on the first line, converted at the document rate.
        $this->assertSame('25.0000', $posted->lines[0]->base_tax_amount);
        $this->assertSame('12.5000', $posted->lines[1]->base_tax_amount);
    }

    #[Test]
    public function an_untaxed_line_records_no_base_tax_rather_than_zero(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload([
                'currency_id' => $this->usd->getKey(),
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
                'lines' => [
                    [
                        'description' => 'Taxed',
                        'quantity' => '1',
                        'unit_price' => '100.00',
                        'discount' => '0',
                        'tax_rate' => '10',
                        'revenue_account_id' => $this->accounts['revenue']->getKey(),
                    ],
                    [
                        'description' => 'Exempt',
                        'quantity' => '1',
                        'unit_price' => '50.00',
                        'discount' => '0',
                        'tax_rate' => '0',
                        'revenue_account_id' => $this->accounts['revenue']->getKey(),
                    ],
                ],
            ])
        );

        $posted = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        // "No tax" and "tax that rounded to nothing" are different statements.
        $this->assertNull($posted->lines[1]->base_tax_amount);
        $this->assertSame('25.0000', $posted->base_tax_total);
    }

    #[Test]
    public function a_base_currency_invoice_is_posted_exactly_as_before(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload(['tax_account_id' => $this->accounts['tax_payable']->getKey()])
        );

        $posted = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        $this->assertNull($posted->currency_id);
        $this->assertNull($posted->exchange_rate);

        // A posted base-currency invoice still has a base grand total - NULL on that
        // column means "not posted yet", nothing else.
        $this->assertSame('200.0000', $posted->base_grand_total);
        $this->assertSame('0.0000', $posted->base_tax_total);

        foreach ($posted->journal->lines as $line) {
            $this->assertNull($line->currency_id);
            $this->assertNull($line->foreign_debit);
            $this->assertNull($line->foreign_credit);
            $this->assertNull($line->exchange_rate);
        }
    }

    #[Test]
    public function a_draft_carries_the_rate_for_preview_and_posting_uses_the_rate_of_record(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload(['currency_id' => $this->usd->getKey()])
        );

        $this->assertSame(self::RATE, $invoice->exchange_rate);

        /*
         * The rate table moves between drafting and posting. A draft is not an
         * accounting fact, so there is nothing to preserve about the rate it was
         * typed with - and honouring the stale preview would post a document at a
         * rate the company no longer uses.
         */
        $this->quoteRate('2026-06-01', '4.0000000000');

        $posted = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        $this->assertSame('4.0000000000', $posted->exchange_rate);
        $this->assertSame('800.0000', $posted->base_grand_total);
        $this->assertSame('4.0000000000', $posted->journal->lines->first()->exchange_rate);
    }

    #[Test]
    public function a_draft_in_a_currency_with_no_rate_is_refused(): void
    {
        $eur = Currency::factory()->code('EUR')->create();

        try {
            app(SalesInvoiceService::class)->createDraft(
                $this->company,
                $this->user,
                $this->invoicePayload(['currency_id' => $eur->getKey()])
            );

            $this->fail('A currency that cannot be converted must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('EUR to IDR', $e->validator->errors()->first('currency_id'));
        }
    }

    #[Test]
    public function an_account_declared_in_another_currency_refuses_the_document(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $idrReceivable = Account::factory()->for($this->company)->asset()->create([
            'code' => '1110',
            'name' => 'IDR Receivable',
            'currency_id' => $this->base->getKey(),
        ]);

        $customer = Customer::factory()->for($this->company)->create([
            'receivable_account_id' => $idrReceivable->getKey(),
        ]);

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload([
                'customer_id' => $customer->getKey(),
                'currency_id' => $this->usd->getKey(),
            ])
        );

        try {
            app(SalesInvoicePostingService::class)->post($invoice, $this->user);

            $this->fail('A USD receivable must not absorb a USD invoice.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('IDR Receivable', $e->validator->errors()->first('lines.0.account_id'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Purchase bill
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_foreign_bill_debits_input_tax_in_base(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $service = app(PurchaseBillService::class);

        $bill = $service->createDraft($this->company, $this->user, [
            'supplier_id' => $this->supplier()->getKey(),
            'bill_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'currency_id' => $this->usd->getKey(),
            'tax_account_id' => $this->accounts['input_tax']->getKey(),
            'lines' => [
                [
                    'description' => 'Materials',
                    'quantity' => '1',
                    'unit_cost' => '400.00',
                    'discount' => '0',
                    'tax_rate' => '10',
                    'expense_account_id' => $this->accounts['expense']->getKey(),
                ],
            ],
        ]);

        $posted = app(PurchaseBillPostingService::class)->post($bill, $this->user);

        $this->assertSame('440.0000', $posted->grand_total);
        $this->assertSame('1100.0000', $posted->base_grand_total);
        $this->assertSame('40.0000', $posted->tax_total);
        $this->assertSame('100.0000', $posted->base_tax_total);

        $lines = $posted->journal->lines;

        // Input tax is a DEBIT, not a credit - the line most easily got wrong.
        $inputTax = $lines->firstWhere('account_id', $this->accounts['input_tax']->getKey());

        $this->assertSame('100.0000', $inputTax->debit);
        $this->assertSame('40.0000', $inputTax->foreign_debit);

        $payable = $lines->firstWhere('account_id', $this->accounts['payable']->getKey());

        $this->assertSame('1100.0000', $payable->credit);
        $this->assertSame('440.0000', $payable->foreign_credit);

        $this->assertSame($payable->credit, $posted->base_grand_total);
        $this->assertSame('100.0000', $posted->lines[0]->base_tax_amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Credit / debit note
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_credit_note_against_a_foreign_invoice_reverses_in_base(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload([
                'currency_id' => $this->usd->getKey(),
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
            ])
        );

        $invoice = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        $notes = app(CreditDebitNoteService::class);

        $note = $notes->createDraft($this->company, $this->user, [
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => $invoice->getKey(),
            'note_date' => '2026-06-20',
            'reason' => 'Returned',
            'currency_id' => $this->usd->getKey(),
            'tax_account_id' => $this->accounts['tax_payable']->getKey(),
            'lines' => [
                [
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '10',
                    'account_id' => $this->accounts['revenue']->getKey(),
                ],
            ],
        ]);

        $posted = app(CreditDebitNotePostingService::class)->post($note, $this->user);

        $this->assertSame('110.0000', $posted->grand_total);
        $this->assertSame('275.0000', $posted->base_grand_total);

        $receivable = $posted->journal->lines
            ->firstWhere('account_id', $this->accounts['receivable']->getKey());

        // A sales credit note CREDITS the receivable.
        $this->assertSame('275.0000', $receivable->credit);
        $this->assertSame('110.0000', $receivable->foreign_credit);
        $this->assertSame($this->usd->getKey(), $receivable->currency_id);

        $this->assertSame($receivable->credit, $posted->base_grand_total);
        $this->assertSame('25.0000', $posted->base_tax_total);
        $this->assertSame('25.0000', $posted->lines[0]->base_tax_amount);
    }

    #[Test]
    public function a_note_inherits_the_source_currency_and_ignores_a_client_choice(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload(['currency_id' => $this->usd->getKey()])
        );

        $invoice = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        $notes = app(CreditDebitNoteService::class);

        // The base currency is offered deliberately: a caller that assumes it may
        // choose the denomination must be shown the source's answer, not a silent
        // different one.
        $note = $notes->createDraft($this->company, $this->user, [
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => $invoice->getKey(),
            'note_date' => '2026-06-20',
            'reason' => 'Returned',
            'currency_id' => $this->base->getKey(),
            'lines' => [
                [
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $this->accounts['revenue']->getKey(),
                ],
            ],
        ]);

        $this->assertSame($this->usd->getKey(), $note->currency_id);
        $this->assertSame(self::RATE, $note->exchange_rate);

        // And an update cannot re-denominate it either.
        $updated = $notes->updateDraft($note, $this->company, [
            'currency_id' => $this->base->getKey(),
            'reason' => 'Returned late',
        ]);

        $this->assertSame($this->usd->getKey(), $updated->currency_id);
    }

    #[Test]
    public function a_note_dated_later_is_priced_at_its_own_rate_so_the_difference_survives(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload(['currency_id' => $this->usd->getKey()])
        );

        $invoice = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        // The rate moves after the invoice was booked.
        $this->quoteRate('2026-06-18', '3.0000000000');

        $note = app(CreditDebitNoteService::class)->createDraft($this->company, $this->user, [
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => $invoice->getKey(),
            'note_date' => '2026-06-20',
            'reason' => 'Returned',
            'lines' => [
                [
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $this->accounts['revenue']->getKey(),
                ],
            ],
        ]);

        $posted = app(CreditDebitNotePostingService::class)->post($note, $this->user);

        /*
         * 100.00 USD at the note's own 3.0, not the invoice's 2.5. Pricing it at
         * 2.5 would also produce a balanced, plausible-looking entry - and would
         * erase the 50.00 the receivable is really worth, which is exactly what
         * realized FX has to be measured against at settlement.
         */
        $this->assertSame('300.0000', $posted->base_grand_total);

        $receivable = $posted->journal->lines
            ->firstWhere('account_id', $this->accounts['receivable']->getKey());

        $this->assertSame('300.0000', $receivable->credit);
        $this->assertSame('100.0000', $receivable->foreign_credit);
        $this->assertSame('3.0000000000', $receivable->exchange_rate);

        // 200.00 of base was originally carried for 100.00 USD; 50.00 of the
        // credit is exchange movement, not relief of the invoice.
        $this->assertSame(
            Money::of('300.0000')->minus(Money::of('250.0000'))->toDatabase(),
            Money::of('50.0000')->toDatabase()
        );
    }

    #[Test]
    public function every_journal_line_of_a_foreign_document_agrees_with_its_own_rate(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload([
                'currency_id' => $this->usd->getKey(),
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
            ])
        );

        $posted = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        foreach ($posted->journal->lines as $line) {
            $this->assertTrue($line->isForeignCurrency());
            $this->assertSame(self::RATE, $line->exchange_rate);

            // foreign x rate == base, exactly. The same identity the database CHECK
            // enforces, asserted here so a failure names the test rather than the
            // constraint.
            $this->assertSame(
                $line->amount()->toDatabase(),
                $line->exchangeRate()->applyTo($line->foreignAmount())->toDatabase()
            );
        }
    }

    #[Test]
    public function a_foreign_document_is_refused_entirely_rather_than_posted_half_converted(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $invoice = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            $this->user,
            $this->invoicePayload(['currency_id' => $this->usd->getKey()])
        );

        $before = Journal::query()->count();

        // Deactivating the rate row removes the ability to price the invoice at all.
        ExchangeRate::query()->update(['is_active' => false]);

        try {
            app(SalesInvoicePostingService::class)->post($invoice, $this->user);

            $this->fail('A document that cannot be priced must not post.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('USD to IDR', $e->validator->errors()->first('currency_id'));
        }

        $this->assertSame($before, Journal::query()->count());
        $this->assertTrue($invoice->fresh()->status->isDraft());
        $this->assertNull($invoice->fresh()->base_grand_total);
    }
}
