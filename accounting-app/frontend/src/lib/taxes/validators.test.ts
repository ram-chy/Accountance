import { describe, expect, it } from "vitest";

import {
  parseTax,
  parseTaxCalculation,
  parseTaxList,
  parseTaxOrThrow,
  parseTaxRate,
  parseTaxRateList,
  parseTaxReport,
  TaxesDataError,
  validateCalculation,
  validateReportRange,
  validateTaxRateWrite,
  validateTaxWrite,
} from "./validators";

const validTax = {
  id: 1,
  code: "VAT",
  name: "VAT standard",
  description: null,
  tax_type: "OUTPUT",
  calculation_basis: "EXCLUSIVE",
  is_active: true,
  applies_to_sales: true,
  applies_to_purchase: false,
  current_rate: "20.0000",
  account_mapping: null,
  created_by: 1,
  updated_by: null,
  created_at: "2026-04-01T00:00:00Z",
  updated_at: null,
};

describe("parseTax", () => {
  it("narrows a complete row", () => {
    const tax = parseTax(validTax);

    expect(tax).not.toBeNull();
    expect(tax?.code).toBe("VAT");
    expect(tax?.current_rate).toBe("20.0000");
  });

  it("drops a row with an unknown tax type rather than coercing it", () => {
    expect(parseTax({ ...validTax, tax_type: "SALES" })).toBeNull();
  });

  it("drops a row missing a required identifier", () => {
    expect(parseTax({ ...validTax, id: undefined })).toBeNull();
  });

  it("tolerates a null current rate", () => {
    const tax = parseTax({ ...validTax, current_rate: null });
    expect(tax?.current_rate).toBeNull();
  });

  it("narrows an embedded account mapping", () => {
    const tax = parseTax({
      ...validTax,
      account_mapping: {
        tax_id: 1,
        output_account_id: 30,
        input_account_id: null,
        output_account: {
          id: 30,
          code: "2200",
          name: "VAT payable",
          account_type: "LIABILITY",
          is_active: true,
        },
        input_account: null,
      },
    });

    expect(tax?.account_mapping?.output_account?.code).toBe("2200");
    expect(tax?.account_mapping?.input_account).toBeNull();
  });
});

describe("parseTaxList", () => {
  it("keeps valid rows and skips invalid ones", () => {
    const state = parseTaxList([validTax, { id: 2 }, { ...validTax, id: 3, code: "GST" }]);

    expect(state.status).toBe("ready");
    expect(state.items.map((item) => item.id)).toEqual([1, 3]);
  });

  it("errors when no row is valid", () => {
    const state = parseTaxList([{ id: 2 }, null]);
    expect(state.status).toBe("error");
  });

  it("errors when the payload is not a list", () => {
    expect(parseTaxList("nope").status).toBe("error");
  });
});

describe("parseTaxOrThrow", () => {
  it("throws a TaxesDataError for a malformed payload", () => {
    expect(() => parseTaxOrThrow({})).toThrow(TaxesDataError);
  });
});

describe("parseTaxRate", () => {
  const rate = {
    id: 5,
    tax_id: 1,
    rate: "20.0000",
    effective_from: "2026-01-01",
    effective_to: null,
    is_open_ended: true,
    is_active: true,
  };

  it("narrows an open-ended rate", () => {
    const parsed = parseTaxRate(rate);
    expect(parsed?.is_open_ended).toBe(true);
    expect(parsed?.effective_to).toBeNull();
  });

  it("drops a rate missing its percentage", () => {
    expect(parseTaxRate({ ...rate, rate: undefined })).toBeNull();
  });

  it("lists rates and skips invalid rows", () => {
    const state = parseTaxRateList([rate, { id: 6 }]);
    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });
});

describe("parseTaxCalculation", () => {
  it("narrows the decision and drops malformed components", () => {
    const result = parseTaxCalculation({
      basis: "EXCLUSIVE",
      taxable_amount: "100.0000",
      total_tax: "20.0000",
      gross_amount: "120.0000",
      tax_components: [
        {
          tax_id: 1,
          tax_code: "VAT",
          tax_name: "VAT",
          tax_type: "OUTPUT",
          tax_rate_id: 5,
          rate: "20.0000",
          taxable_amount: "100.0000",
          tax_amount: "20.0000",
          output_account_id: 30,
          input_account_id: null,
        },
        { tax_id: 2 },
      ],
    });

    expect(result?.tax_components).toHaveLength(1);
    expect(result?.gross_amount).toBe("120.0000");
  });

  it("returns null when the decision is missing", () => {
    expect(parseTaxCalculation({ total_tax: "20.0000" })).toBeNull();
  });
});

describe("parseTaxReport", () => {
  const totals = {
    sales_taxable: "1000.0000",
    output_tax: "200.0000",
    purchase_taxable: "0.0000",
    input_tax: "0.0000",
    net_tax: "200.0000",
    unattributed_tax: "0.0000",
  };

  it("narrows the summary report", () => {
    const report = parseTaxReport({
      period: { from: "2026-04-01", to: "2026-04-30" },
      rows: [],
      totals,
    });

    expect(report?.totals.net_tax).toBe("200.0000");
  });

  it("narrows the by-tax report with the base currency block", () => {
    const report = parseTaxReport({
      period: { from: null, to: null },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      rows: [
        {
          tax_id: null,
          tax_code: null,
          tax_name: null,
          tax_type: null,
          ...totals,
        },
      ],
      totals,
    });

    expect(report?.base_currency?.code).toBe("USD");
    expect(report?.rows[0].tax_id).toBeNull();
  });

  it("returns null when the period is missing", () => {
    expect(parseTaxReport({ rows: [], totals })).toBeNull();
  });
});

describe("validators", () => {
  it("requires a tax code, name, type and basis", () => {
    const errors = validateTaxWrite({
      code: "",
      name: "",
      description: null,
      tax_type: "OUTPUT",
      calculation_basis: "EXCLUSIVE",
    });

    expect(errors.code).toBeDefined();
    expect(errors.name).toBeDefined();
  });

  it("rejects a rate of 100% or more and a bad date", () => {
    const errors = validateTaxRateWrite({
      rate: "100",
      effective_from: "not-a-date",
      effective_to: null,
    });

    expect(errors.rate).toBeDefined();
    expect(errors.effective_from).toBeDefined();
  });

  it("rejects an effective-to before effective-from", () => {
    const errors = validateTaxRateWrite({
      rate: "7.5",
      effective_from: "2026-04-01",
      effective_to: "2026-03-01",
    });

    expect(errors.effective_to).toBeDefined();
  });

  it("validates the calculation inputs", () => {
    expect(validateCalculation({ amount: "abc", date: "2026-04-01" }).amount).toBeDefined();
    expect(validateCalculation({ amount: "10", date: "04/01/2026" }).date).toBeDefined();
    expect(validateCalculation({ amount: "10", date: "2026-04-01" })).toEqual({});
  });

  it("validates the report range ordering", () => {
    expect(validateReportRange({ from: "2026-04-30", to: "2026-04-01" }).to).toBeDefined();
    expect(validateReportRange({ from: "", to: "" })).toEqual({});
  });
});
