"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, formatDateTime, PageHead, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Grant = { id: string; company_id: string; company: string; reason: string; ticket_reference: string | null; expires_at: string; revoked_at: string | null; created_at: string; active: boolean };
type Company = { id: string; name: string };

export default function SupportAccessPage() {
  const { refresh, enterCompany } = useAuth();
  const router = useRouter();
  const [grants, setGrants] = useState<Grant[]>([]);
  const [companies, setCompanies] = useState<Company[]>([]);
  const [form, setForm] = useState({ company_id: "", reason: "", ticket_reference: "", minutes: 60 });
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const result = await api<{ data: Grant[] }>("/platform/support-access", { noCompany: true });
    setGrants(result.data);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
    api<{ data: Company[] }>("/platform/companies/directory", { noCompany: true }).then((result) => setCompanies(result.data));
  }, [load]);

  async function request(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      const result = await api<{ message: string }>("/platform/support-access", { body: form, noCompany: true });
      setNotice(result.message);
      setForm({ ...form, reason: "", ticket_reference: "" });
      await Promise.all([load(), refresh()]);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not open access.", 0));
    }
  }

  async function end(id: string) {
    await api(`/platform/support-access/${id}/end`, { method: "POST", noCompany: true });
    await Promise.all([load(), refresh()]);
  }

  async function open(grant: Grant) {
    await refresh();
    enterCompany(grant.company_id);
    router.push("/dashboard");
  }

  return (
    <>
      <PageHead eyebrow="Platform operations" title="Support access" subtitle="Open time-limited, read-only access to a customer company. Your reason and every page you view are visible to the company." />
      {notice && <Banner tone="success">{notice}</Banner>}
      <div className="grid two">
        <Panel title="Your access" bodyless>
          {grants.length === 0 ? (
            <EmptyState title="No support access yet" />
          ) : (
            <Table headers={["Company", "Reason", "Until", "Status", ""]}>
              {grants.map((grant) => (
                <tr key={grant.id}>
                  <td><strong>{grant.company}</strong></td>
                  <td style={{ whiteSpace: "normal", maxWidth: 260 }}>{grant.reason}{grant.ticket_reference && <div className="tiny">Ticket {grant.ticket_reference}</div>}</td>
                  <td>{formatDateTime(grant.expires_at)}</td>
                  <td>{grant.active ? <Badge tone="green" label="Active" /> : <Badge tone="gray" label={grant.revoked_at ? "Ended" : "Expired"} />}</td>
                  <td>
                    {grant.active && (
                      <div className="row" style={{ justifyContent: "flex-end" }}>
                        <button className="btn primary" onClick={() => void open(grant)}>Open</button>
                        <button className="btn" onClick={() => void end(grant.id)}>End</button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
        <form onSubmit={request}>
          <Panel title="Request access" subtitle="Read-only. Salary, bank and tax details stay hidden.">
            {error && <Banner tone="error">{error.message}</Banner>}
            <div className="stack">
              <Field label="Company" required error={error?.errors.company_id?.[0]}>
                <select value={form.company_id} onChange={(event) => setForm({ ...form, company_id: event.target.value })}>
                  <option value="">Choose a company</option>
                  {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
                </select>
              </Field>
              <Field label="Reason" required error={error?.errors.reason?.[0]} hint="The company will read this.">
                <textarea className="input" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
              </Field>
              <Field label="Ticket reference" optional>
                <input className="input" value={form.ticket_reference} onChange={(event) => setForm({ ...form, ticket_reference: event.target.value })} />
              </Field>
              <Field label="For how long" error={error?.errors.minutes?.[0]}>
                <select value={form.minutes} onChange={(event) => setForm({ ...form, minutes: Number(event.target.value) })}>
                  <option value={30}>30 minutes</option>
                  <option value={60}>1 hour</option>
                  <option value={120}>2 hours</option>
                  <option value={240}>4 hours (maximum)</option>
                </select>
              </Field>
              <button className="btn primary" type="submit">Open read-only access</button>
            </div>
          </Panel>
        </form>
      </div>
    </>
  );
}
