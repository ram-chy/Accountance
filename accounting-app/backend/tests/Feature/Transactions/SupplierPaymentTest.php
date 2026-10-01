<?php

namespace Tests\Feature\Transactions;

use App\Enums\JournalSource;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Supplier payments: allocation, posting and settlement.
 *
 * The entry is:
 *
 *   Dr Accounts Payable   the payment amount
 *       Cr Cash / Bank    the payment amount
 *
 * The same shape as CustomerReceiptTest with the credit and debit swapped. The
 * tests are written out separately rather than shared, because a payment that
 * accidentally books the mirror image of a receipt still balances - it just
 * reduces the wrong side of every account in the ledger.
 */
class SupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function a_draft_payment_is_created_with_its_allocations(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '500.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '500.00'],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_number', 'PAY-000001')
            ->assertJsonPath('data.status', PaymentStatus::Draft->value)
            ->assertJsonPath('data.allocations.0.purchase_bill_id', $bill->getKey())
            ->assertJsonPath('data.allocations.0.amount', '500.0000');

        $this->assertSame(0, Journal::where('source_type', JournalSource::SupplierPayment->value)->count());
    }

    #[Test]
    public function posting_a_payment_debits_payable_and_credits_cash(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $payment = $this->postPayment($user, $company, $supplier, $accounts, $bill, '500.00');

        $journal = Journal::findOrFail($payment->journal_id);
        $this->assertSame(JournalSource::SupplierPayment->value, $journal->source_type->value);

        $lines = $journal->lines()->get();

        /*
         * Payable is debited and cash credited. The mirror image would leave every
         * account's sign wrong while still balancing, so each side is asserted
         * explicitly rather than only checking that the entry balances.
         */
        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['payable']->getKey())->first()->debit);
        $this->assertSame('0.0000', (string) $lines->where('account_id', $accounts['payable']->getKey())->first()->credit);
        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['cash']->getKey())->first()->credit);
        $this->assertSame('0.0000', (string) $lines->where('account_id', $accounts['cash']->getKey())->first()->debit);
    }

    #[Test]
    public function posting_a_partial_payment_marks_the_bill_partially_paid(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->postPayment($user, $company, $supplier, $accounts, $bill, '200.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/purchase-bills/{$bill->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::PartiallyPaid->value)
            ->assertJsonPath('data.paid_total', '200.0000')
            ->assertJsonPath('data.balance_due', '300.0000');
    }

    #[Test]
    public function posting_a_full_payment_marks_the_bill_paid(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->postPayment($user, $company, $supplier, $accounts, $bill, '500.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/purchase-bills/{$bill->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::Paid->value)
            ->assertJsonPath('data.paid_total', '500.0000')
            ->assertJsonPath('data.balance_due', '0.0000');
    }

    #[Test]
    public function a_payment_cannot_settle_another_suppliers_bill(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $other = $this->createSupplier($user, $company, $accounts, ['supplier_code' => 'OTHER']);

        $bill = $this->postBill($user, $company, $other, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '500.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '500.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.purchase_bill_id');
    }

    #[Test]
    public function a_payment_cannot_be_created_against_a_draft_bill(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->makePeriodFor($company, '2027-01-10', 'Draft bill period');

        $draft = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '500.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '500.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $draft->json('data.id'), 'amount' => '500.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.purchase_bill_id');
    }

    #[Test]
    public function allocations_must_total_the_payment_amount(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '500.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '400.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations');
    }

    #[Test]
    public function an_allocation_larger_than_the_outstanding_balance_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '600.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '600.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.purchase_bill_id');
    }

    #[Test]
    public function a_paid_bill_cannot_be_paid_again(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->postPayment($user, $company, $supplier, $accounts, $bill, '500.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-21',
                'amount' => '100.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '100.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.purchase_bill_id');
    }

    #[Test]
    public function the_payment_account_must_be_an_asset(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '500.00',
                // A liability. Money leaving the bank must credit an asset, and
                // this is the account it is being drawn down to pay.
                'payment_account_id' => $accounts['payable']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '500.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');
    }

    #[Test]
    public function a_draft_payment_does_not_reduce_the_bill_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '200.00'],
                ],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/purchase-bills/{$bill->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::Posted->value)
            ->assertJsonPath('data.paid_total', '0.0000');
    }

    #[Test]
    public function a_posted_payment_cannot_be_posted_edited_or_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $payment = $this->postPayment($user, $company, $supplier, $accounts, $bill, '500.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/supplier-payments/{$payment->getKey()}/post")
            ->assertStatus(409);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/supplier-payments/{$payment->getKey()}", [
                'amount' => '600.00',
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '600.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/supplier-payments/{$payment->getKey()}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');
    }

    #[Test]
    public function a_draft_payment_can_be_updated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $first = $this->postBill($user, $company, $supplier, $accounts, date: '2027-01-10');
        $second = $this->postBill($user, $company, $supplier, $accounts, date: '2027-01-11');

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '100.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $first->getKey(), 'amount' => '100.00'],
                ],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/supplier-payments/{$created->json('data.id')}", [
                'amount' => '600.00',
                'allocations' => [
                    ['purchase_bill_id' => $first->getKey(), 'amount' => '100.00'],
                    ['purchase_bill_id' => $second->getKey(), 'amount' => '500.00'],
                ],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.amount', '600.0000');

        $this->assertSame(2, SupplierPayment::findOrFail($created->json('data.id'))->allocations()->count());
    }

    #[Test]
    public function reducing_a_payment_amount_below_its_allocations_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '500.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '500.00'],
                ],
            ])->assertCreated();

        /*
         * Only the amount is sent. The request cannot compare the two, so the
         * service re-checks the stored allocations against the new amount - a
         * draft whose allocations exceed its amount would otherwise be refused
         * at posting with a message the user could not act on.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/supplier-payments/{$created->json('data.id')}", ['amount' => '100.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations');
    }

    #[Test]
    public function a_draft_payment_can_be_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '100.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '100.00'],
                ],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/supplier-payments/{$created->json('data.id')}")
            ->assertSuccessful();

        $this->assertSame(0, SupplierPayment::count());
    }

    #[Test]
    public function a_payment_from_another_company_is_not_found(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $foreign = SupplierPayment::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/supplier-payments/{$foreign->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function a_bill_from_another_company_cannot_be_allocated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $other = $this->createUnrelatedCompany();
        $foreign = PurchaseBill::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '100.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $foreign->getKey(), 'amount' => '100.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.purchase_bill_id');
    }

    #[Test]
    public function a_supplier_with_posted_bills_cannot_be_deactivated_while_owing_money(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);
        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/suppliers/{$supplier->getKey()}/deactivate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier');

        $this->assertTrue(Supplier::findOrFail($supplier->getKey())->is_active);
    }

    private function postPayment(
        User $user,
        Company $company,
        Supplier $supplier,
        array $accounts,
        PurchaseBill $bill,
        string $amount,
        string $date = '2027-01-20'
    ): SupplierPayment {
        $this->makePeriodFor($company, $date, 'P '.substr($date, 0, 7).uniqid());

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => $date,
                'amount' => $amount,
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => $amount],
                ],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/supplier-payments/{$response->json('data.id')}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PaymentStatus::Posted->value);

        return SupplierPayment::findOrFail($response->json('data.id'));
    }
}
