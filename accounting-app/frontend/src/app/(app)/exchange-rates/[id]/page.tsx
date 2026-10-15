import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { ExchangeRateDetailView } from "@/components/exchange-rates/exchange-rate-detail-view";

export const metadata: Metadata = {
  title: "Exchange rate",
};

type ExchangeRatePageProps = {
  params: Promise<{ id: string }>;
};

/**
 * Exchange rate detail route.
 *
 * The `(app)` layout validates the session cookie; the id is parsed here so a
 * non-numeric path segment renders the not-found boundary rather than issuing a
 * doomed request. A missing id resolves to 404 from the backend and is handled
 * by the view's own not-found state.
 */
export default async function ExchangeRatePage({ params }: ExchangeRatePageProps) {
  const { id } = await params;
  const rateId = Number(id);

  if (!Number.isInteger(rateId) || rateId <= 0) {
    notFound();
  }

  return <ExchangeRateDetailView rateId={rateId} />;
}
