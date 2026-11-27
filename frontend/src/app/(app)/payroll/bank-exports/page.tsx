"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { BankExportsTable, type BankExport } from "@/components/payroll/BankFile";
import { Banner, EmptyState, PageHead, Panel } from "@/components/ui";
import { api } from "@/lib/api";

const FILTERS = [
  { value: "", label: "All files" },
  { value: "not_recorded", label: "Payment not recorded" },
  { value: "paid", label: "Paid" },
  { value: "failed", label: "Failed" },
];

function BankExportsPage() {
  const [data, setData] = useState<{ data: BankExport[]; notice: string } | null>(null);
  const [status, setStatus] = useState("");
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setData(await api<{ data: BankExport[]; notice: string }>("/payroll/bank-exports", { query: status ? { payment_status: status } : undefined }));
  }, [status]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch on filter change
    void load();
  }, [load]);

  if (!data) return <div className="loadingpage">Loading…</div>;

  return (
    <>
      <PageHead
        eyebrow="Payroll"
        title="Bank exports"
        subtitle="Bank files created from approved payroll runs. Every creation, download and payment record is kept in the activity history."
        actions={<Link className="btn" href="/payroll/bank-formats">Bank formats</Link>}
      />
      <Banner tone="info">{data.notice} Once your bank has processed a file, record the payment here if you want to keep track of it.</Banner>
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel
        title="Files"
        right={<select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Payment status">{FILTERS.map((filter) => <option key={filter.value} value={filter.value}>{filter.label}</option>)}</select>}
        bodyless={data.data.length > 0}
      >
        {data.data.length === 0
          ? <EmptyState icon="⇄" title={status ? "No files with this payment status" : "No bank files yet"}>{status ? "Choose another filter." : "Open an approved payroll run and choose “Create bank file”."}</EmptyState>
          : <BankExportsTable exports={data.data} showRun onChange={(message) => { if (message) setNotice(message); void load(); }} />}
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.export"]}>
      <BankExportsPage />
    </Guard>
  );
}
