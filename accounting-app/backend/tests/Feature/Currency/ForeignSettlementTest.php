<?php

namespace Tests\Feature\Currency;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyFxSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\ExchangeRate;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\Currency\RealizedFxService;
use App\Services\Accounting\PaymentAllocationService;
use App\Services\Purchasing\PurchaseBillPostingService;
use App\Services\Purchasing\PurchaseBillService;
use App\Services\Purchasing\SupplierPaymentPostingService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Sales\CustomerReceiptPostingService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesInvoicePostingService;
use App\Services\Sales\SalesInvoiceService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settling a foreign-currency invoice or bill, and the realised exchange that
 * settlement produces.
 *
 * WHAT IS WORTH TESTING HERE
 *
 * A foreign settlement is the only place in this application where two rates meet.
 * Everywhere else the rate that prices a document is the same rate the ledger books
 * it at, so the arithmetic is a formality. Here the invoice was carried at one rate
 * and the cash converts at another, and the whole feature is the handling of the
 * gap between them.
 *
 * So the tests below are organised around the gap rather than around the happy path:
 *
 *  1. The receivable is relieved at what it was CARRIED at, not at the settlement
 *     rate. Relieving it at today's rate would balance the journal and destroy the
 *     gain - the money would simply vanish from the receivable with no FX line to
 *     explain it.
 *
 *  2. The difference between the two rates is an explicit third line, credited when
 *     the cash is worth more and debited when it is worth less.
 *
 *  3. Paying MORE than a payable carried is a loss, not a gain. This is the one that
 *     inverts, and an implementation that gets it wrong produces a balanced journal
 *     with the FX result exactly backwards - so it gets its own test.
 *
 *  4. A settlement at the carrying rate touches no FX account at all, because that is
 *     the correct answer rather than a missing one.
 *
 * And two rules that have nothing to do with rates but decide whether the feature is
 * usable: a settlement must be in the same currency as what it settles, and a
 * company that has not said where to post FX results cannot post a foreign one.
 */
class ForeignSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Currency $base;

    private Currency $usd;

    /** @var array<string, Account> */
    private array $accounts;

    private Account $fxGain;

    private Account $fxLoss;

    /** The rate the invoice and bill are raised at. */
    private const INVOICE_RATE = '2.5000000000';

    /** The rate the money is received and paid at, later. */
    private const SETTLEMENT_RATE = '3.0000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUserWithRole(RoleName::Accountant);

        $this->base = Currency::factory()->code('IDR')->create();
        $this->usd = Currency::factory()->code('USD')->create();

        $this->company = $this->createCompanyFor($this->user, ['currency_id' => $this->base->getKey()]);
        $this->accounts = $this->makeTransactionAccounts($this->company);

        CompanyFxSetting::factory()->configured($this->company)->create();

        $this->fxGain = Account::query()->where('company_id', $this->company->getKey())->where('code', '4900')->firstOrFail();
        $this->fxLoss = Account::query()->where('company_id', $this->company->getKey())->where('code', '5900')->firstOrFail();

        // Both months, once. makePeriodFor is not idempotent and the period name is
        // unique per company, so two months need two names - and a test that opened a
        // period from both a fixture and a helper would be testing the fixture.
        $this->makePeriodFor($this->company, '2026-06-15', 'Jun 2026');
        $this->makePeriodFor($this->company, '2026-07-15', 'Jul 2026');

        $this->quoteRate('2026-01-01', self::INVOICE_RATE);
        $this->quoteRate('2026-07-01', self::SETTLEMENT_RATE);
    }

    private function quoteRate(string $effectiveFrom, string $rate): ExchangeRate
    {
        return ExchangeRate::factory()->for($this->company)->create([
            'from_currency_id' => $this->usd->getKey(),
            'to_currency_id' => $this->base->getKey(),
            'effective_date' => $effectiveFrom,
            'rate' => $rate,
        ]);
    }

    private function usdCustomer(): Customer
    {
        return Customer::factory()->for($this->company)->create([
            'receivable_account_id' => $this->accounts['receivable']->getKey(),
        ]);
    }

    private function usdSupplier(): Supplier
    {
        return Supplier::factory()->for($this->company)->create([
            'payable_account_id' => $this->accounts['payable']->getKey(),
        ]);
    }

    /**
     * Post a USD invoice for the given foreign amount.
     */
    private function usdInvoice(float $quantity, float $unitPrice, string $date = '2026-06-15'): SalesInvoice
    {
        $invoice = app(SalesInvoiceService::class)->createDraft($this->company, $this->user, [
            'customer_id' => $this->usdCustomer()->getKey(),
            'invoice_date' => $date,
            'due_date' => '2026-09-15',
            'currency_id' => $this->usd->getKey(),
            'lines' => [
                [
                    'description' => 'Widget',
                    'quantity' => (string) $quantity,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'discount' => '0',
                    'tax_rate' => '0',
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ],
            ],
        ]);

        return app(SalesInvoicePostingService::class)->post($invoice, $this->user);
    }

    private function usdBill(float $quantity, float $unitCost, string $date = '2026-06-15'): PurchaseBill
    {
        $bill = app(PurchaseBillService::class)->createDraft($this->company, $this->user, [
            'supplier_id' => $this->usdSupplier()->getKey(),
            'bill_date' => $date,
            'due_date' => '2026-09-15',
            'currency_id' => $this->usd->getKey(),
            'lines' => [
                [
                    'description' => 'Materials',
                    'quantity' => (string) $quantity,
                    'unit_cost' => number_format($unitCost, 2, '.', ''),
                    'discount' => '0',
                    'tax_rate' => '0',
                    'expense_account_id' => $this->accounts['expense']->getKey(),
                ],
            ],
        ]);

        return app(PurchaseBillPostingService::class)->post($bill, $this->user);
    }

    /**
     * Settle an invoice with a receipt in the given currency and foreign amount.
     */
    private function usdSettlement(
        SalesInvoice $invoice,
        ?Currency $currency,
        float $amount,
        string $date = '2026-07-15'
    ): CustomerReceipt {
        $receipt = app(CustomerReceiptService::class)->createDraft($this->company, $this->user, [
            'customer_id' => $invoice->customer_id,
            'receipt_date' => $date,
            'amount' => number_format($amount, 2, '.', ''),
            'payment_account_id' => $this->accounts['cash']->getKey(),
            'currency_id' => $currency?->getKey(),
            'allocations' => [
                ['sales_invoice_id' => $invoice->getKey(), 'amount' => number_format($amount, 2, '.', '')],
            ],
        ]);

        return app(CustomerReceiptPostingService::class)->post($receipt, $this->user);
    }

    private function usdPayment(
        PurchaseBill $bill,
        ?Currency $currency,
        float $amount,
        string $date = '2026-07-15'
    ): SupplierPayment {
        $payment = app(SupplierPaymentService::class)->createDraft($this->company, $this->user, [
            'supplier_id' => $bill->supplier_id,
            'payment_date' => $date,
            'amount' => number_format($amount, 2, '.', ''),
            'payment_account_id' => $this->accounts['cash']->getKey(),
            'currency_id' => $currency?->getKey(),
            'allocations' => [
                ['purchase_bill_id' => $bill->getKey(), 'amount' => number_format($amount, 2, '.', '')],
            ],
        ]);

        return app(SupplierPaymentPostingService::class)->post($payment, $this->user);
    }

    /*
    |--------------------------------------------------------------------------
    | The entry
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_full_settlement_at_a_higher_rate_books_a_gain(): void
    {
        $invoice = $this->usdInvoice(2, 100);   // 200.00 USD, carried at 2.5 = 500.00

        $this->assertSame('500.0000', $invoice->base_grand_total);

        $receipt = $this->usdSettlement($invoice, $this->usd, 200);   // at 3.0 = 600.00

        $lines = $receipt->journal->lines;

        $cash = $lines->firstWhere('account_id', $this->accounts['cash']->getKey());
        $receivable = $lines->firstWhere('account_id', $this->accounts['receivable']->getKey());
        $gain = $lines->firstWhere('account_id', $this->fxGain->getKey());

        /*
         *   Dr  Cash          600.0000   (200.00 USD at 3.0)
         *   Cr  Receivable   500.0000   (what the invoice was CARRIED at)
         *   Cr  FX Gain      100.0000
         */
        $this->assertSame('600.0000', $cash->debit);
        $this->assertSame('200.0000', $cash->foreign_debit);
        $this->assertSame(self::SETTLEMENT_RATE, $cash->exchange_rate);

        // The whole point: the receivable is relieved at 500, not 600.
        $this->assertSame('500.0000', $receivable->credit);
        $this->assertNull($receivable->foreign_credit);
        $this->assertNull($receivable->exchange_rate);

        $this->assertSame('100.0000', $gain->credit);
        $this->assertSame('0.0000', $gain->debit);

        $this->assertCount(3, $lines);
        $this->assertNull($lines->firstWhere('account_id', $this->fxLoss->getKey()));
    }

    #[Test]
    public function a_settlement_at_a_lower_rate_books_a_loss(): void
    {
        $invoice = $this->usdInvoice(2, 100);   // 200.00 USD, carried at 2.5 = 500.00

        // Re-quote so the later rate is BELOW the invoice's.
        ExchangeRate::query()
            ->where('effective_date', '2026-07-01')
            ->update(['rate' => '2.0000000000']);

        $receipt = $this->usdSettlement($invoice, $this->usd, 200);   // at 2.0 = 400.00

        $lines = $receipt->journal->lines;

        $cash = $lines->firstWhere('account_id', $this->accounts['cash']->getKey());
        $receivable = $lines->firstWhere('account_id', $this->accounts['receivable']->getKey());
        $loss = $lines->firstWhere('account_id', $this->fxLoss->getKey());

        /*
         *   Dr  Cash          400.0000
         *   Dr  FX Loss       100.0000
         *   Cr  Receivable   500.0000
         */
        $this->assertSame('400.0000', $cash->debit);
        $this->assertSame('500.0000', $receivable->credit);
        $this->assertSame('100.0000', $loss->debit);
        $this->assertSame('0.0000', $loss->credit);

        $this->assertNull($lines->firstWhere('account_id', $this->fxGain->getKey()));
    }

    #[Test]
    public function a_settlement_at_the_carrying_rate_touches_no_fx_account(): void
    {
        $invoice = $this->usdInvoice(2, 100);

        // Same rate before and after, so the difference is exactly zero.
        ExchangeRate::query()
            ->where('effective_date', '2026-07-01')
            ->update(['rate' => self::INVOICE_RATE]);

        $receipt = $this->usdSettlement($invoice, $this->usd, 200);

        $lines = $receipt->journal->lines;

        $this->assertCount(2, $lines);
        $this->assertSame('500.0000', $lines->firstWhere('account_id', $this->accounts['cash']->getKey())->debit);
        $this->assertSame('500.0000', $lines->firstWhere('account_id', $this->accounts['receivable']->getKey())->credit);

        // A zero-value gain line would be a different thing entirely, and the
        // journal's own CHECK constraints reject it. Not producing one is the answer.
        $this->assertNull($lines->firstWhere('account_id', $this->fxGain->getKey()));
        $this->assertNull($lines->firstWhere('account_id', $this->fxLoss->getKey()));
    }

    #[Test]
    public function a_partial_settlement_measures_the_gain_on_the_allocated_slice_only(): void
    {
        $invoice = $this->usdInvoice(2, 100);   // 200.00 USD carried at 2.5 = 500.00

        $receipt = $this->usdSettlement($invoice, $this->usd, 100);   // half, at 3.0 = 300.00

        $lines = $receipt->journal->lines;

        /*
         *   Dr  Cash          300.0000   (100.00 USD at 3.0)
         *   Cr  Receivable   250.0000   (100.00 x 2.5, not half of 500 by accident)
         *   Cr  FX Gain       50.0000
         */
        $this->assertSame('300.0000', $lines->firstWhere('account_id', $this->accounts['cash']->getKey())->debit);
        $this->assertSame('250.0000', $lines->firstWhere('account_id', $this->accounts['receivable']->getKey())->credit);
        $this->assertSame('50.0000', $lines->firstWhere('account_id', $this->fxGain->getKey())->credit);

        // And the invoice is half settled, not fully.
        $this->assertSame(
            '100.0000',
            app(PaymentAllocationService::class)->outstandingFor($invoice->fresh())->toDatabase()
        );
    }

    #[Test]
    public function an_allocation_records_the_carrying_base_it_was_measured_against(): void
    {
        $invoice = $this->usdInvoice(2, 100);

        $this->usdSettlement($invoice, $this->usd, 100);

        $allocation = $invoice->fresh()->allocations()->firstOrFail();

        /*
         * The allocation row is where the carrying value is stored, and it is what
         * the gain was measured against. Leaving it NULL would make
         * CustomerReceiptAllocation::baseAmount() fall back to the foreign amount,
         * which is the "no exchange difference" answer and would be silently wrong
         * for every partial settlement in a company whose rate moved.
         */
        $this->assertSame('100.0000', $allocation->amount);
        $this->assertTrue($allocation->hasBaseAmount());
        $this->assertSame('250.0000', $allocation->base_amount);
        $this->assertSame('250.0000', $allocation->baseAmount()->toDatabase());
    }

    #[Test]
    public function the_receipt_records_the_base_amount_the_ledger_booked(): void
    {
        $invoice = $this->usdInvoice(2, 100);

        $receipt = $this->usdSettlement($invoice, $this->usd, 200);

        $this->assertSame('600.0000', $receipt->base_amount);
        $this->assertSame('600.0000', $receipt->baseAmount()->toDatabase());
        $this->assertSame(self::SETTLEMENT_RATE, $receipt->exchange_rate);
    }

    /*
    |--------------------------------------------------------------------------
    | The direction that inverts: payables
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function paying_more_than_a_payable_carried_is_a_loss_not_a_gain(): void
    {
        $bill = $this->usdBill(2, 100);   // 200.00 USD, carried at 2.5 = 500.00

        $payment = $this->usdPayment($bill, $this->usd, 200);   // at 3.0 = 600.00

        $lines = $payment->journal->lines;

        /*
         *   Dr  Payable       500.0000
         *   Dr  FX Loss       100.0000
         *   Cr  Cash          600.0000
         *
         * Paying 100.00 more base than the liability was carried at is a LOSS. The
         * receivable case with the same two numbers is a gain, which is exactly why
         * this direction is easy to get backwards: the entry balances either way and
         * only the sign of the difference says which.
         */
        $this->assertSame('500.0000', $lines->firstWhere('account_id', $this->accounts['payable']->getKey())->debit);
        $this->assertSame('600.0000', $lines->firstWhere('account_id', $this->accounts['cash']->getKey())->credit);
        $this->assertSame('100.0000', $lines->firstWhere('account_id', $this->fxLoss->getKey())->debit);
        $this->assertNull($lines->firstWhere('account_id', $this->fxGain->getKey()));
    }

    #[Test]
    public function paying_less_than_a_payable_carried_is_a_gain(): void
    {
        $bill = $this->usdBill(2, 100);

        ExchangeRate::query()
            ->where('effective_date', '2026-07-01')
            ->update(['rate' => '2.0000000000']);

        $payment = $this->usdPayment($bill, $this->usd, 200);   // at 2.0 = 400.00

        $lines = $payment->journal->lines;

        /*
         *   Dr  Payable       500.0000
         *   Cr  Cash          400.0000
         *   Cr  FX Gain       100.0000
         */
        $this->assertSame('500.0000', $lines->firstWhere('account_id', $this->accounts['payable']->getKey())->debit);
        $this->assertSame('400.0000', $lines->firstWhere('account_id', $this->accounts['cash']->getKey())->credit);
        $this->assertSame('100.0000', $lines->firstWhere('account_id', $this->fxGain->getKey())->credit);
        $this->assertNull($lines->firstWhere('account_id', $this->fxLoss->getKey()));
    }

    #[Test]
    public function a_payment_at_the_carrying_rate_is_a_plain_two_line_entry(): void
    {
        $bill = $this->usdBill(2, 100);

        ExchangeRate::query()
            ->where('effective_date', '2026-07-01')
            ->update(['rate' => self::INVOICE_RATE]);

        $payment = $this->usdPayment($bill, $this->usd, 200);

        $this->assertCount(2, $payment->journal->lines);
    }

    /*
    |--------------------------------------------------------------------------
    | Same-currency settlement
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_receipt_in_another_currency_cannot_settle_the_invoice(): void
    {
        $invoice = $this->usdInvoice(2, 100);

        try {
            $this->usdSettlement($invoice, $this->base, 200);

            $this->fail('A USD invoice must not be settled by an IDR receipt.');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('allocations.0.sales_invoice_id');

            $this->assertStringContainsString('IDR', $message);
            $this->assertStringContainsString($invoice->invoice_number, $message);
        }

        $this->assertSame(
            '200.0000',
            app(PaymentAllocationService::class)->outstandingFor($invoice->fresh())->toDatabase()
        );
    }

    #[Test]
    public function a_payment_in_another_currency_cannot_settle_the_bill(): void
    {
        $bill = $this->usdBill(2, 100);

        try {
            $this->usdPayment($bill, $this->base, 200);

            $this->fail('A USD bill must not be settled by an IDR payment.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('IDR', $e->validator->errors()->first('allocations.0.purchase_bill_id'));
        }
    }

    #[Test]
    public function re_denominating_a_draft_receipt_re_validates_its_allocations(): void
    {
        $invoice = $this->usdInvoice(2, 100);

        $receipt = app(CustomerReceiptService::class)->createDraft($this->company, $this->user, [
            'customer_id' => $invoice->customer_id,
            'receipt_date' => '2026-07-15',
            'amount' => '200.00',
            'payment_account_id' => $this->accounts['cash']->getKey(),
            'currency_id' => $this->usd->getKey(),
            'allocations' => [
                ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
            ],
        ]);

        $this->assertSame($this->usd->getKey(), $receipt->currency_id);

        try {
            app(CustomerReceiptService::class)->updateDraft($receipt, $this->company, [
                'currency_id' => $this->base->getKey(),
            ]);

            $this->fail('Re-denominating must re-check the allocations, not leave them stale.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('IDR', $e->validator->errors()->first('allocations.0.sales_invoice_id'));
        }
    }

    #[Test]
    public function a_receipt_for_a_currency_with_no_rate_is_refused_before_it_exists(): void
    {
        $invoice = $this->usdInvoice(2, 100);

        $eur = Currency::factory()->code('EUR')->create();

        try {
            $this->usdSettlement($invoice, $eur, 200);

            $this->fail('A currency that cannot be converted must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('EUR to IDR', $e->validator->errors()->first('currency_id'));
        }
    }

    #[Test]
    public function cash_in_a_currency_the_account_cannot_hold_is_refused(): void
    {
        $invoice = $this->usdInvoice(2, 100);

        $idrOnlyCash = Account::factory()->for($this->company)->asset()->create([
            'code' => '1010',
            'name' => 'IDR Cash',
            'currency_id' => $this->base->getKey(),
        ]);

        try {
            app(CustomerReceiptService::class)->createDraft($this->company, $this->user, [
                'customer_id' => $invoice->customer_id,
                'receipt_date' => '2026-07-15',
                'amount' => '200.00',
                'payment_account_id' => $idrOnlyCash->getKey(),
                'currency_id' => $this->usd->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
                ],
            ]);

            $this->fail('USD cash cannot be received into an account declared IDR.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('IDR Cash', $e->validator->errors()->first('payment_account_id'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration the company has to supply
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_company_without_fx_accounts_cannot_post_a_foreign_settlement(): void
    {
        CompanyFxSetting::query()
            ->where('company_id', $this->company->getKey())
            ->update(['realized_gain_account_id' => null, 'realized_loss_account_id' => null]);

        $invoice = $this->usdInvoice(2, 100);

        $journalsBefore = Journal::query()->count();

        try {
            $this->usdSettlement($invoice, $this->usd, 200);

            $this->fail('A foreign settlement needs somewhere to post the difference.');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('fx_settings');

            $this->assertStringContainsString('realised FX', $message);
        }

        /*
         * Refused WHOLE. A settlement that booked the cash and the receivable but
         * could not book the 100.00 of exchange would leave the journal short by
         * exactly the amount nobody could name, and it would balance only if the
         * receivable absorbed it - which is the one outcome that destroys the figure
         * this feature exists to produce.
         */
        $this->assertSame($journalsBefore, Journal::query()->count());
        $this->assertTrue($invoice->fresh()->status->isPosted());
        $this->assertSame(
            '200.0000',
            app(PaymentAllocationService::class)->outstandingFor($invoice->fresh())->toDatabase()
        );
    }

    #[Test]
    public function the_fx_accounts_of_another_company_are_not_used(): void
    {
        $otherUser = $this->createUserWithRole(RoleName::Accountant);
        $other = $this->createCompanyFor($otherUser, ['currency_id' => $this->base->getKey()]);

        // A gain/loss pair belonging to a DIFFERENT company must not be reachable.
        CompanyFxSetting::query()
            ->where('company_id', $other->getKey())
            ->update(['realized_gain_account_id' => null, 'realized_loss_account_id' => null]);

        $this->assertTrue(app(RealizedFxService::class)->canPostForeignSettlement($this->company));
        $this->assertFalse(app(RealizedFxService::class)->canPostForeignSettlement($other));
    }

    /*
    |--------------------------------------------------------------------------
    | Regression: a base-currency settlement is untouched
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_base_currency_settlement_is_posted_exactly_as_before(): void
    {
        $invoice = app(SalesInvoiceService::class)->createDraft($this->company, $this->user, [
            'customer_id' => $this->usdCustomer()->getKey(),
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'lines' => [
                [
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_price' => '200.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ],
            ],
        ]);

        $invoice = app(SalesInvoicePostingService::class)->post($invoice, $this->user);

        $receipt = app(CustomerReceiptService::class)->createDraft($this->company, $this->user, [
            'customer_id' => $invoice->customer_id,
            'receipt_date' => '2026-07-15',
            'amount' => '200.00',
            'payment_account_id' => $this->accounts['cash']->getKey(),
            'allocations' => [
                ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
            ],
        ]);

        $receipt = app(CustomerReceiptPostingService::class)->post($receipt, $this->user);

        $this->assertNull($receipt->currency_id);
        $this->assertNull($receipt->exchange_rate);

        $lines = $receipt->journal->lines;

        $this->assertCount(2, $lines);
        $this->assertSame('200.0000', $lines->firstWhere('account_id', $this->accounts['cash']->getKey())->debit);
        $this->assertSame('200.0000', $lines->firstWhere('account_id', $this->accounts['receivable']->getKey())->credit);

        foreach ($lines as $line) {
            $this->assertNull($line->currency_id);
            $this->assertNull($line->foreign_debit);
            $this->assertNull($line->exchange_rate);
        }

        $this->assertSame('200.0000', $receipt->base_amount);

        // And the settlement lifecycle is unchanged: fully settled, still base.
        $this->assertSame(
            '0.0000',
            app(PaymentAllocationService::class)->outstandingFor($invoice->fresh())->toDatabase()
        );
        $this->assertNull($receipt->fresh()->currency_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Audit: a realised FX posting is a financial event
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_receipt_that_realises_fx_is_recorded_in_the_audit_trail(): void
    {
        $invoice = $this->usdInvoice(2, 100);          // 200.00 USD carried at 2.5
        $receipt = $this->usdSettlement($invoice, $this->usd, 200);   // settled at 3.0

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::Posted->value,
            'auditable_type' => $receipt->getMorphClass(),
            'auditable_id' => $receipt->getKey(),
            'company_id' => $this->company->getKey(),
            'actor_id' => $this->user->getKey(),
        ]);
    }

    #[Test]
    public function a_payment_that_realises_fx_is_recorded_in_the_audit_trail(): void
    {
        $bill = $this->usdBill(2, 100);                // 200.00 USD carried at 2.5
        $payment = $this->usdPayment($bill, $this->usd, 200);   // paid at 3.0 -> a loss

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::Posted->value,
            'auditable_type' => $payment->getMorphClass(),
            'auditable_id' => $payment->getKey(),
            'company_id' => $this->company->getKey(),
            'actor_id' => $this->user->getKey(),
        ]);
    }

    #[Test]
    public function a_foreign_settlement_at_the_carrying_rate_writes_no_fx_audit_row(): void
    {
        // Settle at the same rate the invoice was carried at: the journal balances
        // with no FX line, so there is no realised FX event to record.
        ExchangeRate::query()
            ->where('effective_date', '2026-07-01')
            ->update(['rate' => self::INVOICE_RATE]);

        $invoice = $this->usdInvoice(2, 100);
        $receipt = $this->usdSettlement($invoice, $this->usd, 200);

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action', AuditAction::Posted->value)
                ->where('auditable_type', $receipt->getMorphClass())
                ->where('auditable_id', $receipt->getKey())
                ->count(),
        );
    }
}
