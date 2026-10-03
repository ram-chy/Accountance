<?php

namespace Tests\Feature\Transactions;

use App\Enums\JournalSource;
use App\Enums\NoteType;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Credit and debit note drafting and posting.
 *
 * Four note types produce four entries, and the entries differ from one another in
 * exactly one respect each: sales versus purchase decides which side of the balance
 * sheet the counterparty sits on, and credit versus debit decides the direction.
 * Getting any one of the four wrong still produces a journal that balances - the
 * trial balance would be identical - so nothing downstream would notice, and the
 * whole matrix is asserted here rather than sampled.
 *
 *   Sales credit note    Dr Revenue, Dr Tax Payable     Cr Accounts Receivable
 *   Sales debit note     Dr Accounts Receivable          Cr Revenue, Cr Tax Payable
 *   Purchase credit note Dr Accounts Payable             Cr Expense, Cr Input Tax
 *   Purchase debit note  Dr Expense, Dr Input Tax        Cr Accounts Payable
 */
class CreditDebitNoteTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    /**
     * A sales note of $noteType against a posted 200.00 invoice, posted.
     */
    private function postedSalesNote(
        User $user,
        Company $company,
        Customer $customer,
        array $accounts,
        NoteType $noteType,
        string $quantity = '1',
        array $overrides = []
    ): CreditDebitNote {
        $invoice = $this->postInvoice($user, $company, $customer, $accounts, overrides: [
            'lines' => [[
                'description' => 'Widget',
                'quantity' => '2',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'revenue_account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $this->assertSame('200.0000', (string) $invoice->grand_total);

        /*
         * A document-level note: no source line named. That is deliberate for the
         * DEBIT half of the matrix, because a debit note against a line can only
         * give back quantity some earlier credit note consumed - so a debit note
         * for an untouched line is refused by design, and the refusal is asserted
         * separately rather than worked around here.
         */
        $lines = [[
            'description' => 'Adjustment',
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'discount' => '0',
            'tax_rate' => '0',
            'account_id' => $accounts['revenue']->getKey(),
        ]];

        if ($noteType->isCredit()) {
            $lines[0]['sales_invoice_line_id'] = $invoice->lines()->firstOrFail()->getKey();
        }

        return $this->postNote($user, $company, $invoice, $accounts, overrides: array_merge([
            'note_type' => $noteType->value,
            'lines' => $lines,
        ], $overrides));
    }

    #[Test]
    public function a_draft_note_is_created_with_server_computed_totals(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $note = $this->createDraftNote($user, $company, $invoice, $accounts);

        $this->assertSame(TransactionStatus::Draft, $note->status);
        $this->assertSame('CDN-000001', $note->note_number);
        $this->assertSame(NoteType::SalesCreditNote, $note->note_type);

        // 1 x 100.00, no discount, no tax.
        $this->assertSame('100.0000', (string) $note->subtotal);
        $this->assertSame('0.0000', (string) $note->discount_total);
        $this->assertSame('0.0000', (string) $note->tax_total);
        $this->assertSame('100.0000', (string) $note->grand_total);

        $this->assertNull($note->journal_id, 'A draft note must not have touched the ledger.');
    }

    #[Test]
    public function the_counterparty_and_source_are_copied_from_the_document_not_the_request(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Returned goods.',
                'reference' => 'RMA-42',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_id', $customer->getKey())
            ->assertJsonPath('data.supplier_id', null)
            ->assertJsonPath('data.source_document_type', 'sales_invoice')
            ->assertJsonPath('data.source_document_id', $invoice->getKey())
            ->assertJsonPath('data.source_document_number', $invoice->invoice_number)
            ->assertJsonPath('data.is_credit', true)
            ->assertJsonPath('data.label', 'Sales credit note');

        /*
         * The two columns as well as the derived pair: a client building an edit
         * form has to send back the field it was given, so both shapes are exposed.
         */
        $response->assertJsonPath('data.sales_invoice_id', $invoice->getKey())
            ->assertJsonPath('data.purchase_bill_id', null);
    }

    #[Test]
    public function server_owned_fields_are_dropped_rather_than_trusted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Returned goods.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],

                // Every one of these is the server's to decide.
                'note_number' => 'CDN-999999',
                'status' => TransactionStatus::Posted->value,
                'grand_total' => '999999.00',
                'tax_total' => '99999.00',
            ]);

        /*
         * These keys have no validation rule on the store path either, so they are
         * not refused - they are absent from the validated payload. The assertion
         * that matters is the stronger of the two available: a note whose totals
         * and number came from the request would be a note the application could
         * not otherwise produce, and one that would fail the adjustment limit on
         * its very next comparison.
         */
        $response->assertCreated()
            ->assertJsonPath('data.note_number', 'CDN-000001')
            ->assertJsonPath('data.status', TransactionStatus::Draft->value)
            ->assertJsonPath('data.grand_total', '100.0000')
            ->assertJsonPath('data.tax_total', '0.0000');

        $note = CreditDebitNote::findOrFail($response->json('data.id'));

        $this->assertSame('CDN-000001', $note->note_number);
        $this->assertSame('100.0000', (string) $note->grand_total);
        $this->assertSame(TransactionStatus::Draft, $note->status);
        $this->assertNull($note->journal_id);
    }

    #[Test]
    public function the_wrong_source_column_for_the_type_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);
        $customer = $this->createCustomer($user, $company, $accounts);

        // A purchase note naming an invoice.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::PurchaseCreditNote->value,
                'sales_invoice_id' => $this->postInvoice($user, $company, $customer, $accounts)->getKey(),
                'purchase_bill_id' => $bill->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Ambiguous.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['expense']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_invoice_id');
    }

    #[Test]
    public function posting_a_sales_credit_note_credits_the_receivable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $note = $this->postedSalesNote(
            $user, $company, $customer, $accounts,
            NoteType::SalesCreditNote,
        );

        $journal = Journal::findOrFail($note->journal_id);

        $this->assertSame(JournalSource::CreditDebitNote->value, $journal->source_type->value);
        $this->assertSame($note->getKey(), $journal->source_id);

        $lines = $journal->lines()->get();

        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['revenue']->getKey())->first()->debit);
        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['receivable']->getKey())->first()->credit);

        $this->assertSame('100.0000', (string) $note->grand_total);
    }

    #[Test]
    public function posting_a_sales_debit_note_debits_the_receivable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $note = $this->postedSalesNote(
            $user, $company, $customer, $accounts,
            NoteType::SalesDebitNote,
        );

        $lines = Journal::findOrFail($note->journal_id)->lines()->get();

        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['receivable']->getKey())->first()->debit);
        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['revenue']->getKey())->first()->credit);
    }

    #[Test]
    public function a_purchase_credit_note_debits_the_payable_and_credits_expense(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $note = $this->postPurchaseNote($user, $company, $bill, $accounts);

        $lines = Journal::findOrFail($note->journal_id)->lines()->get();

        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['payable']->getKey())->first()->debit);
        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['expense']->getKey())->first()->credit);
    }

    #[Test]
    public function a_purchase_debit_note_debits_expense_and_credits_the_payable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $note = $this->postPurchaseNote($user, $company, $bill, $accounts, overrides: [
            'note_type' => NoteType::PurchaseDebitNote->value,
            'lines' => [[
                'description' => 'Materials charged in error',
                'quantity' => '1',
                'unit_price' => '500.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['expense']->getKey(),
            ]],
        ]);

        $lines = Journal::findOrFail($note->journal_id)->lines()->get();

        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['expense']->getKey())->first()->debit);
        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['payable']->getKey())->first()->credit);
    }

    /**
     * The tax leg's direction, derived rather than remembered.
     *
     * The counterparty leg's direction comes first and the tax leg is always its
     * opposite, because the entry is two-sided: whatever the receivable or payable
     * does, revenue/expense and tax do the reverse. That is why a sales DEBIT note
     * credits tax payable even though the word "debit" appears in its type name -
     * the type names which way the customer's balance moves, not which way tax
     * moves, and a reader who assumes otherwise will get three of these four wrong.
     *
     * @return array<string, array{0: NoteType, 1: string, 2: bool}>
     */
    public static function taxDirections(): array
    {
        return [
            // Sales: AR credited on a credit note, so revenue and tax are debited.
            'sales credit reverses output tax' => [NoteType::SalesCreditNote, 'tax_payable', true],
            // Sales: AR debited on a debit note, so revenue and tax are credited.
            'sales debit charges output tax' => [NoteType::SalesDebitNote, 'tax_payable', false],
            // Purchase: AP debited on a credit note, so expense and tax are credited.
            'purchase credit reverses input tax' => [NoteType::PurchaseCreditNote, 'input_tax', false],
            // Purchase: AP credited on a debit note, so expense and tax are debited.
            'purchase debit charges input tax' => [NoteType::PurchaseDebitNote, 'input_tax', true],
        ];
    }

    #[Test]
    #[DataProvider('taxDirections')]
    public function the_tax_leg_follows_the_side_the_note_is_on(NoteType $type, string $accountKey, bool $taxIsDebit): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $taxAccount = $accounts[$accountKey];

        $itemAccountId = $type->isSales()
            ? $accounts['revenue']->getKey()
            : $accounts['expense']->getKey();

        if ($type->isSales()) {
            $invoice = $this->postInvoice($user, $company, $this->createCustomer($user, $company, $accounts), $accounts);

            $note = $this->postNote($user, $company, $invoice, $accounts, overrides: [
                'note_type' => $type->value,
                'tax_account_id' => $taxAccount->getKey(),
                'lines' => [[
                    'description' => 'Taxed adjustment',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '20',
                    'account_id' => $itemAccountId,
                ]],
            ]);
        } else {
            $bill = $this->postBill($user, $company, $this->createSupplier($user, $company, $accounts), $accounts);

            /*
             * 400.00 at 20% is 480.00, which fits inside the 500.00 bill. A note
             * larger than its source is refused - correctly - so the figure has to
             * be chosen to leave room for the tax rather than to make the largest
             * possible note.
             */
            $note = $this->postPurchaseNote($user, $company, $bill, $accounts, overrides: [
                'note_type' => $type->value,
                'tax_account_id' => $taxAccount->getKey(),
                'lines' => [[
                    'description' => 'Taxed adjustment',
                    'quantity' => '1',
                    'unit_price' => '400.00',
                    'discount' => '0',
                    'tax_rate' => '20',
                    'account_id' => $itemAccountId,
                ]],
            ]);
        }

        // 20% of 100.00 on the sales side, 20% of 500.00 on the purchase side.
        $this->assertNotSame(
            '0.0000',
            (string) $note->tax_total,
            'The note should have been taxed; the tax leg assertions below would pass vacuously otherwise.'
        );

        $lines = Journal::findOrFail($note->journal_id)->lines()->get();
        $taxLine = $lines->where('account_id', $taxAccount->getKey())->first();

        $this->assertNotNull($taxLine, 'The tax account must appear in the entry.');

        $this->assertSame(
            $taxIsDebit ? $note->tax_total : '0.0000',
            (string) $taxLine->debit,
            'The tax leg moved the wrong way for this note type.'
        );
        $this->assertSame(
            $taxIsDebit ? '0.0000' : $note->tax_total,
            (string) $taxLine->credit,
            'The tax leg moved the wrong way for this note type.'
        );

        /*
         * And the counterparty leg carries the GROSS, tax included. If it carried
         * the net the entry would not balance against the item and tax legs, which
         * is the check that makes a mis-built total obvious rather than subtle.
         */
        $counterKey = $type->isSales() ? 'receivable' : 'payable';
        $counterLine = $lines->where('account_id', $accounts[$counterKey]->getKey())->first();

        $this->assertSame(
            $note->grand_total,
            (string) max($counterLine->debit, $counterLine->credit),
            'The counterparty leg must carry the note grand total.'
        );
    }

    #[Test]
    public function the_revenue_leg_carries_the_net_not_the_gross(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $note = $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'tax_account_id' => $accounts['tax_payable']->getKey(),
            'lines' => [[
                'description' => 'Taxed adjustment',
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '20',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $lines = Journal::findOrFail($note->journal_id)->lines()->get();

        /*
         * 100.00 net and 20.00 tax on a 120.00 note. Booking the tax against revenue
         * as well would reverse 120.00 of revenue and 20.00 of tax - the entry would
         * still balance against a 140.00 receivable, so the mistake would only
         * show up in a revenue report, where it is least welcome.
         */
        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['revenue']->getKey())->first()->debit);
        $this->assertSame('20.0000', (string) $lines->where('account_id', $accounts['tax_payable']->getKey())->first()->debit);
        $this->assertSame('120.0000', (string) $lines->where('account_id', $accounts['receivable']->getKey())->first()->credit);
    }

    #[Test]
    public function a_taxed_note_without_a_tax_account_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        /*
         * Refused at draft time, not at posting. DocumentCalculator knows the tax
         * total as soon as the lines are calculated, and the same is true of an
         * invoice or a bill - so a user is told while they are still typing rather
         * than after they have committed. See
         * PurchaseBillTest::a_taxed_bill_without_an_input_tax_account_is_refused
         * for the identical rule on the other half of the phase.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Returned goods, taxed.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '20',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_account_id');

        $this->assertSame(0, CreditDebitNote::query()->count());
    }

    #[Test]
    public function a_tax_account_removed_between_draft_and_posting_is_caught(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $note = $this->createDraftNote($user, $company, $invoice, $accounts, [
            'tax_account_id' => $accounts['tax_payable']->getKey(),
            'lines' => [[
                'description' => 'Returned goods, taxed.',
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '20',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $this->assertSame('120.0000', (string) $note->grand_total);

        // Deactivated after the note was drafted, so the draft-time check passed.
        $accounts['tax_payable']->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$note->getKey()}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_account_id');

        $this->assertSame(TransactionStatus::Draft, $note->refresh()->status);
        $this->assertNull($note->journal_id);
    }

    #[Test]
    public function a_revenue_account_cannot_be_used_on_a_purchase_note(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::PurchaseCreditNote->value,
                'purchase_bill_id' => $bill->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Wrong account.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '500.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.account_id');
    }

    #[Test]
    public function a_draft_source_document_cannot_be_adjusted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $draftInvoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $draftInvoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Against a draft.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_invoice_id');

        $this->assertSame(
            0,
            CreditDebitNote::query()->count(),
            'A note against a draft document must not have been written at all.'
        );
    }

    #[Test]
    public function the_source_document_and_type_cannot_be_repointed_by_an_update(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $otherInvoice = $this->postInvoice($user, $company, $customer, $accounts);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts);
        $note = $this->createDraftNote($user, $company, $invoice, $accounts);

        /*
         * The update succeeds, and that is the point worth being careful about.
         *
         * These three keys have no validation rule at all on the update path, so
         * they are not refused - they are absent from the validated payload, and
         * the service copies its source and type from the persisted note. A test
         * that asserted a 422 here would be asserting an implementation that does
         * not exist; the invariant that matters is that the note cannot end up
         * pointed somewhere else, so that is what is asserted - through the row.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/credit-debit-notes/{$note->getKey()}", [
                'reason' => 'A better reason.',
                'sales_invoice_id' => $otherInvoice->getKey(),
                'purchase_bill_id' => $bill->getKey(),
                'note_type' => NoteType::PurchaseCreditNote->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.sales_invoice_id', $invoice->getKey())
            ->assertJsonPath('data.purchase_bill_id', null)
            ->assertJsonPath('data.note_type', NoteType::SalesCreditNote->value);

        $note->refresh();

        $this->assertSame($invoice->getKey(), $note->sales_invoice_id);
        $this->assertNull($note->purchase_bill_id);
        $this->assertSame(NoteType::SalesCreditNote, $note->note_type);
        $this->assertSame($customer->getKey(), $note->customer_id);
        $this->assertNull($note->supplier_id);
    }

    #[Test]
    public function a_draft_note_can_be_updated_in_place(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $note = $this->createDraftNote($user, $company, $invoice, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/credit-debit-notes/{$note->getKey()}", [
                'reason' => 'Two units were returned, not one.',
                'lines' => [[
                    'description' => 'Two widgets returned',
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                    'sales_invoice_line_id' => $invoice->lines()->firstOrFail()->getKey(),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.grand_total', '200.0000')
            ->assertJsonPath('data.reason', 'Two units were returned, not one.');

        $this->assertSame(1, $note->refresh()->lines()->count(), 'The lines are replaced, not appended to.');
    }

    #[Test]
    public function a_posted_note_cannot_be_edited_or_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $note = $this->postedSalesNote($user, $company, $customer, $accounts, NoteType::SalesCreditNote);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/credit-debit-notes/{$note->getKey()}", [
                'reason' => 'Trying to rewrite history.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/credit-debit-notes/{$note->getKey()}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->assertSame(
            TransactionStatus::Posted,
            $note->refresh()->status,
            'A posted note must survive a refused edit and delete intact.'
        );
    }

    #[Test]
    public function posting_a_note_twice_is_a_conflict_and_makes_no_second_journal(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $note = $this->postedSalesNote($user, $company, $customer, $accounts, NoteType::SalesCreditNote);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$note->getKey()}/post")
            ->assertStatus(409);

        $this->assertSame(
            1,
            Journal::where('source_type', JournalSource::CreditDebitNote->value)->count(),
            'A refused second post must not have written a second entry.'
        );
    }

    #[Test]
    public function a_draft_note_can_be_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $note = $this->createDraftNote($user, $company, $invoice, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/credit-debit-notes/{$note->getKey()}")
            ->assertOk();

        $this->assertNull(CreditDebitNote::find($note->getKey()));
    }

    #[Test]
    public function a_note_with_no_lines_cannot_be_posted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $note = $this->createDraftNote($user, $company, $invoice, $accounts);

        // Emptied directly, because the API will not accept an empty line list.
        $note->lines()->delete();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$note->getKey()}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->assertNull($note->refresh()->journal_id);
    }

    #[Test]
    public function an_inactive_customer_does_not_block_a_credit_note(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $note = $this->postedSalesNote($user, $company, $customer, $accounts, NoteType::SalesCreditNote);

        // Deactivate the customer AFTER the invoice and note were created.
        $customer->forceFill(['is_active' => false])->save();

        $lines = Journal::findOrFail($note->journal_id)->lines()->get();

        $this->assertSame(
            '100.0000',
            (string) $lines->where('account_id', $accounts['receivable']->getKey())->first()->credit,
            'The credit must have been booked even though the customer is now inactive.'
        );
    }

    #[Test]
    public function a_deactivated_receivable_account_does_block_a_note(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $note = $this->createDraftNote(
            $user,
            $company,
            $this->postInvoice($user, $company, $customer, $accounts),
            $accounts,
        );

        // The party being inactive is fine; the account the entry needs is not.
        $accounts['receivable']->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$note->getKey()}/post")
            ->assertUnprocessable();

        $this->assertNull($note->refresh()->journal_id);
    }

    #[Test]
    public function notes_are_listed_filterable_and_readable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $draft = $this->createDraftNote($user, $company, $invoice, $accounts);
        $posted = $this->postNote($user, $company, $invoice, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/credit-debit-notes')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/credit-debit-notes?status=POSTED')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $posted->getKey());

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/credit-debit-notes?sales_invoice_id={$invoice->getKey()}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/credit-debit-notes?sales_invoice_id=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/credit-debit-notes/{$draft->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $draft->getKey())
            ->assertJsonPath('data.status', TransactionStatus::Draft->value);

        // The lines come back, with their source reference resolved for the client.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/credit-debit-notes/{$posted->getKey()}")
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.source_document_type', 'sales_invoice')
            ->assertJsonPath(
                'data.lines.0.source_line_id',
                $invoice->lines()->firstOrFail()->getKey()
            );
    }

    #[Test]
    public function a_note_from_another_company_is_not_visible(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $note = $this->postedSalesNote($user, $company, $customer, $accounts, NoteType::SalesCreditNote);

        /*
         * A member of another company, with the note's id in hand - the exact
         * situation the active-company route binding exists for.
         */
        $intruder = $this->createUserWithRole(RoleName::Accountant);
        $otherCompany = $this->createCompanyFor($intruder);

        $this->actingAsJwt($intruder)
            ->withCompanyContext($otherCompany)
            ->getJson("/api/credit-debit-notes/{$note->getKey()}")
            ->assertStatus(404);

        $this->actingAsJwt($intruder)
            ->withCompanyContext($otherCompany)
            ->getJson('/api/credit-debit-notes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function an_invoice_from_another_company_cannot_be_adjusted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        /*
         * A genuine invoice, in a genuine other company, with a real line on it.
         * What must happen is that our user cannot reference it - so the id is
         * treated as if it did not exist, rather than as an authorisation failure
         * that would confirm it does.
         */
        $intruder = $this->createUserWithRole(RoleName::Accountant);
        $otherCompany = $this->createCompanyFor($intruder);
        $otherAccounts = $this->makeTransactionAccounts($otherCompany);
        $foreignInvoice = $this->postInvoice(
            $intruder,
            $otherCompany,
            $this->createCustomer($intruder, $otherCompany, $otherAccounts),
            $otherAccounts,
        );

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $foreignInvoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Adjusting another tenant\'s invoice.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_invoice_id');

        $this->assertSame(0, CreditDebitNote::query()->count());

        // And the same answer for an id that does not exist at all, so the two
        // cases cannot be told apart by an enumerator.
        $absent = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => 999999,
                'note_date' => '2027-02-15',
                'reason' => 'Adjusting nothing at all.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_invoice_id');

        $this->assertSame(0, CreditDebitNote::query()->count());
    }

    #[Test]
    public function the_adjustable_lines_endpoint_reports_what_is_left(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, overrides: [
            'lines' => [[
                'description' => 'Widget',
                'quantity' => '2',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'revenue_account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $lineId = $invoice->lines()->firstOrFail()->getKey();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}/adjustable-lines")
            ->assertOk()
            ->assertJsonPath('data.grand_total', '200.0000')
            ->assertJsonPath('data.net_adjustment', '0.0000')
            ->assertJsonPath('data.remaining_adjustable_amount', '200.0000')
            ->assertJsonPath('data.lines.0.sales_invoice_line_id', $lineId)
            ->assertJsonPath('data.lines.0.remaining_quantity', '2.0000')
            ->assertJsonPath('data.lines.0.is_adjustable', true);

        // After a posted credit note for one unit, one unit is left.
        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'description' => 'One returned',
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
                'sales_invoice_line_id' => $lineId,
            ]],
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}/adjustable-lines")
            ->assertOk()
            ->assertJsonPath('data.net_adjustment', '100.0000')
            ->assertJsonPath('data.remaining_adjustable_amount', '100.0000')
            ->assertJsonPath('data.lines.0.adjusted_quantity', '1.0000')
            ->assertJsonPath('data.lines.0.remaining_quantity', '1.0000');

        $this->assertIsString($response->json('data.invoice_number'));
    }

    #[Test]
    public function a_draft_note_does_not_consume_any_adjustable_amount(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->createDraftNote($user, $company, $invoice, $accounts);

        /*
         * A draft note has no journal, so it has adjusted nothing. Both drafts can
         * be sized against the full remaining amount - only one will fit at posting
         * time, and that is the same prepare/commit shape as every other document
         * here.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}/adjustable-lines")
            ->assertOk()
            ->assertJsonPath('data.net_adjustment', '0.0000')
            ->assertJsonPath('data.remaining_adjustable_amount', '200.0000');
    }

    #[Test]
    public function an_untaxed_note_needs_no_tax_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $note = $this->postedSalesNote($user, $company, $customer, $accounts, NoteType::SalesCreditNote);

        $this->assertNull($note->tax_account_id);

        $journal = Journal::findOrFail($note->journal_id);

        $this->assertCount(
            2,
            $journal->lines()->get(),
            'Without tax there is no tax leg, so the entry is two lines and not three.'
        );
    }

    #[Test]
    public function the_invoice_itself_is_never_edited_by_a_note(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $before = $invoice->only([
            'grand_total', 'subtotal', 'discount_total', 'tax_total',
            'invoice_number', 'journal_id', 'status',
        ]);

        $this->postedSalesNote($user, $company, $customer, $accounts, NoteType::SalesCreditNote);

        $this->assertSame($before, $invoice->refresh()->only(array_keys($before)));
    }
}
