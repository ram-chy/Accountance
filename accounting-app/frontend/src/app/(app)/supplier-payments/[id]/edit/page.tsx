import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { SupplierPaymentForm } from "@/components/supplier-payments/supplier-payment-form";

export const metadata: Metadata = {
  title: "Edit supplier payment",
};

type EditSupplierPaymentPageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditSupplierPaymentPage({ params }: EditSupplierPaymentPageProps) {
  const { id } = await params;
  const paymentId = Number(id);

  if (!Number.isInteger(paymentId) || paymentId <= 0) {
    notFound();
  }

  return <SupplierPaymentForm paymentId={paymentId} />;
}
