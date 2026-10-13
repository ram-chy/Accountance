import type { Metadata } from "next";

import { CustomerStatementView } from "@/components/reports/customer-statement-view";

export const metadata: Metadata = {
  title: "Customer Statement",
};

export default function CustomerStatementPage() {
  return <CustomerStatementView />;
}
