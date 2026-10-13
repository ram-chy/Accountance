import type { Metadata } from "next";

import { SalesInvoiceForm } from "@/components/sales-invoices/sales-invoice-form";

export const metadata: Metadata = {
  title: "New sales invoice",
};

export default function NewSalesInvoicePage() {
  return <SalesInvoiceForm />;
}
