import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { DimensionDetailView } from "@/components/dimensions/dimension-detail-view";

export const metadata: Metadata = {
  title: "Dimension",
};

type DimensionPageProps = {
  params: Promise<{ id: string }>;
};

/**
 * Dimension detail route.
 *
 * The `(app)` layout validates the session cookie; the id is parsed here so a
 * non-numeric path segment renders the not-found boundary rather than issuing a
 * doomed request. A missing id resolves to 404 from the backend and is handled
 * by the view's own not-found state.
 */
export default async function DimensionPage({ params }: DimensionPageProps) {
  const { id } = await params;
  const dimensionId = Number(id);

  if (!Number.isInteger(dimensionId) || dimensionId <= 0) {
    notFound();
  }

  return <DimensionDetailView dimensionId={dimensionId} />;
}
