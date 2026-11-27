"use client";

import { useCallback, useEffect, useState } from "react";
import { ApprovalDetail, approvalStatus } from "@/components/approvals/ApprovalDetail";
import { EmptyState, Modal, Panel, Person, Table } from "@/components/ui";
import { api } from "@/lib/api";
import { LeaveContext } from "./LeaveContext";
import { dateRange, type LeaveRequestRow } from "./MyLeaveTab";

/** Leave requests of the people within the viewer's scope. */
export function RequestsTab() {
  const [rows, setRows] = useState<LeaveRequestRow[] | null>(null);
  const [status, setStatus] = useState("pending,info_requested");
  const [open, setOpen] = useState<LeaveRequestRow | null>(null);

  const load = useCallback(async () => {
    setRows((await api<{ data: LeaveRequestRow[] }>("/leave/requests", { query: { status } })).data);
  }, [status]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch when the filter changes
    void load();
  }, [load]);

  return (
    <>
      <Panel
        title="Leave requests"
        right={
          <select style={{ width: 190 }} value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Status">
            <option value="pending,info_requested">Waiting for a decision</option>
            <option value="approved">Approved</option>
            <option value="declined,cancelled">Declined or cancelled</option>
            <option value="">All</option>
          </select>
        }
        bodyless
      >
        {rows && rows.length === 0 ? (
          <EmptyState icon="☀" title="No requests here" />
        ) : (
          <Table headers={["Employee", "Leave type", "Dates", "Days", "Status", ""]}>
            {rows?.map((row) => (
              <tr key={row.id}>
                <td>{row.employee && <Person name={row.employee.full_name} />}</td>
                <td>{row.leave_type?.name}</td>
                <td>{dateRange(row)}</td>
                <td>{Number(row.days)}</td>
                <td>{approvalStatus[row.status] ?? row.status}</td>
                <td style={{ textAlign: "right" }}>{row.approval_request_id && <button className="btn" onClick={() => setOpen(row)}>Open</button>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {open && (
        <Modal title="Leave request" onClose={() => setOpen(null)}>
          <LeaveContext leaveRequestId={open.id} />
          {open.approval_request_id && <ApprovalDetail id={open.approval_request_id} onChanged={() => void load()} />}
        </Modal>
      )}
    </>
  );
}
