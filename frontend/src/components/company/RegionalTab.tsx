"use client";

import { useEffect, useState } from "react";
import { Banner, Field, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Settings = { timezone: string; currency_code: string; work_days: number[]; week_start_day: number; date_format: string | null; locale: string };
type Response = { configured: boolean; settings: Settings | null; currencies: string[] };

const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
const ZONES = ["Asia/Karachi", "Europe/London", "America/New_York", "America/Chicago", "America/Denver", "America/Los_Angeles", "Asia/Dubai", "UTC"];

export function RegionalTab() {
  const [data, setData] = useState<Response | null>(null);
  const [form, setForm] = useState<Settings>({ timezone: "", currency_code: "", work_days: [], week_start_day: 1, date_format: "d/m/Y", locale: "en" });
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api<Response>("/company/regional-settings").then((result) => {
      setData(result);
      if (result.settings) setForm(result.settings);
    });
  }, []);

  if (!data) return <div className="empty">Loading…</div>;

  const fieldError = (key: string) => error?.errors[key]?.[0];
  const toggleDay = (day: number) =>
    setForm({ ...form, work_days: form.work_days.includes(day) ? form.work_days.filter((item) => item !== day) : [...form.work_days, day].sort() });

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      const result = await api<Response>("/company/regional-settings", { method: "PUT", body: form });
      setData(result);
      setSaved(true);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <form onSubmit={save}>
      {!data.configured && <Banner tone="info">Confirm these settings yourself. M.Y HRMS does not guess them from your country.</Banner>}
      {saved && <Banner tone="success">Regional settings saved.</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      <Panel title="Regional settings" subtitle="Used for attendance times, leave calculations and pay amounts." right={<button className="btn primary" type="submit">Save settings</button>}>
        <div className="formgrid">
          <Field label="Time zone" required error={fieldError("timezone")}>
            <select value={form.timezone} onChange={(event) => setForm({ ...form, timezone: event.target.value })}>
              <option value="">Choose a time zone</option>
              {ZONES.map((zone) => <option key={zone} value={zone}>{zone}</option>)}
            </select>
          </Field>
          <Field label="Currency" required error={fieldError("currency_code")}>
            <select value={form.currency_code} onChange={(event) => setForm({ ...form, currency_code: event.target.value })}>
              <option value="">Choose a currency</option>
              {data.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
            </select>
          </Field>
          <Field label="Week starts on" required error={fieldError("week_start_day")}>
            <select value={form.week_start_day} onChange={(event) => setForm({ ...form, week_start_day: Number(event.target.value) })}>
              {DAYS.map((day, index) => <option key={day} value={index + 1}>{day}</option>)}
            </select>
          </Field>
          <Field label="Date format" optional>
            <select value={form.date_format ?? ""} onChange={(event) => setForm({ ...form, date_format: event.target.value || null })}>
              <option value="d/m/Y">31/12/2026</option>
              <option value="m/d/Y">12/31/2026</option>
              <option value="Y-m-d">2026-12-31</option>
            </select>
          </Field>
          <Field label="Working days" required full error={fieldError("work_days")}>
            <div className="row" style={{ justifyContent: "flex-start", flexWrap: "wrap" }}>
              {DAYS.map((day, index) => (
                <label key={day} className="checkrow">
                  <input type="checkbox" checked={form.work_days.includes(index + 1)} onChange={() => toggleDay(index + 1)} />
                  {day}
                </label>
              ))}
            </div>
          </Field>
        </div>
      </Panel>
    </form>
  );
}
