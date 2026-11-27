"use client";

import { useCallback, useEffect, useState } from "react";
import { Banner, EmptyState, Field, formatDate, Modal, Panel, Person, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { LeaveType } from "./LeaveTypesTab";

export type Balance = {
  leave_type_id: string;
  leave_type: string;
  policy: string | null;
  year_start: string;
  year_end: string;
  entitlement: number;
  carried_over: number;
  adjustments: number;
  taken: number;
  pending: number;
  available: number;
};
type Row = Balance & { employee: { id: string; full_name: string } };

export function BalancesTab() {
  const { can } = useAuth();
  const [types, setTypes] = useState<LeaveType[]>([]);
  const [typeId, setTypeId] = useState("");
  const [rows, setRows] = useState<Row[] | null>(null);
  const [adjusting, setAdjusting] = useState<{ employee: Row["employee"]; days: string; kind: string; reason: string } | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: LeaveType[] }>("/leave/types").then((result) => setTypes(result.data.filter((type) => type.uses_balance && type.is_active)));
  }, []);

  const load = useCallback(async () => {
    const result = await api<{ data: Row[]; leave_type: { id: string } }>("/leave/balances", { query: { leave_type_id: typeId } }).catch(() => null);
    setRows(result?.data ?? []);
    if (result && !typeId) setTypeId(result.leave_type.id);
  }, [typeId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch when the leave type changes
    void load();
  }, [load]);

  async function adjust() {
    if (!adjusting) return;
    setError(null);
    try {
      await api("/leave/balances/adjustments", { body: { employee_id: adjusting.employee.id, leave_type_id: typeId, days: adjusting.days, kind: adjusting.kind, reason: adjusting.reason } });
      setNotice(`Balance for ${adjusting.employee.full_name} adjusted.`);
      setAdjusting(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not adjust.", 0));
    }
  }

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel
        title="Leave balances"
        subtitle={rows?.[0] ? `Leave year ${formatDate(rows[0].year_start)} – ${formatDate(rows[0].year_end)}` : undefined}
        right={
          <select style={{ width: 200 }} value={typeId} onChange={(event) => setTypeId(event.target.value)} aria-label="Leave type">
            {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
          </select>
        }
        bodyless
      >
        {rows && rows.length === 0 ? (
          <EmptyState icon="☀" title="No balances to show">Add a leave type that tracks an allowance and a policy for it.</EmptyState>
        ) : (
          <Table headers={["Employee", "Entitlement", "Carried over", "Adjustments", "Taken", "Waiting", "Available", ""]}>
            {rows?.map((row) => (
              <tr key={row.employee.id}>
                <td><Person name={row.employee.full_name} detail={row.policy ?? "No policy applies"} /></td>
                <td>{row.entitlement}</td>
                <td>{row.carried_over}</td>
                <td>{row.adjustments}</td>
                <td>{row.taken}</td>
                <td>{row.pending}</td>
                <td><strong style={row.available < 0 ? { color: "var(--red)" } : undefined}>{row.available}</strong></td>
                <td style={{ textAlign: "right" }}>{can("leave.balances.manage") && <button className="btn" onClick={() => { setError(null); setAdjusting({ employee: row.employee, days: "", kind: "adjustment", reason: "" }); }}>Adjust</button>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {adjusting && (
        <Modal title={`Adjust balance · ${adjusting.employee.full_name}`} onClose={() => setAdjusting(null)} footer={<><button className="btn" onClick={() => setAdjusting(null)}>Cancel</button><button className="btn primary" onClick={() => void adjust()}>Save adjustment</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            <Field label="Days" required error={error?.errors.days?.[0]} hint="Use a minus sign to remove days, e.g. -1.5"><input className="input" type="number" step="0.5" value={adjusting.days} onChange={(event) => setAdjusting({ ...adjusting, days: event.target.value })} /></Field>
            <Field label="Kind">
              <select value={adjusting.kind} onChange={(event) => setAdjusting({ ...adjusting, kind: event.target.value })}>
                <option value="adjustment">Correction</option>
                <option value="carry_over">Carried over from last year</option>
              </select>
            </Field>
            <Field label="Reason" required full error={error?.errors.reason?.[0]}><input className="input" value={adjusting.reason} onChange={(event) => setAdjusting({ ...adjusting, reason: event.target.value })} /></Field>
          </div>
        </Modal>
      )}
    </>
  );
}
