"use client";

import { useEffect, useState } from "react";
import { Badge, formatDate, Panel } from "@/components/ui";
import { api } from "@/lib/api";
import type { Balance } from "./BalancesTab";

type Context = {
  balance: Balance | null;
  team_absences: { employee: string; leave_type: string; start_date: string; end_date: string; status: string }[];
};

/** Helps the approver decide: the employee's balance and teammates off on the same days. */
export function LeaveContext({ leaveRequestId }: { leaveRequestId: string }) {
  const [context, setContext] = useState<Context | null>(null);

  useEffect(() => {
    api<Context>(`/leave/requests/${leaveRequestId}/context`).then(setContext).catch(() => undefined);
  }, [leaveRequestId]);

  if (!context) return null;

  return (
    <div className="grid split" style={{ marginBottom: 12 }}>
      <Panel title="Balance">
        {context.balance ? (
          <div className="tiny">
            <strong style={{ fontSize: 18, color: "var(--ink)" }}>{context.balance.available}</strong> day(s) left after this request
            <div>{context.balance.taken} taken · {context.balance.pending} waiting (including this one)</div>
          </div>
        ) : <span className="muted">This leave type has no allowance.</span>}
      </Panel>
      <Panel title="Team off at the same time">
        {context.team_absences.length === 0 ? <span className="muted">Nobody else in the team.</span> : context.team_absences.map((absence, index) => (
          <div key={index} className="row tiny" style={{ padding: "3px 0" }}>
            <span><strong style={{ color: "var(--ink)" }}>{absence.employee}</strong> · {absence.leave_type}</span>
            <span>{formatDate(absence.start_date)} – {formatDate(absence.end_date)} {absence.status !== "approved" && <Badge tone="amber" label="Waiting" />}</span>
          </div>
        ))}
      </Panel>
    </div>
  );
}
