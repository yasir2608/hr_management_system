"use client";

import Link from "next/link";
import { useState } from "react";
import { Badge, Banner, humanize, Panel, Stat, Table } from "@/components/ui";
import { api, download } from "@/lib/api";

export type ImportRow = { row: number; values: Record<string, string | null>; errors: Record<string, string>; excluded?: boolean };
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
type ImportResult = {
  counts: { created: number; skipped: number; excluded: number; failed: number };
  results: { row: number; name: string; outcome: string; employee_id?: string; employee_number?: string; problems?: number }[];
  message: string;
};

export function ImportReview({ initial, warnings, onRestart }: { initial: ImportState; warnings: string[]; onRestart: () => void }) {
  const [state, setState] = useState(initial);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [editing, setEditing] = useState<{ row: number; values: Record<string, string> } | null>(null);
  const [filter, setFilter] = useState<"all" | "attention" | "ready">(initial.error_count > 0 ? "attention" : "all");
  const keys = [...new Set(state.rows.flatMap((row) => Object.keys(row.values)))];
  const shown = state.rows.filter((row) => filter === "all" || (filter === "attention" ? Object.keys(row.errors).length > 0 : Object.keys(row.errors).length === 0));

  async function cancel() {
    await api(`/employee-imports/${state.id}/cancel`, { method: "POST" });
    onRestart();
  }

  async function saveRow() {
    if (!editing) return;
    setState(await api<ImportState>(`/employee-imports/${state.id}/rows/${editing.row}`, { method: "PATCH", body: { values: editing.values } }));
    setEditing(null);
  }

  async function toggle(row: number) {
    setState(await api<ImportState>(`/employee-imports/${state.id}/rows/${row}/toggle`, { method: "POST" }));
  }

  async function confirm() {
    setConfirming(true);
    setResult(await api<ImportResult>(`/employee-imports/${state.id}/confirm`, { method: "POST" }));
  }

  async function recheck() {
    setState(await api<ImportState>(`/employee-imports/${state.id}/validate`, { method: "POST" }));
  }

  if (result) {
    return (
      <div className="stack">
        <Stepper current={4} />
        <Banner tone={result.counts.failed > 0 ? "error" : "success"}>{result.message}</Banner>
        <div className="grid stats">
          <Stat label="Employees added" value={result.counts.created} good />
          <Stat label="Not saved: problems" value={result.counts.skipped} />
          <Stat label="Left out" value={result.counts.excluded} />
          <Stat label="Could not save" value={result.counts.failed} />
        </div>
        <Panel
          title="Results"
          subtitle="Rows with problems were not saved. Fix them in your file and import them again."
          right={<div className="row"><Link className="btn primary" href="/employees">Go to employees</Link><button className="btn" onClick={onRestart}>Import another file</button></div>}
          bodyless
        >
          <Table headers={["Row", "Name", "Outcome", ""]}>
            {result.results.map((row) => (
              <tr key={row.row}>
                <td className="muted">{row.row}</td>
                <td>{row.name || "—"}</td>
                <td>
                  {row.outcome === "created" && <Badge tone="green" label={`Added as ${row.employee_number}`} />}
                  {row.outcome === "skipped" && <Badge tone="amber" label={`Not saved (${row.problems} problem${row.problems === 1 ? "" : "s"})`} />}
                  {row.outcome === "excluded" && <Badge tone="gray" label="Left out" />}
                  {row.outcome === "failed" && <Badge tone="red" label="Could not save" />}
                </td>
                <td>{row.employee_id && <Link className="link" href={`/employees/${row.employee_id}`}>Open</Link>}</td>
              </tr>
            ))}
          </Table>
        </Panel>
      </div>
    );
  }

  return (
    <div className="stack">
      <Stepper current={state.error_count === 0 ? 3 : 2} />
      {warnings.map((warning) => <Banner key={warning} tone="info">{warning}</Banner>)}
      <div className="callout blue" style={{ justifyContent: "space-between", alignItems: "center" }}>
        <span>
          {state.ready_count} row(s) ready. {state.error_count > 0 ? `${state.error_count} row(s) with problems will not be saved unless you fix them.` : "No problems left."} Each employee is saved completely or not at all.
        </span>
        <button className="btn primary" disabled={confirming || state.ready_count === 0} onClick={() => void confirm()}>
          {confirming ? "Saving…" : `Import ${state.ready_count} employee(s)`}
        </button>
      </div>
      <div className="grid stats">
        <Stat label="Rows in file" value={state.row_count} />
        <Stat label="Ready to import" value={state.ready_count} good note="No problems found" />
        <Stat label="Need attention" value={state.error_count} note={state.error_count > 0 ? "Fix or leave out" : "None"} />
        <Stat label="File" value={<span style={{ fontSize: 14 }}>{state.original_name}</span>} />
      </div>
      <Panel
        title="Checked rows"
        subtitle="Fix problems here, or leave a row out. Only ready rows are saved."
        right={
          <div className="row">
            <select style={{ width: 170 }} value={filter} onChange={(event) => setFilter(event.target.value as typeof filter)} aria-label="Show rows">
              <option value="all">All rows</option>
              <option value="attention">Need attention ({state.error_count})</option>
              <option value="ready">Ready ({state.ready_count})</option>
            </select>
            {state.error_count > 0 && <button className="btn" onClick={() => void download(`/employee-imports/${state.id}/errors`)}>Download problems</button>}
            <button className="btn" onClick={() => void recheck()}>Check again</button>
            <button className="btn" onClick={() => void cancel()}>Cancel import</button>
          </div>
        }
        bodyless
      >
        <Table headers={["Row", "Status", ...keys.map(humanize), ""]}>
          {shown.slice(0, 200).map((row) => {
            const problems = row.excluded ? 0 : Object.keys(row.errors).length;
            const isEditing = editing?.row === row.row;
            return (
              <tr key={row.row} style={row.excluded ? { opacity: 0.45 } : undefined}>
                <td className="muted">{row.row}</td>
                <td>{row.excluded ? <Badge tone="gray" label="Left out" /> : problems === 0 ? <Badge tone="green" label="Ready" /> : <Badge tone="amber" label={`${problems} to fix`} />}</td>
                {keys.map((key) => (
                  <td key={key} title={row.errors[key]} style={row.errors[key] && !row.excluded ? { background: "var(--redbg)", color: "var(--red)" } : undefined}>
                    {isEditing && editing ? (
                      <input className="input" style={{ minWidth: 120 }} value={editing.values[key] ?? ""} onChange={(event) => setEditing({ ...editing, values: { ...editing.values, [key]: event.target.value } })} />
                    ) : (
                      row.values[key] ?? <span className="muted">—</span>
                    )}
                    {row.errors[key] && !row.excluded && <div className="tiny" style={{ color: "var(--red)", whiteSpace: "normal", maxWidth: 220 }}>{row.errors[key]}</div>}
                  </td>
                ))}
                <td>
                  <div className="row" style={{ justifyContent: "flex-end" }}>
                    {isEditing ? (
                      <>
                        <button className="btn primary" onClick={() => void saveRow()}>Save</button>
                        <button className="btn" onClick={() => setEditing(null)}>Cancel</button>
                      </>
                    ) : (
                      <>
                        {!row.excluded && <button className="btn" onClick={() => setEditing({ row: row.row, values: Object.fromEntries(keys.map((key) => [key, row.values[key] ?? ""])) })}>Fix</button>}
                        <button className="btn" onClick={() => void toggle(row.row)}>{row.excluded ? "Include" : "Leave out"}</button>
                      </>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </Table>
      </Panel>
    </div>
  );
}
