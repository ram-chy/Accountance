import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CreditDebitNoteForm } from "@/components/credit-debit-notes/credit-debit-note-form";

export const metadata: Metadata = {
  title: "Edit debit note",
};

type EditDebitNotePageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditDebitNotePage({ params }: EditDebitNotePageProps) {
  const { id } = await params;
  const noteId = Number(id);

  if (!Number.isInteger(noteId) || noteId <= 0) {
    notFound();
  }

  return <CreditDebitNoteForm noteType="PURCHASE_DEBIT_NOTE" basePath="/debit-notes" noteId={noteId} />;
}
