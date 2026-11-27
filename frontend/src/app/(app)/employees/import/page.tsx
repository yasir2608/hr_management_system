"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { ImportReview, Stepper, type ImportState } from "@/components/employees/ImportReview";
import { Badge, Banner, Field, PageHead, Panel, Table } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";

type Column = { label: string; required: boolean; sensitive: boolean; help: string; example: string };

function ImportPage() {
  const [columns, setColumns] = useState<Record<string, Column>>({});
  const [canSensitive, setCanSensitive] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [state, setState] = useState<ImportState | null>(null);
  const [warnings, setWarnings] = useState<string[]>([]);
  const [error, setError] = useState<ApiError | null>(null);
  const [uploading, setUploading] = useState(false);

  useEffect(() => {
    api<{ columns: Record<string, Column>; can_import_sensitive: boolean }>("/employee-imports/columns").then((result) => {
      setColumns(result.columns);
      setCanSensitive(result.can_import_sensitive);
    });
  }, []);

  async function upload() {
    if (!file) return;
    setUploading(true);
    setError(null);
    const body = new FormData();
    body.append("file", file);
    try {
      const result = await api<ImportState & { warnings: string[] }>("/employee-imports", { body });
      setState(result);
      setWarnings(result.warnings);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Upload failed.", 0));
    } finally {
      setUploading(false);
    }
  }

  return (
    <>
      <PageHead
        eyebrow="People"
        title="Import employees"
        subtitle="Bring employee records in from a spreadsheet. Nothing is saved until you confirm, and only rows without problems are saved."
        actions={<Link className="btn" href="/employees">Back to employees</Link>}
      />
      {state ? (
        <ImportReview initial={state} warnings={warnings} onRestart={() => { setState(null); setFile(null); setWarnings([]); }} />
      ) : (
        <>
          <Stepper current={0} />
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="grid two">
            <Panel title="Upload your spreadsheet" subtitle="CSV or Excel (.xlsx), up to 2,000 people.">
              <div className="stack">
                <div className="callout blue">
                  Start from the template so the columns match. Columns marked * are required; columns marked [sensitive] hold pay, tax or bank details.
                </div>
                <div>
                  <button className="btn soft" onClick={() => void download("/employee-imports/template")}>Download template</button>
                </div>
                {!canSensitive && <div className="callout amber">Your role cannot import pay, tax or bank details. Those columns will be ignored; a payroll administrator can add them later.</div>}
                <Field label="File" error={error?.errors.file?.[0]}>
                  <input className="input" type="file" accept=".csv,.xlsx,.txt" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
                </Field>
                <div><button className="btn primary" disabled={!file || uploading} onClick={() => void upload()}>{uploading ? "Reading file…" : "Upload and check"}</button></div>
              </div>
            </Panel>
            <Panel title="Columns" bodyless>
              <Table headers={["Column", "What to enter"]}>
                {Object.entries(columns).map(([key, column]) => (
                  <tr key={key}>
                    <td>
                      <strong>{column.label}</strong>
                      {column.required && <span className="req"> *</span>}
                      {column.sensitive && <> <Badge tone="amber" label="Sensitive" /></>}
                    </td>
                    <td style={{ whiteSpace: "normal" }} className="tiny">{column.help}</td>
                  </tr>
                ))}
              </Table>
            </Panel>
          </div>
        </>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["employees.import"]}>
      <ImportPage />
    </Guard>
  );
}
