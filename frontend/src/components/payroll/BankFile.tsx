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
export type BankExport = {
  id: string; format_name: string; file_name: string; currency: string; payment_count: number; download_count: number; total: string;
  excluded: Excluded[]; created_by: string | null; created_at: string | null; last_downloaded_at: string | null;
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

/** Bank files already created, with a download button. Used on a run and on the Bank exports page. */
export function BankExportsTable({ exports, showRun, onDownloaded }: { exports: BankExport[]; showRun?: boolean; onDownloaded?: () => void }) {
  const [error, setError] = useState<string | null>(null);

  async function get(item: BankExport) {
    setError(null);
    try {
      await download(`/payroll/bank-exports/${item.id}/download`);
      onDownloaded?.();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not download the file.");
    }
  }

  return (
    <>
      {error && <div style={{ padding: "10px 16px 0" }}><Banner tone="error">{error}</Banner></div>}
      <Table headers={[...(showRun ? ["Payroll run"] : []), "File", "Payments", "Total", "Created", "Downloads", ""]}>
        {exports.map((item) => (
          <tr key={item.id}>
            {showRun && (
              <td>
                <Link href={`/payroll/runs/${item.run.id}`}><strong>{item.run.group ?? "Payroll"}</strong></Link>{item.run.kind !== "regular" && <> <Badge tone="blue" label={item.run.kind === "adjustment" ? "Adjustment" : "Replacement"} /></>}
                <div className="tiny">{formatDate(item.run.period_start)} – {formatDate(item.run.period_end)} · paid {formatDate(item.run.pay_date)}</div>
              </td>
            )}
            <td>{item.format_name}<div className="tiny">{item.file_name}</div></td>
            <td>{item.payment_count}{item.excluded.length > 0 && <div className="tiny">{item.excluded.length} left out</div>}</td>
            <td>{money(item.total, item.currency)}</td>
            <td>{item.created_at ? formatDateTime(item.created_at) : "—"}<div className="tiny">by {item.created_by ?? "—"}</div></td>
            <td>{item.download_count}{item.last_downloaded_at && <div className="tiny">last {formatDateTime(item.last_downloaded_at)}</div>}</td>
            <td style={{ textAlign: "right" }}><button className="btn soft" onClick={() => void get(item)}>Download</button></td>
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
      <BankExportsTable exports={exports} onDownloaded={() => void load()} />
    </Panel>
  );
}
