import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CustomerReceiptDetailView } from "@/components/customer-receipts/customer-receipt-detail-view";

export const metadata: Metadata = {
  title: "Customer receipt",
};

type CustomerReceiptPageProps = {
  params: Promise<{ id: string }>;
};

export default async function CustomerReceiptPage({ params }: CustomerReceiptPageProps) {
  const { id } = await params;
  const receiptId = Number(id);

  if (!Number.isInteger(receiptId) || receiptId <= 0) {
    notFound();
  }

  return <CustomerReceiptDetailView receiptId={receiptId} />;
}
