<?php

namespace App\Http\Requests\Accounting\Reports;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Base class for every Phase 6 report filter request.
 *
 * One permission gates all of them (`accounting.reports.view`), so the
 * authorization rule is stated once here rather than repeated eleven times and
 * left to drift. A report request is read-only by construction: it can accept
 * dates and company-scoped ids, and nothing it accepts can name a company_id,
 * a journal_id or a balance, because no such rule exists in any subclass.
 *
 * The date-range rule lives here too. Reports share an inclusive, date-only
 * window, and centralising `from_date <= to_date` means a report cannot be added
 * later without the check.
 */
abstract class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(PermissionName::ReportsView->value);
    }

    /**
     * The active company, resolved from the request context and never the body.
     */
    protected function activeCompany(): Company
    {
        return app(CompanyContext::class)->getOrFail();
    }

    protected function activeCompanyId(): int
    {
        return $this->activeCompany()->getKey();
    }

    /**
     * The shared, optional date window.
     *
     * `from`/`to` are accepted as aliases for the existing Phase 4 ledger
     * endpoints' parameter names, so a client that already calls
     * `/accounting/trial-balance?from=...` can call the report endpoints the same
     * way. The canonical names are the brief's `from_date`/`to_date`.
     *
     * @return array<string, mixed>
     */
    protected function dateRangeRules(): array
    {
        return [
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function fromDate(): ?Carbon
    {
        return $this->parseDate($this->input('from_date') ?? $this->input('from'));
    }

    public function toDate(): ?Carbon
    {
        return $this->parseDate($this->input('to_date') ?? $this->input('to'));
    }

    /**
     * The optional point-in-time bound.
     *
     * @return array<string, mixed>
     */
    protected function asOfRules(): array
    {
        return ['as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d']];
    }

    /**
     * The `to` bound defaulting to today, for point-in-time reports.
     */
    public function toDateOrDefault(): Carbon
    {
        return $this->toDate() ?? Carbon::today();
    }

    /**
     * The point-in-time bound for receivables/payables and aging, defaulting to
     * today. Distinct from `to_date` because these reports answer "as we stand
     * now" unless a caller asks otherwise, and conflating the two would let a
     * P&L window silently move an aging report's reference date.
     */
    public function asOfDate(): Carbon
    {
        return $this->parseDate($this->input('as_of')) ?? Carbon::today();
    }

    /**
     * An account id that must be one of the active company's accounts.
     *
     * `exists` alone would accept an id from any company, so the constraint is
     * added here rather than trusted to the controller. Company-scoped route
     * model binding does not apply to a query-string id.
     *
     * @return array<int, mixed>
     */
    protected function companyAccountRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function companyCustomerRule(bool $required = true): array
    {
        return array_merge($required ? ['required'] : ['sometimes', 'nullable'], [
            'integer',
            Rule::exists('customers', 'id')->where('company_id', $this->activeCompanyId()),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function companySupplierRule(bool $required = true): array
    {
        return array_merge($required ? ['required'] : ['sometimes', 'nullable'], [
            'integer',
            Rule::exists('suppliers', 'id')->where('company_id', $this->activeCompanyId()),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $from = $this->fromDate();
            $to = $this->toDate();

            if ($from !== null && $to !== null && $from->greaterThan($to)) {
                $validator->errors()->add(
                    'to_date',
                    'The to date must be on or after the from date.'
                );
            }
        });
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', (string) $value) ?: null;
    }
}
