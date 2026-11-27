"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { BankExportsTable, type BankExport } from "@/components/payroll/BankFile";
import { Banner, EmptyState, PageHead, Panel } from "@/components/ui";
import { api } from "@/lib/api";

function BankExportsPage() {
  const [data, setData] = useState<{ data: BankExport[]; notice: string } | null>(null);

  const load = useCallback(async () => {
    setData(await api<{ data: BankExport[]; notice: string }>("/payroll/bank-exports"));
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!data) return <div className="loadingpage">Loading…</div>;

  return (
    <>
      <PageHead
        eyebrow="Payroll"
        title="Bank exports"
        subtitle="Bank files created from approved payroll runs. Every creation and download is recorded in the activity history."
        actions={<Link className="btn" href="/payroll/bank-formats">Bank formats</Link>}
      />
      <Banner tone="info">{data.notice}</Banner>
      <Panel bodyless={data.data.length > 0}>
        {data.data.length === 0
          ? <EmptyState icon="⇄" title="No bank files yet">Open an approved payroll run and choose “Create bank file”.</EmptyState>
          : <BankExportsTable exports={data.data} showRun onDownloaded={() => void load()} />}
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
