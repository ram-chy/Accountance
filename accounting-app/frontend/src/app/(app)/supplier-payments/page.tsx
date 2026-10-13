import type { Metadata } from "next";

import { SupplierPaymentsView } from "@/components/supplier-payments/supplier-payments-view";

export const metadata: Metadata = {
  title: "Supplier payments",
};

export default function SupplierPaymentsPage() {
  return <SupplierPaymentsView />;
}
