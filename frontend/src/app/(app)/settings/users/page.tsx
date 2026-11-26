"use client";

import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, EmptyState, formatDateTime, PageHead, Panel, Person, Stat, Table } from "@/components/ui";
import { api } from "@/lib/api";
import { roleLabel, type UserRow } from "@/lib/users";

function UsersPage() {
  const [users, setUsers] = useState<UserRow[] | null>(null);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");

  const load = useCallback(async () => {
    const result = await api<{ data: UserRow[]; counts: Record<string, number> }>("/users", { query: { status, search } });
    setUsers(result.data);
    setCounts(result.counts);
  }, [status, search]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch when filters change
    void load();
  }, [load]);

  return (
    <>
      <PageHead eyebrow="Settings" title="Users & permissions" subtitle="Invite people and give each person access appropriate to their responsibilities." />
      <div className="grid stats">
        <Stat label="Active users" value={counts.active ?? 0} />
        <Stat label="Suspended" value={counts.suspended ?? 0} />
        <Stat label="Removed" value={counts.removed ?? 0} note="Kept for access history" />
        <Stat label="Showing" value={users?.length ?? "—"} />
      </div>
      <Panel
        title="People with access"
        right={
          <div className="row">
            <input className="input" style={{ width: 200 }} placeholder="Search name or email" value={search} onChange={(event) => setSearch(event.target.value)} />
            <select style={{ width: 150 }} value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">Active and suspended</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="removed">Removed</option>
            </select>
          </div>
        }
        bodyless
      >
        {users && users.length === 0 ? (
          <EmptyState title="No users match" />
        ) : (
          <Table headers={["Person", "Roles", "Status", "Last sign-in"]}>
            {users?.map((user) => (
              <tr key={user.id}>
                <td><Person name={user.name} detail={user.email} /></td>
                <td style={{ whiteSpace: "normal" }}>
                  {user.roles.length === 0 ? <span className="muted">No role</span> : user.roles.map((role) => <div key={role.assignment_id}>{roleLabel(role)}</div>)}
                </td>
                <td>
                  <Badge status={user.status} />
                  {user.account_disabled && <> <Badge tone="red" label="Account disabled" /></>}
                </td>
                <td>{user.last_login_at ? formatDateTime(user.last_login_at) : <span className="muted">Never</span>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["users.view", "users.manage"]}>
      <UsersPage />
    </Guard>
  );
}
