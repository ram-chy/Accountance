import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { PurchaseBillDetailView } from "@/components/purchase-bills/purchase-bill-detail-view";

export const metadata: Metadata = {
  title: "Purchase bill",
};

type PurchaseBillPageProps = {
  params: Promise<{ id: string }>;
};

export default async function PurchaseBillPage({ params }: PurchaseBillPageProps) {
  const { id } = await params;
  const billId = Number(id);

  if (!Number.isInteger(billId) || billId <= 0) {
    notFound();
  }

  return <PurchaseBillDetailView billId={billId} />;
}
