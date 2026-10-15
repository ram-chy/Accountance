import type { Metadata } from "next";

import { FixedAssetsView } from "@/components/fixed-assets/fixed-assets-view";

export const metadata: Metadata = {
  title: "Fixed asset register",
};

/**
 * Fixed-asset register route.
 *
 * Same screen as the index but reading `GET accounting/fixed-assets/register`,
 * which returns only the capitalised assets. Kept as its own static segment so
 * the distinction is a URL the reports hub can link to; static segments win over
 * the sibling `[id]` dynamic segment.
 */
export default function FixedAssetRegisterPage() {
  return <FixedAssetsView mode="register" />;
}
