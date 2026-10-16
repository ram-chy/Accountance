import type { Metadata } from "next";

import { CompanyCreateForm } from "@/components/company/company-create-form";

export const metadata: Metadata = {
  title: "New company",
};

/**
 * Company creation route.
 *
 * Gated on `companies.create` (Admin only) inside `CompanyCreateForm`; the
 * backend re-authorises the call. On success the flow switches to the new
 * company and lands on its settings.
 */
export default function NewCompanyPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">New company</h1>
        <p className="text-sm text-muted-foreground">
          Register another company. You are attached as its first member.
        </p>
      </div>

      <CompanyCreateForm />
    </div>
  );
}
