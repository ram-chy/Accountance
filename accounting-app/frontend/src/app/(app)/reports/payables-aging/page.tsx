import type { Metadata } from "next";

import { PayablesAgingView } from "@/components/reports/payables-aging-view";

export const metadata: Metadata = {
  title: "Aged Payables",
};

export default function PayablesAgingPage() {
  return <PayablesAgingView />;
}
