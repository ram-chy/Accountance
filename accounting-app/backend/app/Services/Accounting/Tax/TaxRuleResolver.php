<?php

namespace App\Services\Accounting\Tax;

use App\Models\Company;
use App\Models\Tax;
use App\Models\TaxRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Decides which configured taxes apply to a transaction.
 *
 * This is the whole of the "rules" layer, and it is small on purpose. It takes
 * the inputs the brief lists that this application actually has - a transaction
 * side, an optional list of specific taxes, and a date - and answers with taxes.
 *
 * There is no geographic or jurisdiction resolution here, and that is a decision
 * rather than an omission. Every jurisdiction-specific concept the brief mentions
 * (place of supply, ship-from, ship-to, customer registration) would be a *filter*
 * on top of this, and each needs a column somewhere that this phase does not have
 * and the brief does not ask for. The method shape leaves room for those filters
 * without them: inputs() is the single place a new criterion would be declared,
 * and the rest of the class would not change.
 *
 * What it does do, and why each part earns its place:
 *
 *  - A tax that does not apply to this side of the transaction is excluded. An
 *    INPUT tax on a sales invoice would post a debit to an asset on a document
 *    whose only liability is to the customer.
 *  - A tax with no active rate on the date is excluded here but reported by
 *    TaxCalculationService if it was explicitly named. The distinction matters:
 *    "this tax is configured but has no rate today" is a mistake worth refusing
 *    when the user picked it, and unremarkable when they did not.
 *  - An empty list of named taxes is honoured as "none", never as "all". See
 *    resolve()'s early return.
 *  - Ordering is by code, not by id. Two documents naming the same two taxes must
 *    produce the same components in the same order regardless of insertion order,
 *    or an invoice would differ from its own re-save for no accounting reason.
 */
class TaxRuleResolver
{
    /**
     * The taxes that apply to a transaction, in deterministic order.
     *
     * @param  bool  $output  true for a sales side, false for a purchase side
     * @param  Collection<int, int>|null  $taxIds  restrict to these taxes; null means every active tax for the side
     * @return Collection<int, Tax>
     */
    public function resolve(
        Company $company,
        bool $output,
        Carbon|string $date,
        ?Collection $taxIds = null,
    ): Collection {
        /*
         * An empty list is a statement, not an absence.
         *
         * `tax_ids: []` means "no taxes on this document" and must return no taxes.
         * Handled by an explicit early return because the obvious version of this
         * method gets it wrong: `when($taxIds, ...)` treats an empty collection as
         * false, so the restriction is dropped and *every* active tax for the side
         * comes back - a caller asking for no tax would silently be charged all of
         * them. The same falsey-collection trap is why the query below tests
         * `$taxIds !== null` explicitly rather than relying on truthiness.
         */
        if ($taxIds !== null && $taxIds->isEmpty()) {
            return new Collection;
        }

        $taxes = Tax::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->with(['accountMapping'])
            ->when($taxIds !== null, fn ($query) => $query->whereIn('id', $taxIds))
            ->orderBy('code')
            ->get()
            /*
             * Filtered in PHP rather than in SQL because the predicate is
             * `appliesToSales()`, an enum method. Expressing it as a whereIn over
             * the two applicable enum values would be a second encoding of the same
             * fact, and the two would drift the first time a fourth value were
             * added. The set is every tax of one company - tens of rows - so this is
             * not a performance concern, and the rates are loaded in one query
             * below regardless.
             */
            ->filter(fn (Tax $tax) => $output
                ? $tax->tax_type->appliesToSales()
                : $tax->tax_type->appliesToPurchase());

        if ($taxIds === null) {
            return $taxes;
        }

        /*
         * A tax the caller named explicitly is not silently dropped for having no
         * rate on the date. A configuration that exists but is unusable today is a
         * mistake the user needs told about; quietly excluding it would calculate a
         * document at a different rate than the user asked for, which is the exact
         * failure this phase exists to prevent.
         */
        $this->assertNamedTaxesUsable($company, $taxIds, $taxes, $output, 'tax_ids');

        return $taxes;
    }

    /**
     * The taxes that apply, and a rate in force for each.
     *
     * Used by a caller that needs to know a tax is applicable *and* calculable
     * before it starts - a form rendering the taxes available on a date, or a
     * caller that wants to skip rather than fail on a tax with no current rate.
     *
     * @param  Collection<int, int>|null  $taxIds
     * @return Collection<int, Tax>
     */
    public function applicableWithRates(
        Company $company,
        bool $output,
        Carbon|string $date,
        ?Collection $taxIds = null,
    ): Collection {
        $taxes = $this->resolve($company, $output, $date, $taxIds);

        if ($taxes->isEmpty()) {
            return $taxes;
        }

        /*
         * Every candidate's rates in one query, then resolved in memory. This is
         * the N+1 the brief calls out: a document with ten lines each naming two
         * taxes would otherwise issue twenty rate lookups to answer one question.
         * The rates are immutable configuration for the duration of the call, so
         * reusing them across lines is safe as well as cheap.
         */
        $ratesByTax = TaxRate::query()
            ->whereIn('tax_id', $taxes->pluck('id'))
            ->where('is_active', true)
            ->orderBy('effective_from')
            ->get()
            ->groupBy('tax_id');

        return $taxes
            ->filter(fn (Tax $tax) => $tax->rateOn($date, $ratesByTax->get($tax->id)) !== null)
            ->values();
    }

    /**
     * Assert every explicitly named tax belongs to the company and may be used on
     * this side.
     *
     * All of them are fetched in one query rather than one per id: a caller naming
     * four taxes would otherwise cost four round trips to answer a single
     * validation question.
     *
     * The message for a wrong-company tax deliberately cannot distinguish it from
     * one that does not exist, so this cannot be used to discover another tenant's
     * tax ids.
     *
     * @param  Collection<int, int>  $requested
     * @param  Collection<int, Tax>  $applicable
     *
     * @throws ValidationException
     */
    private function assertNamedTaxesUsable(Company $company, Collection $requested, Collection $applicable, bool $output, string $field): void
    {
        $found = $applicable->pluck('id')->all();
        $side = $output ? 'sales' : 'purchase';

        $named = Tax::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $requested)
            ->get()
            ->keyBy('id');

        $errors = [];

        foreach ($requested as $id) {
            $tax = $named->get($id);

            if ($tax === null) {
                $errors["{$field}.{$id}"] = 'The selected tax does not belong to the active company.';
            } elseif (! $tax->is_active) {
                /*
                 * Reported distinctly from a wrong-side tax, and deliberately
                 * against an earlier version of this method that merged the two.
                 *
                 * The earlier caution was that naming the reason leaks configuration
                 * of a tax the caller may not see. That does not hold here: to reach
                 * this branch the id must already belong to the caller's own active
                 * company, and every role that can name a tax on a document in this
                 * application also holds `accounting.tax.view`, so the tax record is
                 * already theirs to read.
                 *
                 * The merged message was actively harmful in the other direction. A
                 * deactivated OUTPUT tax named on an invoice was reported as "does
                 * not apply to sales transactions", which sends the reader looking
                 * for a tax_type problem that does not exist - and the obvious fix
                 * for an OUTPUT tax that seems not to apply to sales is to change
                 * its type to BOTH, corrupting the configuration of every document
                 * the tax has ever been used on. Naming the true cause costs nothing
                 * and prevents that.
                 */
                $errors["{$field}.{$id}"] = "Tax [{$tax->code}] is inactive and cannot be charged.";
            } elseif (! in_array($tax->id, $found, true)) {
                $errors["{$field}.{$id}"] = "Tax [{$tax->code}] does not apply to {$side} transactions.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
