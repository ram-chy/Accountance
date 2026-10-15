"use client";

import Link from "next/link";

import { usePermissions } from "@/components/auth/use-permissions";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";

/**
 * Fixed-asset reporting index.
 *
 * The fixed-asset module exposes one server-computed report (depreciation by
 * period) plus two register-style views. Rather than fabricate a report the
 * backend cannot produce, this page is an honest index that links to the
 * implemented surfaces and says exactly what each one shows.
 */

type ReportLink = {
  href: string;
  title: string;
  description: string;
};

const LINKS: ReportLink[] = [
  {
    href: "/fixed-assets/depreciation",
    title: "Depreciation report",
    description:
      "Depreciation posted within a date range, grouped by asset, with a server-computed total. Filterable by category.",
  },
  {
    href: "/fixed-assets/register",
    title: "Fixed asset register",
    description:
      "Capitalised assets only, with their cost, accumulated depreciation, and net book value as computed by the server.",
  },
  {
    href: "/fixed-assets/disposals",
    title: "Disposals",
    description:
      "Disposed assets with their book values. Open an asset to see its disposal details and gain or loss.",
  },
];

export function FixedAssetReportsHubView() {
  const { can } = usePermissions();
  const canView = can("accounting.fixed_asset.view");

  if (!canView) {
    return (
      <EmptyState
        title="Reports unavailable"
        description="Your role does not include access to fixed assets."
      />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold text-foreground">Fixed asset reports</h1>
        <p className="text-sm text-muted-foreground">
          The fixed-asset reporting surfaces supported by the API.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {LINKS.map((link) => (
          <Card key={link.href}>
            <CardHeader>
              <CardTitle>
                <Link href={link.href} className="text-primary hover:underline">
                  {link.title}
                </Link>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <CardDescription>{link.description}</CardDescription>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
