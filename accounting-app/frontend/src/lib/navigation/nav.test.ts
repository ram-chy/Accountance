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

  it("links the sales and receivables modules with their own permissions", () => {
    const sales = section("Sales and receivables");

    expect(sales.items).toEqual(
      expect.arrayContaining([
        { href: "/customers", label: "Customers", permission: "customers.view" },
        { href: "/sales-invoices", label: "Sales invoices", permission: "sales.invoices.view" },
        { href: "/customer-receipts", label: "Customer receipts", permission: "customer.receipts.view" },
        {
          href: "/credit-notes",
          label: "Credit notes",
          permission: "accounting.credit_debit_note.view",
        },
      ]),
    );
  });

  it("no longer advertises credit notes as upcoming", () => {
    expect(section("Sales and receivables").upcoming ?? []).not.toContain("Credit notes");
  });

  it("exposes the receivables reports under the report permission", () => {
    const reports = section("Reports");

    expect(reports.items).toEqual(
      expect.arrayContaining([
        { href: "/reports/customer-statement", label: "Customer Statement", permission: "accounting.reports.view" },
        { href: "/reports/receivables", label: "Receivables", permission: "accounting.reports.view" },
        { href: "/reports/receivables-aging", label: "Aged Receivables", permission: "accounting.reports.view" },
      ]),
    );
  });

  it("links the purchases and payables modules with their own permissions", () => {
    const purchases = section("Purchases and payables");

    expect(purchases.items).toEqual(
      expect.arrayContaining([
        { href: "/suppliers", label: "Suppliers", permission: "suppliers.view" },
        { href: "/purchase-bills", label: "Purchase bills", permission: "purchases.bills.view" },
        { href: "/supplier-payments", label: "Supplier payments", permission: "supplier.payments.view" },
        {
          href: "/debit-notes",
          label: "Debit notes",
          permission: "accounting.credit_debit_note.view",
        },
      ]),
    );
  });

  it("no longer advertises debit notes as upcoming", () => {
    expect(section("Purchases and payables").upcoming ?? []).not.toContain("Debit notes");
  });

  it("no longer advertises the built purchases modules as upcoming", () => {
    const upcoming = section("Purchases and payables").upcoming ?? [];

    expect(upcoming).not.toContain("Suppliers");
    expect(upcoming).not.toContain("Purchase bills");
    expect(upcoming).not.toContain("Supplier payments");
  });

  it("exposes the payables reports under the report permission", () => {
    const reports = section("Reports");

    expect(reports.items).toEqual(
      expect.arrayContaining([
        { href: "/reports/supplier-statement", label: "Supplier Statement", permission: "accounting.reports.view" },
        { href: "/reports/payables", label: "Payables", permission: "accounting.reports.view" },
        { href: "/reports/payables-aging", label: "Aged Payables", permission: "accounting.reports.view" },
      ]),
    );
  });

  it("links the fixed asset module under the fixed asset permission", () => {
    const fixedAssets = section("Fixed assets");

    expect(fixedAssets.items).toEqual([
      { href: "/fixed-assets", label: "Fixed assets", permission: "accounting.fixed_asset.view" },
      { href: "/fixed-assets/register", label: "Register", permission: "accounting.fixed_asset.view" },
      { href: "/fixed-assets/categories", label: "Categories", permission: "accounting.fixed_asset.view" },
      { href: "/fixed-assets/depreciation", label: "Depreciation", permission: "accounting.fixed_asset.view" },
      { href: "/fixed-assets/disposals", label: "Disposals", permission: "accounting.fixed_asset.view" },
      { href: "/fixed-assets/reports", label: "Reports", permission: "accounting.fixed_asset.view" },
    ]);
  });

  it("does not advertise fixed assets as upcoming anywhere", () => {
    for (const entry of NAV_SECTIONS) {
      expect(entry.upcoming ?? []).not.toContain("Fixed assets");
    }
  });

  it("links the multi-currency module with its own permissions", () => {
    const multiCurrency = section("Multi-currency");

    expect(multiCurrency.items).toEqual([
      { href: "/currencies", label: "Currencies", permission: "accounting.currency.view" },
      { href: "/exchange-rates", label: "Exchange rates", permission: "accounting.exchange_rate.view" },
      { href: "/currency-settings", label: "Currency settings", permission: "accounting.fx.update" },
    ]);
  });

  it("does not advertise multi-currency items as upcoming anywhere", () => {
    for (const entry of NAV_SECTIONS) {
      const upcoming = entry.upcoming ?? [];
      expect(upcoming).not.toContain("Currencies");
      expect(upcoming).not.toContain("Exchange rates");
      expect(upcoming).not.toContain("Currency settings");
    }
  });

  it("links budgets, dimensions and budget variance under their own permissions", () => {
    const budgets = section("Budgets & dimensions");

    expect(budgets.items).toEqual([
      { href: "/budgets", label: "Budgets", permission: "accounting.budgets.view" },
      { href: "/dimensions", label: "Dimensions", permission: "accounting.dimensions.view" },
      {
        href: "/reports/budget-variance",
        label: "Budget variance",
        permission: "accounting.budgets.view",
      },
    ]);
  });

  it("gates budget variance on the budget permission, not the reports permission", () => {
    const budgetVariance = section("Budgets & dimensions").items.find(
      (item) => item.href === "/reports/budget-variance",
    );

    expect(budgetVariance?.permission).toBe("accounting.budgets.view");
  });

  it("does not advertise budgets or dimensions as upcoming anywhere", () => {
    for (const entry of NAV_SECTIONS) {
      const upcoming = entry.upcoming ?? [];
      expect(upcoming).not.toContain("Budgets");
      expect(upcoming).not.toContain("Dimensions");
    }
  });

  it("links the company administration modules with their own permissions", () => {
    const company = section("Company");

    expect(company.items).toEqual([
      { href: "/settings/company", label: "Company settings", permission: "companies.view" },
      { href: "/settings/company/members", label: "Members", permission: "companies.update" },
      { href: "/settings/users", label: "Users", permission: "users.view" },
      { href: "/companies/new", label: "New company", permission: "companies.create" },
    ]);
  });

  it("no longer advertises company settings or users as upcoming", () => {
    const upcoming = section("Company").upcoming ?? [];

    expect(upcoming).not.toContain("Company settings");
    expect(upcoming).not.toContain("Users and roles");
  });

  it("exposes the account screens without a permission gate", () => {
    const account = section("Account");

    expect(account.items).toEqual([
      { href: "/settings/profile", label: "My profile" },
      { href: "/settings/security", label: "Security" },
    ]);
  });
});
