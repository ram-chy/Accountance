<?php

namespace App\Services\Accounting\Budgets;

use App\Enums\AuditAction;
use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The budget lifecycle: create a draft, edit a draft, discard a draft, approve a
 * draft, and revise an approved plan into a new draft version.
 *
 * NOTHING HERE TOUCHES THE LEDGER. A budget is planning data and the only thing
 * this service writes is the budgets table. The actuals a budget is measured
 * against are read from posted journals by BudgetVarianceReportService, never
 * stored, so no budget row can ever disagree with the ledger about what happened.
 *
 * APPROVED VERSIONS ARE IMMUTABLE
 *
 * `update()` and `delete()` refuse an approved budget. A change to an approved
 * plan goes through `revise()`, which creates a NEW draft row - a new version in
 * the same (company, code) chain - and copies the approved lines forward. The
 * approved figures are therefore never overwritten; they remain the record of
 * what was actually authorised, which is the only reason approving anything is
 * meaningful.
 *
 * Every state change re-reads the row under a lock before deciding. The literal
 * "is it still a draft?" check is what makes two concurrent approvals, or an
 * approval racing an edit, resolve to exactly one outcome rather than both.
 *
 * The caller has already been authorized by BudgetPolicy against the active
 * company, and the model it is handed came through the company-scoped route
 * binding (or a company-filtered query). The service still re-asserts the
 * company on every path, because a service is a public seam that a future
 * console command or job will call without a route binding in front of it.
 */
class BudgetService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Raise a new draft budget, version 1.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Company $company, User $actor, array $data): Budget
    {
        $financialYear = $this->resolveFinancialYear($company, (int) $data['financial_year_id']);

        return DB::transaction(function () use ($company, $actor, $data, $financialYear) {
            $budget = new Budget;

            $budget->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
            ]);

            /*
             * Server-owned fields are assigned directly, never filled from the
             * payload. A client cannot name the company, mint a version number,
             * point the parent chain, or - most importantly - create something
             * other than a DRAFT.
             */
            $budget->company_id = $company->getKey();
            $budget->financial_year_id = $financialYear->getKey();
            $budget->version_number = 1;
            $budget->parent_budget_id = null;
            $budget->status = BudgetStatus::Draft;
            $budget->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->saveGuardingUniqueVersion($budget);

            $this->audit->created($budget, $actor);

            return $budget->refresh();
        });
    }

    /**
     * Edit a draft budget's descriptive fields.
     *
     * A budget's financial year is deliberately not editable: its lines reference
     * that year's accounting periods, so moving it to another year would leave
     * every line pointing at a period outside the budget. A wrong-year budget is
     * deleted and recreated, which is honest, rather than silently re-homed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Budget $budget, Company $company, User $actor, array $data): Budget
    {
        $this->assertBelongsToCompany($budget, $company);

        return DB::transaction(function () use ($budget, $actor, $data) {
            $fresh = $this->lock($budget);

            $this->assertDraft($fresh, 'edited');

            $fresh->fill([
                'code' => $data['code'] ?? $fresh->code,
                'name' => $data['name'] ?? $fresh->name,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $fresh->notes,
            ]);

            $fresh->forceFill(['updated_by' => $actor->getKey()]);

            $this->saveGuardingUniqueVersion($fresh);

            $this->audit->updated($fresh, $actor);

            return $fresh->refresh();
        });
    }

    /**
     * Discard a draft budget outright.
     *
     * Only a draft may be deleted. An approved budget is a control record: the
     * way to supersede it is to revise it, and there is deliberately no path that
     * erases an approved plan from history. Lines are removed by the database's
     * ON DELETE CASCADE, so there is no half-deleted budget to reason about.
     *
     * @throws ValidationException
     */
    public function delete(Budget $budget, Company $company, User $actor): void
    {
        $this->assertBelongsToCompany($budget, $company);

        DB::transaction(function () use ($budget, $actor) {
            $fresh = $this->lock($budget);

            $this->assertDraft($fresh, 'deleted');

            $this->audit->deleted($fresh, $actor);

            $fresh->delete();
        });
    }

    /**
     * Finalize a draft.
     *
     * Approval refuses a budget with no lines: approving an empty plan records a
     * decision about nothing, and the variance report it produces would be a page
     * of zeros with no way to tell "planned nothing" from "not filled in yet". A
     * plan with no lines is not yet a plan.
     *
     * @throws ValidationException
     */
    public function approve(Budget $budget, Company $company, User $actor): Budget
    {
        $this->assertBelongsToCompany($budget, $company);

        return DB::transaction(function () use ($budget, $actor) {
            $fresh = $this->lock($budget);

            $this->assertDraft($fresh, 'approved');

            if ($fresh->lines()->count() === 0) {
                throw ValidationException::withMessages([
                    'lines' => 'A budget must have at least one line before it can be approved.',
                ]);
            }

            $fresh->forceFill([
                'status' => BudgetStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->lifecycle(
                AuditAction::Approved,
                $fresh,
                $actor,
                ['status' => BudgetStatus::Draft->value],
                ['status' => BudgetStatus::Approved->value],
            );

            return $fresh->refresh();
        });
    }

    /**
     * Revise an approved budget into a new draft version.
     *
     * The new row shares the source's (company, code), takes the next version
     * number in the chain, and points back at the source through parent_budget_id.
     * Its lines are copies: editing or deleting them cannot touch the approved
     * version, which is the entire point of versioning a budget rather than
     * editing it.
     *
     * Only an APPROVED budget may be revised. Revising a draft would let a
     * partially filled plan spawn unattached versions of itself, so the rule is
     * simply that a revision supersedes a decision, and only an approved budget
     * carries one.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function revise(Budget $budget, Company $company, User $actor, array $data = []): Budget
    {
        $this->assertBelongsToCompany($budget, $company);

        return DB::transaction(function () use ($budget, $actor, $data) {
            $source = $this->lock($budget)->load('lines');

            if (! $source->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an approved budget can be revised. Edit the draft directly while it is still a draft.',
                ]);
            }

            $nextVersion = (int) Budget::query()
                ->where('company_id', $source->company_id)
                ->where('code', $source->code)
                ->max('version_number') + 1;

            $revision = new Budget;

            $revision->fill([
                'code' => $source->code,
                'name' => $data['name'] ?? $source->name,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $source->notes,
            ]);

            $revision->company_id = $source->company_id;
            $revision->financial_year_id = $source->financial_year_id;
            $revision->version_number = $nextVersion;
            $revision->parent_budget_id = $source->getKey();
            $revision->status = BudgetStatus::Draft;
            $revision->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->saveGuardingUniqueVersion($revision);

            foreach ($source->lines as $line) {
                $revision->lines()->create([
                    'account_id' => $line->account_id,
                    'accounting_period_id' => $line->accounting_period_id,
                    'amount' => $line->getRawOriginal('amount'),
                    'description' => $line->description,
                ]);
            }

            $this->audit->created($revision, $actor, [
                'revised_budget_id' => $source->getKey(),
                'source_version' => $source->version_number,
            ]);

            return $revision->refresh();
        });
    }

    /**
     * Resolve a financial year that belongs to the active company.
     *
     * The request rule scopes the id to the company as well, but repeating the
     * scope here means a future caller without a request in front of it cannot
     * create a budget in one company for another company's year.
     *
     * @throws ValidationException
     */
    private function resolveFinancialYear(Company $company, int $financialYearId): FinancialYear
    {
        $year = FinancialYear::query()
            ->where('company_id', $company->getKey())
            ->find($financialYearId);

        if ($year === null) {
            throw ValidationException::withMessages([
                'financial_year_id' => 'The selected financial year does not belong to the active company.',
            ]);
        }

        return $year;
    }

    private function assertBelongsToCompany(Budget $budget, Company $company): void
    {
        if ((int) $budget->company_id !== (int) $company->getKey()) {
            throw (new ModelNotFoundException)->setModel(Budget::class, [$budget->getKey()]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(Budget $budget, string $act): void
    {
        if (! $budget->isDraft()) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'An approved budget cannot be %s. Raise a new version to change it.',
                    $act
                ),
            ]);
        }
    }

    private function lock(Budget $budget): Budget
    {
        return Budget::query()->whereKey($budget->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Save, turning the (company, code, version_number) unique violation into a
     * validation error rather than a 500.
     *
     * The existence check the create/update paths could do first is a race: two
     * requests can both pass it, and the unique index is what actually decides.
     * Catching its error keeps the message the user sees the same whether the
     * clash was found before or after the insert.
     */
    private function saveGuardingUniqueVersion(Budget $budget): void
    {
        try {
            $budget->save();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'code' => sprintf(
                        'A budget with code [%s] already exists in version %d for this company.',
                        $budget->code,
                        $budget->version_number
                    ),
                ]);
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 is the SQL standard integrity-constraint class, which MySQL uses
        // for a duplicate key and PostgreSQL for a unique violation.
        return $e->getCode() === '23000';
    }
}
