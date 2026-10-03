<?php

namespace Tests\Feature\Transactions;

use App\Enums\NoteType;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 11 role matrix, exercised through real HTTP requests.
 *
 * The same reasoning as TransactionAuthorizationTest applies, and it applies with
 * more force to notes than to the documents around them. A permission granted to a
 * role is only real if it is reachable, and notes are reachable by three separate
 * doors - the note endpoints, the post endpoint, and the two adjustable-lines
 * endpoints hanging off invoices and bills - so a matrix that only covered the
 * first door would leave most of the surface untested.
 *
 * The adjustable-lines endpoints are the ones worth dwelling on. They are read-only
 * and they are on the invoice and bill controllers, so the permission a role needs
 * to call them is not the note permission at all: an endpoint living on another
 * document's controller is still a note permission, because it is note data.
 */
class CreditDebitNoteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, array<string, Account>>
     */
    private array $accountsByCompany = [];

    /**
     * Reading notes, and the note-derived figures hanging off other documents.
     *
     * @return array<string, array{0: string}>
     */
    public static function readProvider(): array
    {
        return [
            'admin reads notes' => [RoleName::Admin->value],
            'accountant reads notes' => [RoleName::Accountant->value],
            'manager reads notes' => [RoleName::Manager->value],
            'staff reads nothing' => [RoleName::Staff->value],
        ];
    }

    /**
     * Creating, editing and posting notes.
     *
     * @return array<string, array{0: string}>
     */
    public static function writeProvider(): array
    {
        return [
            'admin writes notes' => [RoleName::Admin->value],
            'accountant writes notes' => [RoleName::Accountant->value],
            'manager writes nothing' => [RoleName::Manager->value],
            'staff writes nothing' => [RoleName::Staff->value],
        ];
    }

    #[Test]
    #[DataProvider('readProvider')]
    public function listing_and_showing_a_note_follows_the_matrix(string $role): void
    {
        [$user, $company] = $this->actor($role);
        $note = $this->draftNote($user, $company);

        $mayRead = $role !== RoleName::Staff->value;

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/credit-debit-notes')
            ->{$mayRead ? 'assertSuccessful' : 'assertForbidden'}();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/credit-debit-notes/{$note->getKey()}")
            ->{$mayRead ? 'assertSuccessful' : 'assertForbidden'}();
    }

    #[Test]
    #[DataProvider('writeProvider')]
    public function creating_a_note_follows_the_matrix(string $role): void
    {
        [$user, $company] = $this->actor($role);
        $invoice = $this->postedInvoice($user, $company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/credit-debit-notes', $this->notePayload($user, $company, $invoice));

        $mayWrite = in_array($role, [RoleName::Admin->value, RoleName::Accountant->value], true);

        $mayWrite
            ? $response->assertSuccessful()
            : $response->assertForbidden();
    }

    #[Test]
    #[DataProvider('writeProvider')]
    public function posting_a_note_follows_the_matrix(string $role): void
    {
        [$user, $company] = $this->actor($role);
        $this->makePeriodFor($company, '2027-02-15', 'Auth notes '.uniqid());
        $note = $this->draftNote($user, $company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/credit-debit-notes/{$note->getKey()}/post");

        $mayPost = in_array($role, [RoleName::Admin->value, RoleName::Accountant->value], true);

        $mayPost
            ? $response->assertSuccessful()
            : $response->assertForbidden();
    }

    #[Test]
    #[DataProvider('writeProvider')]
    public function editing_and_deleting_a_draft_note_follows_the_matrix(string $role): void
    {
        [$user, $company] = $this->actor($role);
        $note = $this->draftNote($user, $company);

        $mayWrite = in_array($role, [RoleName::Admin->value, RoleName::Accountant->value], true);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/credit-debit-notes/{$note->getKey()}", [
                'reason' => 'Edited by an auth test.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $this->accountsFor($company)['revenue']->getKey(),
                ]],
            ])
            ->{$mayWrite ? 'assertSuccessful' : 'assertForbidden'}();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/credit-debit-notes/{$note->getKey()}")
            ->{$mayWrite ? 'assertSuccessful' : 'assertForbidden'}();
    }

    #[Test]
    #[DataProvider('readProvider')]
    public function the_adjustable_lines_endpoints_follow_the_matrix(string $role): void
    {
        [$user, $company] = $this->actor($role);
        $invoice = $this->postedInvoice($user, $company);

        /*
         * Both endpoints live on other documents' controllers and both return note
         * figures - what is still adjustable, by document and by line. A role that
         * cannot read notes must not be able to read the adjustment headroom
         * through this side door either, or "Staff see nothing" is only true of
         * the obvious route.
         */
        $mayRead = $role !== RoleName::Staff->value;

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}/adjustable-lines")
            ->{$mayRead ? 'assertSuccessful' : 'assertForbidden'}();
    }

    #[Test]
    public function a_manager_can_read_a_note_but_not_edit_it(): void
    {
        [$user, $company] = $this->actor(RoleName::Manager->value);
        $note = $this->draftNote($user, $company);

        // A Manager's job in this system is reporting, not data entry.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/credit-debit-notes/{$note->getKey()}")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/credit-debit-notes/{$note->getKey()}", [
                'reason' => 'Manager attempt.',
                'lines' => [[
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'account_id' => $this->accountsFor($company)['revenue']->getKey(),
                ]],
            ])
            ->assertForbidden();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/credit-debit-notes/{$note->getKey()}")
            ->assertForbidden();
    }

    #[Test]
    public function a_note_cannot_be_read_across_companies(): void
    {
        [$user, $company] = $this->actor(RoleName::Admin->value);
        $note = $this->draftNote($user, $company);

        // A second Admin, fully entitled, whose company context is not the note's.
        $outsider = $this->createUserWithRole(RoleName::Admin);
        $theirCompany = $this->createCompanyFor($outsider);

        /*
         * 404, not 403 - and that is the better answer.
         *
         * Route model binding is scoped to the company in the request context, so
         * the note is not found at all: a 403 would confirm that note 12 exists in
         * the system, which is itself a leak to a company that should not know
         * another tenant's document ids. 404 says only that there is nothing here,
         * which is true from this company's point of view.
         */
        $this->actingAsJwt($outsider)
            ->withCompanyContext($theirCompany)
            ->getJson("/api/credit-debit-notes/{$note->getKey()}")
            ->assertNotFound();

        // And their own listing is empty rather than someone else's.
        $this->actingAsJwt($outsider)
            ->withCompanyContext($theirCompany)
            ->getJson('/api/credit-debit-notes')
            ->assertSuccessful()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function the_note_permissions_are_registered(): void
    {
        /*
         * Asserted directly rather than inferred from the request tests above, so
         * that deleting one of the five permissions fails here with a clear name
         * rather than as a scattering of 403s across the matrix tests.
         */
        foreach ([
            PermissionName::CreditDebitNotesView,
            PermissionName::CreditDebitNotesCreate,
            PermissionName::CreditDebitNotesUpdate,
            PermissionName::CreditDebitNotesPost,
            PermissionName::CreditDebitNotesDelete,
        ] as $permission) {
            $this->assertContains($permission->value, array_column(PermissionName::cases(), 'value'));
        }
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function actor(string $role): array
    {
        $user = $this->createUserWithRole($role);

        return [$user, $this->createCompanyFor($user)];
    }

    /**
     * @return array<string, Account>
     */
    private function accountsFor(Company $company): array
    {
        return $this->accountsByCompany[$company->getKey()] ??= $this->makeTransactionAccounts($company);
    }

    private function postedInvoice(User $user, Company $company): SalesInvoice
    {
        $accounts = $this->accountsFor($company);
        $customer = Customer::factory()->for($company)->create([
            'receivable_account_id' => $accounts['receivable']->getKey(),
        ]);

        /*
         * The figures are written rather than left to the factory, because the
         * adjustment limit is a sum over grand_total. A note can only be created
         * against an invoice the service believes is worth something, and a
         * factory default of zero would make every create in this file fail the
         * limit for reasons that have nothing to do with authorization.
         */
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create([
            'status' => TransactionStatus::Posted->value,
            'invoice_date' => '2027-01-10',
            'subtotal' => '200.00',
            'discount_total' => '0',
            'tax_total' => '0',
            'grand_total' => '200.00',
        ]);

        SalesInvoiceLine::factory()->for($invoice, 'invoice')->create([
            'line_number' => 1,
            'quantity' => '2',
            'unit_price' => '100.00',
            'line_total' => '200.00',
            'revenue_account_id' => $accounts['revenue']->getKey(),
        ]);

        return $invoice;
    }

    private function draftNote(User $user, Company $company): CreditDebitNote
    {
        $accounts = $this->accountsFor($company);
        $invoice = $this->postedInvoice($user, $company);

        /*
         * forceCreate with a real source, because the schema insists on one. A note
         * with no source document is not a thing the database will hold, which is
         * the first of the integrity rules this file is not testing and should not
         * be accidentally testing.
         */
        $note = CreditDebitNote::query()->forceCreate([
            'company_id' => $company->getKey(),
            'note_number' => 'CDN-'.uniqid(),
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => $invoice->getKey(),
            'purchase_bill_id' => null,
            'customer_id' => $invoice->customer_id,
            'supplier_id' => null,
            'note_date' => '2027-02-15',
            'status' => TransactionStatus::Draft->value,
            'subtotal' => '100.00',
            'discount_total' => '0',
            'tax_total' => '0',
            'grand_total' => '100.00',
            'reason' => 'Authorization test note.',
            'created_by' => $user->getKey(),
        ]);

        $note->lines()->create([
            'line_number' => 1,
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'line_total' => '100.00',
            'account_id' => $accounts['revenue']->getKey(),
        ]);

        return $note;
    }

    /**
     * @return array<string, mixed>
     */
    private function notePayload(User $user, Company $company, SalesInvoice $invoice): array
    {
        $accounts = $this->accountsFor($company);

        return [
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => $invoice->getKey(),
            'note_date' => '2027-02-15',
            'reason' => 'Authorization test payload.',
            'lines' => [[
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'account_id' => $accounts['revenue']->getKey(),
            ]],
        ];
    }
}
