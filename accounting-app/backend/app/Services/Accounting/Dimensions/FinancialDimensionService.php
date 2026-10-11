<?php

namespace App\Services\Accounting\Dimensions;

use App\Enums\AuditAction;
use App\Enums\FinancialDimensionType;
use App\Models\BudgetLineDimension;
use App\Models\Company;
use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;
use App\Models\JournalLineDimension;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Financial dimension master data: dimensions and their values.
 *
 * Nothing in this class writes an amount, a journal or a balance. A dimension is
 * a label a ledger line may carry - the axis a report is cut along - and its own
 * record is ordinary company master data, so the lifecycle here is the one the
 * rest of the application gives master data: create, edit, retire, reactivate,
 * and delete only while nothing has ever depended on it.
 *
 * WHY DELETION IS THE EXCEPTION AND DEACTIVATION IS THE RULE
 *
 * A dimension value that a posted journal line points at is part of what that
 * line means. Deleting it would not remove the posting - the money stays where it
 * is - but it would remove the ability to say which cost centre incurred it, which
 * is exactly the historical meaning Phase 17 exists to preserve. So a referenced
 * value cannot be deleted (the foreign keys say so, and this service says so with
 * a sentence a user can act on), and retiring a dimension is an is_active flag
 * that leaves every historical reference readable.
 *
 * THE TYPE IS NOT EDITABLE
 *
 * `type` is the dimension's identity as far as a report is concerned: a
 * COST_CENTER named "Sales" and a DEPARTMENT named "Sales" are different questions
 * with different values under them. Letting an update move a dimension from one
 * type to another would silently re-categorise every historical assignment, so the
 * type is fixed at creation and absent from the update rules.
 *
 * CONCURRENCY
 *
 * Every write re-reads its row with lockForUpdate, so two concurrent edits of the
 * same code serialise rather than both passing the pre-check; the unique indexes
 * on (company_id, type, code) and (financial_dimension_id, code) remain the final
 * guard, and a race that slips past the lock is translated into the same
 * validation message instead of a 500.
 */
class FinancialDimensionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Record a new dimension for a company. Active by definition.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Company $company, User $actor, array $data): FinancialDimension
    {
        return DB::transaction(function () use ($company, $actor, $data) {
            $dimension = new FinancialDimension;

            $dimension->fill([
                'type' => $data['type'],
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            /*
             * Server-owned, never taken from the payload: the company comes from
             * the authenticated context and a dimension is never created inactive -
             * retiring one is its own act with its own audit row.
             */
            $dimension->company_id = $company->getKey();
            $dimension->is_active = true;

            $this->saveGuardingDuplicate($dimension);

            $this->audit->created($dimension, $actor);

            return $dimension->refresh();
        });
    }

    /**
     * Edit a dimension's code and name. The type and the active flag are untouched.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(FinancialDimension $dimension, User $actor, array $data): FinancialDimension
    {
        return DB::transaction(function () use ($dimension, $actor, $data) {
            $fresh = $this->lock($dimension);

            $fresh->fill([
                'code' => $data['code'] ?? $fresh->code,
                'name' => $data['name'] ?? $fresh->name,
            ]);

            $this->saveGuardingDuplicate($fresh);

            $this->audit->updated($fresh, $actor);

            return $fresh->refresh();
        });
    }

    /**
     * Retire a dimension: it stays readable for history and leaves every picker.
     *
     * @throws ValidationException
     */
    public function deactivate(FinancialDimension $dimension, User $actor): FinancialDimension
    {
        return DB::transaction(function () use ($dimension, $actor) {
            $fresh = $this->lock($dimension);

            if (! $fresh->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'This dimension is already inactive.',
                ]);
            }

            $fresh->is_active = false;
            $fresh->save();

            $this->audit->lifecycle(
                AuditAction::Deactivated,
                $fresh,
                $actor,
                ['is_active' => true],
                ['is_active' => false],
            );

            return $fresh->refresh();
        });
    }

    /**
     * Bring a retired dimension back into use.
     *
     * @throws ValidationException
     */
    public function activate(FinancialDimension $dimension, User $actor): FinancialDimension
    {
        return DB::transaction(function () use ($dimension, $actor) {
            $fresh = $this->lock($dimension);

            if ($fresh->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'This dimension is already active.',
                ]);
            }

            $fresh->is_active = true;
            $fresh->save();

            $this->audit->lifecycle(
                AuditAction::Activated,
                $fresh,
                $actor,
                ['is_active' => false],
                ['is_active' => true],
            );

            return $fresh->refresh();
        });
    }

    /**
     * Delete a dimension outright - only possible while nothing references it.
     *
     * The check and the foreign keys are two layers doing one job: the check
     * produces the message, the constraint closes the window between the check and
     * the delete. Values are removed with the dimension (the value table cascades),
     * so a value that a journal line points at is what stops the delete, not the
     * dimension row itself.
     *
     * @throws ValidationException
     */
    public function delete(FinancialDimension $dimension, User $actor): void
    {
        DB::transaction(function () use ($dimension, $actor) {
            $fresh = $this->lock($dimension);

            $referenced = $this->referencedValueCount($fresh);

            if ($referenced > 0) {
                throw ValidationException::withMessages([
                    'dimension' => sprintf(
                        'This dimension has %d value(s) referenced by journal or budget lines and cannot be '
                        .'deleted without destroying historical meaning. Deactivate it instead.',
                        $referenced
                    ),
                ]);
            }

            try {
                $fresh->delete();
            } catch (QueryException $e) {
                if ($this->isConstraintViolation($e)) {
                    throw ValidationException::withMessages([
                        'dimension' => 'This dimension is referenced by journal or budget lines and cannot be '
                            .'deleted. Deactivate it instead.',
                    ]);
                }

                throw $e;
            }

            $this->audit->deleted($fresh, $actor);
        });
    }

    /**
     * Add a value under a dimension. Active by definition.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createValue(FinancialDimension $dimension, User $actor, array $data): FinancialDimensionValue
    {
        return DB::transaction(function () use ($dimension, $actor, $data) {
            $parent = $this->lock($dimension);

            if (! $parent->is_active) {
                throw ValidationException::withMessages([
                    'financial_dimension_id' => 'An inactive dimension cannot receive new values.',
                ]);
            }

            $value = new FinancialDimensionValue;

            $value->fill([
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            $value->financial_dimension_id = $parent->getKey();
            $value->is_active = true;

            $this->saveGuardingDuplicateValue($value);

            $this->audit->created($value, $actor, [
                'financial_dimension_id' => (int) $parent->getKey(),
                'dimension_code' => $parent->code,
            ]);

            return $value->refresh();
        });
    }

    /**
     * Edit a value's code and name.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateValue(FinancialDimensionValue $value, User $actor, array $data): FinancialDimensionValue
    {
        return DB::transaction(function () use ($value, $actor, $data) {
            $fresh = $this->lockValue($value);

            $fresh->fill([
                'code' => $data['code'] ?? $fresh->code,
                'name' => $data['name'] ?? $fresh->name,
            ]);

            $this->saveGuardingDuplicateValue($fresh);

            $this->audit->updated($fresh, $actor);

            return $fresh->refresh();
        });
    }

    /**
     * Retire a value. Historical references stay readable.
     *
     * @throws ValidationException
     */
    public function deactivateValue(FinancialDimensionValue $value, User $actor): FinancialDimensionValue
    {
        return DB::transaction(function () use ($value, $actor) {
            $fresh = $this->lockValue($value);

            if (! $fresh->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'This dimension value is already inactive.',
                ]);
            }

            $fresh->is_active = false;
            $fresh->save();

            $this->audit->lifecycle(
                AuditAction::Deactivated,
                $fresh,
                $actor,
                ['is_active' => true],
                ['is_active' => false],
            );

            return $fresh->refresh();
        });
    }

    /**
     * Bring a retired value back into use.
     *
     * @throws ValidationException
     */
    public function activateValue(FinancialDimensionValue $value, User $actor): FinancialDimensionValue
    {
        return DB::transaction(function () use ($value, $actor) {
            $fresh = $this->lockValue($value);

            if ($fresh->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'This dimension value is already active.',
                ]);
            }

            $fresh->is_active = true;
            $fresh->save();

            $this->audit->lifecycle(
                AuditAction::Activated,
                $fresh,
                $actor,
                ['is_active' => false],
                ['is_active' => true],
            );

            return $fresh->refresh();
        });
    }

    /**
     * Delete a value outright - only possible while no line has ever used it.
     *
     * @throws ValidationException
     */
    public function deleteValue(FinancialDimensionValue $value, User $actor): void
    {
        DB::transaction(function () use ($value, $actor) {
            $fresh = $this->lockValue($value);

            if ($this->isValueReferenced($fresh)) {
                throw ValidationException::withMessages([
                    'dimension_value' => 'This dimension value is referenced by journal or budget lines and '
                        .'cannot be deleted without destroying historical meaning. Deactivate it instead.',
                ]);
            }

            try {
                $fresh->delete();
            } catch (QueryException $e) {
                if ($this->isConstraintViolation($e)) {
                    throw ValidationException::withMessages([
                        'dimension_value' => 'This dimension value is referenced by journal or budget lines and '
                            .'cannot be deleted. Deactivate it instead.',
                    ]);
                }

                throw $e;
            }

            $this->audit->deleted($fresh, $actor);
        });
    }

    /**
     * How many of this dimension's values are used by a journal or budget line.
     */
    private function referencedValueCount(FinancialDimension $dimension): int
    {
        $valueIds = $dimension->values()->pluck('id');

        if ($valueIds->isEmpty()) {
            return 0;
        }

        return JournalLineDimension::query()
            ->whereIn('financial_dimension_value_id', $valueIds)
            ->count()
            + BudgetLineDimension::query()
                ->whereIn('financial_dimension_value_id', $valueIds)
                ->count();
    }

    private function isValueReferenced(FinancialDimensionValue $value): bool
    {
        return JournalLineDimension::query()
            ->where('financial_dimension_value_id', $value->getKey())
            ->exists()
            || BudgetLineDimension::query()
                ->where('financial_dimension_value_id', $value->getKey())
                ->exists();
    }

    /**
     * Re-read a dimension under a row lock.
     */
    private function lock(FinancialDimension $dimension): FinancialDimension
    {
        return FinancialDimension::query()
            ->whereKey($dimension->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Re-read a value under a lock held on its parent dimension.
     *
     * The parent rather than the value row, because the uniqueness being
     * protected is scoped to the parent: two concurrent creations of the same code
     * under one dimension must serialise against each other, and locking the row
     * that does not exist yet cannot do that.
     */
    private function lockValue(FinancialDimensionValue $value): FinancialDimensionValue
    {
        $this->lockById($value->financial_dimension_id);

        return FinancialDimensionValue::query()
            ->whereKey($value->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockById(int $dimensionId): void
    {
        FinancialDimension::query()
            ->whereKey($dimensionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Persist a dimension, turning a natural-key violation into a human message.
     *
     * @throws ValidationException
     */
    private function saveGuardingDuplicate(FinancialDimension $dimension): void
    {
        try {
            $dimension->save();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'code' => sprintf(
                        'A [%s] dimension with code [%s] already exists in this company.',
                        FinancialDimensionType::from($dimension->type)->label(),
                        $dimension->code
                    ),
                ]);
            }

            throw $e;
        }
    }

    /**
     * @throws ValidationException
     */
    private function saveGuardingDuplicateValue(FinancialDimensionValue $value): void
    {
        try {
            $value->save();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'code' => sprintf(
                        'The value code [%s] already exists under this dimension.',
                        $value->code
                    ),
                ]);
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    private function isConstraintViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23503'], true);
    }
}
