"use client";

import { useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { Badge } from "@/components/ui/badge";
import { ApiError } from "@/lib/api/errors";

/**
 * Active-company selector.
 *
 * The list is whatever the user is a member of. Switching calls the BFF, which
 * verifies membership/active-state with Laravel before persisting the choice, so
 * the UI never has to police it. A rejected switch surfaces a toast and leaves
 * the previous selection in place.
 */
export function CompanySwitcher() {
  const { companies, activeCompany, switchCompany } = useAuth();
  const [pending, setPending] = useState(false);

  if (companies.length === 0) {
    return null;
  }

  const handleChange = async (event: React.ChangeEvent<HTMLSelectElement>) => {
    const companyId = Number(event.target.value);

    if (!Number.isInteger(companyId) || companyId === activeCompany?.id) {
      return;
    }

    setPending(true);

    try {
      await switchCompany(companyId);
      toast.success("Company switched.");
    } catch (error) {
      const message =
        error instanceof ApiError && error.status === 403
          ? "You do not have access to that company."
          : error instanceof ApiError
            ? error.message
            : "Could not switch company. Please try again.";

      toast.error(message);
    } finally {
      setPending(false);
    }
  };

  return (
    <div className="flex items-center gap-2">
      <label htmlFor="active-company" className="sr-only">
        Active company
      </label>
      <select
        id="active-company"
        value={activeCompany?.id ?? ""}
        onChange={handleChange}
        disabled={pending || companies.length === 1}
        className="h-9 max-w-[16rem] rounded-md border border-border bg-surface px-2 text-sm text-foreground transition-colors hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-70"
      >
        {activeCompany === null ? (
          <option value="">No company</option>
        ) : null}
        {companies.map((company) => (
          <option key={company.id} value={company.id}>
            {company.name}
            {company.is_active ? "" : " (inactive)"}
          </option>
        ))}
      </select>
      {pending ? (
        <Badge variant="info">Switching…</Badge>
      ) : null}
    </div>
  );
}
