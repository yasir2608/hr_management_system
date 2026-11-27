"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, formatDate, humanize, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export type OnboardingTaskRow = {
  id: string;
  title: string;
  owner_type: string;
  owner: string | null;
  due_date: string | null;
  is_required: boolean;
  employee_field_group: string | null;
  status: "todo" | "submitted" | "done" | "blocked";
  blocked_reason: string | null;
  review_note: string | null;
  overdue: boolean;
};

const statusBadge: Record<string, React.ReactNode> = {
  todo: <Badge tone="gray" label="To do" />,
  submitted: <Badge tone="blue" label="Waiting for review" />,
  done: <Badge tone="green" label="Done" />,
  blocked: <Badge tone="red" label="Blocked" />,
};

/** An employee's onboarding checklist, for HR on the employee page. */
export function OnboardingTasksPanel({ employeeId, children }: { employeeId: string; children?: (tasks: OnboardingTaskRow[], reload: () => void) => React.ReactNode }) {
  const [tasks, setTasks] = useState<OnboardingTaskRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setTasks((await api<{ data: OnboardingTaskRow[] }>(`/onboarding/employees/${employeeId}/tasks`)).data);
  }, [employeeId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function start() {
    setError(null);
    try {
      await api(`/onboarding/employees/${employeeId}/start`, { method: "POST" });
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not start onboarding.");
    }
  }

  async function update(task: OnboardingTaskRow, status: OnboardingTaskRow["status"]) {
    setError(null);
    const blocked_reason = status === "blocked" ? window.prompt("What is blocking this task?") : null;
    if (status === "blocked" && !blocked_reason) return;
    try {
      await api(`/onboarding/tasks/${task.id}`, { method: "PATCH", body: { status, blocked_reason } });
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not update the task.");
    }
  }

  if (tasks === null) return <div className="empty">Loading…</div>;

  if (tasks.length === 0) {
    return (
      <Panel title="Onboarding">
        {error && <Banner tone="error">{error}</Banner>}
        <EmptyState icon="✓" title="Onboarding has not started" action={<button className="btn primary" onClick={() => void start()}>Start with the default checklist</button>}>
          Copies the company checklist to this person, with due dates from their start date.
        </EmptyState>
      </Panel>
    );
  }

  const done = tasks.filter((task) => task.status === "done").length;

  return (
    <div className="stack">
      {error && <Banner tone="error">{error}</Banner>}
      {children?.(tasks, () => void load())}
      <Panel title="Onboarding checklist" subtitle={`${done} of ${tasks.length} tasks done`} bodyless>
        <div className="progress" style={{ margin: "0 19px 12px" }}><span style={{ width: `${(done / tasks.length) * 100}%` }} /></div>
        <Table headers={["Task", "Who", "Due", "Status", ""]}>
          {tasks.map((task) => (
            <tr key={task.id}>
              <td style={{ whiteSpace: "normal" }}>
                <strong>{task.title}</strong> {task.is_required ? <Badge tone="amber" label="Required" /> : <span className="tiny">Optional</span>}
                {task.blocked_reason && <div className="tiny" style={{ color: "var(--red)" }}>Blocked: {task.blocked_reason}</div>}
              </td>
              <td>{humanize(task.owner_type)}{task.owner && <div className="tiny">{task.owner}</div>}</td>
              <td style={task.overdue ? { color: "var(--red)" } : undefined}>{formatDate(task.due_date)}{task.overdue && <div className="tiny">Overdue</div>}</td>
              <td>{statusBadge[task.status]}</td>
              <td>
                <div className="row" style={{ justifyContent: "flex-end" }}>
                  {task.status !== "done" && task.status !== "submitted" && <button className="btn" onClick={() => void update(task, "done")}>Mark done</button>}
                  {task.status === "todo" && <button className="btn" onClick={() => void update(task, "blocked")}>Blocked</button>}
                  {(task.status === "done" || task.status === "blocked") && <button className="btn" onClick={() => void update(task, "todo")}>Reopen</button>}
                </div>
              </td>
            </tr>
          ))}
        </Table>
      </Panel>
    </div>
  );
}
