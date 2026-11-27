"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { PERIOD_LABELS, StateBadge, priceLabel, type Plan, type Subscription } from "@/components/platform/Billing";
import { InvoiceStatus, PaymentLine, type BillingInvoice } from "@/components/platform/BillingInvoices";
import { Badge, Banner, EmptyState, Field, Modal, PageHead, Panel, Stat, Table, formatDate, money } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Row = { id: string; name: string; status: string; employees: number; subscription: Subscription | null; upcoming: Subscription | null };
type Overview = { companies: Row[]; totals: { companies: number; with_plan: number; without_plan: number; on_trial: number }; can_manage: boolean };

export default function PlatformBillingPage() {
  const [overview, setOverview] = useState<Overview | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [editing, setEditing] = useState<Plan | "new" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const [billing, planList] = await Promise.all([
      api<Overview>("/platform/billing", { noCompany: true }),
      api<{ data: Plan[] }>("/platform/billing/plans", { noCompany: true }),
    ]);
    setOverview(billing);
    setPlans(planList.data);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!overview) return <div className="empty">Loading…</div>;

  return (
    <>
      <PageHead eyebrow="Staff console" title="Billing" subtitle="Plans and each customer's agreed subscription. This is M.Y HRMS billing, separate from customers' payroll and contractor invoices." />
      {notice && <Banner tone="success">{notice}</Banner>}
      <div className="grid stats">
        <Stat label="Customer companies" value={overview.totals.companies} />
        <Stat label="With a plan" value={overview.totals.with_plan} />
        <Stat label="On trial" value={overview.totals.on_trial} />
        <Stat label="No plan recorded" value={overview.totals.without_plan} />
      </div>

      <Panel title="Subscriptions" subtitle="Open a company to record or change its agreement." bodyless>
        <Table headers={["Company", "Plan", "Price", "Period", "Next period", "Employees"]}>
          {overview.companies.map((row) => (
            <tr key={row.id}>
              <td><Link href={`/companies/${row.id}`}><strong>{row.name}</strong></Link>{row.status !== "active" && <> <Badge status={row.status} /></>}</td>
              <td>
                {row.subscription ? <>{row.subscription.plan_name} <StateBadge state={row.subscription.state} /></> : <span className="muted">No plan</span>}
                {row.upcoming && <div className="tiny">From {formatDate(row.upcoming.starts_on)}: {row.upcoming.plan_name}</div>}
              </td>
              <td>{row.subscription ? priceLabel(row.subscription) : "—"}</td>
              <td>{row.subscription ? PERIOD_LABELS[row.subscription.billing_period] : "—"}</td>
              <td>{row.subscription?.next_period_starts ? formatDate(row.subscription.next_period_starts) : "—"}</td>
              <td>{row.employees}{row.subscription?.employee_limit && <span className="tiny"> of {row.subscription.employee_limit}</span>}</td>
            </tr>
          ))}
        </Table>
      </Panel>

      <Panel title="Plans" subtitle="The plans M.Y HRMS offers. Agreed prices can differ per customer." right={overview.can_manage && <button className="btn soft" onClick={() => setEditing("new")}>New plan</button>} bodyless={plans.length > 0}>
        {plans.length === 0 ? <EmptyState icon="＄" title="No plans yet">Create the plans you offer before recording subscriptions.</EmptyState> : (
          <Table headers={["Plan", "List price", "Companies on it", ""]}>
            {plans.map((plan) => (
              <tr key={plan.id}>
                <td><strong>{plan.name}</strong> {!plan.is_active && <Badge tone="gray" label="Not offered" />}{plan.description && <div className="tiny">{plan.description}</div>}</td>
                <td>{priceLabel(plan)}</td>
                <td>{plan.companies ?? 0}</td>
                <td style={{ textAlign: "right" }}>{overview.can_manage && <button className="btn" onClick={() => setEditing(plan)}>Edit</button>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      <AllInvoices />
      {editing && <PlanForm plan={editing === "new" ? undefined : editing} onClose={() => setEditing(null)} onSaved={(message) => { setEditing(null); setNotice(message); void load(); }} />}
    </>
  );
}

const INVOICE_FILTERS = [{ key: "", label: "All" }, { key: "issued", label: "Issued" }, { key: "overdue", label: "Overdue" }, { key: "paid", label: "Paid" }, { key: "void", label: "Void" }];

/** Every invoice M.Y HRMS has raised, newest first, with outstanding totals. Created on each company's page. */
function AllInvoices() {
  const [status, setStatus] = useState("");
  const [data, setData] = useState<{ data: BillingInvoice[]; totals: { outstanding: Record<string, string>; overdue_count: number; paid_this_month: Record<string, string> } } | null>(null);

  useEffect(() => {
    void api<{ data: BillingInvoice[]; totals: { outstanding: Record<string, string>; overdue_count: number; paid_this_month: Record<string, string> } }>("/platform/billing/invoices", { noCompany: true, query: status ? { status } : undefined }).then(setData);
  }, [status]);

  if (!data) return null;
  const outstanding = Object.entries(data.totals.outstanding).map(([currency, amount]) => money(amount, currency)).join(" · ") || money("0", "USD");
  const collected = Object.entries(data.totals.paid_this_month).map(([currency, amount]) => money(amount, currency)).join(" · ") || money("0", "USD");

  return (
    <Panel
      title="Invoices"
      subtitle={`Outstanding: ${outstanding}${data.totals.overdue_count > 0 ? ` · ${data.totals.overdue_count} overdue` : ""} · Paid this month: ${collected}. Create invoices and record payments from a company's page.`}
      right={<select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Invoice status">{INVOICE_FILTERS.map((filter) => <option key={filter.key} value={filter.key}>{filter.label}</option>)}</select>}
      bodyless={data.data.length > 0}
    >
      {data.data.length === 0 ? <EmptyState icon="▤" title="No invoices here" /> : (
        <Table headers={["Reference", "Company", "Amount", "Issued", "Due", "Status"]}>
          {data.data.map((invoice) => (
            <tr key={invoice.id}>
              <td><strong>{invoice.reference}</strong></td>
              <td><Link href={`/companies/${invoice.company_id}`}>{invoice.company}</Link></td>
              <td>{money(invoice.amount, invoice.currency)}</td>
              <td>{formatDate(invoice.issued_on)}</td>
              <td>{formatDate(invoice.due_on)}</td>
              <td><InvoiceStatus status={invoice.display_status} /><PaymentLine invoice={invoice} /></td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}

function PlanForm({ plan, onClose, onSaved }: { plan?: Plan; onClose: () => void; onSaved: (message: string) => void }) {
  const [form, setForm] = useState({
    name: plan?.name ?? "", description: plan?.description ?? "", price_basis: plan?.price_basis ?? "per_employee", price: plan?.price ?? "",
    currency: plan?.currency ?? "USD", billing_period: plan?.billing_period ?? "monthly", is_active: plan?.is_active ?? true,
  });
  const [error, setError] = useState<ApiError | null>(null);
  const fieldError = (key: string) => error?.errors[key]?.[0];

  async function save() {
    setError(null);
    try {
      await api(plan ? `/platform/billing/plans/${plan.id}` : "/platform/billing/plans", { method: plan ? "PUT" : "POST", noCompany: true, body: { ...form, price: Number(form.price), description: form.description || null } });
      onSaved(plan ? "Plan updated." : "Plan created.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <Modal title={plan ? `Edit ${plan.name}` : "New plan"} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save plan</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      {plan && <Banner tone="info">Changing the list price does not change prices already agreed with customers.</Banner>}
      <div className="formgrid">
        <Field label="Name" required error={fieldError("name")}><input className="input" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></Field>
        <Field label="Charged" required error={fieldError("price_basis")}>
          <select value={form.price_basis} onChange={(event) => setForm({ ...form, price_basis: event.target.value as Plan["price_basis"] })}>
            <option value="per_employee">Per employee</option>
            <option value="flat">Flat fee</option>
          </select>
        </Field>
        <Field label="List price" required error={fieldError("price")}><input className="input" type="number" min={0} step="0.01" value={form.price} onChange={(event) => setForm({ ...form, price: event.target.value })} /></Field>
        <Field label="Currency" required error={fieldError("currency")}><input className="input" maxLength={3} value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value.toUpperCase() })} /></Field>
        <Field label="Billing period" required error={fieldError("billing_period")}>
          <select value={form.billing_period} onChange={(event) => setForm({ ...form, billing_period: event.target.value as Plan["billing_period"] })}>
            {Object.entries(PERIOD_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </Field>
        <Field label="Offered" error={fieldError("is_active")}><label className="checkrow"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} /> Can be chosen for new agreements</label></Field>
        <Field label="Description" optional full error={fieldError("description")}><input className="input" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></Field>
      </div>
    </Modal>
  );
}
