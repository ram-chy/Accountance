import type { Metadata } from "next";

import { DisposalsView } from "@/components/fixed-assets/disposals-view";

export const metadata: Metadata = {
  title: "Disposals",
};

export default function DisposalsPage() {
  return <DisposalsView />;
}
