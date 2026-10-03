<?php

namespace App\Http\Requests\Transactions;

use App\Enums\NoteType;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a draft credit/debit note in the active company.
 *
 * THE PAYLOAD IS SHAPED BY THE NOTE TYPE, AND THAT IS WHY rules() IS CONDITIONAL
 *
 * Four types, two sources. A sales note names sales_invoice_id and its lines may
 * name sales_invoice_line_id; a purchase note names purchase_bill_id and its lines
 * may name purchase_bill_line_id. Expressing that with `required_if` on both pairs
 * would validate successfully in the one configuration that must be impossible -
 * both ids present - and rely on a later service to refuse it.
 *
 * So exactly one field of each pair is `required` and the other is `prohibited`.
 * `prohibited` is used rather than `nullable` deliberately: a client that sends
 * purchase_bill_id on a sales note has made a mistake worth surfacing, and
 * silently dropping the field would let it believe it had adjusted a bill.
 *
 * The type is read from the payload in StoreCreditDebitNoteRequest and from the
 * route model in UpdateCreditDebitNoteRequest, where the type is immutable - see
 * that class for why.
 *
 * WHAT THE REQUEST MAY NOT CONTAIN
 *
 * No `note_number`, `company_id`, `status`, `customer_id`, `supplier_id`,
 * `subtotal`, `discount_total`, `tax_total`, `grand_total`, `journal_id`,
 * `created_by`, `posted_by` or `posted_at` rule appears anywhere below, because
 * this request does not validate those parameters at all. They are not ignored -
 * there is no version of this request in which a client-supplied total or
 * counterparty reaches the database. The service copies customer_id or supplier_id
 * from the document it resolves, and allocates the number from the sequence.
 *
 * The absence of a grand_total rule is the important one: the adjustment limit is
 * measured against a server-computed figure (CreditDebitNoteAdjustmentService), so
 * a client cannot name its own limit.
 */
class StoreCreditDebitNoteRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('create', CreditDebitNote::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sales = $this->isSales();

        return [
            'note_type' => ['required', Rule::in(NoteType::values())],

            'sales_invoice_id' => $sales ? $this->sourceRule('sales_invoices') : ['prohibited'],
            'purchase_bill_id' => $sales ? ['prohibited'] : $this->sourceRule('purchase_bills'),

            /*
             * No `after:today` rule, matching StoreSalesInvoiceRequest: backdated
             * notes are normal bookkeeping, and the accounting period check at
             * posting time is what actually decides whether a date is usable.
             */
            'note_date' => ['required', 'date_format:Y-m-d'],

            /*
             * The one field that cannot be derived from the note and the document
             * it adjusts. Required rather than recommended, so an adjustment with
             * no stated cause cannot be filed at all.
             */
            'reason' => ['required', 'string', 'max:5000'],

            /*
             * The counterparty's own document number - a customer's returns note
             * number, a supplier's credit note number. Optional, because not every
             * adjustment has one, and this is the value the journal reference
             * prefers when it is present.
             */
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],

            /*
             * Optional here even when a line carries a tax rate. Whether a taxable
             * note is missing its tax account depends on the computed tax total,
             * which is a whole-document property the calculator reports with an
             * explanation - rather than a conditional rule firing before the totals
             * are known.
             */
            'tax_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'lines' => ['required', 'array', 'min:1', 'max:'.config('accounting.limits.max_lines_per_journal', 500)],
            'lines.*' => ['required', 'array'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => $this->positiveMoneyRule(),

            /*
             * `unit_price` for every note type, including the two purchase types
             * whose source lines use `unit_cost`. One price column and one request
             * field for all four types, so the same key is read throughout - see
             * the note-lines migration for the naming, and DocumentCalculator, which
             * is called with 'unit_price' for every type.
             */
            'lines.*.unit_price' => $this->decimalAmountRule(),
            'lines.*.discount' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_rate' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_ids' => ['sometimes', 'array'],
            'lines.*.tax_ids.*' => ['integer'],

            /*
             * Source-line references are nullable: a note may adjust a document in
             * total rather than line by line, and both forms are legitimate. When
             * one IS given, the service checks it belongs to the note's own source
             * document and that the adjusted quantity stays within the source
             * quantity - see CreditDebitNoteAdjustmentService::assertLineWithinLimit.
             *
             * No exists() rule here, and that is a considered choice rather than an
             * omission. An unscoped exists() would answer only "no such row" - and
             * would answer it differently for another tenant's real id, which leaks
             * that the id exists somewhere. The service's single message ("does not
             * belong to the invoice this note adjusts") is returned for a
             * non-existent id, a foreign id and an id belonging to another invoice
             * alike, so the field cannot be used to probe for other tenants' rows.
             */
            'lines.*.sales_invoice_line_id' => $sales ? ['nullable', 'integer'] : ['prohibited'],
            'lines.*.purchase_bill_line_id' => $sales ? ['prohibited'] : ['nullable', 'integer'],

            /*
             * Existence here; appropriateness (must be a REVENUE account for a sales
             * note, an EXPENSE account for a purchase one, and active) is decided by
             * TransactionAccountResolver from the note's own type, so there is no
             * call site at which the role could be supplied wrongly.
             */
            'lines.*.account_id' => $this->companyAccountRule(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(
            $this->prohibitedMessages(),
            [
                'reason.required' => 'A credit or debit note must state why the adjustment is being made.',
                'lines.*.quantity.gt' => 'A line quantity must be greater than zero.',
            ]
        );
    }

    /**
     * One message per impossible pair, naming the fix rather than the rule.
     *
     * @return array<string, string>
     */
    private function prohibitedMessages(): array
    {
        return [
            'sales_invoice_id.prohibited' => 'A purchase note adjusts a purchase bill, so sales_invoice_id must not be sent.',
            'purchase_bill_id.prohibited' => 'A sales note adjusts a sales invoice, so purchase_bill_id must not be sent.',
            'lines.*.sales_invoice_line_id.prohibited' => 'A purchase note adjusts a purchase bill, so sales_invoice_line_id must not be sent.',
            'lines.*.purchase_bill_line_id.prohibited' => 'A sales note adjusts a sales invoice, so purchase_bill_line_id must not be sent.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sourceRule(string $table): array
    {
        return [
            'required',
            'integer',
            Rule::exists($table, 'id')->where('company_id', $this->activeCompanyId()),
        ];
    }

    /**
     * Is this a sales note?
     *
     * Read from the payload, and only when the value is one of the four known
     * types. An absent or unrecognised note_type falls back to false so the rules
     * are still a complete set - and note_type's own `in` rule is what reports the
     * actual problem, rather than this method guessing which shape to validate.
     */
    private function isSales(): bool
    {
        return NoteType::tryFrom((string) $this->input('note_type'))?->isSales() ?? false;
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }
}
