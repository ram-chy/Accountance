<?php

namespace App\Models;

use Database\Factories\CompanyFxSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Where this company posts realised foreign exchange gains and losses.
 *
 * A one-to-one companion to Company, holding the only company-scoped settings in
 * this phase that are policy rather than data: everything else about a currency is
 * a fact, and everything else about a rate is dated history.
 *
 * The row always exists - company_id is NOT NULL and unique - so "this company has
 * no FX configuration" means "both account columns are null", not "there is no row".
 * Two representations of "not configured" would mean every caller had to handle the
 * missing-row case separately, and one of them would eventually forget.
 */
#[Fillable([
    'realized_gain_account_id',
    'realized_loss_account_id',
])]
class CompanyFxSetting extends Model
{
    /** @use HasFactory<CompanyFxSettingFactory> */
    use HasFactory;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Credited when the settlement is worth MORE base currency than the balance it
     * clears - the cash that arrives exceeds what the receivable carried, and the
     * excess is income.
     *
     * The wording is deliberate, because "clears less than it carried" reads as the
     * opposite case and is how this gets implemented backwards. Compare
     * RealizedFxResult, which states the rule as settlement base less carrying base.
     */
    public function realizedGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'realized_gain_account_id');
    }

    /**
     * Debited when the settlement is worth LESS base currency than the balance it
     * clears: a rate fall means the cash settles for less than the receivable was
     * carried at, and the shortfall is an expense.
     *
     * This is the mirror of realizedGainAccount and exists only so the pair cannot
     * be assigned the wrong way round.
     */
    public function realizedLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'realized_loss_account_id');
    }

    public function isConfigured(): bool
    {
        return $this->realized_gain_account_id !== null
            && $this->realized_loss_account_id !== null;
    }

    /**
     * The account this company's realised FX result posts to in the given direction.
     *
     * Returns null when unconfigured, and the caller decides whether that is fatal.
     * It has to be a query the caller can make explicitly, because "not configured"
     * is only an error for a company that is actually posting foreign currency -
     * for everyone else it is the normal state, and throwing on read would make an
     * ordinary company look broken.
     *
     * @throws RuntimeException when configured but the referenced account is missing,
     *                          which the restrictOnDelete FKs make impossible
     */
    public function accountFor(bool $isGain): ?Account
    {
        $account = $isGain
            ? $this->realizedGainAccount
            : $this->realizedLossAccount;

        if (! $this->isConfigured() && $account !== null) {
            throw new RuntimeException(
                'FX gain and loss accounts must be configured together.'
            );
        }

        return $account;
    }
}
