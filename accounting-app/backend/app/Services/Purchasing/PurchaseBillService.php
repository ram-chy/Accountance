<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentNumberType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\DocumentCalculator;
use App\Services\Accounting\DocumentNumberSequence;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase bills: draft lifecycle.
 *
 * The counterpart of SalesInvoiceService, differing in three places and no more:
 * the counterparty is a supplier, lines carry unit_cost and an expense account,
 * and tax is recovered rather than charged - which is why the tax account on a
 * bill is an ASSET (input tax receivable from the authority) while the tax
 * account on an invoice is a LIABILITY (output tax owed to it).
 *
 * Everything else - server-allocated numbers, server-computed totals, status
 * lifecycle, draft-only editing, the split from posting - is identical on
 * purpose.
 */
class PurchaseBillService
{
    public function __construct(
        private readonly DocumentNumberSequence $numbers,
        private readonly AccountingPeriodService $periods,
        private readonly DocumentCalculator $calculator,
        private readonly TransactionAccountResolver $accounts,
        private readonly SupplierService $suppliers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createDraft(Company $company, User $actor, array $data): PurchaseBill
    {
        $supplier = $this->resolveSupplier($company, $data['supplier_id']);

        return DB::transaction(function () use ($company, $actor, $data, $supplier) {
            $number = $this->numbers->nextFor($company, DocumentNumberType::Bill);

            $bill = new PurchaseBill([
                'supplier_id' => $supplier->getKey(),
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
                'tax_account_id' => $data['tax_account_id'] ?? null,
            ]);

            $bill->forceFill([
                'company_id' => $company->getKey(),
                'bill_number' => $number,
                'status' => TransactionStatus::Draft->value,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->writeLines($company, $bill, $data['lines'] ?? []);

            return $bill->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function updateDraft(PurchaseBill $bill, Company $company, array $data): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $company, $data) {
            $fresh = PurchaseBill::query()
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            if (array_key_exists('supplier_id', $data)) {
                $fresh->supplier_id = $this->resolveSupplier($company, $data['supplier_id'])->getKey();
            }

            /*
             * Phase 8: re-dating a draft may not target a closed period. See
             * JournalService::updateDraft for why this is checked on the date
             * only, and why it is checked at all.
             */
            if (array_key_exists('bill_date', $data)) {
                $this->periods->assertDateNotClosed($company, Carbon::parse($data['bill_date']), 'bill_date');
            }

            // Absent and explicit null are different requests; see the same
            // comment in CustomerReceiptService::updateDraft.
            foreach (['bill_date', 'due_date', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fresh->{$field} = $data[$field];
                }
            }

            if (array_key_exists('tax_account_id', $data)) {
                $fresh->tax_account_id = $data['tax_account_id'];
            }

            $fresh->save();

            if (array_key_exists('lines', $data)) {
                $this->writeLines($company, $fresh, $data['lines']);
            }

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function deleteDraft(PurchaseBill $bill): void
    {
        DB::transaction(function () use ($bill) {
            $fresh = PurchaseBill::query()
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            $fresh->delete();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private function writeLines(Company $company, PurchaseBill $bill, array $lines): void
    {
        $totals = $this->calculator->calculateDocument($lines, 'unit_cost');

        $this->calculator->assertTaxAccountPresent(
            Money::of($totals['tax_total']),
            $bill->tax_account_id
        );

        $expenseAccounts = $this->resolveLineAccounts($company, $lines);

        $bill->forceFill([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ])->save();

        $bill->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $calculated = $totals['lines'][$index];

            $bill->lines()->create([
                'line_number' => $calculated['line_number'],
                'description' => $line['description'] ?? null,
                'quantity' => $calculated['quantity'],
                'unit_cost' => $calculated['unit_price'],
                'discount' => $calculated['discount'],
                'tax_rate' => $calculated['tax_rate'],
                'tax_amount' => $calculated['tax_amount'],
                'line_total' => $calculated['line_total'],
                'expense_account_id' => $expenseAccounts[$index]->getKey(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, Account>
     *
     * @throws ValidationException
     */
    private function resolveLineAccounts(Company $company, array $lines): array
    {
        $resolved = [];
        $errors = [];

        foreach (array_values($lines) as $index => $line) {
            try {
                $resolved[$index] = $this->accounts->expense($company, (int) ($line['expense_account_id'] ?? 0));
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    $errors["lines.{$index}.expense_account_id"] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @throws ValidationException
     */
    private function resolveSupplier(Company $company, int $supplierId): Supplier
    {
        $supplier = Supplier::query()
            ->where('company_id', $company->getKey())
            ->whereKey($supplierId)
            ->first();

        if ($supplier === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'The selected supplier does not belong to the active company.',
            ]);
        }

        $this->suppliers->assertUsableForBilling($supplier);

        return $supplier;
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(PurchaseBill $bill): void
    {
        if (! $bill->status->isDraft()) {
            throw ValidationException::withMessages([
                'bill' => 'This bill has been posted and is part of the accounting record. '
                    .'It cannot be edited or deleted; record a debit note instead.',
            ]);
        }
    }
}
