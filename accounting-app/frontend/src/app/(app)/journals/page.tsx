import type { Metadata } from "next";

import { JournalsView } from "@/components/journals/journals-view";

export const metadata: Metadata = {
  title: "Journals",
};

/**
 * Journal entry route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Journals are loaded
 * client-side through the Phase 3 proxy via `JournalsView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function JournalsPage() {
  return <JournalsView />;
}
