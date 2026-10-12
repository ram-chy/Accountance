import type { ReactNode } from "react";

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

export type FinancialChartCardProps = {
  title: string;
  description?: string;
  /** Rendered under the chart, e.g. a caveat about the data source. */
  footnote?: string;
  children: ReactNode;
};

/**
 * Titled panel that frames a chart and its supporting text.
 *
 * Kept presentational so it can wrap loading, empty, error and ready bodies
 * interchangeably.
 */
export function FinancialChartCard({
  title,
  description,
  footnote,
  children,
}: FinancialChartCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {description ? <CardDescription>{description}</CardDescription> : null}
      </CardHeader>
      <CardContent className="pt-2">
        {children}
        {footnote ? (
          <p className="mt-4 text-xs text-muted-foreground">{footnote}</p>
        ) : null}
      </CardContent>
    </Card>
  );
}
