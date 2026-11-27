"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, formatDate, humanize, Modal, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { COUNTRIES, countryName } from "@/lib/countries";
import { EMPLOYMENT_TYPES } from "@/lib/employees";
import type { LeaveType } from "./LeaveTypesTab";

type Groups = { work_countries?: string[]; employment_types?: string[] };
type Policy = {
  id: string;
  series_id: string;
  leave_type_id: string;
  leave_type: { name: string; color: string };
  name: string;
  effective_from: string;
  effective_to: string | null;
  annual_days: string;
  accrual: "upfront" | "monthly";
  carry_over_days: string;
  applies_to: Groups | null;
  is_current: boolean;
  is_future: boolean;
};
type Form = { leave_type_id: string; name: string; effective_from: string; annual_days: string; accrual: "upfront" | "monthly"; carry_over_days: string; applies_to: Groups; versionOf?: string };

function groupLabel(groups: Groups | null) {
  const parts = [
    ...(groups?.work_countries ?? []).map(countryName),
    ...(groups?.employment_types ?? []).map(humanize),
  ];
  return parts.length ? parts.join(", ") : "Everyone";
}

export function PoliciesTab() {
  const [policies, setPolicies] = useState<Policy[] | null>(null);
  const [types, setTypes] = useState<LeaveType[]>([]);
  const [form, setForm] = useState<Form | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const [policyResult, typeResult] = await Promise.all([api<{ data: Policy[] }>("/leave/policies"), api<{ data: LeaveType[] }>("/leave/types")]);
    setPolicies(policyResult.data);
    setTypes(typeResult.data.filter((type) => type.uses_balance));
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function save() {
    if (!form) return;
    setError(null);
    const { versionOf, ...body } = form;
    try {
      await api(versionOf ? `/leave/policies/${versionOf}/versions` : "/leave/policies", { body: versionOf ? { ...body, leave_type_id: undefined } : body });
      setNotice(versionOf ? "New version saved. Leave under the earlier version is unchanged." : "Policy saved.");
      setForm(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  const toggle = (key: keyof Groups, value: string) => {
    if (!form) return;
    const list = form.applies_to[key] ?? [];
    setForm({ ...form, applies_to: { ...form.applies_to, [key]: list.includes(value) ? list.filter((item) => item !== value) : [...list, value] } });
  };

  const today = new Date().toISOString().slice(0, 10);

  return (
    <>
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel
        title="Leave policies"
        subtitle="How many days people get. A policy can apply to a group; the most specific match wins. New versions start on a date and leave earlier calculations alone."
        right={<button className="btn primary" disabled={types.length === 0} onClick={() => { setError(null); setForm({ leave_type_id: types[0]?.id ?? "", name: "", effective_from: today, annual_days: "", accrual: "upfront", carry_over_days: "0", applies_to: {} }); }}>New policy</button>}
        bodyless
      >
        {policies && policies.length === 0 ? (
          <EmptyState icon="☀" title="No policies yet">Add a policy for each leave type that tracks an allowance.</EmptyState>
        ) : (
          <Table headers={["Policy", "Applies to", "Days per year", "Accrual", "Carry over", "In force", ""]}>
            {policies?.map((policy) => (
              <tr key={policy.id} style={!policy.is_current && !policy.is_future ? { opacity: 0.55 } : undefined}>
                <td><span style={{ display: "inline-block", width: 9, height: 9, borderRadius: 5, background: policy.leave_type.color, marginRight: 7 }} /><strong>{policy.name}</strong><div className="tiny">{policy.leave_type.name}</div></td>
                <td>{groupLabel(policy.applies_to)}</td>
                <td>{Number(policy.annual_days)}</td>
                <td>{policy.accrual === "monthly" ? "Monthly" : "At year start"}</td>
                <td>{Number(policy.carry_over_days)}</td>
                <td>
                  {formatDate(policy.effective_from)} – {policy.effective_to ? formatDate(policy.effective_to) : "no end"}{" "}
                  {policy.is_current ? <Badge tone="green" label="Current" /> : policy.is_future ? <Badge tone="blue" label="Upcoming" /> : <Badge tone="gray" label="Earlier version" />}
                </td>
                <td>{(policy.is_current || policy.is_future) && !policy.effective_to && (
                  <button className="btn" onClick={() => { setError(null); setForm({ leave_type_id: policy.leave_type_id, name: policy.name, effective_from: today, annual_days: policy.annual_days, accrual: policy.accrual, carry_over_days: policy.carry_over_days, applies_to: policy.applies_to ?? {}, versionOf: policy.id }); }}>New version</button>
                )}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {form && (
        <Modal title={form.versionOf ? "New version" : "New leave policy"} onClose={() => setForm(null)} footer={<><button className="btn" onClick={() => setForm(null)}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save</button></>}>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            {!form.versionOf && (
              <Field label="Leave type" error={error?.errors.leave_type_id?.[0]}>
                <select value={form.leave_type_id} onChange={(event) => setForm({ ...form, leave_type_id: event.target.value })}>
                  {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                </select>
              </Field>
            )}
            <Field label="Name" required error={error?.errors.name?.[0]}><input className="input" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></Field>
            <Field label="In force from" required error={error?.errors.effective_from?.[0]}><input className="input" type="date" value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} /></Field>
            <Field label="Days per year" required error={error?.errors.annual_days?.[0]}><input className="input" type="number" step="0.5" value={form.annual_days} onChange={(event) => setForm({ ...form, annual_days: event.target.value })} /></Field>
            <Field label="Days become available" hint="The accrual method is still to be confirmed; both options are offered.">
              <select value={form.accrual} onChange={(event) => setForm({ ...form, accrual: event.target.value as Form["accrual"] })}>
                <option value="upfront">All at the start of the leave year</option>
                <option value="monthly">A twelfth each month</option>
              </select>
            </Field>
            <Field label="Days that can carry over"><input className="input" type="number" step="0.5" value={form.carry_over_days} onChange={(event) => setForm({ ...form, carry_over_days: event.target.value })} /></Field>
            <Field label="Only for these countries" optional full hint="Leave all unticked to apply to everyone.">
              <div className="row" style={{ justifyContent: "flex-start" }}>
                {COUNTRIES.map((country) => <label key={country.code} className="checkrow"><input type="checkbox" checked={form.applies_to.work_countries?.includes(country.code) ?? false} onChange={() => toggle("work_countries", country.code)} /> {country.name}</label>)}
              </div>
            </Field>
            <Field label="Only for these employment types" optional full>
              <div className="row" style={{ justifyContent: "flex-start" }}>
                {EMPLOYMENT_TYPES.map((type) => <label key={type.value} className="checkrow"><input type="checkbox" checked={form.applies_to.employment_types?.includes(type.value) ?? false} onChange={() => toggle("employment_types", type.value)} /> {type.label}</label>)}
              </div>
            </Field>
          </div>
        </Modal>
      )}
    </>
  );
}
