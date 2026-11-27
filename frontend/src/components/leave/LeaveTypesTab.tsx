"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, Modal, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

export type LeaveType = {
  id: string;
  name: string;
  code: string;
  color: string;
  is_paid: boolean;
  uses_balance: boolean;
  requestable_by: "all" | "hr_only";
  approval: "none" | "manager" | "hr" | "manager_then_hr";
  reason_required: boolean;
  attachment_after_days: number | null;
  is_active: boolean;
};

const approvalLabel = { none: "No approval", manager: "Manager", hr: "HR", manager_then_hr: "Manager, then HR" };
const blank: Omit<LeaveType, "id"> = { name: "", code: "", color: "#315ee8", is_paid: true, uses_balance: true, requestable_by: "all", approval: "manager", reason_required: false, attachment_after_days: null, is_active: true };

export function LeaveTypesTab() {
  const { can } = useAuth();
  const canManage = can("leave.policies.manage");
  const [types, setTypes] = useState<LeaveType[] | null>(null);
  const [editing, setEditing] = useState<(Omit<LeaveType, "id"> & { id?: string }) | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setTypes((await api<{ data: LeaveType[] }>("/leave/types")).data);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function save() {
    if (!editing) return;
    setError(null);
    try {
      await api(editing.id ? `/leave/types/${editing.id}` : "/leave/types", { method: editing.id ? "PUT" : "POST", body: editing });
      setEditing(null);
      setNotice("Leave type saved.");
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  async function addStarters() {
    setNotice((await api<{ message: string }>("/leave/types/starters", { method: "POST" })).message);
    await load();
  }

  const set = <K extends keyof typeof blank>(key: K, value: (typeof blank)[K]) => editing && setEditing({ ...editing, [key]: value });

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel
        title="Leave types"
        subtitle="What kinds of leave people can take, whether it is paid, whether an allowance is tracked, and who approves it."
        right={canManage && <div className="row"><button className="btn" onClick={() => void addStarters()}>Add starter types</button><button className="btn primary" onClick={() => { setError(null); setEditing({ ...blank }); }}>New leave type</button></div>}
        bodyless
      >
        {types && types.length === 0 ? (
          <EmptyState icon="☀" title="No leave types yet" action={canManage && <button className="btn primary" onClick={() => void addStarters()}>Add annual, sick, unpaid and parental leave</button>} />
        ) : (
          <Table headers={["Leave type", "Paid", "Allowance", "Approval", "Rules", "Status", ""]}>
            {types?.map((type) => (
              <tr key={type.id}>
                <td><span style={{ display: "inline-block", width: 10, height: 10, borderRadius: 5, background: type.color, marginRight: 8 }} /><strong>{type.name}</strong> <span className="tiny">{type.code}</span></td>
                <td>{type.is_paid ? "Paid" : "Unpaid"}</td>
                <td>{type.uses_balance ? "Tracked" : "Not tracked"}</td>
                <td>{approvalLabel[type.approval]}</td>
                <td className="tiny">
                  {type.requestable_by === "hr_only" && "Recorded by HR. "}
                  {type.reason_required && "Reason needed. "}
                  {type.attachment_after_days && `Attachment after ${type.attachment_after_days} days.`}
                </td>
                <td>{type.is_active ? <Badge tone="green" label="Active" /> : <Badge tone="gray" label="Off" />}</td>
                <td style={{ textAlign: "right" }}>{canManage && <button className="btn" onClick={() => { setError(null); setEditing({ ...type }); }}>Edit</button>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {editing && (
        <Modal title={editing.id ? `Edit ${editing.name}` : "New leave type"} onClose={() => setEditing(null)} footer={<><button className="btn" onClick={() => setEditing(null)}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            <Field label="Name" required error={error?.errors.name?.[0]}><input className="input" value={editing.name} onChange={(event) => set("name", event.target.value)} /></Field>
            <Field label="Code" required error={error?.errors.code?.[0]} hint="Short code, e.g. AL"><input className="input" value={editing.code} onChange={(event) => set("code", event.target.value)} /></Field>
            <Field label="Who approves">
              <select value={editing.approval} onChange={(event) => set("approval", event.target.value as LeaveType["approval"])}>
                {Object.entries(approvalLabel).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </Field>
            <Field label="Who can request">
              <select value={editing.requestable_by} onChange={(event) => set("requestable_by", event.target.value as LeaveType["requestable_by"])}>
                <option value="all">Employees request it themselves</option>
                <option value="hr_only">Only HR records it</option>
              </select>
            </Field>
            <Field label="Attachment needed after (days)" optional hint="e.g. a sick note after 7 days">
              <input className="input" type="number" min="1" value={editing.attachment_after_days ?? ""} onChange={(event) => set("attachment_after_days", event.target.value ? Number(event.target.value) : null)} />
            </Field>
            <Field label="Colour" optional><input className="input" type="color" value={editing.color} onChange={(event) => set("color", event.target.value)} /></Field>
            <label className="checkrow"><input type="checkbox" checked={editing.is_paid} onChange={(event) => set("is_paid", event.target.checked)} /> Paid leave</label>
            <label className="checkrow"><input type="checkbox" checked={editing.uses_balance} onChange={(event) => set("uses_balance", event.target.checked)} /> Track an allowance (balance)</label>
            <label className="checkrow"><input type="checkbox" checked={editing.reason_required} onChange={(event) => set("reason_required", event.target.checked)} /> A reason is required</label>
            <label className="checkrow"><input type="checkbox" checked={editing.is_active} onChange={(event) => set("is_active", event.target.checked)} /> Active</label>
          </div>
        </Modal>
      )}
    </>
  );
}
