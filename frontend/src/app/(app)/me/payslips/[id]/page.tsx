"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { KIND_LABEL, PayslipBadges, PayslipDocument, type PayslipDetail } from "@/components/payroll/Payslip";
import { Banner, PageHead, Panel, Table, formatDateTime } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

function MyPayslipPage() {
  const { id } = useParams<{ id: string }>();
  const [payslip, setPayslip] = useState<PayslipDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<PayslipDetail>(`/me/payslips/${id}`)
      .then(setPayslip)
      .catch((caught) => setError(caught instanceof ApiError && caught.status === 404 ? "This payslip was not found." : "Could not load the payslip."));
  }, [id]);

  if (error) return <><Banner tone="error">{error}</Banner><Link className="btn" href="/me/payslips">Back to my payslips</Link></>;
  if (!payslip) return <div className="empty">Loading…</div>;

  return (
    <>
      <div className="noprint">
        <PageHead
          eyebrow="My HR"
          title={KIND_LABEL[payslip.kind] ?? "Payslip"}
          subtitle={`${payslip.number} · ${payslip.content.company.name ?? ""}`}
          actions={<><Link className="btn" href="/me/payslips">All my payslips</Link><button className="btn primary" onClick={() => window.print()}>Print or save as PDF</button></>}
        />
        <div className="row" style={{ marginBottom: 12 }}><PayslipBadges payslip={payslip} /></div>
      </div>
      <PayslipDocument payslip={payslip} />
      {payslip.related.length > 0 && (
        <div className="noprint">
          <Panel title="Other payslips for this period" subtitle="From the original payroll or a later correction." bodyless>
            <Table headers={["Payslip", "Kind", "Issued", "Status"]}>
              {payslip.related.map((other) => (
                <tr key={other.id}>
                  <td><Link href={`/me/payslips/${other.id}`}><strong>{other.number}</strong></Link></td>
                  <td>{KIND_LABEL[other.kind] ?? other.kind}</td>
                  <td>{formatDateTime(other.issued_at)}</td>
                  <td><PayslipBadges payslip={other} /></td>
                </tr>
              ))}
            </Table>
          </Panel>
        </div>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["self.payslips.view"]}>
      <MyPayslipPage />
    </Guard>
  );
}
