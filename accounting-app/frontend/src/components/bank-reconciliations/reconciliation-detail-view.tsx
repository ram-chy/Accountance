"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError } from "@/lib/api/errors";
import {
  addBankReconciliationItem,
  removeBankReconciliationItem,
} from "@/lib/bank-reconciliations/api";
import {
  isEditableBankReconciliationStatus,
  type BankReconciliationMovement,
} from "@/lib/bank-reconciliations/types";
import { useBankReconciliation } from "@/lib/bank-reconciliations/use-bank-reconciliation";
import { useBankReconciliationMovements } from "@/lib/bank-reconciliations/use-bank-reconciliation-movements";
import {
  parseBankReconciliationItemId,
  validateAddReconciliationItem,
} from "@/lib/bank-reconciliations/validators";

import { ReconciliationCompleteDialog } from "./reconciliation-complete-dialog";
import { ReconciliationDeleteDialog } from "./reconciliation-delete-dialog";
import { ReconciliationMovementsTable } from "./reconciliation-movements-table";
import { ReconciliationReopenDialog } from "./reconciliation-reopen-dialog";
import { ReconciliationStatusBadge } from "./bank-reconciliations-table";
import { ReconciliationSummary } from "./reconciliation-summary";

/**
 * Bank-reconciliation detail screen.
 *
 * Reads one reconciliation (`useBankReconciliation`) and its eligible movements
 * (`useBankReconciliationMovements`), renders the authoritative figures, and
 * hosts the lifecycle actions: clearing a movement, removing a movement cleared
 * this session, completing, reopening and deleting a draft.
 *
 * The frontend never settles anything itself. Clearing, completing, reopening
 * and deleting all go through the backend, which re-authorises and re-validates
 * every action; the screen simply surfaces the backend's verdict. Item removal
 * is offered only for items whose id came from an add response, because no read
 * endpoint exposes the item id (see the Phase 9 report).
 */

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function ReconciliationDetailView({ reconciliationId }: { reconciliationId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const router = useRouter();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.bank_reconciliation.view");
  const canUpdate = can("accounting.bank_reconciliation.update");
  const canComplete = can("accounting.bank_reconciliation.complete");
  const canReopen = can("accounting.bank_reconciliation.reopen");
  const canViewAccounts = can("accounts.view");

  const [reloadToken, setReloadToken] = useState(0);
  const [knownItemIds, setKnownItemIds] = useState<Record<number, number>>({});
  const [pendingLineId, setPendingLineId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [completing, setCompleting] = useState(false);
  const [reopening, setReopening] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const detail = useBankReconciliation(reconciliationId, canView, reloadToken);
  const movements = useBankReconciliationMovements(reconciliationId, canView, reloadToken);
  const accountsState = useAccountOptions(companyId, canViewAccounts, reloadToken);

  const accountLabel = useMemo(() => {
    if (
      detail.status !== "ready" ||
      detail.data.account_id === null ||
      accountsState.status !== "ready"
    ) {
      return null;
    }

    const account = accountsState.items.find((item) => item.id === detail.data.account_id);
    return account ? `${account.code} — ${account.name}` : null;
  }, [detail, accountsState]);

  const reload = () => setReloadToken((token) => token + 1);

  const reconciliation = detail.status === "ready" ? detail.data : null;
  const editable = reconciliation !== null && isEditableBankReconciliationStatus(reconciliation.status);

  const clearMovement = async (movement: BankReconciliationMovement) => {
    const errors = validateAddReconciliationItem({ journal_line_id: movement.journal_line_id });

    if (errors.journal_line_id) {
      setActionError(errors.journal_line_id);
      return;
    }

    setPendingLineId(movement.journal_line_id);
    setActionError(null);

    try {
      const raw = await addBankReconciliationItem(reconciliationId, {
        journal_line_id: movement.journal_line_id,
      });
      const itemId = parseBankReconciliationItemId(raw);

      if (itemId !== null) {
        setKnownItemIds((current) => ({ ...current, [movement.journal_line_id]: itemId }));
      }

      toast.success("Movement cleared.");
      reload();
    } catch (error) {
      setActionError(explainError(error));
    } finally {
      setPendingLineId(null);
    }
  };

  const removeMovement = async (movement: BankReconciliationMovement) => {
    const itemId = knownItemIds[movement.journal_line_id];

    if (itemId === undefined) {
      return;
    }

    setPendingLineId(movement.journal_line_id);
    setActionError(null);

    try {
      await removeBankReconciliationItem(reconciliationId, itemId);
      setKnownItemIds((current) => {
        const next = { ...current };
        delete next[movement.journal_line_id];
        return next;
      });
      toast.success("Movement removed.");
      reload();
    } catch (error) {
      setActionError(explainError(error));
    } finally {
      setPendingLineId(null);
    }
  };

  const header = (() => {
    if (reconciliation === null) {
      return (
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold text-foreground">Bank reconciliation</h1>
        </div>
      );
    }

    return (
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold text-foreground">Bank reconciliation</h1>
            <ReconciliationStatusBadge status={reconciliation.status} />
          </div>
          <p className="text-sm text-muted-foreground">
            {accountLabel ?? (reconciliation.account_id !== null ? `Account #${reconciliation.account_id}` : "Unknown account")}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {!reconciliation.is_reconciled && canComplete ? (
            <Button onClick={() => setCompleting(true)}>Complete</Button>
          ) : null}
          {reconciliation.status === "RECONCILED" && canReopen ? (
            <Button variant="secondary" onClick={() => setReopening(true)}>
              Reopen
            </Button>
          ) : null}
          {reconciliation.status === "DRAFT" && canUpdate ? (
            <Button
              variant="ghost"
              className="text-danger hover:bg-danger-soft hover:text-danger"
              onClick={() => setDeleting(true)}
            >
              Delete
            </Button>
          ) : null}
        </div>
      </div>
    );
  })();

  const movementsBody = (() => {
    if (!editable && movements.status === "ready" && movements.items.length === 0) {
      return (
        <EmptyState
          title="No eligible movements"
          description="This reconciliation has no ledger movements in its period."
        />
      );
    }

    if (movements.status === "loading" || movements.status === "idle") {
      return <LoadingState label="Loading movements…" />;
    }

    if (movements.status === "not-found") {
      return (
        <EmptyState
          title="Movements unavailable"
          description="This reconciliation no longer exists."
        />
      );
    }

    if (movements.status === "error") {
      return (
        <ErrorState
          title="Could not load movements"
          message={movements.message}
          requestId={movements.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    if (movements.items.length === 0) {
      return (
        <EmptyState
          title="No eligible movements"
          description="No ledger movements fall inside this reconciliation's period."
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <ReconciliationMovementsTable
          items={movements.items}
          editable={editable}
          canUpdateItem={canUpdate}
          knownItemIds={knownItemIds}
          pendingLineId={pendingLineId}
          onClear={(movement) => void clearMovement(movement)}
          onRemove={(movement) => void removeMovement(movement)}
        />
      </div>
    );
  })();

  const hasUntrackedCleared =
    movements.status === "ready" &&
    movements.items.some(
      (movement) => movement.cleared && knownItemIds[movement.journal_line_id] === undefined,
    );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view this reconciliation."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Bank reconciliations unavailable"
          description="Your role does not include access to bank reconciliations."
        />
      );
    }

    if (detail.status === "loading" || detail.status === "idle") {
      return <LoadingState label="Loading reconciliation…" />;
    }

    if (detail.status === "not-found") {
      return (
        <EmptyState
          title="Reconciliation not found"
          description="This reconciliation does not exist, or belongs to another company."
          action={
            <Link href="/bank-reconciliations" className="text-sm font-medium text-primary">
              Back to reconciliations
            </Link>
          }
        />
      );
    }

    if (detail.status === "error") {
      return (
        <ErrorState
          title="Could not load this reconciliation"
          message={detail.message}
          requestId={detail.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    return (
      <div className="flex flex-col gap-6">
        <ReconciliationSummary reconciliation={detail.data} />

        <section className="flex flex-col gap-3">
          <div className="flex flex-col gap-1">
            <h2 className="text-base font-semibold text-foreground">Movements</h2>
            <p className="text-sm text-muted-foreground">
              Ledger movements within the reconciliation period. Clear a movement to add it to
              this reconciliation.
            </p>
          </div>

          {!editable ? (
            <p className="text-sm text-muted-foreground">
              This reconciliation is completed. Reopen it to change its movements.
            </p>
          ) : null}

          {actionError ? (
            <ErrorState title="The movement could not be updated" message={actionError} />
          ) : null}

          {hasUntrackedCleared ? (
            <p className="text-xs text-muted-foreground">
              Cleared movements shown without a Remove action were cleared in an earlier session.
              The server does not expose their item identifier, so they can only be removed from
              the API.
            </p>
          ) : null}

          {movementsBody}
        </section>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <Link
        href="/bank-reconciliations"
        className="text-sm font-medium text-muted-foreground hover:text-foreground"
      >
        ← All reconciliations
      </Link>

      {header}
      {body}

      <ReconciliationCompleteDialog
        reconciliation={completing ? reconciliation : null}
        onClose={() => setCompleting(false)}
        onDone={reload}
      />
      <ReconciliationReopenDialog
        reconciliation={reopening ? reconciliation : null}
        onClose={() => setReopening(false)}
        onDone={reload}
      />
      <ReconciliationDeleteDialog
        reconciliation={deleting ? reconciliation : null}
        onClose={() => setDeleting(false)}
        onDeleted={() => {
          router.push("/bank-reconciliations");
        }}
      />
    </div>
  );
}
