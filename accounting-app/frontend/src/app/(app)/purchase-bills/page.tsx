import type { Metadata } from "next";

import { PurchaseBillsView } from "@/components/purchase-bills/purchase-bills-view";

export const metadata: Metadata = {
  title: "Purchase bills",
};

export default function PurchaseBillsPage() {
  return <PurchaseBillsView />;
}
