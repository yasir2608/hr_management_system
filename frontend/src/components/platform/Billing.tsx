"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, Modal, Panel, Table, formatDate, money } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export type Plan = {
  id: string; name: string; description: string | null; price_basis: "flat" | "per_employee"; price: string; currency: string;
  billing_period: "monthly" | "quarterly" | "annual"; is_active: boolean; companies?: number;
};
export type Subscription = {
  id: string; billing_plan_id: string; plan_name: string; price_basis: string; price: string; currency: string; billing_period: string;
  employee_limit: number | null; status: string; notes: string | null; end_reason: string | null; starts_on: string; ends_on: string | null;
  state: "trial" | "active" | "upcoming" | "ended"; next_period_starts: string | null; recorded_by: string | null;
};

export const PERIOD_LABELS: Record<string, string> = { monthly: "Monthly", quarterly: "Quarterly", annual: "Annual" };
const STATE_TONES: Record<string, string> = { active: "green", trial: "blue", upcoming: "amber", ended: "gray" };

export function priceLabel(item: { price: string; currency: string; price_basis: string; billing_period: string }) {
  const period = { monthly: "month", quarterly: "quarter", annual: "year" }[item.billing_period] ?? item.billing_period;
  return `${money(item.price, item.currency)}${item.price_basis === "per_employee" ? " per employee" : ""} / ${period}`;
}

export function StateBadge({ state }: { state: string }) {
  return <Badge tone={STATE_TONES[state] ?? "gray"} label={state.charAt(0).toUpperCase() + state.slice(1)} />;
}

/** A company's agreed plan and its history, with recording and ending for operations staff. */
export function SubscriptionPanel({ companyId }: { companyId: string }) {
  const [data, setData] = useState<{ current: Subscription | null; history: Subscription[]; plans: Plan[]; can_manage: boolean } | null>(null);
  const [recording, setRecording] = useState(false);
  const [ending, setEnding] = useState<Subscription | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setData(await api(`/platform/companies/${companyId}/subscriptions`, { noCompany: true }));
  }, [companyId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!data) return null;
  const { current } = data;

  return (
    <Panel
      title="Subscription"
      subtitle="The plan and billing period agreed with this customer. Separate from their payroll and contractor invoices."
      right={data.can_manage && <button className="btn soft" onClick={() => setRecording(true)}>Record agreement</button>}
      bodyless={data.history.length > 0}
    >
      {notice && <div style={{ padding: "10px 16px 0" }}><Banner tone="success">{notice}</Banner></div>}
      {data.history.length === 0 ? <EmptyState icon="＄" title="No plan recorded">Record the plan and billing period agreed with this customer.</EmptyState> : (
        <>
          {current
            ? <div style={{ padding: "12px 16px" }}><strong>{current.plan_name}</strong> · {priceLabel(current)} <StateBadge state={current.state} />{current.next_period_starts && <span className="tiny"> · next period starts {formatDate(current.next_period_starts)}</span>}</div>
            : <div style={{ padding: "12px 16px" }}><Badge tone="amber" label="No plan in force today" /></div>}
          <Table headers={["Plan", "Price", "Period", "From", "To", "State", ""]}>
            {data.history.map((item) => (
              <tr key={item.id}>
                <td>{item.plan_name}{item.employee_limit && <div className="tiny">Up to {item.employee_limit} employees</div>}{item.notes && <div className="tiny">{item.notes}</div>}</td>
                <td>{priceLabel(item)}</td>
                <td>{PERIOD_LABELS[item.billing_period] ?? item.billing_period}</td>
                <td>{formatDate(item.starts_on)}</td>
                <td>{item.ends_on ? formatDate(item.ends_on) : "Open-ended"}{item.end_reason && <div className="tiny">{item.end_reason}</div>}</td>
                <td><StateBadge state={item.state} />{item.recorded_by && <div className="tiny">by {item.recorded_by}</div>}</td>
                <td style={{ textAlign: "right" }}>{data.can_manage && item.state !== "ended" && !item.ends_on && <button className="btn" onClick={() => setEnding(item)}>End</button>}</td>
              </tr>
            ))}
          </Table>
        </>
      )}
      {recording && <RecordAgreement companyId={companyId} plans={data.plans} onClose={() => setRecording(false)} onSaved={() => { setRecording(false); setNotice("Agreement recorded."); void load(); }} />}
      {ending && <EndAgreement companyId={companyId} subscription={ending} onClose={() => setEnding(null)} onSaved={() => { setEnding(null); setNotice("Agreement end recorded."); void load(); }} />}
    </Panel>
  );
}

function RecordAgreement({ companyId, plans, onClose, onSaved }: { companyId: string; plans: Plan[]; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState({ billing_plan_id: plans[0]?.id ?? "", price: "", billing_period: "", employee_limit: "", starts_on: new Date().toISOString().slice(0, 10), status: "active", notes: "" });
  const [error, setError] = useState<ApiError | null>(null);
  const plan = plans.find((option) => option.id === form.billing_plan_id);
  const fieldError = (key: string) => error?.errors[key]?.[0];

  async function save() {
    setError(null);
    try {
      await api(`/platform/companies/${companyId}/subscriptions`, {
        method: "POST", noCompany: true,
        body: { ...form, price: form.price === "" ? null : Number(form.price), billing_period: form.billing_period || null, employee_limit: form.employee_limit === "" ? null : Number(form.employee_limit), notes: form.notes || null },
      });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title="Record agreement" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={!plan} onClick={() => void save()}>Save agreement</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      {plans.length === 0 ? <EmptyState title="No active plans">Create a plan on the Billing page first.</EmptyState> : (
        <div className="formgrid">
          <Field label="Plan" required full error={fieldError("billing_plan_id")}>
            <select value={form.billing_plan_id} onChange={(event) => setForm({ ...form, billing_plan_id: event.target.value })}>
              {plans.map((option) => <option key={option.id} value={option.id}>{option.name} · {priceLabel(option)}</option>)}
            </select>
          </Field>
          <Field label="Agreed price" optional hint={plan ? `Leave blank for the list price (${money(plan.price, plan.currency)}).` : undefined} error={fieldError("price")}>
            <input className="input" type="number" min={0} step="0.01" value={form.price} onChange={(event) => setForm({ ...form, price: event.target.value })} />
          </Field>
          <Field label="Billing period" optional hint={plan ? `Defaults to ${PERIOD_LABELS[plan.billing_period].toLowerCase()}.` : undefined} error={fieldError("billing_period")}>
            <select value={form.billing_period} onChange={(event) => setForm({ ...form, billing_period: event.target.value })}>
              <option value="">Plan default</option>
              {Object.entries(PERIOD_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </Field>
          <Field label="Starts on" required hint="Any agreement running then ends the day before." error={fieldError("starts_on")}>
            <input className="input" type="date" value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} />
          </Field>
          <Field label="Type" required error={fieldError("status")}>
            <select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
              <option value="active">Paid subscription</option>
              <option value="trial">Trial</option>
            </select>
          </Field>
          <Field label="Employee limit" optional error={fieldError("employee_limit")}>
            <input className="input" type="number" min={1} value={form.employee_limit} onChange={(event) => setForm({ ...form, employee_limit: event.target.value })} />
          </Field>
          <Field label="Notes" optional full error={fieldError("notes")}>
            <textarea className="input" rows={2} value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} placeholder="e.g. agreed discount, contract reference" />
          </Field>
        </div>
      )}
    </Modal>
  );
}

function EndAgreement({ companyId, subscription, onClose, onSaved }: { companyId: string; subscription: Subscription; onClose: () => void; onSaved: () => void }) {
  const [endsOn, setEndsOn] = useState(new Date().toISOString().slice(0, 10));
  const [reason, setReason] = useState("");
  const [error, setError] = useState<ApiError | null>(null);

  async function save() {
    setError(null);
    try {
      await api(`/platform/companies/${companyId}/subscriptions/${subscription.id}/end`, { method: "POST", noCompany: true, body: { ends_on: endsOn, reason } });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title={`End ${subscription.plan_name}`} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn danger" onClick={() => void save()}>End agreement</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="formgrid">
        <Field label="Last day" required error={error?.errors.ends_on?.[0]}><input className="input" type="date" value={endsOn} onChange={(event) => setEndsOn(event.target.value)} /></Field>
        <Field label="Reason" required full error={error?.errors.reason?.[0]}><textarea className="input" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} /></Field>
      </div>
    </Modal>
  );
}
