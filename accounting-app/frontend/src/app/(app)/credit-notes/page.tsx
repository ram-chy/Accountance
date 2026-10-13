import type { Metadata } from "next";

import { CreditDebitNotesView } from "@/components/credit-debit-notes/credit-debit-notes-view";

export const metadata: Metadata = {
  title: "Credit Notes",
};

export default function CreditNotesPage() {
  return <CreditDebitNotesView noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" />;
}
