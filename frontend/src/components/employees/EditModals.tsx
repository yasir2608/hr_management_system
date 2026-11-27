"use client";

import { useEffect, useState } from "react";
import { Banner, Field, Modal } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { EMPLOYMENT_TYPES, WORK_ARRANGEMENTS, type CountryField, type Employee } from "@/lib/employees";

type Option = { id: string; name: string };
type FormOptions = {
  departments: Option[];
  teams: Option[];
  locations: Option[];
  managers: Option[];
  countries: { code: string; name: string; currency: string; fields: Record<string, CountryField> }[];
};

function useFormOptions() {
  const [options, setOptions] = useState<FormOptions | null>(null);
  useEffect(() => {
    api<FormOptions>("/employees/form-options").then(setOptions).catch(() => undefined);
  }, []);
  return options;
}

function useSubmit(path: string, method: "PUT" | "POST", onDone: (message: string) => void) {
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  async function submit(body: unknown) {
    setSaving(true);
    setError(null);
    try {
      const result = await api<{ message: string }>(path, { method, body });
      onDone(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
      setSaving(false);
    }
  }
  return { error, saving, submit, err: (key: string) => error?.errors[key]?.[0] };
}

type Props = { employee: Employee; personal?: Record<string, string | null>; onClose: () => void; onDone: (message: string) => void };

/** Corrects details in place. Every change is kept in the activity history. */
export function EditDetailsModal({ employee, personal, onClose, onDone }: Props) {
  const [form, setForm] = useState({
    first_name: employee.first_name, last_name: employee.last_name, preferred_name: employee.preferred_name ?? "",
    work_email: employee.work_email ?? "", start_date: employee.start_date ?? "",
    phone: personal?.phone ?? "", personal_email: personal?.personal_email ?? "", date_of_birth: personal?.date_of_birth ?? "",
    address_line1: personal?.address_line1 ?? "", city: personal?.city ?? "", postal_code: personal?.postal_code ?? "",
    emergency_contact_name: personal?.emergency_contact_name ?? "", emergency_contact_relationship: personal?.emergency_contact_relationship ?? "",
    emergency_contact_phone: personal?.emergency_contact_phone ?? "",
  });
  const { error, saving, submit, err } = useSubmit(`/employees/${employee.id}`, "PUT", onDone);
  const set = (key: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: event.target.value });
  const body = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === "" ? null : value]));

  return (
    <Modal title="Edit details" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={saving} onClick={() => void submit(body)}>Save</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <p className="tiny" style={{ marginTop: 0 }}>Use this to correct details. Job, manager, location and pay changes go through “Record a change” so history is kept.</p>
      <div className="formgrid" style={{ maxHeight: "55vh", overflow: "auto" }}>
        <Field label="First name" required error={err("first_name")}><input className="input" value={form.first_name} onChange={set("first_name")} /></Field>
        <Field label="Last name" required error={err("last_name")}><input className="input" value={form.last_name} onChange={set("last_name")} /></Field>
        <Field label="Preferred name" optional><input className="input" value={form.preferred_name} onChange={set("preferred_name")} /></Field>
        <Field label="Work email" optional error={err("work_email")}><input className="input" value={form.work_email} onChange={set("work_email")} /></Field>
        <Field label="Start date" error={err("start_date")}><input className="input" type="date" value={form.start_date} onChange={set("start_date")} /></Field>
        {personal && (
          <>
            <Field label="Date of birth" optional error={err("date_of_birth")}><input className="input" type="date" value={form.date_of_birth} onChange={set("date_of_birth")} /></Field>
            <Field label="Phone" optional><input className="input" value={form.phone} onChange={set("phone")} /></Field>
            <Field label="Personal email" optional error={err("personal_email")}><input className="input" value={form.personal_email} onChange={set("personal_email")} /></Field>
            <Field label="Address" optional><input className="input" value={form.address_line1} onChange={set("address_line1")} /></Field>
            <Field label="City" optional><input className="input" value={form.city} onChange={set("city")} /></Field>
            <Field label="Postal code" optional><input className="input" value={form.postal_code} onChange={set("postal_code")} /></Field>
            <Field label="Emergency contact" optional><input className="input" value={form.emergency_contact_name} onChange={set("emergency_contact_name")} /></Field>
            <Field label="Relationship" optional><input className="input" value={form.emergency_contact_relationship} onChange={set("emergency_contact_relationship")} /></Field>
            <Field label="Emergency phone" optional><input className="input" value={form.emergency_contact_phone} onChange={set("emergency_contact_phone")} /></Field>
          </>
        )}
      </div>
    </Modal>
  );
}

/** Effective-dated change: the previous value stays in history; past payroll is unaffected. */
export function RecordChangeModal({ employee, canChangePay, onClose, onDone, initialType = "job" }: Props & { canChangePay: boolean; initialType?: string }) {
  const options = useFormOptions();
  const [type, setType] = useState(initialType);
  const [form, setForm] = useState<Record<string, string>>({
    effective_from: new Date().toISOString().slice(0, 10), reason: "",
    job_title: employee.current_job_title ?? "", department_id: employee.department?.id ?? "", team_id: employee.team?.id ?? "",
    employment_type: employee.current_employment_type ?? "full_time", manager_id: employee.manager?.id ?? "",
    location_id: employee.location?.id ?? "", work_arrangement: employee.current_work_arrangement ?? "onsite",
    pay_basis: "salary", amount: "", currency: "", frequency: "annual",
  });
  const { error, saving, submit, err } = useSubmit(`/employees/${employee.id}/changes`, "POST", onDone);
  const set = (key: string) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [key]: event.target.value });
  const currency = options?.countries.find((country) => country.code === employee.work_country)?.currency ?? "";

  const fields: Record<string, string[]> = {
    job: ["job_title", "department_id", "team_id", "employment_type"],
    manager: ["manager_id"],
    location: ["location_id", "work_arrangement"],
    compensation: ["pay_basis", "amount", "currency", "frequency"],
  };
  const body = Object.fromEntries([["type", type], ["effective_from", form.effective_from], ["reason", form.reason], ...fields[type].map((key) => [key, form[key] || (key === "currency" ? currency : null)])]);

  return (
    <Modal title="Record a change" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={saving} onClick={() => void submit(body)}>Save change</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="What is changing">
          <select value={type} onChange={(event) => setType(event.target.value)}>
            <option value="job">Job, department or team</option>
            <option value="manager">Manager</option>
            <option value="location">Location or work arrangement</option>
            {canChangePay && <option value="compensation">Pay</option>}
          </select>
        </Field>
        <Field label="Effective from" required error={err("effective_from")} hint="Future dates take effect on that day.">
          <input className="input" type="date" value={form.effective_from} onChange={set("effective_from")} />
        </Field>
        {type === "job" && (
          <>
            <Field label="Job title" required error={err("job_title")}><input className="input" value={form.job_title} onChange={set("job_title")} /></Field>
            <Field label="Employment type">
              <select value={form.employment_type} onChange={set("employment_type")}>{EMPLOYMENT_TYPES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
            </Field>
            <Field label="Department" optional>
              <select value={form.department_id} onChange={set("department_id")}><option value="">None</option>{options?.departments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
            </Field>
            <Field label="Team" optional>
              <select value={form.team_id} onChange={set("team_id")}><option value="">None</option>{options?.teams.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
            </Field>
          </>
        )}
        {type === "manager" && (
          <Field label="New manager" full error={err("manager_id")}>
            <select value={form.manager_id} onChange={set("manager_id")}><option value="">No manager</option>{options?.managers.filter((item) => item.id !== employee.id).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
          </Field>
        )}
        {type === "location" && (
          <>
            <Field label="Location" required error={err("location_id")}>
              <select value={form.location_id} onChange={set("location_id")}><option value="">Choose</option>{options?.locations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
            </Field>
            <Field label="Works">
              <select value={form.work_arrangement} onChange={set("work_arrangement")}>{WORK_ARRANGEMENTS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
            </Field>
          </>
        )}
        {type === "compensation" && (
          <>
            <Field label="Pay basis">
              <select value={form.pay_basis} onChange={set("pay_basis")}><option value="salary">Salary</option><option value="hourly">Hourly</option></select>
            </Field>
            <Field label={`Amount (${form.currency || currency})`} required error={err("amount")}><input className="input" type="number" step="0.01" value={form.amount} onChange={set("amount")} /></Field>
            <Field label="Per">
              <select value={form.frequency} onChange={set("frequency")}><option value="annual">Year</option><option value="monthly">Month</option><option value="hourly">Hour</option></select>
            </Field>
          </>
        )}
        <Field label="Reason" required full error={err("reason")} hint="Kept with the history, e.g. Promotion, Annual review.">
          <input className="input" value={form.reason} onChange={set("reason")} />
        </Field>
      </div>
    </Modal>
  );
}

/** Tax identifiers and bank details. Payroll permission only. */
export function PayrollDetailsModal({ employee, onClose, onDone }: Props) {
  const options = useFormOptions();
  const country = options?.countries.find((item) => item.code === employee.work_country);
  const [tax, setTax] = useState<Record<string, string>>({});
  const [bank, setBank] = useState({ account_holder: employee.full_name, bank_name: "", account_number: "", bank_code: "" });
  const { error, saving, submit, err } = useSubmit(`/employees/${employee.id}/payroll-details`, "PUT", onDone);

  return (
    <Modal title="Payroll details" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={saving} onClick={() => void submit({ tax_details: tax, bank: bank.account_number ? bank : null })}>Save</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      {!country ? (
        <div className="callout amber">Set the employee&apos;s country of employment first.</div>
      ) : (
        <div className="stack">
          <div className="sectiontitle" style={{ marginTop: 0 }}>{country.name} tax details</div>
          <div className="formgrid">
            {Object.entries(country.fields).map(([key, field]) => (
              <Field key={key} label={field.label} required={field.required} error={err(`tax_details.${key}`)} hint={field.hint ?? undefined}>
                {field.options ? (
                  <select value={tax[key] ?? ""} onChange={(event) => setTax({ ...tax, [key]: event.target.value })}>
                    <option value="">Keep current</option>
                    {field.options.map((option) => <option key={option} value={option}>{option.replace(/_/g, " ")}</option>)}
                  </select>
                ) : (
                  <input className="input" placeholder="Leave empty to keep current" value={tax[key] ?? ""} onChange={(event) => setTax({ ...tax, [key]: event.target.value })} />
                )}
              </Field>
            ))}
          </div>
          <div className="sectiontitle">New bank account <span className="tiny">(optional, replaces the primary account)</span></div>
          <div className="formgrid">
            <Field label="Account holder" error={err("bank.account_holder")}><input className="input" value={bank.account_holder} onChange={(event) => setBank({ ...bank, account_holder: event.target.value })} /></Field>
            <Field label="Bank name" optional><input className="input" value={bank.bank_name} onChange={(event) => setBank({ ...bank, bank_name: event.target.value })} /></Field>
            <Field label="Account number / IBAN" error={err("bank.account_number")}><input className="input" value={bank.account_number} onChange={(event) => setBank({ ...bank, account_number: event.target.value })} /></Field>
            <Field label="Sort code / routing number" optional><input className="input" value={bank.bank_code} onChange={(event) => setBank({ ...bank, bank_code: event.target.value })} /></Field>
          </div>
        </div>
      )}
    </Modal>
  );
}

/** Moves the employee to the next lifecycle status. Ended records are kept. */
export function ChangeStatusModal({ employee, nextStatuses, onClose, onDone }: Props & { nextStatuses: string[] }) {
  const [form, setForm] = useState({ status: nextStatuses[0] ?? "", effective_from: new Date().toISOString().slice(0, 10), reason: "", remove_access: true });
  const { error, saving, submit, err } = useSubmit(`/employees/${employee.id}/status`, "POST", onDone);
  const label = (status: string) => ({ invited: "Invited", onboarding: "Onboarding", active: "Active", on_leave: "On leave", ended: "Ended" })[status] ?? status;

  return (
    <Modal title="Change status" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className={`btn ${form.status === "ended" ? "danger" : "primary"}`} disabled={saving} onClick={() => void submit(form)}>Change status</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="New status" error={err("status")}>
          <select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
            {nextStatuses.map((status) => <option key={status} value={status}>{label(status)}</option>)}
          </select>
        </Field>
        <Field label={form.status === "ended" ? "Last day" : "From"} required error={err("effective_from")}>
          <input className="input" type="date" value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} />
        </Field>
        <Field label="Reason" required full error={err("reason")} hint={form.status === "ended" ? "e.g. Resigned, End of contract" : undefined}>
          <input className="input" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
        </Field>
        {form.status === "ended" && employee.has_account && (
          <label className="checkrow field full">
            <input type="checkbox" checked={form.remove_access} onChange={(event) => setForm({ ...form, remove_access: event.target.checked })} />
            Also remove their access to M.Y HRMS
          </label>
        )}
        {employee.current_status === "ended" && <div className="callout blue field full">Re-hiring starts a new period of employment from this date. The earlier period stays in history.</div>}
      </div>
    </Modal>
  );
}
