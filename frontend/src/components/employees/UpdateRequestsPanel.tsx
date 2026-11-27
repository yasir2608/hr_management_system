"use client";

import { useCallback, useEffect, useState } from "react";
import { Banner, humanize, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Change = { old: string | null; new: string | null };
type UpdateRequest = { id: string; changes: Record<string, Change>; note: string | null; status: string; created_at: string };

/** HR review of an employee's pending request to update their personal details. */
export function UpdateRequestsPanel({ employeeId, onReviewed }: { employeeId: string; onReviewed: () => void }) {
  const [pending, setPending] = useState<UpdateRequest[]>([]);
  const [declineNote, setDeclineNote] = useState("");
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    const result = await api<{ data: UpdateRequest[] }>(`/employees/${employeeId}/update-requests`).catch(() => ({ data: [] }));
    setPending(result.data.filter((request) => request.status === "pending"));
  }, [employeeId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function review(id: string, decision: "approve" | "decline") {
    setError(null);
    try {
      await api(`/update-requests/${id}/review`, { body: { decision, review_note: decision === "decline" ? declineNote : null } });
      await load();
      onReviewed();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not review.");
    }
  }

  if (pending.length === 0) return null;

  return (
    <div style={{ marginBottom: 15 }}>
      {pending.map((request) => (
        <Panel key={request.id} title="Update request from the employee" subtitle={request.note ?? undefined}>
          {error && <Banner tone="error">{error}</Banner>}
          <div className="stack" style={{ gap: 6 }}>
            {Object.entries(request.changes).map(([field, change]) => (
              <div key={field}>{humanize(field)}: <span className="muted">{change.old || "—"}</span> → <strong>{change.new || "—"}</strong></div>
            ))}
          </div>
          <div className="row" style={{ marginTop: 12, justifyContent: "flex-start" }}>
            <button className="btn primary" onClick={() => void review(request.id, "approve")}>Approve and update</button>
            <input className="input" style={{ width: 260 }} placeholder="Reason, if declining" value={declineNote} onChange={(event) => setDeclineNote(event.target.value)} />
            <button className="btn danger" onClick={() => void review(request.id, "decline")}>Decline</button>
          </div>
        </Panel>
      ))}
    </div>
  );
}
