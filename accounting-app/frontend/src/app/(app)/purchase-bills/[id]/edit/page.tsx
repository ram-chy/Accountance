import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { PurchaseBillForm } from "@/components/purchase-bills/purchase-bill-form";

export const metadata: Metadata = {
  title: "Edit purchase bill",
};

type EditPurchaseBillPageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditPurchaseBillPage({ params }: EditPurchaseBillPageProps) {
  const { id } = await params;
  const billId = Number(id);

  if (!Number.isInteger(billId) || billId <= 0) {
    notFound();
  }

  return <PurchaseBillForm billId={billId} />;
}
