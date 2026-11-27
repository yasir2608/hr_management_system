"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Guard } from "@/components/Guard";
import { Banner, Field, PageHead, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { EMPLOYMENT_TYPES, WORK_ARRANGEMENTS, type CountryField } from "@/lib/employees";

type Option = { id: string; name: string };
type Country = { code: string; name: string; currency: string; fields: Record<string, CountryField> };
type FormOptions = {
  departments: Option[];
  teams: (Option & { department_id: string | null })[];
  locations: Option[];
  managers: Option[];
  countries: Country[];
  required_reasons: Record<string, string>;
  can_enter_payroll: boolean;
  next_employee_number: string;
};

const blank = {
  first_name: "", last_name: "", preferred_name: "", work_email: "", personal_email: "", phone: "", date_of_birth: "",
  employee_number: "", start_date: "", job_title: "", employment_type: "full_time", department_id: "", team_id: "",
  manager_id: "", location_id: "", work_arrangement: "onsite", work_country: "", status: "onboarding",
};

function AddEmployeePage() {
  const router = useRouter();
  const [options, setOptions] = useState<FormOptions | null>(null);
  const [form, setForm] = useState(blank);
  const [payrollNow, setPayrollNow] = useState(false);
  const [pay, setPay] = useState({ pay_basis: "salary", amount: "", currency: "", frequency: "annual" });
  const [tax, setTax] = useState<Record<string, string>>({});
  const [bank, setBank] = useState({ account_holder: "", bank_name: "", account_number: "", bank_code: "" });
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api<FormOptions>("/employees/form-options").then(setOptions);
  }, []);

  const country = useMemo(() => options?.countries.find((item) => item.code === form.work_country), [options, form.work_country]);
  const teams = options?.teams.filter((team) => !form.department_id || !team.department_id || team.department_id === form.department_id) ?? [];

  if (!options) return <div className="loadingpage">Loading…</div>;

  const set = (key: keyof typeof blank) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [key]: event.target.value });
  const err = (key: string) => error?.errors[key]?.[0];
  const why = (key: string) => options.required_reasons[key];

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError(null);
    const body: Record<string, unknown> = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === "" ? null : value]));
    if (payrollNow) {
      if (pay.amount) body.pay = { ...pay, currency: pay.currency || country?.currency };
      body.tax_details = tax;
      if (bank.account_number) body.bank = bank;
    }
    try {
      const result = await api<{ employee: { id: string }; message: string }>("/employees", { body });
      try {
        window.sessionStorage.setItem("myhrms.flash", result.message);
      } catch {
        /* ignore */
      }
      router.push("/employees");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
      setSaving(false);
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  }

  return (
    <form onSubmit={save} noValidate>
      <PageHead
        eyebrow="People"
        title="Add an employee"
        subtitle="Fields marked * are required; hover the hint under each to see why. Payroll details can be added now or later."
        actions={
          <>
            <Link className="btn" href="/employees">Cancel</Link>
            <button className="btn primary" type="submit" disabled={saving}>{saving ? "Saving…" : "Save employee"}</button>
          </>
        }
      />
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="stack">
        <Panel title="Personal details">
          <div className="formgrid">
            <Field label="First name" required error={err("first_name")} hint={why("first_name")}><input className="input" value={form.first_name} onChange={set("first_name")} /></Field>
            <Field label="Last name" required error={err("last_name")}><input className="input" value={form.last_name} onChange={set("last_name")} /></Field>
            <Field label="Preferred name" optional><input className="input" value={form.preferred_name} onChange={set("preferred_name")} /></Field>
            <Field label="Date of birth" optional error={err("date_of_birth")}><input className="input" type="date" value={form.date_of_birth} onChange={set("date_of_birth")} /></Field>
            <Field label="Work email" optional error={err("work_email")} hint={why("work_email")}><input className="input" type="email" value={form.work_email} onChange={set("work_email")} /></Field>
            <Field label="Personal email" optional error={err("personal_email")}><input className="input" type="email" value={form.personal_email} onChange={set("personal_email")} /></Field>
            <Field label="Phone" optional><input className="input" value={form.phone} onChange={set("phone")} /></Field>
          </div>
        </Panel>

        <Panel title="Employment">
          <div className="formgrid">
            <Field label="Employee number" optional error={err("employee_number")} hint={`${why("employee_number")} Next: ${options.next_employee_number}`}>
              <input className="input" value={form.employee_number} placeholder={options.next_employee_number} onChange={set("employee_number")} />
            </Field>
            <Field label="Start date" required error={err("start_date")} hint={why("start_date")}><input className="input" type="date" value={form.start_date} onChange={set("start_date")} /></Field>
            <Field label="Job title" required error={err("job_title")} hint={why("job_title")}><input className="input" value={form.job_title} onChange={set("job_title")} /></Field>
            <Field label="Employment type" optional>
              <select value={form.employment_type} onChange={set("employment_type")}>
                {EMPLOYMENT_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
              </select>
            </Field>
            <Field label="Department" optional error={err("department_id")}>
              <select value={form.department_id} onChange={set("department_id")}>
                <option value="">None</option>
                {options.departments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
            <Field label="Team" optional error={err("team_id")}>
              <select value={form.team_id} onChange={set("team_id")}>
                <option value="">None</option>
                {teams.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
            <Field label="Manager" optional error={err("manager_id")}>
              <select value={form.manager_id} onChange={set("manager_id")}>
                <option value="">None</option>
                {options.managers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
            <Field label="Starting status" optional>
              <select value={form.status} onChange={set("status")}>
                <option value="onboarding">Onboarding</option>
                <option value="active">Active</option>
                <option value="invited">Invited</option>
              </select>
            </Field>
          </div>
        </Panel>

        <Panel title="Work arrangement">
          <div className="formgrid">
            <Field label="Country of employment" required error={err("work_country")} hint={why("work_country")}>
              <select value={form.work_country} onChange={set("work_country")}>
                <option value="">Choose a country</option>
                {options.countries.map((item) => <option key={item.code} value={item.code}>{item.name}</option>)}
              </select>
            </Field>
            <Field label="Location" optional error={err("location_id")}>
              <select value={form.location_id} onChange={set("location_id")}>
                <option value="">None</option>
                {options.locations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
            <Field label="Works" optional>
              <select value={form.work_arrangement} onChange={set("work_arrangement")}>
                {WORK_ARRANGEMENTS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </Field>
          </div>
        </Panel>

        <Panel title="Payroll details" subtitle="Pay, tax identifiers and bank details. Needed before the person can be paid, not to save the record.">
          {!options.can_enter_payroll ? (
            <div className="callout blue">Your role cannot enter pay, tax or bank details. A payroll administrator can add them later; the employee will show as not payroll-ready until then.</div>
          ) : (
            <div className="stack">
              <div className="row" style={{ justifyContent: "flex-start" }}>
                <label className="checkrow"><input type="radio" checked={!payrollNow} onChange={() => setPayrollNow(false)} /> Add payroll details later</label>
                <label className="checkrow"><input type="radio" checked={payrollNow} onChange={() => setPayrollNow(true)} /> Add them now</label>
              </div>
              {payrollNow && !country && <div className="callout amber">Choose the country of employment first; the tax details depend on it.</div>}
              {payrollNow && country && (
                <>
                  <div className="sectiontitle">Pay</div>
                  <div className="formgrid">
                    <Field label="Pay basis">
                      <select value={pay.pay_basis} onChange={(event) => setPay({ ...pay, pay_basis: event.target.value, frequency: event.target.value === "hourly" ? "hourly" : "annual" })}>
                        <option value="salary">Salary</option>
                        <option value="hourly">Hourly</option>
                      </select>
                    </Field>
                    <Field label={`Amount (${pay.currency || country.currency})`} error={err("pay.amount")}>
                      <input className="input" type="number" min="0" step="0.01" value={pay.amount} onChange={(event) => setPay({ ...pay, amount: event.target.value })} />
                    </Field>
                    <Field label="Per">
                      <select value={pay.frequency} onChange={(event) => setPay({ ...pay, frequency: event.target.value })}>
                        <option value="annual">Year</option>
                        <option value="monthly">Month</option>
                        <option value="hourly">Hour</option>
                      </select>
                    </Field>
                  </div>
                  <div className="sectiontitle">{country.name} tax details</div>
                  <div className="formgrid">
                    {Object.entries(country.fields).map(([key, field]) => (
                      <Field key={key} label={field.label} required={field.required} error={err(`tax_details.${key}`)} hint={field.hint ?? (field.required ? "Needed before payroll can run." : undefined)}>
                        {field.options ? (
                          <select value={tax[key] ?? ""} onChange={(event) => setTax({ ...tax, [key]: event.target.value })}>
                            <option value="">Choose</option>
                            {field.options.map((option) => <option key={option} value={option}>{option.replace(/_/g, " ")}</option>)}
                          </select>
                        ) : (
                          <input className="input" value={tax[key] ?? ""} onChange={(event) => setTax({ ...tax, [key]: event.target.value })} />
                        )}
                      </Field>
                    ))}
                  </div>
                  <div className="sectiontitle">Bank account</div>
                  <div className="formgrid">
                    <Field label="Account holder" error={err("bank.account_holder")}><input className="input" value={bank.account_holder} onChange={(event) => setBank({ ...bank, account_holder: event.target.value })} /></Field>
                    <Field label="Bank name" optional><input className="input" value={bank.bank_name} onChange={(event) => setBank({ ...bank, bank_name: event.target.value })} /></Field>
                    <Field label="Account number / IBAN" error={err("bank.account_number")}><input className="input" value={bank.account_number} onChange={(event) => setBank({ ...bank, account_number: event.target.value })} /></Field>
                    <Field label="Sort code / routing number" optional><input className="input" value={bank.bank_code} onChange={(event) => setBank({ ...bank, bank_code: event.target.value })} /></Field>
                  </div>
                </>
              )}
            </div>
          )}
        </Panel>
      </div>
    </form>
  );
}

export default function Page() {
  return (
    <Guard permissions={["employees.manage"]}>
      <AddEmployeePage />
    </Guard>
  );
}
