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
    ],
    upcoming: ["Ledgers", "Periods and controls"],
  },
  {
    label: "Sales and receivables",
    items: [],
    upcoming: ["Sales invoices", "Customers", "Credit notes", "Customer receipts"],
  },
  {
    label: "Purchases and payables",
    items: [],
    upcoming: [
      "Purchase bills",
      "Suppliers",
      "Debit notes",
      "Supplier payments",
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
    ],
    upcoming: ["Reconciliations"],
  },
  {
    label: "Reports",
    items: [],
    upcoming: ["Financial reports", "Tax reports"],
  },
  {
    label: "Company",
    items: [],
    upcoming: ["Company settings", "Users and roles"],
  },
];
