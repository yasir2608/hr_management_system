"use client";

import { useEffect, useState } from "react";
import { approvalStatus } from "@/components/approvals/ApprovalDetail";
import { EmptyState, Panel, Stat, Table } from "@/components/ui";
import { api } from "@/lib/api";
import type { Balance } from "./BalancesTab";
import { dateRange, type LeaveRequestRow } from "./MyLeaveTab";

/** Leave on an employee's record: balances and every request. */
export function EmployeeLeavePanel({ employeeId }: { employeeId: string }) {
  const [balances, setBalances] = useState<Balance[]>([]);
  const [history, setHistory] = useState<LeaveRequestRow[] | null>(null);

  useEffect(() => {
    api<{ data: Balance[] }>(`/leave/employees/${employeeId}/balances`).then((result) => setBalances(result.data)).catch(() => undefined);
    api<{ data: LeaveRequestRow[] }>(`/leave/employees/${employeeId}/requests`).then((result) => setHistory(result.data)).catch(() => setHistory([]));
  }, [employeeId]);

  return (
    <div className="stack">
      {balances.length > 0 && (
        <div className="grid stats">
          {balances.slice(0, 4).map((balance) => <Stat key={balance.leave_type_id} label={balance.leave_type} value={`${balance.available} days`} note={`${balance.taken} taken · ${balance.pending} waiting`} />)}
        </div>
      )}
      <Panel title="Leave history" bodyless>
        {history && history.length === 0 ? <EmptyState icon="☀" title="No leave recorded" /> : (
          <Table headers={["Leave type", "Dates", "Days", "Status"]}>
            {history?.map((row) => (
              <tr key={row.id}>
                <td>{row.leave_type?.name}</td>
                <td>{dateRange(row)}</td>
                <td>{Number(row.days)}{Number(row.unpaid_days) > 0 && <span className="tiny"> ({Number(row.unpaid_days)} unpaid)</span>}</td>
                <td>{approvalStatus[row.status] ?? row.status}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}
