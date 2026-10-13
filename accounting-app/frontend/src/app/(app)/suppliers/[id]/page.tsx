import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { SupplierDetailView } from "@/components/suppliers/supplier-detail-view";

export const metadata: Metadata = {
  title: "Supplier",
};

type SupplierPageProps = {
  params: Promise<{ id: string }>;
};

export default async function SupplierPage({ params }: SupplierPageProps) {
  const { id } = await params;
  const supplierId = Number(id);

  if (!Number.isInteger(supplierId) || supplierId <= 0) {
    notFound();
  }

  return <SupplierDetailView supplierId={supplierId} />;
}
