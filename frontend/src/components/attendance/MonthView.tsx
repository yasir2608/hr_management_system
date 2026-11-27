"use client";

import { useEffect, useState } from "react";
import { Badge, EmptyState, formatDate, Panel, Stat, Table } from "@/components/ui";
import { api } from "@/lib/api";
import { hours, timeOf, type MonthSummary } from "./attendance";

/** One person's attendance for a month, by local work date. */
export function MonthView({ path, title, extraColumn }: { path: string; title: string; extraColumn?: (shift: MonthSummary["days"][number]["shifts"][number]) => React.ReactNode }) {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [summary, setSummary] = useState<MonthSummary | null>(null);

  useEffect(() => {
    api<MonthSummary>(path, { query: { month } }).then(setSummary).catch(() => setSummary(null));
  }, [path, month]);

  return (
    <div className="stack">
      <div className="grid stats">
        <Stat label="Days worked" value={summary?.days_worked ?? "—"} />
        <Stat label="Time worked" value={summary ? hours(summary.total_minutes) : "—"} />
        <Stat label="Average per day" value={summary && summary.days_worked > 0 ? hours(Math.round(summary.total_minutes / summary.days_worked)) : "—"} />
        <div className="panel stat"><div className="statlabel">Month</div><input className="input" type="month" value={month} onChange={(event) => setMonth(event.target.value)} style={{ marginTop: 8 }} /></div>
      </div>
      <Panel title={title} bodyless>
        {summary && summary.days.length === 0 ? <EmptyState icon="◷" title="No attendance this month" /> : (
          <Table headers={["Date", "In", "Out", "Time", "Note", ...(extraColumn ? [""] : [])]}>
            {summary?.days.flatMap((day) => day.shifts.map((shift, index) => (
              <tr key={shift.id}>
                <td>{index === 0 ? formatDate(day.date) : ""}</td>
                <td>{timeOf(shift.clock_in_local)}</td>
                <td>{shift.clock_out_local ? timeOf(shift.clock_out_local) : <Badge tone="amber" label="Still clocked in" />}</td>
                <td>{hours(shift.minutes)}{shift.source !== "web" && <span className="tiny"> · {shift.source}</span>}</td>
                <td className="muted" style={{ whiteSpace: "normal" }}>{shift.note ?? "—"}</td>
                {extraColumn && <td style={{ textAlign: "right" }}>{extraColumn(shift)}</td>}
              </tr>
            )))}
          </Table>
        )}
      </Panel>
    </div>
  );
}
