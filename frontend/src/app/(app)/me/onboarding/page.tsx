"use client";

import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Field, formatDate, PageHead, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type FieldDef = { label: string; required: boolean; options?: string[]; hint?: string };
type Task = {
  id: string;
  title: string;
  description: string | null;
  is_required: boolean;
  employee_field_group: string | null;
  status: "todo" | "submitted" | "done" | "blocked";
  review_note: string | null;
  due_date: string | null;
  fields: Record<string, FieldDef>;
};

function TaskCard({ task, onDone }: { task: Task; onDone: (message: string) => void }) {
  const [values, setValues] = useState<Record<string, string>>({});
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  const open = task.status === "todo";

  async function submit() {
    setSaving(true);
    setError(null);
    try {
      const result = await api<{ message: string }>(`/me/onboarding/tasks/${task.id}`, { body: { values } });
      onDone(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not send.", 0));
      setSaving(false);
    }
  }

  const status = { todo: <Badge tone="amber" label="To do" />, submitted: <Badge tone="blue" label="With HR" />, done: <Badge tone="green" label="Done" />, blocked: <Badge tone="red" label="On hold" /> }[task.status];

  return (
    <Panel title={<>{task.title} {status}</>} subtitle={`${task.is_required ? "Required" : "Optional"}${task.due_date ? ` · due ${formatDate(task.due_date)}` : ""}`}>
      {task.review_note && open && <Banner tone="info">HR asked for a change: {task.review_note}</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      {!open ? (
        <span className="muted">{task.status === "submitted" ? "Thanks. HR is checking what you sent." : task.status === "done" ? "All done." : "HR has put this task on hold."}</span>
      ) : Object.keys(task.fields).length === 0 ? (
        <button className="btn primary" disabled={saving} onClick={() => void submit()}>Mark as done</button>
      ) : (
        <div className="stack">
          {["bank", "tax"].includes(task.employee_field_group ?? "") && <div className="callout blue">These details are only visible to payroll staff and are stored encrypted.</div>}
          <div className="formgrid">
            {Object.entries(task.fields).map(([key, field]) => (
              <Field key={key} label={field.label} required={field.required} hint={field.hint} error={error?.errors[key]?.[0]}>
                {field.options ? (
                  <select value={values[key] ?? ""} onChange={(event) => setValues({ ...values, [key]: event.target.value })}>
                    <option value="">Choose</option>
                    {field.options.map((option) => <option key={option} value={option}>{option.replace(/_/g, " ")}</option>)}
                  </select>
                ) : (
                  <input className="input" type={key === "date_of_birth" ? "date" : "text"} value={values[key] ?? ""} onChange={(event) => setValues({ ...values, [key]: event.target.value })} />
                )}
              </Field>
            ))}
          </div>
          <div><button className="btn primary" disabled={saving} onClick={() => void submit()}>Send to HR</button></div>
        </div>
      )}
    </Panel>
  );
}

function MyOnboardingPage() {
  const [tasks, setTasks] = useState<Task[] | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Task[] }>("/me/onboarding").then((result) => setTasks(result.data)).catch(() => setTasks([]));
  }, []);

  useEffect(load, [load]);

  const done = tasks?.filter((task) => task.status === "done").length ?? 0;

  return (
    <>
      <PageHead eyebrow="Me" title="My onboarding" subtitle="A few things we need from you before and after your first day." />
      {notice && <Banner tone="success">{notice}</Banner>}
      {tasks && tasks.length === 0 ? (
        <EmptyState icon="✓" title="Nothing to do">You have no onboarding tasks.</EmptyState>
      ) : (
        <div className="stack">
          {tasks && <div className="progress"><span style={{ width: `${(done / Math.max(tasks.length, 1)) * 100}%` }} /></div>}
          {tasks?.map((task) => <TaskCard key={task.id} task={task} onDone={(message) => { setNotice(message); load(); }} />)}
        </div>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["self.profile.view"]}>
      <MyOnboardingPage />
    </Guard>
  );
}
