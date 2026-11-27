"use client";

import { Fragment, useEffect, useState } from "react";
import { Badge, Panel, Table, formatDate, money } from "@/components/ui";
import { api } from "@/lib/api";

type Money3 = { before: string; after: string; difference: string } | null;
type Row = {
  employee_id: string; employee_name: string; presence: "both" | "new" | "gone"; changed: boolean; reasons: string[];
  gross: Money3; net: Money3; items: { label: string; category: string; before: string | null; after: string | null; difference: string | null }[];
};
type Data = { previous: { id: string; period_start: string; period_end: string; status: string } | null; employees: Row[]; amounts_restricted: boolean };

const signed = (value: string, currency: string) => `${Number(value) > 0 ? "+" : ""}${money(value, currency)}`;

/** How this run differs from the group's previous run, employee by employee, and why. */
export function RunComparisonPanel({ runId, version, currency }: { runId: string; version: string; currency: string }) {
  const [data, setData] = useState<Data | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [showAll, setShowAll] = useState(false);

  useEffect(() => {
    api<Data>(`/payroll/runs/${runId}/comparison`).then(setData).catch(() => setData(null));
  }, [runId, version]);

  if (!data || !data.previous) return null;
  const rows = showAll ? data.employees : data.employees.filter((row) => row.changed);

  return (
    <Panel
      title="Compared with last period"
      subtitle={`Previous run: ${formatDate(data.previous.period_start)} – ${formatDate(data.previous.period_end)}. ${data.employees.filter((row) => row.changed).length} of ${data.employees.length} changed.`}
      right={<label className="checkrow"><input type="checkbox" checked={showAll} onChange={(event) => setShowAll(event.target.checked)} /> Show unchanged</label>}
      bodyless
    >
      {rows.length === 0 ? <div className="empty">Nobody’s pay changed from last period.</div> : (
        <Table headers={["Employee", "Gross pay", "Net pay", "Why", ""]}>
          {rows.map((row) => (
            <Fragment key={row.employee_id}>
              <tr>
                <td>{row.employee_name} {row.presence === "new" && <Badge tone="blue" label="New this period" />}{row.presence === "gone" && <Badge tone="gray" label="Not in this run" />}</td>
                <td>{data.amounts_restricted || !row.gross ? "—" : <>{money(row.gross.after, currency)}<div className="tiny">{signed(row.gross.difference, currency)}</div></>}</td>
                <td>{data.amounts_restricted || !row.net ? "—" : <>{money(row.net.after, currency)}<div className="tiny">{signed(row.net.difference, currency)}</div></>}</td>
                <td>{row.reasons.length === 0 ? <span className="muted">No change</span> : row.reasons.map((reason) => <div key={reason} className="tiny">{reason}</div>)}</td>
                <td style={{ textAlign: "right" }}>{row.items.length > 0 && !data.amounts_restricted && <button className="btn" onClick={() => setOpen(open === row.employee_id ? null : row.employee_id)}>{open === row.employee_id ? "Hide" : "Items"}</button>}</td>
              </tr>
              {open === row.employee_id && (
                <tr>
                  <td colSpan={5} style={{ background: "var(--soft, #f7f9fc)" }}>
                    <Table headers={["Item", "Last period", "This period", "Change"]}>
                      {row.items.map((item) => (
                        <tr key={`${item.category}-${item.label}`}>
                          <td>{item.label}</td>
                          <td>{money(item.before ?? "0", currency)}</td>
                          <td>{money(item.after ?? "0", currency)}</td>
                          <td>{signed(item.difference ?? "0", currency)}</td>
                        </tr>
                      ))}
                    </Table>
                  </td>
                </tr>
              )}
            </Fragment>
          ))}
        </Table>
      )}
    </Panel>
  );
}
