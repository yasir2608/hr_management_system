"use client";

import { useCallback, useEffect, useState } from "react";
import { ApprovalDetail, approvalStatus } from "@/components/approvals/ApprovalDetail";
import { Banner, Field, formatDate, Modal, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import type { Shift } from "./attendance";
import { MonthView } from "./MonthView";

type Correction = {
  id: string;
  work_date: string;
  reason: string;
  status: string;
  approval_request_id: string | null;
  original: { clock_in: string | null; clock_out: string | null };
  requested: { clock_in: string | null; clock_out: string | null };
};

const time = (value: string | null) => (value ? value.slice(11, 16) : "—");

export function MyAttendanceTab() {
  const [form, setForm] = useState<{ attendance_record_id: string | null; work_date: string; clock_in: string; clock_out: string; clock_out_next_day: boolean; reason: string } | null>(null);
  const [corrections, setCorrections] = useState<Correction[]>([]);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [refresh, setRefresh] = useState(0);

  const load = useCallback(async () => {
    setCorrections((await api<{ data: Correction[] }>("/me/attendance/corrections").catch(() => ({ data: [] as Correction[] }))).data);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function send() {
    if (!form) return;
    setError(null);
    try {
      const result = await api<{ message: string }>("/me/attendance/corrections", { body: { ...form, clock_out: form.clock_out || null } });
      setNotice(result.message);
      setForm(null);
      setRefresh(refresh + 1);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not send.", 0));
    }
  }

  const ask = (shift: Shift) => {
    setError(null);
    setForm({ attendance_record_id: shift.id, work_date: shift.work_date ?? shift.clock_in_local.slice(0, 10), clock_in: shift.clock_in_local.slice(11, 16), clock_out: shift.clock_out_local?.slice(11, 16) ?? "", clock_out_next_day: false, reason: "" });
  };

  return (
    <div className="stack">
      {notice && <Banner tone="success">{notice}</Banner>}
      <div className="row">
        <span className="muted">Something wrong or missing? Ask for a correction; your manager reviews it.</span>
        <button className="btn" onClick={() => { setError(null); setForm({ attendance_record_id: null, work_date: new Date().toISOString().slice(0, 10), clock_in: "09:00", clock_out: "17:00", clock_out_next_day: false, reason: "" }); }}>Add a missing shift</button>
      </div>
      <MonthView key={refresh} path="/me/attendance" title="My shifts" extraColumn={(shift) => <button className="btn" onClick={() => ask(shift)}>Ask for correction</button>} />
      {corrections.length > 0 && (
        <Panel title="My correction requests" bodyless>
          <Table headers={["Date", "Recorded", "Requested", "Status", ""]}>
            {corrections.map((correction) => (
              <tr key={correction.id}>
                <td>{formatDate(correction.work_date)}</td>
                <td>{correction.original.clock_in ? `${time(correction.original.clock_in)} – ${time(correction.original.clock_out)}` : "Nothing recorded"}</td>
                <td>{time(correction.requested.clock_in)} – {time(correction.requested.clock_out)}</td>
                <td>{approvalStatus[correction.status] ?? correction.status}</td>
                <td style={{ textAlign: "right" }}>{correction.approval_request_id && <button className="link" onClick={() => setOpen(correction.approval_request_id)}>{correction.status === "info_requested" ? "Answer" : "Details"}</button>}</td>
              </tr>
            ))}
          </Table>
        </Panel>
      )}
      {open && <Modal title="Correction request" onClose={() => setOpen(null)}><ApprovalDetail id={open} onChanged={() => void load()} /></Modal>}
      {form && (
        <Modal title={form.attendance_record_id ? "Ask for a correction" : "Add a missing shift"} onClose={() => setForm(null)} footer={<><button className="btn" onClick={() => setForm(null)}>Cancel</button><button className="btn primary" onClick={() => void send()}>Send to my manager</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            <Field label="Date" error={error?.errors.work_date?.[0]}><input className="input" type="date" value={form.work_date} disabled={!!form.attendance_record_id} onChange={(event) => setForm({ ...form, work_date: event.target.value })} /></Field>
            <span />
            <Field label="Clock-in (local time)" error={error?.errors.clock_in?.[0]}><input className="input" type="time" value={form.clock_in} onChange={(event) => setForm({ ...form, clock_in: event.target.value })} /></Field>
            <Field label="Clock-out (local time)" optional error={error?.errors.clock_out?.[0]}><input className="input" type="time" value={form.clock_out} onChange={(event) => setForm({ ...form, clock_out: event.target.value })} /></Field>
            <label className="checkrow field full"><input type="checkbox" checked={form.clock_out_next_day} onChange={(event) => setForm({ ...form, clock_out_next_day: event.target.checked })} /> The clock-out was the next day (night shift)</label>
            <Field label="What happened" required full error={error?.errors.reason?.[0] ?? error?.errors.attendance_record_id?.[0]}><textarea className="input" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} /></Field>
          </div>
          <p className="tiny">The recorded times are kept as they are until your manager approves the change.</p>
        </Modal>
      )}
    </div>
  );
}
