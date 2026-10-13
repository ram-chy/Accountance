import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { SalesInvoiceDetailView } from "@/components/sales-invoices/sales-invoice-detail-view";

export const metadata: Metadata = {
  title: "Sales invoice",
};

type SalesInvoicePageProps = {
  params: Promise<{ id: string }>;
};

export default async function SalesInvoicePage({ params }: SalesInvoicePageProps) {
  const { id } = await params;
  const invoiceId = Number(id);

  if (!Number.isInteger(invoiceId) || invoiceId <= 0) {
    notFound();
  }

  return <SalesInvoiceDetailView invoiceId={invoiceId} />;
}
