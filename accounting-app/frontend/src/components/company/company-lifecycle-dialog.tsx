"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { activateCompany, deactivateCompany } from "@/lib/company/api";
import type { Company } from "@/lib/company/types";

/**
 * Confirmation dialog for a company lifecycle change (deactivate / reactivate).
 *
 * This is a consequential administrative action — deactivating the company you
 * are working in removes it from the active context — so the target company is
 * named explicitly and the change is only reported as successful once the
 * server confirms it. `companies.delete` (Admin only) authorizes both acts; the
 * backend re-checks regardless of whether this dialog is ever shown.
 */
export function CompanyLifecycleDialog({
  open,
  company,
  action,
  onClose,
  onDone,
}: {
  open: boolean;
  company: Company;
  action: "deactivate" | "activate";
  onClose: () => void;
  onDone: () => void;
}) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isDeactivate = action === "deactivate";

  const run = async () => {
    if (submitting) return;

    setSubmitting(true);
    setError(null);

    try {
      if (isDeactivate) {
        await deactivateCompany(company.id);
        toast.success(`${company.name} was deactivated.`);
      } else {
        await activateCompany(company.id);
        toast.success(`${company.name} was reactivated.`);
      }
      onDone();
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : `We could not ${isDeactivate ? "deactivate" : "reactivate"} the company. Please try again.`,
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isDeactivate ? "Deactivate company" : "Reactivate company"}
      description={`This affects ${company.name}.`}
      onClose={submitting ? () => {} : onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button
            variant={isDeactivate ? "danger" : "primary"}
            onClick={run}
            loading={submitting}
          >
            {isDeactivate ? "Deactivate" : "Reactivate"}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm text-muted-foreground">
        {error ? <ErrorState title="Change failed" message={error} /> : null}
        {isDeactivate ? (
          <>
            <p>
              Deactivating <strong className="text-foreground">{company.name}</strong>{" "}
              takes it out of use. Its records are kept — nothing is deleted — and it
              can be reactivated later.
            </p>
            <p>
              If this is the company you are currently working in, you will be
              returned to your next active company.
            </p>
          </>
        ) : (
          <p>
            Reactivating <strong className="text-foreground">{company.name}</strong>{" "}
            makes it available to work in again.
          </p>
        )}
      </div>
    </Dialog>
  );
}
