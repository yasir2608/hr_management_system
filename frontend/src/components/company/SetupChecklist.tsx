"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, Panel } from "@/components/ui";
import { api } from "@/lib/api";

type Step = { key: string; label: string; description: string; status: "complete" | "outstanding" | "skipped"; optional: boolean; required_for_payroll: boolean; link: string };
type Checklist = { steps: Step[]; completed: number; total: number; ready_for_payroll: boolean };

export function SetupChecklist({ compact = false }: { compact?: boolean }) {
  const [checklist, setChecklist] = useState<Checklist | null>(null);

  useEffect(() => {
    api<Checklist>("/company/setup-checklist").then(setChecklist).catch(() => undefined);
  }, []);

  if (!checklist) return null;
  if (compact && checklist.completed === checklist.total) return null;

  const percent = Math.round((checklist.completed / checklist.total) * 100);

  return (
    <Panel
      title="Workspace setup checklist"
      subtitle={`${checklist.completed} of ${checklist.total} steps complete`}
      right={checklist.ready_for_payroll ? <Badge tone="green" label="Ready for payroll" /> : <Badge tone="amber" label="Payroll blocked" />}
    >
      <div className="progress" style={{ marginBottom: 14 }}><span style={{ width: `${percent}%` }} /></div>
      {!checklist.ready_for_payroll && (
        <div className="callout amber" style={{ marginBottom: 12 }}>You can use HR features now. Production payroll stays blocked until the steps marked “Needed for payroll” are complete.</div>
      )}
      <div className="stack" style={{ gap: 8 }}>
        {checklist.steps.map((step) => (
          <div key={step.key} className="row" style={{ padding: "6px 0", borderBottom: "1px solid #eff1f5" }}>
            <div>
              <strong>{step.status === "complete" ? "✓ " : ""}{step.label}</strong>
              <div className="tiny">
                {step.description}
                {step.optional && " · Optional"}
                {step.required_for_payroll && " · Needed for payroll"}
              </div>
            </div>
            <div className="row">
              <Badge status={step.status === "outstanding" ? "pending" : step.status} label={{ complete: "Complete", outstanding: "To do", skipped: "Skipped" }[step.status]} />
              {step.status !== "complete" && <Link className="btn soft" href={step.link}>Open</Link>}
            </div>
          </div>
        ))}
      </div>
    </Panel>
  );
}
