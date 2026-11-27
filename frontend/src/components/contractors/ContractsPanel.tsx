"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, Modal, Panel, Table, formatDate, humanize, money } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";

export type Contract = {
  id: string;
  title: string;
  start_date: string;
  end_date: string | null;
  renewal_date: string | null;
  notice_days: number | null;
  rate_type: "hourly" | "daily" | "monthly" | "fixed_fee";
  amount: string;
  currency: string;
  status: "draft" | "active" | "ended" | "terminated";
  notes: string | null;
  has_signed_document: boolean;
};

const RATE_LABELS: Record<Contract["rate_type"], string> = { hourly: "per hour", daily: "per day", monthly: "per month", fixed_fee: "fixed fee" };

type Draft = Omit<Contract, "id" | "has_signed_document" | "notice_days" | "end_date" | "renewal_date" | "notes"> & { end_date: string; renewal_date: string; notice_days: string; notes: string };

function toDraft(contract?: Contract): Draft {
  return {
    title: contract?.title ?? "",
    start_date: contract?.start_date ?? new Date().toISOString().slice(0, 10),
    end_date: contract?.end_date ?? "",
    renewal_date: contract?.renewal_date ?? "",
    notice_days: contract?.notice_days?.toString() ?? "",
    rate_type: contract?.rate_type ?? "monthly",
    amount: contract?.amount ?? "",
    currency: contract?.currency ?? "GBP",
    status: contract?.status ?? "draft",
    notes: contract?.notes ?? "",
  };
}

function ContractModal({ contractorId, contract, onClose, onSaved }: { contractorId: string; contract?: Contract; onClose: () => void; onSaved: () => void }) {
  const [draft, setDraft] = useState<Draft>(toDraft(contract));
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const set = (patch: Partial<Draft>) => setDraft({ ...draft, ...patch });
  const fieldError = (key: string) => error?.errors[key]?.[0];

  async function save() {
    setError(null);
    const body = new FormData();
    Object.entries(draft).forEach(([key, value]) => value !== "" && body.append(key, value));
    if (file) body.append("signed_document", file);
    try {
      await api(`/contractors/${contractorId}/contracts${contract ? `/${contract.id}` : ""}`, { method: "POST", body });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title={contract ? "Edit contract" : "Add contract"} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save contract</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="Title" required full error={fieldError("title")}><input className="input" value={draft.title} onChange={(event) => set({ title: event.target.value })} placeholder="e.g. Design retainer 2026" /></Field>
        <Field label="Start date" required error={fieldError("start_date")}><input className="input" type="date" value={draft.start_date} onChange={(event) => set({ start_date: event.target.value })} /></Field>
        <Field label="End date" optional hint="Leave blank if open-ended" error={fieldError("end_date")}><input className="input" type="date" value={draft.end_date} onChange={(event) => set({ end_date: event.target.value })} /></Field>
        <Field label="Renewal or review date" optional error={fieldError("renewal_date")}><input className="input" type="date" value={draft.renewal_date} onChange={(event) => set({ renewal_date: event.target.value })} /></Field>
        <Field label="Notice period (days)" optional error={fieldError("notice_days")}><input className="input" type="number" min={0} value={draft.notice_days} onChange={(event) => set({ notice_days: event.target.value })} /></Field>
        <Field label="Rate type" required error={fieldError("rate_type")}>
          <select value={draft.rate_type} onChange={(event) => set({ rate_type: event.target.value as Draft["rate_type"] })}>
            <option value="hourly">Hourly</option>
            <option value="daily">Daily</option>
            <option value="monthly">Monthly</option>
            <option value="fixed_fee">Fixed fee</option>
          </select>
        </Field>
        <Field label="Rate or fee" required error={fieldError("amount")}><input className="input" type="number" min={0} step="0.01" value={draft.amount} onChange={(event) => set({ amount: event.target.value })} /></Field>
        <Field label="Currency" required error={fieldError("currency")}><input className="input" maxLength={3} value={draft.currency} onChange={(event) => set({ currency: event.target.value.toUpperCase() })} /></Field>
        <Field label="Status" required error={fieldError("status")}>
          <select value={draft.status} onChange={(event) => set({ status: event.target.value as Draft["status"] })}>
            <option value="draft">Draft</option>
            <option value="active">Active</option>
            <option value="ended">Ended</option>
            <option value="terminated">Terminated</option>
          </select>
        </Field>
        <Field label="Signed document" optional full hint={contract?.has_signed_document ? "A signed copy is on file; uploading replaces it." : "PDF, image or Word, up to 10 MB"} error={fieldError("signed_document")}>
          <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        </Field>
        <Field label="Notes" optional full><textarea className="input" rows={3} value={draft.notes} onChange={(event) => set({ notes: event.target.value })} /></Field>
      </div>
    </Modal>
  );
}

/** Contracts held with one contractor: dates, rate, currency, status and the signed copy. */
export function ContractsPanel({ contractorId, canManage }: { contractorId: string; canManage: boolean }) {
  const [contracts, setContracts] = useState<Contract[] | null>(null);
  const [editing, setEditing] = useState<Contract | "new" | null>(null);

  const load = useCallback(() => {
    api<{ data: Contract[] }>(`/contractors/${contractorId}/contracts`).then((result) => setContracts(result.data));
  }, [contractorId]);

  useEffect(load, [load]);

  return (
    <Panel title="Contracts" subtitle="Signed copies are stored privately and every download is recorded." right={canManage && <button className="btn soft" onClick={() => setEditing("new")}>Add contract</button>}>
      {!contracts ? <span className="muted">Loading…</span> : contracts.length === 0 ? <span className="muted">No contracts yet.</span> : (
        <Table headers={["Contract", "Dates", "Rate", "Status", ""]}>
          {contracts.map((contract) => (
            <tr key={contract.id}>
              <td><strong>{contract.title}</strong>{contract.notice_days !== null && <div className="tiny">{contract.notice_days} days notice</div>}</td>
              <td>{formatDate(contract.start_date)} – {contract.end_date ? formatDate(contract.end_date) : "open-ended"}{contract.renewal_date && <div className="tiny">Review {formatDate(contract.renewal_date)}</div>}</td>
              <td>{money(contract.amount, contract.currency)} <span className="tiny">{RATE_LABELS[contract.rate_type]}</span></td>
              <td><Badge status={contract.status} label={humanize(contract.status)} /></td>
              <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                {contract.has_signed_document && <button className="btn" onClick={() => void download(`/contractors/${contractorId}/contracts/${contract.id}/signed`)}>Signed copy</button>}{" "}
                {canManage && <button className="btn" onClick={() => setEditing(contract)}>Edit</button>}
              </td>
            </tr>
          ))}
        </Table>
      )}
      {editing && <ContractModal contractorId={contractorId} contract={editing === "new" ? undefined : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </Panel>
  );
}
