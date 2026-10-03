<?php

namespace Tests\Feature\Transactions;

use App\Enums\NoteType;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adjustment limit, and what a posted note then does to everything else.
 *
 * The limit is the whole of Phase 11's arithmetic:
 *
 *     net      = SUM(posted credits) - SUM(posted debits)
 *     remaining = source grand_total - net
 *
 * and it is enforced against a SERVER-CALCULATED note total, re-checked under a row
 * lock on the source document at posting. Everything asserted here exists to make
 * that invariant true rather than merely plausible - including the cases where the
 * wrong implementation would still produce a balanced ledger, because a limit that
 * is off by a credit note leaves no trace anywhere in the accounts.
 *
 * The second half of the file is the integration: a note that posts has to change
 * the source document's settlement status, its balance_due, the outstanding
 * receivables report and the counterparty statement, or it has not adjusted
 * anything as far as the rest of the application is concerned.
 */
class CreditDebitNoteAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    /**
     * A posted invoice for 200.00 on one line of 2 x 100.00.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoiceFor200(User $user, Company $company, Customer $customer, array $accounts, array $overrides = []): SalesInvoice
    {
        return $this->postInvoice($user, $company, $customer, $accounts, overrides: array_merge([
            'lines' => [[
                'description' => 'Widget',
                'quantity' => '2',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'revenue_account_id' => $accounts['revenue']->getKey(),
            ]],
        ], $overrides));
    }

    #[Test]
    public function a_credit_note_larger_than_the_invoice_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Over-crediting attempt.',
                'lines' => [[
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
                // Server-owned, and ignored: the note is priced at 200.00 by the
                // calculator regardless of what the client asks it to be worth.
                'grand_total' => '5000.00',
            ])
            ->assertSuccessful(); // 200.00 exactly fits.

        // One cent more does not.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'One cent too much.',
                'lines' => [[
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                    // 2 x 100.00 plus a 1.00 discount is 199.00, and the unit
                    // price cannot express a cent on two units - so the excess is
                    // created with a third, partial line instead.
                ], [
                    'quantity' => '0.01',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grand_total');
    }

    #[Test]
    public function cumulative_credits_share_one_limit(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        // 150.00 of the 200.00 consumed.
        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '150.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        // 50.00 still fits, exactly.
        $this->createDraftNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '50.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        // And nothing more than that, ever again.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-20',
                'reason' => 'The last cent.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '50.01',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grand_total');
    }

    #[Test]
    public function a_debit_note_shares_the_same_remaining_limit_as_a_credit(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        /*
         * One rule for both directions, not one each. remaining = G - (credits -
         * debits), and a note of either kind must fit inside it - which is what
         * stops an invoice from being debited without limit and becoming an
         * arbitrarily large receivable supported by no document at all.
         *
         * With nothing posted yet, remaining is the full 200.00, so a debit note
         * for 100.00 is legitimate: it is a supplementary charge on goods the
         * customer really did take. It is drafted and not posted, so it changes
         * nothing below.
         */
        $this->createDraftNote($user, $company, $invoice, $accounts, overrides: [
            'note_type' => NoteType::SalesDebitNote->value,
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        // Credit 150.00 of the 200.00.
        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '150.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        // 50.00 remains, and a debit note for exactly that fits.
        $this->createDraftNote($user, $company, $invoice, $accounts, overrides: [
            'note_type' => NoteType::SalesDebitNote->value,
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '50.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        // And one cent more does not, in either direction.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesDebitNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-20',
                'reason' => 'One cent beyond the credit.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '50.01',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grand_total');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-20',
                'reason' => 'A credit beyond the credit.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '50.01',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grand_total');
    }

    #[Test]
    public function a_credit_note_may_not_exceed_the_quantity_on_the_line_it_names(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $lineId = $invoice->lines()->firstOrFail()->getKey();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Three units from a two-unit line.',
                'lines' => [[
                    'quantity' => '3',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                    'sales_invoice_line_id' => $lineId,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.sales_invoice_line_id');
    }

    #[Test]
    public function a_line_from_another_invoice_cannot_be_named(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);
        $otherInvoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $foreignLineId = $otherInvoice->lines()->firstOrFail()->getKey();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-15',
                'reason' => 'Borrowing another invoice\'s line.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                    'sales_invoice_line_id' => $foreignLineId,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.sales_invoice_line_id');

        /*
         * This is the check that stops invoice 2's quantity being consumed by
         * invoice 1's credit note, which would leave invoice 2 creditable twice
         * over. Two id columns and no cross-check is exactly the shape of bug that
         * produces it, so it is asserted rather than assumed.
         */
        $this->assertSame(0, CreditDebitNote::query()->count());
    }

    #[Test]
    public function a_debit_note_on_a_line_may_only_undo_a_credit_on_that_line(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $lineId = $invoice->lines()->firstOrFail()->getKey();

        // Credit one unit of the two.
        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
                'sales_invoice_line_id' => $lineId,
            ]],
        ]);

        // One unit of debit is exactly what the credit made available.
        $this->createDraftNote($user, $company, $invoice, $accounts, overrides: [
            'note_type' => NoteType::SalesDebitNote->value,
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
                'sales_invoice_line_id' => $lineId,
            ]],
        ]);

        // Two units is not.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesDebitNote->value,
                'sales_invoice_id' => $invoice->getKey(),
                'note_date' => '2027-02-20',
                'reason' => 'Debiting below zero.',
                'lines' => [[
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                    'sales_invoice_line_id' => $lineId,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.sales_invoice_line_id');
    }

    #[Test]
    public function the_limit_is_re_checked_at_posting_against_committed_notes(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        // A draft needs no period; a post does, because the note's journal is dated
        // from the note.
        $this->makePeriodFor($company, '2027-02-15', 'P 2027-02 notes');

        /*
         * Two drafts that were each legitimate when they were written: the second
         * claims the whole 200.00, which is all the invoice was ever worth, and it
         * asked nothing the first one had not already been told is available - it
         * simply asked before the answer had changed.
         *
         * Neither of them is wrong and neither client misbehaved, which is the
         * whole reason the posting path has to re-check rather than trust what the
         * draft once knew.
         */
        $fullCredit = [[
            'quantity' => '2',
            'unit_price' => '100.00',
            'discount' => '0',
            'tax_rate' => '0',
            'account_id' => $accounts['revenue']->getKey(),
        ]];

        $first = $this->createDraftNote($user, $company, $invoice, $accounts, [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '150.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);
        $second = $this->createDraftNote($user, $company, $invoice, $accounts, ['lines' => $fullCredit]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$first->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$second->getKey()}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grand_total');

        /*
         * The refused one is still a usable draft, not a casualty: it has no
         * journal and no status change, so the user can resize it and post it.
         * That is what makes the limit a guard rather than a dead end.
         */
        $this->assertSame(TransactionStatus::Draft, $second->refresh()->status);
        $this->assertNull($second->journal_id);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/credit-debit-notes/{$second->getKey()}", [
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '50.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$second->getKey()}/post")
            ->assertSuccessful();

        $this->assertSame(
            '0.0000',
            $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->getJson("/api/sales-invoices/{$invoice->getKey()}/adjustable-lines")
                ->json('data.remaining_adjustable_amount'),
        );
    }

    #[Test]
    public function a_credit_note_reduces_the_balance_due_of_an_unpaid_invoice(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '50.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '50.00'],
        ], '2027-01-20');

        $this->assertSame('150.0000', $this->balanceDue($user, $company, $invoice));

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '50.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        /*
         * The credit reduces what the customer owes without touching the receipt,
         * which is the whole of "adjusts the invoice without mutating it": paid_total
         * is still 50.00 and only balance_due moved.
         */
        $data = $this->invoiceData($user, $company, $invoice);

        $this->assertSame('50.0000', $data['paid_total']);
        $this->assertSame('100.0000', $data['balance_due']);
        $this->assertSame(TransactionStatus::PartiallyPaid->value, $data['status']);
    }

    #[Test]
    public function a_paid_in_full_invoice_can_still_be_credited(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '200.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
        ], '2027-01-20');

        $this->assertSame(TransactionStatus::Paid, $invoice->refresh()->status);
        $this->assertSame('0.0000', $this->balanceDue($user, $company, $invoice));

        // Section 29 of the brief: an invoice paid in full and then partly
        // returned must still be creditable. The limit is against what the invoice
        // was FOR, not against what is unpaid - which is why this works at all.
        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '50.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $data = $this->invoiceData($user, $company, $invoice);

        /*
         * The receipt is untouched and nothing is outstanding. balance_due is
         * clamped at zero rather than reporting the 50.00 that is now owed BACK,
         * because a balance_due of -50.00 would read as though the customer were
         * 50.00 behind - the exact opposite of what happened. The customer's credit
         * position is a refund to be arranged, and it belongs on the credit note,
         * which carries the amount, rather than smuggled into a receivable figure.
         */
        $this->assertSame('200.0000', $data['paid_total']);
        $this->assertSame('0.0000', $data['balance_due']);

        /*
         * And the status stays PAID, which is the one assertion here worth arguing
         * about.
         *
         * Something HAS settled this document - 200.00 was received against it -
         * and that event does not un-happen because a credit note was issued
         * afterwards. PAID followed by a credit is a refund owed to the customer,
         * and it is a conversation with them; it is not the same claim as an
         * invoice sitting unpaid. Rewriting the status to PARTIALLY_PAID here would
         * put this invoice back into the receivables ageing as though the customer
         * had stopped paying, which is both false and the kind of error that
         * destroys trust in the ageing report.
         *
         * The current position is reported by balance_due, which is derived per
         * request and therefore always fresh - so nothing is hidden by leaving the
         * label alone.
         */
        $this->assertSame(TransactionStatus::Paid->value, $data['status']);
        $this->assertSame(TransactionStatus::Paid, $invoice->refresh()->status);
    }

    #[Test]
    public function a_credit_that_covers_the_whole_balance_leaves_a_part_paid_invoice_settled(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '150.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '150.00'],
        ], '2027-01-20');

        $this->assertSame(TransactionStatus::PartiallyPaid, $invoice->refresh()->status);

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '150.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $data = $this->invoiceData($user, $company, $invoice);

        /*
         * 150.00 received, 150.00 credited, nothing outstanding - and the status is
         * still PARTIALLY_PAID rather than promoted to PAID.
         *
         * PARTIALLY_PAID is a settlement record: it says money came in and did not
         * cover the document. That remains true, and a credit note does not change
         * it. Promoting the document to PAID would erase the fact that the
         * customer never paid the last 50.00 - the credit absorbed it. The label
         * "paid" belongs to documents that were actually paid, and this one was not:
         * it was half paid and then half given back.
         */
        $this->assertSame('150.0000', $data['paid_total']);
        $this->assertSame('0.0000', $data['balance_due']);
        $this->assertSame(TransactionStatus::PartiallyPaid->value, $data['status']);
        $this->assertSame(TransactionStatus::PartiallyPaid, $invoice->refresh()->status);
    }

    #[Test]
    public function a_debit_note_after_a_credit_raises_the_balance_again(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $this->assertSame('100.0000', $this->balanceDue($user, $company, $invoice));

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'note_type' => NoteType::SalesDebitNote->value,
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        // Back to where it started: the credit and the debit cancel exactly.
        $this->assertSame('200.0000', $this->balanceDue($user, $company, $invoice));
        $this->assertSame(
            '0.0000',
            $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->getJson("/api/sales-invoices/{$invoice->getKey()}/adjustable-lines")
                ->json('data.net_adjustment'),
        );
    }

    #[Test]
    public function a_fully_credited_paid_invoice_reports_nothing_outstanding(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '200.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
        ], '2027-01-20');

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '2',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        /*
         * Paid 200.00, credited 200.00, so nothing is owed - but the money is owed
         * BACK, and the balance_due clamps to zero rather than reporting -200.00,
         * which would read as though the customer were behind by 200.00.
         *
         * The clamping lives in balance_due, not in the status column, and the two
         * are read from different places on purpose: the status column is history
         * (a receipt settled this document, and later notes do not rewrite that)
         * while balance_due is derived per request from the current figures.
         */
        $data = $this->invoiceData($user, $company, $invoice);

        $this->assertSame('0.0000', $data['balance_due']);
        $this->assertSame('200.0000', $data['paid_total']);
        $this->assertSame(TransactionStatus::Paid->value, $data['status']);

        /*
         * The ageing report lists invoices with money outstanding, and this one has
         * none - so it is excluded rather than shown with a zero row. A zero row
         * would read as "this customer owes nothing and here is the document",
         * which is what the report already means by omitting it, and filling the
         * ageing with settled documents is how an ageing report stops being a
         * collections tool.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?as_of=2027-12-31')
            ->assertSuccessful()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.rows', [])
            ->assertJsonPath('data.totals.balance_due', '0.0000')
            ->assertJsonMissing(['invoice_number' => $invoice->invoice_number]);
    }

    #[Test]
    public function an_outstanding_invoice_credited_partly_stays_on_the_receivables_report(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '50.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $rows = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?as_of=2027-12-31')
            ->assertSuccessful()
            ->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertSame($invoice->invoice_number, $rows[0]['invoice_number']);
        $this->assertSame('150.0000', $rows[0]['balance_due']);
    }

    #[Test]
    public function a_credit_note_appears_on_the_customer_statement_as_a_credit(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'reference' => 'RMA-77',
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '50.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$customer->getKey())
            ->assertSuccessful()
            ->json('data');

        $noteRow = collect($data['rows'])->firstWhere('type', 'credit_note');

        $this->assertNotNull($noteRow, 'The posted note must appear on the statement.');
        $this->assertSame('0.0000', $noteRow['debit']);
        $this->assertSame('50.0000', $noteRow['credit']);
        $this->assertSame('RMA-77', $noteRow['reference'], 'The counterparty reference is preferred over our number.');
        $this->assertSame('150.0000', $data['closing_balance']);
        $this->assertSame('200.0000', $data['totals']['debit']);
        $this->assertSame('50.0000', $data['totals']['credit']);
    }

    #[Test]
    public function a_draft_note_never_appears_on_the_statement(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->createDraftNote($user, $company, $invoice, $accounts);

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$customer->getKey())
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $data['rows'], 'Only the invoice should be listed.');
        $this->assertSame('200.0000', $data['closing_balance']);
    }

    #[Test]
    public function a_purchase_credit_note_appears_on_the_supplier_statement_as_a_debit(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->postPurchaseNote($user, $company, $bill, $accounts, overrides: [
            'lines' => [[
                'description' => 'Materials returned',
                'quantity' => '1',
                'unit_price' => '200.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['expense']->getKey(),
            ]],
        ]);

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/supplier-statement?supplier_id='.$supplier->getKey())
            ->assertSuccessful()
            ->json('data');

        $noteRow = collect($data['rows'])->firstWhere('type', 'credit_note');

        /*
         * A DEBIT on a supplier statement, and this is the assertion most worth
         * making. A supplier statement is payable-positive: the bill is a credit.
         * Reading the note's own isCredit() as the statement direction - which is
         * the obvious implementation - would put a credit note on the credit side
         * and DOUBLE the payable of a bill the supplier had just credited us.
         */
        $this->assertNotNull($noteRow);
        $this->assertSame('200.0000', $noteRow['debit']);
        $this->assertSame('0.0000', $noteRow['credit']);
        $this->assertSame('300.0000', $data['closing_balance']);
    }

    #[Test]
    public function notes_do_not_leave_the_source_document_editable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '50.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        // The invoice is still as posted - and still refuses an edit, exactly as it
        // did before the note existed.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/sales-invoices/{$invoice->getKey()}", ['notes' => 'Rewritten.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->assertNull($invoice->refresh()->notes);
    }

    #[Test]
    public function two_notes_of_opposite_direction_cancel_in_the_reports(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->invoiceFor200($user, $company, $customer, $accounts);

        $this->postNote($user, $company, $invoice, $accounts, overrides: [
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $this->postNote($user, $company, $invoice, $accounts, date: '2027-03-01', overrides: [
            'note_type' => NoteType::SalesDebitNote->value,
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        /*
         * Both notes exist, both have journals, and the receivable is back where it
         * started. The journals did not cancel - two entries of 100.00 in opposite
         * directions are still two entries - which is why this is asserted here on
         * the derived figures rather than assumed from the entries.
         */
        $this->assertSame(2, Journal::query()->count() - 1);
        $this->assertSame('200.0000', $this->balanceDue($user, $company, $invoice));

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$customer->getKey())
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('200.0000', $data['closing_balance']);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceData(User $user, Company $company, SalesInvoice $invoice): array
    {
        return $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertSuccessful()
            ->json('data');
    }

    private function balanceDue(User $user, Company $company, SalesInvoice $invoice): string
    {
        return $this->invoiceData($user, $company, $invoice)['balance_due'];
    }
}
