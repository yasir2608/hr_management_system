"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, Modal, Panel, Table, formatDateTime } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Issue = { key: string; source: string; severity: "blocking" | "warning"; message: string; reviewed: { by: string; at: string; note: string } | null };
type Line = { id: string; employee_id: string; employee_name: string; included: boolean; issues: Issue[] };
export type IssueSummary = { blocking: number; warnings_open: number; warnings_reviewed: number; can_approve: boolean };
type Data = { summary: IssueSummary; lines: Line[] };

const SOURCE: Record<string, string> = { readiness: "Readiness", calculation: "Calculation", comparison: "Compared with last period" };

/**
 * A run's blocking issues (fix and recalculate) and warnings (mark reviewed with a note). Reloads when the run
 * is recalculated (version changes).
 */
export function RunIssuesPanel({ runId, version, canReview, onChange }: { runId: string; version: string; canReview: boolean; onChange: (summary: IssueSummary) => void }) {
  const [data, setData] = useState<Data | null>(null);
  const [reviewing, setReviewing] = useState<{ line: Line; issue: Issue } | null>(null);
  const [note, setNote] = useState("");
  const [error, setError] = useState<ApiError | null>(null);

  const apply = useCallback((result: Data) => { setData(result); onChange(result.summary); }, [onChange]);

  useEffect(() => {
    api<Data>(`/payroll/runs/${runId}/issues`).then(apply);
  }, [runId, version, apply]);

  async function send(path: "review" | "unreview", line: Line, issue: Issue) {
    setError(null);
    try {
      apply(await api<Data>(`/payroll/runs/${runId}/issues/${path}`, { body: { line_id: line.id, key: issue.key, note: path === "review" ? note : undefined } }));
      setReviewing(null);
      setNote("");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  if (!data || data.lines.length === 0) return null;
  const { summary } = data;

  return (
    <Panel
      title="Issues"
      subtitle={`${summary.blocking} blocking · ${summary.warnings_open} warning${summary.warnings_open === 1 ? "" : "s"} to review · ${summary.warnings_reviewed} reviewed`}
      bodyless
    >
      <Table headers={["Employee", "Issue", "Found by", ""]}>
        {data.lines.flatMap((line) => line.issues.map((issue) => (
          <tr key={`${line.id}-${issue.key}`} style={{ opacity: line.included ? 1 : 0.7 }}>
            <td>{line.employee_name}{!line.included && <div className="tiny">Left out of this run</div>}</td>
            <td>
              <Badge tone={issue.severity === "blocking" ? "red" : issue.reviewed ? "green" : "amber"} label={issue.severity === "blocking" ? "Blocks" : issue.reviewed ? "Reviewed" : "Review"} /> {issue.message}
              {issue.reviewed && <div className="tiny">{issue.reviewed.by}, {formatDateTime(issue.reviewed.at)}: “{issue.reviewed.note}”</div>}
            </td>
            <td className="tiny">{SOURCE[issue.source] ?? issue.source}</td>
            <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
              {canReview && issue.severity === "warning" && !issue.reviewed && <button className="btn" onClick={() => setReviewing({ line, issue })}>Mark reviewed</button>}
              {canReview && issue.reviewed && <button className="btn" onClick={() => void send("unreview", line, issue)}>Undo</button>}
              {issue.severity === "blocking" && line.included && <span className="tiny">Fix, then calculate again</span>}
            </td>
          </tr>
        )))}
      </Table>
      {reviewing && (
        <Modal title="Mark warning reviewed" onClose={() => setReviewing(null)} footer={<><button className="btn" onClick={() => setReviewing(null)}>Cancel</button><button className="btn primary" onClick={() => void send("review", reviewing.line, reviewing.issue)}>Mark reviewed</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="callout">{reviewing.line.employee_name}: {reviewing.issue.message}</div>
          <Field label="Why this is fine" required error={error?.errors.note?.[0]} hint="Kept with the run and in the activity log.">
            <textarea className="input" rows={3} value={note} onChange={(event) => setNote(event.target.value)} />
          </Field>
        </Modal>
      )}
    </Panel>
  );
}
