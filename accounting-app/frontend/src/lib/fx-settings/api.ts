"use client";

import { backendGet, backendPut } from "@/lib/api/backend";

import type { FxSettingUpdatePayload } from "./types";

/**
 * Company FX-settings backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * setting is a per-company singleton, so it is addressed without an id — the
 * company comes from the server-side context and nothing in the request can name
 * another company's setting.
 */

export function fetchFxSetting(signal?: AbortSignal): Promise<unknown> {
  return backendGet("accounting/fx-settings", { signal });
}

export function updateFxSetting(payload: FxSettingUpdatePayload): Promise<unknown> {
  return backendPut("accounting/fx-settings", payload);
}
