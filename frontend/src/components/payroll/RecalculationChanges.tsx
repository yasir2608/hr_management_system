"use client";

import { useEffect, useState } from "react";
import { Badge, Panel, Table, formatDateTime, money } from "@/components/ui";
import { api } from "@/lib/api";

type Pair = { before: string; after: string; difference: string } | null;
type Row = { employee_id: string; employee_name: string; change: string; gross: Pair; net: Pair; items: { label: string; before: string | null; after: string | null; difference: string | null }[] };
type Data = { recalculated_at: string | null; gathered_again: boolean; previous_totals: { gross_pay: string; net_pay: string } | null; amounts_restricted: boolean; employees: Row[]; unchanged: number };

const CHANGE: Record<string, { label: string; tone: string }> = {
  changed: { label: "Changed", tone: "amber" }, added: { label: "Added", tone: "blue" }, removed: { label: "No longer in the run", tone: "gray" },
  left_out: { label: "Now left out", tone: "red" }, now_included: { label: "Now included", tone: "green" },
};
const signed = (value: string, currency: string) => `${Number(value) > 0 ? "+" : ""}${money(value, currency)}`;

/** What the latest recalculation changed compared with the calculation before it. */
export function RecalculationChanges({ runId, version, currency }: { runId: string; version: string; currency: string }) {
  const [data, setData] = useState<Data | null>(null);

  useEffect(() => {
    api<Data>(`/payroll/runs/${runId}/changes`).then(setData).catch(() => setData(null));
  }, [runId, version]);

  if (!data?.recalculated_at) return null;

  return (
    <Panel
      title="What changed in the last recalculation"
      subtitle={`${formatDateTime(data.recalculated_at)}${data.gathered_again ? ", after gathering inputs again" : ""}. ${data.employees.length} changed, ${data.unchanged} unchanged.`}
      bodyless={data.employees.length > 0}
    >
      {data.employees.length === 0 ? <span className="muted">Nothing changed: the figures are the same as before.</span> : (
        <Table headers={["Employee", "What happened", "Gross pay", "Net pay", "Items"]}>
          {data.employees.map((row) => (
            <tr key={`${row.employee_id}-${row.change}`}>
              <td>{row.employee_name}</td>
              <td><Badge tone={CHANGE[row.change]?.tone ?? "gray"} label={CHANGE[row.change]?.label ?? row.change} /></td>
              <td>{data.amounts_restricted || !row.gross ? "—" : <>{money(row.gross.before, currency)} → {money(row.gross.after, currency)}<div className="tiny">{signed(row.gross.difference, currency)}</div></>}</td>
              <td>{data.amounts_restricted || !row.net ? "—" : <>{money(row.net.before, currency)} → {money(row.net.after, currency)}<div className="tiny">{signed(row.net.difference, currency)}</div></>}</td>
              <td className="tiny">{row.items.map((item) => <div key={item.label}>{item.label}{!data.amounts_restricted && item.difference !== null && `: ${signed(item.difference, currency)}`}</div>)}</td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}
