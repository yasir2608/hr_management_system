"use client";

import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Field, formatDate, formatDateTime, humanize, Modal, PageHead, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { countryName } from "@/lib/countries";
import { statusLabel, type Employee } from "@/lib/employees";

type Change = { old: string | null; new: string | null };
type UpdateRequest = { id: string; changes: Record<string, Change>; note: string | null; status: string; review_note: string | null; created_at: string; reviewed_by: string | null };
type MyProfile = {
  sections: { summary: Employee; personal?: Record<string, string | null> };
  requests: UpdateRequest[];
  requestable_fields: string[];
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="field">
      <label>{label}</label>
      <div>{value || <span className="muted">—</span>}</div>
    </div>
  );
}

function MyProfilePage() {
  const [profile, setProfile] = useState<MyProfile | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [editing, setEditing] = useState<Record<string, string> | null>(null);
  const [note, setNote] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api<MyProfile>("/me/profile").then(setProfile).catch((caught) => setProblem(caught instanceof ApiError ? caught.message : "Could not load your profile."));
  }, []);

  useEffect(load, [load]);

  if (problem) return <><PageHead eyebrow="Me" title="My profile" /><EmptyState icon="◉" title={problem} /></>;
  if (!profile) return <div className="loadingpage">Loading…</div>;

  const { summary, personal = {} } = profile.sections;
  const pending = profile.requests.find((request) => request.status === "pending");

  async function send() {
    if (!editing) return;
    setError(null);
    try {
      const result = await api<{ message: string }>("/me/update-requests", { body: { changes: editing, note } });
      setNotice(result.message);
      setEditing(null);
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not send.", 0));
    }
  }

  async function withdraw(id: string) {
    await api(`/me/update-requests/${id}/withdraw`, { method: "POST" });
    load();
  }

  return (
    <>
      <PageHead
        eyebrow="Me"
        title="My profile"
        subtitle="Your details as your company holds them. Ask HR to update anything that is out of date."
        actions={!pending && <button className="btn primary" onClick={() => { setError(null); setNote(""); setEditing(Object.fromEntries(profile.requestable_fields.map((field) => [field, (summary as unknown as Record<string, string | null>)[field] ?? personal[field] ?? ""]))); }}>Request a change</button>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      {pending && (
        <Banner tone="info">
          Your request from {formatDate(pending.created_at)} is waiting for HR. <button className="link" onClick={() => void withdraw(pending.id)}>Withdraw it</button>
        </Banner>
      )}
      <div className="grid two">
        <Panel title="Employment">
          <div className="formgrid">
            <Detail label="Name" value={summary.full_name} />
            <Detail label="Employee number" value={summary.employee_number} />
            <Detail label="Job title" value={summary.current_job_title} />
            <Detail label="Status" value={<Badge status={summary.current_status} label={statusLabel(summary.current_status)} />} />
            <Detail label="Department" value={summary.department?.name} />
            <Detail label="Team" value={summary.team?.name} />
            <Detail label="Manager" value={summary.manager?.full_name} />
            <Detail label="Location" value={summary.location?.name} />
            <Detail label="Country of employment" value={countryName(summary.work_country)} />
            <Detail label="Start date" value={formatDate(summary.start_date)} />
          </div>
        </Panel>
        <Panel title="Personal details">
          <div className="formgrid">
            <Detail label="Preferred name" value={summary.preferred_name} />
            <Detail label="Date of birth" value={formatDate(personal.date_of_birth)} />
            <Detail label="Phone" value={personal.phone} />
            <Detail label="Personal email" value={personal.personal_email} />
            <Detail label="Address" value={[personal.address_line1, personal.address_line2, personal.city, personal.postal_code].filter(Boolean).join(", ")} />
            <Detail label="Emergency contact" value={personal.emergency_contact_name && `${personal.emergency_contact_name} · ${personal.emergency_contact_phone ?? ""}`} />
          </div>
        </Panel>
      </div>
      {profile.requests.length > 0 && (
        <div style={{ marginTop: 15 }}>
          <Panel title="My requests" bodyless>
            <Table headers={["Sent", "Changes", "Status", "HR note"]}>
              {profile.requests.map((request) => (
                <tr key={request.id}>
                  <td>{formatDateTime(request.created_at)}</td>
                  <td style={{ whiteSpace: "normal" }}>{Object.entries(request.changes).map(([field, change]) => <div key={field}>{humanize(field)}: <span className="muted">{change.old || "—"}</span> → <strong>{change.new || "—"}</strong></div>)}</td>
                  <td><Badge status={request.status} /></td>
                  <td className="muted">{request.review_note ?? "—"}</td>
                </tr>
              ))}
            </Table>
          </Panel>
        </div>
      )}
      {editing && (
        <Modal title="Request a change" onClose={() => setEditing(null)} footer={<><button className="btn" onClick={() => setEditing(null)}>Cancel</button><button className="btn primary" onClick={() => void send()}>Send to HR</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <p className="tiny" style={{ marginTop: 0 }}>Change the details that are out of date. HR reviews the request before anything changes.</p>
          <div className="formgrid" style={{ maxHeight: "50vh", overflow: "auto" }}>
            {profile.requestable_fields.map((field) => (
              <Field key={field} label={humanize(field)} error={error?.errors[`changes.${field}`]?.[0]}>
                <input className="input" value={editing[field] ?? ""} onChange={(event) => setEditing({ ...editing, [field]: event.target.value })} />
              </Field>
            ))}
            <Field label="Note for HR" optional full><input className="input" value={note} onChange={(event) => setNote(event.target.value)} /></Field>
          </div>
        </Modal>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["self.profile.view"]}>
      <MyProfilePage />
    </Guard>
  );
}
