<?php

namespace App\Http\Requests\Accounting\Reports;

use App\Enums\PermissionName;

/**
 * Filters for the two tax reports.
 *
 * Extends the Phase 6 base for the shared inclusive date window and its
 * `from <= to` check, and overrides only the permission. That override is the
 * reason this class exists rather than a bare `ReportRequest`: the tax reports are
 * gated on `accounting.tax.report.view`, not on the general
 * `accounting.reports.view` every other report uses.
 *
 * The separation is deliberate. `accounting.reports.view` means "may read the
 * ledger", which a bookkeeper holding the whole general ledger has. Whether that
 * same person may see the company's aggregated tax position is a different
 * question, and in most jurisdictions a different pair of eyes. Sharing one
 * permission would answer both with a single yes.
 *
 * As in the base class, no rule here can name a company: the company comes from
 * the request context, so there is no field by which a caller could ask for
 * another tenant's tax figures.
 */
class TaxReportRequest extends ReportRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(PermissionName::TaxReportView->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->dateRangeRules();
    }
}
