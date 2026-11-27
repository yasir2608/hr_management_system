"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, Panel, Stat, formatDate, humanize, money } from "@/components/ui";
import { api } from "@/lib/api";

type Period = { start: string; end: string; pay_date: string; input_cutoff: string };
type Group = {
  id: string; name: string; country_name: string; period: Period | null; days_to_pay: number | null;
  readiness: { ready: number; needs_review: number; missing_information: number }; employees: number; setup_issues: string[];
  run: { id: string; status: string } | null;
};
type OpenRun = { id: string; group: string | null; status: string; period_start: string; period_end: string; pay_date: string; blocking: number; warnings_open: number; can_approve: boolean };
type RecentRun = { id: string; group: string | null; currency: string; period_start: string; period_end: string; employees: number | null; net_pay: string | null };
type Data = {
  totals: { next_pay_date: string | null; next_pay_group: string | null; days_to_next_pay: number | null; employees: number; ready: number; needs_review: number; missing_information: number; ready_percent: number; open_runs: number; blocking_issues: number; warnings_open: number };
  groups: Group[]; open_runs: OpenRun[]; recent_runs: RecentRun[]; amounts_restricted: boolean;
};

/** Payroll at a glance: next pay date, readiness, open runs with their issues, recent approved runs. */
export function PayrollDashboard({ heading }: { heading?: boolean }) {
  const [data, setData] = useState<Data | null>(null);

  useEffect(() => {
    api<Data>("/dashboard/payroll").then(setData);
  }, []);

  if (!data) return null;
  const { totals } = data;

  return (
    <>
      {heading && <div className="sectiontitle" style={{ marginTop: 24 }}>Payroll</div>}
      <div className="grid stats">
        <Stat label="Next pay date" icon="＄" value={totals.next_pay_date ? formatDate(totals.next_pay_date) : "—"} note={totals.next_pay_group ? `${totals.next_pay_group}${totals.days_to_next_pay !== null ? ` · ${totals.days_to_next_pay} day${totals.days_to_next_pay === 1 ? "" : "s"} away` : ""}` : "No pay schedule yet"} />
        <Stat label="Payroll readiness" icon="✓" value={`${totals.ready_percent}%`} note={`${totals.ready} ready · ${totals.needs_review} to review · ${totals.missing_information} missing`} />
        <Stat label="Open pay runs" icon="▤" value={totals.open_runs} note={totals.open_runs ? `${totals.blocking_issues} blocking · ${totals.warnings_open} warnings` : "Nothing in progress"} />
        <Stat label="People on payroll" icon="♙" value={totals.employees} note={`${data.groups.length} payroll group${data.groups.length === 1 ? "" : "s"}`} />
      </div>
      <div className="grid two">
        <Panel title="Pay calendar" subtitle="Current pay period for each payroll group" right={<Link className="btn soft" href="/payroll/runs">All runs</Link>}>
          {data.groups.length === 0 ? <span className="muted">No payroll groups yet. <Link className="link" href="/payroll/groups">Create one</Link>.</span> : data.groups.map((group) => (
            <div key={group.id} className="listrow">
              <div className="mini">{group.country_name.slice(0, 2).toUpperCase()}</div>
              <div className="info">
                <b>{group.name}</b>
                <small>{group.period ? `${formatDate(group.period.start)} – ${formatDate(group.period.end)} · paid ${formatDate(group.period.pay_date)}` : "No pay schedule"}</small>
                {group.setup_issues.length > 0 && <small style={{ display: "block", color: "var(--red)" }}>{group.setup_issues[0]}</small>}
                {group.employees > 0 && <div className="progress" style={{ marginTop: 6 }}><span style={{ width: `${Math.round(((group.readiness.ready + group.readiness.needs_review) / group.employees) * 100)}%` }} /></div>}
              </div>
              {group.run
                ? <Link className="btn" href={`/payroll/runs/${group.run.id}`}>{humanize(group.run.status)}</Link>
                : <Link className="btn" href="/payroll/readiness">{group.readiness.missing_information ? `${group.readiness.missing_information} missing` : "Ready"}</Link>}
            </div>
          ))}
        </Panel>
        <Panel title="Runs needing attention" right={<Link className="btn soft" href="/payroll/readiness">Readiness</Link>}>
          {data.open_runs.length === 0 ? <span className="muted">No runs in progress.</span> : data.open_runs.map((run) => (
            <div key={run.id} className="listrow">
              <div className="info">
                <b>{run.group} · {formatDate(run.period_start)} – {formatDate(run.period_end)}</b>
                <small>Pay date {formatDate(run.pay_date)} · {humanize(run.status)}</small>
              </div>
              {run.blocking > 0 ? <Badge tone="red" label={`${run.blocking} blocking`} /> : run.warnings_open > 0 ? <Badge tone="amber" label={`${run.warnings_open} to review`} /> : <Badge tone="green" label={run.can_approve ? "Ready to approve" : "No issues"} />}
              <Link className="btn" href={`/payroll/runs/${run.id}`}>Open</Link>
            </div>
          ))}
          {data.recent_runs.length > 0 && <div className="sectiontitle">Recently approved</div>}
          {data.recent_runs.map((run) => (
            <div key={run.id} className="listrow">
              <div className="info"><b>{run.group} · {formatDate(run.period_start)} – {formatDate(run.period_end)}</b><small>{run.employees ?? "—"} employees{!data.amounts_restricted && run.net_pay && ` · net ${money(run.net_pay, run.currency)}`}</small></div>
              <Link className="btn" href={`/payroll/runs/${run.id}`}>View</Link>
            </div>
          ))}
        </Panel>
      </div>
    </>
  );
}
