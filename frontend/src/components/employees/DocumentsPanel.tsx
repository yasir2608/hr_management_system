"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, formatDate, humanize, Modal, Panel, Table } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";

type DocumentRow = {
  id: string;
  category: string;
  title: string;
  original_name: string;
  size_bytes: number;
  visibility: "employee" | "hr" | "payroll";
  expires_on: string | null;
  created_at: string;
  uploaded_by: string | null;
  can_delete: boolean;
};
type Listing = { data: DocumentRow[]; can_upload: boolean; upload_visibilities: string[]; categories: string[] };

const visibilityLabel: Record<string, string> = { employee: "Employee can see", hr: "HR only", payroll: "Payroll only" };

function size(bytes: number) {
  return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function DocumentsPanel({ employeeId }: { employeeId: string }) {
  const [listing, setListing] = useState<Listing | null>(null);
  const [uploading, setUploading] = useState(false);
  const [form, setForm] = useState({ title: "", category: "contract", visibility: "hr", expires_on: "" });
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setListing(await api<Listing>(`/employees/${employeeId}/documents`));
  }, [employeeId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function upload() {
    if (!file) return;
    setError(null);
    const body = new FormData();
    body.append("file", file);
    Object.entries(form).forEach(([key, value]) => value && body.append(key, value));
    try {
      const result = await api<{ message: string }>(`/employees/${employeeId}/documents`, { body });
      setNotice(result.message);
      setUploading(false);
      setFile(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Upload failed.", 0));
    }
  }

  async function remove(id: string) {
    if (!window.confirm("Remove this document? The file is deleted.")) return;
    const result = await api<{ message: string }>(`/employees/${employeeId}/documents/${id}`, { method: "DELETE" });
    setNotice(result.message);
    await load();
  }

  if (!listing) return <div className="empty">Loading…</div>;

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel
        title="Documents"
        subtitle="Files are stored privately and every download is recorded."
        right={listing.can_upload && <button className="btn primary" onClick={() => { setError(null); setForm({ ...form, visibility: listing.upload_visibilities[0] ?? "hr" }); setUploading(true); }}>Upload document</button>}
        bodyless
      >
        {listing.data.length === 0 ? (
          <EmptyState icon="▤" title="No documents you can see" />
        ) : (
          <Table headers={["Document", "Category", "Who can see it", "Expires", "Added", ""]}>
            {listing.data.map((document) => (
              <tr key={document.id}>
                <td><strong>{document.title}</strong><div className="tiny">{document.original_name} · {size(document.size_bytes)}</div></td>
                <td>{humanize(document.category)}</td>
                <td><Badge tone={document.visibility === "payroll" ? "amber" : document.visibility === "hr" ? "blue" : "green"} label={visibilityLabel[document.visibility]} /></td>
                <td>{document.expires_on ? formatDate(document.expires_on) : "—"}</td>
                <td>{formatDate(document.created_at)}<div className="tiny">{document.uploaded_by}</div></td>
                <td>
                  <div className="row" style={{ justifyContent: "flex-end" }}>
                    <button className="btn" onClick={() => void download(`/employees/${employeeId}/documents/${document.id}/download`)}>Download</button>
                    {document.can_delete && <button className="btn danger" onClick={() => void remove(document.id)}>Remove</button>}
                  </div>
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {uploading && (
        <Modal title="Upload document" onClose={() => setUploading(false)} footer={<><button className="btn" onClick={() => setUploading(false)}>Cancel</button><button className="btn primary" disabled={!file} onClick={() => void upload()}>Upload</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            <Field label="File" required full error={error?.errors.file?.[0]} hint="PDF, image, Word, Excel, CSV or text. Up to 10 MB.">
              <input className="input" type="file" onChange={(event) => { const chosen = event.target.files?.[0] ?? null; setFile(chosen); if (chosen && !form.title) setForm({ ...form, title: chosen.name.replace(/\.[^.]+$/, "") }); }} />
            </Field>
            <Field label="Title" required error={error?.errors.title?.[0]}><input className="input" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} /></Field>
            <Field label="Category">
              <select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}>
                {listing.categories.map((category) => <option key={category} value={category}>{humanize(category)}</option>)}
              </select>
            </Field>
            <Field label="Who can see it" error={error?.errors.visibility?.[0]}>
              <select value={form.visibility} onChange={(event) => setForm({ ...form, visibility: event.target.value })}>
                {listing.upload_visibilities.map((visibility) => <option key={visibility} value={visibility}>{visibilityLabel[visibility]}</option>)}
              </select>
            </Field>
            <Field label="Expires on" optional error={error?.errors.expires_on?.[0]} hint="e.g. right-to-work documents.">
              <input className="input" type="date" value={form.expires_on} onChange={(event) => setForm({ ...form, expires_on: event.target.value })} />
            </Field>
          </div>
        </Modal>
      )}
    </>
  );
}
