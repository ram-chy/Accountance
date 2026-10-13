/**
 * Application navigation.
 *
 * Navigation is expressed as labelled sections. Each section holds the real,
 * implemented destinations in `items` and the not-yet-built modules in
 * `upcoming`. An `upcoming` entry is rendered as a clearly-marked, non-clickable
 * placeholder — it is never a link, so it can never point at a route that does
 * not exist.
 *
 * `NavItem.permission` is the same permission string the backend enforces for
 * that surface; `usePermissions` filters by it so a user never sees a link to a
 * module they cannot use. Adding a real module later is a data change here.
 */

export type NavItem = {
  href: string;
  label: string;
  /** When present, the item is shown only to users holding this permission. */
  permission?: string;
};

export type NavSection = {
  label: string;
  /** Implemented destinations (rendered as links, filtered by permission). */
  items: NavItem[];
  /** Modules planned for a later phase (rendered as disabled placeholders). */
  upcoming?: string[];
};

export const NAV_SECTIONS: readonly NavSection[] = [
  {
    label: "Overview",
    items: [{ href: "/", label: "Home" }],
  },
  {
    label: "Accounting",
    items: [
      {
        href: "/accounts",
        label: "Chart of accounts",
        permission: "accounts.view",
      },
      {
        href: "/journals",
        label: "Journals",
        permission: "journals.view",
      },
      {
        href: "/financial-years",
        label: "Financial years",
        permission: "accounting.periods.view",
      },
      {
        href: "/periods",
        label: "Accounting periods",
        permission: "accounting.periods.view",
      },
      {
        href: "/taxes",
        label: "Taxes",
        permission: "accounting.tax.view",
      },
      {
        href: "/tax-calculator",
        label: "Tax calculator",
        permission: "accounting.tax.calculate",
      },
    ],
  },
  {
    label: "Sales and receivables",
    items: [
      {
        href: "/customers",
        label: "Customers",
        permission: "customers.view",
      },
      {
        href: "/sales-invoices",
        label: "Sales invoices",
        permission: "sales.invoices.view",
      },
      {
        href: "/customer-receipts",
        label: "Customer receipts",
        permission: "customer.receipts.view",
      },
      {
        href: "/credit-notes",
        label: "Credit notes",
        permission: "accounting.credit_debit_note.view",
      },
    ],
  },
  {
    label: "Purchases and payables",
    items: [
      {
        href: "/suppliers",
        label: "Suppliers",
        permission: "suppliers.view",
      },
      {
        href: "/purchase-bills",
        label: "Purchase bills",
        permission: "purchases.bills.view",
      },
      {
        href: "/supplier-payments",
        label: "Supplier payments",
        permission: "supplier.payments.view",
      },
      {
        href: "/debit-notes",
        label: "Debit notes",
        permission: "accounting.credit_debit_note.view",
      },
    ],
  },
  {
    label: "Banking",
    items: [
      {
        href: "/cash-bank",
        label: "Cash and bank",
        permission: "accounting.cash_bank.view",
      },
      {
        href: "/cash-bank/transactions",
        label: "Transactions",
        permission: "accounting.cash_bank.view",
      },
      {
        href: "/bank-reconciliations",
        label: "Reconciliations",
        permission: "accounting.bank_reconciliation.view",
      },
    ],
  },
  {
    label: "Reports",
    items: [
      {
        href: "/reports/trial-balance",
        label: "Trial Balance",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/profit-loss",
        label: "Profit & Loss",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/general-ledger",
        label: "General Ledger",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/balance-sheet",
        label: "Balance Sheet",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/cash-bank",
        label: "Cash & Bank",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/customer-statement",
        label: "Customer Statement",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/receivables",
        label: "Receivables",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/receivables-aging",
        label: "Aged Receivables",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/supplier-statement",
        label: "Supplier Statement",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/payables",
        label: "Payables",
        permission: "accounting.reports.view",
      },
      {
        href: "/reports/payables-aging",
        label: "Aged Payables",
        permission: "accounting.reports.view",
      },
      {
        href: "/tax-reports",
        label: "Tax reports",
        permission: "accounting.tax.report.view",
      },
    ],
    upcoming: [],
  },
  {
    label: "Company",
    items: [],
    upcoming: ["Company settings", "Users and roles"],
  },
];
