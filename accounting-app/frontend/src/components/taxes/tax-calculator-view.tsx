"use client";

import { useMemo, useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { formatDecimal } from "@/lib/format/money";
import { ApiError } from "@/lib/api/errors";

import { calculateTax } from "@/lib/taxes/api";
import {
  TAX_CALCULATION_BASIS_LABELS,
  TAX_CALCULATION_BASIS_VALUES,
  TAX_TYPE_LABELS,
  type TaxCalculation,
  type TaxCalculationBasisValue,
} from "@/lib/taxes/types";
import { useTaxList } from "@/lib/taxes/use-taxes";
import { parseTaxCalculation, validateCalculation } from "@/lib/taxes/validators";

/**
 * Read-only tax calculator.
 *
 * Posts an amount, a date and a chosen set of taxes to
 * `POST /accounting/tax/calculate`, which persists nothing. The result — taxable
 * amount, tax, gross and one line per component — is rendered exactly as the
 * backend returned it; the frontend never derives or rounds a figure.
 *
 * An empty selection is a legitimate question ("does this figure already include
 * tax?") and returns the amount untaxed, so the button is always available. The
 * basis override is optional; left unset, the backend uses the first selected
 * tax's configured basis.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function TaxCalculatorView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canCalculate = can("accounting.tax.calculate");
  const canViewTaxes = can("accounting.tax.view");

  const [amount, setAmount] = useState("");
  const [date, setDate] = useState(today());
  const [basis, setBasis] = useState<"" | TaxCalculationBasisValue>("");
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [result, setResult] = useState<TaxCalculation | null>(null);
  const [pending, setPending] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  const taxes = useTaxList({
    companyId,
    enabled: canCalculate && canViewTaxes,
    filters: { active_only: true },
    reloadToken: 0,
  });

  const selectedCount = selectedIds.length;

  const sortedTaxes = useMemo(
    () => (taxes.status === "ready" ? taxes.items : []),
    [taxes],
  );

  const toggle = (id: number) => {
    setSelectedIds((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    );
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setError(null);

    const clientErrors = validateCalculation({ amount, date });

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(clientErrors);
      setError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      const raw = await calculateTax({
        amount: amount.trim(),
        date: date.trim(),
        tax_ids: selectedIds,
        ...(basis === "" ? {} : { basis }),
      });
      const parsed = parseTaxCalculation(raw);

      if (parsed === null) {
        setError("The calculation could not be read. Please try again.");
        setResult(null);
      } else {
        setResult(parsed);
      }
    } catch (caught) {
      if (caught instanceof ApiError && caught.isValidation && caught.errors) {
        setFieldErrors(
          Object.fromEntries(
            Object.entries(caught.errors).map(([key, value]) => [key, value[0] ?? ""]),
          ),
        );
        setError("Please correct the highlighted fields.");
      } else if (caught instanceof ApiError) {
        setError(caught.message);
      } else {
        setError("Something went wrong. Please try again.");
      }
      setResult(null);
    } finally {
      setPending(false);
    }
  };

  if (!activeCompany) {
    return <EmptyState title="No company selected" description="Choose a company to calculate tax." />;
  }

  if (!canCalculate) {
    return (
      <EmptyState
        title="Tax calculator unavailable"
        description="Your role does not include access to the tax calculator."
      />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Tax calculator</h1>
        <p className="text-sm text-muted-foreground">
          Work out the tax on an amount. Nothing here is saved — this is a what-if tool.
        </p>
      </div>

      <form
        onSubmit={submit}
        noValidate
        className="flex flex-col gap-5 rounded-lg border border-border bg-surface p-4 sm:p-6"
      >
        {error ? <ErrorState title="The calculation could not be completed" message={error} /> : null}

        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Amount" htmlFor="calc-amount" required error={fieldErrors.amount}>
            <Input
              id="calc-amount"
              name="amount"
              inputMode="decimal"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              invalid={Boolean(fieldErrors.amount)}
              aria-describedby={fieldErrors.amount ? "calc-amount-error" : undefined}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Date" htmlFor="calc-date" required error={fieldErrors.date}>
            <Input
              id="calc-date"
              name="date"
              type="date"
              value={date}
              onChange={(event) => setDate(event.target.value)}
              invalid={Boolean(fieldErrors.date)}
              aria-describedby={fieldErrors.date ? "calc-date-error" : undefined}
            />
          </FormField>

          <FormField
            label="Amount is"
            htmlFor="calc-basis"
            hint="Optional. Left unset, the first selected tax's configured basis is used."
          >
            <select
              id="calc-basis"
              name="basis"
              className={selectClasses}
              value={basis}
              onChange={(event) => setBasis(event.target.value as "" | TaxCalculationBasisValue)}
            >
              <option value="">Default from selected tax</option>
              {TAX_CALCULATION_BASIS_VALUES.map((value) => (
                <option key={value} value={value}>
                  {TAX_CALCULATION_BASIS_LABELS[value]}
                </option>
              ))}
            </select>
          </FormField>
        </div>

        <fieldset className="flex flex-col gap-2">
          <legend className="text-sm font-medium text-foreground">
            Taxes to apply <span className="text-muted-foreground">(optional)</span>
          </legend>

          {!canViewTaxes ? (
            <p className="text-sm text-muted-foreground">
              You need access to the tax list to choose taxes. You can still calculate an untaxed
              figure below.
            </p>
          ) : taxes.status === "loading" ? (
            <p className="text-sm text-muted-foreground">Loading taxes…</p>
          ) : taxes.status === "unavailable" || taxes.status === "error" ? (
            <ErrorState
              title="Could not load taxes"
              message={taxes.message ?? "The tax list is unavailable."}
            />
          ) : sortedTaxes.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No active taxes are configured. Add a tax with a rate to use the calculator.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {sortedTaxes.map((tax) => (
                <li key={tax.id}>
                  <label className="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 transition-colors hover:bg-surface-muted">
                    <input
                      type="checkbox"
                      className="mt-0.5 h-4 w-4"
                      checked={selectedIds.includes(tax.id)}
                      onChange={() => toggle(tax.id)}
                    />
                    <span className="flex flex-col gap-0.5">
                      <span className="text-sm font-medium text-foreground">
                        {tax.code} · {tax.name}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {TAX_TYPE_LABELS[tax.tax_type]} ·{" "}
                        {tax.current_rate === null
                          ? "No rate in force"
                          : `${formatDecimal(tax.current_rate, { fallback: "—" })}%`}
                      </span>
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          )}
        </fieldset>

        <div className="flex items-center justify-end">
          <Button type="submit" loading={pending}>
            Calculate
          </Button>
        </div>
      </form>

      {result !== null ? (
        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
          <div className="flex flex-col gap-1">
            <h2 className="text-base font-semibold text-foreground">Result</h2>
            <p className="text-sm text-muted-foreground">
              {selectedCount === 0
                ? "No taxes were selected, so the amount is returned untaxed."
                : `Calculated with ${selectedCount} tax${selectedCount === 1 ? "" : "es"} on ${date}.`}
            </p>
          </div>

          <dl className="grid gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-1">
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Taxable amount
              </dt>
              <dd className="text-lg font-semibold text-foreground">
                {formatDecimal(result.taxable_amount, { fallback: "—" })}
              </dd>
            </div>
            <div className="flex flex-col gap-1">
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Total tax
              </dt>
              <dd className="text-lg font-semibold text-foreground">
                {formatDecimal(result.total_tax, { fallback: "—" })}
              </dd>
            </div>
            <div className="flex flex-col gap-1">
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Gross amount
              </dt>
              <dd className="text-lg font-semibold text-foreground">
                {formatDecimal(result.gross_amount, { fallback: "—" })}
              </dd>
            </div>
          </dl>

          {result.tax_components.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-left">
                <caption className="sr-only">Tax components</caption>
                <thead>
                  <tr className="border-b border-border">
                    <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                      Tax
                    </th>
                    <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                      Rate
                    </th>
                    <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                      Taxable
                    </th>
                    <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                      Tax
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {result.tax_components.map((component) => (
                    <tr
                      key={`${component.tax_id}-${component.tax_rate_id}`}
                      className="border-b border-border last:border-0"
                    >
                      <td className="px-3 py-2 text-sm text-foreground">
                        {component.tax_code} · {component.tax_name}
                      </td>
                      <td className="px-3 py-2 text-sm text-muted-foreground">
                        {formatDecimal(component.rate, { fallback: "—" })}%
                      </td>
                      <td className="px-3 py-2 text-sm text-muted-foreground">
                        {formatDecimal(component.taxable_amount, { fallback: "—" })}
                      </td>
                      <td className="px-3 py-2 text-sm text-muted-foreground">
                        {formatDecimal(component.tax_amount, { fallback: "—" })}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </section>
      ) : null}
    </div>
  );
}
