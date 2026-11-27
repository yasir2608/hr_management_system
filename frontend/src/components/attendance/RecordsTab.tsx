"use client";

import { useEffect, useState } from "react";
import { Badge, EmptyState, formatDate, Panel, Person, Table } from "@/components/ui";
import { api } from "@/lib/api";
import { hours, timeOf, type Shift } from "./attendance";

/** Records of the people within the viewer's attendance scope. */
export function RecordsTab() {
  // The last seven days, worked out once when the tab opens.
  const [range, setRange] = useState(() => {
    const today = new Date();
    const weekAgo = new Date(today);
    weekAgo.setDate(today.getDate() - 6);
    return { from: weekAgo.toISOString().slice(0, 10), to: today.toISOString().slice(0, 10) };
  });
  const [result, setResult] = useState<{ data: Shift[]; total_minutes: number } | null>(null);

  useEffect(() => {
    api<{ data: Shift[]; total_minutes: number }>("/attendance/records", { query: range }).then(setResult);
  }, [range]);

  return (
    <Panel
      title="Attendance records"
      subtitle={result ? `${result.data.length} shift(s) · ${hours(result.total_minutes)} in total` : undefined}
      right={
        <div className="row">
          <input className="input" type="date" value={range.from} onChange={(event) => setRange({ ...range, from: event.target.value })} aria-label="From" />
          <input className="input" type="date" value={range.to} onChange={(event) => setRange({ ...range, to: event.target.value })} aria-label="To" />
        </div>
      }
      bodyless
    >
      {result && result.data.length === 0 ? <EmptyState icon="◷" title="No records in this period" /> : (
        <Table headers={["Employee", "Date", "In", "Out", "Time", "Time zone"]}>
          {result?.data.map((shift) => (
            <tr key={shift.id}>
              <td>{shift.employee && <Person name={shift.employee.full_name} />}</td>
              <td>{formatDate(shift.work_date)}</td>
              <td>{timeOf(shift.clock_in_local)}</td>
              <td>{shift.clock_out_local ? timeOf(shift.clock_out_local) : <Badge tone="amber" label="Still clocked in" />}</td>
              <td>{hours(shift.minutes)}</td>
              <td className="tiny">{shift.clock_in_timezone}</td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}
