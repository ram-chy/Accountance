import type { Metadata } from "next";

import { CreditDebitNoteForm } from "@/components/credit-debit-notes/credit-debit-note-form";

export const metadata: Metadata = {
  title: "New debit note",
};

export default function NewDebitNotePage() {
  return <CreditDebitNoteForm noteType="PURCHASE_DEBIT_NOTE" basePath="/debit-notes" />;
}
