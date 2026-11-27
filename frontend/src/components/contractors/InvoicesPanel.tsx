"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, Modal, Panel, Table, formatDate, humanize, money } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";
import type { Contract } from "./ContractsPanel";

export type Invoice = {
  id: string;
  contractor_id: string;
  contract_id: string | null;
  contract: { id: string; title: string } | null;
  reference: string;
  issue_date: string;
  due_date: string | null;
  currency: string;
  amount: string;
  description: string | null;
  status: string;
  display_status: string;
  is_overdue: boolean;
  can_edit: boolean;
  moves: string[];
  review: { by: string | null; at: string; reason: string | null } | null;
  has_attachment: boolean;
};

export const MOVE_LABELS: Record<string, string> = { received: "Mark received", pending_review: "Send for review", draft: "Back to draft" };

type Draft = { status: string; reference: string; issue_date: string; due_date: string; currency: string; amount: string; description: string; contract_id: string };

function InvoiceModal({ contractorId, invoice, contracts, onClose, onSaved }: { contractorId: string; invoice?: Invoice; contracts: Contract[]; onClose: () => void; onSaved: () => void }) {
  const active = contracts.find((contract) => contract.status === "active");
  const [draft, setDraft] = useState<Draft>({
    status: invoice ? "" : "received",
    reference: invoice?.reference ?? "",
    issue_date: invoice?.issue_date ?? new Date().toISOString().slice(0, 10),
    due_date: invoice?.due_date ?? "",
    currency: invoice?.currency ?? active?.currency ?? "GBP",
    amount: invoice?.amount ?? "",
    description: invoice?.description ?? "",
    contract_id: invoice ? (invoice.contract_id ?? "") : (active?.id ?? ""),
  });
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const set = (patch: Partial<Draft>) => setDraft({ ...draft, ...patch });
  const fieldError = (key: string) => error?.errors[key]?.[0];

  async function save() {
    setError(null);
    const body = new FormData();
    Object.entries(draft).forEach(([key, value]) => value !== "" && body.append(key, value));
    if (file) body.append("attachment", file);
    try {
      await api(`/contractors/${contractorId}/invoices${invoice ? `/${invoice.id}` : ""}`, { method: "POST", body });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title={invoice ? `Edit invoice ${invoice.reference}` : "Enter invoice"} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save invoice</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="Invoice reference" required error={fieldError("reference")} hint="The contractor’s own invoice number"><input className="input" value={draft.reference} onChange={(event) => set({ reference: event.target.value })} /></Field>
        <Field label="Contract" optional error={fieldError("contract_id")}>
          <select value={draft.contract_id} onChange={(event) => set({ contract_id: event.target.value })}>
            <option value="">Not linked to a contract</option>
            {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.title}</option>)}
          </select>
        </Field>
        <Field label="Invoice date" required error={fieldError("issue_date")}><input className="input" type="date" value={draft.issue_date} onChange={(event) => set({ issue_date: event.target.value })} /></Field>
        <Field label="Due date" optional error={fieldError("due_date")}><input className="input" type="date" value={draft.due_date} onChange={(event) => set({ due_date: event.target.value })} /></Field>
        <Field label="Amount" required error={fieldError("amount")}><input className="input" type="number" min={0} step="0.01" value={draft.amount} onChange={(event) => set({ amount: event.target.value })} /></Field>
        <Field label="Currency" required error={fieldError("currency")}><input className="input" maxLength={3} value={draft.currency} onChange={(event) => set({ currency: event.target.value.toUpperCase() })} /></Field>
        {!invoice && (
          <Field label="Status" required>
            <select value={draft.status} onChange={(event) => set({ status: event.target.value })}>
              <option value="draft">Draft</option>
              <option value="received">Received</option>
              <option value="pending_review">Pending review</option>
            </select>
          </Field>
        )}
        <Field label="Description" optional full><textarea className="input" rows={3} value={draft.description} onChange={(event) => set({ description: event.target.value })} /></Field>
        <Field label="Attachment" optional full error={fieldError("attachment")} hint={invoice?.has_attachment ? "A copy is on file; uploading replaces it." : "PDF, image or Word, up to 10 MB"}>
          <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        </Field>
      </div>
    </Modal>
  );
}

/** Invoices received from one contractor. Paid outside payroll. */
export function InvoicesPanel({ contractorId, canManage, canApprove }: { contractorId: string; canManage: boolean; canApprove: boolean }) {
  const [invoices, setInvoices] = useState<Invoice[] | null>(null);
  const [contracts, setContracts] = useState<Contract[]>([]);
  const [editing, setEditing] = useState<Invoice | "new" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<Invoice | null>(null);
  const [reason, setReason] = useState("");

  const load = useCallback(() => {
    api<{ data: Invoice[] }>(`/contractors/${contractorId}/invoices`).then((result) => setInvoices(result.data));
    api<{ data: Contract[] }>(`/contractors/${contractorId}/contracts`).then((result) => setContracts(result.data));
  }, [contractorId]);

  useEffect(load, [load]);

  async function move(invoice: Invoice, status: string) {
    setError(null);
    try {
      await api(`/contractors/${contractorId}/invoices/${invoice.id}/status`, { method: "POST", body: { status } });
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not change the status.");
    }
  }

  async function review(invoice: Invoice, decision: "approve" | "reject", note?: string) {
    setError(null);
    try {
      await api(`/contractors/${contractorId}/invoices/${invoice.id}/review`, { method: "POST", body: { decision, reason: note } });
      setRejecting(null);
      setReason("");
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not record the decision.");
    }
  }

  return (
    <Panel title="Invoices" subtitle="Contractor invoices are paid outside payroll." right={canManage && <button className="btn soft" onClick={() => setEditing("new")}>Enter invoice</button>}>
      {error && <Banner tone="error">{error}</Banner>}
      {!invoices ? <span className="muted">Loading…</span> : invoices.length === 0 ? <span className="muted">No invoices yet.</span> : (
        <Table headers={["Reference", "Dates", "Amount", "Status", ""]}>
          {invoices.map((invoice) => (
            <tr key={invoice.id}>
              <td><strong>{invoice.reference}</strong>{invoice.contract && <div className="tiny">{invoice.contract.title}</div>}{invoice.description && <div className="tiny">{invoice.description}</div>}</td>
              <td>{formatDate(invoice.issue_date)}{invoice.due_date && <div className="tiny">Due {formatDate(invoice.due_date)}</div>}</td>
              <td>{money(invoice.amount, invoice.currency)}</td>
              <td><Badge status={invoice.display_status} label={humanize(invoice.display_status)} />{invoice.is_overdue && <div className="tiny">{humanize(invoice.status)}</div>}{invoice.review && <div className="tiny">{humanize(invoice.status)} by {invoice.review.by ?? "someone"}{invoice.review.reason && `: ${invoice.review.reason}`}</div>}</td>
              <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                {invoice.has_attachment && <button className="btn" onClick={() => void download(`/contractors/${contractorId}/invoices/${invoice.id}/attachment`)}>Attachment</button>}{" "}
                {canManage && invoice.moves.map((status) => <button key={status} className="btn" onClick={() => void move(invoice, status)}>{MOVE_LABELS[status] ?? humanize(status)}</button>)}{" "}
                {canManage && invoice.can_edit && <button className="btn" onClick={() => setEditing(invoice)}>Edit</button>}{" "}
                {canApprove && invoice.status === "pending_review" && <><button className="btn primary" onClick={() => void review(invoice, "approve")}>Approve</button> <button className="btn danger" onClick={() => setRejecting(invoice)}>Reject</button></>}
              </td>
            </tr>
          ))}
        </Table>
      )}
      {rejecting && (
        <Modal title={`Reject invoice ${rejecting.reference}`} onClose={() => setRejecting(null)} footer={<><button className="btn" onClick={() => setRejecting(null)}>Cancel</button><button className="btn danger" disabled={!reason.trim()} onClick={() => void review(rejecting, "reject", reason)}>Reject invoice</button></>}>
          <Field label="Reason" required hint="Recorded on the invoice and in the activity log, so the contractor knows what to fix.">
            <textarea className="input" rows={3} value={reason} onChange={(event) => setReason(event.target.value)} />
          </Field>
        </Modal>
      )}
      {editing && <InvoiceModal contractorId={contractorId} invoice={editing === "new" ? undefined : editing} contracts={contracts} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </Panel>
  );
}
