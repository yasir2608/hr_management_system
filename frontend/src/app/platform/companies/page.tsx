"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, formatDate, Modal, PageHead, Panel, Table } from "@/components/ui";
import { useAuth } from "@/lib/auth";
import { api, ApiError } from "@/lib/api";
import { COUNTRIES, countryName } from "@/lib/countries";

type CompanyRow = { id: string; name: string; country_code: string; status: string; member_count: number; employee_count: number; last_activity_at: string | null; created_at: string };

const blank = { company: { name: "", legal_name: "", country_code: "", contact_email: "" }, admin: { name: "", email: "" } };

export default function PlatformCompaniesPage() {
  const { user } = useAuth();
  const isOps = user?.platform_role === "ops";
  const [companies, setCompanies] = useState<CompanyRow[] | null>(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState(blank);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: CompanyRow[] }>("/platform/companies", { query: { search, status }, noCompany: true }).then((result) => setCompanies(result.data));
  }, [search, status]);

  useEffect(load, [load]);

  async function create() {
    setError(null);
    try {
      const result = await api<{ message: string }>("/platform/companies", { body: form, noCompany: true });
      setNotice(result.message);
      setCreating(false);
      setForm(blank);
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not create the company.", 0));
    }
  }

  const fieldError = (key: string) => error?.errors[key]?.[0];

  return (
    <>
      <PageHead
        eyebrow="Staff console"
        title="Customer companies"
        subtitle="Create company accounts for customers and see who is set up."
        actions={isOps && <button className="btn primary" onClick={() => setCreating(true)}>Create company</button>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel title="Companies" right={
          <div className="row">
            <input className="input" style={{ width: 220 }} placeholder="Search by name" value={search} onChange={(event) => setSearch(event.target.value)} />
            <select style={{ width: 130 }} value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Status">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
        } bodyless>
        {companies && companies.length === 0 ? (
          <EmptyState title="No companies found" />
        ) : (
          <Table headers={["Company", "Country", "Active users", "Employees", "Last activity", "Status", "Created"]}>
            {companies?.map((company) => (
              <tr key={company.id}>
                <td><Link href={`/companies/${company.id}`}><strong>{company.name}</strong></Link></td>
                <td>{countryName(company.country_code)}</td>
                <td>{company.member_count}</td>
                <td>{company.employee_count}</td>
                <td>{company.last_activity_at ? formatDate(company.last_activity_at) : "—"}</td>
                <td><Badge status={company.status} /></td>
                <td>{formatDate(company.created_at)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {creating && (
        <Modal
          title="Create company account"
          onClose={() => setCreating(false)}
          footer={
            <>
              <button className="btn" onClick={() => setCreating(false)}>Cancel</button>
              <button className="btn primary" onClick={() => void create()}>Create company</button>
            </>
          }
        >
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            <Field label="Company name" required error={fieldError("company.name")}>
              <input className="input" value={form.company.name} onChange={(event) => setForm({ ...form, company: { ...form.company, name: event.target.value } })} />
            </Field>
            <Field label="Country" required error={fieldError("company.country_code")}>
              <select value={form.company.country_code} onChange={(event) => setForm({ ...form, company: { ...form.company, country_code: event.target.value } })}>
                <option value="">Choose</option>
                {COUNTRIES.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
              </select>
            </Field>
            <Field label="Primary administrator name" required error={fieldError("admin.name")}>
              <input className="input" value={form.admin.name} onChange={(event) => setForm({ ...form, admin: { ...form.admin, name: event.target.value } })} />
            </Field>
            <Field label="Administrator email" required error={fieldError("admin.email")} hint="They are emailed a link to set a password.">
              <input className="input" type="email" value={form.admin.email} onChange={(event) => setForm({ ...form, admin: { ...form.admin, email: event.target.value } })} />
            </Field>
          </div>
        </Modal>
      )}
    </>
  );
}
