import type { Metadata } from "next";

import { CreditDebitNotesView } from "@/components/credit-debit-notes/credit-debit-notes-view";

export const metadata: Metadata = {
  title: "Debit Notes",
};

export default function DebitNotesPage() {
  return <CreditDebitNotesView noteType="PURCHASE_DEBIT_NOTE" basePath="/debit-notes" />;
}
