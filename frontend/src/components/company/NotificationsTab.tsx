"use client";

import { useEffect, useState } from "react";
import { Banner, Field, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Category = { key: string; label: string; description: string; reminders: boolean; email: boolean; reminders_on: boolean };
type Settings = { categories: Category[]; reminder_after_days: number; reminder_repeat_days: number; max_reminders: number; can_manage: boolean };

/** Company notification controls: email and reminders per category, and when reminders go out. */
export function NotificationsTab() {
  const [form, setForm] = useState<Settings | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    void api<Settings>("/company/notification-settings").then(setForm);
  }, []);

  if (!form) return <div className="empty">Loading…</div>;

  const setCategory = (key: string, patch: Partial<Category>) =>
    setForm({ ...form, categories: form.categories.map((category) => (category.key === key ? { ...category, ...patch } : category)) });

  async function save(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;
    setError(null);
    try {
      setForm(await api<Settings>("/company/notification-settings", {
        method: "PUT",
        body: {
          categories: Object.fromEntries(form.categories.map((category) => [category.key, { email: category.email, reminders: category.reminders_on }])),
          reminder_after_days: Number(form.reminder_after_days), reminder_repeat_days: Number(form.reminder_repeat_days), max_reminders: Number(form.max_reminders),
        },
      }));
      setNotice("Notification settings saved.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  const readOnly = !form.can_manage;

  return (
    <form onSubmit={(event) => void save(event)}>
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      <Panel title="Notifications" subtitle="In-product notifications are always on. Choose what is also emailed and what is reminded. Emails never include salary, bank, tax or identity details." bodyless>
        <Table headers={["Category", "Email", "Reminders"]}>
          {form.categories.map((category) => (
            <tr key={category.key}>
              <td><strong>{category.label}</strong><div className="tiny">{category.description}</div></td>
              <td><label className="checkrow"><input type="checkbox" disabled={readOnly} checked={category.email} onChange={(event) => setCategory(category.key, { email: event.target.checked })} /> Send email</label></td>
              <td>
                {category.reminders
                  ? <label className="checkrow"><input type="checkbox" disabled={readOnly} checked={category.reminders_on} onChange={(event) => setCategory(category.key, { reminders_on: event.target.checked })} /> Remind if not done</label>
                  : <span className="tiny">No action to remind</span>}
              </td>
            </tr>
          ))}
        </Table>
      </Panel>
      <Panel title="Reminder timing" subtitle="Reminders stop as soon as the action is done, or when someone can no longer do it.">
        <div className="formgrid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))" }}>
          <Field label="First reminder after (days)" error={error?.errors.reminder_after_days?.[0]}>
            <input className="input" type="number" min={1} max={14} disabled={readOnly} value={form.reminder_after_days} onChange={(event) => setForm({ ...form, reminder_after_days: Number(event.target.value) })} />
          </Field>
          <Field label="Repeat every (days)" error={error?.errors.reminder_repeat_days?.[0]}>
            <input className="input" type="number" min={1} max={14} disabled={readOnly} value={form.reminder_repeat_days} onChange={(event) => setForm({ ...form, reminder_repeat_days: Number(event.target.value) })} />
          </Field>
          <Field label="Most reminders per item" hint="0 turns reminders off." error={error?.errors.max_reminders?.[0]}>
            <input className="input" type="number" min={0} max={5} disabled={readOnly} value={form.max_reminders} onChange={(event) => setForm({ ...form, max_reminders: Number(event.target.value) })} />
          </Field>
        </div>
        {!readOnly && <div style={{ marginTop: 14 }}><button className="btn primary" type="submit">Save notification settings</button></div>}
      </Panel>
    </form>
  );
}
