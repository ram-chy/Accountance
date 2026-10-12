import { describe, expect, it } from "vitest";

import type {
  CashBankAccountClassifyPayload,
  CashBankTransactionWritePayload,
} from "./types";
import {
  CashBankDataError,
  parseAccountBalance,
  parseCashBankAccount,
  parseCashBankAccountList,
  parseCashBankAccountOrThrow,
  parseCashBankTransaction,
  parseCashBankTransactionList,
  validateCashBankAccountClassify,
  validateCashBankTransactionWrite,
} from "./validators";

function accountResource(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 1,
    code: "1000",
    name: "Cash on hand",
    account_type: "ASSET",
    normal_balance: "DEBIT",
    cash_bank_kind: "CASH",
    is_active: true,
    description: null,
    bank_account: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function bankDetails(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    account_name: "Acme Operating",
    bank_name: "First National",
    account_number: "123456789",
    branch: "Main",
    bank_identifier: "FNB0001",
    is_active: true,
    ...overrides,
  };
}

function transactionResource(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 10,
    transaction_number: "CBT-000010",
    transaction_type: "DEPOSIT",
    transaction_date: "2027-02-01",
    source_account_id: 20,
    destination_account_id: 1,
    amount: "500.0000",
    status: "DRAFT",
    reference: "REF-1",
    notes: null,
    source_account: { id: 20, code: "4000", name: "Sales" },
    destination_account: { id: 1, code: "1000", name: "Cash on hand" },
    journal_id: null,
    posted_at: null,
    created_by: 1,
    posted_by: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function writePayload(
  overrides: Partial<CashBankTransactionWritePayload> = {},
): CashBankTransactionWritePayload {
  return {
    transaction_date: "2027-02-01",
    amount: "500",
    source_account_id: 20,
    destination_account_id: 1,
    reference: null,
    notes: null,
    ...overrides,
  };
}

describe("parseCashBankAccount", () => {
  it("narrows a cash account with no bank details", () => {
    const account = parseCashBankAccount(accountResource());

    expect(account?.code).toBe("1000");
    expect(account?.cash_bank_kind).toBe("CASH");
    expect(account?.bank_account).toBeNull();
  });

  it("narrows a bank account with nested bank details", () => {
    const account = parseCashBankAccount(
      accountResource({ cash_bank_kind: "BANK", bank_account: bankDetails() }),
    );

    expect(account?.bank_account?.bank_name).toBe("First National");
    expect(account?.bank_account?.is_active).toBe(true);
  });

  it("accepts an unclassified account (kind null)", () => {
    const account = parseCashBankAccount(accountResource({ cash_bank_kind: null }));

    expect(account?.cash_bank_kind).toBeNull();
  });

  it("rejects an unknown kind", () => {
    expect(parseCashBankAccount(accountResource({ cash_bank_kind: "PETTY" }))).toBeNull();
  });

  it("rejects a row without an id", () => {
    expect(parseCashBankAccount(accountResource({ id: undefined }))).toBeNull();
  });

  it("rejects malformed bank details rather than guessing", () => {
    expect(
      parseCashBankAccount(accountResource({ bank_account: { bank_name: "Only a name" } })),
    ).toBeNull();
  });
});

describe("parseCashBankAccountList", () => {
  it("narrows a flat array and infers hasMore from a full page", () => {
    const state = parseCashBankAccountList(
      [accountResource(), accountResource({ id: 2 })],
      2,
    );

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(2);
    expect(state.hasMore).toBe(true);
  });

  it("skips invalid rows without fabricating", () => {
    const state = parseCashBankAccountList([accountResource(), { id: 9 }], 50);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when no row is valid", () => {
    expect(parseCashBankAccountList([{ id: 9 }], 50).status).toBe("error");
  });

  it("errors on a non-list payload", () => {
    expect(parseCashBankAccountList("nope", 50).status).toBe("error");
  });
});

describe("parseCashBankAccountOrThrow", () => {
  it("throws a CashBankDataError for an invalid payload", () => {
    expect(() => parseCashBankAccountOrThrow({ id: 9 })).toThrow(CashBankDataError);
  });
});

describe("parseCashBankTransaction", () => {
  it("narrows a draft transaction", () => {
    const transaction = parseCashBankTransaction(transactionResource());

    expect(transaction?.transaction_number).toBe("CBT-000010");
    expect(transaction?.transaction_type).toBe("DEPOSIT");
    expect(transaction?.status).toBe("DRAFT");
    expect(transaction?.amount).toBe("500.0000");
  });

  it("rejects an unknown type", () => {
    expect(parseCashBankTransaction(transactionResource({ transaction_type: "REFUND" }))).toBeNull();
  });

  it("rejects an unknown status", () => {
    expect(parseCashBankTransaction(transactionResource({ status: "VOID" }))).toBeNull();
  });

  it("rejects a non-decimal amount", () => {
    expect(parseCashBankTransaction(transactionResource({ amount: "five" }))).toBeNull();
  });

  it("rejects a malformed date", () => {
    expect(parseCashBankTransaction(transactionResource({ transaction_date: "01/02/2027" }))).toBeNull();
  });

  it("degrades missing relations and audit fields to null", () => {
    const transaction = parseCashBankTransaction(
      transactionResource({
        source_account: undefined,
        destination_account: undefined,
        reference: undefined,
      }),
    );

    expect(transaction?.source_account).toBeNull();
    expect(transaction?.destination_account).toBeNull();
    expect(transaction?.reference).toBeNull();
  });
});

describe("parseCashBankTransactionList", () => {
  it("narrows a page and infers hasMore", () => {
    const state = parseCashBankTransactionList(
      [transactionResource(), transactionResource({ id: 11 })],
      2,
    );

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(2);
    expect(state.hasMore).toBe(true);
  });

  it("errors when no row is valid", () => {
    expect(parseCashBankTransactionList([{ id: 9 }], 50).status).toBe("error");
  });
});

describe("parseAccountBalance", () => {
  it("narrows a balance read", () => {
    const balance = parseAccountBalance({
      account_id: 1,
      code: "1000",
      name: "Cash on hand",
      account_type: "ASSET",
      normal_balance: "DEBIT",
      is_active: true,
      total_debit: "500.0000",
      total_credit: "0.0000",
      balance: "500.0000",
    });

    expect(balance?.balance).toBe("500.0000");
    expect(balance?.total_debit).toBe("500.0000");
  });

  it("rejects a payload without a balance", () => {
    expect(parseAccountBalance({ account_id: 1 })).toBeNull();
  });
});

describe("validateCashBankTransactionWrite", () => {
  it("accepts a well-formed payload", () => {
    expect(validateCashBankTransactionWrite(writePayload())).toEqual({});
  });

  it("rejects a date that is not Y-m-d", () => {
    expect(
      validateCashBankTransactionWrite(writePayload({ transaction_date: "01/02/2027" }))
        .transaction_date,
    ).toBeDefined();
  });

  it("rejects a zero amount", () => {
    expect(validateCashBankTransactionWrite(writePayload({ amount: "0" })).amount).toBeDefined();
  });

  it("rejects more than four decimal places", () => {
    expect(
      validateCashBankTransactionWrite(writePayload({ amount: "1.00001" })).amount,
    ).toBeDefined();
  });

  it("rejects a missing source account", () => {
    expect(
      validateCashBankTransactionWrite(writePayload({ source_account_id: 0 })).source_account_id,
    ).toBeDefined();
  });

  it("rejects identical source and destination accounts", () => {
    expect(
      validateCashBankTransactionWrite(
        writePayload({ source_account_id: 5, destination_account_id: 5 }),
      ).destination_account_id,
    ).toBeDefined();
  });
});

describe("validateCashBankAccountClassify", () => {
  it("accepts a null kind (removal)", () => {
    const payload: CashBankAccountClassifyPayload = { cash_bank_kind: null };
    expect(validateCashBankAccountClassify(payload)).toEqual({});
  });

  it("requires a bank name and account name when details are supplied", () => {
    const errors = validateCashBankAccountClassify({
      cash_bank_kind: "BANK",
      bank_account: { account_name: "", bank_name: "" },
    });

    expect(errors["bank_account.account_name"]).toBeDefined();
    expect(errors["bank_account.bank_name"]).toBeDefined();
  });

  it("accepts a complete bank block", () => {
    expect(
      validateCashBankAccountClassify({
        cash_bank_kind: "BANK",
        bank_account: { account_name: "Acme", bank_name: "First National" },
      }),
    ).toEqual({});
  });
});
