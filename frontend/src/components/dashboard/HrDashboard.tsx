"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, Panel, Stat, formatDate, formatDateTime, initials } from "@/components/ui";
import { api } from "@/lib/api";

type Data = {
  today: string;
  totals: { people: number; countries: number; present_today: number; expected_today: number; on_leave_today: number; returning_this_week: number; pending_approvals: number; pending_by_type: Record<string, number> };
  leave_to_review: { id: string; employee: string | null; leave_type: string | null; start_date: string; end_date: string; days: number }[];
  attendance: { checked_in: number; expected: number; percent: number; away: string[] };
  headcount: { joined_30d: number; left_30d: number; starting_soon: number; by_status: Record<string, number> };
  onboarding: { id: string; name: string; job_title: string | null; start_date: string | null; tasks_done: number; tasks_total: number }[];
  recent_activity: { id: string; action: string; description: string; actor: string | null; created_at: string }[];
};

const TYPE_NAMES: Record<string, string> = { leave_request: "leave", employee: "profile changes", attendance_correction: "attendance", contractor_invoice: "invoices" };

function dateRange(start: string, end: string) {
  return start === end ? formatDate(start) : `${formatDate(start)} – ${formatDate(end)}`;
}

/** The owner / HR dashboard from the visual design, with live company data. */
export function HrDashboard() {
  const [data, setData] = useState<Data | null>(null);

  useEffect(() => {
    api<Data>("/dashboard/hr").then(setData);
  }, []);

  if (!data) return <div className="empty">Loading your dashboard…</div>;
  const { totals, attendance } = data;
  const pendingKinds = Object.entries(totals.pending_by_type).map(([type, count]) => `${count} ${TYPE_NAMES[type] ?? type.replace(/_/g, " ")}`).join(" · ");

  return (
    <>
      <div className="grid stats">
        <Stat label="Total people" icon="♙" value={totals.people} note={`Across ${totals.countries} ${totals.countries === 1 ? "country" : "countries"}`} />
        <Stat label="Present today" icon="◷" value={totals.present_today} note={totals.expected_today ? `${attendance.percent}% of your team` : "Nobody expected today"} />
        <Stat label="On leave" icon="▣" value={totals.on_leave_today} note={totals.returning_this_week ? `${totals.returning_this_week} returning this week` : "Today"} />
        <Stat label="Pending approvals" icon="✓" value={totals.pending_approvals} note={pendingKinds || "Nothing waiting for you"} />
      </div>
      <div className="grid two">
        <Panel title="Leave requests to review" right={<Link className="btn soft" href="/leave?tab=requests">View all</Link>}>
          {data.leave_to_review.length === 0 ? <span className="muted">No leave requests are waiting for you.</span> : data.leave_to_review.map((leave) => (
            <div key={leave.id} className="listrow">
              <div className="mini">{initials(leave.employee ?? "?")}</div>
              <div className="info"><b>{leave.employee}</b><small>{leave.leave_type} · {dateRange(leave.start_date, leave.end_date)}</small></div>
              <Link className="btn" href="/leave?tab=requests">Review</Link>
            </div>
          ))}
        </Panel>
        <Panel title="Attendance today" right={<Link className="btn soft" href="/attendance">Details</Link>}>
          <div className="row" style={{ justifyContent: "flex-start", gap: 18, padding: "4px 0 14px" }}>
            <div className="ring" style={{ background: `conic-gradient(var(--blue) 0 ${attendance.percent}%, #e8edf4 ${attendance.percent}% 100%)` }}><b>{attendance.percent}%</b></div>
            <div>
              <b style={{ fontSize: 21 }}>{attendance.checked_in} <span className="muted" style={{ fontSize: 13, fontWeight: 500 }}>of {attendance.expected}</span></b>
              <div className="muted">team members checked in</div>
            </div>
          </div>
          <div className="tiny">{attendance.away.length === 0 ? "Nobody is on leave today." : `${attendance.away.length} on leave today: ${attendance.away.slice(0, 4).join(", ")}${attendance.away.length > 4 ? "…" : ""}`}</div>
        </Panel>
      </div>
      <div className="grid two">
        <Panel title="Recent team activity" right={<Link className="btn soft" href="/employees">Employee directory</Link>}>
          {data.recent_activity.length === 0 ? <span className="muted">No recent changes.</span> : data.recent_activity.map((entry) => (
            <div key={entry.id} className="listrow">
              <div className="mini">{initials(entry.actor ?? "?")}</div>
              <div className="info"><b>{entry.description}</b><small>{formatDateTime(entry.created_at)}{entry.actor && ` · ${entry.actor}`}</small></div>
            </div>
          ))}
        </Panel>
        <Panel title="People" subtitle="Last 30 days">
          <div className="listrow"><div className="info"><b>{data.headcount.joined_30d} joined</b><small>Started in the last 30 days</small></div></div>
          <div className="listrow"><div className="info"><b>{data.headcount.left_30d} left</b><small>Ended in the last 30 days</small></div></div>
          <div className="listrow"><div className="info"><b>{data.headcount.starting_soon} starting soon</b><small>Start date in the next 30 days</small></div></div>
          {data.onboarding.length > 0 && <div className="sectiontitle">Onboarding</div>}
          {data.onboarding.map((person) => (
            <div key={person.id} className="listrow">
              <div className="mini">{initials(person.name)}</div>
              <div className="info">
                <b>{person.name}</b><small>{person.job_title ?? "—"}{person.start_date && ` · starts ${formatDate(person.start_date)}`}</small>
                {person.tasks_total > 0 && <div className="progress" style={{ marginTop: 6 }}><span style={{ width: `${Math.round(person.tasks_done / person.tasks_total * 100)}%` }} /></div>}
              </div>
              <Badge tone={person.tasks_done === person.tasks_total && person.tasks_total > 0 ? "green" : "blue"} label={`${person.tasks_done}/${person.tasks_total} tasks`} />
            </div>
          ))}
        </Panel>
      </div>
    </>
  );
}
