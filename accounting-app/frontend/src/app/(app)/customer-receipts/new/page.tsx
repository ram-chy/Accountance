import type { Metadata } from "next";

import { CustomerReceiptForm } from "@/components/customer-receipts/customer-receipt-form";

export const metadata: Metadata = {
  title: "New customer receipt",
};

export default function NewCustomerReceiptPage() {
  return <CustomerReceiptForm />;
}
