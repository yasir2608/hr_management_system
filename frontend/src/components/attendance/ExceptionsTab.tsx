"use client";

import { useEffect, useState } from "react";
import { Badge, EmptyState, formatDate, Panel, Person, Table } from "@/components/ui";
import { api } from "@/lib/api";
import { hours, timeOf, type Shift } from "./attendance";

type ExceptionRow = { type: string; message: string; record: Shift; employee: { id: string; full_name: string } };

const labels: Record<string, React.ReactNode> = {
  missing_clock_out: <Badge tone="red" label="Missing clock-out" />,
  long_shift: <Badge tone="amber" label="Long shift" />,
  short_shift: <Badge tone="amber" label="Short shift" />,
  worked_on_leave: <Badge tone="blue" label="Worked on leave" />,
};

/** Records that need a look: missing clock-outs and unusual shifts. */
export function ExceptionsTab() {
  const [rows, setRows] = useState<ExceptionRow[] | null>(null);

  useEffect(() => {
    api<{ data: ExceptionRow[] }>("/attendance/exceptions").then((result) => setRows(result.data));
  }, []);

  return (
    <Panel title="Exceptions" subtitle="The last 30 days. Employees can ask for a correction; managers and HR review it." bodyless>
      {rows && rows.length === 0 ? <EmptyState icon="✓" title="Nothing unusual" /> : (
        <Table headers={["Employee", "Date", "In", "Out", "Time", "Exception"]}>
          {rows?.map((row, index) => (
            <tr key={`${row.record.id}-${index}`}>
              <td><Person name={row.employee.full_name} /></td>
              <td>{formatDate(row.record.work_date)}</td>
              <td>{timeOf(row.record.clock_in_local)}</td>
              <td>{timeOf(row.record.clock_out_local)}</td>
              <td>{hours(row.record.minutes)}</td>
              <td>{labels[row.type]}<div className="tiny">{row.message}</div></td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}
