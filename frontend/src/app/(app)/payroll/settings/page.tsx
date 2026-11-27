"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Field, PageHead, Panel, Table, formatDate, humanize } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type EmployerField = { key: string; label: string; required: boolean; hint: string | null; options: string[] | null };
type Period = { start: string; end: string; pay_date: string; input_cutoff: string };
type Setting = {
  id: string; currency: string; pay_frequency: string; period_start_day: number; anchor_date: string | null; pay_date_rule: string;
  pay_day: number | null; pay_date_offset_days: number | null; input_cutoff_days: number; employer_details: Record<string, string | null>; updated_at: string;
};
type CountrySettings = {
  country_code: string; country_name: string; default_currency: string; pay_frequencies: string[]; employer_fields: EmployerField[];
  setting: Setting | null; missing: string[]; is_complete: boolean; upcoming_periods: Period[]; calendar_error: string | null;
};

type Draft = {
  currency: string; pay_frequency: string; period_start_day: string; anchor_date: string; pay_date_rule: string;
  pay_day: string; pay_date_offset_days: string; input_cutoff_days: string; employer_details: Record<string, string>;
};

const FREQUENCY_LABELS: Record<string, string> = { monthly: "Monthly", semi_monthly: "Twice a month (1st–15th, 16th–end)", biweekly: "Every two weeks", four_weekly: "Every four weeks", weekly: "Weekly" };
const PAY_DATE_LABELS: Record<string, string> = { last_working_day: "Last working day of the period", fixed_day: "A fixed day of the month", period_end: "Last day of the period", days_after_period_end: "A number of days after the period ends" };
const DAY_BASED = ["biweekly", "four_weekly", "weekly"];

function toDraft(country: CountrySettings): Draft {
  const setting = country.setting;
  return {
    currency: setting?.currency ?? country.default_currency,
    pay_frequency: setting?.pay_frequency ?? country.pay_frequencies[0],
    period_start_day: String(setting?.period_start_day ?? 1),
    anchor_date: setting?.anchor_date ?? "",
    pay_date_rule: setting?.pay_date_rule ?? "last_working_day",
    pay_day: setting?.pay_day ? String(setting.pay_day) : "",
    pay_date_offset_days: setting?.pay_date_offset_days != null ? String(setting.pay_date_offset_days) : "",
    input_cutoff_days: String(setting?.input_cutoff_days ?? 3),
    employer_details: Object.fromEntries(country.employer_fields.map((field) => [field.key, setting?.employer_details[field.key] ?? ""])),
  };
}

function CountryForm({ country, canManage, canSeeIds, onSaved }: { country: CountrySettings; canManage: boolean; canSeeIds: boolean; onSaved: (updated: CountrySettings) => void }) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(country));
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const set = (patch: Partial<Draft>) => setDraft({ ...draft, ...patch });
  const fieldError = (key: string) => error?.errors[key]?.[0];
  const dayBased = DAY_BASED.includes(draft.pay_frequency);

  async function save() {
    setError(null);
    setNotice(null);
    try {
      const updated = await api<CountrySettings>(`/payroll/settings/${country.country_code}`, {
        method: "PUT",
        body: {
          ...draft,
          period_start_day: dayBased || draft.pay_frequency === "semi_monthly" ? null : Number(draft.period_start_day),
          anchor_date: dayBased ? draft.anchor_date : null,
          pay_day: draft.pay_date_rule === "fixed_day" ? Number(draft.pay_day) : null,
          pay_date_offset_days: draft.pay_date_rule === "days_after_period_end" ? Number(draft.pay_date_offset_days) : null,
          input_cutoff_days: Number(draft.input_cutoff_days),
        },
      });
      setNotice(`Payroll settings for ${country.country_name} saved.`);
      setDraft(toDraft(updated));
      onSaved(updated);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      {!country.is_complete && (
        <Banner tone="info">
          {country.setting ? `Still needed before payroll can run for ${country.country_name}: ${country.missing.join(", ")}.` : `Payroll is not set up for ${country.country_name} yet.`}
        </Banner>
      )}
      <div className="grid two">
        <Panel title="Pay schedule" subtitle="When pay periods start and end, and when people are paid.">
          <fieldset disabled={!canManage} style={{ border: 0, padding: 0, margin: 0 }}>
            <div className="formgrid">
              <Field label="Currency" required error={fieldError("currency")}><input className="input" maxLength={3} value={draft.currency} onChange={(event) => set({ currency: event.target.value.toUpperCase() })} /></Field>
              <Field label="How often people are paid" required error={fieldError("pay_frequency")}>
                <select value={draft.pay_frequency} onChange={(event) => set({ pay_frequency: event.target.value })}>
                  {country.pay_frequencies.map((frequency) => <option key={frequency} value={frequency}>{FREQUENCY_LABELS[frequency] ?? humanize(frequency)}</option>)}
                </select>
              </Field>
              {draft.pay_frequency === "monthly" && (
                <Field label="Period starts on day" hint="1 means calendar months" error={fieldError("period_start_day")}>
                  <input className="input" type="number" min={1} max={28} value={draft.period_start_day} onChange={(event) => set({ period_start_day: event.target.value })} />
                </Field>
              )}
              {dayBased && (
                <Field label="First day of any pay period" required hint="Later periods are counted from this date" error={fieldError("anchor_date")}>
                  <input className="input" type="date" value={draft.anchor_date} onChange={(event) => set({ anchor_date: event.target.value })} />
                </Field>
              )}
              <Field label="Pay date" required error={fieldError("pay_date_rule")}>
                <select value={draft.pay_date_rule} onChange={(event) => set({ pay_date_rule: event.target.value })}>
                  {Object.entries(PAY_DATE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </Field>
              {draft.pay_date_rule === "fixed_day" && (
                <Field label="Day of the month" required hint="Short months use their last day" error={fieldError("pay_day")}>
                  <input className="input" type="number" min={1} max={31} value={draft.pay_day} onChange={(event) => set({ pay_day: event.target.value })} />
                </Field>
              )}
              {draft.pay_date_rule === "days_after_period_end" && (
                <Field label="Days after the period ends" required error={fieldError("pay_date_offset_days")}>
                  <input className="input" type="number" min={0} max={15} value={draft.pay_date_offset_days} onChange={(event) => set({ pay_date_offset_days: event.target.value })} />
                </Field>
              )}
              <Field label="Inputs close (days before pay date)" required hint="Changes after this go into the next period" error={fieldError("input_cutoff_days")}>
                <input className="input" type="number" min={0} max={15} value={draft.input_cutoff_days} onChange={(event) => set({ input_cutoff_days: event.target.value })} />
              </Field>
            </div>
          </fieldset>
        </Panel>
        <Panel title="Employer details" subtitle={canSeeIds ? "Identifiers used on payroll reports and filings." : "Identifiers are hidden. Leave a field blank to keep its current value."}>
          <fieldset disabled={!canManage} style={{ border: 0, padding: 0, margin: 0 }}>
            <div className="formgrid">
              {country.employer_fields.map((field) => (
                <Field key={field.key} label={field.label} required={field.required} hint={field.hint ?? undefined} error={fieldError(`employer_details.${field.key}`)} full>
                  {field.options ? (
                    <select value={draft.employer_details[field.key] ?? ""} onChange={(event) => set({ employer_details: { ...draft.employer_details, [field.key]: event.target.value } })}>
                      <option value="">Not set</option>
                      {field.options.map((option) => <option key={option} value={option}>{humanize(option)}</option>)}
                    </select>
                  ) : (
                    <input
                      className="input"
                      value={canSeeIds ? draft.employer_details[field.key] ?? "" : ""}
                      placeholder={canSeeIds ? "" : country.setting?.employer_details[field.key] ?? "Not set"}
                      onChange={(event) => set({ employer_details: { ...draft.employer_details, [field.key]: event.target.value } })}
                    />
                  )}
                </Field>
              ))}
            </div>
          </fieldset>
        </Panel>
      </div>
      {canManage && <div className="row" style={{ justifyContent: "flex-end", marginBottom: 16 }}><button className="btn primary" onClick={() => void save()}>Save {country.country_name} settings</button></div>}
      <Panel title="Upcoming pay periods" subtitle="Worked out from the saved schedule and your company’s working days." bodyless={country.upcoming_periods.length > 0}>
        {country.calendar_error ? <Banner tone="error">{country.calendar_error}</Banner> : country.upcoming_periods.length === 0 ? <span className="muted">Save a schedule to see upcoming pay periods.</span> : (
          <Table headers={["Period", "Inputs close", "Pay date"]}>
            {country.upcoming_periods.map((period) => (
              <tr key={period.start}>
                <td>{formatDate(period.start)} – {formatDate(period.end)}</td>
                <td>{formatDate(period.input_cutoff)}</td>
                <td><strong>{formatDate(period.pay_date)}</strong></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}

function PayrollSettingsPage() {
  const { can } = useAuth();
  const [countries, setCountries] = useState<CountrySettings[] | null>(null);
  const [canSeeIds, setCanSeeIds] = useState(false);
  const [active, setActive] = useState<string | null>(null);

  const load = useCallback(async () => {
    const result = await api<{ countries: CountrySettings[]; can_see_employer_ids: boolean }>("/payroll/settings");
    setCountries(result.countries);
    setCanSeeIds(result.can_see_employer_ids);
    setActive((current) => current ?? result.countries[0]?.country_code ?? null);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!countries) return <div className="loadingpage">Loading…</div>;
  const current = countries.find((country) => country.country_code === active);

  return (
    <>
      <PageHead eyebrow="Payroll" title="Payroll settings" subtitle="Currency, pay schedule, pay-period rules and employer identifiers for each country you pay employees in." />
      {countries.length === 0 ? (
        <Panel>
          <EmptyState title="No payroll countries yet">
            Choose the countries you employ people in under <Link className="link" href="/settings/company">Company setup</Link> first.
          </EmptyState>
        </Panel>
      ) : (
        <>
          <div className="tabs" role="tablist">
            {countries.map((country) => (
              <button key={country.country_code} role="tab" aria-selected={country.country_code === active} className={`tab${country.country_code === active ? " on" : ""}`} onClick={() => setActive(country.country_code)}>
                {country.country_name} <Badge tone={country.is_complete ? "green" : "amber"} label={country.is_complete ? "Ready" : "Setup needed"} />
              </button>
            ))}
          </div>
          {current && (
            <CountryForm
              key={current.country_code}
              country={current}
              canManage={can("payroll.settings.manage")}
              canSeeIds={canSeeIds}
              onSaved={(updated) => setCountries(countries.map((country) => (country.country_code === updated.country_code ? updated : country)))}
            />
          )}
        </>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.settings.manage"]}>
      <PayrollSettingsPage />
    </Guard>
  );
}
