<?php

namespace App\Services\Accounting;

use App\Models\Company;

/**
 * What a document needs to know to resolve a configured tax for one of its lines.
 *
 * A line's arithmetic otherwise depends on three things that the line itself does
 * not carry: which company the taxes belong to, what date the document is (which
 * decides each tax's effective rate), and which side of the ledger the document is
 * on (an OUTPUT tax cannot apply to a purchase).
 *
 * Passed as one nullable object rather than three nullable arguments so that
 * `calculateDocument`'s signature stays readable and so that a caller cannot
 * supply a date without a company - a half-built context would resolve another
 * tenant's taxes or today's rate on a backdated document.
 *
 * Null means "this document is not using configured taxes", which is the Phase 5
 * behaviour where a line carries a hand-entered rate and nothing looks it up. That
 * case is preserved rather than replaced: a document written before this phase, or
 * one whose lines carry only a percentage, must still post.
 */
final readonly class DocumentTaxContext
{
    /**
     * @param  bool  $output  true for a sales document, false for a purchase document
     */
    public function __construct(
        public Company $company,
        public string $date,
        public bool $output,
    ) {}
}
