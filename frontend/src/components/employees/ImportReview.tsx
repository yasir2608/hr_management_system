"use client";

import { useState } from "react";
import { Badge, Banner, humanize, Panel, Stat, Table } from "@/components/ui";
import { api } from "@/lib/api";

export type ImportRow = { row: number; values: Record<string, string | null>; errors: Record<string, string> };
export type ImportState = {
  id: string;
  original_name: string;
  status: string;
  columns: Record<string, string | null>;
  row_count: number;
  ready_count: number;
  error_count: number;
  created_count: number;
  rows: ImportRow[];
};

const steps = ["Upload", "Check", "Review & fix", "Confirm"];

export function Stepper({ current }: { current: number }) {
  return (
    <div className="stepper">
      {steps.map((label, index) => (
        <div key={label} className={`step${index < current ? " done" : index === current ? " current" : ""}`}>
          <span className="num">{index < current ? "✓" : index + 1}</span>
          {label}
        </div>
      ))}
    </div>
  );
}

/** Shows the checked rows: ready ones, and the ones that need attention with the reason per cell. */
export function ImportReview({ initial, warnings, onRestart }: { initial: ImportState; warnings: string[]; onRestart: () => void }) {
  const [state, setState] = useState(initial);
  const [filter, setFilter] = useState<"all" | "attention" | "ready">(initial.error_count > 0 ? "attention" : "all");
  const keys = [...new Set(state.rows.flatMap((row) => Object.keys(row.values)))];
  const shown = state.rows.filter((row) => filter === "all" || (filter === "attention" ? Object.keys(row.errors).length > 0 : Object.keys(row.errors).length === 0));

  async function cancel() {
    await api(`/employee-imports/${state.id}/cancel`, { method: "POST" });
    onRestart();
  }

  async function recheck() {
    setState(await api<ImportState>(`/employee-imports/${state.id}/validate`, { method: "POST" }));
  }

  return (
    <div className="stack">
      <Stepper current={2} />
      {warnings.map((warning) => <Banner key={warning} tone="info">{warning}</Banner>)}
      <div className="grid stats">
        <Stat label="Rows in file" value={state.row_count} />
        <Stat label="Ready to import" value={state.ready_count} good note="No problems found" />
        <Stat label="Need attention" value={state.error_count} note={state.error_count > 0 ? "Fix or leave out" : "None"} />
        <Stat label="File" value={<span style={{ fontSize: 14 }}>{state.original_name}</span>} />
      </div>
      <Panel
        title="Checked rows"
        subtitle="Hover a red cell to see what is wrong."
        right={
          <div className="row">
            <select style={{ width: 170 }} value={filter} onChange={(event) => setFilter(event.target.value as typeof filter)} aria-label="Show rows">
              <option value="all">All rows</option>
              <option value="attention">Need attention ({state.error_count})</option>
              <option value="ready">Ready ({state.ready_count})</option>
            </select>
            <button className="btn" onClick={() => void recheck()}>Check again</button>
            <button className="btn" onClick={() => void cancel()}>Cancel import</button>
          </div>
        }
        bodyless
      >
        <Table headers={["Row", "Status", ...keys.map(humanize)]}>
          {shown.slice(0, 200).map((row) => {
            const problems = Object.keys(row.errors).length;
            return (
              <tr key={row.row}>
                <td className="muted">{row.row}</td>
                <td>{problems === 0 ? <Badge tone="green" label="Ready" /> : <Badge tone="amber" label={`${problems} to fix`} />}</td>
                {keys.map((key) => (
                  <td key={key} title={row.errors[key]} style={row.errors[key] ? { background: "var(--redbg)", color: "var(--red)" } : undefined}>
                    {row.values[key] ?? <span className="muted">—</span>}
                    {row.errors[key] && <div className="tiny" style={{ color: "var(--red)", whiteSpace: "normal", maxWidth: 220 }}>{row.errors[key]}</div>}
                  </td>
                ))}
              </tr>
            );
          })}
        </Table>
      </Panel>
    </div>
  );
}
