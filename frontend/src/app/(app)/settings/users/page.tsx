"use client";

import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { InviteModal } from "@/components/users/InviteModal";
import { ManageUserModal } from "@/components/users/ManageUserModal";
import { Badge, Banner, EmptyState, formatDateTime, PageHead, Panel, Person, Stat, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { roleLabel, type UserRow } from "@/lib/users";

type InvitationRow = { id: string; email: string; role: string; scope_type: string; status: string; expires_at: string; last_sent_at: string; send_count: number; invited_by: string | null };

function UsersPage() {
  const [users, setUsers] = useState<UserRow[] | null>(null);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");

  const { can } = useAuth();
  const canManage = can("users.manage");
  const [invitations, setInvitations] = useState<InvitationRow[]>([]);
  const [roles, setRoles] = useState<{ id: string; name: string }[]>([]);
  const [inviting, setInviting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [managing, setManaging] = useState<UserRow | null>(null);

  const load = useCallback(async () => {
    const [result, pending] = await Promise.all([
      api<{ data: UserRow[]; counts: Record<string, number> }>("/users", { query: { status, search } }),
      api<{ data: InvitationRow[] }>("/invitations"),
    ]);
    setUsers(result.data);
    setCounts(result.counts);
    setInvitations(pending.data);
  }, [status, search]);

  async function act(path: string, method: "POST" | "DELETE") {
    try {
      const result = await api<{ message: string }>(path, { method });
      setNotice(result.message);
    } catch (caught) {
      setNotice(caught instanceof ApiError ? caught.message : "That did not work.");
    }
    void load();
  }


  useEffect(() => {
    if (canManage) api<{ data: { id: string; name: string }[] }>("/roles").then((result) => setRoles(result.data));
  }, [canManage]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch when filters change
    void load();
  }, [load]);

  return (
    <>
      <PageHead
        eyebrow="Settings"
        title="Users & permissions"
        subtitle="Invite people and give each person access appropriate to their responsibilities."
        actions={canManage && <button className="btn primary" onClick={() => setInviting(true)}>Invite user</button>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      {managing && (
        <ManageUserModal
          user={managing}
          roles={roles}
          onClose={() => setManaging(null)}
          onChanged={(message) => {
            setManaging(null);
            setNotice(message);
            void load();
          }}
        />
      )}
      {inviting && (
        <InviteModal
          roles={roles}
          onClose={() => setInviting(false)}
          onSent={(message) => {
            setInviting(false);
            setNotice(message);
            void load();
          }}
        />
      )}
      {invitations.length > 0 && (
        <div style={{ marginBottom: 15 }}>
          <Panel title="Invitations" subtitle="Waiting for the person to accept." bodyless>
            <Table headers={["Email", "Role", "Invited by", "Status", "Expires", ""]}>
              {invitations.map((invitation) => (
                <tr key={invitation.id}>
                  <td><strong>{invitation.email}</strong></td>
                  <td>{invitation.role}{invitation.scope_type !== "company" && <span className="tiny"> · limited</span>}</td>
                  <td>{invitation.invited_by ?? "—"}</td>
                  <td><Badge status={invitation.status} /></td>
                  <td>{formatDateTime(invitation.expires_at)}</td>
                  <td style={{ textAlign: "right" }}>
                    {canManage && (
                      <div className="row" style={{ justifyContent: "flex-end" }}>
                        <button className="btn" onClick={() => void act(`/invitations/${invitation.id}/resend`, "POST")}>Resend</button>
                        {invitation.status === "pending" && <button className="btn danger" onClick={() => void act(`/invitations/${invitation.id}`, "DELETE")}>Cancel</button>}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </Table>
          </Panel>
        </div>
      )}
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
          <Table headers={["Person", "Roles", "Status", "Last sign-in", ""]}>
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
                <td style={{ textAlign: "right" }}>{canManage && user.status !== "removed" && <button className="btn" onClick={() => setManaging(user)}>Manage</button>}</td>
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
