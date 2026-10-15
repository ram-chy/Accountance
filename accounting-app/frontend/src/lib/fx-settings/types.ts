/**
 * Company FX settings data contracts.
 *
 * These mirror the verified Laravel `CompanyFxSettingResource` and the
 * `/accounting/fx-settings` routes exactly (see `CompanyFxSettingController.php`
 * and `FRONTEND_API_HANDOFF.md` §9). The setting is a singleton for the active
 * company: it has no id in the URL, the company comes from the server-side proxy
 * context, and the row always exists — "not configured" means both account
 * columns are null, not that the row is missing.
 *
 * The setting is policy, not data: it names the accounts realised foreign
 * exchange gains and losses post to. Both fields are written together (set both,
 * or clear both by sending both null); the backend refuses a half-configured
 * state.
 */

export type FxAccountRef = {
  id: number;
  code: string;
  name: string;
};

export type CompanyFxSetting = {
  company_id: number;
  realized_gain_account_id: number | null;
  realized_loss_account_id: number | null;
  /** Derived: false exactly when both account ids are null. */
  is_configured: boolean;
  realized_gain_account: FxAccountRef | null;
  realized_loss_account: FxAccountRef | null;
  updated_at: string | null;
};

export type FxSettingState = {
  status: "loading" | "ready" | "error" | "unavailable";
  setting: CompanyFxSetting | null;
  message?: string;
  requestId?: string;
};

/**
 * Update body validated by `UpdateCompanyFxSettingRequest`. Both fields are
 * required to be present: clearing the configuration means sending both as
 * null, not omitting one.
 */
export type FxSettingUpdatePayload = {
  realized_gain_account_id: number | null;
  realized_loss_account_id: number | null;
};
