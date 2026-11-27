"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, Panel, Table, formatDate, formatDateTime, money } from "@/components/ui";
import { api } from "@/lib/api";

export type PayslipSummary = {
  id: string; number: string; kind: string; status: string; currency: string; payroll_run_id: string; employee_id: string;
  employee_name: string | null; employee_number: string | null; period_start: string; period_end: string; pay_date: string;
  net_pay: string | null; net_difference: string | null; issued_at: string; superseded_at: string | null; superseded_by: { id: string; number: string } | null;
};

type Item = { code: string; label: string; amount: string; explanation: string | null };
type Pair = { approved: string; corrected: string; difference: string };

export type PayslipDetail = PayslipSummary & {
  content: {
    company: { name: string | null };
    employee: { name: string; number: string | null; job_title: string | null; department: string | null };
    country_code: string; pay_frequency: string;
    earnings: Item[]; deductions: Item[]; employer_contributions: Item[];
    gross_pay: string; taxable_pay: string; employee_deductions: string; net_pay: string; employer_costs: string;
    days_employed: number | null; period_days: number | null; rules_version: string | null; notes: string[];
    adjustment: { gross_pay: Pair; net_pay: Pair; employer_costs: Pair } | null;
    corrects: { id: string; number: string } | null;
    correction_reason: string | null;
  };
  related: { id: string; number: string; kind: string; status: string; issued_at: string }[];
};

export const KIND_LABEL: Record<string, string> = { regular: "Payslip", adjustment: "Adjustment payslip", replacement: "Replacement payslip" };

/** Issued or superseded, with the payslip kind when it comes from a correction. */
export function PayslipBadges({ payslip }: { payslip: Pick<PayslipSummary, "kind" | "status"> }) {
  return (
    <>
      <Badge status={payslip.status} label={payslip.status === "superseded" ? "Superseded" : "Issued"} />
      {payslip.kind !== "regular" && <> <Badge tone="blue" label={payslip.kind === "adjustment" ? "Adjustment" : "Replacement"} /></>}
    </>
  );
}

/** The payslips an approved run issued. */
export function RunPayslipsPanel({ runId, version }: { runId: string; version: string }) {
  const [payslips, setPayslips] = useState<PayslipSummary[] | null>(null);

  useEffect(() => {
    void api<{ data: PayslipSummary[] }>(`/payroll/runs/${runId}/payslips`).then((result) => setPayslips(result.data));
  }, [runId, version]);

  if (!payslips || payslips.length === 0) return null;
  const superseded = payslips.filter((payslip) => payslip.status === "superseded").length;

  return (
    <Panel title="Payslips" subtitle={`${payslips.length} issued when this run was approved${superseded ? ` · ${superseded} superseded by a replacement` : ""}`} bodyless>
      <Table headers={["Payslip", "Employee", "Net pay", "Status"]}>
        {payslips.map((payslip) => (
          <tr key={payslip.id}>
            <td><Link href={`/payroll/payslips/${payslip.id}`}><strong>{payslip.number}</strong></Link><div className="tiny">Issued {formatDateTime(payslip.issued_at)}</div></td>
            <td>{payslip.employee_name}<div className="tiny">{payslip.employee_number}</div></td>
            <td>{payslip.net_pay === null ? <span className="badge gray">Restricted</span> : payslip.kind === "adjustment" ? signed(payslip.net_difference ?? "0", payslip.currency) : money(payslip.net_pay, payslip.currency)}</td>
            <td><PayslipBadges payslip={payslip} />{payslip.superseded_by && <div className="tiny">by <Link className="link" href={`/payroll/payslips/${payslip.superseded_by.id}`}>{payslip.superseded_by.number}</Link></div>}</td>
          </tr>
        ))}
      </Table>
    </Panel>
  );
}

export function signed(value: string, currency: string) {
  return `${Number(value) > 0 ? "+" : ""}${money(value, currency)}`;
}

function Rows({ title, items, currency, total, totalLabel }: { title: string; items: Item[]; currency: string; total: string; totalLabel: string }) {
  return (
    <div className="slipblock">
      <div className="sliphead"><span>{title}</span><span>Amount</span></div>
      {items.length === 0 ? <div className="sliprow muted"><span>None</span><span /></div> : items.map((item, index) => (
        <div className="sliprow" key={`${item.code}-${index}`}><span>{item.label}</span><span>{money(item.amount, currency)}</span></div>
      ))}
      <div className="sliprow total"><span>{totalLabel}</span><span>{money(total, currency)}</span></div>
    </div>
  );
}

/** The payslip itself, laid out to read well on screen and when printed or saved as PDF. */
export function PayslipDocument({ payslip }: { payslip: PayslipDetail }) {
  const { content, currency } = payslip;

  return (
    <article className="payslip">
      <header className="slipheader">
        <div>
          <div className="slipcompany">{content.company.name ?? "Company"}</div>
          <div className="tiny">{KIND_LABEL[payslip.kind] ?? "Payslip"} · {payslip.number}</div>
        </div>
        <div className="slipnet">
          <div className="tiny">{content.adjustment ? "Net difference" : "Net pay"}</div>
          <strong>{content.adjustment ? signed(content.adjustment.net_pay.difference, currency) : money(content.net_pay, currency)}</strong>
        </div>
      </header>

      <div className="slipmeta">
        <div><span className="tiny">Employee</span><b>{content.employee.name}</b>{content.employee.number && <span className="tiny">No. {content.employee.number}</span>}</div>
        <div><span className="tiny">Role</span><b>{content.employee.job_title ?? "—"}</b>{content.employee.department && <span className="tiny">{content.employee.department}</span>}</div>
        <div><span className="tiny">Pay period</span><b>{formatDate(payslip.period_start)} – {formatDate(payslip.period_end)}</b>{content.days_employed !== null && content.period_days !== null && content.days_employed < content.period_days && <span className="tiny">{content.days_employed} of {content.period_days} days</span>}</div>
        <div><span className="tiny">Pay date</span><b>{formatDate(payslip.pay_date)}</b><span className="tiny">{currency}</span></div>
      </div>

      {payslip.status === "superseded" && <div className="callout red">Superseded{payslip.superseded_by ? ` by ${payslip.superseded_by.number}` : ""}: the payroll run for this period was reversed and replaced. Kept for the record.</div>}
      {content.corrects && <div className="callout">{content.adjustment ? "Adjusts" : "Replaces"} payslip {content.corrects.number}.{content.correction_reason && ` Reason: ${content.correction_reason}`}</div>}

      {content.adjustment && (
        <div className="slipblock">
          <div className="sliphead"><span>Adjustment</span><span>Paid → corrected</span></div>
          {(["gross_pay", "net_pay", "employer_costs"] as const).map((key) => (
            <div className="sliprow" key={key}>
              <span>{key === "gross_pay" ? "Gross pay" : key === "net_pay" ? "Net pay" : "Employer contributions"}</span>
              <span>{money(content.adjustment![key].approved, currency)} → {money(content.adjustment![key].corrected, currency)} <b>({signed(content.adjustment![key].difference, currency)})</b></span>
            </div>
          ))}
        </div>
      )}

      <div className="slipcols">
        <Rows title={content.adjustment ? "Corrected earnings" : "Earnings"} items={content.earnings} currency={currency} total={content.gross_pay} totalLabel="Gross pay" />
        <Rows title="Deductions" items={content.deductions} currency={currency} total={content.employee_deductions} totalLabel="Total deductions" />
      </div>

      <div className="sliptotal"><span>Net pay</span><strong>{money(content.net_pay, currency)}</strong></div>

      {content.employer_contributions.length > 0 && (
        <Rows title="Paid by the employer (not deducted from your pay)" items={content.employer_contributions} currency={currency} total={content.employer_costs} totalLabel="Employer contributions" />
      )}

      <footer className="slipfoot tiny">
        <div>Taxable pay {money(content.taxable_pay, currency)}{content.rules_version && ` · tax rules ${content.rules_version}`}</div>
        {content.notes.map((note) => <div key={note}>{note}</div>)}
        <div>Issued {formatDateTime(payslip.issued_at)}. This payslip is a record of the approved payroll and is not changed afterwards.</div>
      </footer>
    </article>
  );
}
