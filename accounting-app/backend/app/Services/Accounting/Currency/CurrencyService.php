<?php

namespace App\Services\Accounting\Currency;

use App\Enums\AuditAction;
use App\Models\Currency;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The currency master.
 *
 * SCOPE, AND WHY IT IS THE ONLY ACCOUNTING SERVICE WITHOUT A COMPANY
 *
 * Every other service in this phase and the eleven before it is scoped to a
 * company. CurrencyService is not, because currencies are global reference data -
 * see the create migration for why that is a decision rather than an oversight.
 *
 * The consequence for this class is that it must be the strictest about
 * authorization, because its writes are the one thing in the system that affects
 * every tenant. A company may read every currency (a picker has to show what the
 * company can invoice in); it may create or change one only under an explicit
 * permission, and those permissions are not part of the ordinary company-settings
 * set.
 *
 * WHY THERE IS NO DELETE, ONLY DEACTIVATE
 *
 * A currency that a posted document or an exchange rate referenced must stay
 * readable forever - it defines what the numbers on that document mean. Deleting
 * one would not remove the meaning, it would remove the ability to find out what it
 * was. So the lifecycle is reversible all the way to deactivation and stops there,
 * exactly as accounts.is_active and taxes.is_active do.
 *
 * WHY NO SEEDER
 *
 * The phase brief forbids one and the schema was designed to need none: a currency
 * is created here, through the same validated path as everything else, so nothing
 * writes to a financial table by a route an operator cannot see. A fresh install
 * therefore has no currencies until someone creates them, and a company with no
 * base currency books single-currency documents at an implicit rate of 1. That
 * trade is recorded in PHASE_14_REPORT.md rather than hidden.
 */
class CurrencyService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, ?User $actor = null): Currency
    {
        $code = $this->normaliseCode($data['code']);

        $this->assertCodeIsFree($code);
        $this->assertPrecisionIsSane($data['decimal_precision'] ?? 2);

        $currency = new Currency;
        $currency->fill([
            'code' => $code,
            'name' => trim((string) $data['name']),
            'symbol' => isset($data['symbol']) && $data['symbol'] !== ''
                ? mb_substr((string) $data['symbol'], 0, 10)
                : null,
            'decimal_precision' => (int) ($data['decimal_precision'] ?? 2),
        ]);

        // A new currency is active by definition. Lifecycle is a separate,
        // separately-audited transition - see the class docblock.
        $currency->forceFill(['is_active' => true]);

        DB::transaction(function () use ($currency, $actor): void {
            $currency->save();

            $this->audit->created($currency, $actor);
        });

        return $currency->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Currency $currency, array $data, ?User $actor = null): Currency
    {
        if (array_key_exists('code', $data)) {
            $code = $this->normaliseCode($data['code']);

            // A code change must not collide with a different row. Comparing against
            // the currency's own id is what makes "rename USD to US dollar" work
            // while "rename EUR to USD" is refused.
            if ($code !== $currency->code) {
                $this->assertCodeIsFree($code, $currency);
            }

            $currency->code = $code;
        }

        if (array_key_exists('name', $data)) {
            $currency->name = trim((string) $data['name']);
        }

        if (array_key_exists('symbol', $data)) {
            $currency->symbol = $data['symbol'] === null || $data['symbol'] === ''
                ? null
                : mb_substr((string) $data['symbol'], 0, 10);
        }

        if (array_key_exists('decimal_precision', $data)) {
            $this->assertPrecisionIsSane($data['decimal_precision']);
            $currency->decimal_precision = (int) $data['decimal_precision'];
        }

        DB::transaction(function () use ($currency, $actor): void {
            $currency->save();

            /*
             * AuditService::updated() derives before/after from getChanges() itself,
             * so nothing is passed here. Handing it a snapshot as well would file
             * the same information twice, once correctly and once as whatever
             * subset the caller remembered to include - and the second copy would
             * drift.
             */
            $this->audit->updated($currency, $actor);
        });

        return $currency->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function deactivate(Currency $currency, ?User $actor = null): Currency
    {
        if (! $currency->is_active) {
            throw ValidationException::withMessages([
                'currency' => 'This currency is already inactive.',
            ]);
        }

        $before = $this->auditSnapshot($currency);

        DB::transaction(function () use ($currency, $before, $actor): void {
            $currency->forceFill(['is_active' => false])->save();

            $this->audit->lifecycle(
                AuditAction::Deactivated,
                $currency,
                $actor,
                $before,
                $this->auditSnapshot($currency),
            );
        });

        return $currency->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function activate(Currency $currency, ?User $actor = null): Currency
    {
        if ($currency->is_active) {
            throw ValidationException::withMessages([
                'currency' => 'This currency is already active.',
            ]);
        }

        $before = $this->auditSnapshot($currency);

        DB::transaction(function () use ($currency, $before, $actor): void {
            $currency->forceFill(['is_active' => true])->save();

            $this->audit->lifecycle(
                AuditAction::Activated,
                $currency,
                $actor,
                $before,
                $this->auditSnapshot($currency),
            );
        });

        return $currency->refresh();
    }

    /**
     * Find a currency by code, or null.
     *
     * Not foundOrFail: resolving a code the caller supplied should be able to
     * distinguish "no such currency" from "that code is not usable", and a
     * ValidationException naming the field is the honest answer in both cases.
     */
    public function findByCode(string $code): ?Currency
    {
        return Currency::query()
            ->withCode($code)
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public function findActiveByCodeOrFail(string $code, string $field = 'currency_code'): Currency
    {
        $currency = $this->findByCode($code);

        if ($currency === null) {
            throw ValidationException::withMessages([
                $field => 'No currency exists with the code ['.mb_strtoupper($code).'].',
            ]);
        }

        if (! $currency->is_active) {
            /*
             * A separate message from "no such currency" on purpose. They mean
             * different things to whoever is looking at the error: one is a typo, the
             * other is a configuration change that has not been made yet, and
             * collapsing them into "unknown currency" sends someone hunting for a
             * typo that does not exist.
             */
            throw ValidationException::withMessages([
                $field => 'Currency ['.$currency->code.'] exists but is inactive.',
            ]);
        }

        return $currency;
    }

    /**
     * Is this currency safe to activate a company against?
     *
     * Separate from findActiveByCodeOrFail because the base-currency decision has a
     * consequence the general lookup does not: it reinterprets every future
     * document for that company, so the caller needs to know the currency is active
     * and is not the same object as another company's.
     */
    public function isUsableAsBaseCurrency(Currency $currency): bool
    {
        return $currency->is_active;
    }

    private function normaliseCode(string $code): string
    {
        $normalised = mb_strtoupper(trim($code));

        if (preg_match('/^[A-Z]{3}$/', $normalised) !== 1) {
            throw ValidationException::withMessages([
                'code' => 'A currency code must be exactly three letters, such as USD.',
            ]);
        }

        return $normalised;
    }

    private function assertCodeIsFree(string $code, ?Currency $except = null): void
    {
        $query = Currency::query()->withCode($code);

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => "A currency with the code [{$code}] already exists.",
            ]);
        }
    }

    private function assertPrecisionIsSane(mixed $precision): void
    {
        $value = (int) $precision;

        /*
         * Upper bound of 4 is the ISO 4217 reality, not an arbitrary cap: the widest
         * minor unit in circulation is 3 (BHD, JOD, KWD, OMR). Allowing more would
         * mean accepting a currency whose amounts could not be represented in the
         * ledger's own DECIMAL(20,4) without the currency being unable to express
         * itself - a mismatch that surfaces as an unroundable document.
         */
        if ($value < 0 || $value > 4) {
            throw ValidationException::withMessages([
                'decimal_precision' => 'A currency must have between 0 and 4 decimal places.',
            ]);
        }
    }

    /**
     * The fields worth keeping in an audit row.
     *
     * Small and deliberate: an audit trail that stored the whole row would be a
     * second copy of the table, and the fields that matter for a currency are the
     * ones a person would ask about - what it is called, whether it is live, and
     * how precise its amounts are.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(Currency $currency): array
    {
        return [
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'decimal_precision' => $currency->decimal_precision,
            'is_active' => $currency->is_active,
        ];
    }
}
