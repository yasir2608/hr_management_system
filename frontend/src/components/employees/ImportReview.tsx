"use client";

import { useState } from "react";
import { Banner, humanize, Panel, Table } from "@/components/ui";
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

/** After upload: shows what was read from the file. Checking and fixing come in the next steps. */
export function ImportReview({ initial, warnings, onRestart }: { initial: ImportState; warnings: string[]; onRestart: () => void }) {
  const [state] = useState(initial);
  const keys = [...new Set(state.rows.flatMap((row) => Object.keys(row.values)))];

  async function cancel() {
    await api(`/employee-imports/${state.id}/cancel`, { method: "POST" });
    onRestart();
  }

  return (
    <div className="stack">
      {warnings.map((warning) => <Banner key={warning} tone="info">{warning}</Banner>)}
      <Panel
        title={`${state.original_name} · ${state.row_count} rows`}
        subtitle="This is what was read from your file."
        right={<button className="btn" onClick={() => void cancel()}>Cancel import</button>}
        bodyless
      >
        <Table headers={["Row", ...keys.map(humanize)]}>
          {state.rows.slice(0, 100).map((row) => (
            <tr key={row.row}>
              <td className="muted">{row.row}</td>
              {keys.map((key) => <td key={key}>{row.values[key] ?? <span className="muted">—</span>}</td>)}
            </tr>
          ))}
        </Table>
      </Panel>
    </div>
  );
}
