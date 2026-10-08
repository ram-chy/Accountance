<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A budget version: a plan for one fiscal year, in the company's base currency.
 *
 * A budget is PLANNING DATA. It never posts, never creates a journal, and never
 * touches a ledger balance. The actual figures it is compared against are always
 * read from posted journal lines at report time - see BudgetVarianceReportService.
 *
 * One row is one VERSION. The logical budget "FY27 Operating Plan" is every row
 * sharing a (company_id, code); the rows are distinguished by version_number and
 * chained by parent_budget_id. An approved version is immutable: a change is a
 * new draft version, never an update to the approved row.
 *
 * `company_id`, `financial_year_id`, `status`, `version_number`, `parent_budget_id`,
 * `created_by`, `updated_by`, `approved_by` and `approved_at` are all absent from
 * the fillable list on purpose. They are established by BudgetService from the
 * active company and the authenticated user, never taken from a request body - a
 * client must not be able to mint an approved budget, claim another company's, or
 * point its version chain at somebody else's row.
 */
#[Fillable([
    'code',
    'name',
    'notes',
])]
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'version_number' => 'integer',
            'approved_at' => 'datetime',
            'financial_year_id' => 'integer',
            'parent_budget_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class, 'financial_year_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * The version this one revises, or null for an original.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_budget_id');
    }

    /**
     * The versions that revise this one.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_budget_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    public function isApproved(): bool
    {
        return $this->status->isApproved();
    }
}
