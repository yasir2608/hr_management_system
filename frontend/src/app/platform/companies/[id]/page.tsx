"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Badge, EmptyState, Field, PageHead, Panel, Person, Stat, Table, formatDate, formatDateTime } from "@/components/ui";
import { api } from "@/lib/api";
import { countryName } from "@/lib/countries";

export type PlatformCompany = {
  company: { id: string; name: string; legal_name: string | null; country_code: string; contact_email: string | null; phone: string | null; city: string | null; status: string; require_mfa: boolean; created_at: string };
  usage: { active_members: number; employees: number; contractors: number; leave_requests_30d: number; clock_ins_30d: number; last_activity_at: string | null; last_sign_in_at: string | null };
  members: { id: string; status: string; joined_at: string | null; user: { id: string; name: string; email: string; status: string; last_login_at: string | null } | null; roles: string[] }[];
  support_access: { id: string; reason: string; ticket_reference: string | null; expires_at: string; revoked_at: string | null; created_at: string; staff: string | null; active: boolean }[];
  staff_activity: { id: string; action: string; description: string; created_at: string; actor: string | null }[];
};

export default function PlatformCompanyPage() {
  const { id } = useParams<{ id: string }>();
  const [detail, setDetail] = useState<PlatformCompany | null>(null);

  useEffect(() => {
    api<PlatformCompany>(`/platform/companies/${id}`, { noCompany: true }).then(setDetail);
  }, [id]);

  if (!detail) return <div className="empty">Loading…</div>;
  const { company, usage } = detail;

  return (
    <>
      <PageHead eyebrow="Customer company" title={company.name} subtitle={`${countryName(company.country_code)} · customer since ${formatDate(company.created_at)}`} actions={<Link className="btn" href="/platform/companies">All companies</Link>} />
      <div className="grid stats">
        <Stat label="Active users" value={usage.active_members} note={`Last sign-in ${usage.last_sign_in_at ? formatDateTime(usage.last_sign_in_at) : "never"}`} />
        <Stat label="Employees" value={usage.employees} note={`${usage.contractors} active contractors`} />
        <Stat label="Last 30 days" value={usage.leave_requests_30d} note={`leave requests · ${usage.clock_ins_30d} clock-ins`} />
        <Stat label="Last activity" value={usage.last_activity_at ? formatDate(usage.last_activity_at) : "—"} />
      </div>
      <div className="grid two">
        <Panel title="Company profile" right={<Badge status={company.status} />}>
          <div className="formgrid">
            <Field label="Legal name"><div>{company.legal_name ?? "—"}</div></Field>
            <Field label="Contact email"><div>{company.contact_email ?? "—"}</div></Field>
            <Field label="Phone"><div>{company.phone ?? "—"}</div></Field>
            <Field label="City"><div>{company.city ?? "—"}</div></Field>
            <Field label="Two-step sign-in"><div>{company.require_mfa ? "Required for everyone" : "Optional"}</div></Field>
          </div>
        </Panel>
        <Panel title="Support access" subtitle="Time-limited, read-only access opened by M.Y HRMS staff." bodyless={detail.support_access.length > 0}>
          {detail.support_access.length === 0 ? <span className="muted">No support access yet.</span> : (
            <Table headers={["Opened", "By", "Reason", ""]}>
              {detail.support_access.map((grant) => (
                <tr key={grant.id}>
                  <td>{formatDateTime(grant.created_at)}</td>
                  <td>{grant.staff ?? "—"}</td>
                  <td>{grant.reason}{grant.ticket_reference && <div className="tiny">Ticket {grant.ticket_reference}</div>}</td>
                  <td><Badge tone={grant.active ? "amber" : "gray"} label={grant.active ? "Open" : grant.revoked_at ? "Ended" : "Expired"} /></td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
      </div>
      <Panel title="Members" subtitle="People with access to this company and their roles." bodyless>
        <Table headers={["Person", "Roles", "Joined", "Last sign-in", "Access"]}>
          {detail.members.map((member) => (
            <tr key={member.id}>
              <td>{member.user ? <Person name={member.user.name} detail={member.user.email} /> : "—"}</td>
              <td>{member.roles.join(", ") || "—"}</td>
              <td>{formatDate(member.joined_at)}</td>
              <td>{member.user?.last_login_at ? formatDateTime(member.user.last_login_at) : "Never"}</td>
              <td><Badge status={member.user?.status === "disabled" ? "disabled" : member.status} /></td>
            </tr>
          ))}
        </Table>
      </Panel>
      <Panel title="Staff actions on this company" bodyless>
        {detail.staff_activity.length === 0 ? <EmptyState title="No staff actions yet" /> : (
          <Table headers={["When", "Who", "What"]}>
            {detail.staff_activity.map((entry) => (
              <tr key={entry.id}>
                <td>{formatDateTime(entry.created_at)}</td>
                <td>{entry.actor ?? "—"}</td>
                <td>{entry.description}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}
