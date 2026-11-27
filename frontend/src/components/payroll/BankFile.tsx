"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, Modal, Panel, Table, formatDate, formatDateTime, money } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";

type FormatOption = { id: string; name: string; template_name: string; is_default: boolean };
type Excluded = { employee_id: string; name: string; reason: string };
type Preview = {
  notice: string; format_id: string | null; formats: FormatOption[];
  payments?: { employee_id: string; name: string; employee_number: string | null; bank_name: string | null; account: string; currency: string; amount: string }[];
  excluded?: Excluded[]; warnings?: string[]; payment_count?: number; total?: string; currency?: string;
};
type PaymentStatus = "not_recorded" | "paid" | "failed";
type Payment = { status: PaymentStatus; paid_on: string | null; reference: string | null; note: string | null; recorded_by: string | null; recorded_at: string | null };
export type BankExport = {
  id: string; format_name: string; file_name: string; currency: string; payment_count: number; download_count: number; total: string;
  excluded: Excluded[]; created_by: string | null; created_at: string | null; last_downloaded_at: string | null; payment: Payment;
  run: { id: string; kind: string; group: string | null; period_start: string; period_end: string; pay_date: string };
};

/** Choose a format, check the masked preview and totals, confirm, then create and download the bank file. */
export function BankFileModal({ runId, onClose, onCreated }: { runId: string; onClose: () => void; onCreated: (message: string) => void }) {
  const [formatId, setFormatId] = useState<string | null>(null);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (id: string | null) => {
    setError(null);
    try {
      const result = await api<Preview>(`/payroll/runs/${runId}/bank-file`, { query: id ? { bank_format_id: id } : undefined });
      setPreview(result);
      setFormatId(result.format_id);
      setConfirmed(false);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not prepare the preview.");
    }
  }, [runId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load(null);
  }, [load]);

  async function create() {
    if (!preview || !formatId) return;
    setBusy(true);
    setError(null);
    try {
      const created = await api<BankExport>(`/payroll/runs/${runId}/bank-exports`, {
        method: "POST", body: { bank_format_id: formatId, confirmed, payment_count: preview.payment_count, total: preview.total },
      });
      await download(`/payroll/bank-exports/${created.id}/download`);
      onCreated(`Bank file created and downloaded: ${created.payment_count} payments, ${money(created.total, created.currency)}. Upload it to your bank to make the payments.`);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not create the bank file.");
      if (caught instanceof ApiError && caught.status === 409) void load(formatId);
    } finally {
      setBusy(false);
    }
  }

  const canCreate = !!preview && !!formatId && (preview.payment_count ?? 0) > 0 && confirmed && !busy;

  return (
    <Modal
      title="Create bank file"
      onClose={onClose}
      footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={!canCreate} onClick={() => void create()}>{busy ? "Creating…" : "Create and download"}</button></>}
    >
      {error && <Banner tone="error">{error}</Banner>}
      {!preview ? <div className="muted">Preparing the preview…</div> : (
        <>
          <Banner tone="info">{preview.notice}</Banner>
          {preview.formats.length === 0 ? (
            <EmptyState icon="⇄" title="No bank format set up">Set up a bank format for this country under <Link className="link" href="/payroll/bank-formats">Bank formats</Link> first.</EmptyState>
          ) : (
            <>
              <Field label="Bank format" required>
                <select value={formatId ?? ""} onChange={(event) => void load(event.target.value)}>
                  {preview.formats.map((option) => <option key={option.id} value={option.id}>{option.name} ({option.template_name}){option.is_default ? " · default" : ""}</option>)}
                </select>
              </Field>
              {preview.warnings?.map((warning) => <Banner key={warning} tone="error">{warning}</Banner>)}
              <div style={{ display: "flex", gap: 24, margin: "12px 0" }}>
                <div><div className="tiny">Payments</div><strong style={{ fontSize: 20 }}>{preview.payment_count}</strong></div>
                <div><div className="tiny">Total amount</div><strong style={{ fontSize: 20 }}>{money(preview.total, preview.currency)}</strong></div>
                <div><div className="tiny">Left out</div><strong style={{ fontSize: 20 }}>{preview.excluded?.length ?? 0}</strong></div>
              </div>
              {(preview.payments?.length ?? 0) > 0 && (
                <Table headers={["Employee", "Bank", "Account", "Currency", "Amount"]}>
                  {preview.payments!.map((payment) => (
                    <tr key={payment.employee_id}>
                      <td>{payment.name}{payment.employee_number && <div className="tiny">{payment.employee_number}</div>}</td>
                      <td>{payment.bank_name ?? "—"}</td>
                      <td><code>{payment.account}</code></td>
                      <td>{payment.currency}</td>
                      <td style={{ textAlign: "right" }}>{money(payment.amount, payment.currency)}</td>
                    </tr>
                  ))}
                </Table>
              )}
              {(preview.excluded?.length ?? 0) > 0 && (
                <>
                  <h3 style={{ margin: "14px 0 6px", fontSize: 14 }}>Not in this file</h3>
                  <Table headers={["Employee", "Why"]}>
                    {preview.excluded!.map((row) => (
                      <tr key={row.employee_id}><td><Link className="link" href={`/payroll/employees/${row.employee_id}`}>{row.name}</Link></td><td>{row.reason}</td></tr>
                    ))}
                  </Table>
                  <p className="tiny" style={{ marginTop: 6 }}>Pay these employees another way, or fix their bank details and create the file again.</p>
                </>
              )}
              {(preview.payment_count ?? 0) > 0 && (
                <label className="checkrow" style={{ marginTop: 14 }}>
                  <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                  I have checked these payments and understand this file does not send money.
                </label>
              )}
            </>
          )}
        </>
      )}
    </Modal>
  );
}

const PAYMENT_LABELS: Record<PaymentStatus, { label: string; tone: string }> = {
  not_recorded: { label: "Not recorded", tone: "gray" }, paid: { label: "Paid", tone: "green" }, failed: { label: "Failed", tone: "red" },
};

function PaymentCell({ payment }: { payment: Payment }) {
  const { label, tone } = PAYMENT_LABELS[payment.status];
  return (
    <>
      <Badge tone={tone} label={label} />
      {payment.paid_on && <div className="tiny">on {formatDate(payment.paid_on)}</div>}
      {payment.reference && <div className="tiny">Ref. {payment.reference}</div>}
      {payment.note && <div className="tiny">{payment.note}</div>}
      {payment.recorded_by && <div className="tiny">recorded by {payment.recorded_by}</div>}
    </>
  );
}

/** Record what the bank did with an uploaded file. A record only: M.Y HRMS never sends or checks payments. */
function RecordPaymentModal({ item, onClose, onSaved }: { item: BankExport; onClose: () => void; onSaved: () => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [status, setStatus] = useState<PaymentStatus>(item.payment.status === "not_recorded" ? "paid" : item.payment.status);
  const payDate = item.run.pay_date.slice(0, 10);
  const [paidOn, setPaidOn] = useState(item.payment.paid_on ?? (payDate <= today ? payDate : today));
  const [reference, setReference] = useState(item.payment.reference ?? "");
  const [note, setNote] = useState(item.payment.note ?? "");
  const [error, setError] = useState<ApiError | null>(null);
  const fieldError = (key: string) => error?.errors[key]?.[0];

  async function save() {
    setError(null);
    try {
      await api(`/payroll/bank-exports/${item.id}/payment`, {
        method: "POST",
        body: { status, paid_on: status === "paid" ? paidOn : null, reference: status === "not_recorded" ? null : reference || null, note: status === "not_recorded" ? null : note || null },
      });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title="Record payment" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <p className="tiny" style={{ marginBottom: 12 }}>
        {item.format_name} · {item.payment_count} payments · {money(item.total, item.currency)}. Record what your bank reports; this does not send or confirm any payment.
      </p>
      <div className="formgrid">
        <Field label="Status" required full error={fieldError("status")}>
          <label className="checkrow"><input type="radio" checked={status === "paid"} onChange={() => setStatus("paid")} /> Paid by the bank</label>
          <label className="checkrow"><input type="radio" checked={status === "failed"} onChange={() => setStatus("failed")} /> Failed or rejected by the bank</label>
          {item.payment.status !== "not_recorded" && <label className="checkrow"><input type="radio" checked={status === "not_recorded"} onChange={() => setStatus("not_recorded")} /> Not recorded (clear this record)</label>}
        </Field>
        {status === "paid" && <Field label="Payment date" required error={fieldError("paid_on")}><input className="input" type="date" value={paidOn} max={today} onChange={(event) => setPaidOn(event.target.value)} /></Field>}
        {status !== "not_recorded" && <Field label="Payment reference" optional error={fieldError("reference")} hint="The bank's batch or payment reference, if you have one."><input className="input" value={reference} maxLength={100} onChange={(event) => setReference(event.target.value)} /></Field>}
        {status !== "not_recorded" && <Field label={status === "failed" ? "What went wrong" : "Note"} required={status === "failed"} optional={status !== "failed"} full error={fieldError("note")}><textarea className="input" rows={2} value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} /></Field>}
      </div>
    </Modal>
  );
}

/** Bank files already created, with download and payment recording. Used on a run and on the Bank exports page. */
export function BankExportsTable({ exports, showRun, onChange }: { exports: BankExport[]; showRun?: boolean; onChange?: (message?: string) => void }) {
  const [error, setError] = useState<string | null>(null);
  const [recording, setRecording] = useState<BankExport | null>(null);

  async function get(item: BankExport) {
    setError(null);
    try {
      await download(`/payroll/bank-exports/${item.id}/download`);
      onChange?.();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not download the file.");
    }
  }

  return (
    <>
      {error && <div style={{ padding: "10px 16px 0" }}><Banner tone="error">{error}</Banner></div>}
      {recording && <RecordPaymentModal item={recording} onClose={() => setRecording(null)} onSaved={() => { setRecording(null); onChange?.("Payment record saved."); }} />}
      <Table headers={[...(showRun ? ["Payroll run"] : []), "File", "Payments", "Total", "Created", "Downloads", "Payment", ""]}>
        {exports.map((item) => (
          <tr key={item.id}>
            {showRun && (
              <td>
                <Link href={`/payroll/runs/${item.run.id}`}><strong>{item.run.group ?? "Payroll"}</strong></Link>{item.run.kind !== "regular" && <> <Badge tone="blue" label={item.run.kind === "adjustment" ? "Adjustment" : "Replacement"} /></>}
                <div className="tiny">{formatDate(item.run.period_start)} – {formatDate(item.run.period_end)} · pay date {formatDate(item.run.pay_date)}</div>
              </td>
            )}
            <td>{item.format_name}<div className="tiny">{item.file_name}</div></td>
            <td>{item.payment_count}{item.excluded.length > 0 && <div className="tiny">{item.excluded.length} left out</div>}</td>
            <td>{money(item.total, item.currency)}</td>
            <td>{item.created_at ? formatDateTime(item.created_at) : "—"}<div className="tiny">by {item.created_by ?? "—"}</div></td>
            <td>{item.download_count}{item.last_downloaded_at && <div className="tiny">last {formatDateTime(item.last_downloaded_at)}</div>}</td>
            <td><PaymentCell payment={item.payment} /></td>
            <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
              <button className="btn soft" onClick={() => void get(item)}>Download</button>{" "}
              <button className="btn" onClick={() => setRecording(item)}>{item.payment.status === "not_recorded" ? "Record payment" : "Update"}</button>
            </td>
          </tr>
        ))}
      </Table>
    </>
  );
}

/** A run's bank files, shown on an approved run to people who can export. */
export function RunBankExportsPanel({ runId, version }: { runId: string; version: number }) {
  const [exports, setExports] = useState<BankExport[] | null>(null);

  const load = useCallback(async () => {
    setExports((await api<{ data: BankExport[] }>("/payroll/bank-exports", { query: { run: runId } })).data);
  }, [runId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch on change
    void load();
  }, [load, version]);

  if (!exports || exports.length === 0) return null;

  return (
    <Panel title="Bank files" subtitle="Files created from this run. Creating a file does not send money." bodyless>
      <BankExportsTable exports={exports} onChange={() => void load()} />
    </Panel>
  );
}
