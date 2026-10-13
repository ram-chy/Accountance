import type { Metadata } from "next";

import { CreditDebitNoteForm } from "@/components/credit-debit-notes/credit-debit-note-form";

export const metadata: Metadata = {
  title: "New credit note",
};

export default function NewCreditNotePage() {
  return <CreditDebitNoteForm noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" />;
}
