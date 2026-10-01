<?php

namespace App\Services\Accounting;

use App\Enums\DocumentNumberType;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the next number for transactional documents.
 *
 * Mirrors JournalNumberSequence exactly: a per-company counter per document
 * type, allocated inside a transaction with a row lock, and never reused. The
 * counter only moves forward; deleting a draft invoice does not return its
 * number, because an identifier that has been issued to a document that once
 * existed must not be reissued.
 */
class DocumentNumberSequence
{
    /**
     * Allocate the next number for this company and document type.
     *
     * MUST be called inside a transaction. The lock is held until that
     * transaction commits or rolls back, serialising concurrent allocations.
     */
    public function nextFor(Company $company, DocumentNumberType $type): string
    {
        $this->ensureRowExists($company, $type);

        $lastNumber = (int) DB::table('document_number_sequences')
            ->where('company_id', $company->getKey())
            ->where('document_type', $type->value)
            ->lockForUpdate()
            ->value('last_number');

        $next = $lastNumber + 1;

        DB::table('document_number_sequences')
            ->where('company_id', $company->getKey())
            ->where('document_type', $type->value)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return $this->format($company, $type, $next);
    }

    private function ensureRowExists(Company $company, DocumentNumberType $type): void
    {
        DB::table('document_number_sequences')->insertOrIgnore([
            'company_id' => $company->getKey(),
            'document_type' => $type->value,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function format(Company $company, DocumentNumberType $type, int $number): string
    {
        $companySettings = $company->settings()->first();

        $prefix = match ($type) {
            DocumentNumberType::Invoice => $companySettings?->invoice_number_prefix ?? 'INV-',
            DocumentNumberType::Bill => 'BILL-',
            DocumentNumberType::Receipt => 'RCPT-',
            DocumentNumberType::Payment => 'PAY-',
        };

        return $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
