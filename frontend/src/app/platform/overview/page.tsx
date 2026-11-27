"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, EmptyState, PageHead, Panel, Stat, Table, formatDate, formatDateTime } from "@/components/ui";
import { api } from "@/lib/api";
import { countryName } from "@/lib/countries";

type Overview = {
  totals: Record<"companies" | "active_companies" | "suspended_companies" | "new_companies_30d" | "customer_users" | "active_employees" | "active_contractors" | "open_support_sessions", number>;
  attention: { id: string; name: string; status: string; reasons: string[] }[];
  recent_companies: { id: string; name: string; country_code: string; status: string; created_at: string }[];
  recent_staff_activity: { id: string; action: string; description: string; created_at: string; actor: string | null; company: { id: string; name: string } | null }[];
};

export default function PlatformOverviewPage() {
  const [overview, setOverview] = useState<Overview | null>(null);

  useEffect(() => {
    api<Overview>("/platform/overview", { noCompany: true }).then(setOverview);
  }, []);

  if (!overview) return <div className="empty">Loading…</div>;
  const { totals } = overview;

  return (
    <>
      <PageHead eyebrow="Staff console" title="Platform overview" subtitle="Every customer company at a glance. Only counts are shown here; open a company for details." />
      <div className="grid stats">
        <Stat label="Customer companies" value={totals.companies} note={`${totals.active_companies} active · ${totals.suspended_companies} suspended`} />
        <Stat label="New in 30 days" value={totals.new_companies_30d} />
        <Stat label="Customer users" value={totals.customer_users} note={`${totals.active_employees} employees · ${totals.active_contractors} contractors`} />
        <Stat label="Open support sessions" value={totals.open_support_sessions} />
      </div>
      <div className="grid two">
        <Panel title="Needs attention" subtitle="Suspended, without active users, or quiet for 30 days." bodyless={overview.attention.length > 0}>
          {overview.attention.length === 0 ? <span className="muted">Nothing needs attention.</span> : (
            <Table headers={["Company", "Why", "Status"]}>
              {overview.attention.map((company) => (
                <tr key={company.id}>
                  <td><Link href={`/companies/${company.id}`}>{company.name}</Link></td>
                  <td>{company.reasons.join(" · ")}</td>
                  <td><Badge status={company.status} /></td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
        <Panel title="Newest companies" bodyless>
          <Table headers={["Company", "Country", "Created", "Status"]}>
            {overview.recent_companies.map((company) => (
              <tr key={company.id}>
                <td><Link href={`/companies/${company.id}`}>{company.name}</Link></td>
                <td>{countryName(company.country_code)}</td>
                <td>{formatDate(company.created_at)}</td>
                <td><Badge status={company.status} /></td>
              </tr>
            ))}
          </Table>
        </Panel>
      </div>
      <Panel title="Recent staff activity" bodyless>
        {overview.recent_staff_activity.length === 0 ? <EmptyState title="No staff activity yet" /> : (
          <Table headers={["When", "Who", "What", "Company"]}>
            {overview.recent_staff_activity.map((entry) => (
              <tr key={entry.id}>
                <td>{formatDateTime(entry.created_at)}</td>
                <td>{entry.actor ?? "—"}</td>
                <td>{entry.description}</td>
                <td>{entry.company ? <Link href={`/companies/${entry.company.id}`}>{entry.company.name}</Link> : "—"}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}
