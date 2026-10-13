import type { Metadata } from "next";

import { CustomerReceiptsView } from "@/components/customer-receipts/customer-receipts-view";

export const metadata: Metadata = {
  title: "Customer Receipts",
};

export default function CustomerReceiptsPage() {
  return <CustomerReceiptsView />;
}
