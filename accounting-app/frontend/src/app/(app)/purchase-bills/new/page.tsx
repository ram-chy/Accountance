import type { Metadata } from "next";

import { PurchaseBillForm } from "@/components/purchase-bills/purchase-bill-form";

export const metadata: Metadata = {
  title: "New purchase bill",
};

export default function NewPurchaseBillPage() {
  return <PurchaseBillForm />;
}
