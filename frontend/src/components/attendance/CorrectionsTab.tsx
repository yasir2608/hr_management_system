"use client";

import { useCallback, useEffect, useState } from "react";
import { ApprovalDetail, approvalStatus } from "@/components/approvals/ApprovalDetail";
import { EmptyState, formatDate, Modal, Panel, Person, Table } from "@/components/ui";
import { api } from "@/lib/api";

type Row = {
  id: string;
  work_date: string;
  reason: string;
  status: string;
  approval_request_id: string | null;
  timezone: string;
  original: { clock_in: string | null; clock_out: string | null };
  requested: { clock_in: string | null; clock_out: string | null };
  employee: { id: string; full_name: string };
};

const time = (value: string | null) => (value ? value.slice(11, 16) : "—");

/** Correction requests to review: recorded and requested times side by side. */
export function CorrectionsTab() {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [status, setStatus] = useState("pending,info_requested");
  const [open, setOpen] = useState<string | null>(null);

  const load = useCallback(async () => {
    setRows((await api<{ data: Row[] }>("/attendance/corrections", { query: { status } })).data);
  }, [status]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch when the filter changes
    void load();
  }, [load]);

  return (
    <>
      <Panel
        title="Correction requests"
        subtitle="Approving updates the record; the recorded times are kept with the request."
        right={
          <select style={{ width: 190 }} value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Status">
            <option value="pending,info_requested">Waiting for a decision</option>
            <option value="approved">Approved</option>
            <option value="declined,cancelled">Declined or cancelled</option>
            <option value="all">All</option>
          </select>
        }
        bodyless
      >
        {rows && rows.length === 0 ? <EmptyState icon="✓" title="Nothing to review" /> : (
          <Table headers={["Employee", "Date", "Recorded", "Requested", "Reason", "Status", ""]}>
            {rows?.map((row) => (
              <tr key={row.id}>
                <td><Person name={row.employee.full_name} /></td>
                <td>{formatDate(row.work_date)}</td>
                <td>{row.original.clock_in ? `${time(row.original.clock_in)} – ${time(row.original.clock_out)}` : "Nothing recorded"}</td>
                <td><strong>{time(row.requested.clock_in)} – {time(row.requested.clock_out)}</strong><div className="tiny">{row.timezone}</div></td>
                <td className="muted" style={{ whiteSpace: "normal", maxWidth: 240 }}>{row.reason}</td>
                <td>{approvalStatus[row.status] ?? row.status}</td>
                <td style={{ textAlign: "right" }}>{row.approval_request_id && <button className="btn" onClick={() => setOpen(row.approval_request_id)}>Review</button>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {open && <Modal title="Correction request" onClose={() => setOpen(null)}><ApprovalDetail id={open} onChanged={() => void load()} /></Modal>}
    </>
  );
}
