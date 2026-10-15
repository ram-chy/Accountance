/**
 * Exchange-rate data contracts.
 *
 * These mirror the verified Laravel `ExchangeRateResource` and the
 * exchange-rate routes exactly (see `ExchangeRateController.php`,
 * `ExchangeRateResource.php` and `FRONTEND_API_HANDOFF.md` §9). Rates are
 * **company-scoped and dated**: the company comes from the server-side proxy
 * context, so no request carries a company id.
 *
 * DIRECTION
 *
 * `rate` is units of the **to** currency per ONE unit of the **from** currency,
 * and conversion is always a multiply — nothing divides. The resource carries
 * the codes it is built from so a client can render `USD/INR` without resolving
 * two currency ids.
 *
 * The `rate` is a decimal **string**, never a JSON number: a double cannot
 * round-trip the stored value exactly, and it must match the ledger's DECIMAL
 * math. The client never does arithmetic on it.
 */

export type ExchangeRateCurrencyRef = {
  id: number;
  code: string;
  name: string;
};

export type ExchangeRate = {
  id: number;
  from_currency_id: number;
  to_currency_id: number;
  /** The from currency's code, present when the relation was loaded. */
  from_currency: string | null;
  /** The to currency's code, present when the relation was loaded. */
  to_currency: string | null;
  /** The direction string, e.g. `USD/INR`, present when the relation was loaded. */
  pair: string | null;
  /** Calendar date, `YYYY-MM-DD`. */
  effective_date: string;
  /** Decimal string: units of `to_currency` per one unit of `from_currency`. */
  rate: string;
  is_active: boolean;
  source: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

export type ExchangeRateListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: ExchangeRate[];
  message?: string;
  requestId?: string;
};

export type ExchangeRateDetailState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  rate: ExchangeRate | null;
  message?: string;
  requestId?: string;
};

/**
 * The filters the index endpoint supports. There is no effective-date filter;
 * the list is returned whole (ordered `effective_date` desc, then id desc).
 */
export type ExchangeRateListFilters = {
  from_currency_id?: number;
  to_currency_id?: number;
  active_only?: boolean;
};

/** Create body validated by `StoreExchangeRateRequest` (+ `ExchangeRateService`). */
export type ExchangeRateCreatePayload = {
  from_currency_id: number;
  to_currency_id: number;
  rate: string;
  effective_date: string;
  source?: string | null;
};

/**
 * Update body validated by `UpdateExchangeRateRequest`. The pair
 * (`from`/`to`) is deliberately absent: a rate is history, so correcting it
 * means changing the value or date, never re-pointing it at another pair.
 */
export type ExchangeRateUpdatePayload = Partial<{
  rate: string;
  effective_date: string;
  source: string | null;
}>;

/**
 * A well-formed rate is a plain decimal greater than zero. The backend accepts
 * up to ten decimal places (`Rate::scale()`), silently ignoring trailing
 * zeroes beyond that; this pattern is the shallow client-side pre-check and the
 * service remains the authority (including the self-pair-is-1 rule).
 */
export const RATE_PATTERN = /^(?:\d+)(?:\.\d{1,10})?$/;
