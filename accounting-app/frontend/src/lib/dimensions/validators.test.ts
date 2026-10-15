import { describe, expect, it } from "vitest";

import type { FinancialDimension, FinancialDimensionValue } from "./types";
import {
  parseFinancialDimension,
  parseFinancialDimensionDetail,
  parseFinancialDimensionList,
  parseFinancialDimensionOrThrow,
  parseFinancialDimensionValue,
  parseFinancialDimensionValueList,
  validateFinancialDimensionCreate,
  validateFinancialDimensionUpdate,
  validateFinancialDimensionValueWrite,
} from "./validators";

const validDimension: FinancialDimension = {
  id: 3,
  type: "COST_CENTER",
  type_label: "Cost Center",
  code: "CC",
  name: "Cost centers",
  is_active: true,
  values_count: 2,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

const validValue: FinancialDimensionValue = {
  id: 9,
  financial_dimension_id: 3,
  code: "CC-001",
  name: "Sales",
  is_active: true,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

describe("parseFinancialDimension", () => {
  it("narrows a complete dimension row", () => {
    const parsed = parseFinancialDimension(validDimension);

    expect(parsed).not.toBeNull();
    expect(parsed?.type).toBe("COST_CENTER");
    expect(parsed?.code).toBe("CC");
    expect(parsed?.values_count).toBe(2);
  });

  it("narrows the nested values when the controller loaded them", () => {
    const parsed = parseFinancialDimension({ ...validDimension, values: [validValue] });

    expect(parsed?.values).toHaveLength(1);
    expect(parsed?.values?.[0].code).toBe("CC-001");
  });

  it("rejects a type the backend does not implement", () => {
    expect(parseFinancialDimension({ ...validDimension, type: "SEGMENT" })).toBeNull();
  });

  it("rejects a row missing a required field", () => {
    expect(parseFinancialDimension({ ...validDimension, code: undefined })).toBeNull();
    expect(parseFinancialDimension({ ...validDimension, is_active: undefined })).toBeNull();
  });
});

describe("parseFinancialDimensionValue", () => {
  it("narrows a complete value row", () => {
    const parsed = parseFinancialDimensionValue(validValue);

    expect(parsed).not.toBeNull();
    expect(parsed?.financial_dimension_id).toBe(3);
    expect(parsed?.name).toBe("Sales");
  });

  it("rejects a value missing its parent dimension id", () => {
    expect(parseFinancialDimensionValue({ ...validValue, financial_dimension_id: undefined })).toBeNull();
  });
});

describe("parseFinancialDimensionOrThrow", () => {
  it("throws on an invalid payload", () => {
    expect(() => parseFinancialDimensionOrThrow({ id: 1 })).toThrow();
  });
});

describe("dimension lists", () => {
  it("parses an envelope-wrapped list", () => {
    const state = parseFinancialDimensionList({ success: true, data: [validDimension] }, 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when no dimension row survives parsing", () => {
    const state = parseFinancialDimensionList({ data: [{ id: 1 }] }, 25);

    expect(state.status).toBe("error");
  });

  it("errors when the payload is not a list", () => {
    expect(parseFinancialDimensionList("nope", 25).status).toBe("error");
  });

  it("parses a value list and reports readiness", () => {
    const state = parseFinancialDimensionValueList({ data: [validValue] }, 25);

    expect(state.status).toBe("ready");
    expect(state.items[0].code).toBe("CC-001");
  });

  it("errors when no value row survives parsing", () => {
    const state = parseFinancialDimensionValueList({ data: [{ id: 1 }] }, 25);

    expect(state.status).toBe("error");
  });

  it("parses a single dimension detail", () => {
    const state = parseFinancialDimensionDetail(validDimension);

    expect(state.status).toBe("ready");
    expect(state.dimension?.id).toBe(3);
  });
});

describe("dimension write validation", () => {
  it("accepts a complete create payload", () => {
    expect(validateFinancialDimensionCreate({ type: "PROJECT", code: "PRJ", name: "Projects" })).toEqual({});
  });

  it("flags the required create fields", () => {
    const errors = validateFinancialDimensionCreate({
      type: "" as never,
      code: "  ",
      name: "",
    });

    expect(errors.type).toBeDefined();
    expect(errors.code).toBeDefined();
    expect(errors.name).toBeDefined();
  });

  it("only validates supplied update fields", () => {
    expect(validateFinancialDimensionUpdate({})).toEqual({});
    expect(validateFinancialDimensionUpdate({ name: "" })).toHaveProperty("name");
    expect(validateFinancialDimensionUpdate({ name: "" })).not.toHaveProperty("code");
  });

  it("flags the required value fields", () => {
    expect(validateFinancialDimensionValueWrite({ code: "CC-001", name: "Sales" })).toEqual({});
    expect(validateFinancialDimensionValueWrite({ code: "", name: "" })).toHaveProperty("code");
  });
});
