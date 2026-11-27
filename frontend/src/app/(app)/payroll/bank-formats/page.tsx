"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Field, Modal, PageHead, Panel, Table, formatDateTime } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type SettingField = { key: string; label: string; required: boolean; max: number | null; sensitive: boolean; hint: string | null; default: string | null };
type Template = { key: string; name: string; description: string; file_type: string; employee_details: string[]; countries: string[]; settings: SettingField[] };
type Missing = { id: string; name: string; problems: string[] };
type BankFormat = {
  id: string; name: string; template: string; template_name: string; file_type: string; country_code: string; country_name: string;
  is_default: boolean; is_active: boolean; updated_at: string | null;
  settings: { key: string; label: string; sensitive: boolean; value: string | null }[];
  readiness: { employees: number; missing_count: number; missing: Missing[] };
};
type Country = { country_code: string; country_name: string };
type Data = { countries: Country[]; templates: Template[]; formats: BankFormat[]; can_manage: boolean };

function BankFormatsPage() {
  const [data, setData] = useState<Data | null>(null);
  const [editing, setEditing] = useState<BankFormat | "new" | null>(null);
  const [missingFor, setMissingFor] = useState<BankFormat | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setData(await api<Data>("/payroll/bank-formats"));
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function remove(format: BankFormat) {
    if (!window.confirm(`Remove the bank format “${format.name}”? Its saved company details are deleted.`)) return;
    setError(null);
    try {
      const result = await api<{ message: string }>(`/payroll/bank-formats/${format.id}`, { method: "DELETE" });
      setNotice(result.message);
      void load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not remove the format.");
    }
  }

  if (!data) return <div className="loadingpage">Loading…</div>;

  return (
    <>
      <PageHead
        eyebrow="Payroll"
        title="Bank formats"
        subtitle="The bank file formats your company uses to pay salaries. Bank files are created from approved payroll runs for you to upload to your bank."
        actions={data.can_manage && data.templates.length > 0 && <button className="btn primary" onClick={() => setEditing("new")}>Set up a format</button>}
      />
      <Banner tone="info">Creating a bank file does not send money. You upload the file to your bank and approve the payment there.</Banner>
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}

      {data.countries.length === 0 ? (
        <Panel><EmptyState title="No payroll countries yet">Choose the countries you employ people in under <Link className="link" href="/settings/company">Company setup</Link>.</EmptyState></Panel>
      ) : (
        <Panel title="Your formats" bodyless={data.formats.length > 0}>
          {data.formats.length === 0 ? (
            <EmptyState icon="⇄" title="No bank formats yet">{data.can_manage ? "Set up the format your bank accepts before creating a bank file." : "A payroll administrator needs to set up a bank format before bank files can be created."}</EmptyState>
          ) : (
            <Table headers={["Format", "Country", "Company details", "Employee bank details", ""]}>
              {data.formats.map((format) => (
                <tr key={format.id}>
                  <td>
                    <strong>{format.name}</strong>{" "}
                    {format.is_default && <Badge tone="blue" label="Default" />}{" "}
                    {!format.is_active && <Badge tone="gray" label="Inactive" />}
                    <div className="tiny">{format.template_name} · .{format.file_type}</div>
                    {format.updated_at && <div className="tiny">Updated {formatDateTime(format.updated_at)}</div>}
                  </td>
                  <td>{format.country_name}</td>
                  <td>
                    {format.settings.filter((setting) => setting.value).map((setting) => (
                      <div key={setting.key} className="tiny"><span>{setting.label}:</span> <span style={{ color: "var(--ink, inherit)" }}>{setting.value}</span></div>
                    ))}
                  </td>
                  <td>
                    {format.readiness.employees === 0 ? <span className="muted">No employees in payroll groups yet</span>
                      : format.readiness.missing_count === 0 ? <Badge tone="green" label={`All ${format.readiness.employees} complete`} />
                      : <><Badge tone="amber" label={`${format.readiness.missing_count} of ${format.readiness.employees} incomplete`} /> <button className="link" onClick={() => setMissingFor(format)}>See who</button></>}
                  </td>
                  <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                    {data.can_manage && <><button className="btn soft" onClick={() => setEditing(format)}>Edit</button> <button className="btn" onClick={() => void remove(format)}>Remove</button></>}
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
      )}

      {data.templates.length > 0 && (
        <Panel title="Supported formats" subtitle="Only these formats can be set up. If your bank needs a different one, it is not available yet.">
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(260px, 1fr))", gap: 14 }}>
            {data.templates.map((template) => (
              <div key={template.key} style={{ border: "1px solid var(--line, #e3e8ef)", borderRadius: 10, padding: 14 }}>
                <strong>{template.name}</strong>
                <div className="tiny" style={{ margin: "4px 0 8px" }}>{template.countries.map((code) => data.countries.find((country) => country.country_code === code)?.country_name ?? code).join(", ")} · .{template.file_type}</div>
                <p className="tiny" style={{ marginBottom: 8 }}>{template.description}</p>
                <div className="tiny"><strong>Each employee needs:</strong> {template.employee_details.join(", ")}</div>
              </div>
            ))}
          </div>
        </Panel>
      )}

      {editing && (
        <FormatForm
          data={data}
          format={editing === "new" ? undefined : editing}
          onClose={() => setEditing(null)}
          onSaved={(message) => { setEditing(null); setNotice(message); void load(); }}
        />
      )}
      {missingFor && (
        <Modal title={`Incomplete bank details · ${missingFor.name}`} onClose={() => setMissingFor(null)} footer={<button className="btn" onClick={() => setMissingFor(null)}>Close</button>}>
          <p className="tiny" style={{ marginBottom: 10 }}>These employees will be left out of a {missingFor.template_name} file until their bank details are completed on their payroll profile.</p>
          <Table headers={["Employee", "What is needed"]}>
            {missingFor.readiness.missing.map((row) => (
              <tr key={row.id}>
                <td><Link className="link" href={`/payroll/employees/${row.id}`}>{row.name}</Link></td>
                <td>{row.problems.map((problem) => <div key={problem}>{problem}</div>)}</td>
              </tr>
            ))}
          </Table>
        </Modal>
      )}
    </>
  );
}

function FormatForm({ data, format, onClose, onSaved }: { data: Data; format?: BankFormat; onClose: () => void; onSaved: (message: string) => void }) {
  const firstCountry = data.countries.find((country) => data.templates.some((template) => template.countries.includes(country.country_code)))?.country_code ?? "";
  const [country, setCountry] = useState(format?.country_code ?? firstCountry);
  const available = data.templates.filter((template) => template.countries.includes(country));
  const [templateKey, setTemplateKey] = useState(format?.template ?? available[0]?.key ?? "");
  const template = data.templates.find((option) => option.key === templateKey);
  const [name, setName] = useState(format?.name ?? "");
  const [isDefault, setIsDefault] = useState(format?.is_default ?? false);
  const [isActive, setIsActive] = useState(format?.is_active ?? true);
  // Sensitive details start blank on edit: leaving them blank keeps the saved value.
  const [settings, setSettings] = useState<Record<string, string>>(() =>
    Object.fromEntries((format?.settings ?? []).map((setting) => [setting.key, setting.sensitive ? "" : setting.value ?? ""])),
  );
  const [error, setError] = useState<ApiError | null>(null);
  const fieldError = (key: string) => error?.errors[key]?.[0];

  function chooseCountry(code: string) {
    setCountry(code);
    setTemplateKey(data.templates.find((option) => option.countries.includes(code))?.key ?? "");
  }

  async function save() {
    setError(null);
    try {
      await api(format ? `/payroll/bank-formats/${format.id}` : "/payroll/bank-formats", {
        method: format ? "PUT" : "POST",
        body: { ...(format ? {} : { country_code: country, template: templateKey }), name, is_default: isDefault, is_active: isActive, settings },
      });
      onSaved(format ? "Bank format updated." : "Bank format set up.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title={format ? `Edit ${format.name}` : "Set up a bank format"} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()} disabled={!template}>Save format</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="Country" required error={fieldError("country_code")}>
          <select value={country} onChange={(event) => chooseCountry(event.target.value)} disabled={!!format}>
            {data.countries.map((option) => <option key={option.country_code} value={option.country_code}>{option.country_name}</option>)}
          </select>
        </Field>
        <Field label="File format" required error={fieldError("template")}>
          <select value={templateKey} onChange={(event) => setTemplateKey(event.target.value)} disabled={!!format}>
            {available.map((option) => <option key={option.key} value={option.key}>{option.name}</option>)}
          </select>
        </Field>
        {template && <div className="tiny" style={{ gridColumn: "1 / -1" }}>{template.description} Each employee needs: {template.employee_details.join(", ")}.</div>}
        <Field label="Name" required full error={fieldError("name")}><input className="input" value={name} onChange={(event) => setName(event.target.value)} placeholder="e.g. Barclays salary payments" /></Field>
        {template?.settings.map((field) => {
          const saved = format?.settings.find((setting) => setting.key === field.key)?.value;
          return (
            <Field
              key={field.key}
              label={field.label}
              required={field.required && !(field.sensitive && saved)}
              optional={!field.required}
              error={fieldError(`settings.${field.key}`)}
              hint={field.sensitive && saved ? `Saved as ${saved}. Leave blank to keep it.` : field.hint ?? (field.default ? `Defaults to “${field.default}”.` : undefined)}
            >
              <input className="input" value={settings[field.key] ?? ""} maxLength={field.max ?? undefined} autoComplete="off" onChange={(event) => setSettings({ ...settings, [field.key]: event.target.value })} />
            </Field>
          );
        })}
        <Field label="Options" full>
          <label className="checkrow"><input type="checkbox" checked={isDefault} onChange={(event) => setIsDefault(event.target.checked)} /> Default format for this country</label>
          <label className="checkrow"><input type="checkbox" checked={isActive} onChange={(event) => setIsActive(event.target.checked)} /> Active (can be chosen when creating a bank file)</label>
        </Field>
      </div>
    </Modal>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.settings.manage", "payroll.export"]}>
      <BankFormatsPage />
    </Guard>
  );
}
