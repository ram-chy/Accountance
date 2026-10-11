/**
 * Shared API envelope and list-payload types.
 *
 * Backend success responses are wrapped as `{ success, data, message? }`
 * (`bootstrap/app.php`). List endpoints currently return a flat `data` array
 * with no `meta`/`links`/`total` (Phase 0 audit finding P2-1); the pagination
 * adapter tolerates both the flat shape and a Laravel paginator shape so it
 * keeps working if the backend adds pagination metadata later.
 */

export type ApiEnvelope<T> = {
  success: boolean;
  data: T;
  message?: string;
};

export type ApiErrorBody = {
  success?: false;
  message?: string;
  /** Laravel validation bag: field name -> messages. */
  errors?: Record<string, string[]>;
};

export type PaginatorMeta = {
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
};

export type PaginatorLinks = {
  first?: string | null;
  last?: string | null;
  prev?: string | null;
  next?: string | null;
};

/** Laravel paginator shape, used only if the backend starts returning it. */
export type PaginatorPayload<T> = {
  data?: T[];
  meta?: PaginatorMeta;
  links?: PaginatorLinks;
} & PaginatorMeta;

/** Query primitives accepted by the API client. */
export type QueryValue =
  | string
  | number
  | boolean
  | null
  | undefined
  | readonly (string | number | boolean)[];

export type QueryParams = Record<string, QueryValue>;
