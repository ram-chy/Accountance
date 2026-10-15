/**
 * Runtime validation for FX-settings responses and inputs.
 *
 * API JSON is untrusted input. The parser narrows a raw payload to the exact
 * fields the UI renders. A setting whose `company_id` or `is_configured` is not
 * the shape the backend always sends is treated as invalid rather than guessed
 * at, since the whole form renders from it.
 *
 * The one client-side input rule is the pairing rule a form can check without
 * the accounts themselves: a gain account and a loss account must be set
 * together. The backend re-checks this (and account ownership and type); this is
 * only a helpful pre-check, and the backend's message wins.
 */

import type {
  CompanyFxSetting,
  FxAccountRef,
  FxSettingUpdatePayload,
} from "./types";

export class FxSettingsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "FxSettingsDataError";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asNumber(value: unknown): number | undefined {
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

function asNullableNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function asString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

function parseAccountRef(value: unknown): FxAccountRef | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const code = asString(value.code);
  const name = asString(value.name);
  if (id === undefined || code === undefined || name === undefined) return null;
  return { id, code, name };
}

export function parseCompanyFxSetting(value: unknown): CompanyFxSetting | null {
  if (!isRecord(value)) return null;

  const company_id = asNumber(value.company_id);
  const is_configured = asBoolean(value.is_configured);

  if (company_id === undefined || is_configured === undefined) {
    return null;
  }

  return {
    company_id,
    realized_gain_account_id: asNullableNumber(value.realized_gain_account_id),
    realized_loss_account_id: asNullableNumber(value.realized_loss_account_id),
    is_configured,
    realized_gain_account: parseAccountRef(value.realized_gain_account),
    realized_loss_account: parseAccountRef(value.realized_loss_account),
    updated_at: typeof value.updated_at === "string" ? value.updated_at : null,
  };
}

export function parseCompanyFxSettingOrThrow(raw: unknown): CompanyFxSetting {
  const setting = parseCompanyFxSetting(raw);
  if (setting === null) {
    throw new FxSettingsDataError("The FX settings response was invalid.");
  }
  return setting;
}

export type FxSettingValidationErrors = Record<string, string>;

/**
 * A form pre-check: the gain and loss accounts must be set together. The service
 * is the authority and its message is shown when this passes but the backend
 * still refuses.
 */
export function validateFxSettingUpdate(
  payload: FxSettingUpdatePayload,
): FxSettingValidationErrors {
  const errors: FxSettingValidationErrors = {};

  const hasGain = payload.realized_gain_account_id !== null;
  const hasLoss = payload.realized_loss_account_id !== null;

  if (hasGain !== hasLoss) {
    if (!hasGain) {
      errors.realized_gain_account_id = "Choose a gain account to configure FX, or clear both.";
    }
    if (!hasLoss) {
      errors.realized_loss_account_id = "Choose a loss account to configure FX, or clear both.";
    }
  }

  return errors;
}
