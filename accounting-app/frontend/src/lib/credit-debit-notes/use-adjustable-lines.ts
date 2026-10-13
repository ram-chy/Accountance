"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchAdjustableLines } from "./api";
import type { AdjustableSourceState, NoteType } from "./types";
import { parseAdjustableSource } from "./validators";

/**
 * What is still adjustable on the source document a note is being built against.
 *
 * The figures are computed per request by the backend from the posted notes, so
 * the browser never recomputes an authoritative remaining amount — it only
 * displays what the server returned and uses it as an advisory limit.
 */

export type UseAdjustableLinesOptions = {
  companyId: number | null;
  noteType: NoteType;
  sourceId: number | null;
  enabled: boolean;
  reloadToken?: number;
};

export function useAdjustableLines(options: UseAdjustableLinesOptions): AdjustableSourceState {
  const { companyId, noteType, sourceId, enabled, reloadToken = 0 } = options;
  const key =
    enabled && companyId !== null && sourceId !== null && sourceId > 0
      ? `${companyId}|${noteType}|${sourceId}|${reloadToken}`
      : null;
  const [stored, setStored] = useState<{ key: string; data: AdjustableSourceState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null || sourceId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchAdjustableLines(noteType, sourceId, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseAdjustableSource(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The adjustable-lines response was invalid." } });
          return;
        }
        setStored({ key, data: { status: "ready", data: parsed } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;

        const message =
          error instanceof ApiError && error.status === 404
            ? "The document this note adjusts could not be found."
            : error instanceof ApiError
              ? error.message
              : "Could not load the adjustable lines for this document.";

        setStored({ key, data: { status: "error", data: null, message } });
      });

    return () => controller.abort();
  }, [key, companyId, noteType, sourceId]);

  if (!enabled || companyId === null || sourceId === null || sourceId <= 0) {
    return { status: "idle", data: null };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", data: null };
}
