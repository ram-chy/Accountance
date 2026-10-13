/**
 * Financial reports - common types and shared structures.
 */

export type DateRangeFilters = {
  from: string;
  to: string;
};

export const DEFAULT_DATE_RANGE: DateRangeFilters = {
  from: "",
  to: "",
};

export type BaseCurrency = {
  code: string | null;
  name: string | null;
  symbol: string | null;
  decimals: number | null;
};

export type ReportPeriod = {
  from: string | null;
  to: string | null;
};
