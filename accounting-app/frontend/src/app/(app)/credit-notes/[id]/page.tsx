import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CreditDebitNoteDetailView } from "@/components/credit-debit-notes/credit-debit-note-detail-view";

export const metadata: Metadata = {
  title: "Credit note",
};

type CreditNotePageProps = {
  params: Promise<{ id: string }>;
};

export default async function CreditNotePage({ params }: CreditNotePageProps) {
  const { id } = await params;
  const noteId = Number(id);

  if (!Number.isInteger(noteId) || noteId <= 0) {
    notFound();
  }

  return <CreditDebitNoteDetailView noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" noteId={noteId} />;
}
