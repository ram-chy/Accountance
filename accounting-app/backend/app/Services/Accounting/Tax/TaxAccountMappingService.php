<?php

namespace App\Services\Accounting\Tax;

use App\Models\Tax;
use App\Models\TaxAccountMapping;
use App\Services\Accounting\TransactionAccountResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where a tax's money goes.
 *
 * This service creates no accounts. It points at accounts that already exist in
 * the chart of accounts, and it validates them by calling
 * TransactionAccountResolver - the same class that validates `tax_account_id` on an
 * invoice and `input_tax_account_id` on a bill.
 *
 * That delegation is the point. The rule being enforced is "output tax posts to a
 * LIABILITY, input tax posts to an ASSET", and it is already written down once, in
 * the ROLES table of that resolver, in the terms the posting services use. Restating
 * it here as a second check would be a second answer to the same question, and the
 * two would drift - someone widening a role there would leave this one behind, and
 * the failure would be an unbalanced journal rather than a rejected configuration.
 *
 * So a mapping cannot be saved pointing at an account a transaction would also
 * have refused.
 */
class TaxAccountMappingService
{
    public function __construct(
        private readonly TransactionAccountResolver $accounts,
    ) {}

    /**
     * @throws ValidationException
     */
    public function save(Tax $tax, array $data): TaxAccountMapping
    {
        $outputId = $data['output_account_id'] ?? null;
        $inputId = $data['input_account_id'] ?? null;

        if ($outputId === null && $inputId === null) {
            throw ValidationException::withMessages([
                'output_account_id' => 'A tax mapping must set at least one of the output or input accounts.',
            ]);
        }

        /*
         * Only the sides the tax can actually be used on are validated. An OUTPUT
         * tax with an input account is not a mapping this tax can act on, so
         * rejecting it would be wrong - but storing one silently would put an
         * account in the database that no calculation could ever reach.
         */
        if ($outputId !== null && $tax->appliesToSales()) {
            $this->resolveSide($tax, 'output_account_id', 'tax_account_id', $outputId);
        }

        if ($inputId !== null && $tax->appliesToPurchase()) {
            $this->resolveSide($tax, 'input_account_id', 'input_tax_account_id', $inputId);
        }

        if ($outputId !== null && $inputId !== null && $outputId === $inputId) {
            /*
             * Distinctness is checked here as well as by the CHECK constraint,
             * because the constraint's failure arrives as a raw driver error with no
             * indication of which field the user should change.
             */
            throw ValidationException::withMessages([
                'input_account_id' => 'The output and input accounts must be different accounts.',
            ]);
        }

        /*
         * One transaction because this is an upsert and a destroy: replacing a
         * mapping is delete-then-insert on the row, and a failure partway through
         * would otherwise leave the tax with either both mappings or neither.
         */
        return DB::transaction(function () use ($tax, $outputId, $inputId) {
            TaxAccountMapping::query()->where('tax_id', $tax->getKey())->delete();

            $mapping = new TaxAccountMapping([
                'tax_id' => $tax->getKey(),
                'output_account_id' => $outputId,
                'input_account_id' => $inputId,
            ]);

            $mapping->company_id = $tax->company_id;
            $mapping->forceFill(['created_by' => Auth::id()]);

            $mapping->save();

            return $mapping;
        });
    }

    /**
     * Validate one side of the mapping, reporting under the field the client sent.
     *
     * `resolve()` keys its error by the *role* - "tax_account_id" - because on a
     * bill that is the field a client submits. On this endpoint the same role is
     * *not* a field: the request calls it `output_account_id` or `input_account_id`,
     * so a relayed message would point the user at a field that appears nowhere in
     * their payload and in no mapping response, which is an error they cannot act on.
     *
     * The rule itself is not restated or re-implemented here; the messages are moved
     * from the role key to the field key and nothing else changes.
     *
     * @throws ValidationException
     */
    private function resolveSide(Tax $tax, string $field, string $role, int $accountId): void
    {
        try {
            $this->accounts->resolve($tax->company, $role, $accountId);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                $field => array_merge(...array_values($e->errors())),
            ]);
        }
    }

    /**
     * Remove a tax's mapping.
     *
     * Safe at any time: a mapping is configuration, not a record. Documents that
     * used the tax keep their own `tax_account_id`, so removing the mapping does not
     * orphan any posted journal - it only means new documents naming this tax will
     * be refused until a mapping exists again, which is the honest state to be in.
     */
    public function delete(Tax $tax): void
    {
        TaxAccountMapping::query()->where('tax_id', $tax->getKey())->delete();
    }

    /**
     * Assert a tax can be booked on a document of the given side.
     *
     * @throws ValidationException
     */
    public function assertPostable(Tax $tax, bool $output, string $field = 'tax_account_id'): void
    {
        $mapping = $tax->accountMapping;

        $id = $mapping === null
            ? null
            : ($output ? $mapping->output_account_id : $mapping->input_account_id);

        if ($id !== null) {
            return;
        }

        throw ValidationException::withMessages([
            $field => sprintf(
                'Tax [%s] has no %s account configured, so its money has nowhere to be recorded.',
                $tax->code,
                $output ? 'liability' : 'recovery'
            ),
        ]);
    }
}
