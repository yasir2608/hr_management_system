"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, Modal, Panel, Table, formatDate, money } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { ImpactPreview } from "./ImpactPreview";

type PayItem = {
  id: string; kind: "allowance" | "deduction"; name: string; calculation: "fixed" | "percent"; amount: string | null; percent: string | null;
  taxable: boolean; notes: string | null; valid_from: string; valid_to: string | null; status: "active" | "scheduled" | "ended";
};
type Listing = { data: PayItem[]; amounts_restricted: boolean; can_manage: boolean };
type Action = { type: "add"; kind: PayItem["kind"] } | { type: "edit" | "change" | "end"; item: PayItem };

const today = () => new Date().toISOString().slice(0, 10);

function ItemModal({ employeeId, action, currency, onClose, onSaved }: { employeeId: string; action: Action; currency: string; onClose: () => void; onSaved: (message: string) => void }) {
  const item = "item" in action ? action.item : null;
  const [form, setForm] = useState({
    kind: action.type === "add" ? action.kind : item!.kind,
    name: item?.name ?? "",
    calculation: item?.calculation ?? "fixed",
    amount: item?.amount ?? "",
    percent: item?.percent ?? "",
    taxable: item?.taxable ?? true,
    valid_from: item?.valid_from ?? today(),
    valid_to: item?.valid_to ?? "",
    notes: item?.notes ?? "",
    effective_from: today(),
    end_date: item?.valid_to ?? today(),
  });
  const [error, setError] = useState<ApiError | null>(null);
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch });
  const fieldError = (key: string) => error?.errors[key]?.[0];
  const started = item !== null && item.status !== "scheduled";
  const base = `/payroll/employees/${employeeId}/pay-items`;
  const label = form.kind === "allowance" ? "allowance" : "deduction";

  async function save() {
    setError(null);
    try {
      if (action.type === "change") {
        await api(`${base}/${item!.id}/change`, { body: { effective_from: form.effective_from, amount: item!.calculation === "fixed" ? form.amount : null, percent: item!.calculation === "percent" ? form.percent : null, reason: form.notes || null } });
        onSaved(`New ${label} amount saved from ${formatDate(form.effective_from)}.`);
      } else if (action.type === "end") {
        await api(`${base}/${item!.id}/end`, { body: { end_date: form.end_date } });
        onSaved(`“${item!.name}” ends on ${formatDate(form.end_date)}.`);
      } else {
        const body = started
          ? { name: form.name, notes: form.notes || null }
          : { kind: form.kind, name: form.name, calculation: form.calculation, amount: form.calculation === "fixed" ? form.amount : null, percent: form.calculation === "percent" ? form.percent : null, taxable: form.taxable, valid_from: form.valid_from, valid_to: form.valid_to || null, notes: form.notes || null };
        await api(item ? `${base}/${item.id}` : base, { method: item ? "PUT" : "POST", body });
        onSaved(item ? "Saved." : `${label[0].toUpperCase()}${label.slice(1)} added.`);
      }
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  const title = action.type === "add" ? `Add ${label}` : action.type === "change" ? `Change “${item!.name}” from a date` : action.type === "end" ? `End “${item!.name}”` : `Edit “${item!.name}”`;

  return (
    <Modal title={title} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      {action.type === "change" && (
        <div className="formgrid">
          <div className="callout" style={{ gridColumn: "1 / -1" }}>The current amount stays for pay periods before this date, so earlier payroll is unchanged.</div>
          <Field label="New amount from" required error={fieldError("effective_from")}><input className="input" type="date" value={form.effective_from} onChange={(event) => set({ effective_from: event.target.value })} /></Field>
          {item!.calculation === "fixed"
            ? <Field label={`New amount per pay period (${currency})`} required error={fieldError("amount")}><input className="input" type="number" min={0} step="0.01" value={form.amount} onChange={(event) => set({ amount: event.target.value })} /></Field>
            : <Field label="New % of base pay" required error={fieldError("percent")}><input className="input" type="number" min={0} max={100} step="0.001" value={form.percent} onChange={(event) => set({ percent: event.target.value })} /></Field>}
          <Field label="Reason" optional full><input className="input" value={form.notes} onChange={(event) => set({ notes: event.target.value })} placeholder="e.g. Annual review" /></Field>
          <div style={{ gridColumn: "1 / -1" }}>
            <ImpactPreview
              employeeId={employeeId}
              ready={!!form.effective_from && Number(item!.calculation === "fixed" ? form.amount : form.percent) > 0}
              body={{ kind: "pay_item", item_id: item!.id, effective_from: form.effective_from, amount: item!.calculation === "fixed" ? form.amount : null, percent: item!.calculation === "percent" ? form.percent : null }}
            />
          </div>
        </div>
      )}
      {action.type === "end" && (
        <Field label="Last day it applies" required error={fieldError("end_date")}><input className="input" type="date" value={form.end_date} onChange={(event) => set({ end_date: event.target.value })} /></Field>
      )}
      {(action.type === "add" || action.type === "edit") && (
        <div className="formgrid">
          {started && <div className="callout" style={{ gridColumn: "1 / -1" }}>This item has started. Only the name and notes can change here; use “Change from a date” for a new amount.</div>}
          <Field label="Name" required error={fieldError("name")} full><input className="input" value={form.name} onChange={(event) => set({ name: event.target.value })} placeholder={form.kind === "allowance" ? "e.g. Car allowance" : "e.g. Cycle to work scheme"} /></Field>
          {!started && (
            <>
              <Field label="Calculated as" required>
                <select value={form.calculation} onChange={(event) => set({ calculation: event.target.value as PayItem["calculation"] })}>
                  <option value="fixed">Fixed amount per pay period</option>
                  <option value="percent">Percentage of base pay</option>
                </select>
              </Field>
              {form.calculation === "fixed"
                ? <Field label={`Amount per pay period (${currency})`} required error={fieldError("amount")}><input className="input" type="number" min={0} step="0.01" value={form.amount} onChange={(event) => set({ amount: event.target.value })} /></Field>
                : <Field label="% of base pay" required error={fieldError("percent")}><input className="input" type="number" min={0} max={100} step="0.001" value={form.percent} onChange={(event) => set({ percent: event.target.value })} /></Field>}
              <Field label="Starts" required error={fieldError("valid_from")}><input className="input" type="date" value={form.valid_from} onChange={(event) => set({ valid_from: event.target.value })} /></Field>
              <Field label="Ends" optional hint="Blank means until changed" error={fieldError("valid_to")}><input className="input" type="date" value={form.valid_to} onChange={(event) => set({ valid_to: event.target.value })} /></Field>
              <Field label="Tax treatment" full>
                <label className="checkrow">
                  <input type="checkbox" checked={form.taxable} onChange={(event) => set({ taxable: event.target.checked })} />
                  {form.kind === "allowance" ? "Taxed as pay" : "Taken after tax (untick if it reduces taxable pay, e.g. salary sacrifice)"}
                </label>
              </Field>
            </>
          )}
          <Field label="Notes" optional full><input className="input" value={form.notes} onChange={(event) => set({ notes: event.target.value })} /></Field>
        </div>
      )}
    </Modal>
  );
}

/** An employee's regular allowances and recurring deductions. */
export function PayItemsPanel({ employeeId, currency }: { employeeId: string; currency: string }) {
  const [listing, setListing] = useState<Listing | null>(null);
  const [action, setAction] = useState<Action | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    api<Listing>(`/payroll/employees/${employeeId}/pay-items`).then(setListing);
  }, [employeeId]);

  useEffect(load, [load]);

  async function remove(item: PayItem) {
    setError(null);
    try {
      setNotice((await api<{ message: string }>(`/payroll/employees/${employeeId}/pay-items/${item.id}`, { method: "DELETE" })).message);
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not remove.");
    }
  }

  const value = (item: PayItem) => (listing?.amounts_restricted ? <span className="badge gray">Restricted</span> : item.calculation === "fixed" ? money(item.amount, currency) : `${Number(item.percent)}% of base pay`);

  return (
    <Panel
      title="Allowances and deductions"
      subtitle="Regular amounts added to or taken from each pay period, for the dates they apply."
      right={listing?.can_manage && <div className="row"><button className="btn soft" onClick={() => setAction({ type: "add", kind: "allowance" })}>Add allowance</button><button className="btn soft" onClick={() => setAction({ type: "add", kind: "deduction" })}>Add deduction</button></div>}
      bodyless={(listing?.data.length ?? 0) > 0}
    >
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}
      {!listing ? <span className="muted">Loading…</span> : listing.data.length === 0 ? <span className="muted">No allowances or deductions.</span> : (
        <Table headers={["Item", "Amount", "Dates", "Status", ""]}>
          {listing.data.map((item) => (
            <tr key={item.id}>
              <td>
                <strong>{item.name}</strong> <Badge tone={item.kind === "allowance" ? "green" : "amber"} label={item.kind === "allowance" ? "Allowance" : "Deduction"} />
                <div className="tiny">{item.kind === "allowance" ? (item.taxable ? "Taxed as pay" : "Not taxed") : (item.taxable ? "After tax" : "Before tax")}{item.notes && ` · ${item.notes}`}</div>
              </td>
              <td>{value(item)}</td>
              <td>{formatDate(item.valid_from)} – {item.valid_to ? formatDate(item.valid_to) : "until changed"}</td>
              <td><Badge status={item.status === "active" ? "active" : item.status === "scheduled" ? "pending" : "ended"} label={item.status === "active" ? "Active" : item.status === "scheduled" ? "Starts later" : "Ended"} /></td>
              <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                {listing.can_manage && item.status !== "ended" && <>
                  <button className="btn" onClick={() => setAction({ type: "edit", item })}>Edit</button>{" "}
                  {item.status === "active" && <><button className="btn" onClick={() => setAction({ type: "change", item })}>Change from a date</button>{" "}<button className="btn" onClick={() => setAction({ type: "end", item })}>End</button></>}
                  {item.status === "scheduled" && <button className="btn danger" onClick={() => void remove(item)}>Remove</button>}
                </>}
              </td>
            </tr>
          ))}
        </Table>
      )}
      {action && <ItemModal employeeId={employeeId} action={action} currency={currency} onClose={() => setAction(null)} onSaved={(message) => { setAction(null); setNotice(message); load(); }} />}
    </Panel>
  );
}
