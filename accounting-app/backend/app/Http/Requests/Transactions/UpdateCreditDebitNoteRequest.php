<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a draft credit/debit note.
 *
 * THE SOURCE AND THE TYPE ARE NOT AMONG THE RULES
 *
 * There is no `note_type`, `sales_invoice_id` or `purchase_bill_id` rule here, and
 * that is the single most consequential decision in this class rather than an
 * oversight. A note's source decides its counterparty, its tax side, which account
 * role each line may use, which source lines are adjustable and the entire
 * adjustment-limit calculation - so re-pointing an existing note would mean
 * re-deriving all of that from a document that its persisted lines have no
 * relationship to. The result could be a note whose lines reference invoice A and
 * whose accounting adjusts invoice B.
 *
 * The cost of forbidding it is that a draft raised against the wrong invoice has to
 * be deleted and re-raised, which consumes a number that is never reused and
 * nothing else. The alternative was judged and rejected: it is the only way this
 * module could produce a note that misrepresents which document it adjusted.
 *
 * `customer_id` and `supplier_id` are absent for the same reason, and additionally
 * because they are copied from the source document rather than accepted from the
 * client at any point in the note's life.
 *
 * EVERY OTHER FIELD IS `sometimes`
 *
 * A partial update is a partial update. `sometimes` means an absent key is simply
 * not written, so a client fixing a typo in `reason` does not have to resend the
 * lines - and cannot accidentally blank a field by omitting it.
 *
 * `status` is not among the rules either. A client cannot move a note to POSTED
 * through this endpoint; the only way to post is CreditDebitNotePostingService,
 * which is the only thing that can create the journal with it.
 */
class UpdateCreditDebitNoteRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->note());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sales = $this->note()?->note_type->isSales() ?? false;

        return [
            'note_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'required', 'string', 'max:5000'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'tax_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            /*
             * All-or-nothing when present, for the reason UpdateSalesInvoiceRequest
             * does the same: there is no way to replace a subset of lines, and a
             * payload that tried would either lose lines or duplicate them.
             * DocumentCalculator recomputes every total from whatever arrives.
             */
            'lines' => ['sometimes', 'required', 'array', 'min:1', 'max:'.config('accounting.limits.max_lines_per_journal', 500)],
            'lines.*' => ['required', 'array'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => $this->positiveMoneyRule(),
            'lines.*.unit_price' => $this->decimalAmountRule(),
            'lines.*.discount' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_rate' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_ids' => ['sometimes', 'array'],
            'lines.*.tax_ids.*' => ['integer'],

            'lines.*.sales_invoice_line_id' => $sales ? ['nullable', 'integer'] : ['prohibited'],
            'lines.*.purchase_bill_line_id' => $sales ? ['prohibited'] : ['nullable', 'integer'],
            'lines.*.account_id' => $this->companyAccountRule(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.sales_invoice_line_id.prohibited' => 'A purchase note adjusts a purchase bill, so sales_invoice_line_id must not be sent.',
            'lines.*.purchase_bill_line_id.prohibited' => 'A sales note adjusts a sales invoice, so purchase_bill_line_id must not be sent.',
            'lines.*.quantity.gt' => 'A line quantity must be greater than zero.',
        ];
    }

    /**
     * The note being updated.
     *
     * Nullable because authorize() runs before the route model is guaranteed to be
     * resolved in every Laravel path, and a null here must produce an ordinary 403
     * rather than a fatal. rules() treats it the same way: with no note there is no
     * type, so both source-line fields are prohibited and the request is rejected -
     * which is the correct outcome for a request that cannot be attributed to a note.
     */
    private function note(): ?CreditDebitNote
    {
        $note = $this->route('creditDebitNote');

        if ($note instanceof CreditDebitNote) {
            return $note;
        }

        if (is_string($note) && ctype_digit($note)) {
            try {
                return CreditDebitNote::query()->findOrFail((int) $note);
            } catch (ModelNotFoundException) {
                return null;
            }
        }

        return null;
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }
}
