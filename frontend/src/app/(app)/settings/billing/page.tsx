"use client";

import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { EmptyState, PageHead, Panel, Stat, Table, formatDate, money } from "@/components/ui";
import { PERIOD_LABELS, priceLabel } from "@/components/platform/Billing";
import { InvoiceStatus } from "@/components/platform/BillingInvoices";
import { api } from "@/lib/api";

type Agreement = {
  plan_name: string; price_basis: string; price: string; currency: string; billing_period: string; employee_limit: number | null;
  state: string; starts_on: string; ends_on: string | null; next_period_starts: string | null;
};
type Invoice = {
  id: string; reference: string; period_start: string | null; period_end: string | null; amount: string; currency: string;
  issued_on: string; due_on: string; status: string; paid_on: string | null; payment_method: string | null; payment_reference: string | null;
};
type Billing = {
  company: string; current: Agreement | null; upcoming: Agreement | null; employees: number; invoices: Invoice[];
  totals: { outstanding: Record<string, string>; overdue_count: number };
};

function AgreementPanel({ title, agreement, employees }: { title: string; agreement: Agreement; employees: number }) {
  return (
    <Panel title={title} subtitle={`${agreement.plan_name} · ${priceLabel(agreement)}`}>
      <p>Billed {(PERIOD_LABELS[agreement.billing_period] ?? agreement.billing_period).toLowerCase()} from {formatDate(agreement.starts_on)}{agreement.ends_on ? ` until ${formatDate(agreement.ends_on)}` : ""}.</p>
      {agreement.next_period_starts && <p className="tiny">Next billing period starts {formatDate(agreement.next_period_starts)}.</p>}
      {agreement.employee_limit !== null && <p className="tiny">Employee limit: {employees} of {agreement.employee_limit} in use.</p>}
    </Panel>
  );
}

function BillingPage() {
  const [billing, setBilling] = useState<Billing | null>(null);

  useEffect(() => {
    void api<Billing>("/company/billing").then(setBilling);
  }, []);

  if (!billing) return null;
  const outstanding = Object.entries(billing.totals.outstanding).map(([currency, amount]) => money(amount, currency)).join(" · ") || "Nothing due";

  return (
    <>
      <PageHead eyebrow="Administration" title="Billing" subtitle={`Your ${billing.company} subscription to M.Y HRMS. Payments are made outside the product; contact M.Y HRMS if something looks wrong.`} />
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 14, marginBottom: 16 }}>
        <Stat label="Plan" value={billing.current?.plan_name ?? "No plan recorded"} />
        <Stat label="Outstanding" value={outstanding} />
        <Stat label="Overdue invoices" value={billing.totals.overdue_count} good={billing.totals.overdue_count === 0} />
      </div>
      {billing.current ? <AgreementPanel title="Current plan" agreement={billing.current} employees={billing.employees} /> : (
        <Panel title="Current plan"><EmptyState icon="▤" title="No plan recorded yet">M.Y HRMS has not recorded an agreement for your company.</EmptyState></Panel>
      )}
      {billing.upcoming && <AgreementPanel title="Upcoming plan" agreement={billing.upcoming} employees={billing.employees} />}
      <Panel title="Invoices" subtitle="Invoices from M.Y HRMS for your subscription. Not payroll, and not contractor invoices." bodyless={billing.invoices.length > 0}>
        {billing.invoices.length === 0 ? <EmptyState icon="▤" title="No invoices yet" /> : (
          <Table headers={["Reference", "Period", "Amount", "Issued", "Due", "Status"]}>
            {billing.invoices.map((invoice) => (
              <tr key={invoice.id}>
                <td><strong>{invoice.reference}</strong></td>
                <td>{invoice.period_start ? `${formatDate(invoice.period_start)} – ${formatDate(invoice.period_end)}` : "—"}</td>
                <td>{money(invoice.amount, invoice.currency)}</td>
                <td>{formatDate(invoice.issued_on)}</td>
                <td>{formatDate(invoice.due_on)}</td>
                <td><InvoiceStatus status={invoice.status} />{invoice.paid_on && <div className="tiny">Paid {formatDate(invoice.paid_on)}{invoice.payment_method && ` · ${invoice.payment_method}`}{invoice.payment_reference && ` · ${invoice.payment_reference}`}</div>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["company.settings.manage"]}>
      <BillingPage />
    </Guard>
  );
}
