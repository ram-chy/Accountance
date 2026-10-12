"use client";

import { apiFetch, type ApiFetchOptions } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { notifyUnauthorized } from "@/lib/api/unauthorized";

/**
 * Browser client for business data.
 *
 * Every call targets the same-origin proxy (`/api/backend/*`), never Laravel
 * directly: the proxy attaches the bearer token and the active company from the
 * server-side session. Response unwrapping, `ApiError` and request-id capture
 * are inherited from the shared Phase 1 client, so business and session calls
 * fail identically.
 *
 * A `401` (the token expired or was revoked) is reported to the shared handler
 * so the app returns to login; other failures propagate to the caller.
 */

const PROXY_PREFIX = "/api/backend";

function toProxyPath(path: string): string {
  return `${PROXY_PREFIX}/${path.replace(/^\/+/, "")}`;
}

export type BackendRequestOptions = Omit<ApiFetchOptions, "baseUrl">;

export function backendFetch<T>(
  path: string,
  options: BackendRequestOptions = {},
): Promise<T> {
  return apiFetch<T>(toProxyPath(path), { ...options, baseUrl: "" }).catch(
    (error: unknown) => {
      if (error instanceof ApiError && error.status === 401) {
        notifyUnauthorized();
      }

      throw error;
    },
  );
}

export function backendGet<T>(
  path: string,
  options?: Omit<BackendRequestOptions, "method" | "body">,
): Promise<T> {
  return backendFetch<T>(path, { ...options, method: "GET" });
}

export function backendPost<T>(
  path: string,
  body?: unknown,
  options?: Omit<BackendRequestOptions, "method" | "body">,
): Promise<T> {
  return backendFetch<T>(path, { ...options, method: "POST", body });
}

export function backendPut<T>(
  path: string,
  body?: unknown,
  options?: Omit<BackendRequestOptions, "method" | "body">,
): Promise<T> {
  return backendFetch<T>(path, { ...options, method: "PUT", body });
}

export function backendPatch<T>(
  path: string,
  body?: unknown,
  options?: Omit<BackendRequestOptions, "method" | "body">,
): Promise<T> {
  return backendFetch<T>(path, { ...options, method: "PATCH", body });
}

export function backendDelete<T>(
  path: string,
  options?: Omit<BackendRequestOptions, "method" | "body">,
): Promise<T> {
  return backendFetch<T>(path, { ...options, method: "DELETE" });
}
