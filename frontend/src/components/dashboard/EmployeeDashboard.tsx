"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Panel, Stat, formatDate, humanize } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Data = {
  employee: { id: string; name: string; job_title: string | null; work_country: string | null; status: string };
  leave: { leave_type_id: string; leave_type: string; available: number; taken: number; pending: number; entitlement: number }[] | null;
  attendance: { enabled: boolean; clocked_in: boolean; open_shift: { clock_in_local?: string } | null; minutes_today: number } | null;
  next_payday: string | null;
  latest_payslip: null;
  requests: { id: string; leave_type: string | null; status: string; start_date: string; end_date: string; days: number }[];
  profile: { percent: number; missing: string[] };
};

const STATUS_TONE: Record<string, string> = { pending: "amber", info_requested: "amber", approved: "green", declined: "red" };

function hours(minutes: number) {
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/** The employee's own dashboard from the visual design ("My HR workspace"). */
export function EmployeeDashboard() {
  const [data, setData] = useState<Data | null | undefined>(undefined);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Data | null }>("/dashboard/me").then((result) => setData(result.data));
  }, []);

  useEffect(load, [load]);

  async function clock(direction: "in" | "out") {
    setError(null);
    try {
      setNotice((await api<{ message: string }>(`/me/clock/${direction}`, { method: "POST", body: {} })).message);
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not record that.");
    }
  }

  if (data === undefined) return <div className="empty">Loading…</div>;
  if (data === null) return null;
  const annual = data.leave?.find((balance) => /annual/i.test(balance.leave_type)) ?? data.leave?.[0];
  const attendance = data.attendance;

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}
      <div className="grid stats">
        <Stat label={annual ? `${annual.leave_type} balance` : "Leave balance"} icon="▣" value={annual ? `${annual.available} days` : "—"} note={annual ? `${annual.taken} taken · ${annual.pending} waiting for approval` : "No leave types yet"} />
        <div className="panel stat">
          <div className="statlabel"><span>Attendance today</span><span className="staticon">◷</span></div>
          <div className="statnum" style={{ fontSize: 20 }}>{!attendance?.enabled ? "Not tracked" : attendance.clocked_in ? "Clocked in" : "Not clocked in"}</div>
          {attendance?.enabled && (
            <div className="row" style={{ justifyContent: "flex-start", marginTop: 6 }}>
              <button className={`btn ${attendance.clocked_in ? "" : "primary"}`} onClick={() => void clock(attendance.clocked_in ? "out" : "in")}>{attendance.clocked_in ? "Clock out" : "Clock in"}</button>
              {attendance.minutes_today > 0 && <span className="tiny">{hours(attendance.minutes_today)} today</span>}
            </div>
          )}
        </div>
        <Stat label="Next payday" icon="＄" value={data.next_payday ? formatDate(data.next_payday) : "—"} note={data.next_payday ? "Company pay schedule" : "Not set up yet"} />
        <Stat label="Latest payslip" icon="▤" value="—" note="Payslips appear here once payroll is approved" />
      </div>
      <div className="grid two">
        <Panel title="My requests" right={data.leave && <Link className="btn primary" href="/leave">＋ Request leave</Link>}>
          {data.requests.length === 0 ? <span className="muted">No recent requests.</span> : data.requests.map((request) => (
            <div key={request.id} className="listrow">
              <div className="info"><b>{request.leave_type} · {request.start_date === request.end_date ? formatDate(request.start_date) : `${formatDate(request.start_date)} – ${formatDate(request.end_date)}`}</b><small>{request.days} day{request.days === 1 ? "" : "s"}</small></div>
              <Badge tone={STATUS_TONE[request.status] ?? "gray"} label={humanize(request.status)} />
            </div>
          ))}
        </Panel>
        <Panel title="My profile" right={<Link className="btn soft" href="/me">View profile</Link>}>
          <div className="info" style={{ marginBottom: 10 }}><b>{data.employee.name}</b><div className="tiny">{data.employee.job_title ?? "—"}</div></div>
          <div className="row" style={{ marginBottom: 6 }}><span className="tiny">Profile complete</span><b>{data.profile.percent}%</b></div>
          <div className="progress"><span style={{ width: `${data.profile.percent}%` }} /></div>
          {data.profile.missing.length > 0
            ? <p className="tiny" style={{ marginTop: 10 }}>Still needed: {data.profile.missing.join(", ")}.</p>
            : <p className="tiny" style={{ marginTop: 10 }}>Your profile is complete.</p>}
        </Panel>
      </div>
    </>
  );
}
