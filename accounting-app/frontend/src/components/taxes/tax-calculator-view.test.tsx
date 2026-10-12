import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TaxCalculatorView } from "./tax-calculator-view";

const { state, calculateTax } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
  },
  calculateTax: vi.fn(),
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({
    activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null,
  }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({
    can: (name: string) => state.perms.has(name),
  }),
}));

vi.mock("@/lib/taxes/use-taxes", () => ({
  useTaxList: () => state.list,
}));

vi.mock("@/lib/taxes/api", () => ({ calculateTax }));

const readyTaxList = {
  status: "ready",
  items: [
    {
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
      created_at: null,
      updated_at: null,
    },
  ],
};

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.tax.calculate", "accounting.tax.view"]);
  state.list = readyTaxList;
  calculateTax.mockReset();
});

describe("TaxCalculatorView", () => {
  it("lists active taxes to choose from", () => {
    render(<TaxCalculatorView />);

    expect(screen.getByRole("checkbox")).toBeInTheDocument();
    expect(screen.getByText("VAT · VAT standard")).toBeInTheDocument();
  });

  it("hides the calculator without the calculate permission", () => {
    state.perms = new Set(["accounting.tax.view"]);

    render(<TaxCalculatorView />);

    expect(screen.getByText("Tax calculator unavailable")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<TaxCalculatorView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("posts the amount, date and selected taxes and renders the result", async () => {
    const user = userEvent.setup();
    calculateTax.mockResolvedValue({
      basis: "EXCLUSIVE",
      taxable_amount: "100.0000",
      total_tax: "20.0000",
      gross_amount: "120.0000",
      tax_components: [
        {
          tax_id: 1,
          tax_code: "VAT",
          tax_name: "VAT standard",
          tax_type: "OUTPUT",
          tax_rate_id: 5,
          rate: "20.0000",
          taxable_amount: "100.0000",
          tax_amount: "20.0000",
          output_account_id: 30,
          input_account_id: null,
        },
      ],
    });

    render(<TaxCalculatorView />);

    await user.type(screen.getByRole("textbox", { name: "Amount" }), "100");
    await user.click(screen.getByRole("checkbox"));

    const form = screen.getByRole("button", { name: "Calculate" }).closest("form");
    fireEvent.submit(form as HTMLFormElement);

    await waitFor(() =>
      expect(calculateTax).toHaveBeenCalledWith({
        amount: "100",
        date: expect.any(String),
        tax_ids: [1],
      }),
    );
    expect(await screen.findByText("120.0000")).toBeInTheDocument();
  });
});
