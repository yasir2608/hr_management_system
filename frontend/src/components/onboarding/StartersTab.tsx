"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, EmptyState, formatDate, Panel, Person, Table } from "@/components/ui";
import { api } from "@/lib/api";
import { statusLabel } from "@/lib/employees";

type Row = {
  employee: { id: string; full_name: string; job_title: string | null; start_date: string | null; status: string };
  done: number;
  total: number;
  required_open: number;
  blocked: number;
  awaiting_review: number;
  overdue: number;
};

export function StartersTab() {
  const [rows, setRows] = useState<Row[] | null>(null);

  useEffect(() => {
    api<{ data: Row[] }>("/onboarding/employees").then((result) => setRows(result.data));
  }, []);

  return (
    <Panel title="New starters" subtitle="Everyone with an onboarding checklist." bodyless>
      {rows && rows.length === 0 ? (
        <EmptyState icon="✓" title="Nobody is onboarding">Start onboarding from an employee&apos;s page.</EmptyState>
      ) : (
        <Table headers={["Employee", "Start date", "Progress", "Attention", "Status"]}>
          {rows?.map((row) => (
            <tr key={row.employee.id}>
              <td><Link href={`/employees/${row.employee.id}?tab=onboarding`} style={{ textDecoration: "none" }}><Person name={row.employee.full_name} detail={row.employee.job_title} /></Link></td>
              <td>{formatDate(row.employee.start_date)}</td>
              <td style={{ minWidth: 160 }}>
                <div className="progress"><span style={{ width: `${(row.done / Math.max(row.total, 1)) * 100}%` }} /></div>
                <div className="tiny">{row.done} of {row.total} done · {row.required_open} required open</div>
              </td>
              <td>
                {row.overdue > 0 && <Badge tone="red" label={`${row.overdue} overdue`} />} {row.blocked > 0 && <Badge tone="red" label={`${row.blocked} blocked`} />}{" "}
                {row.awaiting_review > 0 && <Badge tone="blue" label={`${row.awaiting_review} to review`} />}
                {row.overdue + row.blocked + row.awaiting_review === 0 && <span className="muted">—</span>}
              </td>
              <td><Badge status={row.employee.status} label={statusLabel(row.employee.status)} /></td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}
