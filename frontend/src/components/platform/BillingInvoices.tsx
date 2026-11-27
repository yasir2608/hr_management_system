"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, Modal, Panel, Table, formatDate, money } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export type BillingInvoice = {
  id: string; company_id: string; company: string | null; company_subscription_id: string | null; reference: string; amount: string; currency: string;
  notes: string | null; status: "issued" | "void"; void_reason: string | null; period_start: string | null; period_end: string | null;
  issued_on: string; due_on: string; display_status: "issued" | "overdue" | "void"; created_by: string | null;
};
type Suggestion = {
  company_subscription_id: string; plan_name: string; reference: string; period_start: string; period_end: string;
  amount: string; currency: string; issued_on: string; due_on: string; basis: string;
};

const INVOICE_TONES: Record<string, string> = { issued: "blue", overdue: "red", void: "gray" };

export function InvoiceStatus({ status }: { status: string }) {
  return <Badge tone={INVOICE_TONES[status] ?? "gray"} label={status.charAt(0).toUpperCase() + status.slice(1)} />;
}

/** A company's invoices from M.Y HRMS, with create, correct and void for operations staff. */
export function InvoicesPanel({ companyId }: { companyId: string }) {
  const [data, setData] = useState<{ data: BillingInvoice[]; suggestion: Suggestion | null; can_manage: boolean } | null>(null);
  const [editing, setEditing] = useState<BillingInvoice | "new" | null>(null);
  const [voiding, setVoiding] = useState<BillingInvoice | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setData(await api(`/platform/companies/${companyId}/invoices`, { noCompany: true }));
  }, [companyId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!data) return null;
  const done = (message: string) => { setEditing(null); setVoiding(null); setNotice(message); void load(); };

  return (
    <Panel
      title="Invoices from M.Y HRMS"
      subtitle="Subscription invoices raised to this customer. Not payroll, and not contractor invoices."
      right={data.can_manage && <button className="btn soft" onClick={() => setEditing("new")}>New invoice</button>}
      bodyless={data.data.length > 0}
    >
      {notice && <div style={{ padding: "10px 16px 0" }}><Banner tone="success">{notice}</Banner></div>}
      {data.data.length === 0 ? (
        <EmptyState icon="▤" title="No invoices yet">
          {data.suggestion ? `Next suggested: ${formatDate(data.suggestion.period_start)} – ${formatDate(data.suggestion.period_end)}, ${money(data.suggestion.amount, data.suggestion.currency)}.` : "Record an agreement first to get a suggested invoice."}
        </EmptyState>
      ) : (
        <Table headers={["Reference", "Period", "Amount", "Issued", "Due", "Status", ""]}>
          {data.data.map((invoice) => (
            <tr key={invoice.id}>
              <td><strong>{invoice.reference}</strong>{invoice.notes && <div className="tiny">{invoice.notes}</div>}</td>
              <td>{invoice.period_start ? `${formatDate(invoice.period_start)} – ${formatDate(invoice.period_end)}` : "—"}</td>
              <td>{money(invoice.amount, invoice.currency)}</td>
              <td>{formatDate(invoice.issued_on)}</td>
              <td>{formatDate(invoice.due_on)}</td>
              <td><InvoiceStatus status={invoice.display_status} />{invoice.void_reason && <div className="tiny">{invoice.void_reason}</div>}</td>
              <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                {data.can_manage && invoice.status === "issued" && <><button className="btn" onClick={() => setEditing(invoice)}>Correct</button> <button className="btn" onClick={() => setVoiding(invoice)}>Void</button></>}
              </td>
            </tr>
          ))}
        </Table>
      )}
      {editing && <InvoiceForm companyId={companyId} invoice={editing === "new" ? undefined : editing} suggestion={data.suggestion} onClose={() => setEditing(null)} onSaved={() => done(editing === "new" ? "Invoice recorded." : "Invoice corrected.")} />}
      {voiding && <VoidInvoice companyId={companyId} invoice={voiding} onClose={() => setVoiding(null)} onSaved={() => done("Invoice voided.")} />}
    </Panel>
  );
}

function InvoiceForm({ companyId, invoice, suggestion, onClose, onSaved }: { companyId: string; invoice?: BillingInvoice; suggestion: Suggestion | null; onClose: () => void; onSaved: () => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const source = invoice ?? suggestion;
  const [form, setForm] = useState({
    reference: source?.reference ?? "", company_subscription_id: source?.company_subscription_id ?? "",
    period_start: source?.period_start ?? "", period_end: source?.period_end ?? "", amount: source?.amount ?? "", currency: source?.currency ?? "USD",
    issued_on: source?.issued_on ?? today, due_on: source?.due_on ?? today, notes: invoice?.notes ?? "",
  });
  const [error, setError] = useState<ApiError | null>(null);
  const fieldError = (key: string) => error?.errors[key]?.[0];
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch });

  async function save() {
    setError(null);
    try {
      await api(invoice ? `/platform/companies/${companyId}/invoices/${invoice.id}` : `/platform/companies/${companyId}/invoices`, {
        method: invoice ? "PUT" : "POST", noCompany: true,
        body: { ...form, amount: Number(form.amount), company_subscription_id: form.company_subscription_id || null, period_start: form.period_start || null, period_end: form.period_end || null, notes: form.notes || null },
      });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title={invoice ? `Correct ${invoice.reference}` : "New invoice"} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save invoice</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      {!invoice && suggestion && <Banner tone="info">Suggested from {suggestion.plan_name}: {suggestion.basis}. Change anything that differs from what was agreed.</Banner>}
      <div className="formgrid">
        <Field label="Reference" required error={fieldError("reference")}><input className="input" value={form.reference} onChange={(event) => set({ reference: event.target.value })} /></Field>
        <Field label="Amount" required error={fieldError("amount")}><input className="input" type="number" min={0} step="0.01" value={form.amount} onChange={(event) => set({ amount: event.target.value })} /></Field>
        <Field label="Currency" required error={fieldError("currency")}><input className="input" maxLength={3} value={form.currency} onChange={(event) => set({ currency: event.target.value.toUpperCase() })} /></Field>
        <Field label="Period from" optional error={fieldError("period_start")}><input className="input" type="date" value={form.period_start} onChange={(event) => set({ period_start: event.target.value })} /></Field>
        <Field label="Period to" optional error={fieldError("period_end")}><input className="input" type="date" value={form.period_end} onChange={(event) => set({ period_end: event.target.value })} /></Field>
        <Field label="Invoice date" required error={fieldError("issued_on")}><input className="input" type="date" value={form.issued_on} onChange={(event) => set({ issued_on: event.target.value })} /></Field>
        <Field label="Due date" required error={fieldError("due_on")}><input className="input" type="date" value={form.due_on} onChange={(event) => set({ due_on: event.target.value })} /></Field>
        <Field label="Notes" optional full error={fieldError("notes")}><textarea className="input" rows={2} value={form.notes} onChange={(event) => set({ notes: event.target.value })} /></Field>
      </div>
    </Modal>
  );
}

function VoidInvoice({ companyId, invoice, onClose, onSaved }: { companyId: string; invoice: BillingInvoice; onClose: () => void; onSaved: () => void }) {
  const [reason, setReason] = useState("");
  const [error, setError] = useState<ApiError | null>(null);

  async function save() {
    setError(null);
    try {
      await api(`/platform/companies/${companyId}/invoices/${invoice.id}/void`, { method: "POST", noCompany: true, body: { reason } });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not void the invoice.", 0));
    }
  }

  return (
    <Modal title={`Void ${invoice.reference}`} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn danger" onClick={() => void save()}>Void invoice</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <p className="tiny" style={{ marginBottom: 10 }}>The invoice is kept for the record, marked void.</p>
      <Field label="Reason" required error={error?.errors.reason?.[0]}><textarea className="input" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} /></Field>
    </Modal>
  );
}
