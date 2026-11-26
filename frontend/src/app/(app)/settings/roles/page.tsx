"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, Field, humanize, Modal, PageHead, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Role = { id: string; key: string; name: string; description: string | null; is_system: boolean; member_count: number; permissions: string[] };
type CatalogueEntry = { key: string; group: string; description: string; is_sensitive: boolean };

function RolesPage() {
  const { can } = useAuth();
  const [roles, setRoles] = useState<Role[]>([]);
  const [catalogue, setCatalogue] = useState<CatalogueEntry[]>([]);
  const [editing, setEditing] = useState<{ id?: string; name: string; description: string; permissions: string[] } | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const canManage = can("roles.manage");

  const load = useCallback(async () => {
    const result = await api<{ data: Role[]; catalogue: CatalogueEntry[] }>("/roles");
    setRoles(result.data);
    setCatalogue(result.catalogue);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  const groups = useMemo(() => {
    const byGroup = new Map<string, CatalogueEntry[]>();
    catalogue.forEach((entry) => byGroup.set(entry.group, [...(byGroup.get(entry.group) ?? []), entry]));
    return [...byGroup.entries()];
  }, [catalogue]);

  async function save() {
    if (!editing) return;
    setError(null);
    try {
      await api(editing.id ? `/roles/${editing.id}` : "/roles", { method: editing.id ? "PUT" : "POST", body: editing });
      setNotice(`Role “${editing.name}” saved. Changes apply on each person's next action.`);
      setEditing(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  const toggle = (key: string) =>
    editing && setEditing({ ...editing, permissions: editing.permissions.includes(key) ? editing.permissions.filter((item) => item !== key) : [...editing.permissions, key] });

  return (
    <>
      <PageHead
        eyebrow="Settings"
        title="Roles & permissions"
        subtitle="Starter roles cover most companies. A person can hold more than one role, and you can add roles of your own."
        actions={canManage && <button className="btn primary" onClick={() => { setError(null); setEditing({ name: "", description: "", permissions: [] }); }}>Add role</button>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel title="Workspace roles" bodyless>
        <Table headers={["Role", "Permissions", "People", ""]}>
          {roles.map((role) => (
            <tr key={role.id}>
              <td>
                <strong>{role.name}</strong> {role.is_system ? <Badge tone="blue" label="Starter" /> : <Badge tone="gray" label="Custom" />}
                <div className="tiny" style={{ whiteSpace: "normal", maxWidth: 380 }}>{role.description}</div>
              </td>
              <td>
                {role.key === "owner" ? "Everything" : `${role.permissions.length} permissions`}
                {role.permissions.some((key) => catalogue.find((entry) => entry.key === key)?.is_sensitive) && <> · <Badge tone="amber" label="Payroll-sensitive" /></>}
              </td>
              <td>{role.member_count}</td>
              <td style={{ textAlign: "right" }}>
                {canManage && role.key !== "owner" && (
                  <button className="btn" onClick={() => { setError(null); setEditing({ id: role.id, name: role.name, description: role.description ?? "", permissions: role.permissions }); }}>Edit</button>
                )}
              </td>
            </tr>
          ))}
        </Table>
      </Panel>
      {editing && (
        <Modal
          title={editing.id ? `Edit ${editing.name}` : "Add role"}
          onClose={() => setEditing(null)}
          footer={<><button className="btn" onClick={() => setEditing(null)}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save role</button></>}
        >
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="stack" style={{ maxHeight: "60vh", overflow: "auto" }}>
            <Field label="Role name" required error={error?.errors.name?.[0]}>
              <input className="input" value={editing.name} onChange={(event) => setEditing({ ...editing, name: event.target.value })} />
            </Field>
            <Field label="Description" optional>
              <input className="input" value={editing.description} onChange={(event) => setEditing({ ...editing, description: event.target.value })} />
            </Field>
            {groups.map(([group, entries]) => (
              <div key={group}>
                <div className="sectiontitle">{humanize(group)}</div>
                <div className="stack" style={{ gap: 6 }}>
                  {entries.map((entry) => (
                    <label key={entry.key} className="checkrow">
                      <input type="checkbox" checked={editing.permissions.includes(entry.key)} onChange={() => toggle(entry.key)} />
                      <span>{entry.description} {entry.is_sensitive && <Badge tone="amber" label="Sensitive" />}</span>
                    </label>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </Modal>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["users.view", "users.manage", "roles.manage"]}>
      <RolesPage />
    </Guard>
  );
}
