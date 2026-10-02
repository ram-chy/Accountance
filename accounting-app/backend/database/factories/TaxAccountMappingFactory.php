<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\Tax;
use App\Models\TaxAccountMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxAccountMapping>
 */
class TaxAccountMappingFactory extends Factory
{
    protected $model = TaxAccountMapping::class;

    /**
     * Maps only the output side, on an OUTPUT tax.
     *
     * Both choices keep the fixture consistent with itself. The default tax is
     * OUTPUT, so only output_account_id is populated - a mapping with both sides
     * set describes a BOTH tax, and a factory that quietly produced one would let
     * a test assert OUTPUT posting rules against a row it never asked for. Tests
     * that want an INPUT or BOTH mapping state it with inputAccount()/bothSides().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_id' => Tax::factory()->output(),
            'company_id' => fn (array $attributes) => Tax::findOrFail($attributes['tax_id'])->company_id,
            'output_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail(
                    Tax::findOrFail($attributes['tax_id'])->company_id
                ))
                ->liability()
                ->create(['code' => '2100', 'name' => 'Tax Payable'])
                ->getKey(),
            'input_account_id' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function outputAccount(Account $account): static
    {
        return $this->state(fn () => ['output_account_id' => $account->getKey()]);
    }

    public function inputAccount(Account $account): static
    {
        return $this->state(fn () => ['input_account_id' => $account->getKey()]);
    }

    /**
     * A mapping on a tax that applies to both sides, with a distinct account for
     * each. The two accounts must differ - a tax that both collects and recovers
     * against one account nets to zero and is unreadable - so the second is
     * created here rather than derived from $account.
     */
    public function bothSides(Account $output, Account $input): static
    {
        return $this->outputAccount($output)->inputAccount($input);
    }
}
