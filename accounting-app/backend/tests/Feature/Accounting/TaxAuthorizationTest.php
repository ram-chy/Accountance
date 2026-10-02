<?php

namespace Tests\Feature\Accounting;

use App\Enums\PermissionName;
use App\Models\Tax;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may configure and use taxes, and whose taxes they can reach.
 *
 * Two different protections, kept in one file because both are about the boundary
 * the tax feature must not cross:
 *
 *  - Capability. `accounting.tax.*` is granted to Accountant and, read-only, to
 *    Manager. Staff get nothing, and the reason is not tidiness: `calculate` and
 *    `report` disclose a company's effective tax rates, which is exactly the
 *    disclosure Staff is not entitled to.
 *  - Tenant. A tax id from another company is a 404, not a 403, because a 403 would
 *    confirm that the id exists.
 */
class TaxAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_accountant_may_configure_and_calculate(): void
    {
        $this->assertGranted('Accountant', [
            PermissionName::TaxView,
            PermissionName::TaxCreate,
            PermissionName::TaxUpdate,
            PermissionName::TaxDelete,
            PermissionName::TaxCalculate,
            PermissionName::TaxReportView,
        ]);
    }

    /**
     * A manager reads tax configuration and its reports, and may use the
     * calculation endpoint to answer a question, but does not change what the
     * company charges.
     */
    #[Test]
    public function a_manager_may_read_and_calculate_but_not_configure(): void
    {
        $this->assertGranted('Manager', [
            PermissionName::TaxView,
            PermissionName::TaxCalculate,
            PermissionName::TaxReportView,
        ]);

        $this->assertDenied('Manager', [
            PermissionName::TaxCreate,
            PermissionName::TaxUpdate,
            PermissionName::TaxDelete,
        ]);
    }

    /**
     * Staff get no tax capability at all. Asserted on the permission rather than by
     * walking every endpoint, because the permission list is what Staff's role is
     * actually made of.
     */
    #[Test]
    public function staff_are_granted_no_tax_capability(): void
    {
        $this->assertDenied('Staff', [
            PermissionName::TaxView,
            PermissionName::TaxCreate,
            PermissionName::TaxUpdate,
            PermissionName::TaxDelete,
            PermissionName::TaxCalculate,
            PermissionName::TaxReportView,
        ]);
    }

    #[Test]
    public function staff_cannot_read_the_tax_list(): void
    {
        $user = $this->createUserWithRole('Staff');
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/taxes')
            ->assertForbidden();
    }

    #[Test]
    public function staff_cannot_create_a_tax(): void
    {
        $user = $this->createUserWithRole('Staff');
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/taxes', [
                'code' => 'VAT',
                'name' => 'Value Added Tax',
                'tax_type' => 'OUTPUT',
                'calculation_basis' => 'EXCLUSIVE',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('taxes', ['company_id' => $company->getKey()]);
    }

    #[Test]
    public function a_manager_cannot_create_a_rate(): void
    {
        $user = $this->createUserWithRole('Manager');
        $company = $this->createCompanyFor($user);
        $tax = Tax::factory()->for($company)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/taxes/{$tax->getKey()}/rates", [
                'rate' => '10.0000',
                'effective_from' => '2026-01-01',
            ])
            ->assertForbidden();
    }

    /**
     * Rate endpoints authorize against the rate, so they are the ones that prove the
     * policy is registered for the rate's own class rather than only for the tax.
     */
    #[Test]
    public function a_manager_cannot_delete_a_rate(): void
    {
        $user = $this->createUserWithRole('Manager');
        $company = $this->createCompanyFor($user);
        $tax = Tax::factory()->for($company)->create();
        $rate = TaxRate::factory()->for($tax)->rate('10.0000')->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounting/taxes/{$tax->getKey()}/rates/{$rate->getKey()}")
            ->assertForbidden();

        $this->assertDatabaseHas('tax_rates', ['id' => $rate->getKey()]);
    }

    /**
     * A tax from another company does not exist as far as this request is concerned.
     */
    #[Test]
    public function another_companys_tax_is_not_found(): void
    {
        $user = $this->createUserWithRole('Accountant');
        $company = $this->createCompanyFor($user);

        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);
        $foreign = Tax::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/taxes/{$foreign->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function another_companys_rate_is_not_found(): void
    {
        $user = $this->createUserWithRole('Accountant');
        $company = $this->createCompanyFor($user);

        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);

        $ownTax = Tax::factory()->for($company)->create();
        $foreignTax = Tax::factory()->for($other)->create();
        $foreignRate = TaxRate::factory()->for($foreignTax)->rate('10.0000')->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounting/taxes/{$ownTax->getKey()}/rates/{$foreignRate->getKey()}")
            ->assertNotFound();
    }

    /**
     * The listing is scoped to the active company even when several exist.
     */
    #[Test]
    public function the_tax_list_contains_only_the_active_companys_taxes(): void
    {
        $user = $this->createUserWithRole('Accountant');
        $company = $this->createCompanyFor($user);

        Tax::factory()->for($company)->create(['code' => 'MINE', 'name' => 'Mine']);

        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);
        Tax::factory()->for($other)->create(['code' => 'THEIRS', 'name' => 'Theirs']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/taxes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'MINE');

        $this->assertStringNotContainsString('THEIRS', $response->getContent());
    }

    /**
     * The calculation endpoint resolves taxes company-scoped, so a cross-company id
     * in the body is a validation error and not a silent success.
     */
    #[Test]
    public function calculating_with_another_companys_tax_is_refused(): void
    {
        $user = $this->createUserWithRole('Accountant');
        $company = $this->createCompanyFor($user);

        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);
        $foreign = Tax::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/tax/calculate', [
                'amount' => '100.0000',
                'date' => '2026-06-15',
                'tax_ids' => [$foreign->getKey()],
            ])
            ->assertStatus(422);
    }

    /**
     * The calculation endpoint needs no configuration right of its own to be
     * reachable, but it does need the permission; Staff must not be able to use it
     * to read the company's rates through a 422's error text.
     */
    #[Test]
    public function staff_cannot_use_the_calculation_endpoint(): void
    {
        $user = $this->createUserWithRole('Staff');
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/tax/calculate', [
                'amount' => '100.0000',
                'date' => '2026-06-15',
            ])
            ->assertForbidden();
    }

    /**
     * A manager may use the calculator: reading the effective rate for a date is
     * what the Manager grant of `calculate` is for.
     */
    #[Test]
    public function a_manager_may_use_the_calculation_endpoint(): void
    {
        $user = $this->createUserWithRole('Manager');
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/tax/calculate', [
                'amount' => '100.0000',
                'date' => '2026-06-15',
            ])
            ->assertOk()
            ->assertJsonPath('data.total_tax', '0.0000');
    }

    #[Test]
    public function an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/accounting/taxes')->assertUnauthorized();
    }

    /**
     * @param  list<PermissionName>  $permissions
     */
    private function assertGranted(string $role, array $permissions): void
    {
        $user = $this->createUserWithRole($role);

        foreach ($permissions as $permission) {
            $this->assertTrue(
                $user->can($permission->value),
                "{$role} should hold [{$permission->value}]."
            );
        }
    }

    /**
     * @param  list<PermissionName>  $permissions
     */
    private function assertDenied(string $role, array $permissions): void
    {
        $user = $this->createUserWithRole($role);

        foreach ($permissions as $permission) {
            $this->assertFalse(
                $user->can($permission->value),
                "{$role} should not hold [{$permission->value}]."
            );
        }
    }
}
