import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CustomerDetailView } from "@/components/customers/customer-detail-view";

export const metadata: Metadata = {
  title: "Customer",
};

type CustomerPageProps = {
  params: Promise<{ id: string }>;
};

export default async function CustomerPage({ params }: CustomerPageProps) {
  const { id } = await params;
  const customerId = Number(id);

  if (!Number.isInteger(customerId) || customerId <= 0) {
    notFound();
  }

  return <CustomerDetailView customerId={customerId} />;
}
