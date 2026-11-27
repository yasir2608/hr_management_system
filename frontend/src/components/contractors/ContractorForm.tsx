"use client";

import { useEffect, useState } from "react";
import { Banner, Field, Modal } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { COUNTRIES } from "@/lib/countries";

export type ContractorField = { key: string; label: string; type: "text" | "number" | "date" | "select"; options: string[] | null; is_required: boolean };
export type Contact = { name: string; role?: string | null; email?: string | null; phone?: string | null };
export type Contractor = {
  id: string;
  kind: "person" | "supplier";
  name: string;
  legal_name: string | null;
  email: string | null;
  phone: string | null;
  country_code: string | null;
  address: string | null;
  owner_user_id: string | null;
  owner?: { name: string } | null;
  status: "active" | "inactive";
  custom_fields: Record<string, string> | null;
  notes: string | null;
  contacts?: Contact[];
};

const blank = { kind: "person" as const, name: "", legal_name: "", email: "", phone: "", country_code: "", address: "", owner_user_id: "", status: "active" as const, custom_fields: {}, notes: "", contacts: [] as Contact[] };

export function ContractorForm({ contractor, fields, onClose, onSaved }: { contractor?: Contractor; fields: ContractorField[]; onClose: () => void; onSaved: (contractor: Contractor) => void }) {
  const [form, setForm] = useState<typeof blank & { custom_fields: Record<string, string> }>(() => contractor
    ? { ...blank, ...Object.fromEntries(Object.entries(contractor).map(([key, value]) => [key, value ?? ""])), custom_fields: contractor.custom_fields ?? {}, contacts: contractor.contacts ?? [] } as typeof blank & { custom_fields: Record<string, string> }
    : { ...blank, custom_fields: {} });
  const [members, setMembers] = useState<{ user_id: string; name: string }[]>([]);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    api<{ data: { user_id: string; name: string; status: string }[] }>("/users").then((result) => setMembers(result.data.filter((user) => user.status === "active"))).catch(() => undefined);
  }, []);

  async function save() {
    setError(null);
    const body = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === "" ? null : value]));
    try {
      onSaved(await api<Contractor>(contractor ? `/contractors/${contractor.id}` : "/contractors", { method: contractor ? "PUT" : "POST", body }));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  const set = (key: keyof typeof blank, value: unknown) => setForm({ ...form, [key]: value });
  const err = (key: string) => error?.errors[key]?.[0];

  return (
    <Modal title={contractor ? `Edit ${contractor.name}` : "Add contractor"} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid" style={{ maxHeight: "60vh", overflow: "auto" }}>
        <Field label="Type">
          <select value={form.kind} onChange={(event) => set("kind", event.target.value)}>
            <option value="person">Individual</option>
            <option value="supplier">Supplier company</option>
          </select>
        </Field>
        <Field label="Name" required error={err("name")}><input className="input" value={form.name} onChange={(event) => set("name", event.target.value)} /></Field>
        <Field label="Legal name" optional><input className="input" value={form.legal_name ?? ""} onChange={(event) => set("legal_name", event.target.value)} /></Field>
        <Field label="Email" optional error={err("email")}><input className="input" value={form.email ?? ""} onChange={(event) => set("email", event.target.value)} /></Field>
        <Field label="Phone" optional><input className="input" value={form.phone ?? ""} onChange={(event) => set("phone", event.target.value)} /></Field>
        <Field label="Country" optional>
          <select value={form.country_code ?? ""} onChange={(event) => set("country_code", event.target.value)}>
            <option value="">—</option>
            {COUNTRIES.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
          </select>
        </Field>
        <Field label="Owner in your company" optional error={err("owner_user_id")}>
          <select value={form.owner_user_id ?? ""} onChange={(event) => set("owner_user_id", event.target.value)}>
            <option value="">Nobody yet</option>
            {members.map((member) => <option key={member.user_id} value={member.user_id}>{member.name}</option>)}
          </select>
        </Field>
        <Field label="Status">
          <select value={form.status} onChange={(event) => set("status", event.target.value)}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </Field>
        {fields.map((field) => (
          <Field key={field.key} label={field.label} required={field.is_required} optional={!field.is_required} error={err(`custom_fields.${field.key}`)}>
            {field.type === "select" ? (
              <select value={form.custom_fields[field.key] ?? ""} onChange={(event) => setForm({ ...form, custom_fields: { ...form.custom_fields, [field.key]: event.target.value } })}>
                <option value="">—</option>
                {(field.options ?? []).map((option) => <option key={option} value={option}>{option}</option>)}
              </select>
            ) : (
              <input className="input" type={field.type === "date" ? "date" : field.type === "number" ? "number" : "text"} value={form.custom_fields[field.key] ?? ""} onChange={(event) => setForm({ ...form, custom_fields: { ...form.custom_fields, [field.key]: event.target.value } })} />
            )}
          </Field>
        ))}
        <div className="field full">
          <label>Contacts</label>
          {form.contacts.map((contact, index) => (
            <div key={index} className="row" style={{ marginBottom: 6 }}>
              <input className="input" placeholder="Name" value={contact.name} onChange={(event) => set("contacts", form.contacts.map((item, position) => (position === index ? { ...item, name: event.target.value } : item)))} />
              <input className="input" placeholder="Role" value={contact.role ?? ""} onChange={(event) => set("contacts", form.contacts.map((item, position) => (position === index ? { ...item, role: event.target.value } : item)))} />
              <input className="input" placeholder="Email" value={contact.email ?? ""} onChange={(event) => set("contacts", form.contacts.map((item, position) => (position === index ? { ...item, email: event.target.value } : item)))} />
              <button className="btn danger" onClick={() => set("contacts", form.contacts.filter((_, position) => position !== index))}>×</button>
            </div>
          ))}
          <div><button className="btn soft" onClick={() => set("contacts", [...form.contacts, { name: "" }])}>Add contact</button></div>
        </div>
        <Field label="Notes" optional full><textarea className="input" value={form.notes ?? ""} onChange={(event) => set("notes", event.target.value)} /></Field>
      </div>
    </Modal>
  );
}
