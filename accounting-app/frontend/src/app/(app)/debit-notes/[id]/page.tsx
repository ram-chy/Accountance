import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CreditDebitNoteDetailView } from "@/components/credit-debit-notes/credit-debit-note-detail-view";

export const metadata: Metadata = {
  title: "Debit note",
};

type DebitNotePageProps = {
  params: Promise<{ id: string }>;
};

export default async function DebitNotePage({ params }: DebitNotePageProps) {
  const { id } = await params;
  const noteId = Number(id);

  if (!Number.isInteger(noteId) || noteId <= 0) {
    notFound();
  }

  return <CreditDebitNoteDetailView noteType="PURCHASE_DEBIT_NOTE" basePath="/debit-notes" noteId={noteId} />;
}
