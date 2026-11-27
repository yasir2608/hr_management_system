"use client";

import { useEffect, useState } from "react";
import { Banner, Field, Modal } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Submission = { fields: Record<string, { label: string }>; values: Record<string, string | null>; can_review: boolean };

/** HR or payroll checks what a new starter sent, then approves it or asks for a correction. */
export function ReviewSubmissionModal({ taskId, title, onClose, onDone }: { taskId: string; title: string; onClose: () => void; onDone: () => void }) {
  const [submission, setSubmission] = useState<Submission | null>(null);
  const [note, setNote] = useState("");
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    api<Submission>(`/onboarding/tasks/${taskId}/submission`).then(setSubmission);
  }, [taskId]);

  async function decide(decision: "approve" | "correct") {
    setError(null);
    try {
      await api(`/onboarding/tasks/${taskId}/review`, { body: { decision, note: decision === "correct" ? note : null } });
      onDone();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal
      title={`Review: ${title}`}
      onClose={onClose}
      footer={submission?.can_review && (
        <>
          <button className="btn" onClick={() => void decide("correct")}>Ask for a correction</button>
          <button className="btn primary" onClick={() => void decide("approve")}>Approve</button>
        </>
      )}
    >
      {error && <Banner tone="error">{error.message}</Banner>}
      {!submission ? (
        <div className="empty">Loading…</div>
      ) : (
        <div className="stack">
          {!submission.can_review && <div className="callout amber">Bank and tax details are reviewed by payroll staff. You can see that they were sent, but not the values.</div>}
          <div className="formgrid">
            {Object.entries(submission.values).map(([key, value]) => (
              <Field key={key} label={submission.fields[key]?.label ?? key}><div>{value || <span className="muted">—</span>}</div></Field>
            ))}
          </div>
          {submission.can_review && (
            <Field label="Note for the employee" optional hint="Required if you ask for a correction." error={error?.errors.note?.[0]}>
              <input className="input" value={note} onChange={(event) => setNote(event.target.value)} />
            </Field>
          )}
        </div>
      )}
    </Modal>
  );
}
