import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { TaxDetailView } from "@/components/taxes/tax-detail-view";

export const metadata: Metadata = {
  title: "Tax",
};

type TaxPageProps = {
  params: Promise<{ id: string }>;
};

/**
 * Tax detail route.
 *
 * The `(app)` layout validates the session cookie; the id is parsed here so a
 * non-numeric path segment renders the not-found boundary rather than issuing a
 * doomed request. Cross-company ids resolve to 404 from the backend and are
 * handled by the view's own not-found state.
 */
export default async function TaxPage({ params }: TaxPageProps) {
  const { id } = await params;
  const taxId = Number(id);

  if (!Number.isInteger(taxId) || taxId <= 0) {
    notFound();
  }

  return <TaxDetailView taxId={taxId} />;
}
