import { describe, expect, it } from "vitest";

import type { SessionCompany } from "@/lib/auth/types";
import { pickActiveCompany } from "./snapshot";

function makeCompany(id: number, overrides: Partial<SessionCompany> = {}): SessionCompany {
  return {
    id,
    name: `Company ${id}`,
    legal_name: null,
    registration_number: null,
    tax_number: null,
    email: null,
    phone: null,
    website: null,
    address: {
      line_1: null,
      line_2: null,
      city: null,
      state: null,
      postal_code: null,
      country_code: null,
    },
    timezone: "UTC",
    date_format: "Y-m-d",
    is_active: true,
    is_default: false,
    settings: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

describe("pickActiveCompany", () => {
  it("returns null when there are no companies", () => {
    expect(pickActiveCompany([])).toBeNull();
  });

  it("returns null when every company is inactive", () => {
    const companies = [
      makeCompany(1, { is_active: false }),
      makeCompany(2, { is_active: false }),
    ];

    expect(pickActiveCompany(companies)).toBeNull();
  });

  it("prefers the previously selected active company", () => {
    const companies = [makeCompany(1), makeCompany(2)];

    expect(pickActiveCompany(companies, 2)?.id).toBe(2);
  });

  it("ignores a preferred company the user is no longer an active member of", () => {
    const companies = [makeCompany(1), makeCompany(2, { is_active: false })];

    expect(pickActiveCompany(companies, 2)?.id).toBe(1);
  });

  it("falls back to the backend default among active companies", () => {
    const companies = [makeCompany(1), makeCompany(3, { is_active: false })];
    const fallbackTarget = makeCompany(2, { is_default: true });
    companies.push(fallbackTarget);
    companies.push(makeCompany(4));

    expect(pickActiveCompany(companies)?.id).toBe(2);
  });

  it("falls back to the first active company otherwise", () => {
    const companies = [makeCompany(1), makeCompany(2)];

    expect(pickActiveCompany(companies)?.id).toBe(1);
  });
});