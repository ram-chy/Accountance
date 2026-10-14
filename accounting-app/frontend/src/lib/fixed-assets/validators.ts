export function parseFixedAssetDepreciation(value: unknown): FixedAssetDepreciation | null {
  if (!isRecord(value)) {
    return null;
  }
  const id = asNumber(value.id);
  const fixed_asset_id = asNumber(value.fixed_asset_id);
  const period_number = asNumber(value.period_number);
  const period_start_date = asString(value.period_start_date);
  const period_end_date = asString(value.period_end_date);
  const amount = asString(value.amount);
  const is_final_period = asBoolean(value.is_final_period);
  if (
    id === undefined ||
    fixed_asset_id === undefined ||
    period_number === undefined ||
    period_start_date === undefined ||
    period_end_date === undefined ||
    amount === undefined ||
    is_final_period === undefined
  ) {
    return null;
  }
  return {
    id,
    fixed_asset_id,
    period_number,
    period_start_date,
    period_end_date,
    amount,
    is_final_period,
    journal_id: asNullableNumber(value.journal_id),
    journal: parseJournalRef(value.journal),
    posted_at: asNullableString(value.posted_at),
    posted_by: asNullableNumber(value.posted_by),
    created_at: asNullableString(value.created_at),
  };
}

export function parseFixedAssetDisposal(value: unknown): FixedAssetDisposal | null {
  if (!isRecord(value)) {
    return null;
  }
  const id = asNumber(value.id);
  const fixed_asset_id = asNumber(value.fixed_asset_id);
  const disposal_date = asString(value.disposal_date);
  const proceeds = asString(value.proceeds);
  const carrying_value_at_disposal = asString(value.carrying_value_at_disposal);
  const gain = asString(value.gain);
  const loss = asString(value.loss);
  const net_result = asString(value.net_result);
  const proceeds_account_id = asNumber(value.proceeds_account_id);
  if (
    id === undefined ||
    fixed_asset_id === undefined ||
    disposal_date === undefined ||
    proceeds === undefined ||
    carrying_value_at_disposal === undefined ||
    gain === undefined ||
    loss === undefined ||
    net_result === undefined ||
    proceeds_account_id === undefined
  ) {
    return null;
  }
  return {
    id,
    fixed_asset_id,
    disposal_date,
    reason: asNullableString(value.reason),
    proceeds,
    carrying_value_at_disposal,
    gain,
    loss,
    net_result,
    proceeds_account_id,
    proceeds_account: parseAccountRef(value.proceeds_account),
    journal_id: asNullableNumber(value.journal_id),
    journal: parseJournalRef(value.journal),
    posted_at: asNullableString(value.posted_at),
    disposed_by: asNullableNumber(value.disposed_by),
    created_at: asNullableString(value.created_at),
  };
}

export function parseFixedAsset(value: unknown): FixedAsset | null {
  if (!isRecord(value)) {
    return null;
  }
  const id = asNumber(value.id);
  const asset_number = asString(value.asset_number);
  const name = asString(value.name);
  const status = asString(value.status);
  const acquisition_method = asString(value.acquisition_method);
  const fixed_asset_category_id = asNumber(value.fixed_asset_category_id);
  const acquisition_date = asString(value.acquisition_date);
  const depreciation_start_date = asString(value.depreciation_start_date);
  const original_cost = asString(value.original_cost);
  const salvage_value = asString(value.salvage_value);
  const useful_life_months = asNumber(value.useful_life_months);
  const depreciation_method = asString(value.depreciation_method);
  const accumulated_depreciation = asString(value.accumulated_depreciation);
  const carrying_value = asString(value.carrying_value);
  const monthly_charge = asString(value.monthly_charge);
  const period_count = asNumber(value.period_count);
  const remaining_period_count = asNumber(value.remaining_period_count);
  const asset_account_id = asNumber(value.asset_account_id);
  const accumulated_depreciation_account_id = asNumber(value.accumulated_depreciation_account_id);
  const depreciation_expense_account_id = asNumber(value.depreciation_expense_account_id);
  const acquisition_account_id = asNumber(value.acquisition_account_id);
  if (
    id === undefined ||
    asset_number === undefined ||
    name === undefined ||
    status === undefined ||
    acquisition_method === undefined ||
    fixed_asset_category_id === undefined ||
    acquisition_date === undefined ||
    depreciation_start_date === undefined ||
    original_cost === undefined ||
    salvage_value === undefined ||
    useful_life_months === undefined ||
    depreciation_method === undefined ||
    accumulated_depreciation === undefined ||
    carrying_value === undefined ||
    monthly_charge === undefined ||
    period_count === undefined ||
    remaining_period_count === undefined ||
    asset_account_id === undefined ||
    accumulated_depreciation_account_id === undefined ||
    depreciation_expense_account_id === undefined ||
    acquisition_account_id === undefined
  ) {
    return null;
  }
  if (status !== "DRAFT" && status !== "ACTIVE" && status !== "FULLY_DEPRECIATED" && status !== "DISPOSED") {
    return null;
  }
  if (acquisition_method !== "CASH" && acquisition_method !== "SUPPLIER_CREDIT") {
    return null;
  }
  if (depreciation_method !== "STRAIGHT_LINE") {
    return null;
  }
  const depreciations: FixedAssetDepreciation[] = [];
  if (Array.isArray((value as any).depreciations)) {
    for (const entry of (value as any).depreciations) {
      const d = parseFixedAssetDepreciation(entry);
      if (d) {
        depreciations.push(d);
      }
    }
  }
  return {
    id,
    asset_number,
    name,
    description: asNullableString(value.description),
    serial_number: asNullableString(value.serial_number),
    supplier_reference: asNullableString(value.supplier_reference),
    status: status as any,
    acquisition_method: acquisition_method as any,
    fixed_asset_category_id,
    category: parseCategoryRef(value.category),
    acquisition_date,
    depreciation_start_date,
    original_cost,
    salvage_value,
    useful_life_months,
    depreciation_method: "STRAIGHT_LINE",
    accumulated_depreciation,
    carrying_value,
    monthly_charge,
    period_count,
    remaining_period_count,
    asset_account_id,
    accumulated_depreciation_account_id,
    depreciation_expense_account_id,
    gain_on_disposal_account_id: asNullableNumber(value.gain_on_disposal_account_id),
    loss_on_disposal_account_id: asNullableNumber(value.loss_on_disposal_account_id),
    acquisition_account_id,
    asset_account: parseAccountRef(value.asset_account),
    accumulated_depreciation_account: parseAccountRef(value.accumulated_depreciation_account),
    depreciation_expense_account: parseAccountRef(value.depreciation_expense_account),
    acquisition_account: parseAccountRef(value.acquisition_account),
    journal_id: asNullableNumber(value.journal_id),
    journal: parseJournalRef(value.journal),
    capitalised_at: asNullableString(value.capitalised_at),
    fully_depreciated_at: asNullableString(value.fully_depreciated_at),
    disposed_at: asNullableString(value.disposed_at),
    depreciations: depreciations.length > 0 || (value as any).depreciations ? depreciations : null,
    disposal: parseFixedAssetDisposal(value.disposal),
    created_by: asNullableNumber(value.created_by),
    updated_by: asNullableNumber(value.updated_by),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}