"use client";

import { useState } from "react";
import { Badge, Banner, Field, Modal } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { roleLabel, type UserRow } from "@/lib/users";
import { ScopePicker } from "./InviteModal";

export function ManageUserModal({ user, roles, onClose, onChanged }: { user: UserRow; roles: { id: string; name: string }[]; onClose: () => void; onChanged: (message: string) => void }) {
  const [adding, setAdding] = useState({ role_id: roles[0]?.id ?? "", scope_type: "company", scope_id: null as string | null, include_indirect: false });
  const [error, setError] = useState<ApiError | null>(null);

  async function run(path: string, method: "POST" | "DELETE", body?: unknown) {
    setError(null);
    try {
      const result = await api<{ message: string }>(path, { method, body });
      onChanged(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("That did not work.", 0));
    }
  }

  return (
    <Modal title={`Manage access · ${user.name}`} onClose={onClose} footer={<button className="btn" onClick={onClose}>Close</button>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="stack">
        <div className="row">
          <div>
            <strong>{user.email}</strong>
            <div className="tiny">Changes apply the next time {user.name.split(" ")[0]} does anything in M.Y HRMS.</div>
          </div>
          <Badge status={user.status} />
        </div>

        <div className="sectiontitle">Roles</div>
        {user.roles.length === 0 && <span className="muted">No roles. This person can open the company but do nothing.</span>}
        {user.roles.map((role) => (
          <div key={role.assignment_id} className="row">
            <span>{roleLabel(role)}</span>
            <button className="btn danger" onClick={() => void run(`/users/${user.id}/roles/${role.assignment_id}`, "DELETE")}>Remove</button>
          </div>
        ))}

        <div className="sectiontitle">Add a role</div>
        <div className="formgrid">
          <Field label="Role" error={error?.errors.role_id?.[0]}>
            <select value={adding.role_id} onChange={(event) => setAdding({ ...adding, role_id: event.target.value })}>
              {roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
            </select>
          </Field>
          <ScopePicker scopeType={adding.scope_type} scopeId={adding.scope_id} error={error?.errors.scope_id?.[0]} onChange={(scope_type, scope_id) => setAdding({ ...adding, scope_type, scope_id })} />
          {adding.scope_type === "reporting_line" && (
            <label className="checkrow field full">
              <input type="checkbox" checked={adding.include_indirect} onChange={(event) => setAdding({ ...adding, include_indirect: event.target.checked })} />
              Include indirect reports
            </label>
          )}
        </div>
        <div><button className="btn soft" onClick={() => void run(`/users/${user.id}/roles`, "POST", adding)}>Add role</button></div>

        <div className="sectiontitle">Access</div>
        <div className="row" style={{ justifyContent: "flex-start" }}>
          {user.status === "active" ? (
            <button className="btn" onClick={() => void run(`/users/${user.id}/suspend`, "POST")}>Suspend access</button>
          ) : (
            <button className="btn" onClick={() => void run(`/users/${user.id}/reactivate`, "POST")}>Restore access</button>
          )}
          <button className="btn danger" onClick={() => window.confirm(`Remove ${user.name}'s access? Their history is kept.`) && void run(`/users/${user.id}/remove`, "POST")}>
            Remove access
          </button>
        </div>
      </div>
    </Modal>
  );
}
