"use client";

import { useState } from "react";
import { Banner, Field, Modal } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export type PayrollGroup = {
  id: string; country_code: string; name: string; description: string | null; member_count: number; own_schedule: boolean;
  pay_frequency: string | null; period_start_day: number | null; anchor_date: string | null; pay_date_rule: string | null;
  pay_day: number | null; pay_date_offset_days: number | null; input_cutoff_days: number | null;
  schedule: { pay_frequency: string; pay_date_rule: string; pay_day: number | null } | null;
  current_period: { start: string; end: string; pay_date: string; input_cutoff: string } | null;
};

export type CountryOption = { country_code: string; country_name: string; pay_frequencies: string[] };

export const FREQUENCY_LABELS: Record<string, string> = { monthly: "Monthly", semi_monthly: "Twice a month", biweekly: "Every two weeks", four_weekly: "Every four weeks", weekly: "Weekly" };
const PAY_DATE_LABELS: Record<string, string> = { last_working_day: "Last working day of the period", fixed_day: "A fixed day of the month", period_end: "Last day of the period", days_after_period_end: "Days after the period ends" };
const DAY_BASED = ["biweekly", "four_weekly", "weekly"];

/** Create or edit a payroll group, optionally with its own pay schedule. */
export function GroupForm({ group, countries, defaultCountry, onClose, onSaved }: { group?: PayrollGroup; countries: CountryOption[]; defaultCountry?: string; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState({
    country_code: group?.country_code ?? defaultCountry ?? countries[0]?.country_code ?? "",
    name: group?.name ?? "",
    description: group?.description ?? "",
    own_schedule: group?.own_schedule ?? false,
    pay_frequency: group?.pay_frequency ?? "",
    period_start_day: String(group?.period_start_day ?? 1),
    anchor_date: group?.anchor_date ?? "",
    pay_date_rule: group?.pay_date_rule ?? "last_working_day",
    pay_day: group?.pay_day ? String(group.pay_day) : "",
    pay_date_offset_days: group?.pay_date_offset_days != null ? String(group.pay_date_offset_days) : "",
    input_cutoff_days: group?.input_cutoff_days != null ? String(group.input_cutoff_days) : "",
  });
  const [error, setError] = useState<ApiError | null>(null);
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch });
  const fieldError = (key: string) => error?.errors[key]?.[0];
  const country = countries.find((option) => option.country_code === form.country_code);
  const frequency = form.pay_frequency || country?.pay_frequencies[0] || "monthly";
  const dayBased = DAY_BASED.includes(frequency);
  const optional = (value: string) => (value === "" ? null : Number(value));

  async function save() {
    setError(null);
    try {
      await api(group ? `/payroll/groups/${group.id}` : "/payroll/groups", {
        method: group ? "PUT" : "POST",
        body: {
          country_code: form.country_code, name: form.name, description: form.description || null, own_schedule: form.own_schedule,
          ...(form.own_schedule ? {
            pay_frequency: frequency,
            period_start_day: frequency === "monthly" ? optional(form.period_start_day) : null,
            anchor_date: dayBased ? form.anchor_date : null,
            pay_date_rule: form.pay_date_rule,
            pay_day: form.pay_date_rule === "fixed_day" ? optional(form.pay_day) : null,
            pay_date_offset_days: form.pay_date_rule === "days_after_period_end" ? optional(form.pay_date_offset_days) : null,
            input_cutoff_days: optional(form.input_cutoff_days),
          } : {}),
        },
      });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title={group ? `Edit ${group.name}` : "New payroll group"} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save group</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="Name" required error={fieldError("name")}><input className="input" value={form.name} onChange={(event) => set({ name: event.target.value })} placeholder="e.g. UK monthly salaried" /></Field>
        <Field label="Country" required error={fieldError("country_code")}>
          <select value={form.country_code} onChange={(event) => set({ country_code: event.target.value, pay_frequency: "" })} disabled={!!group && group.member_count > 0}>
            {countries.map((option) => <option key={option.country_code} value={option.country_code}>{option.country_name}</option>)}
          </select>
        </Field>
        <Field label="Description" optional full><input className="input" value={form.description} onChange={(event) => set({ description: event.target.value })} /></Field>
        <Field label="Pay schedule" full>
          <label className="checkrow"><input type="radio" checked={!form.own_schedule} onChange={() => set({ own_schedule: false })} /> Use the {country?.country_name ?? "country"} payroll settings</label>
          <label className="checkrow"><input type="radio" checked={form.own_schedule} onChange={() => set({ own_schedule: true })} /> This group has its own schedule</label>
        </Field>
        {form.own_schedule && (
          <>
            <Field label="How often" required error={fieldError("pay_frequency")}>
              <select value={frequency} onChange={(event) => set({ pay_frequency: event.target.value })}>
                {country?.pay_frequencies.map((option) => <option key={option} value={option}>{FREQUENCY_LABELS[option] ?? option}</option>)}
              </select>
            </Field>
            {frequency === "monthly" && <Field label="Period starts on day" error={fieldError("period_start_day")}><input className="input" type="number" min={1} max={28} value={form.period_start_day} onChange={(event) => set({ period_start_day: event.target.value })} /></Field>}
            {dayBased && <Field label="First day of any pay period" required error={fieldError("anchor_date")}><input className="input" type="date" value={form.anchor_date} onChange={(event) => set({ anchor_date: event.target.value })} /></Field>}
            <Field label="Pay date" required error={fieldError("pay_date_rule")}>
              <select value={form.pay_date_rule} onChange={(event) => set({ pay_date_rule: event.target.value })}>
                {Object.entries(PAY_DATE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </Field>
            {form.pay_date_rule === "fixed_day" && <Field label="Day of the month" required error={fieldError("pay_day")}><input className="input" type="number" min={1} max={31} value={form.pay_day} onChange={(event) => set({ pay_day: event.target.value })} /></Field>}
            {form.pay_date_rule === "days_after_period_end" && <Field label="Days after the period ends" required error={fieldError("pay_date_offset_days")}><input className="input" type="number" min={0} max={15} value={form.pay_date_offset_days} onChange={(event) => set({ pay_date_offset_days: event.target.value })} /></Field>}
            <Field label="Inputs close (days before pay date)" optional hint="Blank uses the country setting" error={fieldError("input_cutoff_days")}><input className="input" type="number" min={0} max={15} value={form.input_cutoff_days} onChange={(event) => set({ input_cutoff_days: event.target.value })} /></Field>
          </>
        )}
      </div>
    </Modal>
  );
}
