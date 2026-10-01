<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Company lifecycle and membership operations.
 *
 * Business rules enforced here, not in controllers:
 *  - a user may belong to many companies but only one may be their default
 *  - a company must be active before it can be selected or made default
 *  - membership rows are unique, and deactivating a company that is somebody's
 *    default moves that default rather than leaving an unusable selection
 */
class CompanyService
{
    public function create(User $creator, array $data, bool $isDefault = true): Company
    {
        try {
            return DB::transaction(function () use ($creator, $data, $isDefault) {
                // is_active is set with forceFill() rather than mass assignment
                // because it is absent from the model's $fillable: only this
                // service may set it. It is set explicitly rather than left to
                // the column default so the in-memory instance matches the
                // database, because attach() checks the value below.
                $company = new Company($data);
                $company->forceFill(['is_active' => true])->save();

                $this->attach($company, $creator, $isDefault);

                // Every company gets a settings row so later phases never have
                // to handle a "company without settings".
                $company->settings()->create();

                return $company->load('settings');
            });
        } catch (QueryException $e) {
            // Rule::unique in the form request is a SELECT followed by an
            // INSERT, so two concurrent creates of the same name can both pass
            // it. The unique index is the real guarantee; this turns the
            // resulting driver error back into the same 422 the request-level
            // check produces, instead of a 500.
            throw $this->asValidationException($e, [
                'name' => 'A company with this name already exists.',
            ]);
        }
    }

    public function update(Company $company, array $data): Company
    {
        try {
            $company->fill($data)->save();
        } catch (QueryException $e) {
            // Same race as create(): the request-level uniqueness check cannot
            // see a row inserted between its SELECT and this UPDATE.
            throw $this->asValidationException($e, [
                'name' => 'A company with this name already exists.',
            ]);
        }

        return $company->refresh();
    }

    /**
     * Re-throw a constraint violation as a validation error, or pass the
     * original exception along when it is not one we can explain.
     */
    private function asValidationException(QueryException $e, array $messages): QueryException|ValidationException
    {
        if ($e->getCode() !== '23000') {
            return $e;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * Deactivate a company.
     *
     * Records are never deleted, because journals and ledger entries will
     * reference this row. Any user who had this company as their default is
     * moved to another active company they belong to, or left without a default
     * if they have none.
     */
    public function deactivate(Company $company): Company
    {
        return DB::transaction(function () use ($company) {
            if (! $company->is_active) {
                return $company;
            }

            $company->forceFill(['is_active' => false])->save();

            $affected = $company->users()
                ->wherePivot('is_default', true)
                ->get();

            foreach ($affected as $user) {
                $replacement = $user->companies()
                    ->where('companies.is_active', true)
                    ->where('companies.id', '!=', $company->getKey())
                    ->orderBy('companies.id')
                    ->first();

                if ($replacement) {
                    $this->makeDefault($user, $replacement);
                } else {
                    // No active company left for this user. The pivot flag is
                    // cleared so the unusable default is not handed back later.
                    $company->users()->updateExistingPivot($user->getKey(), [
                        'is_default' => false,
                    ]);
                }
            }

            return $company->refresh();
        });
    }

    public function activate(Company $company): Company
    {
        $company->forceFill(['is_active' => true])->save();

        return $company->refresh();
    }

    /**
     * Add a user to a company.
     */
    public function attach(Company $company, User $user, bool $isDefault = false): void
    {
        if ($isDefault) {
            $this->assertCompanyIsSelectable($company);

            $this->clearDefault($user);
        }

        try {
            $company->users()->syncWithoutDetaching([
                $user->getKey() => ['is_default' => $isDefault],
            ]);
        } catch (QueryException $e) {
            // The unique index on (company_id, user_id) is the real guard.
            throw ValidationException::withMessages([
                'user_id' => 'This user already belongs to the selected company.',
            ]);
        }
    }

    public function detach(Company $company, User $user): void
    {
        $company->users()->detach($user->getKey());
    }

    /**
     * Make a company the user's default.
     */
    public function makeDefault(User $user, Company $company): void
    {
        $this->assertCompanyIsSelectable($company);

        $this->assertMember($user, $company);

        DB::transaction(function () use ($user, $company) {
            $this->clearDefault($user);

            $company->users()->updateExistingPivot($user->getKey(), [
                'is_default' => true,
            ]);
        });
    }

    /**
     * Find a company the user may actually select.
     *
     * Returns null when the id is unknown, the user is not a member, or the
     * company is inactive. Callers must not be able to tell those cases apart,
     * so a single null is returned for all of them.
     */
    public function findSelectableFor(User $user, int $companyId): ?Company
    {
        $company = Company::query()->find($companyId);

        if (! $company || ! $company->is_active) {
            return null;
        }

        return $company->hasMember($user) ? $company : null;
    }

    /**
     * Assert the user may act inside this company.
     *
     * This is the membership half of company authorization. Permission checks
     * answer "may this role do X"; only this answers "is this user inside the
     * company being addressed", which no role check can establish.
     *
     * @throws ValidationException
     */
    public function assertMember(User $user, Company $company): void
    {
        if (! $company->hasMember($user)) {
            throw ValidationException::withMessages([
                'company' => 'You do not have access to the selected company.',
            ]);
        }
    }

    private function assertCompanyIsSelectable(Company $company): void
    {
        if (! $company->is_active) {
            throw ValidationException::withMessages([
                'company' => 'An inactive company cannot be selected.',
            ]);
        }
    }

    /**
     * Remove the current default flag from every company the user belongs to.
     */
    private function clearDefault(User $user): void
    {
        DB::table('company_user')
            ->where('user_id', $user->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
