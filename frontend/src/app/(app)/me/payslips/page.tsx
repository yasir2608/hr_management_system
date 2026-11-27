"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { KIND_LABEL, PayslipBadges, signed, type PayslipSummary } from "@/components/payroll/Payslip";
import { EmptyState, PageHead, Panel, Table, formatDate, money } from "@/components/ui";
import { api } from "@/lib/api";

function MyPayslipsPage() {
  const [payslips, setPayslips] = useState<PayslipSummary[] | null>(null);

  useEffect(() => {
    void api<{ data: PayslipSummary[] }>("/me/payslips").then((result) => setPayslips(result.data));
  }, []);

  return (
    <>
      <PageHead eyebrow="My HR" title="My payslips" subtitle="Your payslip for each approved pay period. Open one to view, print or save it as a PDF." />
      <Panel bodyless>
        {payslips === null ? <div className="empty">Loading…</div> : payslips.length === 0 ? <EmptyState icon="▤" title="No payslips yet">Your payslips appear here once your company approves a payroll run.</EmptyState> : (
          <Table headers={["Pay period", "Pay date", "Payslip", "Net pay", "Status"]}>
            {payslips.map((payslip) => (
              <tr key={payslip.id}>
                <td><Link href={`/me/payslips/${payslip.id}`}><strong>{formatDate(payslip.period_start)} – {formatDate(payslip.period_end)}</strong></Link></td>
                <td>{formatDate(payslip.pay_date)}</td>
                <td>{KIND_LABEL[payslip.kind] ?? "Payslip"}<div className="tiny">{payslip.number}</div></td>
                <td>{payslip.kind === "adjustment" ? signed(payslip.net_difference ?? "0", payslip.currency) : money(payslip.net_pay ?? "0", payslip.currency)}</td>
                <td><PayslipBadges payslip={payslip} />{payslip.superseded_by && <div className="tiny">replaced by <Link className="link" href={`/me/payslips/${payslip.superseded_by.id}`}>{payslip.superseded_by.number}</Link></div>}</td>
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
    <Guard permissions={["self.payslips.view"]}>
      <MyPayslipsPage />
    </Guard>
  );
}
