import type { Metadata } from "next";

import { SalesInvoicesView } from "@/components/sales-invoices/sales-invoices-view";

export const metadata: Metadata = {
  title: "Sales Invoices",
};

export default function SalesInvoicesPage() {
  return <SalesInvoicesView />;
}
