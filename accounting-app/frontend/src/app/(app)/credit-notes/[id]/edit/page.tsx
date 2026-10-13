import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CreditDebitNoteForm } from "@/components/credit-debit-notes/credit-debit-note-form";

export const metadata: Metadata = {
  title: "Edit credit note",
};

type EditCreditNotePageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditCreditNotePage({ params }: EditCreditNotePageProps) {
  const { id } = await params;
  const noteId = Number(id);

  if (!Number.isInteger(noteId) || noteId <= 0) {
    notFound();
  }

  return <CreditDebitNoteForm noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" noteId={noteId} />;
}
