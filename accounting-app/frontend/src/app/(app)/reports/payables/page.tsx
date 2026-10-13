import type { Metadata } from "next";

import { PayablesView } from "@/components/reports/payables-view";

export const metadata: Metadata = {
  title: "Payables",
};

export default function PayablesPage() {
  return <PayablesView />;
}
