"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, formatDateTime, humanize, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export type Approval = {
  id: string;
  type: string;
  title: string;
  summary: Record<string, string | number | null> | null;
  status: "pending" | "info_requested" | "approved" | "declined" | "cancelled";
  requested_by: string | null;
  is_mine: boolean;
  can_decide: boolean;
  can_reassign: boolean;
  created_at: string;
  steps: { order: number; approver: string | null; status: string; decided_by: string | null; decided_at: string | null; comment: string | null }[];
  history: { action: string; actor: string | null; comment: string | null; at: string }[];
};

const actionLabel: Record<string, string> = {
  submitted: "sent the request",
  approved: "approved",
  declined: "declined",
  info_requested: "asked for more information",
  info_provided: "answered",
  reassigned: "reassigned it",
  cancelled: "cancelled it",
};

export const approvalStatus: Record<string, React.ReactNode> = {
  pending: <Badge tone="amber" label="Awaiting approval" />,
  info_requested: <Badge tone="blue" label="Needs information" />,
  approved: <Badge tone="green" label="Approved" />,
  declined: <Badge tone="red" label="Declined" />,
  cancelled: <Badge tone="gray" label="Cancelled" />,
};

/** One approval request: facts, approvers, history, and the actions open to this viewer. */
export function ApprovalDetail({ id, onChanged, members = [] }: { id: string; onChanged?: () => void; members?: { id: string; name: string }[] }) {
  const [approval, setApproval] = useState<Approval | null>(null);
  const [comment, setComment] = useState("");
  const [reassignTo, setReassignTo] = useState("");
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setApproval(await api<Approval>(`/approvals/${id}`));
  }, [id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- load when the request changes
    void load();
  }, [load]);

  async function post(path: string, body: unknown) {
    setError(null);
    try {
      setApproval(await api<Approval>(`/approvals/${id}/${path}`, { body }));
      setComment("");
      onChanged?.();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("That did not work.", 0));
    }
  }

  if (!approval) return <div className="empty">Loading…</div>;

  return (
    <Panel title={<>{approval.title} {approvalStatus[approval.status]}</>} subtitle={`Requested by ${approval.requested_by ?? "—"} · ${formatDateTime(approval.created_at)}`}>
      <div className="stack">
        {error && <Banner tone="error">{error.message}</Banner>}
        {approval.summary && (
          <div className="formgrid">
            {Object.entries(approval.summary).map(([key, value]) => (
              <Field key={key} label={humanize(key)}><div>{value ?? "—"}</div></Field>
            ))}
          </div>
        )}
        <div>
          <div className="sectiontitle">Approvers</div>
          {approval.steps.map((step) => (
            <div key={step.order} className="row" style={{ padding: "4px 0" }}>
              <span>{step.order}. {step.approver ?? "—"}</span>
              <span className="tiny">{step.status === "waiting" ? "Later step" : humanize(step.status)}{step.decided_by ? ` by ${step.decided_by}` : ""}</span>
            </div>
          ))}
        </div>
        <div>
          <div className="sectiontitle">History</div>
          {approval.history.map((event, index) => (
            <div key={index} className="tiny" style={{ padding: "3px 0" }}>
              <strong style={{ color: "var(--ink)" }}>{event.actor ?? "Someone"}</strong> {actionLabel[event.action] ?? event.action} · {formatDateTime(event.at)}
              {event.comment && <div style={{ marginLeft: 10 }}>“{event.comment}”</div>}
            </div>
          ))}
        </div>
        {approval.can_decide && (
          <div className="stack">
            <Field label="Comment" hint="Required when declining or asking for information." error={error?.errors.comment?.[0]}>
              <textarea className="input" value={comment} onChange={(event) => setComment(event.target.value)} />
            </Field>
            <div className="row" style={{ justifyContent: "flex-start" }}>
              <button className="btn primary" onClick={() => void post("decide", { decision: "approve", comment })}>Approve</button>
              <button className="btn" onClick={() => void post("decide", { decision: "request_info", comment })}>Ask for information</button>
              <button className="btn danger" onClick={() => void post("decide", { decision: "decline", comment })}>Decline</button>
            </div>
          </div>
        )}
        {approval.is_mine && approval.status === "info_requested" && (
          <div className="stack">
            <Field label="Your answer"><textarea className="input" value={comment} onChange={(event) => setComment(event.target.value)} /></Field>
            <div><button className="btn primary" onClick={() => void post("info", { comment })}>Send answer</button></div>
          </div>
        )}
        {approval.can_reassign && members.length > 0 && (
          <div className="row" style={{ justifyContent: "flex-start" }}>
            <select style={{ width: 220 }} value={reassignTo} onChange={(event) => setReassignTo(event.target.value)} aria-label="Reassign to">
              <option value="">Reassign to…</option>
              {members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
            </select>
            <button className="btn" disabled={!reassignTo} onClick={() => void post("reassign", { approver_user_id: reassignTo, comment })}>Reassign</button>
          </div>
        )}
      </div>
    </Panel>
  );
}
