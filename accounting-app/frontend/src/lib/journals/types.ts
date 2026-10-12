/**
 * Journal entry data contracts.
 *
 * These mirror the verified Laravel `JournalResource` / `JournalLineResource`
 * and the journal routes exactly (see `JournalController.php`, `JournalService`,
 * `JournalPostingService`, `StoreJournalRequest`, `UpdateJournalRequest` and
 * `ValidatesJournalLines`). Money is carried as decimal *strings* — never
 * numbers — because the backend validates and stores decimals as strings to
 * avoid a double round-trip, and the UI must not re-introduce one.
 *
 * Payloads for `POST /journals` and `PUT /journals/{journal}` are written in the
 * snake_case the backend validates. Identifiers, `journal_number`, `status`,
 * `source_type`, `created_by`, `posted_by` and `posted_at` are server-owned and
 * are never sent by the browser.
 */

export type JournalStatusValue = "DRAFT" | "POSTED";

export const JOURNAL_STATUS_VALUES: readonly JournalStatusValue[] = [
  "DRAFT",
  "POSTED",
] as const;

/**
 * Document that produced a journal, mirroring the backend `JournalSource` enum.
 * `MANUAL` is the default for a journal typed on this screen; the transactional
 * values belong to other modules that post through the same table. A null or
 * unrecognised value is read as manual.
 *
 * The list mirrors every case of `App\Enums\JournalSource` exactly. It is a
 * closed union rather than an open string so a source this build does not know
 * is treated as unrecognised (and rendered as a manual entry) instead of being
 * displayed as a fabricated label.
 */
export type JournalSourceValue =
  | "MANUAL"
  | "ADJUSTMENT"
  | "SALES_INVOICE"
  | "PURCHASE_BILL"
  | "CUSTOMER_RECEIPT"
  | "SUPPLIER_PAYMENT"
  | "CASH_BANK_TRANSACTION"
  | "CREDIT_DEBIT_NOTE"
  | "FIXED_ASSET"
  | "FIXED_ASSET_DEPRECIATION"
  | "FIXED_ASSET_DISPOSAL";

export const JOURNAL_SOURCE_VALUES: readonly JournalSourceValue[] = [
  "MANUAL",
  "ADJUSTMENT",
  "SALES_INVOICE",
  "PURCHASE_BILL",
  "CUSTOMER_RECEIPT",
  "SUPPLIER_PAYMENT",
  "CASH_BANK_TRANSACTION",
  "CREDIT_DEBIT_NOTE",
  "FIXED_ASSET",
  "FIXED_ASSET_DEPRECIATION",
  "FIXED_ASSET_DISPOSAL",
] as const;

/** One row from the verified `JournalLineResource`. */
export type JournalLine = {
  id: number;
  line_number: number;
  account_id: number;
  /** Account code/name are present when the account relation was loaded. */
  account_code: string | null;
  account_name: string | null;
  description: string | null;
  /** Base-currency amount, one side always zero. Decimal string. */
  debit: string;
  credit: string;
  /** Foreign-currency metadata (Phase 14); null for a base-currency line. */
  currency_id: number | null;
  currency_code: string | null;
  exchange_rate: string | null;
  foreign_debit: string | null;
  foreign_credit: string | null;
};

/**
 * Server-derived totals. Present only when the lines are loaded — the index
 * endpoint always loads them, so every list row carries its own totals, but a
 * header-only payload would not.
 */
export type JournalTotals = {
  total_debit: string;
  total_credit: string;
  is_balanced: boolean;
};

/** One journal from the verified `JournalResource`. */
export type Journal = {
  id: number;
  journal_number: string;
  journal_date: string;
  description: string | null;
  reference: string | null;
  status: JournalStatusValue;
  source_type: JournalSourceValue | null;
  source_id: number | null;
  created_by: number | null;
  posted_by: number | null;
  posted_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  /** Always an array; empty when the resource did not load lines. */
  lines: JournalLine[];
  totals: JournalTotals | null;
};

/** Client-side status filter; empty means "all statuses". */
export type JournalListFilters = {
  search: string;
  status: JournalStatusValue | "";
  from: string;
  to: string;
};

export const DEFAULT_JOURNAL_FILTERS: JournalListFilters = {
  search: "",
  status: "",
  from: "",
  to: "",
};

/**
 * Validated list state, mirroring `AccountListState`. `perPage` is carried so a
 * short page can be recognised for the flat-array pagination caveat.
 */
export type JournalListState =
  | { status: "loading"; items: []; perPage: number; hasMore: false }
  | { status: "unavailable"; items: []; perPage: number; hasMore: false; message: string }
  | {
      status: "error";
      items: [];
      perPage: number;
      hasMore: false;
      message: string;
      requestId?: string;
    }
  | { status: "ready"; items: Journal[]; perPage: number; hasMore: boolean };

/** A single line as submitted to the backend. Both sides are always sent. */
export type JournalLineInput = {
  account_id: number;
  description: string | null;
  debit: string;
  credit: string;
};

/** The validated create/update body. */
export type JournalWritePayload = {
  journal_date: string;
  description: string | null;
  reference: string | null;
  lines: JournalLineInput[];
};
