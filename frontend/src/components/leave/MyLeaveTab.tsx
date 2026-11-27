"use client";

import { useCallback, useEffect, useState } from "react";
import { ApprovalDetail, approvalStatus } from "@/components/approvals/ApprovalDetail";
import { Banner, EmptyState, Field, formatDate, Modal, Panel, Stat, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import type { Balance } from "./BalancesTab";
import type { LeaveType } from "./LeaveTypesTab";

export type LeaveRequestRow = {
  id: string;
  start_date: string;
  end_date: string;
  start_half: boolean;
  end_half: boolean;
  days: string;
  unpaid_days: string;
  reason: string | null;
  status: string;
  approval_request_id: string | null;
  leave_type: { id: string; name: string; color: string; is_paid: boolean } | null;
  employee?: { id: string; full_name: string };
  has_attachment: boolean;
};

export function dateRange(row: { start_date: string; end_date: string; start_half?: boolean; end_half?: boolean }) {
  const start = `${formatDate(row.start_date)}${row.start_half ? " (pm)" : ""}`;
  return row.start_date === row.end_date ? start : `${start} – ${formatDate(row.end_date)}${row.end_half ? " (am)" : ""}`;
}

export function MyLeaveTab() {
  const [balances, setBalances] = useState<Balance[]>([]);
  const [rule, setRule] = useState<"block" | "allow_unpaid" | "exception">("block");
  const [requests, setRequests] = useState<LeaveRequestRow[] | null>(null);
  const [types, setTypes] = useState<LeaveType[]>([]);
  const [form, setForm] = useState<{ id?: string; leave_type_id: string; start_date: string; end_date: string; start_half: boolean; end_half: boolean; reason: string } | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [days, setDays] = useState<number | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [answering, setAnswering] = useState<string | null>(null);

  const load = useCallback(async () => {
    const [balanceResult, requestResult, typeResult] = await Promise.all([
      api<{ data: Balance[]; insufficient_balance_rule?: string }>("/me/leave/balances").catch(() => ({ data: [] as Balance[], insufficient_balance_rule: "block" })),
      api<{ data: LeaveRequestRow[] }>("/me/leave/requests").catch(() => ({ data: [] })),
      api<{ data: LeaveType[] }>("/leave/types"),
    ]);
    setBalances(balanceResult.data);
    setRule((balanceResult as { insufficient_balance_rule?: "block" | "allow_unpaid" | "exception" }).insufficient_balance_rule ?? "block");
    setRequests(requestResult.data);
    setTypes(typeResult.data.filter((type) => type.is_active && type.requestable_by === "all"));
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  useEffect(() => {
    if (!form?.start_date || !form.end_date || form.end_date < form.start_date) return;
    api<{ days: number }>("/leave/days-preview", { body: { start_date: form.start_date, end_date: form.end_date, start_half: form.start_half, end_half: form.end_half } })
      .then((result) => setDays(result.days))
      .catch(() => setDays(null));
  }, [form?.start_date, form?.end_date, form?.start_half, form?.end_half]);

  async function cancelRequest(id: string) {
    if (!window.confirm("Cancel this leave? The days go back to your balance.")) return;
    try {
      setNotice((await api<{ message: string }>(`/me/leave/requests/${id}/cancel`, { method: "POST" })).message);
    } catch (caught) {
      setNotice(caught instanceof ApiError ? caught.message : "Could not cancel.");
    }
    await load();
  }

  async function send() {
    if (!form) return;
    setError(null);
    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => key !== "id" && value !== undefined && body.append(key, typeof value === "boolean" ? (value ? "1" : "0") : value));
    if (file) body.append("attachment", file);
    try {
      const result = await api<{ message: string }>(form.id ? `/me/leave/requests/${form.id}` : "/me/leave/requests", { body });
      setNotice(result.message);
      setForm(null);
      setFile(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not send.", 0));
    }
  }

  const selected = types.find((type) => type.id === form?.leave_type_id);
  const today = new Date().toISOString().slice(0, 10);

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      <div className="grid stats">
        {balances.slice(0, 3).map((balance) => (
          <Stat key={balance.leave_type_id} label={balance.leave_type} value={`${balance.available} days`} note={`${balance.taken} taken · ${balance.pending} waiting · of ${balance.entitlement + balance.carried_over + balance.adjustments}`} />
        ))}
        <div className="panel stat" style={{ display: "grid", placeItems: "center" }}>
          <button className="btn primary" disabled={types.length === 0} onClick={() => { setError(null); setDays(null); setForm({ leave_type_id: types[0]?.id ?? "", start_date: today, end_date: today, start_half: false, end_half: false, reason: "" }); }}>Request leave</button>
        </div>
      </div>
      <Panel title="My requests" bodyless>
        {requests && requests.length === 0 ? (
          <EmptyState icon="☀" title="No leave requests yet" />
        ) : (
          <Table headers={["Leave type", "Dates", "Days", "Status", "Reason", ""]}>
            {requests?.map((row) => (
              <tr key={row.id}>
                <td><span style={{ display: "inline-block", width: 9, height: 9, borderRadius: 5, background: row.leave_type?.color, marginRight: 7 }} />{row.leave_type?.name}</td>
                <td>{dateRange(row)}</td>
                <td>{Number(row.days)}{Number(row.unpaid_days) > 0 && <div className="tiny">{Number(row.unpaid_days)} unpaid</div>}</td>
                <td>{approvalStatus[row.status] ?? row.status}{row.approval_request_id && <div><button className="link" onClick={() => setAnswering(row.approval_request_id)}>{row.status === "info_requested" ? "Answer the question" : "Details"}</button></div>}</td>
                <td className="muted" style={{ whiteSpace: "normal" }}>{row.reason ?? "—"}</td>
                <td style={{ textAlign: "right" }}>
                  {(["pending", "info_requested"].includes(row.status) || (row.status === "approved" && row.start_date > today)) && (
                    <div className="row" style={{ justifyContent: "flex-end" }}>
                      <button className="btn" onClick={() => { setError(null); setDays(null); setForm({ id: row.id, leave_type_id: row.leave_type?.id ?? "", start_date: row.start_date, end_date: row.end_date, start_half: row.start_half, end_half: row.end_half, reason: row.reason ?? "" }); }}>Change</button>
                      <button className="btn danger" onClick={() => void cancelRequest(row.id)}>{row.status === "approved" ? "Cancel" : "Withdraw"}</button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {answering && (
        <Modal title="Leave request" onClose={() => setAnswering(null)}>
          <ApprovalDetail id={answering} onChanged={() => void load()} />
        </Modal>
      )}
      {form && (
        <Modal title={form.id ? "Change leave request" : "Request leave"} onClose={() => setForm(null)} footer={<><button className="btn" onClick={() => setForm(null)}>Cancel</button><button className="btn primary" onClick={() => void send()}>Send request</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            <Field label="Leave type" full error={error?.errors.leave_type_id?.[0]}>
              <select value={form.leave_type_id} onChange={(event) => setForm({ ...form, leave_type_id: event.target.value })}>
                {types.map((type) => <option key={type.id} value={type.id}>{type.name}{type.is_paid ? "" : " (unpaid)"}</option>)}
              </select>
            </Field>
            <Field label="First day" error={error?.errors.start_date?.[0]}><input className="input" type="date" value={form.start_date} onChange={(event) => setForm({ ...form, start_date: event.target.value, end_date: form.end_date < event.target.value ? event.target.value : form.end_date })} /></Field>
            <Field label="Last day" error={error?.errors.end_date?.[0]}><input className="input" type="date" value={form.end_date} onChange={(event) => setForm({ ...form, end_date: event.target.value })} /></Field>
            <label className="checkrow"><input type="checkbox" checked={form.start_half} onChange={(event) => setForm({ ...form, start_half: event.target.checked })} /> Start at midday</label>
            <label className="checkrow"><input type="checkbox" checked={form.end_half} onChange={(event) => setForm({ ...form, end_half: event.target.checked })} /> Finish at midday</label>
            <Field label="Reason" optional={!selected?.reason_required} required={selected?.reason_required} full error={error?.errors.reason?.[0]}>
              <textarea className="input" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
            </Field>
            {selected?.attachment_after_days && (
              <Field label="Attachment" full error={error?.errors.attachment?.[0]} hint={`Needed for more than ${selected.attachment_after_days} days. PDF or photo.`}>
                <input className="input" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
              </Field>
            )}
          </div>
          {days !== null && (() => {
            const available = balances.find((balance) => balance.leave_type_id === selected?.id)?.available ?? 0;
            const over = selected?.uses_balance && days > available;
            const overText = { block: "This is more than you have, so it cannot be sent.", allow_unpaid: `The extra -e day(s) will be unpaid.`, exception: "This is more than you have; it will go to HR as an exception." }[rule];
            return <div className={`callout `} style={{ marginTop: 12 }}>{days} working day(s).{selected?.uses_balance && ` You have  day(s) available.`} {over && overText}</div>;
          })()}
        </Modal>
      )}
    </>
  );
}
