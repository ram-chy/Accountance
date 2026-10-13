import { describe, expect, it } from "vitest";

import { NAV_SECTIONS } from "./nav";

function section(label: string) {
  const found = NAV_SECTIONS.find((entry) => entry.label === label);
  if (!found) throw new Error(`No nav section named ${label}`);
  return found;
}

describe("NAV_SECTIONS", () => {
  it("exposes the balance sheet and cash & bank reports under the report permission", () => {
    const reports = section("Reports");

    expect(reports.items).toEqual(
      expect.arrayContaining([
        { href: "/reports/balance-sheet", label: "Balance Sheet", permission: "accounting.reports.view" },
        { href: "/reports/cash-bank", label: "Cash & Bank", permission: "accounting.reports.view" },
      ]),
    );
  });

  it("keeps the trial balance, P&L and general ledger links", () => {
    const hrefs = section("Reports").items.map((item) => item.href);

    expect(hrefs).toEqual(
      expect.arrayContaining([
        "/reports/trial-balance",
        "/reports/profit-loss",
        "/reports/general-ledger",
      ]),
    );
  });

  it("no longer advertises a stale Ledgers placeholder", () => {
    const accounting = section("Accounting");

    expect(accounting.upcoming ?? []).not.toContain("Ledgers");
  });
});
