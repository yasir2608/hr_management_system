"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { PayslipBadges, signed, type PayslipSummary } from "@/components/payroll/Payslip";
import { EmptyState, PageHead, Panel, Table, formatDate, money } from "@/components/ui";
import { api } from "@/lib/api";

function PayslipsPage() {
  const [payslips, setPayslips] = useState<PayslipSummary[] | null>(null);
  const [filters, setFilters] = useState({ search: "", period: "", status: "" });

  useEffect(() => {
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ""));
    const timer = setTimeout(() => {
      void api<{ data: PayslipSummary[] }>(`/payroll/payslips?${query.toString()}`).then((result) => setPayslips(result.data));
    }, 250);
    return () => clearTimeout(timer);
  }, [filters]);

  const setFilter = (key: keyof typeof filters) => (event: { target: { value: string } }) => setFilters((current) => ({ ...current, [key]: event.target.value }));

  return (
    <>
      <PageHead eyebrow="Payroll" title="Payslips" subtitle="Issued automatically when a payroll run is approved. A payslip is never edited: corrections issue a new one." />
      <Panel
        title="All payslips"
        subtitle={payslips ? `${payslips.length} shown` : undefined}
        right={
          <div className="row" style={{ flexWrap: "wrap", justifyContent: "flex-end" }}>
            <input className="input" style={{ width: 200 }} placeholder="Search name, number, payslip" value={filters.search} onChange={setFilter("search")} />
            <input className="input" style={{ width: 150 }} type="month" aria-label="Pay period" value={filters.period} onChange={setFilter("period")} />
            <select style={{ width: 130 }} value={filters.status} onChange={setFilter("status")} aria-label="Status">
              <option value="">Any status</option>
              <option value="issued">Issued</option>
              <option value="superseded">Superseded</option>
            </select>
          </div>
        }
        bodyless
      >
        {payslips === null ? <div className="empty">Loading…</div> : payslips.length === 0 ? <EmptyState title="No payslips found">Payslips appear here once a payroll run is approved.</EmptyState> : (
          <Table headers={["Payslip", "Employee", "Pay period", "Pay date", "Net pay", "Status"]}>
            {payslips.map((payslip) => (
              <tr key={payslip.id}>
                <td><Link href={`/payroll/payslips/${payslip.id}`}><strong>{payslip.number}</strong></Link></td>
                <td>{payslip.employee_name}<div className="tiny">{payslip.employee_number}</div></td>
                <td>{formatDate(payslip.period_start)} – {formatDate(payslip.period_end)}</td>
                <td>{formatDate(payslip.pay_date)}</td>
                <td>{payslip.net_pay === null ? <span className="badge gray">Restricted</span> : payslip.kind === "adjustment" ? signed(payslip.net_difference ?? "0", payslip.currency) : money(payslip.net_pay, payslip.currency)}</td>
                <td><PayslipBadges payslip={payslip} /></td>
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
    <Guard permissions={["payslips.manage", "payroll.view"]}>
      <PayslipsPage />
    </Guard>
  );
}
