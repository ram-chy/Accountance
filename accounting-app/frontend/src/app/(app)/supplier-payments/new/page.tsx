import type { Metadata } from "next";

import { SupplierPaymentForm } from "@/components/supplier-payments/supplier-payment-form";

export const metadata: Metadata = {
  title: "New supplier payment",
};

export default function NewSupplierPaymentPage() {
  return <SupplierPaymentForm />;
}
