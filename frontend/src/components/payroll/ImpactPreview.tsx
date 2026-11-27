"use client";

import { useEffect, useState } from "react";
import { Badge, Table, formatDate, money } from "@/components/ui";
import { api } from "@/lib/api";

type Period = {
  start: string; end: string; pay_date: string; effect: "unchanged" | "part_period" | "changed"; days_at_new_rate: number | null;
  before: string; after: string; difference: string; inputs_closed: boolean; is_past: boolean;
};
type Result = { available: boolean; reason?: string; currency?: string; periods?: Period[]; label: string; note: string };

/**
 * Which upcoming pay periods an effective-dated change touches, shown before it is saved. Renders nothing for people
 * who may not see pay, or until the change is filled in.
 */
export function ImpactPreview({ employeeId, body, ready }: { employeeId: string; body: Record<string, unknown>; ready: boolean }) {
  const [result, setResult] = useState<Result | null>(null);
  const key = JSON.stringify(body);

  useEffect(() => {
    if (!ready) return;
    let cancelled = false;
    const timer = setTimeout(() => {
      api<Result>(`/payroll/employees/${employeeId}/impact-preview`, { body: JSON.parse(key) })
        .then((value) => { if (!cancelled) setResult(value); })
        .catch(() => { if (!cancelled) setResult(null); });
    }, 350);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [employeeId, key, ready]);

  if (!ready || !result) return null;
  if (!result.available) return <div className="callout">{result.reason}</div>;

  return (
    <div className="stack" style={{ marginTop: 12 }}>
      <div className="sectiontitle" style={{ marginTop: 0 }}>Effect on upcoming pay periods</div>
      <Table headers={["Period", result.label, "Change", ""]}>
        {result.periods!.map((period) => (
          <tr key={period.start}>
            <td>{formatDate(period.start)} – {formatDate(period.end)}<div className="tiny">Paid {formatDate(period.pay_date)}</div></td>
            <td>{period.effect === "unchanged" ? money(period.before, result.currency) : <><s className="muted">{money(period.before, result.currency)}</s> {money(period.after, result.currency)}</>}</td>
            <td>{period.effect === "unchanged" ? "—" : `${Number(period.difference) > 0 ? "+" : ""}${money(period.difference, result.currency)}`}</td>
            <td>
              {period.effect === "part_period" && <Badge tone="blue" label={`Part period · ${period.days_at_new_rate} days at new amount`} />}
              {period.effect === "changed" && <Badge tone="green" label="Whole period" />}
              {period.effect !== "unchanged" && period.inputs_closed && <div><Badge tone="amber" label={period.is_past ? "Period already ended" : "Inputs closed"} /></div>}
            </td>
          </tr>
        ))}
      </Table>
      <div className="tiny">{result.note}</div>
    </div>
  );
}
