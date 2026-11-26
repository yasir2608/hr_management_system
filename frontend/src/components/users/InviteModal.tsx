"use client";

import { useEffect, useState } from "react";
import { Banner, Field, Modal } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Option = { id: string; name: string };
type EmployeeOption = { id: string; first_name: string; last_name: string };

export function ScopePicker({
  scopeType,
  scopeId,
  onChange,
  error,
}: {
  scopeType: string;
  scopeId: string | null;
  onChange: (scopeType: string, scopeId: string | null) => void;
  error?: string;
}) {
  const [teams, setTeams] = useState<Option[]>([]);
  const [locations, setLocations] = useState<Option[]>([]);
  const [managers, setManagers] = useState<Option[]>([]);

  useEffect(() => {
    api<{ data: Option[] }>("/teams").then((result) => setTeams(result.data)).catch(() => undefined);
    api<{ data: Option[] }>("/locations").then((result) => setLocations(result.data)).catch(() => undefined);
    api<{ data: EmployeeOption[] }>("/employees", { query: { per_page: 200 } })
      .then((result) => setManagers(result.data.map((employee) => ({ id: employee.id, name: `${employee.first_name} ${employee.last_name}` }))))
      .catch(() => undefined);
  }, []);

  const options = { team: teams, location: locations, reporting_line: managers }[scopeType] ?? [];

  return (
    <>
      <Field label="Access covers" hint="Limit a manager to the people they look after.">
        <select value={scopeType} onChange={(event) => onChange(event.target.value, null)}>
          <option value="company">The whole company</option>
          <option value="team">One team</option>
          <option value="location">One location</option>
          <option value="reporting_line">A manager&apos;s reporting line</option>
        </select>
      </Field>
      {scopeType !== "company" && (
        <Field label={{ team: "Team", location: "Location", reporting_line: "Manager" }[scopeType] ?? "Scope"} required error={error}>
          <select value={scopeId ?? ""} onChange={(event) => onChange(scopeType, event.target.value || null)}>
            <option value="">Choose…</option>
            {options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
          </select>
        </Field>
      )}
    </>
  );
}

export function InviteModal({ roles, onClose, onSent }: { roles: Option[]; onClose: () => void; onSent: (message: string) => void }) {
  const [form, setForm] = useState({ email: "", role_id: roles.find((role) => role.name.startsWith("Employee"))?.id ?? roles[0]?.id ?? "", scope_type: "company", scope_id: null as string | null });
  const [error, setError] = useState<ApiError | null>(null);
  const [sending, setSending] = useState(false);

  async function send() {
    setSending(true);
    setError(null);
    try {
      const result = await api<{ message: string }>("/invitations", { body: form });
      onSent(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not send the invitation.", 0));
      setSending(false);
    }
  }

  return (
    <Modal
      title="Invite a user"
      onClose={onClose}
      footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={sending} onClick={() => void send()}>Send invitation</button></>}
    >
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="Email address" required full error={error?.errors.email?.[0]}>
          <input className="input" type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} autoFocus />
        </Field>
        <Field label="Role" required error={error?.errors.role_id?.[0]}>
          <select value={form.role_id} onChange={(event) => setForm({ ...form, role_id: event.target.value })}>
            {roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
          </select>
        </Field>
        <ScopePicker scopeType={form.scope_type} scopeId={form.scope_id} error={error?.errors.scope_id?.[0]} onChange={(scope_type, scope_id) => setForm({ ...form, scope_type, scope_id })} />
      </div>
      <p className="tiny">The invitation link expires in 7 days. You can resend it from the list.</p>
    </Modal>
  );
}
