import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { BudgetDetailView } from "@/components/budgets/budget-detail-view";

export const metadata: Metadata = {
  title: "Budget",
};

type BudgetPageProps = {
  params: Promise<{ id: string }>;
};

/**
 * Budget detail route.
 *
 * The `(app)` layout validates the session cookie; the id is parsed here so a
 * non-numeric path segment renders the not-found boundary rather than issuing a
 * doomed request. A missing id resolves to 404 from the backend and is handled
 * by the view's own not-found state.
 */
export default async function BudgetPage({ params }: BudgetPageProps) {
  const { id } = await params;
  const budgetId = Number(id);

  if (!Number.isInteger(budgetId) || budgetId <= 0) {
    notFound();
  }

  return <BudgetDetailView budgetId={budgetId} />;
}
