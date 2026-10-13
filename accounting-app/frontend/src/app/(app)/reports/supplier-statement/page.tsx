import type { Metadata } from "next";

import { SupplierStatementView } from "@/components/reports/supplier-statement-view";

export const metadata: Metadata = {
  title: "Supplier Statement",
};

export default function SupplierStatementPage() {
  return <SupplierStatementView />;
}
