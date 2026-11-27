"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, humanize, Modal, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Task = { title: string; description?: string | null; owner_type: string; due_offset_days: number; is_required: boolean; employee_field_group: string | null };
type Template = { id: string; name: string; description: string | null; is_default: boolean; is_active: boolean; tasks: Task[] };

export function dueLabel(days: number) {
  if (days === 0) return "On the start date";
  return days < 0 ? `${-days} day(s) before start` : `${days} day(s) after start`;
}

export function TemplatesTab() {
  const [templates, setTemplates] = useState<Template[]>([]);
  const [owners, setOwners] = useState<string[]>([]);
  const [groups, setGroups] = useState<string[]>([]);
  const [editing, setEditing] = useState<{ id?: string; name: string; description: string; tasks: Task[] } | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const result = await api<{ data: Template[]; owners: string[]; field_groups: string[] }>("/onboarding/templates");
    setTemplates(result.data);
    setOwners(result.owners);
    setGroups(result.field_groups);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function save() {
    if (!editing) return;
    setError(null);
    try {
      await api(editing.id ? `/onboarding/templates/${editing.id}` : "/onboarding/templates", { method: editing.id ? "PUT" : "POST", body: editing });
      setNotice("Checklist saved. Employees who already started keep their own copy.");
      setEditing(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  async function act(path: string, method: "POST" | "DELETE") {
    try {
      const result = await api<{ message: string }>(path, { method });
      setNotice(result.message);
    } catch (caught) {
      setNotice(caught instanceof ApiError ? caught.message : "That did not work.");
    }
    await load();
  }

  const setTask = (index: number, patch: Partial<Task>) =>
    editing && setEditing({ ...editing, tasks: editing.tasks.map((task, position) => (position === index ? { ...task, ...patch } : task)) });

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      <div className="row" style={{ marginBottom: 12 }}>
        <span className="muted">Checklists are copied to each new starter; required tasks must be done before the employee is activated.</span>
        <button className="btn primary" onClick={() => { setError(null); setEditing({ name: "", description: "", tasks: [{ title: "", owner_type: "hr", due_offset_days: 0, is_required: true, employee_field_group: null }] }); }}>New checklist</button>
      </div>
      <div className="stack">
        {templates.map((template) => (
          <Panel
            key={template.id}
            title={<>{template.name} {template.is_default && <Badge tone="blue" label="Default" />}</>}
            subtitle={template.description ?? undefined}
            right={
              <div className="row">
                {!template.is_default && <button className="btn" onClick={() => void act(`/onboarding/templates/${template.id}/default`, "POST")}>Make default</button>}
                <button className="btn" onClick={() => { setError(null); setEditing({ id: template.id, name: template.name, description: template.description ?? "", tasks: template.tasks }); }}>Edit</button>
                {!template.is_default && <button className="btn danger" onClick={() => void act(`/onboarding/templates/${template.id}`, "DELETE")}>Remove</button>}
              </div>
            }
            bodyless
          >
            <Table headers={["Task", "Who does it", "Due", ""]}>
              {template.tasks.map((task, index) => (
                <tr key={index}>
                  <td><strong>{task.title}</strong>{task.employee_field_group && <div className="tiny">Employee fills in {task.employee_field_group} details</div>}</td>
                  <td>{humanize(task.owner_type)}</td>
                  <td>{dueLabel(task.due_offset_days)}</td>
                  <td>{task.is_required ? <Badge tone="amber" label="Required" /> : <Badge tone="gray" label="Optional" />}</td>
                </tr>
              ))}
            </Table>
          </Panel>
        ))}
      </div>
      {editing && (
        <Modal title={editing.id ? "Edit checklist" : "New checklist"} onClose={() => setEditing(null)} footer={<><button className="btn" onClick={() => setEditing(null)}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save checklist</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="stack" style={{ maxHeight: "60vh", overflow: "auto" }}>
            <Field label="Name" required error={error?.errors.name?.[0]}><input className="input" value={editing.name} onChange={(event) => setEditing({ ...editing, name: event.target.value })} /></Field>
            {editing.tasks.map((task, index) => (
              <div key={index} className="panel" style={{ padding: 12 }}>
                <div className="formgrid">
                  <Field label={`Task ${index + 1}`} required full error={error?.errors[`tasks.${index}.title`]?.[0]}><input className="input" value={task.title} onChange={(event) => setTask(index, { title: event.target.value })} /></Field>
                  <Field label="Who does it">
                    <select value={task.owner_type} onChange={(event) => setTask(index, { owner_type: event.target.value })}>{owners.map((owner) => <option key={owner} value={owner}>{humanize(owner)}</option>)}</select>
                  </Field>
                  <Field label="Due (days from start)" hint="Negative = before the first day.">
                    <input className="input" type="number" value={task.due_offset_days} onChange={(event) => setTask(index, { due_offset_days: Number(event.target.value) })} />
                  </Field>
                  {task.owner_type === "employee" && (
                    <Field label="Employee fills in" optional>
                      <select value={task.employee_field_group ?? ""} onChange={(event) => setTask(index, { employee_field_group: event.target.value || null })}>
                        <option value="">Nothing (just tick it off)</option>
                        {groups.map((group) => <option key={group} value={group}>{humanize(group)} details</option>)}
                      </select>
                    </Field>
                  )}
                  <label className="checkrow"><input type="checkbox" checked={task.is_required} onChange={(event) => setTask(index, { is_required: event.target.checked })} /> Required before activation</label>
                  <div style={{ textAlign: "right" }}><button className="btn danger" onClick={() => setEditing({ ...editing, tasks: editing.tasks.filter((_, position) => position !== index) })}>Remove task</button></div>
                </div>
              </div>
            ))}
            <div><button className="btn soft" onClick={() => setEditing({ ...editing, tasks: [...editing.tasks, { title: "", owner_type: "hr", due_offset_days: 0, is_required: true, employee_field_group: null }] })}>Add task</button></div>
          </div>
        </Modal>
      )}
    </>
  );
}
