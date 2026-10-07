<?php

namespace Tests\Feature\Currency;

use App\Enums\PermissionName;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may configure currencies and exchange rates.
 *
 * The line this file defends is that currency master data is GLOBAL while a rate
 * is company-scoped, so the grant that lets the Accountant price a foreign invoice
 * must not also let it change what every tenant may invoice in. The assertions are
 * on permissions rather than on endpoints because the permission list is what the
 * role is actually made of.
 */
class CurrencyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_accountant_manages_rates_and_fx_but_not_the_currency_master(): void
    {
        $this->assertGranted('Accountant', [
            PermissionName::CurrencyView,
            PermissionName::ExchangeRateView,
            PermissionName::ExchangeRateCreate,
            PermissionName::ExchangeRateUpdate,
            PermissionName::FxUpdate,
            PermissionName::ControlsView,
        ]);

        $this->assertDenied('Accountant', [
            PermissionName::CurrencyCreate,
            PermissionName::CurrencyUpdate,
            PermissionName::CurrencyActivate,
            PermissionName::CurrencyDeactivate,
        ]);
    }

    #[Test]
    public function a_manager_reads_rates_and_controls_but_configures_nothing(): void
    {
        $this->assertGranted('Manager', [
            PermissionName::CurrencyView,
            PermissionName::ExchangeRateView,
            PermissionName::ControlsView,
        ]);

        $this->assertDenied('Manager', [
            PermissionName::ExchangeRateCreate,
            PermissionName::ExchangeRateUpdate,
            PermissionName::FxUpdate,
            PermissionName::CurrencyCreate,
            PermissionName::CurrencyUpdate,
            PermissionName::CurrencyActivate,
            PermissionName::CurrencyDeactivate,
        ]);
    }

    #[Test]
    public function staff_are_granted_no_currency_capability(): void
    {
        $this->assertDenied('Staff', [
            PermissionName::CurrencyView,
            PermissionName::CurrencyCreate,
            PermissionName::CurrencyUpdate,
            PermissionName::CurrencyActivate,
            PermissionName::CurrencyDeactivate,
            PermissionName::ExchangeRateView,
            PermissionName::ExchangeRateCreate,
            PermissionName::ExchangeRateUpdate,
            PermissionName::FxUpdate,
            PermissionName::ControlsView,
        ]);
    }

    #[Test]
    public function an_admin_holds_every_currency_capability_through_its_wildcard(): void
    {
        $this->assertGranted('Admin', [
            PermissionName::CurrencyView,
            PermissionName::CurrencyCreate,
            PermissionName::CurrencyUpdate,
            PermissionName::CurrencyActivate,
            PermissionName::CurrencyDeactivate,
            PermissionName::ExchangeRateView,
            PermissionName::ExchangeRateCreate,
            PermissionName::ExchangeRateUpdate,
            PermissionName::FxUpdate,
            PermissionName::ControlsView,
        ]);
    }

    #[Test]
    public function currency_master_writes_are_refused_without_the_permission(): void
    {
        $user = $this->createUserWithRole('Staff');
        $currency = Currency::factory()->create();

        $this->assertFalse($user->can('update', $currency));
        $this->assertFalse($user->can('deactivate', $currency));
        $this->assertFalse($user->can('create', Currency::class));
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
