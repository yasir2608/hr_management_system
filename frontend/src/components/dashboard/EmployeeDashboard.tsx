"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Panel, Stat, formatDate, humanize, initials, money } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { signed, type PayslipSummary } from "@/components/payroll/Payslip";

type Data = {
  employee: { id: string; name: string; job_title: string | null; work_country: string | null; status: string };
  leave: { leave_type_id: string; leave_type: string; available: number; taken: number; pending: number; entitlement: number }[] | null;
  attendance: { enabled: boolean; clocked_in: boolean; open_shift: { clock_in_local?: string } | null; minutes_today: number } | null;
  next_payday: string | null;
  latest_payslip: PayslipSummary | null;
  requests: { id: string; leave_type: string | null; status: string; start_date: string; end_date: string; days: number }[];
  profile: { percent: number; missing: string[] };
};

const STATUS_TONE: Record<string, string> = { pending: "amber", info_requested: "amber", approved: "green", declined: "red" };

function hours(minutes: number) {
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

function requestDates(start: string, end: string) {
  return start === end ? formatDate(start) : `${formatDate(start)} – ${formatDate(end)}`;
}

const REQUEST_MARK: Record<string, string> = { approved: "✓", declined: "✕", cancelled: "✕" };

/** The employee's own requests live on the "My leave" tab ("Requests" is the approvers' queue). */
const MY_REQUESTS = "/leave?tab=mine";

function attendanceNote(attendance: NonNullable<Data["attendance"]>) {
  if (!attendance.enabled) return "Attendance is not enabled for your role";
  const today = attendance.minutes_today > 0 ? `${hours(attendance.minutes_today)} today` : "";
  if (attendance.clocked_in) {
    const since = attendance.open_shift?.clock_in_local ? `Since ${attendance.open_shift.clock_in_local}` : "Shift in progress";
    return today ? `${since} · ${today}` : since;
  }
  return today ? `Clocked out · ${today}` : "Your workday hasn’t started yet";
}

/** The employee's own dashboard from the visual design ("My HR workspace"). */
export function EmployeeDashboard() {
  const { can } = useAuth();
  const [data, setData] = useState<Data | null | undefined>(undefined);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [clocking, setClocking] = useState(false);

  const load = useCallback(() => {
    api<{ data: Data | null }>("/dashboard/me")
      .then((result) => {
        setData(result.data);
        setError(null);
      })
      .catch((caught) => setError(caught instanceof ApiError ? caught.message : "We couldn’t load your dashboard."));
  }, []);

  useEffect(load, [load]);

  async function clock(direction: "in" | "out") {
    setError(null);
    setClocking(true);
    try {
      setNotice((await api<{ message: string }>(`/me/clock/${direction}`, { method: "POST", body: {} })).message);
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not record that.");
    } finally {
      setClocking(false);
    }
  }

  if (data === undefined && error) {
    return <div className="employee-dashboard"><Banner tone="error">{error}</Banner><button className="btn" onClick={load}>Try again</button></div>;
  }
  if (data === undefined) return <div className="loadingpage" aria-live="polite">Loading your dashboard…</div>;
  if (data === null) return null;
  const annual = data.leave?.find((balance) => /annual/i.test(balance.leave_type)) ?? data.leave?.[0];
  const attendance = data.attendance;
  const quickActions = [
    data.leave && { href: "/leave", icon: "＋", title: "Request time off", note: "Plan an upcoming break" },
    attendance?.enabled && { href: "/attendance", icon: "◷", title: "View attendance", note: "Check your recent records" },
    can("self.payslips.view") && { href: "/me/payslips", icon: "▤", title: "Open payslips", note: "View your pay statements" },
  ].filter((action): action is { href: string; icon: string; title: string; note: string } => Boolean(action));

  return (
    <section className="employee-dashboard">
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}
      <div className="grid stats">
        <Stat label={annual ? `${annual.leave_type} balance` : "Leave balance"} icon="▣" value={annual ? `${annual.available} days` : "—"} note={annual ? `${annual.taken} taken · ${annual.pending} waiting for approval` : "No leave types yet"} />
        <div className="panel stat employee-attendance-stat">
          <div className="statlabel"><span>Attendance today</span><span className="staticon">◷</span></div>
          <div className="statnum employee-attendance-status">{!attendance?.enabled ? "Not tracked" : attendance.clocked_in ? "You’re clocked in" : attendance.minutes_today > 0 ? "Clocked out" : "Ready when you are"}</div>
          <div className="statnote">{attendance ? attendanceNote(attendance) : "Attendance is not enabled for your role"}</div>
          {attendance?.enabled && (
            <div className="employee-clock-action">
              <button className={`btn ${attendance.clocked_in ? "" : "primary"}`} disabled={clocking} onClick={() => void clock(attendance.clocked_in ? "out" : "in")}>{clocking ? "Saving…" : attendance.clocked_in ? "Clock out" : "Clock in"}</button>
            </div>
          )}
        </div>
        <Stat label="Next payday" icon="＄" value={data.next_payday ? formatDate(data.next_payday) : "—"} note={data.next_payday ? "Company pay schedule" : "Not set up yet"} />
        <Stat
          label="Latest payslip" icon="▤"
          value={data.latest_payslip ? (data.latest_payslip.kind === "adjustment" ? signed(data.latest_payslip.net_difference ?? "0", data.latest_payslip.currency) : money(data.latest_payslip.net_pay ?? "0", data.latest_payslip.currency)) : "—"}
          note={data.latest_payslip ? <Link className="link" href={`/me/payslips/${data.latest_payslip.id}`}>Paid {formatDate(data.latest_payslip.pay_date)} · view payslip</Link> : "Payslips appear here once payroll is approved"}
        />
      </div>
      <div className="grid two employee-lower-grid">
        <Panel title="My requests" subtitle="Keep track of leave and other requests." right={data.leave && <Link className="btn soft" href={MY_REQUESTS}>View all</Link>}>
          {data.requests.length === 0 ? (
            <EmptyState
              icon="＋"
              title="No requests yet"
              action={data.leave ? <Link className="btn primary" href="/leave">Request leave</Link> : undefined}
            >
              Requests you submit will appear here, along with their latest status.
            </EmptyState>
          ) : data.requests.map((request) => (
            <Link key={request.id} className="employee-request-row" href={MY_REQUESTS}>
              <span className="employee-request-mark" aria-hidden="true">{REQUEST_MARK[request.status] ?? "◷"}</span>
              <span className="employee-request-copy"><b>{request.leave_type ?? "Request"}</b><small>{requestDates(request.start_date, request.end_date)} · {request.days} day{request.days === 1 ? "" : "s"}</small></span>
              <Badge tone={STATUS_TONE[request.status] ?? "gray"} label={humanize(request.status)} />
              <span className="employee-request-arrow" aria-hidden="true">›</span>
            </Link>
          ))}
        </Panel>
        <Panel title="Your profile" subtitle="Your information, in one place." right={<Link className="btn soft" href="/me">View profile</Link>}>
          <div className="employee-profile-summary">
            <div className="avatar employee-profile-avatar">{initials(data.employee.name)}</div>
            <div className="employee-profile-name"><b>{data.employee.name}</b><span>{data.employee.job_title ?? "Team member"}{data.employee.work_country ? ` · ${data.employee.work_country}` : ""}</span></div>
            <span className="employee-profile-percent">{data.profile.percent}%</span>
          </div>
          <div className="row employee-profile-progress-label"><span className="tiny">Profile completion</span><b>{data.profile.percent === 100 ? "All done" : `${data.profile.missing.length} detail${data.profile.missing.length === 1 ? "" : "s"} to add`}</b></div>
          <div className="progress employee-profile-progress" role="progressbar" aria-label="Profile completion" aria-valuemin={0} aria-valuemax={100} aria-valuenow={data.profile.percent}><span style={{ width: `${data.profile.percent}%` }} /></div>
          <div className="employee-profile-footer">
            <span>{data.profile.missing.length === 0 ? "Your profile is up to date." : `Still needed: ${data.profile.missing.slice(0, 3).join(", ")}${data.profile.missing.length > 3 ? ` and ${data.profile.missing.length - 3} more` : ""}.`}</span>
            <Link className="link" href="/me">{data.profile.percent === 100 ? "Review profile" : "Complete profile"} <span aria-hidden="true">→</span></Link>
          </div>
        </Panel>
      </div>
      {quickActions.length > 0 && (
        <nav className="employee-quick-actions" aria-label="Quick actions">
          {quickActions.map((action) => (
            <Link key={action.href} href={action.href} className="employee-quick-action">
              <span className="employee-quick-icon" aria-hidden="true">{action.icon}</span>
              <span><b>{action.title}</b><small>{action.note}</small></span>
              <span className="employee-request-arrow" aria-hidden="true">›</span>
            </Link>
          ))}
        </nav>
      )}
    </section>
  );
}
