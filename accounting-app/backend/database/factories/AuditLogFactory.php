<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * A company and an actor are created by default because that is the common
     * shape of an audit row. Security rows with a null company are produced by
     * the security() state instead, so a test that cares about that case says so.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'actor_id' => User::factory(),
            'action' => AuditAction::Created->value,
            'auditable_type' => null,
            'auditable_id' => null,
            'request_id' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'before_data' => null,
            'after_data' => null,
            'metadata' => null,
            'created_at' => now(),
        ];
    }

    public function action(AuditAction $action): static
    {
        return $this->state(fn () => ['action' => $action->value]);
    }

    /**
     * A security event with no company and no resource, as a failed login for an
     * unknown address produces.
     */
    public function security(AuditAction $action = AuditAction::LoginFailed): static
    {
        return $this->state(fn () => [
            'company_id' => null,
            'auditable_type' => null,
            'auditable_id' => null,
            'action' => $action->value,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->getKey()]);
    }

    public function byUser(User $user): static
    {
        return $this->state(fn () => ['actor_id' => $user->getKey()]);
    }
}
