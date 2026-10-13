import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { SalesInvoiceForm } from "@/components/sales-invoices/sales-invoice-form";

export const metadata: Metadata = {
  title: "Edit sales invoice",
};

type EditSalesInvoicePageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditSalesInvoicePage({ params }: EditSalesInvoicePageProps) {
  const { id } = await params;
  const invoiceId = Number(id);

  if (!Number.isInteger(invoiceId) || invoiceId <= 0) {
    notFound();
  }

  return <SalesInvoiceForm invoiceId={invoiceId} />;
}
