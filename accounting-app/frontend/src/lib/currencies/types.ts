/**
 * Currency master data contracts.
 *
 * These mirror the verified Laravel `CurrencyResource` and the currency routes
 * exactly (see `CurrencyController.php`, `CurrencyResource.php` and
 * `FRONTEND_API_HANDOFF.md` §9). Currencies are **global** reference data: the
 * list is readable by anyone working inside a company, but there is no company
 * id in any request and the master cannot be deleted — the lifecycle ends at
 * deactivation.
 *
 * There is no `is_active` field on the update payload: activation and
 * deactivation are separate, separately-audited routes, not a form field.
 */

export type Currency = {
  id: number;
  /** Three uppercase letters, e.g. `USD`. */
  code: string;
  name: string;
  symbol: string | null;
  /** Decimal places the currency is stored/displayed at (0–4). */
  decimal_precision: number;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type CurrencyListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: Currency[];
  message?: string;
  requestId?: string;
};

export type CurrencyDetailState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  currency: Currency | null;
  message?: string;
  requestId?: string;
};

/**
 * The only filter the index endpoint supports. There is no search and no
 * pagination — the list is returned whole, ordered by `code`.
 */
export type CurrencyListFilters = {
  active_only?: boolean;
};

/** Create body validated by `StoreCurrencyRequest` (+ `CurrencyService`). */
export type CurrencyCreatePayload = {
  code: string;
  name: string;
  symbol: string | null;
  decimal_precision: number;
};

/** Update body validated by `UpdateCurrencyRequest` (PUT; absent means unchanged). */
export type CurrencyUpdatePayload = Partial<{
  code: string;
  name: string;
  symbol: string | null;
  decimal_precision: number;
}>;

/**
 * The precision bounds the backend actually enforces, in `CurrencyService`,
 * not the wider `0–9` the request schema permits. The service owns this rule
 * ("the ISO 4217 reality"), so the client mirrors it to give a useful message
 * rather than relying on the request-level `max:9`.
 */
export const MIN_CURRENCY_DECIMAL_PRECISION = 0;
export const MAX_CURRENCY_DECIMAL_PRECISION = 4;
export const DEFAULT_CURRENCY_DECIMAL_PRECISION = 2;

/** A currency code is exactly three ASCII letters (`CurrencyService::normaliseCode`). */
export const CURRENCY_CODE_PATTERN = /^[A-Za-z]{3}$/;
