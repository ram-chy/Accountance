import { API_BASE_URL, isApiConfigured } from "./config";
import { ApiError } from "./errors";
import type { ApiEnvelope, ApiErrorBody, QueryParams } from "./types";

/**
 * Phase 2 extension point.
 *
 * Auth and company-context headers are attached by registering a provider here
 * once. Because every request flows through `apiFetch`, no call site changes
 * when the token strategy is finalised; swapping to an httpOnly-cookie BFF is
 * likewise a base-URL/config change rather than a client rewrite.
 *
 * Providers must never log or persist tokens themselves.
 */
export type RequestHeadersProvider = () =>
  | Record<string, string>
  | Promise<Record<string, string>>;

let requestHeadersProvider: RequestHeadersProvider | null = null;

export function setRequestHeadersProvider(
  provider: RequestHeadersProvider | null,
): void {
  requestHeadersProvider = provider;
}

export type ApiFetchOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  /** JSON body. `FormData` is passed through untouched (no manual header). */
  body?: unknown;
  headers?: Record<string, string>;
  query?: QueryParams;
  signal?: AbortSignal;
};

function buildQueryString(query?: QueryParams): string {
  if (!query) {
    return "";
  }

  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === null || value === undefined) {
      continue;
    }

    if (Array.isArray(value)) {
      for (const item of value) {
        params.append(key, String(item));
      }
      continue;
    }

    params.append(key, String(value));
  }

  const serialised = params.toString();
  return serialised ? `?${serialised}` : "";
}

function resolveUrl(path: string, query?: QueryParams): string {
  const normalisedPath = path.startsWith("/") ? path : `/${path}`;
  return `${API_BASE_URL}${normalisedPath}${buildQueryString(query)}`;
}

function extractRequestId(response: Response): string | undefined {
  return response.headers.get("x-request-id") ?? undefined;
}

function toApiError(
  status: number,
  parsed: unknown,
  requestId: string | undefined,
  fallbackMessage: string,
): ApiError {
  const body = (parsed ?? {}) as ApiErrorBody;

  return new ApiError({
    status,
    message: body.message || fallbackMessage,
    errors: body.errors,
    requestId,
  });
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

/**
 * Perform an API request and unwrap the `{ success, data }` envelope.
 *
 * - Writes are never retried automatically; callers decide on retry policy.
 * - `204 No Content` and empty bodies resolve to `undefined`.
 * - Non-2xx responses, and 2xx bodies with `success: false`, throw `ApiError`.
 */
export async function apiFetch<T>(
  path: string,
  options: ApiFetchOptions = {},
): Promise<T> {
  if (!isApiConfigured) {
    throw new ApiError({
      status: 0,
      isNetworkError: true,
      message:
        "The API base URL is not configured. Set NEXT_PUBLIC_API_BASE_URL and rebuild.",
    });
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(requestHeadersProvider ? await requestHeadersProvider() : {}),
    ...options.headers,
  };

  let body: BodyInit | undefined;

  if (options.body !== undefined) {
    if (options.body instanceof FormData) {
      body = options.body;
    } else {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(options.body);
    }
  }

  let response: Response;

  try {
    response = await fetch(resolveUrl(path, options.query), {
      method: options.method ?? (body ? "POST" : "GET"),
      headers,
      body,
      signal: options.signal,
      credentials: "same-origin",
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") {
      throw new ApiError({
        status: 0,
        isAborted: true,
        isNetworkError: true,
        message: "The request was cancelled.",
      });
    }

    throw new ApiError({
      status: 0,
      isNetworkError: true,
      message: "Could not reach the server. Check your connection and try again.",
    });
  }

  const requestId = extractRequestId(response);
  const text = await response.text();

  let parsed: unknown;

  if (text) {
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = undefined;
    }
  }

  if (!response.ok) {
    throw toApiError(
      response.status,
      parsed,
      requestId,
      `Request failed with status ${response.status}.`,
    );
  }

  if (isRecord(parsed) && parsed.success === false) {
    throw toApiError(
      response.status,
      parsed,
      requestId,
      "The request could not be completed.",
    );
  }

  if (isRecord(parsed) && "data" in parsed) {
    return (parsed as ApiEnvelope<T>).data;
  }

  return parsed as T;
}

export function apiGet<T>(path: string, options?: Omit<ApiFetchOptions, "method" | "body">): Promise<T> {
  return apiFetch<T>(path, { ...options, method: "GET" });
}

export function apiPost<T>(path: string, body?: unknown, options?: Omit<ApiFetchOptions, "method" | "body">): Promise<T> {
  return apiFetch<T>(path, { ...options, method: "POST", body });
}

export function apiPut<T>(path: string, body?: unknown, options?: Omit<ApiFetchOptions, "method" | "body">): Promise<T> {
  return apiFetch<T>(path, { ...options, method: "PUT", body });
}

export function apiPatch<T>(path: string, body?: unknown, options?: Omit<ApiFetchOptions, "method" | "body">): Promise<T> {
  return apiFetch<T>(path, { ...options, method: "PATCH", body });
}

export function apiDelete<T>(path: string, options?: Omit<ApiFetchOptions, "method" | "body">): Promise<T> {
  return apiFetch<T>(path, { ...options, method: "DELETE" });
}
