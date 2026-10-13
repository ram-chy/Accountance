import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { SupplierPaymentDetailView } from "@/components/supplier-payments/supplier-payment-detail-view";

export const metadata: Metadata = {
  title: "Supplier payment",
};

type SupplierPaymentPageProps = {
  params: Promise<{ id: string }>;
};

export default async function SupplierPaymentPage({ params }: SupplierPaymentPageProps) {
  const { id } = await params;
  const paymentId = Number(id);

  if (!Number.isInteger(paymentId) || paymentId <= 0) {
    notFound();
  }

  return <SupplierPaymentDetailView paymentId={paymentId} />;
}
