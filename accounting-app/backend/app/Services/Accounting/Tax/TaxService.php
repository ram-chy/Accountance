<?php

namespace App\Services\Accounting\Tax;

use App\Enums\TaxCalculationBasis;
use App\Enums\TaxType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Tax;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Configuration of the taxes a company charges and recovers.
 *
 * The rules that matter here are about history rather than arithmetic:
 *
 *  1. A tax that appears on a document line can never be deleted, only
 *     deactivated - the line's rate snapshot is meaningless without knowing which
 *     tax it was.
 *  2. A tax whose rates are still reachable on a future date can never be
 *     deleted, because the rates would be left with nothing to belong to.
 *
 * Deactivation is the reversible alternative in both cases and is what the error
 * messages tell the user to reach for.
 */
class TaxService
{
    /**
     * @throws ValidationException
     */
    public function create(Company $company, array $data): Tax
    {
        $tax = new Tax([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'tax_type' => TaxType::from($data['tax_type']),
            'calculation_basis' => TaxCalculationBasis::from($data['calculation_basis']),
        ]);

        $tax->company_id = $company->getKey();

        /*
         * is_active is not in $fillable: a new tax is active by definition, and
         * activation is a separate, audited transition. created_by comes from the
         * authenticated user rather than the payload - an audit row recording
         * whoever the client claimed configured the tax is worthless.
         */
        $tax->forceFill([
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        $this->persist($tax, $company, $data, creating: true);

        return $tax;
    }

    /**
     * @throws ValidationException
     */
    public function update(Tax $tax, array $data): Tax
    {
        /*
         * Presence, not truthiness, decides what is written. An earlier version
         * filtered out nulls, which quietly made it impossible to clear a
         * description: sending `description: null` was indistinguishable from
         * omitting the field, so a description could be set once and never
         * removed. `array_key_exists` keeps "absent" and "explicitly null" apart.
         */
        foreach (['code', 'name', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $tax->{$field} = $data[$field];
            }
        }

        /*
         * tax_type and calculation_basis are handled explicitly rather than folded
         * into the generic fill, because both change what the tax *means* and
         * therefore what already-snapshotted lines on historical documents are
         * being described as. Neither is refused for that reason - a document line
         * stores the rate it was calculated with, so its arithmetic stays correct
         * whatever the tax is later reclassified as - but the change is recorded
         * through updated_by, which is the only way anyone can find out afterwards.
         */
        if (array_key_exists('tax_type', $data)) {
            $tax->tax_type = TaxType::from($data['tax_type']);
        }

        if (array_key_exists('calculation_basis', $data)) {
            $tax->calculation_basis = TaxCalculationBasis::from($data['calculation_basis']);
        }

        $tax->forceFill(['updated_by' => Auth::id()]);

        $this->persist($tax, $tax->company, $data, creating: false);

        return $tax->refresh();
    }

    public function activate(Tax $tax): Tax
    {
        $tax->forceFill(['is_active' => true, 'updated_by' => Auth::id()])->save();

        return $tax->refresh();
    }

    /**
     * Stop a tax being applied to new documents.
     *
     * The tax and its rates stay exactly as they are: a posted invoice that names
     * it must still be able to resolve the rate it was calculated with, so this
     * changes nothing about the past. `is_active` is checked by the rate resolver,
     * which is what keeps a deactivated tax out of new calculations while leaving
     * historical reads intact.
     */
    public function deactivate(Tax $tax): Tax
    {
        $tax->forceFill(['is_active' => false, 'updated_by' => Auth::id()])->save();

        return $tax->refresh();
    }

    /**
     * Delete a tax that nothing depends on.
     *
     * @throws ValidationException
     */
    public function delete(Tax $tax): void
    {
        if ($tax->isReferencedByDocument()) {
            throw ValidationException::withMessages([
                'tax' => 'This tax cannot be deleted because documents were calculated with it. '
                    .'Deactivate it instead.',
            ]);
        }

        if ($tax->rates()->exists()) {
            throw ValidationException::withMessages([
                'tax' => 'This tax cannot be deleted because it has rates. Deactivate it instead.',
            ]);
        }

        // The database also restricts tax_rates.tax_id and tax_account_mappings.tax_id;
        // this is the message the user sees instead of a constraint violation.
        if ($tax->accountMapping !== null) {
            throw ValidationException::withMessages([
                'tax' => 'This tax cannot be deleted because it has an account mapping. '
                    .'Remove the mapping first.',
            ]);
        }

        $tax->delete();
    }

    /**
     * Taxes a user may see, for the active company.
     *
     * Inactive taxes are included by default: a configuration screen needs them to
     * show what exists, and a caller wanting only applicable ones filters with
     * $activeOnly.
     *
     * @return Collection<int, Tax>
     */
    public function listFor(Company $company, bool $activeOnly = false): Collection
    {
        return Tax::query()
            ->where('company_id', $company->getKey())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get();
    }

    /**
     * A tax in the active company, or a company-scoped failure.
     *
     * The single lookup used by the controller, so route-model binding cannot
     * resolve a tax id belonging to another company. The message deliberately
     * cannot distinguish "no such tax" from "another company's tax".
     *
     * @throws ValidationException
     */
    public function findFor(Company $company, int $taxId): Tax
    {
        $tax = Tax::query()
            ->where('company_id', $company->getKey())
            ->whereKey($taxId)
            ->first();

        if ($tax === null) {
            throw ValidationException::withMessages([
                'tax' => 'The selected tax does not belong to the active company.',
            ]);
        }

        return $tax;
    }

    /**
     * The account a tax of this type posts to, from its mapping.
     *
     * Null when the tax has no mapping yet, or has no mapping for the side this
     * document needs. Callers that are about to post must treat null as a failure
     * rather than skipping the tax - a document that computes tax and then does
     * not record it is worse than one that refuses to save.
     */
    public function accountFor(Tax $tax, bool $output): ?Account
    {
        $mapping = $tax->accountMapping;

        if ($mapping === null) {
            return null;
        }

        $id = $output ? $mapping->output_account_id : $mapping->input_account_id;

        if ($id === null) {
            return null;
        }

        return $output ? $mapping->outputAccount : $mapping->inputAccount;
    }

    /**
     * Save, translating a duplicate code into a field-level validation error.
     *
     * @throws ValidationException
     */
    private function persist(Tax $tax, Company $company, array $data, bool $creating): void
    {
        try {
            $tax->save();
        } catch (QueryException $e) {
            $this->throwIfDuplicateCode($e, $company, $data, $tax->getKey());
        }
    }

    /**
     * @throws ValidationException
     */
    private function throwIfDuplicateCode(QueryException $e, Company $company, array $data, ?int $taxId): void
    {
        if (! str_contains($e->getMessage(), 'Duplicate entry')) {
            throw $e;
        }

        $duplicate = Tax::query()
            ->where('company_id', $company->getKey())
            ->when($taxId, fn ($query) => $query->whereKeyNot($taxId))
            ->where('code', $data['code'] ?? '')
            ->exists();

        if (! $duplicate) {
            // Some other unique index fired. Re-throwing untouched beats reporting
            // a duplicate code that does not exist and hiding the real fault.
            throw $e;
        }

        throw ValidationException::withMessages([
            'code' => 'This tax code is already in use for the selected company.',
        ]);
    }
}
