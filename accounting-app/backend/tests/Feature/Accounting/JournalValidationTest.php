<?php

namespace Tests\Feature\Accounting;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Accounting\JournalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The structural rules of a journal: one-sided lines, non-negative amounts,
 * non-zero amounts, a minimum of two lines, and - the central rule - equal
 * debits and credits.
 *
 * These are asserted at three levels on purpose:
 *
 *   1. The request layer, which is what a client actually experiences.
 *   2. The service layer, which is the guarantee for non-HTTP callers.
 *   3. The database CHECK constraints, which is the guarantee against a bug in
 *      1 or 2 ever letting bad data through.
 *
 * A test that only covered level 1 would pass while the database still allowed a
 * zero-value line to be inserted by a console command or a queued job.
 */
class JournalValidationTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    /**
     * A company plus an asset/revenue pair, created for the given user.
     *
     * The user is a parameter rather than created internally: the caller's token
     * must belong to the same person who is a member of the company, or every
     * request comes back 403 from the company context middleware and the test
     * fails for a reason that has nothing to do with journal validation.
     *
     * @return array{0: Company, 1: Account, 2: Account}
     */
    private function fixture(User $user): array
    {
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        return [$company, $cash, $revenue];
    }

    private function submit(User $user, Company $company, array $lines): TestResponse
    {
        return $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'lines' => $lines,
            ]);
    }

    #[Test]
    public function an_unbalanced_journal_is_rejected(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        $response = $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '100.0000', 'credit' => '0'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '99.9999'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertStringContainsString('not balanced', $this->responseErrors($response)['lines']);
        $this->assertSame(0, Journal::query()->count());
    }

    #[Test]
    public function the_balance_check_reports_the_exact_difference(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        $errors = $this->responseErrors($this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '100.0000', 'credit' => '0'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '75.0000'],
        ]));

        // The user is told how far out they are, in exact decimal, so the fix is
        // arithmetic rather than a re-submission.
        $this->assertStringContainsString('25.0000', $errors['lines']);
    }

    #[Test]
    public function a_line_with_both_a_debit_and_a_credit_is_rejected(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        $response = $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '100.0000', 'credit' => '40.0000'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '100.0000'],
        ]);

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'cannot have both a debit and a credit',
            $this->responseErrors($response)['lines.0']
        );
    }

    #[Test]
    public function a_zero_value_line_is_rejected(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        $response = $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '0.0000', 'credit' => '0.0000'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '0'],
        ]);

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'greater than zero on one side',
            $this->responseErrors($response)['lines.0']
        );
    }

    #[Test]
    public function a_negative_amount_is_rejected(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        $response = $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '-100.0000', 'credit' => '0'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '-100.0000'],
        ]);

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'cannot have a negative amount',
            $this->responseErrors($response)['lines.0']
        );
    }

    #[Test]
    public function a_single_line_journal_is_rejected(): void
    {
        $user = $this->accountant();
        [$company, $cash] = $this->fixture($user);

        $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '100.0000', 'credit' => '0'],
        ])->assertStatus(422)->assertJsonValidationErrors('lines');

        $this->assertSame(0, Journal::query()->count());
    }

    #[Test]
    public function a_journal_may_not_exceed_the_configured_line_limit(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        $limit = (int) config('accounting.limits.max_lines_per_journal');

        // 500 lines of 1.0000 debit against 499 of 1.0000 credit plus a single
        // 2.0000 credit line: balanced, so the ONLY thing that can reject this
        // payload is the line count.
        $lines = [];
        for ($i = 0; $i < $limit; $i++) {
            $lines[] = ['account_id' => $cash->getKey(), 'debit' => '1.0000', 'credit' => '0'];
        }
        $lines[] = ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => (string) $limit.'.0000'];

        $this->submit($user, $company, $lines)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    #[Test]
    public function a_non_numeric_amount_is_rejected_before_arithmetic(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => 'one hundred', 'credit' => '0'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '100.0000'],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.debit');
    }

    #[Test]
    public function extra_precision_is_rounded_half_up_rather_than_rejected(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        /*
         * config/accounting.php documents that a client sending more decimals than
         * the ledger stores gets them rounded, not a rejection. 1.00005 rounds to
         * 1.0001 (half away from zero); if this came back as a validation error the
         * documented tolerance would be a lie.
         */
        $response = $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '1.00005', 'credit' => '0'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '1.00005'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.lines.0.debit', '1.0001')
            ->assertJsonPath('data.lines.1.credit', '1.0001');
    }

    #[Test]
    public function an_amount_that_rounds_away_to_zero_is_still_rejected(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        /*
         * The tolerance is not a licence to write zero-value lines. 0.00001 rounds
         * to 0.0000 at the ledger's scale, and the one-sided/non-zero rule is
         * re-checked *after* rounding - otherwise rounding would create lines the
         * database CHECK constraint then rejects as an integrity error.
         */
        $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '0.00001', 'credit' => '0'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '0.00001'],
        ])->assertStatus(422);

        $this->assertSame(0, Journal::query()->count());
    }

    #[Test]
    public function amounts_that_cannot_be_read_as_decimals_are_rejected_outright(): void
    {
        $user = $this->accountant();
        [$company, $cash, $revenue] = $this->fixture($user);

        /*
         * Eleven decimal places is past the input bound in config. A value that
         * far out is a client bug, not a rounding case worth accommodating, and
         * silently rounding it would hide the bug.
         */
        $this->submit($user, $company, [
            ['account_id' => $cash->getKey(), 'debit' => '0.123456789012', 'credit' => '0'],
            ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '0.123456789012'],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.debit');
    }

    #[Test]
    public function the_service_rejects_an_unbalanced_payload_without_writing_anything(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // Written straight through the service, with no HTTP layer in the way, to
        // prove the guarantee does not depend on the FormRequest.
        $this->expectException(ValidationException::class);

        try {
            app(JournalService::class)->createDraft($company, $user, [
                'journal_date' => '2027-01-15',
                'lines' => [
                    ['account_id' => $cash->getKey(), 'debit' => '100.0000', 'credit' => '0'],
                    ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '10.0000'],
                ],
            ]);
        } finally {
            // Validation runs before the first write, so a rejected payload leaves
            // no header and no lines behind.
            $this->assertSame(0, Journal::query()->count());
            $this->assertSame(0, JournalLine::query()->count());
        }
    }

    #[Test]
    public function the_database_rejects_a_line_that_is_both_sided(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        /*
         * The last line of defence, reached by bypassing the application entirely.
         * journal_lines_one_sided_check exists so that a future module writing
         * lines with the query builder cannot create an entry the application
         * would have refused.
         */
        $this->expectException(QueryException::class);

        \DB::table('journal_lines')->insert([
            'journal_id' => $journal->getKey(),
            'account_id' => $cash->getKey(),
            'description' => null,
            'debit' => '10.0000',
            'credit' => '10.0000',
            'line_number' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_rejects_a_zero_value_line(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        $this->expectException(QueryException::class);

        \DB::table('journal_lines')->insert([
            'journal_id' => $journal->getKey(),
            'account_id' => $cash->getKey(),
            'description' => null,
            'debit' => '0.0000',
            'credit' => '0.0000',
            'line_number' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_rejects_a_negative_amount(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        $this->expectException(QueryException::class);

        \DB::table('journal_lines')->insert([
            'journal_id' => $journal->getKey(),
            'account_id' => $cash->getKey(),
            'description' => null,
            'debit' => '-10.0000',
            'credit' => '0.0000',
            'line_number' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
