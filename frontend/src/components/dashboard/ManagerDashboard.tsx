"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, Modal, Panel, Person, Stat, Table, formatDateTime, initials } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Member = { id: string; name: string; job_title: string | null; location: string | null; direct_report: boolean; clock_in_at: string | null; today: { status: string; label: string } };
type Request = { approval_id: string; type: string; title: string; summary: string | null; requested_by: string | null; created_at: string };
type Data = {
  totals: { team: number; direct_reports: number; leave_to_review: number; corrections_to_review: number; other_to_review: number; present: number; on_leave: number; not_checked_in: number };
  requests: Request[]; team: Member[];
};

const TODAY_TONE: Record<string, string> = { present: "green", finished: "gray", on_leave: "blue", not_checked_in: "amber", onboarding: "blue" };

/** The manager dashboard from the visual design ("Team workspace"): team today and requests to decide. No pay data. */
export function ManagerDashboard() {
  const [data, setData] = useState<Data | null>(null);
  const [declining, setDeclining] = useState<Request | null>(null);
  const [comment, setComment] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    api<Data>("/dashboard/manager").then(setData);
  }, []);

  useEffect(load, [load]);

  async function decide(request: Request, decision: "approve" | "decline", note?: string) {
    setError(null);
    try {
      await api(`/approvals/${request.approval_id}/decide`, { body: { decision, comment: note || null } });
      setNotice(`${request.title}: ${decision === "approve" ? "approved" : "declined"}.`);
      setDeclining(null);
      setComment("");
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not record the decision.");
    }
  }

  if (!data) return <div className="empty">Loading your team…</div>;
  const { totals } = data;

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}
      <div className="grid stats">
        <Stat label="Direct reports" icon="♧" value={totals.direct_reports} note={totals.team > totals.direct_reports ? `${totals.team} people in your team` : "Your team"} />
        <Stat label="Present today" icon="◷" value={totals.present} note={`${totals.not_checked_in} not checked in · ${totals.on_leave} on leave`} />
        <Stat label="Leave to review" icon="▣" value={totals.leave_to_review} note="Waiting for your decision" />
        <Stat label="Attendance corrections" icon="✓" value={totals.corrections_to_review} note={totals.other_to_review ? `${totals.other_to_review} other requests` : "Waiting for your decision"} />
      </div>
      <Panel title="Requests awaiting your decision" right={<Link className="btn soft" href="/leave?tab=requests">Open leave requests</Link>}>
        {data.requests.length === 0 ? <span className="muted">Nothing is waiting for you.</span> : data.requests.map((request) => (
          <div key={request.approval_id} className="listrow">
            <div className="mini">{initials(request.requested_by ?? "?")}</div>
            <div className="info"><b>{request.title}</b><small>{request.summary ?? ""}{request.summary ? " · " : ""}asked {formatDateTime(request.created_at)}</small></div>
            <button className="btn" onClick={() => setDeclining(request)}>Decline</button>
            <button className="btn primary" onClick={() => void decide(request, "approve")}>Approve</button>
          </div>
        ))}
      </Panel>
      <Panel title="My team" right={<Link className="btn soft" href="/employees">Employee directory</Link>} bodyless>
        {data.team.length === 0 ? <div className="empty">Nobody reports to you yet.</div> : (
          <Table headers={["Employee", "Location", "Today", "Status"]}>
            {data.team.map((member) => (
              <tr key={member.id}>
                <td><Link href={`/employees/${member.id}`} style={{ textDecoration: "none" }}><Person name={member.name} detail={member.job_title} /></Link></td>
                <td>{member.location ?? "—"}</td>
                <td>{member.clock_in_at ? new Date(member.clock_in_at).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) : "—"}</td>
                <td><Badge tone={TODAY_TONE[member.today.status] ?? "gray"} label={member.today.label} /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {declining && (
        <Modal title={`Decline: ${declining.title}`} onClose={() => setDeclining(null)} footer={<><button className="btn" onClick={() => setDeclining(null)}>Cancel</button><button className="btn danger" onClick={() => void decide(declining, "decline", comment)}>Decline</button></>}>
          <Field label="Reason" hint="The person who asked will see this.">
            <textarea className="input" rows={3} value={comment} onChange={(event) => setComment(event.target.value)} />
          </Field>
        </Modal>
      )}
    </>
  );
}
