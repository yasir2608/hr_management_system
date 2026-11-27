"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, EmptyState, PageHead, Panel, Person, Table, formatDateTime } from "@/components/ui";
import { api } from "@/lib/api";

type UserRow = { id: string; name: string; email: string; status: string; mfa_enabled: boolean; last_login_at: string | null; company_count: number };

export default function PlatformUsersPage() {
  const [users, setUsers] = useState<UserRow[] | null>(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");

  useEffect(() => {
    api<{ data: UserRow[] }>("/platform/users", { query: { search, status }, noCompany: true }).then((result) => setUsers(result.data));
  }, [search, status]);

  return (
    <>
      <PageHead eyebrow="Staff console" title="Customer users" subtitle="Find a person across every customer company, e.g. to help with sign-in problems." />
      <Panel
        title="Users"
        right={
          <div className="row">
            <input className="input" style={{ width: 240 }} placeholder="Search by name or email" value={search} onChange={(event) => setSearch(event.target.value)} />
            <select style={{ width: 130 }} value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Status">
              <option value="">All</option>
              <option value="active">Can sign in</option>
              <option value="disabled">Locked</option>
            </select>
          </div>
        }
        bodyless
      >
        {users && users.length === 0 ? <EmptyState title="No users found" /> : (
          <Table headers={["Person", "Companies", "Two-step sign-in", "Last sign-in", "Sign-in"]}>
            {users?.map((user) => (
              <tr key={user.id}>
                <td><Link href={`/platform/users/${user.id}`} style={{ textDecoration: "none" }}><Person name={user.name} detail={user.email} /></Link></td>
                <td>{user.company_count}</td>
                <td>{user.mfa_enabled ? "On" : "Off"}</td>
                <td>{user.last_login_at ? formatDateTime(user.last_login_at) : "Never"}</td>
                <td><Badge status={user.status === "disabled" ? "blocked" : "active"} label={user.status === "disabled" ? "Locked" : "Active"} /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}
