"use client";

import { useEffect, useState } from "react";
import { Badge, Banner, Field, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Preferences = {
  leave_year_start_month: number;
  half_day_leave_allowed: boolean;
  insufficient_balance_rule: "block" | "allow_unpaid" | "exception";
  attendance_enabled: boolean;
  standard_hours_per_day: string | number;
  status: "not_started" | "skipped" | "confirmed";
};

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

export function PreferencesTab() {
  const [form, setForm] = useState<Preferences | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    api<Preferences>("/company/preferences").then(setForm);
  }, []);

  if (!form) return <div className="empty">Loading…</div>;

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      setForm(await api<Preferences>("/company/preferences", { method: "PUT", body: form }));
      setNotice("Preferences saved.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  async function skip() {
    setForm(await api<Preferences>("/company/preferences/skip", { method: "POST" }));
    setNotice("Skipped for now. Defaults apply until you come back to this step.");
  }

  const statusBadge = { not_started: <Badge tone="gray" label="Not started" />, skipped: <Badge tone="amber" label="Skipped for later" />, confirmed: <Badge tone="green" label="Confirmed" /> }[form.status];

  return (
    <form onSubmit={save}>
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      <Panel
        title={<>Leave and attendance preferences {statusBadge}</>}
        subtitle="Optional. You can skip this step and come back later; leave types and policies are set up in Leave."
        right={
          <div className="actions">
            {form.status !== "confirmed" && <button className="btn" type="button" onClick={() => void skip()}>Skip for now</button>}
            <button className="btn primary" type="submit">Save preferences</button>
          </div>
        }
      >
        <div className="formgrid">
          <Field label="Leave year starts in" error={error?.errors.leave_year_start_month?.[0]}>
            <select value={form.leave_year_start_month} onChange={(event) => setForm({ ...form, leave_year_start_month: Number(event.target.value) })}>
              {MONTHS.map((month, index) => <option key={month} value={index + 1}>{month}</option>)}
            </select>
          </Field>
          <Field label="When someone does not have enough leave" hint="You can change this later.">
            <select value={form.insufficient_balance_rule} onChange={(event) => setForm({ ...form, insufficient_balance_rule: event.target.value as Preferences["insufficient_balance_rule"] })}>
              <option value="block">Block the request</option>
              <option value="allow_unpaid">Allow the extra days as unpaid leave</option>
              <option value="exception">Send to the approver as an exception</option>
            </select>
          </Field>
          <Field label="Standard working hours per day" error={error?.errors.standard_hours_per_day?.[0]}>
            <input className="input" type="number" step="0.25" min="1" max="24" value={form.standard_hours_per_day} onChange={(event) => setForm({ ...form, standard_hours_per_day: event.target.value })} />
          </Field>
          <div className="stack" style={{ alignContent: "end" }}>
            <label className="checkrow">
              <input type="checkbox" checked={form.half_day_leave_allowed} onChange={(event) => setForm({ ...form, half_day_leave_allowed: event.target.checked })} />
              Allow half-day leave requests
            </label>
            <label className="checkrow">
              <input type="checkbox" checked={form.attendance_enabled} onChange={(event) => setForm({ ...form, attendance_enabled: event.target.checked })} />
              Employees clock in and out in M.Y HRMS
            </label>
          </div>
        </div>
      </Panel>
    </form>
  );
}
