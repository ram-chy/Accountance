import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { ReconciliationDetailView } from "@/components/bank-reconciliations/reconciliation-detail-view";

export const metadata: Metadata = {
  title: "Bank reconciliation",
};

type ReconciliationPageProps = {
  params: Promise<{ id: string }>;
};

/**
 * Bank-reconciliation detail route.
 *
 * The `(app)` layout validates the session cookie; the id is parsed here so a
 * non-numeric path segment renders the not-found boundary rather than issuing a
 * doomed request. Cross-company ids resolve to 404 from the backend and are
 * handled by the view's own not-found state.
 */
export default async function ReconciliationPage({ params }: ReconciliationPageProps) {
  const { id } = await params;
  const reconciliationId = Number(id);

  if (!Number.isInteger(reconciliationId) || reconciliationId <= 0) {
    notFound();
  }

  return <ReconciliationDetailView reconciliationId={reconciliationId} />;
}
