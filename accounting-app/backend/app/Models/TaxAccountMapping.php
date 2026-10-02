<?php

namespace App\Models;

use Database\Factories\TaxAccountMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which chart-of-accounts accounts one tax posts to.
 *
 * Reuses accounts. This model creates none, and neither does TaxAccountMappingService:
 * a company that wants a "VAT payable" account creates it in the chart of accounts
 * like every other account and points the mapping at it. A tax engine that
 * auto-created accounts would be a second account hierarchy with its own naming
 * scheme, its own validation and its own lifecycle, and the brief forbids it.
 *
 * The account-type rule is not restated here. It is the rule
 * TransactionAccountResolver already applies to `tax_account_id` (LIABILITY) and
 * `input_tax_account_id` (ASSET), and the mapping service calls that resolver
 * rather than open-coding the check, so "where can output tax go" has one answer
 * in the application whether the question arrives through an invoice or through
 * a tax configuration.
 */
#[Fillable([
    'tax_id',
    'output_account_id',
    'input_account_id',
])]
class TaxAccountMapping extends Model
{
    /** @use HasFactory<TaxAccountMappingFactory> */
    use HasFactory;

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function outputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'output_account_id');
    }

    public function inputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'input_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
