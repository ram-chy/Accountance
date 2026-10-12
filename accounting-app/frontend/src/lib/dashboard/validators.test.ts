import { describe, expect, it } from "vitest";

import {
  DashboardDataError,
  parseAccountBalance,
  parseCashBankAccounts,
  parseCurrency,
  parseJournals,
  parsePayables,
  parseProfitLoss,
  parseReceivables,
} from "./validators";

// Local fixture mirroring the verified backend base-currency block.
const USD = { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 };

const pnlPayload = {
  period: { from: "2026-04-01", to: "2026-06-30" },
  base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
  revenue: {
    total: "1000.0000",
    accounts: [
      { account_id: 1, code: "4000", name: "Sales", amount: "1000.0000" },
      { account_id: null, code: "bad", name: "Bad", amount: "nope" },
    ],
  },
  expenses: {
    total: "400.0000",
    accounts: [{ account_id: 2, code: "5000", name: "Rent", amount: "400.0000" }],
  },
  gross_profit: "1000.0000",
  net_profit: "600.0000",
  is_profitable: true,
};

describe("parseCurrency", () => {
  it("narrows a currency block and nulls unknown fields", () => {
    expect(parseCurrency(pnlPayload.base_currency)).toEqual(USD);
    expect(parseCurrency(null)).toBeNull();
    expect(parseCurrency({ code: "EUR" })).toEqual({
      code: "EUR",
      name: null,
      symbol: null,
      decimals: null,
    });
  });
});

describe("parseProfitLoss", () => {
  it("narrows the report and filters malformed account rows", () => {
    const report = parseProfitLoss(pnlPayload);

    expect(report.revenue.total).toBe("1000.0000");
    expect(report.revenue.accounts).toHaveLength(1);
    expect(report.revenue.accounts[0]).toEqual({
      accountId: 1,
      code: "4000",
      name: "Sales",
      amount: "1000.0000",
    });
    expect(report.netProfit).toBe("600.0000");
    expect(report.isProfitable).toBe(true);
    expect(report.baseCurrency).toEqual(USD);
  });

  it("throws when the report structure or a required amount is missing", () => {
    expect(() => parseProfitLoss({ revenue: {} })).toThrow(DashboardDataError);
    expect(() =>
      parseProfitLoss({
        revenue: { total: "oops", accounts: [] },
        expenses: { total: "1", accounts: [] },
      }),
    ).toThrow(DashboardDataError);
  });
});

describe("parseReceivables / parsePayables", () => {
  const payload = {
    as_of: "2026-06-30",
    base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
    count: 3,
    totals: { balance_due: "250.0000" },
  };

  it("reads the balance due and count", () => {
    expect(parseReceivables(payload).balanceDue).toBe("250.0000");
    expect(parseReceivables(payload).count).toBe(3);
    expect(parsePayables(payload).balanceDue).toBe("250.0000");
  });

  it("throws when totals are missing", () => {
    expect(() => parseReceivables({})).toThrow(DashboardDataError);
  });
});

describe("parseAccountBalance", () => {
  it("reads the ledger balance", () => {
    expect(
      parseAccountBalance({
        account_id: 5,
        code: "1000",
        name: "Cash",
        balance: "10.0000",
      }).balance,
    ).toBe("10.0000");
  });

  it("throws when the account id is missing", () => {
    expect(() => parseAccountBalance({ code: "1000" })).toThrow(
      DashboardDataError,
    );
  });
});

describe("parseCashBankAccounts", () => {
  it("normalises a list payload and skips rows without an id", () => {
    const accounts = parseCashBankAccounts({
      data: [
        {
          id: 1,
          code: "1010",
          name: "Petty Cash",
          cash_bank_kind: "cash",
          is_active: true,
        },
        { code: "no-id", name: "Broken" },
      ],
    });

    expect(accounts).toEqual([
      {
        id: 1,
        code: "1010",
        name: "Petty Cash",
        kind: "cash",
        isActive: true,
      },
    ]);
  });
});

describe("parseJournals", () => {
  it("maps journal rows and reads the debit total", () => {
    const rows = parseJournals({
      data: [
        {
          id: 7,
          journal_number: "JV-7",
          journal_date: "2026-05-02",
          description: "Opening balances",
          reference: null,
          status: "POSTED",
          source_type: "manual_entry",
          totals: { total_debit: "1000.0000", total_credit: "1000.0000" },
        },
      ],
    });

    expect(rows).toEqual([
      {
        id: 7,
        number: "JV-7",
        date: "2026-05-02",
        description: "Opening balances",
        reference: null,
        status: "POSTED",
        sourceType: "manual_entry",
        amount: "1000.0000",
      },
    ]);
  });

  it("leaves the amount null when totals are absent", () => {
    const [row] = parseJournals({ data: [{ id: 8, status: "DRAFT" }] });
    expect(row.amount).toBeNull();
    expect(row.number).toBe("—");
  });
});
