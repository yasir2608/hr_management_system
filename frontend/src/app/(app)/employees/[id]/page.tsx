"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { DocumentsPanel } from "@/components/employees/DocumentsPanel";
import { OnboardingTasksPanel } from "@/components/onboarding/OnboardingTasksPanel";
import { UpdateRequestsPanel } from "@/components/employees/UpdateRequestsPanel";
import { ChangeStatusModal, EditDetailsModal, PayrollDetailsModal, RecordChangeModal } from "@/components/employees/EditModals";
import { NoAccess } from "@/components/Guard";
import { Badge, Banner, EmptyState, formatDate, humanize, initials, money, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useTab } from "@/lib/useTab";
import { countryName } from "@/lib/countries";
import { statusLabel, type Employee } from "@/lib/employees";

type HistoryRow = { id: string; valid_from: string; valid_to: string | null; reason: string | null; [key: string]: unknown };
type Profile = {
  sections: {
    summary: Employee;
    personal?: Record<string, string | null>;
    history?: { job: HistoryRow[]; manager: HistoryRow[]; location: HistoryRow[]; status: HistoryRow[] };
    pay?: { current: Record<string, string> | null; history: HistoryRow[] };
    tax_details?: { country_code: string; details: Record<string, string> }[];
    bank?: { id: string; account_holder: string; bank_name: string | null; account_number: string; is_primary: boolean }[];
    payroll_readiness?: { missing: { key: string; label: string }[] };
  };
  restricted_sections: string[];
  is_self: boolean;
  can_edit: boolean;
  can_edit_payroll: boolean;
  next_statuses: string[];
};

const restrictedText: Record<string, string> = {
  personal: "Personal details are visible to HR and the employee only.",
  pay: "Pay is visible to people with payroll permission.",
  tax_details: "Tax identifiers are visible to people with payroll permission.",
  bank: "Bank details are visible to people with payroll permission.",
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="field">
      <label>{label}</label>
      <div>{value || <span className="muted">—</span>}</div>
    </div>
  );
}

function HistoryTable({ rows, columns }: { rows: HistoryRow[]; columns: [string, string][] }) {
  if (rows.length === 0) return <div className="tiny">No history yet.</div>;
  return (
    <Table headers={[...columns.map(([, label]) => label), "From", "To", "Reason"]}>
      {rows.map((row) => (
        <tr key={row.id}>
          {columns.map(([key]) => <td key={key}>{row[key] ? humanize(String(row[key])) : "—"}</td>)}
          <td>{formatDate(row.valid_from)}</td>
          <td>{row.valid_to ? formatDate(row.valid_to) : <Badge tone="green" label="Current" />}</td>
          <td className="muted">{row.reason ?? "—"}</td>
        </tr>
      ))}
    </Table>
  );
}

export default function EmployeeDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [tab, setTab] = useTab(["overview", "history", "payroll", "documents", "onboarding"]);
  const { can } = useAuth();
  const [modal, setModal] = useState<"details" | "change" | "payroll" | "status" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api<Profile>(`/employees/${id}`).then(setProfile).catch((caught) => setError(caught instanceof ApiError ? caught : null));
  }, [id]);

  useEffect(load, [load]);

  function done(message: string) {
    setModal(null);
    setNotice(message);
    load();
  }

  if (error) return error.status === 404 ? <NoAccess message="This employee does not exist, or is outside the people you have access to." /> : <EmptyState title={error.message} />;
  if (!profile) return <div className="loadingpage">Loading…</div>;

  const { summary, personal, history, pay, tax_details, bank, payroll_readiness } = profile.sections;
  const tabs = [
    ["overview", "Overview"],
    ...(history ? [["history", "Employment history"]] : []),
    ["payroll", "Pay & tax"],
    ["documents", "Documents"],
    ...(can("onboarding.manage") ? [["onboarding", "Onboarding"]] : []),
  ];

  return (
    <>
      <div className="pagehead">
        <div className="person" style={{ gap: 14 }}>
          <div className="mini" style={{ width: 52, height: 52, fontSize: 16 }}>{initials(summary.full_name)}</div>
          <div>
            <div className="eyebrow"><Link href="/employees" style={{ textDecoration: "none" }}>Employees</Link> · {summary.employee_number}</div>
            <h1>{summary.full_name}</h1>
            <p className="subtitle">
              {summary.current_job_title ?? "No job title"} {summary.department && `· ${summary.department.name}`} · <Badge status={summary.current_status} label={statusLabel(summary.current_status)} />
            </p>
          </div>
        </div>
        <div className="actions">
          {profile.can_edit && <button className="btn" onClick={() => setModal("details")}>Edit details</button>}
          {profile.next_statuses.length > 0 && <button className="btn" onClick={() => setModal("status")}>Change status</button>}
          {(profile.can_edit || profile.can_edit_payroll) && <button className="btn primary" onClick={() => setModal("change")}>Record a change</button>}
          {profile.can_edit_payroll && <button className="btn soft" onClick={() => setModal("payroll")}>Payroll details</button>}
        </div>
      </div>
      {notice && <Banner tone="success">{notice}</Banner>}
      {profile.can_edit && <UpdateRequestsPanel employeeId={summary.id} onReviewed={load} />}
      {modal === "details" && <EditDetailsModal employee={summary} personal={personal ?? undefined} onClose={() => setModal(null)} onDone={done} />}
      {modal === "change" && <RecordChangeModal employee={summary} canChangePay={profile.can_edit_payroll} onClose={() => setModal(null)} onDone={done} />}
      {modal === "status" && <ChangeStatusModal employee={summary} nextStatuses={profile.next_statuses} onClose={() => setModal(null)} onDone={done} />}
      {modal === "payroll" && <PayrollDetailsModal employee={summary} onClose={() => setModal(null)} onDone={done} />}

      <div className="tabs" role="tablist">
        {tabs.map(([key, label]) => (
          <button key={key} role="tab" aria-selected={tab === key} className={`tab${tab === key ? " on" : ""}`} onClick={() => setTab(key)}>{label}</button>
        ))}
      </div>

      {tab === "overview" && (
        <div className="grid two">
          <Panel title="Employment">
            <div className="formgrid">
              <Detail label="Employee number" value={summary.employee_number} />
              <Detail label="Start date" value={formatDate(summary.start_date)} />
              <Detail label="Employment type" value={summary.current_employment_type && humanize(summary.current_employment_type)} />
              <Detail label="Manager" value={summary.manager && <Link href={`/employees/${summary.manager.id}`}>{summary.manager.full_name}</Link>} />
              <Detail label="Department" value={summary.department?.name} />
              <Detail label="Team" value={summary.team?.name} />
              <Detail label="Location" value={summary.location?.name} />
              <Detail label="Works" value={summary.current_work_arrangement && humanize(summary.current_work_arrangement)} />
              <Detail label="Country of employment" value={countryName(summary.work_country)} />
              <Detail label="Work email" value={summary.work_email} />
              {summary.end_date && <Detail label="End date" value={formatDate(summary.end_date)} />}
              <Detail label="M.Y HRMS account" value={summary.has_account ? "Yes" : "Not invited yet"} />
            </div>
          </Panel>
          <div className="stack">
            {personal ? (
              <Panel title="Personal details">
                <div className="formgrid">
                  <Detail label="Date of birth" value={formatDate(personal.date_of_birth)} />
                  <Detail label="Phone" value={personal.phone} />
                  <Detail label="Personal email" value={personal.personal_email} />
                  <Detail label="Nationality" value={personal.nationality} />
                  <Detail label="Address" value={[personal.address_line1, personal.city, personal.postal_code].filter(Boolean).join(", ")} />
                  <Detail label="Emergency contact" value={personal.emergency_contact_name && `${personal.emergency_contact_name} (${personal.emergency_contact_relationship ?? "contact"}) ${personal.emergency_contact_phone ?? ""}`} />
                </div>
              </Panel>
            ) : (
              <Panel title="Personal details"><div className="callout blue">{restrictedText.personal}</div></Panel>
            )}
            {payroll_readiness && (
              <Panel title="Payroll readiness" right={payroll_readiness.missing.length === 0 ? <Badge tone="green" label="Ready" /> : <Badge tone="amber" label="Missing information" />}>
                {payroll_readiness.missing.length === 0 ? (
                  <span className="muted">Everything payroll needs is in place.</span>
                ) : (
                  <ul style={{ margin: 0, paddingLeft: 18 }}>
                    {payroll_readiness.missing.map((item) => <li key={item.key} className="tiny">{item.label}</li>)}
                  </ul>
                )}
              </Panel>
            )}
          </div>
        </div>
      )}

      {tab === "history" && history && (
        <div className="stack">
          <Panel title="Job" bodyless><HistoryTable rows={history.job} columns={[["job_title", "Job title"], ["department", "Department"], ["employment_type", "Type"]]} /></Panel>
          <Panel title="Status" bodyless><HistoryTable rows={history.status} columns={[["status", "Status"]]} /></Panel>
          <div className="grid split">
            <Panel title="Manager" bodyless><HistoryTable rows={history.manager} columns={[["manager", "Manager"]]} /></Panel>
            <Panel title="Location" bodyless><HistoryTable rows={history.location} columns={[["location", "Location"], ["work_arrangement", "Works"]]} /></Panel>
          </div>
        </div>
      )}

      {tab === "documents" && <DocumentsPanel employeeId={summary.id} />}
      {tab === "onboarding" && <OnboardingTasksPanel employeeId={summary.id} />}

      {tab === "payroll" && (
        <div className="grid split">
          <Panel title="Pay">
            {pay ? (
              pay.current ? (
                <div className="stack">
                  <div className="statnum currency">{money(pay.current.amount, pay.current.currency)} <span className="tiny">per {pay.current.frequency.replace("ly", "").replace("annual", "year")}</span></div>
                  <HistoryTable rows={pay.history} columns={[["amount", "Amount"], ["frequency", "Frequency"]]} />
                </div>
              ) : <span className="muted">No pay recorded yet.</span>
            ) : <div className="callout blue">{restrictedText.pay}</div>}
          </Panel>
          <div className="stack">
            <Panel title="Tax details">
              {tax_details ? (
                tax_details.length === 0 ? <span className="muted">None recorded yet.</span> : tax_details.map((detail) => (
                  <div key={detail.country_code} className="formgrid">
                    {Object.entries(detail.details).map(([key, value]) => <Detail key={key} label={humanize(key)} value={value} />)}
                  </div>
                ))
              ) : <div className="callout blue">{restrictedText.tax_details}</div>}
            </Panel>
            <Panel title="Bank account">
              {bank ? (
                bank.length === 0 ? <span className="muted">None recorded yet.</span> : bank.map((account) => (
                  <div key={account.id} className="row">
                    <div><strong>{account.account_holder}</strong><div className="tiny">{account.bank_name ?? "Bank"} · {account.account_number}</div></div>
                    {account.is_primary && <Badge tone="blue" label="Primary" />}
                  </div>
                ))
              ) : <div className="callout blue">{restrictedText.bank}</div>}
            </Panel>
          </div>
        </div>
      )}
    </>
  );
}
