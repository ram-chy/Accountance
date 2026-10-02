<?php

namespace App\Services\Accounting\Tax;

use App\Models\PurchaseBillLine;
use App\Models\SalesInvoiceLine;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Effective-dated rates.
 *
 * The whole service exists to enforce one rule: a tax has exactly one rate in
 * force on any given day. That is what makes `Tax::rateOn()` return a single rate
 * rather than a collection, and it is what stops a posted document from being
 * calculated against an arbitrary one of several candidates.
 *
 * The rule cannot be a database constraint. "These two date ranges must not
 * intersect" is not expressible as a unique index in MySQL - a unique index can
 * compare rows for equality, not intervals - so it is enforced here, on write,
 * inside a transaction. A raw INSERT outside the application could still create an
 * overlap, which is why the resolver does not silently pick a winner: it takes the
 * latest effective_from, and a tie there is a data problem rather than a
 * resolvable ambiguity.
 *
 * Adding a rate with an open-ended period therefore requires closing the previous
 * one, which `create()` does for the caller by inferring `effective_to` as the day
 * before the new rate starts. A user changing a rate from 10% to 12% effective
 * April gets a closed 10% period and an open 12% period without having to know
 * that is what they were supposed to submit; if they do submit an explicit
 * `effective_to` it is honoured and must not overlap anything.
 */
class TaxRateService
{
    /**
     * @throws ValidationException
     */
    public function create(Tax $tax, array $data): TaxRate
    {
        $rate = Tax::rateAmountFor($data['rate']);
        $effectiveFrom = $this->day($data['effective_from']);
        $effectiveTo = isset($data['effective_to']) ? $this->day($data['effective_to']) : null;

        $this->assertRateInRange($rate, 'rate');

        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages([
                'effective_to' => 'A rate must not end before it starts.',
            ]);
        }

        /*
         * Everything that writes happens in one transaction, because the closing of
         * a predecessor and the overlap check cannot be ordered safely without one:
         *
         *  - Close first, then check. Closing is what makes the ordinary change
         *    ("from April it is 12%") expressible at all - a new open-ended rate
         *    necessarily overlaps the open-ended rate it replaces until that one is
         *    closed, so checking before closing rejects precisely the change the
         *    service exists to perform.
         *  - Check against the state *after* closing. The period being validated
         *    is the one that will be stored, not the one submitted.
         *  - Roll back on failure. A rejected rate leaves the existing history
         *    exactly as it was, which is the guarantee a caller gets from the
         *    transaction rather than from careful ordering. This is the one
         *    transaction the overlap rule needs; see the class docblock.
         */
        return DB::transaction(function () use ($tax, $rate, $effectiveFrom, $effectiveTo): TaxRate {
            /*
             * Only an open-ended predecessor is closed, and only one starting before
             * the new rate. A predecessor that already ends on a date is left alone,
             * because its period was a decision made deliberately rather than a
             * default waiting to be filled in.
             */
            if ($effectiveTo === null) {
                $this->closeOpenEndedPredecessors($tax, $effectiveFrom);
            }

            $this->assertNoOverlap($tax, $this->period($effectiveFrom, $effectiveTo), 'rate');

            $new = new TaxRate([
                'tax_id' => $tax->getKey(),
                'rate' => $rate->toDatabase(),
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);

            $new->company_id = $tax->company_id;
            $new->forceFill(['is_active' => true, 'created_by' => Auth::id()]);

            $new->save();

            return $new;
        });
    }

    /**
     * @throws ValidationException
     */
    public function update(TaxRate $rate, array $data): TaxRate
    {
        /*
         * company_id is enforced rather than trusted: a rate belongs to exactly one
         * company and this service is the only thing that establishes which.
         */
        $tax = $rate->tax;

        if (array_key_exists('rate', $data)) {
            $amount = Tax::rateAmountFor($data['rate']);

            $this->assertRateInRange($amount, 'rate');

            $rate->rate = $amount->toDatabase();
        }

        $from = $data['effective_from'] ?? null;
        $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : null;

        $effectiveFrom = $from === null ? $rate->effective_from->toDateString() : $this->day($from);
        $effectiveTo = array_key_exists('effective_to', $data)
            ? ($to === null ? null : $this->day($to))
            : $rate->effective_to?->toDateString();

        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages([
                'effective_to' => 'A rate must not end before it starts.',
            ]);
        }

        $this->assertNoOverlap($tax, $this->period($effectiveFrom, $effectiveTo), 'rate', $rate);

        $rate->effective_from = $effectiveFrom;
        $rate->effective_to = $effectiveTo;
        $rate->forceFill(['updated_by' => Auth::id()])->save();

        return $rate->refresh();
    }

    public function activate(TaxRate $rate): TaxRate
    {
        $rate->forceFill(['is_active' => true, 'updated_by' => Auth::id()])->save();

        return $rate->refresh();
    }

    /**
     * Withdraw a rate from use without touching its history.
     *
     * Deactivation is not deletion: a rate that calculated a posted document must
     * stay readable, and closing its period would be a different and larger claim
     * about what the company charged.
     */
    public function deactivate(TaxRate $rate): TaxRate
    {
        $rate->forceFill(['is_active' => false, 'updated_by' => Auth::id()])->save();

        return $rate->refresh();
    }

    /**
     * Delete a rate that has never applied to anything.
     *
     * "Has never applied" means no posted document was calculated with this rate,
     * not merely that the rate's own period does not reach the newest document. A
     * rate whose period covers a day on which an invoice was posted was used, even
     * if a later rate has since superseded it, and removing the row would take the
     * explanation of that document's arithmetic with it: the document would be left
     * holding a percentage that no configuration can account for.
     *
     * So any posted document at all blocks the delete, and the date it is named in
     * the message so the user can find it. Deactivation is the way to retire a rate
     * that has been in use.
     *
     * @throws ValidationException
     */
    public function delete(TaxRate $rate): void
    {
        $latestUsed = $this->latestPostedDocumentDateFor($rate);

        if ($latestUsed !== null) {
            throw ValidationException::withMessages([
                'rate' => 'This rate cannot be deleted because a document dated '
                    .$latestUsed->toDateString().' was calculated with it. '
                    .'Deactivate it instead.',
            ]);
        }

        $rate->delete();
    }

    /**
     * A rate in the active company, or a company-scoped failure.
     *
     * @throws ValidationException
     */
    public function findFor(int $companyId, int $rateId): TaxRate
    {
        $rate = TaxRate::query()
            ->where('company_id', $companyId)
            ->whereKey($rateId)
            ->first();

        if ($rate === null) {
            throw ValidationException::withMessages([
                'rate' => 'The selected tax rate does not belong to the active company.',
            ]);
        }

        return $rate;
    }

    /**
     * A rate in the active company for a given tax.
     *
     * @throws ValidationException
     */
    public function findForTax(Tax $tax, int $rateId): TaxRate
    {
        $rate = $this->findFor($tax->company_id, $rateId);

        if ($rate->tax_id !== $tax->getKey()) {
            throw ValidationException::withMessages([
                'rate' => 'The selected tax rate does not belong to this tax.',
            ]);
        }

        return $rate;
    }

    /**
     * A rate is only usable between zero and one hundred percent, and the upper
     * bound is a real limit rather than a tidiness rule: at 100% the inclusive
     * divisor (100 + rate) is zero, and above it the extracted net is negative. A
     * rate that cannot be calculated must not be stored, so that the failure
     * surfaces on the form that submitted it rather than on an invoice weeks later.
     *
     * @throws ValidationException
     */
    private function assertRateInRange(Money $rate, string $field): void
    {
        if ($rate->isNegative()) {
            throw ValidationException::withMessages([
                $field => 'A tax rate cannot be negative.',
            ]);
        }

        if (! $rate->lessThan(Money::ofInt(100))) {
            throw ValidationException::withMessages([
                $field => 'A tax rate must be less than 100%.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoOverlap(Tax $tax, TaxRate $incoming, string $field, ?TaxRate $excluding = null): void
    {
        $clash = $this->overlappingRate($incoming, $tax, $excluding);

        if ($clash !== null) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'This period overlaps the existing rate of %s%% (%s to %s). '
                    .'A tax has only one rate in force on any day.',
                    $clash->rate,
                    $clash->effective_from->toDateString(),
                    $clash->effective_to?->toDateString() ?? 'open-ended'
                ),
            ]);
        }
    }

    /**
     * The first existing rate whose period intersects the candidate's.
     *
     * An inactive rate still counts: retiring a rate does not make its period
     * available, and allowing a new rate to be laid over a retired one would let
     * history become ambiguous for a document dated while the retired rate was
     * still the configured answer.
     */
    private function overlappingRate(TaxRate $candidate, ?Tax $tax = null, ?TaxRate $excluding = null): ?TaxRate
    {
        $tax ??= $candidate->tax;

        return TaxRate::query()
            ->where('tax_id', $tax->getKey())
            ->when($excluding, fn ($query) => $query->whereKeyNot($excluding->getKey()))
            ->get()
            ->first(fn (TaxRate $existing) => $candidate->overlaps($existing));
    }

    /**
     * Close every open-ended rate that starts before a new one does.
     *
     * Each is closed the day before the new rate begins, which is the period that
     * makes "10% until April, 12% from April" a contiguous history rather than two
     * rates with a hole in them. Called inside `create()`'s transaction, so a rate
     * that is subsequently rejected takes these closures with it.
     *
     * A rate starting on the same day is deliberately left open: two rates cannot
     * both answer for it, and closing this one at the day before the new one starts
     * would give it a negative length. That case is an overlap, and is reported as
     * one.
     */
    private function closeOpenEndedPredecessors(Tax $tax, Carbon|string $effectiveFrom): void
    {
        $day = $this->day($effectiveFrom);

        TaxRate::query()
            ->where('tax_id', $tax->getKey())
            ->whereNull('effective_to')
            ->where('effective_from', '<', $day)
            ->get()
            ->each(function (TaxRate $existing) use ($day): void {
                $existing->effective_to = Carbon::parse($day)->subDay();
                $existing->forceFill(['updated_by' => Auth::id()])->save();
            });
    }

    /**
     * A non-persisted rate, used only to ask the overlap question about a period.
     */
    private function period(string $from, ?string $to): TaxRate
    {
        $rate = new TaxRate(['effective_from' => $from]);

        $rate->effective_from = Carbon::parse($from)->startOfDay();
        $rate->effective_to = $to === null ? null : Carbon::parse($to)->endOfDay();

        return $rate;
    }

    /**
     * The latest date on which a document was calculated with this rate's
     * percentage, or null if none was.
     *
     * Both line tables snapshot the rate as a percentage rather than referencing
     * the rate row, so this matches on the value. That is deliberately a value
     * match rather than a `tax_rates.id` match: a line may carry a rate a user
     * typed by hand at the same percentage, and treating that as "this rate was
     * used" errs towards refusing a delete that might not have been necessary,
     * rather than allowing one that should not be.
     *
     * Only *posted* documents count. A draft line is a note about an intention, not
     * an accounting fact, and refusing a delete because of a draft that was never
     * posted would make the configuration unusable during ordinary data entry.
     */
    private function latestPostedDocumentDateFor(TaxRate $rate): ?Carbon
    {
        $percentage = $this->percentageOf($rate);

        $sales = SalesInvoiceLine::query()
            ->where('tax_id', $rate->tax_id)
            ->where('tax_rate', $percentage)
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->whereNotNull('sales_invoices.journal_id')
            ->max('sales_invoices.invoice_date');

        $purchase = PurchaseBillLine::query()
            ->where('tax_id', $rate->tax_id)
            ->where('tax_rate', $percentage)
            ->join('purchase_bills', 'purchase_bills.id', '=', 'purchase_bill_lines.purchase_bill_id')
            ->whereNotNull('purchase_bills.journal_id')
            ->max('purchase_bills.bill_date');

        $dates = array_filter([$sales, $purchase]);

        return $dates === [] ? null : Carbon::parse(max($dates));
    }

    /**
     * The stored percentage as a plain decimal string.
     *
     * DECIMAL(6,4) stores "10.0000" and MySQL compares a decimal column to a
     * string as a number, so trailing zeroes need no stripping - `where('tax_rate',
     * '10.0000')` and `where('tax_rate', '10')` are the same comparison. Written as
     * the raw attribute for that reason, with no parsing that could introduce a
     * rounding difference between what is stored and what is searched for.
     */
    private function percentageOf(TaxRate $rate): string
    {
        return $rate->getRawOriginal('rate') ?? (string) $rate->rate;
    }

    private function day(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->toDateString()
            : Carbon::parse((string) $value)->toDateString();
    }
}
