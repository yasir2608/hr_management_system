"use client";

import { useEffect, useState } from "react";
import { EmptyState, formatDate, Panel, Person, Table } from "@/components/ui";
import { api } from "@/lib/api";

type Row = {
  employee: { id: string; full_name: string | null; employee_number: string | null };
  unpaid_days: number;
  entries: { leave_request_id: string; leave_type: string; dates: string[]; days: number }[];
};
type Feed = { period: string; total_days: number; data: Row[] };

function monthBounds(month: string) {
  const [year, value] = month.split("-").map(Number);
  const last = new Date(year, value, 0).getDate();
  return { from: `${month}-01`, to: `${month}-${String(last).padStart(2, "0")}` };
}

/** What payroll will deduct as unpaid leave for a pay period. */
export function UnpaidFeedTab() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [feed, setFeed] = useState<Feed | null>(null);

  useEffect(() => {
    api<Feed>("/leave/unpaid", { query: monthBounds(month) }).then(setFeed);
  }, [month]);

  return (
    <Panel
      title="Unpaid leave for payroll"
      subtitle={feed ? `${feed.period} · ${feed.total_days} unpaid day(s) in total. Only approved leave is included; payroll runs read this same list.` : undefined}
      right={<input className="input" type="month" style={{ width: 170 }} value={month} onChange={(event) => setMonth(event.target.value)} aria-label="Pay period month" />}
      bodyless
    >
      {feed && feed.data.length === 0 ? (
        <EmptyState icon="☀" title="No unpaid leave in this period" />
      ) : (
        <Table headers={["Employee", "Unpaid days", "Leave"]}>
          {feed?.data.map((row) => (
            <tr key={row.employee.id}>
              <td><Person name={row.employee.full_name ?? "—"} detail={row.employee.employee_number} /></td>
              <td><strong>{row.unpaid_days}</strong></td>
              <td style={{ whiteSpace: "normal" }}>
                {row.entries.map((entry) => (
                  <div key={entry.leave_request_id} className="tiny">{entry.leave_type}: {entry.days} day(s), {formatDate(entry.dates[0])}{entry.dates.length > 1 ? ` – ${formatDate(entry.dates[entry.dates.length - 1])}` : ""}</div>
                ))}
              </td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}
