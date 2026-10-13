import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CustomerReceiptForm } from "@/components/customer-receipts/customer-receipt-form";

export const metadata: Metadata = {
  title: "Edit customer receipt",
};

type EditCustomerReceiptPageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditCustomerReceiptPage({ params }: EditCustomerReceiptPageProps) {
  const { id } = await params;
  const receiptId = Number(id);

  if (!Number.isInteger(receiptId) || receiptId <= 0) {
    notFound();
  }

  return <CustomerReceiptForm receiptId={receiptId} />;
}
