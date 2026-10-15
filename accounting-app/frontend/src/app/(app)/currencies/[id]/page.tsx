import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CurrencyDetailView } from "@/components/currencies/currency-detail-view";

export const metadata: Metadata = {
  title: "Currency",
};

type CurrencyPageProps = {
  params: Promise<{ id: string }>;
};

/**
 * Currency detail route.
 *
 * The `(app)` layout validates the session cookie; the id is parsed here so a
 * non-numeric path segment renders the not-found boundary rather than issuing a
 * doomed request. A missing id resolves to 404 from the backend and is handled
 * by the view's own not-found state.
 */
export default async function CurrencyPage({ params }: CurrencyPageProps) {
  const { id } = await params;
  const currencyId = Number(id);

  if (!Number.isInteger(currencyId) || currencyId <= 0) {
    notFound();
  }

  return <CurrencyDetailView currencyId={currencyId} />;
}
