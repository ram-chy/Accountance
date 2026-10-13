import { describe, expect, it } from "vitest";

import {
  parseBalanceSheet,
  parseCashBank,
  parseCustomerStatement,
  parseGeneralLedger,
  parsePayables,
  parsePayablesAging,
  parseProfitLoss,
  parseReceivables,
  parseReceivablesAging,
  parseSupplierStatement,
  parseTrialBalance,
} from "./validators";

/**
 * These fixtures mirror the exact arrays the backend report services build
 * (`app/Services/Accounting/Reports/*`). The P&L and balance-sheet account rows
 * deliberately carry no `account_type` field, because
 * `JournalReportService::signedRowsForType()` does not return one.
 */

const baseCurrency = {
  code: "USD",
  name: "US Dollar",
  symbol: "$",
  decimals: 2,
};

const trialBalance = {
  period: { from: "2026-01-01", to: "2026-12-31" },
  base_currency: baseCurrency,
  rows: [
    {
      account_id: 1,
      code: "1000",
      name: "Cash",
      account_type: "ASSET",
      debit_total: "100.0000",
      credit_total: "0.0000",
      net_balance: "100.0000",
    },
  ],
  totals: {
    debit_total: "100.0000",
    credit_total: "0.0000",
    net_balance: "100.0000",
    is_balanced: false,
    difference: "100.0000",
  },
  include_zero_balances: false,
};

const profitLoss = {
  period: { from: null, to: null },
  base_currency: baseCurrency,
  revenue: {
    total: "1000.0000",
    accounts: [{ account_id: 1, code: "4000", name: "Sales", amount: "1000.0000" }],
  },
  expenses: {
    total: "600.0000",
    accounts: [{ account_id: 2, code: "5000", name: "Rent", amount: "600.0000" }],
  },
  gross_profit: "400.0000",
  net_profit: "400.0000",
  is_profitable: true,
};

const generalLedger = {
  account: {
    id: 1,
    code: "1000",
    name: "Cash",
    account_type: "ASSET",
    normal_balance: "DEBIT",
    is_active: true,
  },
  period: { from: null, to: null },
  base_currency: baseCurrency,
  opening_balance: "0.0000",
  rows: [
    {
      journal_id: 1,
      journal_line_id: 10,
      journal_number: "JNL-000001",
      journal_date: "2026-01-15",
      reference: null,
      description: "Opening entry",
      source_type: "MANUAL",
      source_id: null,
      debit: "500.0000",
      credit: "0.0000",
      running_balance: "500.0000",
      currency_id: null,
      exchange_rate: null,
      foreign_debit: null,
      foreign_credit: null,
    },
  ],
  period_debits: "500.0000",
  period_credits: "0.0000",
  closing_balance: "500.0000",
  closing_balance_signed: "500.0000",
};

const balanceSheet = {
  period: { from: null, to: "2026-12-31" },
  base_currency: baseCurrency,
  assets: {
    total: "1000.0000",
    accounts: [{ account_id: 1, code: "1000", name: "Cash", amount: "1000.0000" }],
  },
  liabilities: {
    total: "400.0000",
    accounts: [{ account_id: 2, code: "2000", name: "Loan", amount: "400.0000" }],
  },
  equity: {
    total: "600.0000",
    accounts: [{ account_id: 3, code: "3000", name: "Capital", amount: "200.0000" }],
    retained_earnings: "400.0000",
  },
  totals: {
    assets: "1000.0000",
    liabilities: "400.0000",
    equity: "600.0000",
    liabilities_and_equity: "1000.0000",
    is_balanced: true,
    difference: "0.0000",
  },
  current_period_result: "400.0000",
};

describe("parseTrialBalance", () => {
  it("narrows a complete payload", () => {
    const parsed = parseTrialBalance(trialBalance);

    expect(parsed).not.toBeNull();
    expect(parsed?.rows[0].code).toBe("1000");
    expect(parsed?.totals.difference).toBe("100.0000");
  });

  it("rejects a row with a non-decimal balance", () => {
    expect(
      parseTrialBalance({
        ...trialBalance,
        rows: [{ ...trialBalance.rows[0], net_balance: "not-a-number" }],
      }),
    ).toBeNull();
  });

  it("rejects a row that is missing its code", () => {
    expect(
      parseTrialBalance({ ...trialBalance, rows: [{ ...trialBalance.rows[0], code: undefined }] }),
    ).toBeNull();
  });

  it("rejects a non-object", () => {
    expect(parseTrialBalance("nope")).toBeNull();
    expect(parseTrialBalance(null)).toBeNull();
  });
});

describe("parseProfitLoss", () => {
  it("accepts account rows with no account_type field", () => {
    const parsed = parseProfitLoss(profitLoss);

    expect(parsed).not.toBeNull();
    expect(parsed?.revenue.accounts[0].code).toBe("4000");
    expect(parsed?.net_profit).toBe("400.0000");
  });

  it("rejects an account row with a non-decimal amount", () => {
    expect(
      parseProfitLoss({
        ...profitLoss,
        revenue: {
          total: "1000.0000",
          accounts: [{ account_id: 1, code: "4000", name: "Sales", amount: "x" }],
        },
      }),
    ).toBeNull();
  });

  it("rejects a section whose accounts is not an array", () => {
    expect(
      parseProfitLoss({ ...profitLoss, expenses: { total: "0.0000", accounts: {} } }),
    ).toBeNull();
  });
});

describe("parseGeneralLedger", () => {
  it("narrows a complete payload including FX provenance fields", () => {
    const parsed = parseGeneralLedger(generalLedger);

    expect(parsed).not.toBeNull();
    expect(parsed?.account.code).toBe("1000");
    expect(parsed?.rows[0].running_balance).toBe("500.0000");
  });

  it("accepts a foreign row with numeric provenance", () => {
    const parsed = parseGeneralLedger({
      ...generalLedger,
      rows: [
        {
          ...generalLedger.rows[0],
          currency_id: 2,
          exchange_rate: "1.250000",
          foreign_debit: "400.0000",
          foreign_credit: null,
        },
      ],
    });

    expect(parsed).not.toBeNull();
    expect(parsed?.rows[0].currency_id).toBe(2);
  });

  it("rejects a row with an invalid journal_date", () => {
    expect(
      parseGeneralLedger({
        ...generalLedger,
        rows: [{ ...generalLedger.rows[0], journal_date: "15/01/2026" }],
      }),
    ).toBeNull();
  });

  it("rejects a payload missing the closing balance", () => {
    expect(parseGeneralLedger({ ...generalLedger, closing_balance: undefined })).toBeNull();
  });
});

describe("parseCashBank", () => {
  it("uses the general-ledger shape", () => {
    const parsed = parseCashBank(generalLedger);

    expect(parsed).not.toBeNull();
    expect(parsed?.opening_balance).toBe("0.0000");
  });

  it("rejects the same malformed payload as the general ledger", () => {
    expect(parseCashBank({ ...generalLedger, rows: [{}] })).toBeNull();
  });
});

describe("parseBalanceSheet", () => {
  it("narrows a complete payload with retained earnings", () => {
    const parsed = parseBalanceSheet(balanceSheet);

    expect(parsed).not.toBeNull();
    expect(parsed?.equity.retained_earnings).toBe("400.0000");
    expect(parsed?.totals.is_balanced).toBe(true);
    expect(parsed?.current_period_result).toBe("400.0000");
  });

  it("rejects an equity section missing retained earnings", () => {
    expect(
      parseBalanceSheet({
        ...balanceSheet,
        equity: { ...balanceSheet.equity, retained_earnings: undefined },
      }),
    ).toBeNull();
  });

  it("rejects totals with a non-boolean balanced flag", () => {
    expect(
      parseBalanceSheet({
        ...balanceSheet,
        totals: { ...balanceSheet.totals, is_balanced: "yes" },
      }),
    ).toBeNull();
  });

  it("rejects an account row that is missing its amount", () => {
    expect(
      parseBalanceSheet({
        ...balanceSheet,
        assets: {
          total: "1000.0000",
          accounts: [{ account_id: 1, code: "1000", name: "Cash" }],
        },
      }),
    ).toBeNull();
  });
});

const customerStatement = {
  customer: { id: 4, code: "C-001", name: "Acme" },
  period: { from: "2026-01-01", to: "2026-06-30" },
  base_currency: baseCurrency,
  opening_balance: "0.0000",
  rows: [
    {
      date: "2026-02-01",
      type: "invoice",
      reference: "INV-001",
      description: null,
      debit: "1000.0000",
      credit: "0.0000",
      running_balance: "1000.0000",
      currency_code: "USD",
      exchange_rate: null,
      foreign_amount: null,
    },
    {
      date: "2026-02-10",
      type: "receipt",
      reference: "RCP-001",
      description: "Bank transfer",
      debit: "0.0000",
      credit: "400.0000",
      running_balance: "600.0000",
      currency_code: null,
      exchange_rate: null,
      foreign_amount: null,
    },
  ],
  totals: { debit: "1000.0000", credit: "400.0000" },
  closing_balance: "600.0000",
};

describe("parseCustomerStatement", () => {
  it("narrows a complete statement including running balances", () => {
    const parsed = parseCustomerStatement(customerStatement);

    expect(parsed).not.toBeNull();
    expect(parsed?.customer.code).toBe("C-001");
    expect(parsed?.rows[1].running_balance).toBe("600.0000");
    expect(parsed?.closing_balance).toBe("600.0000");
  });

  it("rejects a row with a non-numeric running balance", () => {
    expect(
      parseCustomerStatement({
        ...customerStatement,
        rows: [{ ...customerStatement.rows[0], running_balance: "n/a" }],
      }),
    ).toBeNull();
  });

  it("rejects a payload missing the closing balance", () => {
    expect(parseCustomerStatement({ ...customerStatement, closing_balance: undefined })).toBeNull();
  });

  it("preserves a credit_note row from the statement integration", () => {
    const parsed = parseCustomerStatement({
      ...customerStatement,
      rows: [
        {
          ...customerStatement.rows[0],
          type: "credit_note",
          reference: "CN-000001",
          debit: "0.0000",
          credit: "100.0000",
        },
      ],
    });

    expect(parsed).not.toBeNull();
    expect(parsed?.rows[0].type).toBe("credit_note");
  });
});

const receivables = {
  as_of: "2026-06-30",
  base_currency: baseCurrency,
  rows: [
    {
      invoice_id: 11,
      invoice_number: "INV-011",
      invoice_date: "2026-05-01",
      due_date: "2026-05-31",
      customer: { id: 4, code: "C-001", name: "Acme" },
      grand_total: "1000.0000",
      paid_total: "400.0000",
      balance_due: "600.0000",
      days_past_due: 30,
      currency_code: null,
      exchange_rate: null,
      base_grand_total: null,
    },
  ],
  count: 1,
  totals: { grand_total: "1000.0000", paid_total: "400.0000", balance_due: "600.0000" },
};

describe("parseReceivables", () => {
  it("narrows a complete receivables payload", () => {
    const parsed = parseReceivables(receivables);

    expect(parsed).not.toBeNull();
    expect(parsed?.count).toBe(1);
    expect(parsed?.rows[0].balance_due).toBe("600.0000");
  });

  it("accepts a row with a null due date and no FX fields", () => {
    const parsed = parseReceivables({
      ...receivables,
      rows: [{ ...receivables.rows[0], due_date: null, days_past_due: 0 }],
    });

    expect(parsed).not.toBeNull();
    expect(parsed?.rows[0].due_date).toBeNull();
  });

  it("rejects a row with a malformed customer summary", () => {
    expect(
      parseReceivables({
        ...receivables,
        rows: [{ ...receivables.rows[0], customer: { id: 4 } }],
      }),
    ).toBeNull();
  });

  it("rejects totals with a non-decimal balance", () => {
    expect(
      parseReceivables({
        ...receivables,
        totals: { ...receivables.totals, balance_due: "not-a-number" },
      }),
    ).toBeNull();
  });
});

const receivablesAging = {
  as_of: "2026-06-30",
  base_currency: baseCurrency,
  buckets: [
    { key: "current", label: "Current (0-30)", min: null, max: 30, total: "100.0000" },
    { key: "days_31_60", label: "31-60 days", min: 31, max: 60, total: "600.0000" },
  ],
  rows: [
    {
      counterparty: { id: 4, code: "C-001", name: "Acme" },
      buckets: { current: "100.0000", days_31_60: "600.0000" },
      total: "700.0000",
    },
  ],
  totals: {
    by_bucket: { current: "100.0000", days_31_60: "600.0000" },
    total: "700.0000",
  },
};

describe("parseReceivablesAging", () => {
  it("narrows a complete aging payload with data-driven buckets", () => {
    const parsed = parseReceivablesAging(receivablesAging);

    expect(parsed).not.toBeNull();
    expect(parsed?.buckets).toHaveLength(2);
    expect(parsed?.rows[0].buckets.days_31_60).toBe("600.0000");
    expect(parsed?.totals.total).toBe("700.0000");
  });

  it("rejects a bucket with a non-numeric total", () => {
    expect(
      parseReceivablesAging({
        ...receivablesAging,
        buckets: [{ ...receivablesAging.buckets[0], total: "x" }],
      }),
    ).toBeNull();
  });

  it("rejects a row cell that is not a decimal string", () => {
    expect(
      parseReceivablesAging({
        ...receivablesAging,
        rows: [{ ...receivablesAging.rows[0], buckets: { current: 100 } }],
      }),
    ).toBeNull();
  });
});

const supplierStatement = {
  supplier: { id: 7, code: "S-001", name: "Parts Ltd" },
  period: { from: "2026-01-01", to: "2026-06-30" },
  base_currency: baseCurrency,
  opening_balance: "0.0000",
  rows: [
    {
      date: "2026-02-01",
      type: "bill",
      reference: "BILL-001",
      description: null,
      debit: "0.0000",
      credit: "1000.0000",
      running_balance: "1000.0000",
      currency_code: "USD",
      exchange_rate: null,
      foreign_amount: null,
    },
    {
      date: "2026-02-10",
      type: "payment",
      reference: "PAY-001",
      description: "Bank transfer",
      debit: "400.0000",
      credit: "0.0000",
      running_balance: "600.0000",
      currency_code: null,
      exchange_rate: null,
      foreign_amount: null,
    },
  ],
  totals: { debit: "400.0000", credit: "1000.0000" },
  closing_balance: "600.0000",
};

describe("parseSupplierStatement", () => {
  it("narrows a complete statement including running balances", () => {
    const parsed = parseSupplierStatement(supplierStatement);

    expect(parsed).not.toBeNull();
    expect(parsed?.supplier.code).toBe("S-001");
    expect(parsed?.rows[1].running_balance).toBe("600.0000");
    expect(parsed?.closing_balance).toBe("600.0000");
  });

  it("rejects a row with a non-numeric running balance", () => {
    expect(
      parseSupplierStatement({
        ...supplierStatement,
        rows: [{ ...supplierStatement.rows[0], running_balance: "n/a" }],
      }),
    ).toBeNull();
  });

  it("rejects a payload missing the closing balance", () => {
    expect(parseSupplierStatement({ ...supplierStatement, closing_balance: undefined })).toBeNull();
  });

  it("preserves a debit_note row from the statement integration", () => {
    const parsed = parseSupplierStatement({
      ...supplierStatement,
      rows: [
        {
          ...supplierStatement.rows[0],
          type: "debit_note",
          reference: "DN-000001",
          debit: "100.0000",
          credit: "0.0000",
        },
      ],
    });

    expect(parsed).not.toBeNull();
    expect(parsed?.rows[0].type).toBe("debit_note");
  });
});

const payables = {
  as_of: "2026-06-30",
  base_currency: baseCurrency,
  rows: [
    {
      bill_id: 21,
      bill_number: "BILL-021",
      bill_date: "2026-05-01",
      due_date: "2026-05-31",
      supplier: { id: 7, code: "S-001", name: "Parts Ltd" },
      grand_total: "1000.0000",
      paid_total: "400.0000",
      balance_due: "600.0000",
      days_past_due: 30,
      currency_code: null,
      exchange_rate: null,
      base_grand_total: null,
    },
  ],
  count: 1,
  totals: { grand_total: "1000.0000", paid_total: "400.0000", balance_due: "600.0000" },
};

describe("parsePayables", () => {
  it("narrows a complete payables payload", () => {
    const parsed = parsePayables(payables);

    expect(parsed).not.toBeNull();
    expect(parsed?.count).toBe(1);
    expect(parsed?.rows[0].balance_due).toBe("600.0000");
  });

  it("accepts a row with a null due date and no FX fields", () => {
    const parsed = parsePayables({
      ...payables,
      rows: [{ ...payables.rows[0], due_date: null, days_past_due: 0 }],
    });

    expect(parsed).not.toBeNull();
    expect(parsed?.rows[0].due_date).toBeNull();
  });

  it("rejects a row with a malformed supplier summary", () => {
    expect(
      parsePayables({
        ...payables,
        rows: [{ ...payables.rows[0], supplier: { id: 7 } }],
      }),
    ).toBeNull();
  });

  it("rejects totals with a non-decimal balance", () => {
    expect(
      parsePayables({
        ...payables,
        totals: { ...payables.totals, balance_due: "not-a-number" },
      }),
    ).toBeNull();
  });
});

const payablesAging = {
  as_of: "2026-06-30",
  base_currency: baseCurrency,
  buckets: [
    { key: "current", label: "Current (0-30)", min: null, max: 30, total: "100.0000" },
    { key: "days_31_60", label: "31-60 days", min: 31, max: 60, total: "600.0000" },
  ],
  rows: [
    {
      counterparty: { id: 7, code: "S-001", name: "Parts Ltd" },
      buckets: { current: "100.0000", days_31_60: "600.0000" },
      total: "700.0000",
    },
  ],
  totals: {
    by_bucket: { current: "100.0000", days_31_60: "600.0000" },
    total: "700.0000",
  },
};

describe("parsePayablesAging", () => {
  it("narrows a complete aging payload with data-driven buckets", () => {
    const parsed = parsePayablesAging(payablesAging);

    expect(parsed).not.toBeNull();
    expect(parsed?.buckets).toHaveLength(2);
    expect(parsed?.rows[0].buckets.days_31_60).toBe("600.0000");
    expect(parsed?.totals.total).toBe("700.0000");
  });

  it("rejects a bucket with a non-numeric total", () => {
    expect(
      parsePayablesAging({
        ...payablesAging,
        buckets: [{ ...payablesAging.buckets[0], total: "x" }],
      }),
    ).toBeNull();
  });

  it("rejects a row cell that is not a decimal string", () => {
    expect(
      parsePayablesAging({
        ...payablesAging,
        rows: [{ ...payablesAging.rows[0], buckets: { current: 100 } }],
      }),
    ).toBeNull();
  });
});
