import type { Metadata } from "next";

import { ReceivablesView } from "@/components/reports/receivables-view";

export const metadata: Metadata = {
  title: "Receivables",
};

export default function ReceivablesPage() {
  return <ReceivablesView />;
}
